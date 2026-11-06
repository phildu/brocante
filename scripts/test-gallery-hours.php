<?php

// Test des horaires et de l'alerte de fermeture d'une galerie (includes/gallery-hours.php) : isolation vis-à-vis des horaires de la boutique,
// bandeau public, public de l'alerte (abonnés confirmés, membres fidélité, exposants attribués), pied de page propre à chaque groupe,
// double envoi refusé, désinscription des membres. Usage : php scripts/test-gallery-hours.php
// N'écrit que dans un dossier temporaire (et le journal d'e-mails local, remis en l'état).

$tmp = sys_get_temp_dir() . '/brocante-test-gh-' . getmypid();
mkdir($tmp, 0777, true);
putenv('BROCANTE_GALLERIES_DIR=' . $tmp);
putenv('BROCANTE_GM_FILE=' . $tmp . '/modules-absent.json');
putenv('BROCANTE_DB=' . $tmp . '/boutique.db');
$dataDir = __DIR__ . '/../data';
$mailLog = $dataDir . '/mail-outbox.log';
$dataDirExisted = is_dir($dataDir);
if (!$dataDirExisted) mkdir($dataDir, 0755);   // saas_mail() n'écrit son journal local que si le dossier existe
$mailLogExisted = is_file($mailLog);
$mailLogSize = $mailLogExisted ? filesize($mailLog) : 0;
register_shutdown_function(function () use ($tmp, $dataDir, $dataDirExisted, $mailLog, $mailLogExisted, $mailLogSize) {
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
    if (!$mailLogExisted) @unlink($mailLog);
    elseif (is_file($mailLog)) { $h = fopen($mailLog, 'r+'); ftruncate($h, $mailLogSize); fclose($h); }
    if (!$dataDirExisted) @rmdir($dataDir);
});
$_SERVER['HTTP_HOST'] = 'galeries.test';   // hôte local : les e-mails vont au journal, rien n'est envoyé
define('DB_PATH', $tmp . '/boutique.db');
(new PDO('sqlite:' . DB_PATH))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
require_once __DIR__ . '/../includes/gallery-hours.php';
$_SESSION = [];

$checks = 0;
$failures = 0;
function check(string $label, $actual, $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual === $expected) { echo "  ✓ $label\n"; return; }
    $failures++;
    echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
}
/** E-mails écrits dans le journal local depuis le début du test : [['to' => …, 'subject' => …, 'body' => …]]. */
function mails_since_start(): array
{
    global $mailLog, $mailLogSize;
    $raw = is_file($mailLog) ? (string) substr((string) file_get_contents($mailLog), $mailLogSize) : '';
    $out = [];
    foreach (array_filter(explode("----\n", $raw), static fn ($b) => trim($b) !== '') as $block) {
        if (preg_match('/À : (\S+)\nObjet : ([^\n]*)\n(.*)$/s', $block, $m)) $out[] = ['to' => $m[1], 'subject' => $m[2], 'body' => $m[3]];
    }
    return $out;
}
function to_list(array $mails): array
{
    $to = array_column($mails, 'to');
    sort($to);
    return $to;
}

$today = shop_hours_now();
$d = static fn (string $mod): string => $today->modify($mod)->format('Y-m-d');
$grid = [];
foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
    $grid[$day] = $day === 'sun' ? ['closed' => true, 'from' => [], 'to' => []] : ['closed' => false, 'from' => ['09:00'], 'to' => ['18:00']];
}

$g = gallery_normalize('gal-a', ['name' => 'Galerie des Halles', 'type' => 'vide-grenier', 'published' => true, 'members' => [],
    'admins' => [['email' => 'orga@example.test', 'name' => 'Orga', 'hash' => 'x', 'created' => 1]],
    'event' => ['title' => 'Vide-grenier', 'date' => $d('+30 days'), 'mode' => 'places', 'places_count' => 10, 'price_cents' => 2000, 'published' => true]]);
gallery_save($g);
$g = gallery_load('gal-a');

echo "Fonctionnalité « horaires » des types de galerie\n";
foreach (array_keys(gt_defaults()) as $typeKey) {
    check("proposée par défaut au type « $typeKey »", gt_feature(['type' => $typeKey, 'slug' => 'x'], 'horaires'), true);
}
check('listée parmi les fonctionnalités', isset(GT_FEATURES['horaires']), true);
check('galerie de test : activée', gh_enabled($g), true);

