<?php
// Assistance vendeur : disponibilité des vendeurs, questions des visiteurs, veille d'intention (attente sur une pièce,
// panier, paiement) et réservation temporaire d'une pièce. Les alertes partent vers l'administration (voix + liste),
// l'e-mail, le SMS (OVH ou Twilio) et WhatsApp (Meta), avec un accord par canal et par vendeur.
defined('PLUGIN_RUNTIME') || exit;

const AV_MODULE = 'assistance-vendeur';
const AV_CHANNELS = ['mail', 'sms', 'whatsapp'];
const AV_QUESTION_LABELS = ['site' => 'Réponse par e-mail', 'whatsapp_call' => 'Demande d’appel WhatsApp'];
const AV_MAX_QUESTION_IP_HOUR = 5;

function av_defaults(): array
{
    return [
        'active' => '1',
        'widget' => '1',
        'available_until' => '0',
        'available_by' => '',
        'available_note' => '',
        'dwell_seconds' => '90',
        'cooldown_minutes' => '20',
        'hold_minutes' => '20',
        'alert_cart' => '1',
        'alert_cart_add' => '0',
        'alert_checkout' => '1',
        'notify_visit' => '1',
        'notify_intent' => '0',
        'voice' => '1',
        'channels' => 'mail,sms,whatsapp',
        'whatsapp' => '',
        'retention_days' => '7',
        'welcome' => 'Une question ? Un vendeur peut vous répondre.',
        'away' => 'Laissez-nous votre question : nous répondons dès que possible.',
        'last_visit_alert' => '0',
        'last_purge' => '0',
    ];
}

function av_settings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $out = av_defaults();
    foreach (db()->query('SELECT key, value FROM av_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    }
    return $cache = $out;
}

function av_setting(string $key, ?string $default = null): string
{
    $s = av_settings();
    return $s[$key] ?? ($default ?? '');
}

function av_bool(string $key): bool
{
    return av_setting($key) === '1';
}

function av_setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO av_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
    av_settings(true);
}

function av_active(): bool
{
    return av_bool('active');
}

function av_site_name(): string
{
    try {
        return (string) (get_content()['site_name'] ?: tenant('name'));
    } catch (Throwable $e) {
        return 'La boutique';
    }
}

function av_admin_url(): string
{
    return shop_abs_url('/admin/plugin.php?' . http_build_query(['p' => AV_MODULE, 'v' => 'index']));
}

function av_product_url(string $ref): string
{
    return shop_abs_url('/produit.php?ref=' . rawurlencode($ref));
}

function av_random_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Jeton de présence : uniquement 32 caractères hexadécimaux (fourni par le navigateur ou la session de la boutique). */
function av_token(mixed $value): string
{
    $t = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : '';
}

/** Jeton du visiteur dans la session de la boutique (créé à la première page, réutilisé par les hooks panier/paiement). */
function av_session_token(bool $create = false): string
{
    $t = av_token($_SESSION['assistance_vendeur_token'] ?? '');
    if ($t === '' && $create) {
        $t = av_random_token();
        $_SESSION['assistance_vendeur_token'] = $t;
    }
    return $t;
}

function av_ip_hash(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|av|' . (function_exists('tenant_slug') ? tenant_slug() : ''));
}

/** Numéro international « +33… » (national 06… converti), null si invalide. */
function av_phone_e164(string $raw): ?string
{
    $p = preg_replace('/[\s.\-()\/]/', '', trim($raw));
    if ($p === '' || !preg_match('/^\+?\d+$/', $p)) return null;
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if ($p[0] !== '+' && preg_match('/^0[1-9]\d{8}$/', $p)) $p = '+33' . substr($p, 1);
    $digits = strlen(ltrim($p, '+'));
    return $digits >= 8 && $digits <= 15 ? $p : null;
}

function av_phone_digits(string $raw): string
{
    return preg_replace('/\D/', '', $raw);
}

// ── Disponibilité ───────────────────────────────────────────────────────────────────────────────────────────────────────

function av_availability(): array
{
    $until = (int) av_setting('available_until', '0');
    return [
        'on' => $until > time(),
        'until' => $until,
        'by' => av_setting('available_by'),
        'note' => av_setting('available_note'),
    ];
}

function av_set_availability(int $minutes, string $note, string $by): void
{
    $minutes = max(0, min(480, $minutes));
    av_setting_set('available_until', $minutes > 0 ? (string) (time() + $minutes * 60) : '0');
    av_setting_set('available_by', $minutes > 0 ? mb_substr($by, 0, 80) : '');
    av_setting_set('available_note', mb_substr(trim($note), 0, 160));
}

