<?php
// Caisse du Studio : lecture d'un QR code (billet de retrait, reçu, pièce) et vente directe en boutique (espèces ou carte via Stripe).

require_once __DIR__ . '/pos-express.php';

const POS_MAX_LINES = 20;
const POS_MAX_CENTS = 10000000;

/** Un plugin est-il actif, à jour, et son module souscrit (ou en essai) ? Charge alors sa bibliothèque. */
function pos_plugin(string $id, string $lib): bool
{
    require_once __DIR__ . '/plugins.php';
    require_once __DIR__ . '/addons.php';
    try {
        if (plugin_shop_status($id) !== 'ready' || !addon_is_entitled($id)) return false;
    } catch (Throwable $e) {
        return false;
    }
    if (!defined('PLUGIN_RUNTIME')) define('PLUGIN_RUNTIME', true);
    require_once plugins_dir() . '/' . $id . '/lib/' . $lib . '.php';
    return true;
}

function pos_product_json(array $p): array
{
    return [
        'ref' => $p['ref'], 'name' => $p['name'], 'stock' => (int) $p['stock'],
        'price' => (string) effective_price($p), 'price_cents' => price_to_cents((string) effective_price($p)),
        'fixed' => pos_catalog_cents($p) !== null,
        'free' => product_free_qty($p) >= 1, 'photo' => !empty($p['photo']) ? '/' . ltrim((string) $p['photo'], '/') : '',
    ];
}

/** Reconnaît ce que le scanner a lu. -> ['type' => pickup|receipt|product|none, ...] */
function pos_resolve(string $code): array
{
    $code = trim($code);
    if ($code === '' || mb_strlen($code) > 600) return ['type' => 'none'];

    // 1. Billet de retrait sur stand (module « Retrait sur stand »)
    if ((preg_match('#/api/retrait/billet/([a-f0-9]{32})#', $code, $m) || preg_match('/^[A-Za-z0-9]{6}$/', $code)) && pos_plugin('retrait-stand', 'rt')) {
        $r = rt_find($m[1] ?? $code);
        if ($r) {
            $valid = $r['status'] === 'authorized' && (int) $r['expires_at'] > time();
            return ['type' => 'pickup', 'id' => (int) $r['id'], 'code' => $r['code'], 'status' => $r['status'], 'valid' => $valid, 'name' => $r['name'], 'email' => $r['email'],
                'phone' => $r['phone'], 'product' => $r['product_name'], 'ref' => $r['product_ref'], 'amount_cents' => (int) $r['amount_cents'], 'expires_at' => (int) $r['expires_at']];
        }
    }

    // 2. Reçu d'achat (module « Reçus & journal des ventes ») : preuve d'un achat déjà payé
    if (preg_match('#/api/recu/([a-f0-9]{32})#', $code, $m) && pos_plugin('recus-compta', 'rc')) {
        $st = db()->prepare('SELECT * FROM rc_receipts WHERE token = ?');
        $st->execute([$m[1]]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $d = json_decode((string) $r['data'], true) ?: [];
            return ['type' => 'receipt', 'number' => $r['number'], 'email' => $r['email'], 'total_cents' => (int) ($d['total_cents'] ?? 0), 'paid_at' => (string) ($d['paid_at'] ?? ''), 'lines' => $d['lines'] ?? []];
        }
    }

    // 3. Pièce : adresse de sa fiche (QR des étiquettes), référence ou code-barres
    $ref = '';
    if (preg_match('#produit\.php\?(?:[^\#]*&)?ref=([A-Za-z0-9_-]{1,40})#', $code, $m)) $ref = $m[1];
    elseif (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code)) $ref = $code;
    $p = null;
    if ($ref !== '') $p = get_product($ref);
    if (!$p && preg_match('/^\d{8,14}$/', $code)) {
        $st = db()->prepare('SELECT ref FROM products WHERE barcode = ? LIMIT 1');
        $st->execute([$code]);
        if ($r = $st->fetchColumn()) $p = get_product((string) $r);
    }
    if ($p) return ['type' => 'product'] + pos_product_json($p);
    return ['type' => 'none'];
}

