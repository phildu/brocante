<?php

// Parcours commande (P0) : journal, retrait sur place, remboursements et
// e-mails transactionnels. Fichier additif : le schéma est créé/complété à
// l'ouverture de la base, les colonnes historiques restent valables.

const ORDER_PICKUP_DAYS = 14;

/** Colonnes et tables du parcours commande (idempotent, appelé à l'ouverture de la base). */
function order_ensure_schema(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if ($columns) {
        $extra = [
            'shop_slug' => 'TEXT',                    // commerce d'origine (e-mails et journal, y compris depuis la plateforme)
            'shipping_mode' => 'TEXT',                // 'pickup' (retrait) ou 'shipping' (expédition)
            'shipping_label' => 'TEXT',               // libellé exact de l'option choisie
            'pickup_code' => 'TEXT',                  // code remis au client pour le retrait
            'pickup_deadline' => 'TEXT',              // date limite de retrait (jour de mise à disposition + 14 jours)
            'ready_at' => 'TEXT',
            'picked_up_at' => 'TEXT',
            'picked_up_by' => 'TEXT',                 // personne ayant remis la commande
            'cancelled_at' => 'TEXT',
            'refunded_cents' => 'INTEGER NOT NULL DEFAULT 0',
            'refunded_at' => 'TEXT',
            'last_refund_id' => 'TEXT',
            'dispute_status' => 'TEXT',               // needs_response, under_review, won, lost…
            'dispute_id' => 'TEXT',
            'stripe_payment_intent' => 'TEXT',
            'payment_provider' => 'TEXT',             // stripe, monetico ou paypal (NULL = commande antérieure, donc Stripe)
            'payment_reference' => 'TEXT',            // référence marchande envoyée au prestataire
            'provider_checkout_id' => 'TEXT',         // référence Monetico ou commande PayPal
            'provider_payment_id' => 'TEXT',          // capture PayPal ou numéro d'autorisation
            'payment_authorization' => 'TEXT',        // numéro d'autorisation Monetico (nécessaire au re-crédit)
            'payment_detail' => 'TEXT',               // libellé lisible (carte masquée, 3DS…)
            'payment_date' => 'TEXT',                 // date du paiement, heure de Paris
            'confirmation_sent_at' => 'TEXT',
        ];
        foreach ($extra as $column => $type) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec('ALTER TABLE orders ADD COLUMN ' . $column . ' ' . $type);
            }
        }
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS order_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  type TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  actor TEXT NOT NULL DEFAULT 'system',
  once_key TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS order_events_order ON order_events(order_id, id)');
    // Un même e-mail (ou une même action ponctuelle) n'est enregistré qu'une fois par commande.
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS order_events_once ON order_events(order_id, once_key) WHERE once_key IS NOT NULL');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS stripe_events (
  id TEXT PRIMARY KEY,
  type TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
}

function order_get(int $orderId, ?PDO $pdo = null): ?array
{
    $st = ($pdo ?? db())->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    return $st->fetch() ?: null;
}