/** Numéro WhatsApp de la boutique : réglage du module, sinon celui du module Messagerie s'il est installé. */
function av_shop_whatsapp(): string
{
    $own = av_phone_digits(av_setting('whatsapp'));
    if ($own !== '') return $own;
    try {
        $has = db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ms_settings'")->fetchColumn();
        if ($has) {
            $v = db()->query("SELECT value FROM ms_settings WHERE key = 'whatsapp'")->fetchColumn();
            $v = av_phone_digits((string) $v);
            if ($v !== '') return $v;
        }
    } catch (Throwable $e) {
    }
    return '';
}

// ── Canaux de notification ─────────────────────────────────────────────────────────────────────────────────────────────

function av_channel_labels(): array
{
    return ['mail' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
}

function av_channel_ready(string $channel): bool
{
    if ($channel === 'mail') return true;
    if ($channel === 'sms') {
        require_once dirname(__DIR__, 3) . '/includes/login-otp.php';
        return function_exists('login_otp_sms_available') && login_otp_sms_available();
    }
    if ($channel === 'whatsapp') {
        $c = av_wa_config();
        return $c['phone_id'] !== '' && $c['token'] !== '';
    }
    return false;
}

/** Identifiants WhatsApp Cloud API (Meta) : réglages de la boutique, sinon variables d'environnement. */
function av_wa_config(): array
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

function av_wa_send(string $phone, string $text): bool
{
    $c = av_wa_config();
    if ($c['phone_id'] === '' || $c['token'] === '') return false;
    $to = av_phone_digits($phone);
    if ($to === '') return false;
    $payload = $c['template'] !== ''
        ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
            'template' => ['name' => $c['template'], 'language' => ['code' => 'fr'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]]]]
        : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text]];
    $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($c['phone_id']) . '/messages';
    $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']],
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $c['token'] . "\r\n",
            'content' => $json,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        return @file_get_contents($url, false, $ctx) !== false;
    } catch (Throwable $e) {
        error_log('Assistance vendeur (WhatsApp) : ' . $e->getMessage());
        return false;
    }
}

function av_mail(string $to, string $subject, string $body): bool
{
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    try {
        return password_reset_mail($to, $subject, $body);
    } catch (Throwable $e) {
        error_log('Assistance vendeur (e-mail) : ' . $e->getMessage());
        return false;
    }
}

function av_sms(string $phone, string $body): bool
{
    require_once dirname(__DIR__, 3) . '/includes/login-otp.php';
    try {
        return login_otp_send_sms($phone, $body);
    } catch (Throwable $e) {
        error_log('Assistance vendeur (SMS) : ' . $e->getMessage());
        return false;
    }
}

// ── Vendeurs prévenus ──────────────────────────────────────────────────────────────────────────────────────────────────

/** Fiches des vendeurs prévenus (créées depuis les comptes de l'équipe au premier usage). */
function av_sellers(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM av_sellers ORDER BY active DESC, name COLLATE NOCASE, email COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[strtolower((string) $r['email'])] = $r;
    }
    return $out;
}