echo "\nDonnées et normalisation\n";
check('rien d\'enregistré : pas de grille', gh_read('gal-a')['schedule'], null);
check('grille proposée : lundi-samedi, dimanche fermé', [gh_editor_schedule($g)['mon']['closed'], gh_editor_schedule($g)['sun']['closed']], [false, true]);
check('rien d\'enregistré : bloc public vide', gh_public_html($g), '');
check('enregistrement', gh_save('gal-a', $grid, [
    ['from' => $d('+10 days'), 'to' => $d('+16 days'), 'type' => 'vacation', 'label' => 'Congés d’automne'],
    ['from' => $d('+3 days'), 'to' => '', 'type' => 'closure', 'label' => ''],
    ['from' => $d('-20 days'), 'to' => $d('-18 days'), 'type' => 'holiday', 'label' => 'Pont passé'],
    ['from' => 'pas-une-date', 'to' => '', 'type' => 'closure', 'label' => 'ignorée'],
    ['from' => $d('+5 days'), 'to' => $d('+4 days'), 'type' => 'closure', 'label' => 'fin avant début : ignorée'],
], '  Parking fermé  '), true);
$data = gh_read('gal-a');
check('dimanche fermé', $data['schedule']['sun']['closed'], true);
check('lundi 9 h – 18 h', $data['schedule']['mon']['ranges'], [['from' => '09:00', 'to' => '18:00']]);
check('trois fermetures valides, triées', array_column($data['closures'], 'type'), ['holiday', 'closure', 'vacation']);
check('fermeture sans « Au » : un seul jour', $data['closures'][1]['to'], $data['closures'][1]['from']);
check('message libre nettoyé', $data['notice'], 'Parking fermé');
check('fichier dédié à la galerie', is_file($tmp . '/gal-a.hours.json'), true);
file_put_contents($tmp . '/gal-z.hours.json', '{"schedule":"n\'importe quoi","closures":"x","alerts":7}');
check('JSON corrompu : valeurs sûres', gh_read('gal-z'), ['schedule' => null, 'closures' => [], 'notice' => '', 'alerts' => []]);

echo "\nIsolation vis-à-vis des horaires de la boutique\n";
check('avant : la boutique n\'a pas d\'horaires', shop_hours_schedule(), null);
$inside = gh_with($g, static fn () => shop_hours_summary_text());
check('dans le calcul : horaires de la galerie', $inside, 'Lun–Sam : 9 h – 18 h · Dim : fermé');
check('après : la boutique n\'a toujours pas d\'horaires', [shop_hours_schedule(), shop_hours_source()], [null, null]);
check('résumé boutique vide', shop_hours_summary_text(), '');
try {
    gh_with($g, static function () { throw new RuntimeException('boum'); });
} catch (RuntimeException $e) {
}
check('une erreur dans le calcul rend aussi la main', shop_hours_source(), null);
check('calculs imbriqués : la source précédente est rétablie', gh_with($g, static fn () => gh_with($g, static fn () => 1) + (shop_hours_source() !== null ? 1 : 0)), 2);
$g2 = gallery_normalize('gal-b', ['name' => 'Autre galerie', 'type' => 'puces', 'members' => []]);
gallery_save($g2);
check('une autre galerie ne voit pas ces horaires', gh_with($g2, static fn () => shop_hours_summary_text()), '');

echo "\nBandeau et horaires publics\n";
$html = gh_public_html($g);
check('horaires affichés', str_contains($html, 'Lun–Sam : 9 h – 18 h'), true);
check('message libre affiché (il remplace le message automatique)', str_contains($html, 'Parking fermé'), true);
gh_save('gal-a', $grid, gh_read('gal-a')['closures'], '');
$html = gh_public_html($g);
check('fermeture à venir annoncée (dans les 7 jours)', str_contains($html, 'À venir'), true);
gh_save('gal-a', $grid, array_merge(gh_read('gal-a')['closures'], [['from' => $d('+0 days'), 'to' => $d('+1 days'), 'type' => 'closure', 'label' => 'Problème de chauffage']]), '');
$html = gh_public_html($g);
check('fermeture en cours : avertissement', str_contains($html, 'Fermeture&nbsp;: ') && str_contains($html, 'Problème de chauffage'), true);
check('…avec la réouverture', str_contains($html, 'Réouverture'), true);
check('…sans répéter le motif dans la ligne d\'horaires', substr_count($html, 'Problème de chauffage'), 1);
check('texte échappé', gh_with($g, static function () { return true; }) && !str_contains($html, '<script'), true);
gh_save('gal-a', $grid, [['from' => $d('+0 days'), 'to' => $d('+0 days'), 'type' => 'closure', 'label' => '<script>alert(1)</script>']], '');
check('motif hostile échappé', str_contains(gh_public_html($g), '&lt;script&gt;') && !str_contains(gh_public_html($g), '<script>'), true);
gh_save('gal-a', $grid, [
    ['from' => $d('+10 days'), 'to' => $d('+16 days'), 'type' => 'vacation', 'label' => 'Congés d’automne'],
    ['from' => $d('+3 days'), 'to' => $d('+3 days'), 'type' => 'closure', 'label' => ''],
    ['from' => $d('-20 days'), 'to' => $d('-18 days'), 'type' => 'holiday', 'label' => 'Pont passé'],
], '');
check('fermetures à venir (la passée est écartée)', count(gh_upcoming_closures($g)), 2);
check('la plus proche d\'abord', gh_upcoming_closures($g)[0]['from'], $d('+3 days'));