/** Ajoute une ligne au journal de la commande ; $onceKey empêche un doublon pour la même commande. */
function order_event(int $orderId, string $type, string $detail = '', string $actor = 'system', ?string $onceKey = null): bool
{
    $st = db()->prepare('INSERT OR IGNORE INTO order_events (order_id, type, detail, actor, once_key) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$orderId, $type, mb_substr($detail, 0, 1000), mb_substr($actor, 0, 60), $onceKey]);
    return $st->rowCount() > 0;
}

function order_event_seen(int $orderId, string $type): bool
{
    $st = db()->prepare('SELECT 1 FROM order_events WHERE order_id = ? AND type = ? LIMIT 1');
    $st->execute([$orderId, $type]);
    return (bool) $st->fetchColumn();
}

function order_events_for(int $orderId, int $limit = 20): array
{
    $st = db()->prepare('SELECT * FROM order_events WHERE order_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** 'pickup' ou 'shipping' : la colonne fait foi, sinon l'absence d'adresse/frais de port vaut retrait. */
function order_mode(array $order): string
{
    $mode = (string) ($order['shipping_mode'] ?? '');
    if ($mode === 'pickup' || $mode === 'shipping') return $mode;
    return (int) ($order['shipping_cents'] ?? 0) === 0 && empty($order['shipping_address']) ? 'pickup' : 'shipping';
}

function order_is_pickup(array $order): bool
{
    return order_mode($order) === 'pickup';
}

/** Étapes de préparation proposées dans l'administration, selon le mode de livraison. */
function order_fulfillment_steps(array $order): array
{
    return order_is_pickup($order)
        ? ['a_confirmer' => 'À confirmer', 'a_preparer' => 'En préparation', 'pret_retrait' => 'Prêt au retrait', 'retiree' => 'Retirée']
        : ['a_confirmer' => 'À confirmer', 'a_preparer' => 'À préparer', 'expediee' => 'Expédiée', 'livree' => 'Livrée'];
}

/** Code de retrait lisible (sans I, O, 0, 1 pour éviter les confusions au téléphone). */
function order_pickup_code(?PDO $pdo = null): string
{
    $pdo ??= db();
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM orders WHERE pickup_code = ? AND status = 'paid' LIMIT 1");
        $st->execute([$code]);
        if (!$st->fetchColumn()) return $code;
    }
    return strtoupper(bin2hex(random_bytes(3)));
}

function order_remaining_refund(array $order): int
{
    return max(0, (int) $order['amount_total'] - (int) ($order['refunded_cents'] ?? 0));
}

/** Remplit les champs de paiement (intent, mode, code de retrait) et place la commande à la bonne première étape. */
function order_prepare_after_payment(array $order, array $session): void
{
    $pi = $session['payment_intent'] ?? null;
    $pi = is_array($pi) ? (string) ($pi['id'] ?? '') : (string) $pi;
    $mode = (string) ($order['shipping_mode'] ?? '');
    if ($mode === '') {
        $paidShipping = (int) ($session['shipping_cost']['amount_total'] ?? $order['shipping_cents'] ?? 0);
        $mode = $paidShipping > 0 || !empty($order['shipping_address']) ? 'shipping' : 'pickup';
    }
    $code = (string) ($order['pickup_code'] ?? '');
    if ($code === '' && $mode === 'pickup') $code = order_pickup_code();
    $firstStep = $mode === 'pickup' ? 'a_confirmer' : 'a_preparer';
    db()->prepare("UPDATE orders SET
            stripe_payment_intent = COALESCE(NULLIF(stripe_payment_intent, ''), ?),
            shipping_mode = CASE WHEN shipping_mode IS NULL OR shipping_mode = '' THEN ? ELSE shipping_mode END,
            pickup_code = COALESCE(NULLIF(pickup_code, ''), ?),
            fulfillment_status = CASE WHEN fulfillment_status IS NULL OR fulfillment_status = '' OR fulfillment_status = 'a_preparer' THEN ? ELSE fulfillment_status END
        WHERE id = ?")
        ->execute([$pi !== '' ? $pi : null, $mode, $code !== '' ? $code : null, $firstStep, (int) $order['id']]);
}

// ── E-mails ────────────────────────────────────────────────────────────────

/** Nom et domaine du commerce d'origine de la commande (valables même depuis le webhook de la plateforme). */
function order_mail_identity(array $order): array
{
    $slug = (string) ($order['shop_slug'] ?? '');
    $config = [];
    if ($slug !== '' && is_file(tenant_dir($slug) . '/tenant.php')) {
        try {
            $config = tenant_load($slug);
        } catch (Throwable $e) {
        }
    }
    if (!$config) $config = function_exists('tenant') ? tenant() : [];
    $name = trim((string) ($config['name'] ?? ''));
    $host = strtolower((string) parse_url((string) ($config['site_url'] ?? ''), PHP_URL_HOST));
    if ($host === '') $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return [$name !== '' ? $name : 'Votre boutique', $host];
}

function order_mail_body_lines(array $order): string
{
    $lines = [];
    foreach (json_decode((string) ($order['items'] ?? '[]'), true) ?: [] as $item) {
        $lines[] = (int) $item['qty'] . ' × ' . $item['name'] . ' — ' . format_cents((int) $item['unit_cents'] * (int) $item['qty']);
    }
    return implode("\n", $lines);
}

function order_mail_signature(array $order): string
{
    [$name] = order_mail_identity($order);
    return "\n\nÀ bientôt,\n$name";
}

/**
 * Envoie un e-mail de commande une seule fois (clé $event), sauf $force.
 * En local, le message est recopié dans data/mail-outbox.log.
 */
function order_send_email(int $orderId, string $event, string $subject, string $body, bool $force = false): bool
{
    $order = order_get($orderId);
    if (!$order || empty($order['email']) || !filter_var($order['email'], FILTER_VALIDATE_EMAIL)) return false;
    $marker = 'email_' . $event;
    if (!$force && order_event_seen($orderId, $marker)) return false;

    require_once __DIR__ . '/mail-tracking.php';
    [$name, $host] = order_mail_identity($order);
    $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.test')
        || filter_var($host, FILTER_VALIDATE_IP) !== false || mail_open_is_local_host();
    $fromHost = $local ? 'localhost.localdomain' : $host;
    $ok = mail_open_send((string) $order['email'], $subject, $body, 'commande', [
        'from' => 'From: ' . str_replace(['"', "\r", "\n"], '', $name) . ' <noreply@' . $fromHost . '>',
        'log' => dirname(__DIR__) . '/data/mail-outbox.log',
        'local' => $local,
        'context' => 'order:' . $orderId,
    ]);
    if ($ok) {
        order_event($orderId, $marker, $subject, 'system', $marker);
        if ($event === 'confirmation') {
            db()->prepare('UPDATE orders SET confirmation_sent_at = COALESCE(confirmation_sent_at, ?) WHERE id = ?')->execute([gmdate('c'), $orderId]);
        }
    }
    return $ok;
}

function order_send_confirmation(int $orderId, bool $force = false): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $pickup = order_is_pickup($order);
    $content = function_exists('get_content') ? get_content() : [];
    $body = "Bonjour,\n\nMerci pour votre commande n° " . (int) $order['id'] . " sur $name.\n\n"
        . order_mail_body_lines($order) . "\n\n"
        . 'Total payé : ' . format_cents((int) $order['amount_total'])
        . ((int) $order['shipping_cents'] > 0 ? ' (dont ' . format_cents((int) $order['shipping_cents']) . ' de livraison)' : '') . "\n\n"
        . ($pickup
            ? "Mode : retrait sur place" . (!empty($content['pickup_label']) ? ' — ' . $content['pickup_label'] : '') . "\n"
                . (!empty($order['pickup_code']) ? "Code de retrait : " . $order['pickup_code'] . "\n" : '')
                . "Nous vous préviendrons dès que votre commande sera prête.\n"
            : 'Mode : ' . ($order['shipping_label'] ?: 'expédition') . "\n"
                . (!empty($order['shipping_address']) ? "Adresse : " . $order['shipping_address'] . "\n" : '')
                . "Nous vous préviendrons dès que le colis sera expédié.\n")
        . order_mail_signature($order);
    return order_send_email($orderId, 'confirmation', "Votre commande n° " . (int) $order['id'] . " — $name", $body, $force);
}

function order_send_ready(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $content = function_exists('get_content') ? get_content() : [];
    $deadline = !empty($order['pickup_deadline']) ? date('d/m/Y', strtotime((string) $order['pickup_deadline'])) : '';
    $body = "Bonjour,\n\nVotre commande n° " . (int) $order['id'] . " est prête à être retirée.\n\n"
        . (!empty($order['pickup_code']) ? "Code de retrait : " . $order['pickup_code'] . "\n" : '')
        . (!empty($content['pickup_label']) ? 'Lieu : ' . $content['pickup_label'] . "\n" : '')
        . ($deadline !== '' ? "Merci de venir avant le $deadline.\n" : '')
        . "\nPrésentez ce code au vendeur pour retirer votre commande."
        . order_mail_signature($order);
    return order_send_email($orderId, 'pret_retrait', "Votre commande n° " . (int) $order['id'] . " est prête — $name", $body);
}

function order_send_shipped(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $tracking = trim((string) ($order['tracking_number'] ?? ''));
    $body = "Bonjour,\n\nVotre commande n° " . (int) $order['id'] . " vient d'être expédiée.\n\n"
        . ($order['shipping_label'] ? 'Mode : ' . $order['shipping_label'] . "\n" : '')
        . ($tracking !== '' ? "Numéro de suivi : $tracking\n" : '')
        . (!empty($order['shipping_address']) ? "Adresse : " . $order['shipping_address'] . "\n" : '')
        . "\nLe colis est sous la responsabilité du vendeur jusqu'à sa remise."
        . order_mail_signature($order);
    return order_send_email($orderId, 'expediee', "Votre commande n° " . (int) $order['id'] . " est expédiée — $name", $body);
}

function order_send_delivered(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $body = "Bonjour,\n\nVotre commande n° " . (int) $order['id'] . " est indiquée comme livrée.\n\n"
        . "Si vous ne l'avez pas reçue, répondez à ce message ou contactez directement le vendeur."
        . order_mail_signature($order);
    return order_send_email($orderId, 'livree', "Votre commande n° " . (int) $order['id'] . " est livrée — $name", $body);
}

function order_send_picked_up(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $body = "Bonjour,\n\nNous confirmons le retrait de votre commande n° " . (int) $order['id'] . ".\n\n"
        . order_mail_body_lines($order) . "\n\nMerci de votre visite !"
        . order_mail_signature($order);
    return order_send_email($orderId, 'retiree', "Retrait confirmé — commande n° " . (int) $order['id'] . " — $name", $body);
}

function order_send_refund(int $orderId, int $cents, bool $full): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $body = "Bonjour,\n\nUn remboursement de " . format_cents($cents) . " a été effectué pour votre commande n° " . (int) $order['id'] . ".\n\n"
        . ($full
            ? "La commande est intégralement remboursée.\n"
            : 'Montant total remboursé : ' . format_cents((int) $order['refunded_cents']) . ' sur ' . format_cents((int) $order['amount_total']) . ".\n")
        . "\nLe montant est recrédité sur votre moyen de paiement d'origine. Selon la banque, cela peut prendre 5 à 10 jours ouvrés."
        . order_mail_signature($order);
    return order_send_email($orderId, 'remboursement_' . (int) ($order['refunded_cents'] ?? $cents), "Remboursement de votre commande n° " . (int) $order['id'] . " — $name", $body);
}

// ── Actions d'administration ───────────────────────────────────────────────

function order_mark_ready(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order || $order['status'] !== 'paid' || !order_is_pickup($order)) return false;
    if (!empty($order['picked_up_at'])) return false;
    $deadline = gmdate('Y-m-d H:i:s', time() + ORDER_PICKUP_DAYS * 86400);
    db()->prepare("UPDATE orders SET ready_at = COALESCE(ready_at, ?), pickup_deadline = ?, fulfillment_status = 'pret_retrait' WHERE id = ?")
        ->execute([gmdate('c'), $deadline, $orderId]);
    order_event($orderId, 'pret_retrait', 'Commande prête, retrait possible jusqu\'au ' . date('d/m/Y', strtotime($deadline)), 'admin');
    order_send_ready($orderId);
    return true;
}