/** Reprend les comptes actifs de l'équipe (admin, community manager, photographe) sans écraser les accords déjà enregistrés. */
function av_sellers_sync(): int
{
    $n = 0;
    $st = db()->prepare('INSERT INTO av_sellers (email, name, phone, consent_mail, active, updated_at) VALUES (?, ?, ?, 1, 1, ?) ON CONFLICT(email) DO NOTHING');
    foreach (db()->query("SELECT name, email, phone FROM accounts WHERE role IN ('admin', 'community_manager', 'photographe') AND is_active = 1 AND email <> ''") as $a) {
        $email = strtolower(trim((string) $a['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $st->execute([$email, mb_substr((string) $a['name'], 0, 80), (string) ($a['phone'] ?? ''), time()]);
        if ($st->rowCount() > 0) $n++;
    }
    return $n;
}

function av_seller_save(array $in): ?string
{
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) return 'Adresse e-mail invalide.';
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    $rawPhone = trim((string) ($in['phone'] ?? ''));
    $phone = $rawPhone === '' ? '' : av_phone_e164($rawPhone);
    if ($rawPhone !== '' && $phone === null) return 'Numéro de téléphone invalide.';
    $mail = !empty($in['consent_mail']) ? 1 : 0;
    $sms = !empty($in['consent_sms']) ? 1 : 0;
    $wa = !empty($in['consent_wa']) ? 1 : 0;
    if (($sms || $wa) && $phone === '') return 'Renseignez le portable du vendeur avant d’autoriser le SMS ou WhatsApp.';
    $active = !empty($in['active']) ? 1 : 0;
    db()->prepare('INSERT INTO av_sellers (email, name, phone, consent_mail, consent_sms, consent_wa, active, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(email) DO UPDATE SET name = excluded.name, phone = excluded.phone, consent_mail = excluded.consent_mail,
        consent_sms = excluded.consent_sms, consent_wa = excluded.consent_wa, active = excluded.active, updated_at = excluded.updated_at')
        ->execute([$email, $name, (string) $phone, $mail, $sms, $wa, $active, time()]);
    return null;
}

function av_seller_delete(string $email): void
{
    db()->prepare('DELETE FROM av_sellers WHERE email = ?')->execute([strtolower(trim($email))]);
}

// ── Alertes ────────────────────────────────────────────────────────────────────────────────────────────────────────────

function av_kind_labels(): array
{
    return [
        'visit' => 'Un visiteur est sur le site',
        'question' => 'Question d’un visiteur',
        'dwell' => 'Un visiteur s’attarde sur une pièce',
        'cart' => 'Un visiteur prépare son panier',
        'cart_add' => 'Pièce ajoutée au panier',
        'checkout_start' => 'Un visiteur remplit le paiement',
        'checkout' => 'Paiement ouvert',
        'test' => 'Alerte de démonstration',
    ];
}

function av_kind_label(string $kind): string
{
    return av_kind_labels()[$kind] ?? $kind;
}

/** Niveau d'alerte : high (toujours sur les canaux externes), mid (si activé), low (visite). */
function av_kind_level(string $kind): string
{
    if ($kind === 'question' || $kind === 'checkout') return 'high';
    if ($kind === 'visit' || $kind === 'test') return 'low';
    return 'mid';
}

function av_csv(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
}

function av_product_info(string $ref): ?array
{
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
    if ($ref === '' || !function_exists('get_product')) return null;
    $p = get_product($ref);
    if (!$p) return null;
    $price = function_exists('effective_price') ? (string) effective_price($p) : (string) ($p['price'] ?? '');
    $photo = trim((string) ($p['photo'] ?? ''));
    return ['ref' => (string) $p['ref'], 'name' => (string) $p['name'], 'price' => $price,
        'photo' => $photo !== '' ? share_media_url($photo) : ''];
}

/** Dernière alerte du même type pour ce visiteur (et cette pièce) dans la fenêtre de répétition. */
function av_recent_alert(string $visitor, string $kind, string $ref, int $minutes, string $src = ''): bool
{
    if ($src !== '') {
        $st = db()->prepare('SELECT 1 FROM av_alerts WHERE src = ? LIMIT 1');
        $st->execute([$src]);
        return (bool) $st->fetchColumn();
    }
    $kinds = $kind === 'checkout_start' ? ['checkout_start', 'checkout'] : [$kind];
    $in = implode(',', array_fill(0, count($kinds), '?'));
    $st = db()->prepare("SELECT 1 FROM av_alerts WHERE visitor = ? AND kind IN ($in) AND ref = ? AND created_at >= ? LIMIT 1");
    $st->execute(array_merge([$visitor], $kinds, [$ref, time() - max(1, $minutes) * 60]));
    return (bool) $st->fetchColumn();
}

function av_alert_create(array $a): int
{
    $now = time();
    $st = db()->prepare('INSERT INTO av_alerts (visitor, kind, src, ref, product_name, detail, email, phone, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        av_token($a['visitor'] ?? ''),
        preg_replace('/[^a-z_]/', '', (string) ($a['kind'] ?? '')),
        mb_substr(trim((string) ($a['src'] ?? '')), 0, 80),
        preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($a['ref'] ?? '')),
        mb_substr(trim((string) ($a['product_name'] ?? '')), 0, 160),
        mb_substr(trim((string) ($a['detail'] ?? '')), 0, 1200),
        mb_substr(strtolower(trim((string) ($a['email'] ?? ''))), 0, 160),
        mb_substr(trim((string) ($a['phone'] ?? '')), 0, 24),
        $now,
    ]);
    $id = (int) db()->lastInsertId();
    $alert = $a + ['id' => $id, 'created_at' => $now];
    if (($a['kind'] ?? '') !== 'test') av_alert_notify($alert);
    return $id;
}

function av_alert_short(array $alert): string
{
    $bits = [av_kind_label((string) ($alert['kind'] ?? ''))];
    if (($alert['product_name'] ?? '') !== '') $bits[] = '« ' . $alert['product_name'] . ' »';
    $detail = trim((string) ($alert['detail'] ?? ''));
    if ($detail !== '') $bits[] = mb_substr(preg_replace('/\s+/u', ' ', $detail), 0, 140);
    $bits[] = av_admin_url();
    return mb_substr(implode(' · ', $bits), 0, 480);
}

function av_alert_mail_text(array $alert): string
{
    $lines = [];
    $lines[] = av_kind_label((string) ($alert['kind'] ?? ''));
    if (($alert['product_name'] ?? '') !== '') {
        $lines[] = 'Pièce : ' . $alert['product_name'] . (($alert['ref'] ?? '') !== '' ? ' (réf. ' . $alert['ref'] . ')' : '');
        if (($alert['ref'] ?? '') !== '') $lines[] = av_product_url((string) $alert['ref']);
    }
    if (($alert['detail'] ?? '') !== '') $lines[] = '';
    if (($alert['detail'] ?? '') !== '') $lines[] = (string) $alert['detail'];
    $contact = [];
    if (($alert['email'] ?? '') !== '') $contact[] = 'e-mail : ' . $alert['email'];
    if (($alert['phone'] ?? '') !== '') $contact[] = 'téléphone : ' . $alert['phone'];
    if ($contact) $lines[] = 'Visiteur — ' . implode(' · ', $contact);
    $lines[] = '';
    $lines[] = 'Heure : ' . date('d/m/Y H:i', (int) ($alert['created_at'] ?? time()));
    $lines[] = 'Ouvrir l’assistance : ' . av_admin_url();
    return implode("\n", $lines);
}

/** Envoie l'alerte aux vendeurs prévenus, par canal autorisé (accord, numéro, service configuré) et une fois par triplet. */
function av_alert_notify(array $alert): void
{
    if (!av_active()) return;
    $kind = (string) ($alert['kind'] ?? '');
    $level = av_kind_level($kind);
    $external = $level === 'high'
        || ($level === 'mid' && av_bool('notify_intent'))
        || ($kind === 'visit' && av_bool('notify_visit'));
    if (!$external) return;
    $allowed = array_values(array_intersect(AV_CHANNELS, av_csv(av_setting('channels', 'mail,sms,whatsapp'))));
    if (!$allowed) return;
    $sellers = av_sellers();
    if (!$sellers) return;

    $site = av_site_name();
    $subject = '[' . $site . '] ' . av_kind_label($kind) . (($alert['product_name'] ?? '') !== '' ? ' — ' . $alert['product_name'] : '');
    $body = av_alert_mail_text($alert);
    $short = av_alert_short($alert);
    $journal = db()->prepare('INSERT OR IGNORE INTO av_notifications (alert_id, email, channel, status, detail, sent_at) VALUES (?, ?, ?, ?, ?, ?)');

    foreach ($sellers as $email => $seller) {
        if ((int) $seller['active'] !== 1) continue;
        foreach ($allowed as $channel) {
            if (!av_channel_ready($channel)) continue;
            if ($channel === 'mail' && (int) $seller['consent_mail'] !== 1) continue;
            if ($channel === 'sms' && (int) $seller['consent_sms'] !== 1) continue;
            if ($channel === 'whatsapp' && (int) $seller['consent_wa'] !== 1) continue;
            $phone = (string) $seller['phone'];
            if ($channel !== 'mail' && $phone === '') continue;
            if ($level !== 'high' && av_notified_recently($email, $channel, 300)) continue;
            $exists = db()->prepare('SELECT 1 FROM av_notifications WHERE alert_id = ? AND email = ? AND channel = ?');
            $exists->execute([(int) $alert['id'], $email, $channel]);
            if ($exists->fetchColumn()) continue;
            $ok = false;
            $why = '';
            try {
                if ($channel === 'mail') $ok = av_mail($email, $subject, $body);
                elseif ($channel === 'sms') $ok = av_sms($phone, $short);
                else $ok = av_wa_send($phone, $short);
            } catch (Throwable $e) {
                $why = $e->getMessage();
            }
            $journal->execute([(int) $alert['id'], $email, $channel, $ok ? 'sent' : 'failed', $ok ? '' : ($why ?: 'envoi refusé'), time()]);
        }
    }
}

function av_notified_recently(string $email, string $channel, int $seconds): bool
{
    $st = db()->prepare("SELECT 1 FROM av_notifications WHERE email = ? AND channel = ? AND status = 'sent' AND sent_at >= ? LIMIT 1");
    $st->execute([$email, $channel, time() - $seconds]);
    return (bool) $st->fetchColumn();
}

// ── Visiteurs, présence et intentions ─────────────────────────────────────────────────────────────────────────────────

function av_visitor(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM av_visitors WHERE token = ?');
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function av_clean_refs(mixed $refs): array
{
    $out = [];
    foreach ((array) $refs as $r) {
        $r = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r);
        if ($r !== '' && !in_array($r, $out, true)) $out[] = $r;
        if (count($out) >= 20) break;
    }
    return $out;
}

function av_cart_detail(array $refs): string
{
    $names = [];
    foreach ($refs as $ref) {
        $p = av_product_info($ref);
        if ($p) $names[] = '« ' . $p['name'] . ' »';
    }
    if (!$names) return '';
    $shown = array_slice($names, 0, 3);
    return 'Panier : ' . implode(', ', $shown) . (count($names) > 3 ? '…' : '');
}

function av_hold_for(string $ref, string $token): ?array
{
    $st = db()->prepare('SELECT * FROM av_holds WHERE ref = ? AND until > ? AND released_at IS NULL ORDER BY until DESC LIMIT 1');
    $st->execute([$ref, time()]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) return null;
    return ['ref' => (string) $h['ref'], 'until' => (int) $h['until'],
        'mine' => $token !== '' && (string) $h['visitor'] === $token,
        'scope' => (string) $h['visitor'] === '' ? 'all' : 'visitor'];
}

/** État affiché au visiteur (et à la page d'administration). */
function av_state(string $token = '', string $ref = ''): array
{
    $avail = av_availability();
    $hours = function_exists('shop_hours_status') ? shop_hours_status() : ['open' => false, 'label' => ''];
    $asked = false;
    if ($token !== '') {
        $st = db()->prepare('SELECT 1 FROM av_questions WHERE visitor = ? AND created_at >= ? LIMIT 1');
        $st->execute([$token, time() - 1800]);
        $asked = (bool) $st->fetchColumn();
    }
    return [
        'ok' => true,
        'active' => av_active(),
        'widget' => av_bool('widget'),
        'available' => $avail['on'],
        'until' => $avail['until'],
        'by' => $avail['by'],
        'note' => $avail['note'],
        'shop' => ['open' => !empty($hours['open']), 'label' => (string) ($hours['label'] ?? '')],
        'whatsapp' => av_shop_whatsapp(),
        'dwell_seconds' => (int) av_setting('dwell_seconds', '90'),
        'hold' => $ref !== '' ? av_hold_for($ref, $token) : null,
        'asked' => $asked,
        'welcome' => av_setting('welcome'),
        'away' => av_setting('away'),
        'server_now' => time(),
    ];
}

function av_visitor_upsert(string $token, string $page, string $ref, int $dwell, array $cart, string $ipHash): bool
{
    $existing = av_visitor($token);
    $now = time();
    $cartCsv = implode(',', array_slice($cart, 0, 20));
    if ($existing) {
        db()->prepare('UPDATE av_visitors SET last_at = ?, page = ?, ref = ?, dwell = ?, cart = ?, ip_hash = ? WHERE token = ?')
            ->execute([$now, $page, $ref, $dwell, $cartCsv, $ipHash, $token]);
        return false;
    }
    db()->prepare('INSERT INTO av_visitors (token, first_at, last_at, page, ref, dwell, cart, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$token, $now, $now, $page, $ref, $dwell, $cartCsv, $ipHash]);
    return true;
}

/** Signal de présence et d'intention envoyé par la page (au plus une alerte par type et par fenêtre de répétition). */
function av_ping(array $in): array
{
    if (!av_active()) return ['ok' => true, 'active' => false, 'widget' => false, 'server_now' => time()];
    $token = av_token($in['token'] ?? '');
    if ($token === '') return ['ok' => false, 'error' => 'Jeton de visite invalide.', 'server_now' => time()];
    $page = in_array(($in['page'] ?? ''), ['home', 'shop', 'product', 'cart', 'checkout', 'other'], true) ? (string) $in['page'] : 'other';
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['ref'] ?? ''));
    $dwell = max(0, min(3600, (int) ($in['dwell'] ?? 0)));
    $event = in_array(($in['event'] ?? ''), ['cart_view', 'checkout_focus', 'checkout_submit'], true) ? (string) $in['event'] : '';
    $cart = av_clean_refs($in['cart'] ?? []);
    $cooldown = max(1, (int) av_setting('cooldown_minutes', '20'));
    $product = $ref !== '' ? av_product_info($ref) : null;
    $productName = $product['name'] ?? '';

    $isNew = av_visitor_upsert($token, $page, $ref, $dwell, $cart, av_ip_hash());

    // Première visite alors qu'aucun vendeur ne s'est déclaré disponible : un seul rappel par demi-heure.
    if ($isNew && !av_availability()['on'] && time() - (int) av_setting('last_visit_alert', '0') >= 1800) {
        av_setting_set('last_visit_alert', (string) time());
        av_alert_create([
            'visitor' => $token,
            'kind' => 'visit',
            'detail' => 'Première page vue : ' . ($page === 'product' && $productName !== '' ? 'la fiche « ' . $productName . ' »' : $page)
                . '. Êtes-vous disponible pour répondre aux questions ?',
        ]);
    }

    if ($page === 'product' && $product && $dwell >= (int) av_setting('dwell_seconds', '90')
        && !av_recent_alert($token, 'dwell', $ref, $cooldown)) {
        av_alert_create([
            'visitor' => $token,
            'kind' => 'dwell',
            'ref' => $ref,
            'product_name' => $productName,
            'detail' => 'Sur la fiche depuis ' . $dwell . ' secondes' . ($product['price'] !== '' ? ' · ' . $product['price'] : ''),
        ]);
    }

    $cartDetail = av_cart_detail($cart);
    if ($event === 'cart_view' && av_bool('alert_cart') && !av_recent_alert($token, 'cart', '', $cooldown)) {
        av_alert_create([
            'visitor' => $token,
            'kind' => 'cart',
            'detail' => trim('Panier ouvert. ' . $cartDetail),
        ]);
    }
    if (($event === 'checkout_focus' || $event === 'checkout_submit') && av_bool('alert_checkout')
        && !av_recent_alert($token, 'checkout_start', '', $cooldown)) {
        av_alert_create([
            'visitor' => $token,
            'kind' => 'checkout_start',
            'detail' => trim(($event === 'checkout_submit' ? 'Demande de paiement envoyée. ' : 'Coordonnées du paiement en cours de saisie. ') . $cartDetail),
        ]);
    }

    av_purge();
    return av_state($token, $ref);
}