echo "\nMessage proposé\n";
[$past, $one, $vac] = gh_read('gal-a')['closures'];
$msg = gh_alert_message($g, $vac);
check('objet : type + nom de la galerie', $msg['subject'], 'Congés — Galerie des Halles');
check('pas de pied de page prérédigé (ajouté à l\'envoi)', str_contains($msg['body'], 'Pour gérer vos notifications'), false);
check('la période et le motif sont repris', str_contains($msg['body'], 'sera fermé du') && str_contains($msg['body'], 'Congés d’automne'), true);
check('réouverture calculée avec les horaires de la galerie (9 h)', str_contains($msg['body'], 'à 9 h.'), true);
check('variable du prénom conservée pour l\'envoi', str_contains($msg['body'], 'Bonjour {prenom},'), true);
check('nom de la galerie déjà dans le texte', str_contains($msg['body'], 'Galerie des Halles sera fermé') && !str_contains($msg['body'], '{site}'), true);

echo "\nPublic de l'alerte\n";
$ga = static function (string $email, bool $confirmed): void { global $tmp; };
ga_write('gal-a', 'subs', [
    ['id' => 'aaaaaaaaaaaa', 'email' => 'abo1@example.test', 'confirmed' => 1, 'created' => 1, 'last_sent' => 0],
    ['id' => 'bbbbbbbbbbbb', 'email' => 'abo2@example.test', 'confirmed' => 0, 'created' => 1, 'last_sent' => 0],   // jamais confirmé
    ['id' => 'cccccccccccc', 'email' => 'commun@example.test', 'confirmed' => 1, 'created' => 1, 'last_sent' => 0],
    ['id' => 'dddddddddddd', 'email' => 'pas-un-mail', 'confirmed' => 1, 'created' => 1, 'last_sent' => 0],
]);
$tokJean = str_repeat('a1', 16);
$tokAnon = str_repeat('b2', 16);
$tokOff = str_repeat('c3', 16);
gl_update('gal-a', static function (array &$dd) use ($tokJean, $tokAnon, $tokOff) {
    $base = ['points' => 5, 'earned' => 5, 'created' => 1, 'scans' => [], 'day' => ['d' => '', 'n' => 0]];
    $dd['members']['jean@example.test'] = ['email' => 'jean@example.test', 'name' => 'Jean', 'token' => $tokJean] + $base;
    $dd['members']['anon@example.test'] = ['email' => 'anon@example.test', 'name' => '', 'token' => $tokAnon] + $base;
    $dd['members']['parti@example.test'] = ['email' => 'parti@example.test', 'name' => 'Parti', 'token' => $tokOff, 'alerts_off' => 1] + $base;
    $dd['members']['commun@example.test'] = ['email' => 'commun@example.test', 'name' => 'Commun', 'token' => str_repeat('d4', 16)] + $base;
});
gb_update('gal-a', static function (array &$dd) {
    $row = static fn (string $status, string $name, string $email) => ['status' => $status, 'name' => $name, 'email' => $email, 'phone' => '0600000000', 'price_cents' => 2000,
        'token' => md5($name), 'kind' => 'particulier', 'requested' => time() - 3600];
    $dd['slots']['ev1'] = $row('taken', 'Alice Martin', 'alice@example.test');
    $dd['slots']['ev2'] = $row('pending', 'Denis Attente', 'denis@example.test');
    $dd['slots']['ev3'] = $row('taken', 'Commun Exposant', 'commun@example.test');
    $dd['slots']['ev4'] = $row('taken', 'Sans Mail', 'invalide');
});
$aud = gh_audience($g);
check('abonnés confirmés : 2 adresses valides (le non confirmé et l\'invalide sont écartés)', $aud['groups']['subs']['count'], 2);
check('membres : 3 (celui qui s\'est retiré est écarté)', $aud['groups']['members']['count'], 3);
check('exposants attribués : 2 (l\'attente et l\'invalide sont écartés)', $aud['groups']['exposants']['count'], 2);
check('adresses distinctes : « commun » compté une fois', $aud['unique'], 2 + 3 + 2 - 2);
$r = gh_recipients($g, ['subs', 'members', 'exposants']);
check('l\'adresse commune garde le lien de désinscription des abonnés', [$r['commun@example.test']['group'], str_contains($r['commun@example.test']['optout'], 'k=sub')], ['subs', true]);
check('le non confirmé n\'est pas destinataire', isset($r['abo2@example.test']), false);
check('le membre retiré n\'est pas destinataire', isset($r['parti@example.test']), false);
check('l\'exposant en attente n\'est pas destinataire', isset($r['denis@example.test']), false);
check('l\'exposant a le lien de son espace, sans désinscription', [$r['alice@example.test']['optout'], str_contains($r['alice@example.test']['link'], '/galerie/exposant.php?g=gal-a&t=')], ['', true]);
check('un seul groupe à la fois', array_keys(gh_recipients($g, ['exposants'])), ['alice@example.test', 'commun@example.test']);