function order_confirm_pickup(int $orderId, string $code, string $by = ''): array
{
    $order = order_get($orderId);
    if (!$order) return ['ok' => false, 'message' => 'Commande introuvable.'];
    if ($order['status'] !== 'paid') return ['ok' => false, 'message' => 'La commande n\'est pas payée.'];
    if (!order_is_pickup($order)) return ['ok' => false, 'message' => 'Cette commande est expédiée, pas retirée.'];
    if (!empty($order['picked_up_at'])) return ['ok' => false, 'message' => 'Cette commande est déjà marquée comme retirée.'];
    $code = strtoupper(trim($code));
    if ($code === '' || empty($order['pickup_code']) || !hash_equals(strtoupper((string) $order['pickup_code']), $code)) {
        return ['ok' => false, 'message' => 'Code de retrait incorrect.'];
    }
    db()->prepare("UPDATE orders SET picked_up_at = ?, picked_up_by = ?, fulfillment_status = 'retiree' WHERE id = ?")
        ->execute([gmdate('c'), $by !== '' ? mb_substr($by, 0, 80) : null, $orderId]);
    order_event($orderId, 'retiree', 'Remise au client' . ($by !== '' ? ' par ' . $by : '') . ' (code vérifié)', 'admin');
    order_send_picked_up($orderId);
    return ['ok' => true, 'message' => 'Retrait enregistré.'];
}