/** Valide un panier : [[ref, price_cents]] → [lignes, erreur]. */
function pos_lines(array $items): array
{
    if (!$items) return [[], 'Le panier est vide.'];
    if (count($items) > POS_MAX_LINES) return [[], POS_MAX_LINES . ' pièces au plus par vente.'];
    $lines = [];
    $seen = [];
    foreach ($items as $it) {
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($it['ref'] ?? ''));
        $p = $ref !== '' ? get_product($ref) : null;
        if (!$p) return [[], 'Pièce introuvable : ' . $ref];
        if (isset($seen[$ref])) return [[], '« ' . $p['name'] . ' » est déjà dans le panier.'];
        $seen[$ref] = true;
        if (!in_stock($p)) return [[], '« ' . $p['name'] . ' » n\'est plus en stock.'];
        if (product_free_qty($p) < 1) return [[], '« ' . $p['name'] . ' » est réservée ou en cours de paiement par un autre client (scannez son billet).'];
        // Prix du catalogue s'il est unique ; sinon (vide, 0 €, fourchette, sur devis, pièce express) il est convenu sur place et doit être saisi.
        $catalog = pos_catalog_cents($p);
        $cents = $it['price_cents'] ?? null;
        if ($cents === null || $cents === '') $cents = $catalog;
        if ($cents === null) return [[], 'Indiquez le prix convenu pour « ' . $p['name'] . ' ».'];
        $cents = (int) $cents;
        if ($cents < 0 || $cents > POS_MAX_CENTS) return [[], 'Prix invalide pour « ' . $p['name'] . ' ».'];
        if ($cents === 0 && $catalog === null) return [[], 'Indiquez le prix convenu pour « ' . $p['name'] . ' » : une vente à 0 € n\'a pas de sens au journal.'];
        $line = ['ref' => $p['ref'], 'name' => $p['name'], 'qty' => 1, 'unit_cents' => $cents];
        if ($catalog === null || $cents !== $catalog) $line['negotiated'] = true;   // prix non fixé à l'avance, ou différent de l'étiquette
        if (($p['badge'] ?? '') === POS_EXPRESS_BADGE) $line['express'] = true;      // pièce hors catalogue, photographiée en caisse
        $lines[] = $line;
    }
    return [$lines, null];
}

/**
 * Ventes par carte de la caisse restées en attente (client parti, application fermée) : leurs pièces restent retenues 31 minutes.
 * Avant une nouvelle vente de ces mêmes pièces, on ferme la page de paiement oubliée ; si le client avait payé entre-temps, la vente est enregistrée.
 */
function pos_release_pending(array $items): void
{
    $refs = array_map('strval', array_column($items, 'ref'));
    if (!$refs) return;
    $rows = db()->query("SELECT * FROM orders WHERE status = 'pending' AND shipping_method LIKE 'Vente en boutique%' AND created_at >= datetime('now', '-" . CHECKOUT_HOLD_MINUTES . " minutes')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $o) {
        $inOrder = array_map('strval', array_column(json_decode((string) $o['items'], true) ?: [], 'ref'));
        if (!array_intersect($refs, $inOrder)) continue;
        if (payment_provider_of($o) === 'sumup') {
            // Vente au terminal SumUp oubliée : on relit la transaction avant de libérer les pièces.
            try {
                $state = pos_sumup_refresh($o);
                if ($state === 'pending') {
                    $config = sumup_terminal_config();
                    if ($config) { try { sumup_reader_terminate($config); } catch (Throwable $e) { /* rien à abandonner */ } }
                    $state = pos_sumup_refresh($o);
                }
            } catch (Throwable $e) {
                $state = 'pending';
            }
            if ($state !== 'paid') db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$o['id']]);
            continue;
        }
        $session = null;
        try { stripe_request('POST', '/checkout/sessions/' . urlencode($o['stripe_session_id']) . '/expire'); } catch (Throwable $e) { /* déjà payée ou expirée */ }
        try { $session = stripe_request('GET', '/checkout/sessions/' . urlencode($o['stripe_session_id'])); } catch (Throwable $e) {}
        if (($session['payment_status'] ?? '') === 'paid') pos_finalize((int) $o['id'], $session);
        else db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$o['id']]);
    }
}

