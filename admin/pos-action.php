<?php
// Caisse du Studio (studio.php, onglet « Caisse ») : réponses JSON {ok: true, ...} ou {ok: false, error}.
//   lookup        → reconnaît un QR code / une référence (billet de retrait, reçu, pièce)
//   pickup_collect, pickup_release → billet de retrait : encaisser (la somme bloquée est prélevée) ou annuler
//   sale_cash     → vente réglée en espèces (ou autrement, en main propre)
//   sale_card     → page de paiement Stripe à faire scanner au client ; sale_status → la suivre ; sale_cancel → l'annuler
//   sale_terminal → montant envoyé au terminal SumUp Solo appairé (suivi et annulation par sale_status / sale_cancel)
//   express_photo → vente express : photo d'une pièce hors catalogue → fiche masquée, nom et description rédigés par l'IA
//   express_save  → nom et description corrigés ; express_discard → vente abandonnée, fiche supprimée
//   express_ask   → avis de l'expert (le gérant) demandé par e-mail avec la photo ; express_status → sa réponse (page avis-expertise.php)
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pos_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$account = admin_session();
// Le gérant (administrateur) et les vendeurs de la caisse (rôle « Vendeur ») : un autre vendeur que le gérant le prévient à chaque vente.
if ($account === null || !in_array($account['role'], ['admin', 'vendeur'], true)) pos_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pos_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
@set_time_limit(60);

$in = $_POST;
if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $j = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($j) ? $j : [];
}
$action = (string) ($in['action'] ?? '');