function order_mark_shipped(int $orderId, string $tracking = ''): bool
{
    $order = order_get($orderId);
    if (!$order || $order['status'] !== 'paid' || order_is_pickup($order)) return false;
    $tracking = trim($tracking);
    db()->prepare("UPDATE orders SET fulfillment_status = 'expediee', tracking_number = COALESCE(NULLIF(?, ''), tracking_number) WHERE id = ?")
        ->execute([$tracking, $orderId]);
    order_event($orderId, 'expediee', 'Colis expédié' . ($tracking !== '' ? ' — suivi ' . $tracking : ''), 'admin');
    order_send_shipped($orderId);
    return true;
}

function order_mark_delivered(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order || $order['status'] !== 'paid' || order_is_pickup($order)) return false;
    db()->prepare("UPDATE orders SET fulfillment_status = 'livree' WHERE id = ?")->execute([$orderId]);
    order_event($orderId, 'livree', 'Commande indiquée comme livrée', 'admin');
    order_send_delivered($orderId);
    return true;
}

function order_cancel(int $orderId, string $reason = ''): array
{
    $order = order_get($orderId);
    if (!$order) return ['ok' => false, 'message' => 'Commande introuvable.'];
    if ($order['status'] === 'cancelled') return ['ok' => false, 'message' => 'Commande déjà annulée.'];
    if ($order['status'] === 'paid' || (int) ($order['refunded_cents'] ?? 0) > 0) {
        return ['ok' => false, 'message' => 'Commande payée : utilisez le remboursement.'];
    }
    db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ?")->execute([gmdate('c'), $orderId]);
    order_event($orderId, 'annulee', $reason !== '' ? $reason : 'Commande annulée avant paiement', 'admin');
    return ['ok' => true, 'message' => 'Commande annulée.'];
}

