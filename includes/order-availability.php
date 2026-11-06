<?php

// Validation de la disponibilité par le vendeur avant paiement.
//
// Beaucoup de pièces sont uniques et peuvent être vendues en boutique pendant
// qu'un client les commande en ligne. Quand l'option est active, le passage à
// la caisse ne crée pas de paiement : il enregistre une demande (statut
// « awaiting »), prévient le vendeur par SMS, WhatsApp ou e-mail avec un lien
// signé, puis envoie au client un lien de paiement quand le vendeur a confirmé.
// Un refus (pièce vendue entre-temps) annule la demande : rien n'est prélevé.
//
// Les liens sont signés (HMAC) et datés : le vendeur peut répondre depuis son
// téléphone sans se connecter, le client peut suivre sa demande sans compte.

const OA_REQUEST_DAYS = 7;   // délai laissé au vendeur pour répondre
const OA_BUYER_LINK_DAYS = 30;   // le client peut rouvrir le suivi de sa demande au-delà

require_once __DIR__ . '/share.php';   // shop_abs_url() pour les liens signés

// ── Réglages ───────────────────────────────────────────────────────────────

function oa_enabled(): bool
{
    try {
        return shop_setting('oa_enabled', '0') === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/** Canal demandé : auto (WhatsApp puis SMS puis e-mail), sms, whatsapp ou email. */
function oa_channel(): string
{
    try {
        $channel = shop_setting('oa_channel', 'auto');
    } catch (Throwable $e) {
        $channel = 'auto';
    }
    return in_array($channel, ['auto', 'sms', 'whatsapp', 'email'], true) ? $channel : 'auto';
}

function oa_channel_labels(): array
{
    return ['auto' => 'Automatique', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'];
}

function oa_channel_label(?string $channel = null): string
{
    $channel ??= oa_channel();
    return oa_channel_labels()[$channel] ?? $channel;
}

function oa_phone(): string
{
    try {
        return trim(shop_setting('oa_phone', ''));
    } catch (Throwable $e) {
        return '';
    }
}

/** Délai de paiement accordé au client après confirmation (6 à 168 heures). */
function oa_validity_hours(): int
{
    try {
        $hours = (int) shop_setting('oa_validity_hours', '48');
    } catch (Throwable $e) {
        $hours = 48;
    }
    return max(6, min(168, $hours));
}

/** Numéro du vendeur : réglage dédié, sinon numéro WhatsApp du pack messagerie, sinon téléphone du commerce. */
function oa_seller_phone(): string
{
    $phone = oa_phone();
    if ($phone !== '') return $phone;
    try {
        $value = db()->query("SELECT value FROM ms_settings WHERE key = 'whatsapp'")->fetchColumn();
        if (is_string($value) && trim($value) !== '') return trim($value);
    } catch (Throwable $e) {
    }
    return trim((string) tenant('admin_phone', ''));
}

/** Adresses qui reçoivent la demande quand aucun canal mobile n'aboutit. */
function oa_admin_emails(): array
{
    $emails = [];
    foreach (pricing_admin_emails() as $email) $emails[] = $email;
    $main = trim((string) tenant('admin_email', ''));
    if ($main !== '') $emails[] = $main;
    return array_values(array_unique(array_filter($emails, static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
}

// ── Canaux de notification ────────────────────────────────────────────────

/** Identifiants WhatsApp Cloud API (mêmes réglages que les actions commerciales). */
function oa_whatsapp_config(): array
{
    $read = static function (string $setting, string $env): string {
        $v = trim((string) getenv($env));
        if ($v !== '') return $v;
        try {
            return trim(shop_setting($setting, ''));
        } catch (Throwable $e) {
            return '';
        }
    };
    return [
        'phone_id' => $read('meta_wa_phone_id', 'WHATSAPP_PHONE_ID'),
        'token' => $read('meta_wa_token', 'WHATSAPP_TOKEN'),
        'template' => $read('meta_wa_template', 'WHATSAPP_TEMPLATE'),
    ];
}

function oa_whatsapp_ready(): bool
{
    $config = oa_whatsapp_config();
    return $config['phone_id'] !== '' && $config['token'] !== '';
}

function oa_sms_ready(): bool
{
    require_once __DIR__ . '/sms.php';
    return sms_local_host() || sms_provider_name() !== '';
}

function oa_channel_ready(string $channel): bool
{
    return match ($channel) {
        'whatsapp' => oa_whatsapp_ready(),
        'sms' => oa_sms_ready(),
        'email', 'auto' => true,
        default => false,
    };
}

/** Envoi WhatsApp (modèle approuvé si « meta_wa_template » est renseigné, sinon texte simple). */
function oa_whatsapp_send(string $phone, string $text): bool
{
    $config = oa_whatsapp_config();
    if ($config['phone_id'] === '' || $config['token'] === '') return false;
    $to = preg_replace('/\D/', '', $phone);
    if ($to === '') return false;
    $payload = $config['template'] !== ''
        ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
            'template' => ['name' => $config['template'], 'language' => ['code' => 'fr'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]]]]
        : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text]];
    $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($config['phone_id']) . '/messages';
    $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['token']],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $config['token'] . "\r\n",
        'content' => $json,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

/** E-mail au vendeur, avec la même boîte locale de test que les e-mails de commande. */
function oa_mail_to_shop(string $to, string $subject, string $body, string $context): bool
{
    require_once __DIR__ . '/mail-tracking.php';
    [$name, $host] = order_mail_identity(['shop_slug' => tenant_slug()]);
    $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.test')
        || filter_var($host, FILTER_VALIDATE_IP) !== false || mail_open_is_local_host();
    return mail_open_send($to, $subject, $body, 'commande', [
        'from' => 'From: ' . str_replace(['"', "\r", "\n"], '', $name) . ' <noreply@' . ($local ? 'localhost.localdomain' : $host) . '>',
        'log' => dirname(__DIR__) . '/data/mail-outbox.log',
        'local' => $local,
        'context' => $context,
    ]);
}

// ── Jetons et liens signés ────────────────────────────────────────────────

/** Signature pure (testable sans base) : rôle, commande et date d'expiration. */
function oa_sign(string $secret, int $orderId, string $role, int $expiresAt): string
{
    return hash_hmac('sha256', 'oa:' . $role . ':' . $orderId . ':' . $expiresAt, $secret);
}

function oa_sign_valid(string $secret, int $orderId, string $role, string $token, int $expiresAt, ?int $now = null): bool
{
    if ($secret === '' || $expiresAt <= ($now ?? time())) return false;
    return hash_equals(oa_sign($secret, $orderId, $role, $expiresAt), $token);
}

function oa_secret(): string
{
    return payment_token_secret();
}

function oa_expiry(array $order, string $kind): int
{
    $created = (int) strtotime((string) ($order['created_at'] ?? ''));
    if ($created <= 0) $created = time();
    if ($kind === 'seller') return $created + OA_REQUEST_DAYS * 86400;
    if ($kind === 'buyer') return $created + (OA_REQUEST_DAYS + OA_BUYER_LINK_DAYS) * 86400;
    $deadline = (int) strtotime((string) ($order['availability_deadline'] ?? ''));
    return $deadline > 0 ? $deadline : $created + oa_validity_hours() * 3600;
}

function oa_link(string $page, array $order, string $role, string $kind): string
{
    $orderId = (int) ($order['id'] ?? 0);
    $exp = oa_expiry($order, $kind);
    $token = oa_sign(oa_secret(), $orderId, $role, $exp);
    return shop_abs_url('/' . ltrim($page, '/') . '?o=' . $orderId . '&t=' . rawurlencode($token) . '&e=' . $exp);
}

/** Page de validation du vendeur (lien SMS/WhatsApp/e-mail). */
function oa_seller_url(array $order): string
{
    return oa_link('disponibilite.php', $order, 'seller', 'seller');
}

/** Suivi de la demande côté client. */
function oa_buyer_url(array $order): string
{
    return oa_link('demande.php', $order, 'buyer', 'buyer');
}

/** Lien qui remet la commande dans le panier du client. */
function oa_pay_url(array $order): string
{
    return oa_link('reprise-commande.php', $order, 'buyer', 'pay');
}

function oa_token_valid(array $order, string $role, string $token, int $expiresAt): bool
{
    return oa_sign_valid(oa_secret(), (int) ($order['id'] ?? 0), $role, $token, $expiresAt);
}

// ── Création et lecture des demandes ──────────────────────────────────────

/** Statuts lisibles d'une demande de disponibilité. */
function oa_statuses(): array
{
    return [
        'pending' => 'En attente de votre validation',
        'available' => 'Disponible — paiement attendu',
        'refused' => 'Pièce vendue — demande refusée',
        'expired' => 'Délai dépassé',
        'cancelled' => 'Annulée par le client',
        'converted' => 'Commande payée (demande remplacée)',
    ];
}

function oa_status_label(string $status): string
{
    return oa_statuses()[$status] ?? $status;
}

/** Une demande peut encore être validée (en attente) ou payée (disponible, dans le délai). */
function oa_is_open(array $order): bool
{
    if (($order['status'] ?? '') !== 'awaiting') return false;
    $state = (string) ($order['availability_status'] ?? '');
    if ($state === 'pending') return true;
    if ($state === 'available') {
        $deadline = (int) strtotime((string) ($order['availability_deadline'] ?? ''));
        return $deadline > time();
    }
    return false;
}

/** Demande en cours de validation, du plus ancien au plus récent. */
function oa_pending_orders(int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    return db()->query("SELECT * FROM orders WHERE status = 'awaiting' AND availability_status = 'pending' ORDER BY created_at ASC LIMIT " . $limit)->fetchAll();
}

/** Demandes confirmées dont le client n'a pas encore payé (pour le tableau de bord vendeur). */
function oa_waiting_payment_orders(int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $st = db()->prepare("SELECT * FROM orders WHERE status = 'awaiting' AND availability_status = 'available' AND availability_deadline > ? ORDER BY availability_deadline ASC LIMIT " . $limit);
    $st->execute([gmdate('c')]);
    return $st->fetchAll();
}

/**
 * Enregistre une demande (aucun paiement). $order contient les montants et
 * coordonnées déjà contrôlés par checkout.php ; $payload permet de remettre
 * exactement le même panier dans la session du client après validation.
 */
function oa_create(array $order, array $payload): int
{
    $reference = 'D' . strtoupper(bin2hex(random_bytes(5)));
    $stmt = db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, shipping_cents, status, items, payment_reference, email, phone, terms_at, terms_version, create_account, ship_name, ship_phone, declared_address, buyer_address, shop_slug, shipping_mode, shipping_label, created_at, availability_status, availability_channel, availability_payload, gift_wrap, gift_wrap_cents, gift_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        'demande:' . $reference,
        (int) $order['amount_total'],
        (int) $order['shipping_cents'],
        'awaiting',
        json_encode($order['items'], JSON_UNESCAPED_UNICODE),
        $reference,
        (string) $order['email'],
        (string) ($order['phone'] ?? ''),
        (string) ($order['terms_at'] ?? gmdate('c')),
        (string) ($order['terms_version'] ?? ''),
        !empty($order['create_account']) ? 1 : 0,
        ($order['ship_name'] ?? '') !== '' ? $order['ship_name'] : null,
        ($order['ship_phone'] ?? '') !== '' ? $order['ship_phone'] : null,
        $order['declared_address'] ?? null,
        $order['buyer_address'] ?? null,
        (string) ($order['shop_slug'] ?? tenant_slug()),
        (string) ($order['shipping_mode'] ?? 'pickup'),
        (string) ($order['shipping_label'] ?? ''),
        gmdate('c'),
        'pending',
        oa_channel(),
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        !empty($order['gift_wrap']) ? 1 : 0,
        (int) ($order['gift_wrap_cents'] ?? 0),
        ($order['gift_message'] ?? '') !== '' ? (string) $order['gift_message'] : null,
    ]);
    return (int) db()->lastInsertId();
}