/** Question d'un visiteur : e-mail, WhatsApp immédiat ou demande d'appel WhatsApp. */
function av_question(array $in): array
{
    if (!av_active()) return ['ok' => false, 'error' => 'L’assistance est momentanément indisponible.'];
    if (!empty($in['website'])) return ['ok' => true, 'message' => 'Merci ! Votre question est bien partie.'];
    $token = av_token($in['token'] ?? '');
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    $email = mb_substr(strtolower(trim((string) ($in['email'] ?? ''))), 0, 160);
    $rawPhone = trim((string) ($in['phone'] ?? ''));
    $phone = $rawPhone === '' ? '' : (string) av_phone_e164($rawPhone);
    $channel = ($in['channel'] ?? 'site') === 'whatsapp_call' ? 'whatsapp_call' : 'site';
    $body = mb_substr(trim((string) ($in['body'] ?? '')), 0, 1200);
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['ref'] ?? ''));
    $product = $ref !== '' ? av_product_info($ref) : null;

    if ($channel === 'site' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Indiquez une adresse e-mail valide pour recevoir la réponse.'];
    if ($channel === 'whatsapp_call') {
        if ($phone === '') return ['ok' => false, 'error' => 'Indiquez un numéro WhatsApp valide (par exemple 06 12 34 56 78).'];
        if (empty($in['consent'])) return ['ok' => false, 'error' => 'Cochez l’accord pour être appelé sur WhatsApp.'];
        if ($body === '') $body = 'Demande d’appel WhatsApp pour répondre à une question.';
    }
    if (mb_strlen($body) < 5) return ['ok' => false, 'error' => 'Écrivez votre question (5 caractères minimum).'];
    if (preg_match_all('#https?://#i', $body) > 2) return ['ok' => false, 'error' => 'Trop de liens dans le message.'];

    $hash = av_ip_hash();
    db()->prepare('DELETE FROM av_hits WHERE at < ?')->execute([time() - 3600]);
    $n = db()->prepare('SELECT COUNT(*) FROM av_hits WHERE ip_hash = ? AND at > ?');
    $n->execute([$hash, time() - 3600]);
    if ((int) $n->fetchColumn() >= AV_MAX_QUESTION_IP_HOUR) return ['ok' => false, 'error' => 'Trop de questions depuis votre connexion : réessayez dans une heure.'];

    if ($token !== '') {
        $last = db()->prepare('SELECT COUNT(*) FROM av_questions WHERE visitor = ? AND created_at > ?');
        $last->execute([$token, time() - 120]);
        if ((int) $last->fetchColumn() > 0) return ['ok' => false, 'error' => 'Votre question précédente vient de partir : patientez deux minutes.'];
    } else {
        $token = av_random_token();
    }
    db()->prepare('INSERT INTO av_hits (ip_hash, at) VALUES (?, ?)')->execute([$hash, time()]);

    av_visitor_upsert($token, $ref !== '' ? 'product' : 'other', $ref, 0, [], $hash);
    db()->prepare('INSERT INTO av_questions (visitor, name, email, phone, channel, ref, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$token, $name, $email, $phone, $channel, $ref, $body, time()]);

    $detail = AV_QUESTION_LABELS[$channel] . "\n" . $body;
    if ($phone !== '') $detail .= "\nTéléphone : " . $phone;
    av_alert_create([
        'visitor' => $token,
        'kind' => 'question',
        'ref' => $ref,
        'product_name' => $product['name'] ?? '',
        'detail' => $detail,
        'email' => $email,
        'phone' => $phone,
    ]);

    $available = av_availability()['on'];
    $wa = av_shop_whatsapp();
    return [
        'ok' => true,
        'token' => $token,
        'available' => $available,
        'message' => $available
            ? 'Merci ! Un vendeur est disponible et vous répond au plus vite.'
            : 'Merci ! Votre question est transmise : nous vous répondons dès que possible.',
        'whatsapp' => $wa,
        'whatsapp_url' => $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Bonjour, je viens du site' . ($product ? ' à propos de « ' . $product['name'] . ' »' : '')) : '',
        'server_now' => time(),
    ];
}

// ── Pièces retenues (temporisation d'achat) ───────────────────────────────────────────────────────────────────────────

function av_hold_create(string $ref, string $visitor, int $minutes, string $by, int $alertId = 0): ?string
{
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
    if ($ref === '' || !av_product_info($ref)) return 'Pièce introuvable.';
    $minutes = max(1, min(240, $minutes));
    if ($alertId > 0) db()->prepare('UPDATE av_holds SET released_at = ? WHERE alert_id = ? AND released_at IS NULL')->execute([time(), $alertId]);
    db()->prepare('INSERT INTO av_holds (ref, visitor, until, alert_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$ref, av_token($visitor), time() + $minutes * 60, $alertId, mb_substr($by, 0, 80), time()]);
    return null;
}

function av_hold_release(string $ref, int $alertId = 0): int
{
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
    if ($alertId > 0) {
        $st = db()->prepare('UPDATE av_holds SET released_at = ? WHERE alert_id = ? AND released_at IS NULL');
        $st->execute([time(), $alertId]);
        return $st->rowCount();
    }
    if ($ref === '') return 0;
    $st = db()->prepare('UPDATE av_holds SET released_at = ? WHERE ref = ? AND released_at IS NULL');
    $st->execute([time(), $ref]);
    return $st->rowCount();
}

function av_holds_active(): array
{
    $out = [];
    foreach (db()->query('SELECT h.*, a.product_name FROM av_holds h LEFT JOIN av_alerts a ON a.id = h.alert_id
        WHERE h.until > ' . time() . ' AND h.released_at IS NULL ORDER BY h.until')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = $r;
    }
    return $out;
}

// ── Entretien et état de l'administration ─────────────────────────────────────────────────────────────────────────────

function av_purge(): void
{
    if (time() - (int) av_setting('last_purge', '0') < 600) return;
    av_setting_set('last_purge', (string) time());
    $days = max(1, min(90, (int) av_setting('retention_days', '7')));
    $cut = time() - $days * 86400;
    foreach ([
        'DELETE FROM av_visitors WHERE last_at < ?',
        'DELETE FROM av_alerts WHERE created_at < ?',
        'DELETE FROM av_questions WHERE created_at < ?',
        'DELETE FROM av_notifications WHERE sent_at < ?',
        'DELETE FROM av_hits WHERE at < ?',
        'DELETE FROM av_holds WHERE until < ?',
    ] as $sql) {
        try {
            db()->prepare($sql)->execute([$cut]);
        } catch (Throwable $e) {
        }
    }
}

function av_settings_save(array $in): ?string
{
    $bool = ['active', 'widget', 'alert_cart', 'alert_cart_add', 'alert_checkout', 'notify_visit', 'notify_intent', 'voice'];
    foreach ($bool as $k) av_setting_set($k, !empty($in[$k]) ? '1' : '0');
    $ranges = ['dwell_seconds' => [20, 600], 'cooldown_minutes' => [5, 180], 'hold_minutes' => [5, 120], 'retention_days' => [1, 90]];
    foreach ($ranges as $k => [$min, $max]) {
        $v = (int) ($in[$k] ?? av_setting($k));
        av_setting_set($k, (string) max($min, min($max, $v)));
    }
    $channels = array_values(array_intersect(AV_CHANNELS, is_array($in['channels'] ?? null) ? array_map('strval', $in['channels']) : av_csv((string) ($in['channels'] ?? ''))));
    av_setting_set('channels', implode(',', $channels ?: ['mail']));
    $wa = av_phone_digits((string) ($in['whatsapp'] ?? ''));
    if ($wa !== '' && av_phone_e164($wa) === null) return 'Numéro WhatsApp de la boutique invalide.';
    av_setting_set('whatsapp', $wa);
    foreach (['welcome' => 200, 'away' => 200] as $k => $max) {
        if (array_key_exists($k, $in)) av_setting_set($k, mb_substr(trim((string) $in[$k]), 0, $max));
    }
    return null;
}

function av_admin_state(): array
{
    av_sellers_sync();
    av_purge();
    $now = time();
    $alerts = [];
    foreach (db()->query('SELECT * FROM av_alerts ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $a['id'] = (int) $a['id'];
        $a['created_at'] = (int) $a['created_at'];
        $a['seen_at'] = $a['seen_at'] !== null ? (int) $a['seen_at'] : null;
        $a['handled_at'] = $a['handled_at'] !== null ? (int) $a['handled_at'] : null;
        $a['label'] = av_kind_label((string) $a['kind']);
        $a['level'] = av_kind_level((string) $a['kind']);
        $a['url'] = $a['ref'] !== '' ? av_product_url((string) $a['ref']) : '';
        $a['wa_visitor'] = $a['phone'] !== '' ? av_phone_digits((string) $a['phone']) : '';
        $alerts[] = $a;
    }
    $visitors = [];
    foreach (db()->query('SELECT * FROM av_visitors WHERE last_at >= ' . ($now - 300) . ' ORDER BY last_at DESC LIMIT 40') as $v) {
        $visitors[] = [
            'token' => substr((string) $v['token'], 0, 8),
            'last_at' => (int) $v['last_at'],
            'page' => (string) $v['page'],
            'ref' => (string) $v['ref'],
            'dwell' => (int) $v['dwell'],
            'cart' => av_clean_refs($v['cart'] !== '' ? explode(',', (string) $v['cart']) : []),
        ];
    }
    $unseen = (int) db()->query('SELECT COUNT(*) FROM av_alerts WHERE seen_at IS NULL')->fetchColumn();
    $hours = function_exists('shop_hours_status') ? shop_hours_status() : ['open' => false, 'label' => ''];
    $sellers = [];
    foreach (av_sellers() as $s) {
        $s['consent_mail'] = (int) $s['consent_mail'];
        $s['consent_sms'] = (int) $s['consent_sms'];
        $s['consent_wa'] = (int) $s['consent_wa'];
        $s['active'] = (int) $s['active'];
        $sellers[] = $s;
    }
    $holds = [];
    foreach (av_holds_active() as $h) {
        $holds[] = ['id' => (int) $h['id'], 'ref' => (string) $h['ref'], 'product_name' => (string) ($h['product_name'] ?? ''),
            'until' => (int) $h['until'], 'scope' => (string) $h['visitor'] === '' ? 'all' : 'visitor'];
    }
    return [
        'ok' => true,
        'now' => $now,
        'settings' => av_settings(true),
        'availability' => av_availability(),
        'shop' => ['open' => !empty($hours['open']), 'label' => (string) ($hours['label'] ?? '')],
        'sellers' => $sellers,
        'alerts' => $alerts,
        'visitors' => $visitors,
        'holds' => $holds,
        'counts' => ['online' => count($visitors), 'unseen' => $unseen],
        'channels' => [
            'mail' => true,
            'sms' => av_channel_ready('sms'),
            'whatsapp' => av_channel_ready('whatsapp'),
            'shop_whatsapp' => av_shop_whatsapp(),
        ],
        'admin_url' => av_admin_url(),
    ];
}