echo "\nEnvoi\n";
$key = closure_alert_key($vac);
$subject = $msg['subject'];
check('public vide refusé', gh_alert_send($g, $key, [], $subject, $msg['body'])['error'], 'Choisissez au moins un public à prévenir.');
check('groupe inconnu refusé', is_string(gh_alert_send($g, $key, ['benevoles'], $subject, $msg['body'])['error']), true);
check('message trop court refusé', gh_alert_send($g, $key, ['subs'], $subject, 'Court')['error'], 'Rédigez un objet et un message.');
check('période inconnue refusée', is_string(gh_alert_send($g, '000000000000', ['subs'], $subject, $msg['body'])['error']), true);
check('période passée refusée', is_string(gh_alert_send($g, closure_alert_key($past), ['subs'], $subject, $msg['body'])['error']), true);
check('rien n\'est parti', mails_since_start(), []);
$res = gh_alert_send($g, $key, ['subs', 'members', 'exposants'], $subject, $msg['body'], 'orga');
check('envoi sans erreur', $res['error'], null);
check('5 adresses, 5 messages', [$res['recipients'], $res['sent'], $res['failed'], $res['skipped']], [5, 5, 0, 0]);
$mails = mails_since_start();
check('uniquement les bonnes adresses', to_list($mails), ['abo1@example.test', 'alice@example.test', 'anon@example.test', 'commun@example.test', 'jean@example.test']);
$by = array_column($mails, null, 'to');
check('objet', $by['abo1@example.test']['subject'], 'Congés — Galerie des Halles');
check('abonné : « Bonjour, » sans nom', str_contains($by['abo1@example.test']['body'], 'Bonjour,') && !str_contains($by['abo1@example.test']['body'], '{prenom}'), true);
check('abonné : lien de désinscription signé', (bool) preg_match('#Se désinscrire de ces alertes : http\S*/galerie/contact\.php\?a=stop&g=gal-a&k=sub&i=aaaaaaaaaaaa&t=[a-f0-9]{32}#', $by['abo1@example.test']['body']), true);
check('membre : prénom inséré', str_contains($by['jean@example.test']['body'], 'Bonjour Jean,'), true);
check('membre : désinscription « member »', (bool) preg_match('#k=member&i=' . $tokJean . '&t=[a-f0-9]{32}#', $by['jean@example.test']['body']), true);
check('membre sans nom : « Bonjour, »', str_contains($by['anon@example.test']['body'], 'Bonjour,'), true);
check('exposant : espace exposant, pas de désinscription', str_contains($by['alice@example.test']['body'], 'Votre espace exposant : ') && !str_contains($by['alice@example.test']['body'], 'Se désinscrire'), true);
check('exposant : nom complet', str_contains($by['alice@example.test']['body'], 'Bonjour Alice Martin,'), true);
check('le nom de la galerie et son adresse sont en pied de page', str_contains($by['abo1@example.test']['body'], '« Galerie des Halles » : http'), true);
check('aucun message ne garde une variable', array_sum(array_map(static fn ($m) => preg_match('/\{(prenom|nom|site|lien_preferences)\}/', $m['body']), $mails)), 0);
$alert = gh_read('gal-a')['alerts'][$key];
check('alerte consignée', [$alert['sent'], $alert['by'], $alert['groups']], [5, 'orga', ['subs', 'members', 'exposants']]);
check('journal d\'audit', in_array('closure_alert', array_column(gb_audit_read('gal-a', 50), 'action'), true), true);
check('libellé du journal', GB_AUDIT_LABELS['closure_alert'], 'Alerte de fermeture envoyée aux visiteurs');
check('les périodes déjà annoncées ne sont plus à proposer', isset(gh_new_pending('gal-a', [])[$key]), false);
check('l\'autre période reste à proposer', isset(gh_new_pending('gal-a', [])[closure_alert_key($one)]), true);
check('une période déjà connue n\'est pas reproposée', gh_new_pending('gal-a', [closure_alert_key($one)]), []);
check('horaires et fermetures survivent à l\'envoi', [count(gh_read('gal-a')['closures']), gh_read('gal-a')['schedule']['mon']['closed']], [3, false]);