/** Crée la commande en attente. -> id. */
function pos_create_order(array $lines, string $sessionId, string $method, ?array $account = null): int
{
    $total = array_sum(array_map(static fn ($l) => $l['unit_cents'] * $l['qty'], $lines));
    [$soldBy, $soldById, $notify] = pos_seller_fields($account);
    $negotiated = array_filter($lines, static fn ($l) => !empty($l['negotiated'])) ? 1 : 0;
    db()->prepare("INSERT INTO orders (stripe_session_id, amount_total, status, items, shipping_method, sold_by, sold_by_id, price_negotiated, notify_manager) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?)")
        ->execute([$sessionId, $total, json_encode($lines, JSON_UNESCAPED_UNICODE), 'Vente en boutique — ' . $method, $soldBy, $soldById, $negotiated, $notify]);
    return (int) db()->lastInsertId();
}

/** Commande payée : stock, compte client, reçu et registre (via order_mark_paid), pièce « vendue sur le stand », remise en main propre. */
function pos_finalize(int $orderId, array $session): bool
{
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) return false;
    $session += ['shipping_cost' => ['amount_total' => 0], 'amount_total' => (int) $order['amount_total']];
    $changed = $order['status'] !== 'paid' ? order_mark_paid($order, $session) : false;
    foreach (json_decode((string) $order['items'], true) ?: [] as $l) {
        db()->prepare("UPDATE products SET sold_channel = 'stand', sold_at = COALESCE(sold_at, CURRENT_TIMESTAMP) WHERE ref = ? AND stock <= 0 AND (sold_channel IS NULL OR sold_channel = '')")->execute([$l['ref']]);
    }
    db()->prepare("UPDATE orders SET fulfillment_status = 'livree' WHERE id = ? AND status = 'paid' AND shipping_method LIKE 'Vente en boutique%'")->execute([$orderId]);
    if ($changed || $order['status'] === 'paid') {
        pos_express_sold($order);        // pièce hors catalogue : le prix convenu devient le prix de sa fiche
        pos_notify_manager($orderId);    // autre vendeur que le gérant : un message, une seule fois par vente
    }
    return $changed;
}

/** Lien du reçu d'une commande (module Reçus), ou ''. */
function pos_receipt_url(int $orderId): string
{
    try {
        if (!pos_plugin('recus-compta', 'rc')) return '';
        $st = db()->prepare('SELECT * FROM rc_receipts WHERE order_id = ?');
        $st->execute([$orderId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? rc_public_url($r) : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Vente au terminal SumUp : relit la transaction auprès de SumUp (jamais d'après un webhook seul)
 * et renvoie 'paid' (vente enregistrée), 'failed' (refusée ou abandonnée) ou 'pending'.
 */
function pos_sumup_refresh(array $order): string
{
    $config = sumup_terminal_config() ?? sumup_config();
    if (!$config) throw new RuntimeException('SumUp n’est plus configuré pour cette boutique.');
    $transaction = sumup_transaction_by_client_id($config, (string) ($order['provider_checkout_id'] ?? ''));
    if (sumup_transaction_paid($transaction)) return pos_sumup_settle($order, $transaction) ? 'paid' : 'pending';
    if (sumup_transaction_failed($transaction)) return 'failed';
    return 'pending';
}

/** Enregistre la vente d'un paiement SumUp réussi (montant et devise contrôlés). Faux si le contrôle échoue. */
function pos_sumup_settle(array $order, array $transaction): bool
{
    $orderId = (int) $order['id'];
    if (sumup_cents($transaction['amount'] ?? -1) !== (int) $order['amount_total'] || strtoupper((string) ($transaction['currency'] ?? '')) !== 'EUR') {
        order_event($orderId, 'paiement_echec', 'Montant SumUp incohérent : ' . sumup_cents($transaction['amount'] ?? 0) . ' centimes au lieu de ' . (int) $order['amount_total'], 'sumup');
        return false;
    }
    db()->prepare("UPDATE orders SET payment_provider = 'sumup', provider_payment_id = ?, payment_detail = ?, payment_date = ? WHERE id = ?")
        ->execute([(string) ($transaction['id'] ?? ''), sumup_terminal_detail($transaction), gmdate('c'), $orderId]);
    pos_finalize($orderId, []);
    return true;
}
