<?php

// Test du comptage de foule comme extension de galerie : base propre à chaque galerie, réécriture des adresses, accès bénévole et responsable,
// disponibilité (gm_*). Usage : php scripts/test-comptage-galerie.php — n'écrit que dans un dossier temporaire.

$tmp = sys_get_temp_dir() . '/brocante-test-cpg-' . getmypid();
mkdir($tmp, 0777, true);
putenv('BROCANTE_GALLERIES_DIR=' . $tmp);
putenv('BROCANTE_GM_FILE=' . $tmp . '/modules-absent.json');   // réglages du portail : valeurs d'origine
putenv('BROCANTE_DB=' . $tmp . '/boutique.db');
register_shutdown_function(function () use ($tmp) { foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f); @rmdir($tmp); });
$_SERVER['HTTP_HOST'] = 'galeries.test';
define('DB_PATH', $tmp . '/boutique.db');
(new PDO('sqlite:' . DB_PATH))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
require_once __DIR__ . '/../includes/comptage.php';
require_once __DIR__ . '/../includes/gallery-admin.php';
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

echo "Hors galerie\n";
check('aucun contexte', cp_gallery_context(), null);
check('adresses inchangées', cp_url_map('<a href="/admin/comptage/acces.php">'), '<a href="/admin/comptage/acces.php">');

echo "\nDisponibilité (gm_*)\n";
$vg = gallery_normalize('vg-a', ['name' => 'Vide-grenier A', 'type' => 'vide-grenier', 'members' => []]);
$mar = gallery_normalize('mar-b', ['name' => 'Galerie B', 'type' => 'marchande', 'members' => []]);
check('proposée d\'office aux vide-greniers (option payante)', gm_state($vg, 'comptage')['mode'], 'option');
check('non proposée aux autres types', gm_state($mar, 'comptage')['mode'], 'off');
check('inactive tant qu\'elle n\'est ni essayée ni souscrite', gm_enabled($vg, 'comptage'), false);
check('essai gratuit démarré', gm_start_trial($vg, 'comptage'), null);
check('active pendant l\'essai', gm_enabled($vg, 'comptage'), true);
check('essai : 14 jours', round((gm_state($vg, 'comptage')['until'] - time()) / 86400), 14.0);
check('refus de l\'essai pour un type qui ne la propose pas', is_string(gm_start_trial($mar, 'comptage')), true);
check('indépendante de la buvette et des invendus', gm_enabled($vg, 'buvette') || gm_enabled($vg, 'invendus'), false);