function oa_payload(array $order): array
{
    $payload = json_decode((string) ($order['availability_payload'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

// ── Textes ────────────────────────────────────────────────────────────────

function oa_items_summary(array $order): string
{
    $items = json_decode((string) ($order['items'] ?? '[]'), true) ?: [];
    $names = [];
    foreach ($items as $item) $names[] = (int) ($item['qty'] ?? 1) . ' × ' . (string) ($item['name'] ?? '');
    return implode(', ', $names);
}

function oa_seller_text(array $order, string $link): string
{
    [$shop] = order_mail_identity($order);
    return mb_substr($shop . ' : commande n° ' . (int) $order['id'] . ' — ' . oa_items_summary($order)
        . (empty($order['gift_wrap']) ? '' : ' — emballage cadeau')
        . '. Validez la disponibilité avant paiement : ' . $link, 0, 480);
}

function oa_seller_mail_body(array $order, string $link): string
{
    [$shop] = order_mail_identity($order);
    $body = "Bonjour,\n\nUne commande attend votre validation avant paiement sur $shop.\n\n"
        . 'Commande n° ' . (int) $order['id'] . "\n"
        . oa_items_summary($order) . "\n"
        . 'Total : ' . format_cents((int) $order['amount_total']) . "\n"
        . (order_gift_summary($order) !== '' ? order_gift_summary($order) . "\n" : '')
        . 'Mode : ' . (order_is_pickup($order) ? 'retrait sur place' : ('expédition — ' . (string) $order['shipping_label'])) . "\n"
        . 'Client : ' . (string) ($order['email'] ?? '') . ((string) ($order['phone'] ?? '') !== '' ? ' — ' . $order['phone'] : '') . "\n\n"
        . "Vérifiez que la pièce est toujours en stock, puis validez (ou indiquez qu'elle est déjà vendue) :\n$link\n\n"
        . "Aucun paiement n'a été encaissé. Le client reçoit automatiquement un lien de paiement si vous confirmez.\n\n"
        . 'Vous pouvez aussi répondre depuis Administration → Commandes.';
    return $body;
}

function oa_buyer_received_body(array $order): string
{
    [$shop] = order_mail_identity($order);
    return "Bonjour,\n\nVotre demande n° " . (int) $order['id'] . " est bien arrivée chez $shop.\n\n"
        . oa_items_summary($order) . "\n"
        . 'Total prévu : ' . format_cents((int) $order['amount_total']) . "\n"
        . (order_gift_summary($order) !== '' ? order_gift_summary($order) . "\n" : '')
        . "\nLe vendeur vérifie que la pièce est toujours disponible. Aucun paiement n'a été effectué : vous ne payez que si elle est disponible.\n\n"
        . 'Suivre ma demande : ' . oa_buyer_url($order) . "\n\n"
        . "Vous recevrez un lien de paiement dès que le vendeur aura confirmé.\n\n"
        . 'À bientôt,' . "\n$shop";
}

function oa_buyer_available_body(array $order): string
{
    [$shop] = order_mail_identity($order);
    $deadline = (int) strtotime((string) ($order['availability_deadline'] ?? ''));
    return "Bonjour,\n\nBonne nouvelle : la pièce de votre demande n° " . (int) $order['id'] . " est disponible chez $shop.\n\n"
        . oa_items_summary($order) . "\n"
        . 'Total : ' . format_cents((int) $order['amount_total']) . "\n"
        . (order_gift_summary($order) !== '' ? order_gift_summary($order) . "\n" : '')
        . "\nElle est mise de côté pour vous. Réglez votre commande avant le " . date('d/m/Y à H:i', $deadline) . " :\n"
        . oa_pay_url($order) . "\n\n"
        . 'Passé ce délai, la demande sera annulée et la pièce remise en vente. Aucun paiement n\'a été effectué pour l\'instant.'
        . "\n\nÀ bientôt,\n$shop";
}

function oa_buyer_refused_body(array $order): string
{
    [$shop] = order_mail_identity($order);
    return "Bonjour,\n\nLa pièce de votre demande n° " . (int) $order['id'] . " n'est plus disponible chez $shop.\n\n"
        . oa_items_summary($order) . "\n\n"
        . "Elle a été vendue entre-temps. Aucun paiement n'a été effectué et aucun montant ne vous sera prélevé.\n\n"
        . "D'autres pièces vous attendent peut-être : " . shop_abs_url('/boutique.php') . "\n\nÀ bientôt,\n$shop";
}

function oa_buyer_expired_body(array $order): string
{
    [$shop] = order_mail_identity($order);
    return "Bonjour,\n\nVotre demande n° " . (int) $order['id'] . " a expiré chez $shop.\n\n"
        . oa_items_summary($order) . "\n\n"
        . "Le délai de paiement est dépassé : la pièce a été remise en vente. Aucun montant ne vous a été prélevé.\n\n"
        . 'À bientôt,' . "\n$shop";
}

// ── Notifications ─────────────────────────────────────────────────────────

/**
 * Prévient le vendeur (WhatsApp, SMS ou e-mail selon le réglage et les
 * identifiants disponibles). Retourne le canal réellement utilisé, '' si tout
 * a échoué — la demande reste visible dans Administration → Commandes.
 */
function oa_notify_seller(int $orderId): string
{
    $order = order_get($orderId);
    if (!$order) return '';
    $link = oa_seller_url($order);
    $text = oa_seller_text($order, $link);
    $phone = oa_seller_phone();
    $wanted = oa_channel();
    if ($wanted === 'auto') $wanted = oa_whatsapp_ready() ? 'whatsapp' : (oa_sms_ready() ? 'sms' : 'email');

    $used = '';
    try {
        if ($wanted === 'whatsapp' && oa_whatsapp_ready() && $phone !== '' && oa_whatsapp_send($phone, $text)) {
            $used = 'whatsapp';
        } elseif ($wanted !== 'email' && oa_sms_ready() && $phone !== '' && sms_send($phone, $text)) {
            $used = 'sms';
        }
    } catch (Throwable $e) {
        error_log('validation disponibilité (notification) : ' . $e->getMessage());
    }

    if ($used === '') {
        $emails = oa_admin_emails();
        $sent = false;
        foreach ($emails as $email) {
            if (oa_mail_to_shop($email, 'Commande à valider — n° ' . (int) $order['id'], oa_seller_mail_body($order, $link), 'order:' . $orderId)) {
                $sent = true;
                break;
            }
        }
        if ($sent) {
            $used = 'email';
        }
    }

    if ($used !== '') {
        db()->prepare('UPDATE orders SET availability_channel = ? WHERE id = ?')->execute([$used, $orderId]);
        order_event($orderId, 'dispo_notification', 'Vendeur prévenu par ' . oa_channel_label($used), 'system', 'dispo_notification');
    } else {
        order_event($orderId, 'dispo_notification_echec', 'Aucune notification envoyée au vendeur (canal indisponible) — validez depuis Administration → Commandes', 'system', 'dispo_notification_echec');
    }
    return $used;
}

function oa_send_buyer_received(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$shop] = order_mail_identity($order);
    return order_send_email($orderId, 'dispo_demande', 'Votre demande n° ' . (int) $order['id'] . ' est arrivée — ' . $shop, oa_buyer_received_body($order));
}

function oa_send_buyer_available(int $orderId, bool $force = false): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$shop] = order_mail_identity($order);
    return order_send_email($orderId, 'dispo_disponible', 'Votre pièce est disponible — demande n° ' . (int) $order['id'] . ' — ' . $shop, oa_buyer_available_body($order), $force);
}

function oa_send_buyer_refused(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$shop] = order_mail_identity($order);
    return order_send_email($orderId, 'dispo_refusee', 'Pièce vendue — demande n° ' . (int) $order['id'] . ' — ' . $shop, oa_buyer_refused_body($order));
}

