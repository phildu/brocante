<?php
// Tests du module Notifications (logique métier), sur une base SQLite et un
// fichier de configuration jetables. Aucun appel réseau : les API (Twilio,
// Discord, Telegram) et mail() sont remplacées par des doubles ; le client SMTP
// est testé contre un faux serveur local.
//   php scripts/test-notifications.php
// N'écrit jamais dans brocante.db ni dans .secrets/.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmpDir = sys_get_temp_dir() . '/brocante-nt-test-' . getmypid();
mkdir($tmpDir, 0700, true);
putenv('BROCANTE_DB=' . $tmpDir . '/test.db');
putenv('SITE_URL=https://brocante.arrimage.com'); // adresse publique pour les liens de désinscription (sinon : celle du commerce actif, vide en test)
// Base jetable créée à partir de schema.sql : db() (config.php) attend les tables de base d'un commerce (settings, content…).
(new PDO('sqlite:' . $tmpDir . '/test.db'))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
define('NT_CONFIG_FILE', $tmpDir . '/cfg/notifications.json');
register_shutdown_function(function () use ($tmpDir) {
    foreach (glob($tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    foreach (glob($tmpDir . '/cfg/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($tmpDir . '/cfg');
    @rmdir($tmpDir);
});

require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/buvette.php';

$failures = 0;
$checks = 0;
function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "  ✓ $label\n";
    }
}

function paris(string $when): int
{
    return (new DateTimeImmutable($when, new DateTimeZone('Europe/Paris')))->getTimestamp();
}

// Double de nt_http : enregistre les appels, renvoie les réponses programmées (200 vide sinon).
$http = ['calls' => [], 'queue' => []];
nt_hook('http', function ($method, $url, $headers, $body, $auth) use (&$http) {
    $http['calls'][] = compact('method', 'url', 'headers', 'body', 'auth');
    return array_shift($http['queue']) ?? ['status' => 200, 'body' => '{}', 'error' => '', 'retry_after' => null];
});
function http_reply(int $status, array $json = [], ?int $retryAfter = null, string $error = ''): void
{
    global $http;
    $http['queue'][] = ['status' => $status, 'body' => json_encode($json), 'error' => $error, 'retry_after' => $retryAfter];
}
function http_reset(): void
{
    global $http;
    $http = ['calls' => [], 'queue' => []];
}

// Double de mail().
$mails = [];
nt_hook('mail', function ($to, $subject, $body, $headers, $params) use (&$mails) {
    $mails[] = compact('to', 'subject', 'body', 'headers', 'params');
    return true;
});

nt_set_clock(paris('2026-11-10 14:00')); // mardi
nt_boot();

/* ------------------------------------------------------------------ */
echo "Adresses & numéros\n";
check('e-mail normalisé en minuscules', nt_normalize_address('email', '  Alice@Example.FR '), 'alice@example.fr');
check('e-mail invalide refusé', nt_normalize_address('email', 'pas-un-mail'), null);
check('e-mail avec retour à la ligne refusé (injection d\'en-tête)', nt_normalize_address('email', "a@b.fr\r\nBcc: x@y.fr"), null);
check('mobile « 06 12 34 56 78 » -> E.164', nt_normalize_address('sms', '06 12 34 56 78'), '+33612345678');
check('mobile « +33 6 12 34 56 78 »', nt_normalize_address('whatsapp', '+33 6 12 34 56 78'), '+33612345678');
check('mobile « 0033 6… »', nt_normalize_address('sms', '0033612345678'), '+33612345678');
check('mobile « +33 (0)6… » : le 0 de trop est retiré', nt_normalize_address('sms', '+33 (0)6 12 34 56 78'), '+33612345678');
check('mobile sans 0 ni + refusé (ambigu)', nt_normalize_address('sms', '612345678'), null);
check('mobile étranger conservé', nt_normalize_address('sms', '+44 7700 900123'), '+447700900123');
check('mobile trop court refusé', nt_normalize_address('sms', '06 12'), null);
check('Discord : ID utilisateur', nt_normalize_address('discord', '123456789012345678'), '123456789012345678');
$hook = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789';
check('Discord : webhook officiel', nt_normalize_address('discord', $hook), $hook);
check('Discord : URL quelconque refusée (SSRF)', nt_normalize_address('discord', 'https://evil.example/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789'), null);
check('Discord : http (non chiffré) refusé', nt_normalize_address('discord', 'http://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-JKL0123456789'), null);
check('Telegram : chat ID', nt_normalize_address('telegram', '123456789'), '123456789');
check('Telegram : groupe (ID négatif)', nt_normalize_address('telegram', '-1001234567890'), '-1001234567890');
check('Telegram : @canal', nt_normalize_address('telegram', '@brocante_news'), '@brocante_news');
check('Telegram : texte quelconque refusé', nt_normalize_address('telegram', 'bonjour'), null);

/* ------------------------------------------------------------------ */
echo "\nConfiguration\n";
// Le navigateur (Web Push) n'a aucun identifiant à saisir : ses clés VAPID se créent seules.
check('canaux non configurés au départ (hors navigateur)', array_keys(array_filter(array_map(fn($c) => $c['ready'], nt_channel_status()))), ['push']);
check('mise à jour de la section e-mail', nt_config_update('email', ['from_email' => 'boutique@brocante.fr', 'from_name' => 'La Brocante, du Petit Chalet', 'transport' => 'mail']), true);
check('mise à jour Twilio', nt_config_update('twilio', ['account_sid' => 'ACtest', 'auth_token' => 's3cret']), true);
nt_config_update('sms', ['from' => '+33700000000']);
nt_config_update('whatsapp', ['from' => '+14155238886']);
nt_config_update('discord', ['bot_token' => 'bot-token']);
nt_config_update('telegram', ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ0123']);
check('secret laissé vide : valeur conservée', (function () {
    nt_config_update('twilio', ['account_sid' => 'ACtest', 'auth_token' => '']);
    return nt_cfg('twilio.auth_token');
})(), 's3cret');
check('fichier de configuration en 0600', substr(sprintf('%o', fileperms(NT_CONFIG_FILE)), -4), '0600');
check('dossier protégé par un .htaccess', is_file(dirname(NT_CONFIG_FILE) . '/.htaccess'), true);
check('rechargé depuis le disque', nt_config(true)['sms']['from'], '+33700000000');
check('tous les canaux prêts', array_values(array_filter(array_column(nt_channel_status(), 'ready'))), [true, true, true, true, true, true]);

/* ------------------------------------------------------------------ */
echo "\nUtilisateurs & canaux\n";
$alice = nt_user_create('Alice Martin');
check('jeton secret de 32 caractères hexadécimaux', (bool) preg_match('/^[0-9a-f]{32}$/', nt_user_get($alice)['token']), true);
check('canal e-mail enregistré', nt_user_set_channel($alice, 'email', 'Alice@Example.fr', true, 1), null);
check('canal SMS enregistré', nt_user_set_channel($alice, 'sms', '06 12 34 56 78', true, 0), null);
check('canal Discord (webhook)', nt_user_set_channel($alice, 'discord', $hook, true, 2), null);
check('adresse invalide : message d\'erreur', nt_user_set_channel($alice, 'sms', 'abc') !== null, true);
check('canal inconnu : message d\'erreur', nt_user_set_channel($alice, 'fax', '0102030405'), 'Canal inconnu.');
$u = nt_user_get($alice);
check('adresse e-mail stockée normalisée', $u['channels']['email']['address'], 'alice@example.fr');
check('retrouvée par adresse', nt_user_find_by_address('sms', '+33612345678'), $alice);
check('retrouvée par jeton', nt_user_by_token($u['token'])['id'], $alice);
check('jeton mal formé : refusé sans requête', nt_user_by_token("' OR 1=1 --"), null);
check('prénom pour {prenom}', nt_user_first_name($u), 'Alice');
check('recherche par nom', count(nt_users_search('mart')), 1);
check('recherche : % n\'est pas un joker', count(nt_users_search('%')), 0);
check('recherche par adresse', count(nt_users_search('0612')), 0);
check('recherche par adresse (E.164)', count(nt_users_search('+3361234')), 1);

$bob = nt_user_create('Bob Durand');
nt_user_set_channel($bob, 'email', 'bob@example.fr');
nt_user_set_channel($bob, 'telegram', '555666777', true, 0);

/* ------------------------------------------------------------------ */
echo "\nRubriques & préférences\n";
$topics = nt_topics();
check('rubriques d\'origine créées', isset($topics['commande'], $topics['newsletter']), true);
check('newsletter = marketing', $topics['newsletter']['is_marketing'], 1);
check('commande = transactionnelle', $topics['commande']['is_marketing'], 0);
$u = nt_user_get($alice);
check('transactionnel : reçu par défaut', nt_pref_effective($u, $topics['commande'], 'email'), true);
check('marketing : refusé par défaut (opt-in)', nt_pref_effective($u, $topics['newsletter'], 'email'), false);
nt_set_pref($alice, 'newsletter', 'email', true, 'utilisateur');
check('opt-in enregistré avec sa source', db()->query("SELECT source FROM nt_preferences WHERE user_id = $alice AND topic = 'newsletter'")->fetchColumn(), 'utilisateur');
check('marketing : accepté après opt-in', nt_pref_effective(nt_user_get($alice), $topics['newsletter'], 'email'), true);
nt_set_pref($alice, 'commande', 'sms', false, 'utilisateur');
check('opt-out d\'un canal transactionnel', nt_pref_effective(nt_user_get($alice), $topics['commande'], 'sms'), false);
nt_set_pref($alice, 'commande', 'sms', true, 'utilisateur');
check('retour à la valeur par défaut : ligne supprimée', (int) db()->query("SELECT COUNT(*) FROM nt_preferences WHERE user_id = $alice AND topic = 'commande'")->fetchColumn(), 0);
check('rubrique personnalisée créée', nt_topic_save('atelier', 'Ateliers', 'Dates des ateliers', true), null);
check('clé invalide refusée', nt_topic_save('Bad Key', 'x', '', false) !== null, true);
check('« test » est réservée', nt_topic_save('test', 'x', '', false) !== null, true);
nt_topic_save('atelier', 'Ateliers créatifs', '', false); // tentative de passer en transactionnel
check('le caractère marketing ne peut pas être modifié', nt_topics()['atelier']['is_marketing'], 1);
check('rubrique d\'origine non supprimable', nt_topic_delete('commande'), false);
check('rubrique personnalisée supprimable', nt_topic_delete('atelier'), true);

/* ------------------------------------------------------------------ */
echo "\nHeures calmes (marketing SMS / WhatsApp)\n";
check('mardi 14 h : autorisé', nt_quiet_adjust(paris('2026-11-10 14:00')), paris('2026-11-10 14:00'));
check('mardi 21 h -> mercredi 8 h', nt_quiet_adjust(paris('2026-11-10 21:00')), paris('2026-11-11 08:00'));
check('mardi 3 h -> mardi 8 h', nt_quiet_adjust(paris('2026-11-10 03:00')), paris('2026-11-10 08:00'));
check('samedi 10 h : autorisé', nt_quiet_adjust(paris('2026-11-07 10:00')), paris('2026-11-07 10:00'));
check('samedi 21 h -> lundi 8 h (dimanche exclu)', nt_quiet_adjust(paris('2026-11-07 21:00')), paris('2026-11-09 08:00'));
check('dimanche midi -> lundi 8 h', nt_quiet_adjust(paris('2026-11-08 12:00')), paris('2026-11-09 08:00'));

/* ------------------------------------------------------------------ */
echo "\nPlanification\n";
$plan = nt_plan('commande', [$alice, $bob]);
check('2 destinataires pour une rubrique transactionnelle', $plan['recipients'], 2);
check('Alice : SMS puis e-mail puis Discord (ordre de priorité)', array_column($plan['users'][$alice]['targets'], 'channel'), ['sms', 'email', 'discord']);
$plan = nt_plan('newsletter', [$alice, $bob]);
check('newsletter : seule Alice (opt-in) est ciblée', $plan['recipients'], 1);
check('newsletter : Bob exclu pour « non abonné »', $plan['users'][$bob]['skipped'][0][1], 'Non abonné à cette rubrique');
check('newsletter : un seul canal pour Alice (e-mail)', array_column($plan['users'][$alice]['targets'], 'channel'), ['email']);
$plan = nt_plan('commande', [$alice], ['channels' => ['email']]);
check('restriction à un canal', array_column($plan['users'][$alice]['targets'], 'channel'), ['email']);
$plan = nt_plan('commande', [$alice], ['strategy' => 'fallback']);
check('repli : un seul envoi visé a priori', array_sum($plan['counts']), 1);
check('rubrique inconnue', isset(nt_plan('nimportequoi', [$alice])['error']), true);
nt_user_disable_channel($alice, 'discord', 'test');
$plan = nt_plan('commande', [$alice]);
check('canal désactivé exclu avec son motif', $plan['users'][$alice]['skipped'][0][1], 'Canal désactivé (test)');
nt_user_set_channel($alice, 'discord', $hook, true, 2);
db()->prepare('UPDATE nt_users SET is_active = 0 WHERE id = ?')->execute([$bob]);
check('utilisateur désactivé : aucun canal', nt_plan('commande', [$bob])['recipients'], 0);
db()->prepare('UPDATE nt_users SET is_active = 1 WHERE id = ?')->execute([$bob]);

/* ------------------------------------------------------------------ */
echo "\nEnvoi tous canaux (Twilio, e-mail, Discord)\n";
http_reset();
http_reply(201, ['sid' => 'SM111']);   // SMS
http_reply(200, ['id' => '9001']);     // Discord
$mails = [];
$r = nt_notify('commande', [$alice], 'Votre colis est parti', "Bonjour {prenom},\nvotre commande est expédiée.");
check('3 envois mis en file', $r['queued'], 3);
check('3 envois réussis', $r['processed']['sent'], 3);
$sms = $http['calls'][0];
check('SMS : URL Twilio', $sms['url'], 'https://api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json');
check('SMS : authentification Basic (jamais dans l\'URL)', $sms['auth'], 'ACtest:s3cret');
parse_str($sms['body'], $f);
check('SMS : destinataire en E.164', $f['To'], '+33612345678');
check('SMS : expéditeur', $f['From'], '+33700000000');
check('SMS : sujet + message, {prenom} remplacé', $f['Body'], "Votre colis est parti\n\nBonjour Alice,\nvotre commande est expédiée.");
$dc = $http['calls'][1];
check('Discord webhook : URL avec wait=true', $dc['url'], $hook . '?wait=true');
$dcBody = json_decode($dc['body'], true);
check('Discord : aucune mention autorisée (@everyone neutralisé)', $dcBody['allowed_mentions'], ['parse' => []]);
check('e-mail : envoyé via mail()', count($mails), 1);
check('e-mail : destinataire', $mails[0]['to'], 'alice@example.fr');
check('e-mail : objet', iconv_mime_decode($mails[0]['subject'], 0, 'UTF-8'), 'Votre colis est parti');
check('e-mail : multipart texte + HTML', str_contains($mails[0]['headers'], 'multipart/alternative') && str_contains($mails[0]['body'], 'text/html'), true);
check('e-mail : nom d\'expéditeur entre guillemets', str_contains($mails[0]['headers'], 'From: "La Brocante, du Petit Chalet" <boutique@brocante.fr>'), true);
check('e-mail : pas de désinscription sur un message de suivi', str_contains($mails[0]['headers'], 'List-Unsubscribe'), false);
check('e-mail : enveloppe -f', $mails[0]['params'], '-fboutique@brocante.fr');
$st = db()->query("SELECT status, provider_id FROM nt_deliveries WHERE channel = 'sms'")->fetch();
check('identifiant fournisseur conservé', $st, ['status' => 'sent', 'provider_id' => 'SM111']);

echo "\nMarketing : consentement, désinscription, heures calmes\n";
http_reset();
$mails = [];
$r = nt_notify('newsletter', [$alice, $bob], 'Nouveautés', "Salut {prenom} !\nDe nouvelles trouvailles.");
check('newsletter : un seul destinataire (opt-in)', $r['recipients'], 1);
$h = $mails[0]['headers'];
check('e-mail marketing : en-tête List-Unsubscribe', (bool) preg_match('#List-Unsubscribe: <https://brocante\.arrimage\.com/notifications/preferences\.php\?t=[0-9a-f]{32}>#', $h), true);
check('e-mail marketing : désinscription en un clic (RFC 8058)', str_contains($h, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), true);
check('e-mail marketing : lien de désinscription ajouté au texte', str_contains(quoted_printable_decode($mails[0]['body']), 'vous désinscrire : https://brocante.arrimage.com/notifications/preferences.php?t='), true);

// SMS marketing envoyé le soir : différé au lendemain 8 h.
nt_set_pref($alice, 'newsletter', 'sms', true, 'admin');
nt_set_clock(paris('2026-11-10 21:30'));
http_reset();
$r = nt_notify('newsletter', [$alice], 'Vente', 'Grand vide-grenier samedi.', ['channels' => ['sms'], 'short_body' => 'Vide-grenier samedi !']);
check('SMS marketing du soir : rien envoyé tout de suite', [$r['queued'], $r['processed']['sent'], count($http['calls'])], [1, 0, 0]);
check('SMS marketing : programmé le lendemain à 8 h', (int) db()->query("SELECT next_attempt_at FROM nt_deliveries ORDER BY id DESC LIMIT 1")->fetchColumn(), paris('2026-11-11 08:00'));
nt_set_clock(paris('2026-11-11 08:01'));
http_reply(201, ['sid' => 'SM222']);
$p = nt_process_queue();
check('SMS marketing envoyé le lendemain', $p['sent'], 1);
parse_str($http['calls'][0]['body'], $f);
check('SMS : version courte + lien de désinscription', (bool) preg_match('#^Vide-grenier samedi !\nDésinscription : https://brocante\.arrimage\.com/notifications/preferences\.php\?t=[0-9a-f]{32}$#', $f['Body']), true);

// Désinscription entre la mise en file et l'envoi.
nt_set_clock(paris('2026-11-11 21:30'));
http_reset();
nt_notify('newsletter', [$alice], 'Dernière minute', 'Texte', ['channels' => ['sms']]);
nt_user_optout_marketing($alice);
nt_set_clock(paris('2026-11-12 08:30'));
$p = nt_process_queue();
check('désabonné entre-temps : envoi annulé', [$p['sent'], $p['skipped'], count($http['calls'])], [0, 1, 0]);
check('motif conservé dans l\'historique', db()->query("SELECT error FROM nt_deliveries ORDER BY id DESC LIMIT 1")->fetchColumn(), 'Désabonné entre-temps');
check('opt-out marketing : toutes les cellules marketing refermées', nt_pref_effective(nt_user_get($alice), nt_topics()['newsletter'], 'email'), false);
check('opt-out marketing : les messages de suivi continuent', nt_pref_effective(nt_user_get($alice), nt_topics()['commande'], 'email'), true);
nt_set_clock(paris('2026-11-10 14:00'));

/* ------------------------------------------------------------------ */
echo "\nErreurs, reprises, désactivation de canal\n";
$carol = nt_user_create('Carol');
nt_user_set_channel($carol, 'sms', '07 11 22 33 44');
http_reset();
http_reply(500, ['message' => 'oups']);
$r = nt_notify('commande', [$carol], '', 'Test reprise');
check('erreur 500 : à réessayer, pas encore en échec', [$r['processed']['retry'], $r['processed']['failed']], [1, 0]);
$row = db()->query("SELECT status, attempts, next_attempt_at FROM nt_deliveries WHERE user_id = $carol")->fetch();
check('1re reprise dans 60 s', [$row['status'], $row['attempts'], (int) $row['next_attempt_at']], ['queued', 1, nt_now() + 60]);
check('pas de nouvelle tentative avant l\'échéance', nt_process_queue()['sent'], 0);
nt_set_clock(nt_now() + 61);
http_reply(429, ['message' => 'trop vite'], 120);
$p = nt_process_queue();
$row = db()->query("SELECT attempts, next_attempt_at FROM nt_deliveries WHERE user_id = $carol")->fetch();
check('429 + Retry-After 120 s : on attend le plus long des deux délais (600 s)', [$p['retry'], (int) $row['next_attempt_at'] - nt_now()], [1, 600]);
nt_set_clock(nt_now() + 601);
http_reply(0, [], null, 'timeout');
nt_process_queue();
nt_set_clock(nt_now() + 3601);
http_reply(503, []);
$p = nt_process_queue();
$row = db()->query("SELECT status, attempts, error FROM nt_deliveries WHERE user_id = $carol")->fetch();
check('4 tentatives épuisées : en échec', [$p['failed'], $row['status'], $row['attempts']], [1, 'failed', 4]);
check('message d\'abandon explicite', str_contains($row['error'], 'abandon après 4 tentatives'), true);
check('« Réessayer » remet en file', nt_retry_delivery((int) db()->query("SELECT id FROM nt_deliveries WHERE user_id = $carol")->fetchColumn()), true);
http_reply(201, ['sid' => 'SM333']);
check('réessai réussi', nt_process_queue()['sent'], 1);

// Désabonnement STOP chez Twilio -> le canal est coupé.
http_reply(400, ['code' => 21610, 'message' => 'blocked']);
$r = nt_notify('commande', [$carol], '', 'Encore un');
check('STOP Twilio : échec définitif', $r['processed']['failed'], 1);
check('STOP Twilio : canal désactivé avec son motif', nt_user_get($carol)['channels']['sms']['disabled_reason'], 'Le destinataire s\'est désabonné (STOP)');
check('STOP Twilio : plus aucune cible', nt_plan('commande', [$carol])['recipients'], 0);
check('réactivation manuelle efface le motif', (function () use ($carol) {
    nt_user_set_channel($carol, 'sms', '07 11 22 33 44', true);
    return nt_user_get($carol)['channels']['sms']['disabled_reason'];
})(), '');

// Erreur définitive sans reprise (numéro invalide).
http_reply(400, ['code' => 21211, 'message' => 'Invalid To']);
$r = nt_notify('commande', [$carol], '', 'x');
check('numéro invalide : échec sans reprise', [$r['processed']['failed'], $r['processed']['retry']], [1, 0]);

// Bail expiré : un envoi resté « sending » est repris.
$carolId = db()->query("SELECT id FROM nt_deliveries WHERE user_id = $carol ORDER BY id DESC LIMIT 1")->fetchColumn();
db()->prepare("UPDATE nt_deliveries SET status = 'sending', next_attempt_at = ? WHERE id = ?")->execute([nt_now() - 5, $carolId]);
http_reply(201, ['sid' => 'SM444']);
check('bail expiré : envoi repris', nt_process_queue()['sent'], 1);
db()->prepare("UPDATE nt_deliveries SET status = 'sending', next_attempt_at = ? WHERE id = ?")->execute([nt_now() + 300, $carolId]);
check('bail en cours : envoi laissé tranquille', nt_process_queue()['sent'], 0);

/* ------------------------------------------------------------------ */
echo "\nCanal de repli (stratégie « fallback »)\n";
$dave = nt_user_create('Dave');
nt_user_set_channel($dave, 'telegram', '999888777', true, 0);
nt_user_set_channel($dave, 'email', 'dave@example.fr', true, 1);
http_reset();
$mails = [];
http_reply(403, ['description' => 'Forbidden: bot was blocked by the user']);
$r = nt_notify('commande', [$dave], 'Colis', 'Votre colis est arrivé', ['strategy' => 'fallback']);
check('repli : 2 envois en file, rang 0 puis 1', db()->query("SELECT group_concat(channel || rank) FROM (SELECT * FROM nt_deliveries WHERE user_id = $dave ORDER BY id)")->fetchColumn(), 'telegram0,email1');
check('repli : Telegram bloqué -> e-mail envoyé', [$r['processed']['failed'], $r['processed']['sent'], count($mails)], [1, 1, 1]);
check('Telegram bloqué : canal coupé', nt_user_get($dave)['channels']['telegram']['enabled'], 0);
check('Telegram : jeton jamais dans les en-têtes ni le corps', (function () use ($http) {
    $c = $http['calls'][0];
    return str_contains(implode('', $c['headers']) . $c['body'], 'ABCDEFGHIJKL');
})(), false);
check('Telegram : texte brut (pas de parse_mode)', array_key_exists('parse_mode', json_decode($http['calls'][0]['body'], true)), false);

nt_user_set_channel($dave, 'telegram', '999888777', true, 0);
http_reset();
$mails = [];
http_reply(200, ['ok' => true, 'result' => ['message_id' => 77]]);
$r = nt_notify('commande', [$dave], 'Colis', 'Deuxième colis', ['strategy' => 'fallback']);
check('repli : le 1er canal réussit -> e-mail ignoré', [$r['processed']['sent'], $r['processed']['skipped'], count($mails)], [1, 1, 0]);
check('repli : motif du saut', db()->query("SELECT error FROM nt_deliveries WHERE channel = 'email' AND user_id = $dave ORDER BY id DESC LIMIT 1")->fetchColumn(), 'Déjà reçu par un canal prioritaire');

// Rang 0 en attente de reprise : le rang 1 patiente.
http_reset();
http_reply(500, []);
$r = nt_notify('commande', [$dave], 'Colis', 'Troisième', ['strategy' => 'fallback']);
check('repli : rang 0 en reprise, rang 1 en attente', [$r['processed']['retry'], $r['processed']['deferred'], $r['processed']['sent']], [1, 1, 0]);

/* ------------------------------------------------------------------ */
echo "\nDiscord en message privé (bot) et WhatsApp\n";
$eve = nt_user_create('Eve');
nt_user_set_channel($eve, 'discord', '234567890123456789');
nt_user_set_channel($eve, 'whatsapp', '06 99 88 77 66', true, 1);
http_reset();
http_reply(200, ['id' => '555555555555555555']); // ouverture du MP
http_reply(200, ['id' => '666']);                // message
http_reply(201, ['sid' => 'SMwa']);              // WhatsApp
nt_notify('commande', [$eve], 'Salut', 'Message privé');
check('Discord MP : ouverture du salon puis envoi', [$http['calls'][0]['url'], $http['calls'][1]['url']], [
    'https://discord.com/api/v10/users/@me/channels',
    'https://discord.com/api/v10/channels/555555555555555555/messages',
]);
check('Discord MP : en-tête bot', in_array('Authorization: Bot bot-token', $http['calls'][0]['headers'], true), true);
parse_str($http['calls'][2]['body'], $f);
check('WhatsApp : préfixe whatsapp: sur To et From', [$f['To'], $f['From']], ['whatsapp:+33699887766', 'whatsapp:+14155238886']);

http_reset();
http_reply(200, ['id' => '555555555555555555']);
http_reply(403, ['code' => 50007, 'message' => 'Cannot send messages to this user']);
http_reply(400, ['code' => 63016, 'message' => 'outside window']);
$r = nt_notify('commande', [$eve], '', 'Encore');
check('Discord MP fermés / WhatsApp hors fenêtre : échecs définitifs', $r['processed']['failed'], 2);
check('WhatsApp : motif explicite', str_contains(db()->query("SELECT error FROM nt_deliveries WHERE channel = 'whatsapp' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'modèle de message approuvé'), true);

/* ------------------------------------------------------------------ */
echo "\nMessage de test\n";
http_reset();
http_reply(201, ['sid' => 'SMtest']);
$r = nt_send_test($alice, 'sms');
check('test : envoi même sans abonnement à une rubrique', $r['processed']['sent'], 1);
nt_set_pref($alice, 'commande', 'sms', false, 'utilisateur');
http_reply(201, ['sid' => 'SMtest2']);
check('test : ignore les préférences de rubrique', nt_send_test($alice, 'sms')['processed']['sent'], 1);
nt_set_pref($alice, 'commande', 'sms', true, 'utilisateur');
nt_user_disable_channel($alice, 'sms', 'x');
check('test : respecte l\'état du canal', nt_send_test($alice, 'sms')['recipients'], 0);
nt_user_set_channel($alice, 'sms', '06 12 34 56 78', true, 0);

/* ------------------------------------------------------------------ */
echo "\nE-mail : MIME et sécurité des en-têtes\n";
$m = nt_email_build(['subject' => "Piège\r\nBcc: evil@example.org", 'text' => "Voir https://exemple.fr/?a=1&b=2 <script>", 'unsubscribe_url' => '']);
check('objet : retours à la ligne neutralisés', preg_match('/[\r\n](?!\s)Bcc:/i', $m['subject']), 0);
check('aucun en-tête Bcc injecté', preg_grep('/^Bcc:/i', $m['headers']), []);
check('HTML : balises échappées', str_contains(quoted_printable_decode($m['body']), '&lt;script&gt;'), true);
check('HTML : lien cliquable', str_contains(quoted_printable_decode($m['body']), '<a href="https://exemple.fr/?a=1&amp;b=2">'), true);
$ev = nt_user_create("Mallory\r\nBcc: x@y.fr");
nt_user_set_channel($ev, 'email', 'mallory@example.fr');
$mails = [];
nt_notify('commande', [$ev], 'Bonjour {nom}', 'x');
check('{nom} avec CRLF : objet sur une ligne', str_contains($mails[0]['subject'], "\n"), false);
check('objet accentué encodé en MIME', str_starts_with(nt_email_build(['subject' => 'Été', 'text' => 'x', 'unsubscribe_url' => ''])['subject'], '=?UTF-8?B?'), true);

echo "\nE-mail : client SMTP (faux serveur local)\n";
function smtp_server(string $tmpDir, string $name): ?array
{
    $script = $tmpDir . '/fake-smtp-' . $name . '.php';
    file_put_contents($script, <<<'PHP'
<?php
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
fwrite(STDOUT, stream_socket_get_name($srv, false) . "\n");
fflush(STDOUT);
$c = stream_socket_accept($srv, 10);
$cmds = []; $data = ''; $in = false;
fwrite($c, "220 fake ESMTP\r\n");
while (($line = fgets($c)) !== false) {
    if ($in) {
        if ($line === ".\r\n") { $in = false; fwrite($c, "250 2.0.0 Ok: queued as FAKE123\r\n"); } else { $data .= $line; }
        continue;
    }
    $cmds[] = rtrim($line);
    $v = strtoupper(substr($line, 0, 4));
    if ($v === 'EHLO') fwrite($c, "250-fake\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
    elseif ($v === 'AUTH') fwrite($c, str_contains($line, base64_encode("\0user\0secret")) ? "235 2.7.0 ok\r\n" : "535 5.7.8 bad credentials\r\n");
    elseif ($v === 'MAIL') fwrite($c, "250 ok\r\n");
    elseif ($v === 'RCPT') fwrite($c, "250 ok\r\n");
    elseif ($v === 'DATA') { $in = true; fwrite($c, "354 go\r\n"); }
    elseif ($v === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
    else fwrite($c, "500 ?\r\n");
}
file_put_contents($argv[1], json_encode(['cmds' => $cmds, 'data' => $data]));
PHP);
    $out = $tmpDir . '/smtp-' . $name . '.json';
    $proc = proc_open([PHP_BINARY, $script, $out], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) return null;
    stream_set_timeout($pipes[1], 5);
    $addr = trim((string) fgets($pipes[1]));
    if (!preg_match('/:(\d+)$/', $addr, $m)) return null;
    return ['proc' => $proc, 'pipes' => $pipes, 'port' => (int) $m[1], 'out' => $out];
}

if (function_exists('proc_open')) {
    nt_config_update('email', ['transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => 0, 'smtp_secure' => '', 'smtp_user' => 'user', 'smtp_pass' => 'secret']);

    $srv = smtp_server($tmpDir, 'ok');
    nt_config_update('email', ['smtp_port' => $srv['port']]);
    $mails = [];
    $r = nt_send_email('dest@example.fr', ['subject' => 'Bonjour', 'text' => "Ligne 1\n.Ligne commençant par un point\nFin", 'unsubscribe_url' => '']);
    proc_close($srv['proc']);
    $got = json_decode((string) @file_get_contents($srv['out']), true);
    check('SMTP : message accepté', [$r['ok'], str_starts_with($r['id'], '<')], [true, true]);
    check('SMTP : dialogue complet (EHLO, AUTH, MAIL, RCPT, DATA, QUIT)', array_map(fn($c) => explode(' ', $c)[0], $got['cmds'] ?? []), ['EHLO', 'AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT']);
    check('SMTP : MAIL FROM / RCPT TO', [$got['cmds'][2] ?? '', $got['cmds'][3] ?? ''], ['MAIL FROM:<boutique@brocante.fr>', 'RCPT TO:<dest@example.fr>']);
    check('SMTP : corps transmis (To/Subject présents, mail() non utilisé)', [str_contains($got['data'] ?? '', "To: dest@example.fr\r\n"), str_contains($got['data'] ?? '', "Subject: Bonjour\r\n"), count($mails)], [true, true, 0]);
    check('SMTP : ligne commençant par un point protégée (dot-stuffing)', str_contains(quoted_printable_decode($got['data'] ?? ''), "\r\n..Ligne commençant par un point\r\n"), true);

    $srv = smtp_server($tmpDir, 'bad');
    nt_config_update('email', ['smtp_port' => $srv['port'], 'smtp_pass' => 'mauvais']);
    $r = nt_send_email('dest@example.fr', ['subject' => 'x', 'text' => 'x', 'unsubscribe_url' => '']);
    proc_close($srv['proc']);
    check('SMTP : identifiants refusés (535) = échec définitif', [$r['ok'], $r['permanent']], [false, true]);

    nt_config_update('email', ['smtp_port' => 1]);
    $r = nt_send_email('dest@example.fr', ['subject' => 'x', 'text' => 'x', 'unsubscribe_url' => '']);
    check('SMTP : serveur injoignable = à réessayer', [$r['ok'], $r['permanent']], [false, false]);
    nt_config_update('email', ['transport' => 'mail', 'smtp_pass' => 'secret']);
} else {
    echo "  (proc_open indisponible : test SMTP ignoré)\n";
}

/* ------------------------------------------------------------------ */
echo "\nImport des bénévoles et des clients\n";
bv_boot();
db()->exec("INSERT INTO bv_volunteers (name, phone, email) VALUES ('Léa Petit', '06 55 44 33 22', 'lea@example.fr'), ('Sans contact', '', ''), ('Alice Martin', '', 'alice@example.fr')");
// La table orders vient de schema.sql (stripe_session_id obligatoire et unique).
db()->exec("INSERT INTO orders (stripe_session_id, email, status) VALUES ('cs_t1', 'Client@Example.fr', 'paid'), ('cs_t2', 'client@example.fr', 'paid'), ('cs_t3', 'attente@example.fr', 'pending'), ('cs_t4', '', 'paid')");
$imp = nt_import_volunteers();
check('bénévoles : 1 créé, 1 déjà connu (Alice), 1 sans contact ignoré', [$imp['created'], $imp['existing']], [1, 1]);
$lea = nt_user_find_by_address('email', 'lea@example.fr');
check('bénévole : e-mail et SMS', array_keys(nt_user_get($lea)['channels']), ['email', 'sms']);
check('bénévole : aucun opt-in marketing', nt_user_get($lea)['prefs'], []);
$imp = nt_import_customers();
check('clients : seul le client ayant payé est créé (doublons fusionnés)', [$imp['created'], nt_user_find_by_address('email', 'attente@example.fr')], [1, null]);
check('import idempotent', [nt_import_volunteers()['created'], nt_import_customers()['created']], [0, 0]);

/* ------------------------------------------------------------------ */
echo "\nCommande expédiée\n";
$order = ['id' => 42, 'status' => 'paid', 'email' => 'Claire.Dupont@Example.fr', 'created_at' => '2026-11-03 10:15:00',
    'shipping_address' => 'Claire Dupont, 3 rue des Lilas, 80000 Amiens, FR', 'tracking_number' => '6A12345678901',
    'items' => json_encode([['name' => 'Lampe de chevet', 'qty' => 1], ['name' => 'Vase bleu', 'qty' => 2]])];
check('commande non payée : rien envoyé', nt_notify_order_shipped(['status' => 'pending'] + $order)['status'], 'skipped');
check('sans e-mail : rien envoyé', nt_notify_order_shipped(['email' => ''] + $order)['status'], 'skipped');
$mails = [];
$r = nt_notify_order_shipped($order);
check('client prévenu par e-mail', [$r['status'], $r['message']], ['sent', 'client prévenu (E-mail)']);
check('un utilisateur est créé avec le nom de l\'adresse de livraison', nt_user_get(nt_user_find_by_address('email', 'claire.dupont@example.fr'))['name'], 'Claire Dupont');
check('e-mail : objet', iconv_mime_decode($mails[0]['subject'], 0, 'UTF-8'), 'Votre commande du 03/11/2026 a été expédiée');
$text = quoted_printable_decode($mails[0]['body']);
check('e-mail : prénom, articles et numéro de suivi', [str_contains($text, 'Bonjour Claire,'), str_contains($text, '1 × Lampe de chevet, 2 × Vase bleu'), str_contains($text, 'Numéro de suivi : 6A12345678901')], [true, true, true]);
check('pas de lien de désinscription (message de suivi)', str_contains($mails[0]['headers'], 'List-Unsubscribe'), false);
$mails = [];
check('2e appel pour la même commande : pas de doublon', [nt_notify_order_shipped($order)['status'], count($mails)], ['duplicate', 0]);
$mails = [];
$r = nt_notify_order_shipped(['id' => 43, 'shipping_address' => null, 'tracking_number' => null, 'email' => 'retrait@example.fr'] + $order);
check('retrait (sans adresse) : « est prête », pas « expédiée »', [$r['status'], iconv_mime_decode($mails[0]['subject'], 0, 'UTF-8')], ['sent', 'Votre commande du 03/11/2026 est prête']);
check('nom de repli : début de l\'e-mail', nt_user_get(nt_user_find_by_address('email', 'retrait@example.fr'))['name'], 'Retrait');
$optout = nt_user_find_by_address('email', 'claire.dupont@example.fr');
nt_set_pref($optout, 'commande', 'email', false, 'utilisateur');
$mails = [];
$r = nt_notify_order_shipped(['id' => 44] + $order);
check('client retiré du suivi de commande : respecté, rien envoyé', [$r['status'], count($mails)], ['skipped', 0]);
check('… et la commande n\'est pas marquée notifiée (réessayable)', nt_meta_get('order_shipped_notified:44'), '');
nt_set_pref($optout, 'commande', 'email', true, 'utilisateur');
nt_user_disable_channel($optout, 'email', 'désinscrit');
check('canal e-mail désactivé : respecté', nt_notify_order_shipped(['id' => 45] + $order)['status'], 'skipped');
nt_user_set_channel($optout, 'email', 'claire.dupont@example.fr', true);
$cfgBackup = nt_config()['email']['from_email'];
nt_config_update('email', ['from_email' => '']);
$r = nt_notify_order_shipped(['id' => 46, 'email' => 'nouveau@example.fr'] + $order);
check('e-mail non configuré : pas d\'exception, client non prévenu', [$r['status'], str_contains($r['message'], 'non configuré')], ['skipped', true]);
nt_config_update('email', ['from_email' => $cfgBackup]);

/* ------------------------------------------------------------------ */
echo "\nNavigateur (Web Push)\n";
$b64 = fn($x) => wp_b64u_dec($x);
$uaPub = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$uaAuth = 'BTBZMqHH6r4Tts7J_aSIgg';
// Vecteur de test officiel de la RFC 8291 (annexe A) : le message chiffré doit être identique octet pour octet.
check('chiffrement : vecteur de la RFC 8291', wp_b64u_enc(wp_encrypt(
    'When I grow up, I want to be a watermelon', $b64($uaPub), $b64($uaAuth),
    $b64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $b64('DGv6ra1nlYgDCS1FRnbzlw'),
    $b64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')
)), 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');
check('chiffrement : deux envois ne donnent pas le même corps (sel et clé éphémères)', wp_encrypt('a', $b64($uaPub), $b64($uaAuth)) !== wp_encrypt('a', $b64($uaPub), $b64($uaAuth)), true);
check('chiffrement : clé d\'abonné trop courte refusée', (function () use ($b64, $uaAuth) { try { wp_encrypt('a', 'abc', $b64($uaAuth)); return 'accepté'; } catch (RuntimeException $e) { return 'refusé'; } })(), 'refusé');

$keys = wp_keys();
check('clés VAPID créées : publique 65 octets, privée 32 octets', [strlen($b64($keys['public'])), strlen($b64($keys['private']))], [65, 32]);
check('clés VAPID stockées avec le fichier de configuration, en 0600', [dirname(wp_keys_path()) === dirname(NT_CONFIG_FILE), substr(sprintf('%o', fileperms(wp_keys_path())), -4)], [true, '0600']);
check('clés VAPID : relues telles quelles (jamais régénérées)', wp_keys()['public'], $keys['public']);
$auth = wp_vapid_authorization('https://fcm.googleapis.com/fcm/send/xyz', 'mailto:boutique@brocante.fr', 1900000000);
preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $auth, $m);
$claims = json_decode($b64($m[2]), true);
check('VAPID : audience = origine du service de push, contact et expiration', [$claims['aud'], $claims['sub'], $claims['exp'] - 1900000000], ['https://fcm.googleapis.com', 'mailto:boutique@brocante.fr', 43200]);
$rs = $b64($m[3]);
$derInt = fn($i) => (ord($i[0]) & 0x80 ? "\0" : '') . $i;
$r = $derInt(ltrim(substr($rs, 0, 32), "\0")); $sg = $derInt(ltrim(substr($rs, 32), "\0"));
$der = "\x30" . chr(strlen($r) + strlen($sg) + 4) . "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($sg)) . $sg;
check('VAPID : signature ES256 valide avec la clé publique, au format r||s', [strlen($rs), $m[4] === $keys['public'], openssl_verify($m[1] . '.' . $m[2], $der, openssl_pkey_get_public(wp_ec_public_pem($b64($keys['public']))), OPENSSL_ALGO_SHA256)], [64, true, 1]);

$endpoint = 'https://fcm.googleapis.com/fcm/send/abcDEF123';
$subJson = json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => $uaPub, 'auth' => $uaAuth]]);
$canon = nt_normalize_address('push', $subJson);
check('abonnement valide accepté, forme canonique', json_decode((string) $canon, true), ['endpoint' => $endpoint, 'p256dh' => $uaPub, 'auth' => $uaAuth]);
check('abonnement déjà canonique accepté', nt_normalize_address('push', (string) $canon), $canon);
check('abonnement : endpoint quelconque refusé (SSRF)', nt_normalize_address('push', json_encode(['endpoint' => 'https://evil.example/x', 'keys' => ['p256dh' => $uaPub, 'auth' => $uaAuth]])), null);
check('abonnement : endpoint interne refusé', nt_normalize_address('push', json_encode(['endpoint' => 'https://169.254.169.254/fcm.googleapis.com/', 'keys' => ['p256dh' => $uaPub, 'auth' => $uaAuth]])), null);
check('abonnement : http (non chiffré) refusé', nt_normalize_address('push', json_encode(['endpoint' => 'http://fcm.googleapis.com/fcm/send/a', 'keys' => ['p256dh' => $uaPub, 'auth' => $uaAuth]])), null);
check('abonnement : sous-domaine pirate d\'un service refusé', nt_normalize_address('push', json_encode(['endpoint' => 'https://fcm.googleapis.com.evil.example/a', 'keys' => ['p256dh' => $uaPub, 'auth' => $uaAuth]])), null);
check('abonnement : clé publique invalide refusée', nt_normalize_address('push', json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => 'AAAA', 'auth' => $uaAuth]])), null);
check('abonnement : secret d\'authentification invalide refusé', nt_normalize_address('push', json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => $uaPub, 'auth' => 'court']])), null);
check('abonnement : texte quelconque refusé', nt_normalize_address('push', 'bonjour'), null);

$pushUser = nt_user_create('Hélène Navigateur');
check('l\'utilisateur enregistre son navigateur', nt_user_set_channel($pushUser, 'push', $subJson, true, 0), null);
check('adresse masquée (aucun endpoint affiché)', nt_mask_address('push', (string) $canon), 'ce navigateur');
http_reset();
http_reply(201);
$pm = nt_notify('commande', [$pushUser], 'Votre colis est parti', 'Bonjour {prenom}, votre commande part aujourd\'hui.');
check('push : envoyé', [$pm['processed']['sent'], $pm['processed']['failed']], [1, 0]);
$call = $http['calls'][0];
$hdr = implode("\n", $call['headers']);
check('push : POST vers l\'endpoint du navigateur, corps binaire chiffré', [$call['method'], $call['url'], strlen($call['body']) > 86, str_contains($call['body'], 'colis')], ['POST', $endpoint, true, false]);
check('push : en-têtes VAPID, aes128gcm et durée de vie', [str_contains($hdr, 'Authorization: vapid t='), str_contains($hdr, 'Content-Encoding: aes128gcm'), str_contains($hdr, 'TTL: 86400')], [true, true, true]);
check('push : le corps chiffré commence par le sel, la taille 4096 et la clé de 65 octets', [unpack('N', substr($call['body'], 16, 4))[1], ord($call['body'][20])], [4096, 65]);
$payload = nt_render(['subject' => 'Titre', 'body' => 'Corps', 'short_body' => ''], nt_user_get($pushUser), ['is_marketing' => 1], 'push');
check('push : titre = objet, texte = corps, pas de lien de désinscription collé au texte', [$payload['subject'], $payload['text']], ['Titre', 'Corps']);

http_reset();
http_reply(410);
$pm = nt_notify('commande', [$pushUser], 'Rappel', 'Second message');
check('push : 410 (abonnement retiré par le navigateur) -> canal coupé', [$pm['processed']['failed'], nt_user_get($pushUser)['channels']['push']['enabled']], [1, 0]);
nt_user_set_channel($pushUser, 'push', $subJson, true, 0);
http_reset();
http_reply(503, [], 30);
$pm = nt_notify('commande', [$pushUser], 'Rappel 2', 'Troisième message');
check('push : 503 = erreur temporaire, canal conservé', [$pm['processed']['sent'], nt_user_get($pushUser)['channels']['push']['enabled']], [0, 1]);
nt_user_delete($pushUser);

/* ------------------------------------------------------------------ */
echo "\nSuppression, effacement et purge\n";
$before = (int) db()->query("SELECT COUNT(*) FROM nt_deliveries WHERE user_id = $dave")->fetchColumn();
check('l\'utilisateur a un historique', $before > 0, true);
nt_user_delete($dave);
check('effacement : historique, canaux et préférences supprimés', [
    (int) db()->query("SELECT COUNT(*) FROM nt_deliveries WHERE user_id = $dave")->fetchColumn(),
    (int) db()->query("SELECT COUNT(*) FROM nt_user_channels WHERE user_id = $dave")->fetchColumn(),
], [0, 0]);
check('aucun message orphelin', (int) db()->query('SELECT COUNT(*) FROM nt_messages WHERE id NOT IN (SELECT DISTINCT message_id FROM nt_deliveries)')->fetchColumn(), 0);

nt_set_clock(paris('2027-03-01 10:00'));
$total = (int) db()->query("SELECT COUNT(*) FROM nt_deliveries WHERE status IN ('sent','failed','skipped')")->fetchColumn();
db()->prepare("INSERT INTO nt_deliveries (message_id, user_id, channel, address, status, next_attempt_at, created_at) SELECT message_id, user_id, channel, address, 'queued', 0, ? FROM nt_deliveries LIMIT 1")->execute([nt_fmt(nt_now())]);
check('purge à 90 jours : supprime l\'historique terminé, garde les envois en file ou en cours', [nt_purge(90) > 0, db()->query("SELECT group_concat(status) FROM (SELECT status FROM nt_deliveries ORDER BY status)")->fetchColumn()], [true, 'queued,sending']);
check('statistiques', array_keys(nt_stats()), ['users', 'without_channel', 'pending', 'sent', 'failed', 'by_channel']);

echo "\n";
if ($failures > 0) {
    echo "ÉCHEC : $failures vérification(s) sur $checks.\n";
    exit(1);
}
echo "OK : $checks vérifications passées.\n";