echo "\nBase propre à chaque galerie\n";
gallery_save($vg);
gallery_save($mar);
cp_enter_gallery($vg, true);
check('contexte : galerie A', cp_gallery_context()['slug'], 'vg-a');
cp_boot();
cp_event_create('Brocante A', '2026-09-20', 'Place', 500, true);
check('l\'événement est dans la base de A', count(cp_events_list()), 1);
check('fichier de la base de A', is_file($tmp . '/vg-a.db'), true);
$GLOBALS['db_pdo_override'] = gallery_sql('mar-b');
cp_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => true, 'map' => true]);
check('la base de B ne contient pas encore le schéma', (int) gallery_sql('mar-b')->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'cp_events'")->fetchColumn(), 0);
check('nom affiché : celui de la galerie', cp_site_name(), 'Galerie B');
check('la base de la boutique n\'est pas touchée', (int) (new PDO('sqlite:' . DB_PATH))->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'cp_%'")->fetchColumn(), 0);
check('A garde son événement', (int) gallery_sql('vg-a')->query('SELECT COUNT(*) FROM cp_events')->fetchColumn(), 1);

echo "\nRéécriture des adresses (administrateur de la galerie)\n";
cp_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true, 'map' => true]);
check('résultats', cp_url_map('/admin/comptage/index.php'), '/portail/galerie-comptage.php?s=resultats');
check('page + paramètre', cp_url_map('/admin/comptage/index.php?event=3&bucket=30'), '/portail/galerie-comptage.php?s=resultats&event=3&bucket=30');
check('événements + ancre', cp_url_map('/admin/comptage/evenements.php#evt-2'), '/portail/galerie-comptage.php?s=evenements#evt-2');
check('export', cp_url_map('/admin/comptage/export.php?event=1&what=taps'), '/portail/galerie-comptage.php?s=export&event=1&what=taps');
check('accès + QR', cp_url_map('/admin/comptage/acces.php?qr=svg'), '/portail/galerie-comptage.php?s=acces&qr=svg');
check('dans un lien HTML', cp_url_map('<a href="/admin/comptage/acces.php">x</a>'), '<a href="/portail/galerie-comptage.php?s=acces">x</a>');
check('compteur des bénévoles', cp_url_map('/comptage/index.php'), '/galerie/vg-a/comptage/index.php');
check('API', cp_url_map('/comptage/api.php'), '/galerie/vg-a/comptage/api.php');
check('racine', cp_url_map('href="/comptage/"'), 'href="/galerie/vg-a/comptage/"');
check('manifeste', cp_url_map('/comptage/manifest.json'), '/galerie/vg-a/comptage/manifest.json');
check('pas de double réécriture', cp_url_map(cp_url_map('/comptage/login.php')), '/galerie/vg-a/comptage/login.php');
check('les fichiers /assets/comptage.* ne sont pas touchés', cp_url_map('<script src="/assets/comptage.js"></script><link href="/assets/comptage.css">'), '<script src="/assets/comptage.js"></script><link href="/assets/comptage.css">');
check('texte sans adresse inchangé', cp_url_map('Le comptage de la foule'), 'Le comptage de la foule');
check('lien à communiquer', cp_volunteer_space_url(), 'http://galeries.test/galerie/vg-a/comptage/');
echo "\nRéécriture (exploitant : la galerie est précisée)\n";
cp_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => false, 'map' => true]);
check('résultats', cp_url_map('/admin/comptage/index.php'), '/portail/galerie-comptage.php?s=resultats&g=vg-a');
check('page + paramètre', cp_url_map('/admin/comptage/export.php?event=1'), '/portail/galerie-comptage.php?s=export&g=vg-a&event=1');
cp_url_map_off();
check('téléchargement : corps non réécrit', cp_url_map('/comptage/'), '/comptage/');

echo "\nAccès bénévole et responsable\n";
cp_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true, 'map' => true]);
$_SESSION = [];
check('visiteur : refusé', cp_is_volunteer(), false);
$_SESSION['cp_volunteer'] = 'vg-a';
check('bénévole de A : accepté dans A', cp_is_volunteer(), true);
cp_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => false, 'map' => true]);
check('bénévole de A : refusé dans B', cp_is_volunteer(), false);
$_SESSION = ['is_admin' => 'petit-chalet'];
check('administrateur d\'une BOUTIQUE : refusé', cp_is_volunteer(), false);
cp_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true, 'map' => true]);
$_SESSION = ['gallery_admin' => ['email' => 'a@example.com', 'slugs' => ['vg-a'], 'slug' => 'vg-a', 'at' => time()]];
$vg2 = gallery_load('vg-a');
$vg2['admins'] = [['email' => 'a@example.com', 'name' => 'A', 'hash' => password_hash('x', PASSWORD_DEFAULT), 'must_change' => false, 'created' => time(), 'last_login' => 0]];
gallery_save($vg2);
check('responsable de A : accepté dans A', cp_is_volunteer(), true);
cp_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => true, 'map' => true]);
check('responsable de A : refusé dans B', cp_is_volunteer(), false);
$_SESSION = ['portail_user' => 'exploitant'];
check('exploitant du portail : accepté', cp_is_volunteer(), true);
$_SESSION = [];

echo "\nSuppression d'une galerie\n";
check('suppression de A', gallery_delete('vg-a'), true);
check('sa base disparaît avec elle', is_file($tmp . '/vg-a.db'), false);
check('la base de B reste', is_file($tmp . '/mar-b.db'), true);

echo "\n" . ($failures === 0 ? "OK : $checks contrôles réussis.\n" : "ÉCHEC : $failures sur $checks.\n");
exit($failures === 0 ? 0 : 1);
