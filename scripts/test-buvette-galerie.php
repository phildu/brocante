<?php

// Test de la buvette comme extension de galerie : base propre à chaque galerie, réécriture des adresses, accès bénévole et responsable,
// disponibilité (gm_*). Usage : php scripts/test-buvette-galerie.php — n'écrit que dans un dossier temporaire.

$tmp = sys_get_temp_dir() . '/brocante-test-bvg-' . getmypid();
mkdir($tmp, 0777, true);
putenv('BROCANTE_GALLERIES_DIR=' . $tmp);
putenv('BROCANTE_GM_FILE=' . $tmp . '/modules-absent.json');   // réglages du portail : valeurs d'origine
putenv('BROCANTE_DB=' . $tmp . '/boutique.db');
register_shutdown_function(function () use ($tmp) { foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f); @rmdir($tmp); });
$_SERVER['HTTP_HOST'] = 'localhost';
define('DB_PATH', $tmp . '/boutique.db');
(new PDO('sqlite:' . DB_PATH))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
require_once __DIR__ . '/../includes/buvette.php';
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
check('aucun contexte', bv_gallery_context(), null);
check('adresses inchangées', bv_url_map('<a href="/admin/buvette/carte.php">'), '<a href="/admin/buvette/carte.php">');

echo "\nDisponibilité (gm_*)\n";
$vg = gallery_normalize('vg-a', ['name' => 'Vide-grenier A', 'type' => 'vide-grenier', 'members' => []]);
$mar = gallery_normalize('mar-b', ['name' => 'Galerie B', 'type' => 'marchande', 'members' => []]);
check('proposée d\'office aux vide-greniers (option payante)', gm_state($vg, 'buvette')['mode'], 'option');
check('non proposée aux autres types', gm_state($mar, 'buvette')['mode'], 'off');
check('inactive tant qu\'elle n\'est ni essayée ni souscrite', gm_enabled($vg, 'buvette'), false);
check('essai gratuit démarré', gm_start_trial($vg, 'buvette'), null);
check('active pendant l\'essai', gm_enabled($vg, 'buvette'), true);
check('essai : 14 jours', round((gm_state($vg, 'buvette')['until'] - time()) / 86400), 14.0);
check('refus d\'un second essai', is_string(gm_start_trial($vg, 'buvette')), true);
check('refus de l\'essai pour un type qui ne la propose pas', is_string(gm_start_trial($mar, 'buvette')), true);

echo "\nBase propre à chaque galerie\n";
gallery_save($vg);
gallery_save($mar);
bv_enter_gallery($vg, true);
check('contexte : galerie A', bv_gallery_context()['slug'], 'vg-a');
bv_boot();
db()->prepare("INSERT INTO bv_events (name, event_date, is_active) VALUES ('Brocante A', '2026-09-20', 1)")->execute();
check('l\'événement est dans la base de A', (int) db()->query('SELECT COUNT(*) FROM bv_events')->fetchColumn(), 1);
check('fichier de la base de A', is_file($tmp . '/vg-a.db'), true);
$_GET = [];
bv_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => true]);
$GLOBALS['db_pdo_override'] = gallery_sql('mar-b');
db()->exec(BV_SCHEMA);
check('la base de B est vide : A et B ne partagent rien', (int) db()->query('SELECT COUNT(*) FROM bv_events')->fetchColumn(), 0);
check('fichier de la base de B', is_file($tmp . '/mar-b.db'), true);
check('A garde son événement', (int) gallery_sql('vg-a')->query('SELECT COUNT(*) FROM bv_events')->fetchColumn(), 1);
check('la base de la boutique n\'est pas touchée', (int) (new PDO('sqlite:' . DB_PATH))->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'bv_events'")->fetchColumn(), 0);
check('nom affiché : celui de la galerie', bv_site_name(), 'Galerie B');

echo "\nRéécriture des adresses (administrateur de la galerie)\n";
bv_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true]);
check('tableau de bord', bv_url_map('/admin/buvette/index.php'), '/portail/galerie-buvette.php?s=dashboard');
check('page + ancre', bv_url_map('/admin/buvette/courses.php#besoins'), '/portail/galerie-buvette.php?s=courses#besoins');
check('page + paramètre', bv_url_map('/admin/buvette/carte.php?affiche=1'), '/portail/galerie-buvette.php?s=carte&affiche=1');
check('dans un lien HTML', bv_url_map('<a href="/admin/buvette/recettes.php">x</a>'), '<a href="/portail/galerie-buvette.php?s=recettes">x</a>');
check('espace bénévoles', bv_url_map('/buvette/login.php'), '/galerie/vg-a/buvette/login.php');
check('racine de l\'espace bénévoles', bv_url_map('href="/buvette/"'), 'href="/galerie/vg-a/buvette/"');
check('pas de double réécriture', bv_url_map(bv_url_map('/buvette/carte.php')), '/galerie/vg-a/buvette/carte.php');
check('texte sans adresse inchangé', bv_url_map('Une buvette sympa'), 'Une buvette sympa');
echo "\nRéécriture (exploitant : la galerie est précisée)\n";
bv_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => false]);
check('tableau de bord', bv_url_map('/admin/buvette/index.php'), '/portail/galerie-buvette.php?s=dashboard&g=vg-a');
check('page + paramètre', bv_url_map('/admin/buvette/carte.php?affiche=1'), '/portail/galerie-buvette.php?s=carte&g=vg-a&affiche=1');

echo "\nAccès bénévole et responsable\n";
bv_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true]);
$_SESSION = [];
check('visiteur : refusé', bv_is_volunteer(), false);
$_SESSION['bv_volunteer'] = 'vg-a';
check('bénévole de A : accepté dans A', bv_is_volunteer(), true);
bv_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => false]);
check('bénévole de A : refusé dans B', bv_is_volunteer(), false);
$_SESSION = ['is_admin' => 'petit-chalet'];
check('administrateur d\'une BOUTIQUE : refusé', bv_is_volunteer(), false);
bv_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true]);
$_SESSION = ['gallery_admin' => ['email' => 'a@example.com', 'slugs' => ['vg-a'], 'slug' => 'vg-a', 'at' => time()]];
$vg2 = gallery_load('vg-a');
$vg2['admins'] = [['email' => 'a@example.com', 'name' => 'A', 'hash' => password_hash('x', PASSWORD_DEFAULT), 'must_change' => false, 'created' => time(), 'last_login' => 0]];
gallery_save($vg2);
check('responsable de A : accepté dans A', bv_is_volunteer(), true);
bv_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => true]);
check('responsable de A : refusé dans B', bv_is_volunteer(), false);
$_SESSION = ['portail_user' => 'exploitant'];
check('exploitant du portail : accepté', bv_is_volunteer(), true);
$_SESSION = [];
echo "\nSuppression d'une galerie\n";
check('suppression de A', gallery_delete('vg-a'), true);
check('sa base disparaît avec elle', is_file($tmp . '/vg-a.db'), false);
check('la base de B reste', is_file($tmp . '/mar-b.db'), true);

echo "\n" . ($failures === 0 ? "OK : $checks contrôles réussis.\n" : "ÉCHEC : $failures sur $checks.\n");
exit($failures === 0 ? 0 : 1);