/** Remette les articles d'une commande en stock (une seule fois par commande). */
function order_restock(int $orderId): void
{
    $order = order_get($orderId);
    if (!$order || !order_event($orderId, 'stock_restored', 'Articles remis en stock', 'admin', 'stock_restored')) return;
    $st = db()->prepare('UPDATE products SET stock = MIN(stock + ?, 9999), restocked_at = CURRENT_TIMESTAMP WHERE ref = ?');
    foreach (json_decode((string) $order['items'], true) ?: [] as $item) {
        $st->execute([(int) $item['qty'], (string) $item['ref']]);
    }
}

/**
 * Rembourse tout ou partie d'une commande payée via Stripe, Monetico ou PayPal.
 * Pour un paiement Stripe Connect (destination charge), le transfert et la
 * commission sont repris au commerçant/à la galerie (reverse_transfer +
 * refund_application_fee). Monetico passe par le re-crédit, PayPal par le
 * remboursement de la capture.
 * -> ['ok' => bool, 'message' => string, 'full' => bool]
 */
function order_refund(int $orderId, int $cents, string $reason = '', bool $restock = false, string $actor = 'admin'): array
{
    $order = order_get($orderId);
    if (!$order) return ['ok' => false, 'message' => 'Commande introuvable.', 'full' => false];
    if (!in_array($order['status'], ['paid', 'refunded'], true) && (int) ($order['refunded_cents'] ?? 0) === 0) {
        return ['ok' => false, 'message' => 'Seule une commande payée peut être remboursée.', 'full' => false];
    }
    $remaining = order_remaining_refund($order);
    if ($remaining <= 0) return ['ok' => false, 'message' => 'Cette commande est déjà intégralement remboursée.', 'full' => true];
    if ($cents <= 0) return ['ok' => false, 'message' => 'Montant de remboursement invalide.', 'full' => false];
    if ($cents > $remaining) $cents = $remaining;
    $full = $cents >= $remaining;

    $provider = payment_provider_of($order);
    try {
        if ($provider === 'monetico') {
            $monetico = monetico_config();
            if (!$monetico) throw new RuntimeException("Monetico n'est plus configuré pour cette boutique.");
            $refundId = monetico_refund($order, $cents, $monetico);
        } elseif ($provider === 'paypal') {
            $paypal = paypal_config();
            if (!$paypal) throw new RuntimeException("PayPal n'est plus configuré pour cette boutique.");
            $refundId = paypal_refund($order, $cents, $paypal);
        } else {
            $pi = trim((string) ($order['stripe_payment_intent'] ?? ''));
            $connect = ($order['payment_via'] ?? '') === 'connect';
            if ($pi === '') {
                $session = stripe_request('GET', '/checkout/sessions/' . urlencode((string) $order['stripe_session_id']), [], $connect);
                $raw = $session['payment_intent'] ?? null;
                $pi = is_array($raw) ? (string) ($raw['id'] ?? '') : (string) $raw;
                if ($pi === '') return ['ok' => false, 'message' => 'Paiement Stripe introuvable pour cette commande.', 'full' => false];
                db()->prepare('UPDATE orders SET stripe_payment_intent = ? WHERE id = ?')->execute([$pi, $orderId]);
            }
            $params = [
                'payment_intent' => $pi,
                'amount' => $cents,
                'reason' => 'requested_by_customer',
                'metadata' => ['order_id' => (string) $orderId, 'shop' => (string) ($order['shop_slug'] ?? tenant_slug())],
            ];
            if ($connect) {
                $params['reverse_transfer'] = 'true';
                $params['refund_application_fee'] = 'true';
            }
            $refund = stripe_request('POST', '/refunds', $params, $connect);
            $refundId = (string) ($refund['id'] ?? '');
        }
    } catch (Throwable $e) {
        order_event($orderId, 'remboursement_echec', $e->getMessage(), $actor);
        return ['ok' => false, 'message' => payment_provider_label($provider) . ' a refusé le remboursement : ' . $e->getMessage(), 'full' => false];
    }
    $newTotal = (int) ($order['refunded_cents'] ?? 0) + $cents;
    $status = $full ? 'refunded' : 'paid';
    db()->prepare('UPDATE orders SET refunded_cents = ?, refunded_at = ?, last_refund_id = ?, status = ? WHERE id = ?')
        ->execute([$newTotal, gmdate('c'), $refundId !== '' ? $refundId : null, $status, $orderId]);
    order_event($orderId, 'remboursement', format_cents($cents) . ' remboursés' . ($reason !== '' ? ' — ' . $reason : '') . ($refundId !== '' ? ' (' . $refundId . ')' : ''), $actor);
    if ($full && $restock) order_restock($orderId);
    $fresh = order_get($orderId) ?: $order;
    order_send_refund($orderId, $cents, $full);
    return ['ok' => true, 'message' => format_cents($cents) . ' remboursés' . ($full ? ' (commande intégralement remboursée)' : ''), 'full' => $full, 'order' => $fresh];
}

