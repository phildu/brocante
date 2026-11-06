<?php
// Tests de l'alerte de fermeture (includes/closure-alerts.php) : clé de période, message proposé, public estimé sans écriture, envoi par le
// module Notifications (clients, inscrits à la lettre, contacts manuels ; jamais les bénévoles), double envoi refusé, désinscription respectée.
// Base SQLite et configuration jetables ; aucun appel réseau (mail() est remplacée par un double).
//   php scripts/test-closure-alerts.php
// N'écrit jamais dans brocante.db ni dans .secrets/.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmpDir = sys_get_temp_dir() . '/brocante-ca-test-' . getmypid();
mkdir($tmpDir, 0700, true);
putenv('BROCANTE_DB=' . $tmpDir . '/test.db');
putenv('SITE_URL=https://brocante.arrimage.com');
(new PDO('sqlite:' . $tmpDir . '/test.db'))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
define('NT_CONFIG_FILE', $tmpDir . '/cfg/notifications.json');
register_shutdown_function(function () use ($tmpDir) {
    foreach (glob($tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
    foreach (glob($tmpDir . '/cfg/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
    @rmdir($tmpDir . '/cfg');
    @rmdir($tmpDir);
});

require_once __DIR__ . '/../includes/closure-alerts.php';

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

$mails = [];
nt_hook('mail', function ($to, $subject, $body, $headers, $params) use (&$mails) {
    $mails[] = compact('to', 'subject', 'body');
    return true;
});
function mailed_to(): array
{
    global $mails;
    $to = array_column($mails, 'to');
    sort($to);
    return $to;
}
nt_boot();
nt_config_update('email', ['from_email' => 'boutique@brocante.fr', 'from_name' => 'La Brocante', 'transport' => 'mail']);

$today = shop_hours_now();
$d = static fn (string $mod): string => $today->modify($mod)->format('Y-m-d');
$tz = shop_hours_timezone();

// Horaires structurés (lundi-samedi 10 h-12 h / 14 h-18 h, fermé le dimanche) et trois périodes de fermeture.
$days = [];
foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
    $days[$day] = $day === 'sun'
        ? ['closed' => true, 'from' => [], 'to' => []]
        : ['closed' => false, 'from' => ['10:00', '14:00'], 'to' => ['12:00', '18:00']];
}
shop_hours_save_schedule($days);
shop_hours_save_closures([
    ['from' => $d('+10 days'), 'to' => $d('+16 days'), 'type' => 'vacation', 'label' => 'Congés d’automne'],
    ['from' => $d('+3 days'), 'to' => $d('+3 days'), 'type' => 'closure', 'label' => ''],
    ['from' => $d('-20 days'), 'to' => $d('-18 days'), 'type' => 'holiday', 'label' => 'Pont passé'],
]);
$closures = shop_hours_closures();   // triées par date : passée, +3 j, +10 j
[$past, $oneDay, $vacation] = $closures;

/* ------------------------------------------------------------------ */
echo "Identité d'une période\n";
check('clé de 12 caractères hexadécimaux', (bool) preg_match('/^[a-f0-9]{12}$/', closure_alert_key($vacation)), true);
check('clé stable', closure_alert_key($vacation), closure_alert_key($vacation));
check('la clé ne dépend pas du motif', closure_alert_key($vacation), closure_alert_key(['label' => 'Autre motif', 'type' => 'closure'] + $vacation));
check('une autre période a une autre clé', closure_alert_key($vacation) !== closure_alert_key($oneDay), true);
check('retrouvée par sa clé', closure_alert_find(closure_alert_key($oneDay))['from'], $d('+3 days'));
check('clé inconnue : null', closure_alert_find('000000000000'), null);
check('période à venir', closure_alert_is_upcoming($vacation), true);
check('période en cours (dernier jour = aujourd’hui)', closure_alert_is_upcoming(['from' => $d('-2 days'), 'to' => $d('+0 days')]), true);
check('période passée : plus d’alerte', closure_alert_is_upcoming($past), false);

echo "\nMessage proposé\n";
$m = closure_alert_message($vacation);
check('objet : type de fermeture + site', $m['subject'], 'Congés — {site}');
check('le motif est repris', str_contains($m['body'], '(Congés d’automne)'), true);
check('la période est en toutes lettres', str_contains($m['body'], 'du ' . shop_hours_date_label(new DateTimeImmutable($d('+10 days') . ' 00:00', $tz)) . ' au '), true);
check('la réouverture est annoncée', str_contains($m['body'], 'réouverture'), true);
check('variables du module présentes', str_contains($m['body'], '{prenom}') && str_contains($m['body'], '{lien_preferences}'), true);
check('version courte pour SMS', str_contains($m['short_body'], '{site} : fermé') && !str_contains($m['short_body'], 'Bonjour'), true);
$one = closure_alert_message($oneDay);
check('un seul jour : « le … »', str_contains($one['body'], 'fermé le ' . shop_hours_date_label(new DateTimeImmutable($d('+3 days') . ' 00:00', $tz))), true);
check('sans motif : pas de parenthèses vides', str_contains($one['body'], '()'), false);
check('objet d’une fermeture exceptionnelle', $one['subject'], 'Fermeture exceptionnelle — {site}');
$sunday = closure_alert_message(['from' => $d('+1 days'), 'to' => $d('+1 days'), 'type' => 'closure', 'label' => '']);
check('message toujours rédigé (jamais d’erreur)', str_contains($sunday['body'], '{site} sera fermé'), true);

echo "\nNouvelles périodes à proposer après un enregistrement\n";
$pending = closure_alert_new_pending([]);
check('deux périodes à venir sont à annoncer', count($pending), 2);
check('la période passée n’est jamais proposée', isset($pending[closure_alert_key($past)]), false);
check('une période déjà connue n’est pas reproposée', array_keys(closure_alert_new_pending([closure_alert_key($oneDay)])), [closure_alert_key($vacation)]);

echo "\nPublic estimé (sans rien écrire)\n";
$pdo = db();
$order = $pdo->prepare("INSERT INTO orders (stripe_session_id, email, status) VALUES (?, ?, ?)");
$order->execute(['cs_1', 'Alice@Example.fr', 'paid']);
$order->execute(['cs_2', 'bob@example.fr', 'paid']);
$order->execute(['cs_3', 'impayee@example.fr', 'pending']);
$acc = $pdo->prepare("INSERT INTO accounts (role, name, email, source) VALUES (?, ?, ?, ?)");
$acc->execute(['prospect', 'Chloé', 'chloe@example.fr', 'newsletter']);
$acc->execute(['prospect', 'Visiteur du formulaire', 'contact@example.fr', 'connexion']);
$acc->execute(['prospect', 'Alice déjà cliente', 'alice@example.fr', 'newsletter']);
$manual = nt_user_create('Manon Manuelle');
nt_user_set_channel($manual, 'email', 'manon@example.fr');
$vol = nt_user_create('Victor Bénévole', '', 'benevole', '9');
nt_user_set_channel($vol, 'email', 'victor@example.fr');
$before = (int) $pdo->query('SELECT COUNT(*) FROM nt_users')->fetchColumn();
$aud = closure_alert_audience();
check('clients à créer : les deux commandes payées', $aud['commande']['to_import'], 2);
check('inscrits à créer : Chloé seule (Alice est déjà dans les clients, le formulaire n’est pas la lettre)', $aud['newsletter']['to_import'], 1);
check('contacts manuels existants : 1', $aud['manuel']['existing'], 1);
check('les bénévoles ne sont dans aucun public', array_sum(array_column($aud, 'existing')), 1);
check('l’estimation n’écrit rien', (int) $pdo->query('SELECT COUNT(*) FROM nt_users')->fetchColumn(), $before);

echo "\nEnvoi\n";
$key = closure_alert_key($vacation);
$msg = closure_alert_message($vacation);
check('aucun public choisi : refusé', closure_alert_send($key, [], $msg['subject'], $msg['body'], $msg['short_body'])['error'], 'Choisissez au moins un public à prévenir.');
check('public inconnu ignoré : refusé', is_string(closure_alert_send($key, ['benevole'], $msg['subject'], $msg['body'], $msg['short_body'])['error']), true);
check('message vide refusé', closure_alert_send($key, ['commande'], 'S', '  ', '')['error'], 'Le message est vide.');
check('période inconnue refusée', is_string(closure_alert_send('000000000000', ['commande'], 'S', 'B', '')['error']), true);
check('période passée refusée', is_string(closure_alert_send(closure_alert_key($past), ['commande'], 'S', 'B', '')['error']), true);
check('rien n’est parti à ce stade', $mails, []);
$r = closure_alert_send($key, ['commande', 'newsletter', 'manuel'], $msg['subject'], $msg['body'], $msg['short_body'], 'orga');
check('envoi sans erreur', $r['error'], null);
check('3 contacts créés (2 clients + 1 inscrit)', $r['imported'], 3);
check('4 personnes prévenues (Alice, Bob, Chloé, Manon)', $r['recipients'], 4);
check('uniquement ces adresses', mailed_to(), ['alice@example.fr', 'bob@example.fr', 'chloe@example.fr', 'manon@example.fr']);
check('le bénévole n’est pas prévenu', in_array('victor@example.fr', mailed_to(), true), false);
check('le contact du formulaire n’est pas prévenu', in_array('contact@example.fr', mailed_to(), true), false);
check('la commande impayée n’est pas prévenue', in_array('impayee@example.fr', mailed_to(), true), false);
$alice = current(array_filter($mails, static fn ($x) => $x['to'] === 'alice@example.fr'));
$aliceSubject = iconv_mime_decode($alice['subject'], 0, 'UTF-8');
$aliceBody = quoted_printable_decode($alice['body']);
check('objet : type de fermeture et nom du site', $aliceSubject, 'Congés — La Brocante');
check('prénom inséré', str_contains($aliceBody, 'Bonjour Alice,'), true);
check('lien de préférences présent', str_contains($aliceBody, 'https://brocante.arrimage.com/notifications/preferences.php?t='), true);
check('aucune variable non remplacée', preg_match('/\{(prenom|site|lien_preferences)\}/', $aliceBody), 0);
check('rubrique « fermeture » créée, non marketing', nt_topics()[CLOSURE_ALERT_TOPIC]['is_marketing'], 0);
$log = closure_alert_log();
check('alerte consignée', [$log[$key]['recipients'], $log[$key]['by']], [4, 'orga']);
check('les périodes déjà annoncées ne sont plus proposées', isset(closure_alert_new_pending([])[$key]), false);
check('l’autre période reste à annoncer', isset(closure_alert_new_pending([])[closure_alert_key($oneDay)]), true);

echo "\nDouble envoi et renvoi\n";
$mails = [];
$again = closure_alert_send($key, ['commande'], $msg['subject'], $msg['body'], $msg['short_body']);
check('second envoi refusé', str_contains((string) $again['error'], 'déjà été prévenus le ' . date('d/m/Y')), true);
check('…et rien n’est parti', $mails, []);
$forced = closure_alert_send($key, ['commande'], $msg['subject'], $msg['body'], $msg['short_body'], 'orga', true);
check('renvoi volontaire accepté', $forced['error'], null);
check('…aux seuls clients (2)', mailed_to(), ['alice@example.fr', 'bob@example.fr']);
check('…sans recréer de contacts', $forced['imported'], 0);

echo "\nRespect des préférences\n";
$bob = nt_user_find_by_address('email', 'bob@example.fr');
check('Bob se retire des fermetures', nt_set_pref($bob, CLOSURE_ALERT_TOPIC, 'email', false, 'utilisateur'), true);
$mails = [];
$second = closure_alert_send(closure_alert_key($oneDay), ['commande'], 'Fermeture — {site}', 'Bonjour {prenom}, fermé demain.', '', 'orga');
check('envoi pour l’autre période', $second['error'], null);
check('Bob n’est pas prévenu, Alice oui', mailed_to(), ['alice@example.fr']);
$stmt = $pdo->prepare('UPDATE nt_user_channels SET enabled = 0 WHERE user_id = ?');
$stmt->execute([nt_user_find_by_address('email', 'alice@example.fr')]);
$mails = [];
$third = closure_alert_send($key, ['commande'], 'S', 'B {prenom}', '', 'orga', true);
check('plus personne à prévenir : message explicite, rien de consigné de plus', [is_string($third['error']), $mails], [true, []]);

echo "\n" . ($failures === 0 ? "OK : $checks contrôles réussis.\n" : "ÉCHEC : $failures contrôle(s) sur $checks.\n");
exit($failures === 0 ? 0 : 1);
