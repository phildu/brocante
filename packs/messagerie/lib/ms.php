<?php
// Messagerie & WhatsApp : questions des visiteurs et réponses.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

const MS_MODULE = 'messagerie';
const MS_MAX_PER_IP_HOUR = 5;

function ms_defaults(): array
{
    return ['whatsapp' => '', 'notify_email' => '', 'wa_template' => "Bonjour, je suis intéressé(e) par « {piece} » ({lien}). Est-elle toujours disponible ?", 'show_form' => '1', 'show_whatsapp' => '1'];
}

function ms_settings(): array
{
    $out = ms_defaults();
    foreach (db()->query('SELECT key, value FROM ms_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    return $out;
}

/** Numéro WhatsApp en chiffres internationaux (33612345678) ; un 06… devient 33 6… ; '' si invalide. */
function ms_wa_number(string $raw): string
{
    $d = preg_replace('/[^0-9+]/', '', $raw);
    if (str_starts_with($d, '+')) $d = substr($d, 1);
    elseif (str_starts_with($d, '00')) $d = substr($d, 2);
    elseif (str_starts_with($d, '0')) $d = '33' . substr($d, 1);
    return preg_match('/^\d{9,15}$/', $d) ? $d : '';
}

function ms_settings_save(array $in): void
{
    $st = db()->prepare('INSERT INTO ms_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach (ms_defaults() as $k => $_) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string) $in[$k]);
        if ($k === 'whatsapp') $v = $v === '' ? '' : (ms_wa_number($v) ?: '');
        if ($k === 'notify_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $v = '';
        if (in_array($k, ['show_form', 'show_whatsapp'], true)) $v = $v === '1' ? '1' : '0';
        $st->execute([$k, mb_substr($v, 0, 400)]);
    }
}

function ms_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    return password_reset_mail($to, $subject, $body . ($replyTo !== '' ? "\n\n(Répondre à : $replyTo)" : ''));
}

/** Message d'un visiteur. -> [message, erreur]. */
function ms_receive(string $name, string $email, string $ref, string $body, string $ip): array
{
    $email = mb_strtolower(trim($email));
    $name = mb_substr(trim($name), 0, 80);
    $body = mb_substr(trim($body), 0, 2000);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) return [null, 'Adresse e-mail invalide.'];
    if (mb_strlen($body) < 5) return [null, 'Écrivez votre message (5 caractères minimum).'];
    if (preg_match_all('#https?://#i', $body) > 2) return [null, 'Trop de liens dans le message.'];
    $hash = hash('sha256', $ip . '|ms|' . tenant_slug());
    db()->prepare('DELETE FROM ms_hits WHERE at < ?')->execute([time() - 3600]);
    $n = db()->prepare('SELECT COUNT(*) FROM ms_hits WHERE ip_hash = ? AND at > ?');
    $n->execute([$hash, time() - 3600]);
    if ((int) $n->fetchColumn() >= MS_MAX_PER_IP_HOUR) return [null, 'Trop de messages depuis votre connexion : réessayez dans une heure.'];
    db()->prepare('INSERT INTO ms_hits (ip_hash, at) VALUES (?, ?)')->execute([$hash, time()]);
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
    $pn = '';
    if ($ref !== '') {
        $q = db()->prepare('SELECT name FROM products WHERE ref = ?');
        $q->execute([$ref]);
        $pn = (string) $q->fetchColumn();
        if ($pn === '') $ref = '';
    }
    db()->prepare("INSERT INTO ms_messages (direction, email, name, product_ref, body) VALUES ('in', ?, ?, ?, ?)")->execute([$email, $name, $ref, $body]);
    $to = ms_settings()['notify_email'];
    if ($to !== '') {
        $site = (string) (get_content()['site_name'] ?? tenant('name'));
        ms_mail($to, 'Nouveau message — ' . $site . ($pn !== '' ? ' — ' . $pn : ''),
            "De : " . ($name !== '' ? "$name " : '') . "<$email>\n" . ($pn !== '' ? "Pièce : $pn (réf. $ref) — " . shop_abs_url('/produit.php?ref=' . rawurlencode($ref)) . "\n" : '') . "\n$body\n\nRépondre : "
            . shop_abs_url('/admin/plugin.php?p=messagerie&v=index'), $email);
    }
    return ['Merci ! Votre message est bien parti : nous vous répondons par e-mail.', null];
}

/** Réponse de la boutique, envoyée par e-mail au visiteur. -> message d'erreur ou null. */
function ms_reply(int $inId, string $body): ?string
{
    $q = db()->prepare("SELECT * FROM ms_messages WHERE id = ? AND direction = 'in'");
    $q->execute([$inId]);
    $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m) return 'Message introuvable.';
    $body = mb_substr(trim($body), 0, 4000);
    if (mb_strlen($body) < 2) return 'Écrivez votre réponse.';
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    $ok = ms_mail($m['email'], 'Re : votre message — ' . $site, $body . "\n\n— $site\n" . shop_abs_url('/boutique.php'));
    if (!$ok) return "Envoi impossible : le serveur de messagerie n'a pas accepté l'e-mail.";
    db()->prepare("INSERT INTO ms_messages (direction, email, name, product_ref, body, status) VALUES ('out', ?, ?, ?, ?, 'sent')")->execute([$m['email'], $m['name'], $m['product_ref'], $body]);
    db()->prepare("UPDATE ms_messages SET status = 'answered' WHERE email = ? AND direction = 'in' AND id <= ?")->execute([$m['email'], $inId]);
    return null;
}
