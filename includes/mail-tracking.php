<?php

// Suivi d'ouverture des e-mails : chaque message envoyé par la plateforme ou
// une boutique reçoit un jeton et une image invisible de 1 × 1 pixel. Quand le
// destinataire affiche le message avec les images, le pixel est demandé à
// mail-open.php, qui note l'ouverture dans la base du commerce.
//
// C'est une mesure indicative, pas une preuve de lecture : Outlook bloque les
// images par défaut, et la protection de la vie privée d'Apple Mail les
// précharge pour tous les messages. Les chiffres servent à comparer des envois
// entre eux, pas à certifier qu'une personne a lu.

const MAIL_OPEN_SOURCES = [
    'action-commerciale' => 'Annonce de l’action commerciale',
    'boutique' => 'Boutique (transactionnel)',
    'compte' => 'Compte client / mot de passe',
    'messagerie' => 'Messagerie vendeur',
    'reservation' => 'Réservation avec arrhes',
    'retrait' => 'Retrait en boutique',
    'alerte-chineur' => 'Alerte chineur',
    'assistance-vendeur' => 'Assistance vendeur',
    'espace-visiteur' => 'Espace visiteur',
    'recu' => 'Reçu / comptabilité',
    'caisse' => 'Vente en caisse (avis au gérant)',
    'galerie' => 'Abonnements et demandes de galerie',
    'plateforme' => 'Plateforme Brocs',
];

/** Signification lisible d'une source de suivi. */
function mail_open_source_label(string $source): string
{
    return MAIL_OPEN_SOURCES[$source] ?? ($source !== '' ? $source : 'Autre');
}

function mail_open_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mail_opens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT '',
  context TEXT NOT NULL DEFAULT '',
  sent_at INTEGER NOT NULL,
  first_open_at INTEGER,
  last_open_at INTEGER,
  open_count INTEGER NOT NULL DEFAULT 0,
  bot_count INTEGER NOT NULL DEFAULT 0,
  ip TEXT NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL DEFAULT ''
)
SQL);
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mail_opens_sent ON mail_opens(sent_at)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mail_opens_source ON mail_opens(source)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mail_opens_email ON mail_opens(email COLLATE NOCASE)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mail_opens_context ON mail_opens(context)');
    $done = true;
}

/** Requête servie en local (aucune boîte réelle ne reçoit le message). */
function mail_open_is_local_host(): bool
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')
        || filter_var($host, FILTER_VALIDATE_IP) !== false;
}

/** Adresse absolue du pixel, avec le préfixe d'un commerce servi sous /<identifiant>/. */
function mail_open_pixel_url(string $token): string
{
    $path = '/mail-open.php?t=' . rawurlencode($token);
    if (function_exists('shop_abs_url')) return shop_abs_url($path);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}

/**
 * Enregistre un message qui part et rend son jeton de suivi ('' si la base
 * n'est pas disponible : le message part quand même, sans compteur).
 */
function mail_open_token_create(string $to, string $subject, string $source = 'boutique', string $context = ''): string
{
    $to = mb_substr(trim($to), 0, 190);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return '';
    // Pages publiques (inscription, annuaire) : pas de config.php, donc pas de base — le message part sans compteur.
    if (!function_exists('db')) return '';
    mail_open_ensure_schema();
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO mail_opens (token, email, subject, source, context, sent_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$token, $to, mb_substr($subject, 0, 190), mb_substr($source, 0, 60), mb_substr($context, 0, 120), time()]);
    return $token;
}

/** Expéditeur par défaut : le nom de la boutique et une adresse noreply du domaine servi. */
function mail_open_default_from(): string
{
    $site = '';
    if (function_exists('get_content')) {
        try {
            $site = (string) (get_content()['site_name'] ?? '');
        } catch (Throwable $e) {
        }
    }
    if ($site === '' && function_exists('tenant')) {
        $site = (string) tenant('name');
    }
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = mail_open_is_local_host();
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    return 'From: ' . str_replace(['"', "\r", "\n"], '', $site) . " <$from>";
}

/** Corps HTML : le texte reste lisible, les liens deviennent cliquables, le pixel ferme le message. */
function mail_open_html_body(string $body, string $pixelUrl): string
{
    $escaped = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $linked = preg_replace_callback('~https?://[^\s<]+~u', static fn (array $m): string =>
        '<a href="' . $m[0] . '" style="color:#0b57d0">' . $m[0] . '</a>', $escaped);
    $pixel = $pixelUrl !== ''
        ? '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;opacity:0;">'
        : '';
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"></head>'
        . '<body style="margin:0;padding:0;background:#fff;color:#222;">'
        . '<div style="max-width:640px;margin:0 auto;padding:20px;font-family:-apple-system,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;white-space:pre-wrap;">'
        . $linked . '</div>' . $pixel . '</body></html>';
}

/** Version MIME « alternative » : texte brut d'origine + HTML avec le pixel. */
function mail_open_mime(string $body, string $pixelUrl): array
{
    $boundary = '=_brocs_' . bin2hex(random_bytes(12));
    $plain = str_replace(["\r\n", "\r"], "\n", $body);
    $plain = preg_replace('/^--' . preg_quote($boundary, '/') . '/m', '-- ', $plain);
    $plain = str_replace("\n", "\r\n", (string) $plain);
    $html = str_replace("\n", "\r\n", mail_open_html_body($body, $pixelUrl));
    $mime = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$plain\r\n"
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n"
        . "--$boundary--";
    return ['boundary' => $boundary, 'body' => $mime];
}