function oa_send_buyer_expired(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$shop] = order_mail_identity($order);
    return order_send_email($orderId, 'dispo_expiree', 'Demande expirée — n° ' . (int) $order['id'] . ' — ' . $shop, oa_buyer_expired_body($order));
}

// ── Actions du vendeur ────────────────────────────────────────────────────

/** Le vendeur confirme que la pièce est disponible : le client reçoit son lien de paiement. */
function oa_confirm(int $orderId, string $actor = 'vendeur'): array
{
    $order = order_get($orderId);
    if (!$order) return ['ok' => false, 'message' => 'Commande introuvable.'];
    if (($order['availability_status'] ?? '') === 'available' && ($order['status'] ?? '') === 'awaiting') {
        return ['ok' => true, 'message' => 'Disponibilité déjà confirmée — le client peut payer.'];
    }
    if (($order['status'] ?? '') !== 'awaiting' || ($order['availability_status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'Cette demande ne peut plus être validée (elle est annulée ou le délai est passé).'];
    }
    $deadline = gmdate('c', time() + oa_validity_hours() * 3600);
    $upd = db()->prepare("UPDATE orders SET availability_status = 'available', availability_confirmed_at = ?, availability_deadline = ? WHERE id = ? AND status = 'awaiting' AND availability_status = 'pending'");
    $upd->execute([gmdate('c'), $deadline, $orderId]);
    if ($upd->rowCount() === 0) return ['ok' => false, 'message' => 'La demande a déjà changé d\'état — rechargez la page.'];

    order_event($orderId, 'dispo_confirmee', 'Disponibilité confirmée — paiement attendu jusqu\'au ' . date('d/m/Y H:i', strtotime($deadline)), $actor);
    $sent = false;
    try {
        $sent = oa_send_buyer_available($orderId);
    } catch (Throwable $e) {
        error_log('validation disponibilité (e-mail client) : ' . $e->getMessage());
    }
    return ['ok' => true, 'message' => 'Disponibilité confirmée. ' . ($sent ? 'Le client a reçu son lien de paiement.' : 'Le lien de paiement n\'a pas pu être envoyé par e-mail — contactez le client.')];
}

/** Le vendeur indique que la pièce a été vendue entre-temps : demande annulée, rien n'est prélevé. */
function oa_refuse(int $orderId, string $actor = 'vendeur', string $reason = ''): array
{
    $order = order_get($orderId);
    if (!$order) return ['ok' => false, 'message' => 'Commande introuvable.'];
    if (!in_array((string) ($order['availability_status'] ?? ''), ['pending', 'available'], true) || ($order['status'] ?? '') !== 'awaiting') {
        return ['ok' => false, 'message' => 'Cette demande est déjà fermée.'];
    }
    $upd = db()->prepare("UPDATE orders SET availability_status = 'refused', status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'awaiting'");
    $upd->execute([gmdate('c'), $orderId]);
    if ($upd->rowCount() === 0) return ['ok' => false, 'message' => 'La demande a déjà changé d\'état — rechargez la page.'];
    order_event($orderId, 'dispo_refusee', 'Pièce déjà vendue' . ($reason !== '' ? ' — ' . $reason : '') . '. Aucun paiement demandé.', $actor);
    try {
        oa_send_buyer_refused($orderId);
    } catch (Throwable $e) {
        error_log('validation disponibilité (refus) : ' . $e->getMessage());
    }
    return ['ok' => true, 'message' => 'Demande refusée : le client est prévenu, aucun paiement n\'a été effectué.'];
}

/** Le client renonce à sa demande (depuis la page de suivi). */
function oa_cancel_by_buyer(int $orderId): array
{
    $order = order_get($orderId);
    if (!$order) return ['ok' => false, 'message' => 'Demande introuvable.'];
    if (!oa_is_open($order)) return ['ok' => false, 'message' => 'Cette demande n\'est plus modifiable.'];
    $upd = db()->prepare("UPDATE orders SET availability_status = 'cancelled', status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'awaiting'");
    $upd->execute([gmdate('c'), $orderId]);
    if ($upd->rowCount() === 0) return ['ok' => false, 'message' => 'La demande a déjà changé d\'état.'];
    order_event($orderId, 'dispo_annulee_client', 'Demande annulée par le client', 'client');
    return ['ok' => true, 'message' => 'Votre demande est annulée. Aucun paiement n\'a été effectué.'];
}

/** Annule une demande dont le délai est dépassé (réponse du vendeur ou paiement du client). */
function oa_expire(array $order): bool
{
    if (($order['status'] ?? '') !== 'awaiting') return false;
    $state = (string) ($order['availability_status'] ?? '');
    $now = time();
    $expired = false;
    if ($state === 'pending') {
        $expired = oa_expiry($order, 'seller') <= $now;
    } elseif ($state === 'available') {
        $deadline = (int) strtotime((string) ($order['availability_deadline'] ?? ''));
        $expired = $deadline > 0 && $deadline <= $now;
    }
    if (!$expired) return false;
    $upd = db()->prepare("UPDATE orders SET availability_status = 'expired', status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'awaiting'");
    $upd->execute([gmdate('c'), (int) $order['id']]);
    if ($upd->rowCount() === 0) return false;
    order_event((int) $order['id'], 'dispo_expiree', 'Délai dépassé — demande annulée, aucun paiement demandé', 'system');
    if ($state === 'available') {
        try {
            oa_send_buyer_expired((int) $order['id']);
        } catch (Throwable $e) {
            error_log('validation disponibilité (expiration) : ' . $e->getMessage());
        }
    }
    return true;
}

/** Passe en revue les demandes en attente (appelé à l'ouverture des commandes dans l'administration). */
function oa_sweep(): int
{
    $count = 0;
    try {
        $rows = db()->query("SELECT * FROM orders WHERE status = 'awaiting'")->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }
    foreach ($rows as $order) {
        if (oa_expire($order)) $count++;
    }
    return $count;
}

// ── Reprise du panier après confirmation ─────────────────────────────────

/**
 * Remet le panier et les coordonnées de la demande dans la session du client.
 * -> ['ok' => bool, 'message' => string, 'order' => ?array]
 */
function oa_resume(int $orderId, string $token, int $expiresAt): array
{
    $order = order_get($orderId);
    if (!$order || !oa_token_valid($order, 'buyer', $token, $expiresAt)) {
        return ['ok' => false, 'message' => 'Ce lien de paiement n\'est pas valide.', 'order' => null];
    }
    if (!oa_is_open($order) || ($order['availability_status'] ?? '') !== 'available') {
        return ['ok' => false, 'message' => 'Cette demande n\'est plus ouverte au paiement.', 'order' => $order];
    }
    if (oa_expire($order)) {
        return ['ok' => false, 'message' => 'Le délai de paiement est dépassé : la pièce a été remise en vente.', 'order' => $order];
    }
    $payload = oa_payload($order);
    if (!$payload || empty($payload['cart']) || !is_array($payload['cart'])) {
        return ['ok' => false, 'message' => 'Le panier de cette demande est introuvable — recommencez votre commande.', 'order' => $order];
    }
    // Une pièce vendue entre-temps ne doit pas être payée : on renvoie le client vers la boutique.
    foreach ($payload['cart'] as $ref => $qty) {
        $product = get_product((string) $ref);
        if (!$product || !in_stock($product)) {
            return ['ok' => false, 'message' => 'Une pièce de votre demande n\'est plus disponible — recommencez votre commande.', 'order' => $order];
        }
    }
    $cart = cart_get();
    foreach ($payload['cart'] as $ref => $qty) {
        $cart[(string) $ref] = max((int) ($cart[(string) $ref] ?? 0), (int) $qty);
    }
    $_SESSION['cart'] = $cart;

    $contact = (array) ($payload['contact'] ?? []);
    if ($contact) $_SESSION['checkout_contact'] = $contact;
    foreach (['buyer_country', 'buyer_line1', 'buyer_postal', 'buyer_city', 'buyer_address_label'] as $key) {
        if (isset($payload[$key])) $_SESSION[$key] = (string) $payload[$key];
    }
    foreach (['shipping_country', 'shipping_line1', 'shipping_line2', 'shipping_postal_code', 'shipping_city'] as $key) {
        if (isset($payload[$key])) $_SESSION[$key] = (string) $payload[$key];
    }
    if (!empty($payload['shipping_ui'])) $_SESSION['shipping_ui'] = (string) $payload['shipping_ui'];
    if (!empty($payload['payment_method'])) $_SESSION['checkout_payment_method'] = (string) $payload['payment_method'];
    if (!empty($payload['reward_code'])) $_SESSION['reward_code'] = (string) $payload['reward_code'];
    // L'emballage cadeau fait partie de la demande : il est reproposé tel quel au retour sur le panier.
    $_SESSION['gift_wrap'] = !empty($payload['gift_wrap']) ? 1 : 0;
    $giftMessage = trim((string) ($payload['gift_message'] ?? ''));
    if ($_SESSION['gift_wrap'] && $giftMessage !== '') $_SESSION['gift_message'] = $giftMessage; else unset($_SESSION['gift_message']);
    $_SESSION['checkout_resume_order'] = $orderId;
    return ['ok' => true, 'message' => '', 'order' => $order];
}

/** Demande en cours de reprise (bandeau du panier) ; l'expiration est traitée au passage. */
function oa_resume_order(): ?array
{
    $orderId = (int) ($_SESSION['checkout_resume_order'] ?? 0);
    if ($orderId <= 0) return null;
    $order = order_get($orderId);
    if (!$order || !oa_is_open($order) || ($order['availability_status'] ?? '') !== 'available' || oa_expire($order)) {
        unset($_SESSION['checkout_resume_order']);
        return null;
    }
    return $order;
}

/** Le client a bien lancé un paiement depuis la reprise : la demande d'origine est refermée. */
function oa_mark_converted(int $newOrderId): void
{
    $requestId = (int) ($_SESSION['checkout_resume_order'] ?? 0);
    unset($_SESSION['checkout_resume_order']);
    if ($requestId <= 0 || $requestId === $newOrderId) return;
    $order = order_get($requestId);
    if (!$order || ($order['status'] ?? '') !== 'awaiting' || ($order['availability_status'] ?? '') !== 'available') return;
    $upd = db()->prepare("UPDATE orders SET status = 'cancelled', availability_status = 'converted', availability_order_id = ?, cancelled_at = ? WHERE id = ? AND status = 'awaiting'");
    $upd->execute([$newOrderId, gmdate('c'), $requestId]);
    if ($upd->rowCount() > 0) {
        order_event($requestId, 'dispo_convertie', 'Remplacée par la commande n° ' . $newOrderId, 'client');
    }
}