try {
    switch ($action) {
        case 'lookup':
            pos_reply(['ok' => true] + pos_resolve((string) ($in['code'] ?? '')));

        case 'express_photo':
            @set_time_limit(120);   // la rédaction par l'IA peut prendre une minute
            $r = pos_express_create((array) ($_FILES['photo'] ?? []), mb_substr(trim((string) ($in['notes'] ?? '')), 0, 300));
            pos_reply($r, $r['ok'] ? 200 : 422);

        case 'express_save':
            $err = pos_express_save((string) ($in['ref'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['description'] ?? ''));
            pos_reply($err ? ['ok' => false, 'error' => $err] : ['ok' => true], $err ? 422 : 200);

        case 'express_ask':
            $r = pos_appraisal_request((string) ($in['ref'] ?? ''), (string) ($in['note'] ?? ''), (string) ($in['hint'] ?? ''), $account);
            pos_reply($r, $r['ok'] ? 200 : 422);

        case 'express_status':
            pos_reply(['ok' => true] + pos_appraisal_status((string) ($in['ref'] ?? '')));

        case 'express_discard':
            pos_reply(['ok' => true, 'discarded' => pos_express_discard((string) ($in['ref'] ?? ''))]);

        case 'pickup_collect':
        case 'pickup_release':
            if (!pos_plugin('retrait-stand', 'rt')) pos_reply(['ok' => false, 'error' => ext_t('{Le ext} « Retrait sur stand » n\'est pas {?actif|active}.')], 422);
            $id = (int) ($in['id'] ?? 0);
            $err = $action === 'pickup_collect' ? rt_collect($id) : rt_release($id);
            if ($err) pos_reply(['ok' => false, 'error' => $err], 422);
            $oid = (int) db()->query('SELECT COALESCE(order_id, 0) FROM rt_pickups WHERE id = ' . $id)->fetchColumn();
            pos_reply(['ok' => true, 'order_id' => $oid, 'receipt_url' => $oid ? pos_receipt_url($oid) : '']);

        case 'sale_cash':
            pos_release_pending((array) ($in['items'] ?? []));
            [$lines, $err] = pos_lines((array) ($in['items'] ?? []));
            if ($err) pos_reply(['ok' => false, 'error' => $err], 422);
            db()->exec('BEGIN IMMEDIATE');
            $oid = pos_create_order($lines, 'pos_cash_' . bin2hex(random_bytes(8)), 'espèces', $account);
            db()->exec('COMMIT');
            $email = filter_var(trim((string) ($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
            pos_finalize($oid, ['customer_details' => ['email' => $email, 'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80)]]);
            pos_reply(['ok' => true, 'order_id' => $oid, 'total_cents' => array_sum(array_column($lines, 'unit_cents')), 'receipt_url' => pos_receipt_url($oid)]);

        case 'sale_card':
            if (!stripe_configured()) pos_reply(['ok' => false, 'error' => 'Stripe n\'est pas configuré sur cette boutique (Administration → Réglages Stripe).'], 422);
            pos_release_pending((array) ($in['items'] ?? []));
            [$lines, $err] = pos_lines((array) ($in['items'] ?? []));
            if ($err) pos_reply(['ok' => false, 'error' => $err], 422);
            if (array_sum(array_column($lines, 'unit_cents')) < 50) pos_reply(['ok' => false, 'error' => 'Stripe n\'accepte pas un paiement par carte inférieur à 0,50 €.'], 422);
            $params = ['mode' => 'payment', 'locale' => 'fr', 'metadata' => ['type' => 'pos'],
                'success_url' => SITE_URL . '/checkout-success.php?session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => SITE_URL . '/boutique.php',
                'expires_at' => time() + CHECKOUT_HOLD_MINUTES * 60];
            foreach ($lines as $i => $l) {
                $params['line_items'][$i] = ['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $l['unit_cents'], 'product_data' => ['name' => mb_substr($l['name'], 0, 100)]]];
            }
            $session = stripe_request('POST', '/checkout/sessions', $params);
            db()->exec('BEGIN IMMEDIATE');
            $oid = pos_create_order($lines, (string) $session['id'], 'carte', $account);
            db()->exec('COMMIT');
            pos_reply(['ok' => true, 'order_id' => $oid, 'url' => (string) $session['url'], 'total_cents' => array_sum(array_column($lines, 'unit_cents'))]);

        case 'sale_terminal':
            $config = sumup_terminal_config();
            if (!$config) pos_reply(['ok' => false, 'error' => 'Le terminal SumUp n\'est pas appairé (Administration → Paiement → SumUp).'], 422);
            pos_release_pending((array) ($in['items'] ?? []));
            [$lines, $err] = pos_lines((array) ($in['items'] ?? []));
            if ($err) pos_reply(['ok' => false, 'error' => $err], 422);
            $total = array_sum(array_column($lines, 'unit_cents'));
            if ($total <= 0) pos_reply(['ok' => false, 'error' => 'Montant nul : utilisez le paiement en espèces / en main.'], 422);
            $reference = payment_reference();
            db()->exec('BEGIN IMMEDIATE');
            $oid = pos_create_order($lines, 'sumup:' . $reference, 'carte (terminal SumUp)', $account);
            db()->prepare("UPDATE orders SET payment_provider = 'sumup', payment_reference = ?, shop_slug = ? WHERE id = ?")->execute([$reference, tenant_slug(), $oid]);
            db()->exec('COMMIT');
            try {
                $webhook = rtrim(tenant_site_url(), '/') . '/sumup-terminal-webhook.php?order=' . $oid . '&sig=' . rawurlencode(payment_return_token($oid, 'sumup'));
                $clientId = sumup_reader_checkout($config, $total, 'Vente en boutique ' . $reference, $webhook, $reference);
                db()->prepare('UPDATE orders SET provider_checkout_id = ? WHERE id = ?')->execute([$clientId, $oid]);
            } catch (Throwable $e) {
                db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$oid]);
                pos_reply(['ok' => false, 'error' => 'Le terminal n\'a pas accepté le paiement : ' . mb_substr($e->getMessage(), 0, 160)], 502);
            }
            pos_reply(['ok' => true, 'order_id' => $oid, 'total_cents' => $total]);

        case 'sale_status':
        case 'sale_cancel':
            $st = db()->prepare("SELECT * FROM orders WHERE id = ? AND shipping_method LIKE 'Vente en boutique%'");
            $st->execute([(int) ($in['order_id'] ?? 0)]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order) pos_reply(['ok' => false, 'error' => 'Vente introuvable.'], 404);
            if ($order['status'] === 'paid') {
                pos_finalize((int) $order['id'], []);
                pos_reply(['ok' => true, 'status' => 'paid', 'receipt_url' => pos_receipt_url((int) $order['id'])]);
            }
            if ($order['status'] !== 'pending') pos_reply(['ok' => true, 'status' => 'canceled']);
            if (payment_provider_of($order) === 'sumup') {
                // Vente au terminal SumUp : l'état vient de la transaction relue auprès de SumUp.
                if ($action === 'sale_cancel') {
                    $config = sumup_terminal_config();
                    if ($config) { try { sumup_reader_terminate($config); } catch (Throwable $e) { /* rien à abandonner */ } }
                }
                $state = pos_sumup_refresh($order);
                if ($state === 'paid') pos_reply(['ok' => true, 'status' => 'paid', 'receipt_url' => pos_receipt_url((int) $order['id'])]);
                if ($state === 'failed' || $action === 'sale_cancel') {
                    db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$order['id']]);
                    pos_reply(['ok' => true, 'status' => $state === 'failed' ? 'failed' : 'canceled']);
                }
                pos_reply(['ok' => true, 'status' => 'pending']);
            }
            if ($action === 'sale_cancel') {
                try { stripe_request('POST', '/checkout/sessions/' . urlencode($order['stripe_session_id']) . '/expire'); } catch (Throwable $e) { /* déjà payée ou expirée */ }
                $session = null;
                try { $session = stripe_request('GET', '/checkout/sessions/' . urlencode($order['stripe_session_id'])); } catch (Throwable $e) {}
                if (($session['payment_status'] ?? '') === 'paid') {
                    pos_finalize((int) $order['id'], $session);
                    pos_reply(['ok' => true, 'status' => 'paid', 'receipt_url' => pos_receipt_url((int) $order['id'])]);
                }
                db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$order['id']]);
                pos_reply(['ok' => true, 'status' => 'canceled']);
            }
            $session = stripe_request('GET', '/checkout/sessions/' . urlencode($order['stripe_session_id']));
            if (($session['payment_status'] ?? '') === 'paid') {
                pos_finalize((int) $order['id'], $session);
                pos_reply(['ok' => true, 'status' => 'paid', 'receipt_url' => pos_receipt_url((int) $order['id'])]);
            }
            if (($session['status'] ?? '') === 'expired') {
                db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$order['id']]);
                pos_reply(['ok' => true, 'status' => 'expired']);
            }
            pos_reply(['ok' => true, 'status' => 'pending']);

        default:
            pos_reply(['ok' => false, 'error' => 'Action inconnue.'], 422);
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->exec('ROLLBACK');
    error_log('Caisse : ' . $e->getMessage());
    pos_reply(['ok' => false, 'error' => 'Erreur : ' . mb_substr($e->getMessage(), 0, 180)], 500);
}