/**
 * Envoi central avec suivi. $options :
 *   from      en-tête « From: … » complet (défaut : nom de la boutique)
 *   reply_to  adresse de réponse
 *   log       fichier de boîte locale (écrit seulement en local)
 *   local     force le mode local au lieu de le déduire de l'hôte
 *   context   identifiant libre (ex. « aco:12 ») pour regrouper les envois
 */
function mail_open_send(string $to, string $subject, string $body, string $source = 'boutique', array $options = []): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $local = array_key_exists('local', $options) ? (bool) $options['local'] : mail_open_is_local_host();
    $token = '';
    try {
        $token = mail_open_token_create($to, $subject, $source, (string) ($options['context'] ?? ''));
    } catch (Throwable $e) {
        error_log('Suivi d’ouverture e-mail : ' . $e->getMessage());
    }
    $pixel = $token !== '' ? mail_open_pixel_url($token) : '';
    $log = (string) ($options['log'] ?? '');
    if ($local) {
        if ($log !== '') {
            $extra = $pixel !== '' ? "\n\nPixel d’ouverture (cliquer pour simuler l’ouverture) : $pixel" : '';
            @file_put_contents($log, '[' . date('c') . "] À : $to\nObjet : $subject\n$body$extra\n----\n", FILE_APPEND | LOCK_EX);
        }
        return true;
    }
    if (!function_exists('mail')) return false;
    $from = trim(str_replace(["\r", "\n"], '', (string) ($options['from'] ?? mail_open_default_from())));
    $replyTo = trim(str_replace(["\r", "\n"], '', (string) ($options['reply_to'] ?? '')));
    $mime = mail_open_mime($body, $pixel);
    $headers = [];
    if ($from !== '') $headers[] = $from;
    if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $mime['boundary'] . '"';
    // Messages de la plateforme (expéditeur du domaine du portail) : SMTP authentifié de contact@ ; sinon, ou en cas d'échec, mail().
    require_once __DIR__ . '/portal-mailer.php';
    if (portal_mailer_dispatch($from, $to, '=?UTF-8?B?' . base64_encode($subject) . '?=',
        array_slice($headers, $from !== '' ? 1 : 0), $mime['body']) === true) {
        return true;
    }
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $mime['body'], implode("\r\n", $headers));
}

/** Robots et aperçus de liens : leurs demandes ne comptent pas comme des ouvertures. */
function mail_open_is_bot(string $ua): bool
{
    if ($ua === '') return false;
    return (bool) preg_match('/bot\b|crawler|spider|facebookexternalhit|slackbot|twitterbot|telegrambot|discordbot|linkedinbot|pinterest|embedly|quora link preview|skype uri preview|google page speed|pingdom|uptime|monitor|zgrab|masscan/i', $ua);
}

/** Enregistre une demande de pixel dans la base déjà ouverte (utilisé par mail-open.php). */
function mail_open_record_token(PDO $pdo, string $token, string $ua, string $ip): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    try {
        $ua = mb_substr(str_replace(["\r", "\n"], ' ', $ua), 0, 400);
        if (mail_open_is_bot($ua)) {
            $st = $pdo->prepare('UPDATE mail_opens SET bot_count = bot_count + 1, user_agent = ? WHERE token = ?');
            $st->execute([$ua, $token]);
        } else {
            $now = time();
            $st = $pdo->prepare('UPDATE mail_opens SET open_count = open_count + 1, first_open_at = COALESCE(first_open_at, ?),
                last_open_at = ?, ip = ?, user_agent = ? WHERE token = ?');
            $st->execute([$now, $now, mb_substr($ip, 0, 45), $ua, $token]);
        }
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('Suivi d’ouverture e-mail : ' . $e->getMessage());
        return false;
    }
}

/** Chiffres d'un lot d'envois (ex. une opération commerciale). */
function mail_open_context_summary(string $context): array
{
    $out = ['sent' => 0, 'opened' => 0, 'opens' => 0, 'last_open' => 0];
    if ($context === '') return $out;
    try {
        mail_open_ensure_schema();
        $st = db()->prepare('SELECT COUNT(*) AS sent, COALESCE(SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END), 0) AS opened,
            COALESCE(SUM(open_count), 0) AS opens, COALESCE(MAX(last_open_at), 0) AS last_open
            FROM mail_opens WHERE context = ?');
        $st->execute([$context]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'sent' => (int) ($row['sent'] ?? 0),
            'opened' => (int) ($row['opened'] ?? 0),
            'opens' => (int) ($row['opens'] ?? 0),
            'last_open' => (int) ($row['last_open'] ?? 0),
        ];
    } catch (Throwable $e) {
        return $out;
    }
}

/** Libellé lisible du logiciel de messagerie, d'après la signature de la demande. */
function mail_open_client_label(string $ua): string
{
    $ua = strtolower($ua);
    if ($ua === '') return '—';
    return match (true) {
        str_contains($ua, 'googleimageproxy') => 'Gmail (proxy d’images)',
        str_contains($ua, 'yahoomailproxy') => 'Yahoo Mail (proxy)',
        str_contains($ua, 'outlook') || str_contains($ua, 'microsoft office') => 'Outlook',
        str_contains($ua, 'thunderbird') => 'Thunderbird',
        str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'apple mail') || str_contains($ua, 'applewebkit') && str_contains($ua, 'macintosh') => 'Apple Mail',
        str_contains($ua, 'android') && str_contains($ua, 'gmail') => 'Gmail (Android)',
        default => 'Autre client',
    };
}