echo "\nDouble envoi\n";
$sizeBefore = count(mails_since_start());
$again = gh_alert_send($g, $key, ['subs'], $subject, $msg['body']);
check('second envoi refusé', str_contains((string) $again['error'], 'déjà été prévenus le ' . date('d/m/Y')), true);
check('…aucun e-mail de plus', count(mails_since_start()), $sizeBefore);
$forced = gh_alert_send($g, $key, ['subs'], $subject, $msg['body'], 'orga', true);
check('renvoi volontaire', [$forced['error'], $forced['sent']], [null, 2]);

echo "\nDésinscription d'un membre\n";
$sig = ga_token('gal-a', 'member', $tokJean);
check('jeton invalide refusé', ga_stop('gal-a', 'member', $tokJean, str_repeat('0', 32)), false);
check('membre inconnu refusé', ga_stop('gal-a', 'member', str_repeat('e5', 16), ga_token('gal-a', 'member', str_repeat('e5', 16))), false);
check('type inconnu refusé', ga_stop('gal-a', 'visiteur', $tokJean, $sig), false);
check('désinscription du membre', ga_stop('gal-a', 'member', $tokJean, $sig), true);
check('il n\'est plus destinataire', isset(gh_recipients($g, ['members'])['jean@example.test']), false);
check('son compte et ses points restent', gl_read('gal-a')['members']['jean@example.test']['points'], 5);
check('les autres membres restent destinataires', isset(gh_recipients($g, ['members'])['anon@example.test']), true);
check('la désinscription d\'un abonné fonctionne toujours', ga_stop('gal-a', 'sub', 'aaaaaaaaaaaa', ga_token('gal-a', 'sub', 'aaaaaaaaaaaa')), true);
check('…et il n\'est plus destinataire', isset(gh_recipients($g, ['subs'])['abo1@example.test']), false);

echo "\nVolume\n";
$many = [];
for ($i = 1; $i <= GH_MAX_RECIPIENTS + 3; $i++) $many[] = ['id' => sprintf('%012x', $i), 'email' => "v$i@example.test", 'confirmed' => 1, 'created' => 1, 'last_sent' => 0];
ga_write('gal-a', 'subs', $many);
gh_save('gal-a', $grid, array_merge(gh_read('gal-a')['closures'], []), '');
$big = gh_alert_send($g, closure_alert_key($one), ['subs'], 'Fermé demain', 'Bonjour {prenom}, nous fermons demain.', 'orga');
check('500 destinataires au plus par envoi', [$big['error'], $big['sent'], $big['skipped']], [null, GH_MAX_RECIPIENTS, 3]);

echo "\nGalerie supprimée\n";
check('suppression', gallery_delete('gal-b'), true);
check('autre galerie : ses horaires sont intacts', is_file($tmp . '/gal-a.hours.json'), true);
gh_save('gal-b', $grid, [], '');
gallery_save($g2);
check('les horaires disparaissent avec la galerie', [gallery_delete('gal-b'), is_file($tmp . '/gal-b.hours.json')], [true, false]);

echo "\n" . ($failures === 0 ? "OK : $checks contrôles réussis.\n" : "ÉCHEC : $failures contrôle(s) sur $checks.\n");
exit($failures === 0 ? 0 : 1);