// ── Événements Stripe ──────────────────────────────────────────────────────

function order_stripe_signature_valid(string $payload, string $header, string $secret): bool
{
    $time = 0;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $time = (int) $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if ($time === 0 || abs(time() - $time) > 300) return false;
    $expected = hash_hmac('sha256', $time . '.' . $payload, $secret);
    foreach ($sigs as $sig) if (hash_equals($expected, $sig)) return true;
    return false;
}

function order_by_session(string $sessionId, ?PDO $pdo = null): ?array
{
    if ($sessionId === '') return null;
    $st = ($pdo ?? db())->prepare('SELECT * FROM orders WHERE stripe_session_id = ?');
    $st->execute([$sessionId]);
    return $st->fetch() ?: null;
}

function order_by_payment_intent(string $paymentIntent, ?PDO $pdo = null): ?array
{
    if ($paymentIntent === '') return null;
    $st = ($pdo ?? db())->prepare('SELECT * FROM orders WHERE stripe_payment_intent = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$paymentIntent]);
    return $st->fetch() ?: null;
}

/**
 * Applique un événement Stripe au parcours d'une commande (paiement, expiration,
 * remboursement, litige). Idempotent : l'identifiant de l'événement est noté
 * dans stripe_events. -> état lisible (journalisé par l'appelant).
 */
function order_apply_stripe_event(array $event, ?PDO $pdo = null): string
{
    $pdo ??= db();
    $type = (string) ($event['type'] ?? '');
    $obj = (array) ($event['data']['object'] ?? []);
    if ($type === '') return 'événement sans type';

    $eventId = (string) ($event['id'] ?? '');
    if ($eventId !== '') {
        $st = $pdo->prepare('INSERT OR IGNORE INTO stripe_events (id, type) VALUES (?, ?)');
        $st->execute([$eventId, $type]);
        if ($st->rowCount() === 0) return 'déjà traité';
    }

    $meta = (array) ($obj['metadata'] ?? []);
    $isShopOrder = ($meta['type'] ?? '') === 'shop_order';

    if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        if (!$isShopOrder) return 'ignoré';
        if (($obj['payment_status'] ?? '') !== 'paid') return 'paiement en attente';
        $order = order_by_session((string) ($obj['id'] ?? ''), $pdo);
        if (!$order) return 'commande inconnue';
        if ($order['status'] === 'paid') return 'déjà payée';
        return order_mark_paid($order, $obj) ? 'commande payée' : 'déjà payée';
    }

    if (in_array($type, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
        if (!$isShopOrder) return 'ignoré';
        $order = order_by_session((string) ($obj['id'] ?? ''), $pdo);
        if (!$order || $order['status'] !== 'pending') return 'sans effet';
        $pdo->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ?")->execute([gmdate('c'), (int) $order['id']]);
        order_event((int) $order['id'], 'annulee', $type === 'checkout.session.expired' ? 'Page de paiement expirée' : 'Paiement différé échoué', 'stripe');
        return 'commande annulée';
    }

    if ($type === 'charge.refunded') {
        $order = order_by_payment_intent((string) ($obj['payment_intent'] ?? ''), $pdo);
        if (!$order) return 'commande inconnue';
        $before = (int) ($order['refunded_cents'] ?? 0);
        $amount = (int) ($obj['amount_refunded'] ?? 0);
        if ($amount <= $before) return 'sans effet';
        $full = $amount >= (int) $order['amount_total'];
        $pdo->prepare('UPDATE orders SET refunded_cents = ?, refunded_at = ?, last_refund_id = ?, status = ? WHERE id = ?')
            ->execute([$amount, gmdate('c'), (string) ($obj['id'] ?? ''), $full ? 'refunded' : 'paid', (int) $order['id']]);
        order_event((int) $order['id'], 'remboursement', format_cents($amount - $before) . ' remboursés depuis Stripe' . ($full ? ' — commande intégralement remboursée' : ''), 'stripe');
        order_send_refund((int) $order['id'], $amount - $before, $full);
        return 'remboursement noté';
    }

    if (in_array($type, ['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed'], true)) {
        $order = order_by_payment_intent((string) ($obj['payment_intent'] ?? ''), $pdo);
        if (!$order) return 'commande inconnue';
        $status = (string) ($obj['status'] ?? '');
        $pdo->prepare('UPDATE orders SET dispute_status = ?, dispute_id = ? WHERE id = ?')
            ->execute([$status, (string) ($obj['id'] ?? ''), (int) $order['id']]);
        $label = match ($status) {
            'won' => 'litige gagné',
            'lost' => 'litige perdu',
            'under_review' => 'litige en cours d\'examen',
            default => 'litige ouvert — réponse attendue',
        };
        order_event((int) $order['id'], 'litige', $label . ' (' . format_cents((int) ($obj['amount'] ?? 0)) . ')', 'stripe');
        return $label;
    }

    return 'ignoré';
}

/** Traite un événement Connect pour la boutique $slug (base propre au commerce, contexte basculé). */
function order_apply_connect_event(string $slug, array $event): string
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) || !is_file(tenant_dir($slug) . '/tenant.php')) return 'commerce inconnu';
    require_once __DIR__ . '/plugins.php';
    [$pdo] = plugin_open_tenant_db($slug);
    order_ensure_schema($pdo);
    $previous = $GLOBALS['db_pdo_override'] ?? null;
    $GLOBALS['db_pdo_override'] = $pdo;
    try {
        return order_apply_stripe_event($event, $pdo);
    } finally {
        if ($previous === null) unset($GLOBALS['db_pdo_override']); else $GLOBALS['db_pdo_override'] = $previous;
    }
}

/** Commerce dont le compte Stripe Connect est $account (data/saas/connect-rates.json), ou ''. */
function order_shop_for_account(string $account): string
{
    if ($account === '') return '';
    $file = dirname(__DIR__) . '/data/saas/connect-rates.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) return '';
    foreach ($data as $slug => $entry) {
        if (is_array($entry) && (string) ($entry['account'] ?? '') === $account) return (string) $slug;
    }
    return '';
}
