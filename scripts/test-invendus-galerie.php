<?php

// Test de « Invendus & dons » comme extension de galerie : base propre à chaque galerie, réécriture des adresses, accès public,
// disponibilité (gm_*). Usage : php scripts/test-invendus-galerie.php — n'écrit que dans un dossier temporaire.

$tmp = sys_get_temp_dir() . '/brocante-test-ivg-' . getmypid();
mkdir($tmp, 0777, true);
putenv('BROCANTE_GALLERIES_DIR=' . $tmp);
putenv('BROCANTE_GM_FILE=' . $tmp . '/modules-absent.json');   // réglages du portail : valeurs d'origine
putenv('BROCANTE_DB=' . $tmp . '/boutique.db');
register_shutdown_function(function () use ($tmp) { foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f); @rmdir($tmp); });
$_SERVER['HTTP_HOST'] = 'galeries.test';
define('DB_PATH', $tmp . '/boutique.db');
(new PDO('sqlite:' . DB_PATH))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
require_once __DIR__ . '/../includes/invendus.php';
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
check('aucun contexte', unsold_gallery_context(), null);
check('adresses inchangées', unsold_url_map('<a href="/admin/invendus.php">'), '<a href="/admin/invendus.php">');
check('le schéma n\'est pas créé au chargement', (int) (new PDO('sqlite:' . DB_PATH))->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'unsold_events'")->fetchColumn(), 0);

echo "\nDisponibilité (gm_*)\n";
$vg = gallery_normalize('vg-a', ['name' => 'Vide-grenier A', 'type' => 'vide-grenier', 'members' => []]);
$mar = gallery_normalize('mar-b', ['name' => 'Galerie B', 'type' => 'marchande', 'members' => []]);
check('proposée d\'office aux vide-greniers (option payante)', gm_state($vg, 'invendus')['mode'], 'option');
check('non proposée aux autres types', gm_state($mar, 'invendus')['mode'], 'off');
check('inactive tant qu\'elle n\'est ni essayée ni souscrite', gm_enabled($vg, 'invendus'), false);
check('essai gratuit démarré', gm_start_trial($vg, 'invendus'), null);
check('active pendant l\'essai', gm_enabled($vg, 'invendus'), true);
check('essai : 14 jours', round((gm_state($vg, 'invendus')['until'] - time()) / 86400), 14.0);
check('refus de l\'essai pour un type qui ne la propose pas', is_string(gm_start_trial($mar, 'invendus')), true);
check('indépendante de la buvette', gm_enabled($vg, 'buvette'), false);

echo "\nBase propre à chaque galerie\n";
gallery_save($vg);
gallery_save($mar);
unsold_enter_gallery($vg, true);
check('contexte : galerie A', unsold_gallery_context()['slug'], 'vg-a');
unsold_ensure_schema();
db()->prepare("INSERT INTO unsold_events (name, event_date, collect_start, collect_end, status, desk_token, created_at) VALUES ('Brocante A', '2026-09-20', '2026-09-20 16:00', '2026-09-20 18:30', 'published', 'tok-a', datetime('now'))")->execute();
check('l\'édition est dans la base de A', (int) db()->query('SELECT COUNT(*) FROM unsold_events')->fetchColumn(), 1);
check('fichier de la base de A', is_file($tmp . '/vg-a.db'), true);
$GLOBALS['db_pdo_override'] = gallery_sql('mar-b');
unsold_gallery_context(['slug' => 'mar-b', 'name' => 'Galerie B', 'limited' => true, 'map' => true]);
check('la base de B ne contient pas encore le schéma', (int) gallery_sql('mar-b')->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'unsold_events'")->fetchColumn(), 0);
check('nom affiché : celui de la galerie', unsold_site_name(), 'Galerie B');
check('la base de la boutique n\'est pas touchée', (int) (new PDO('sqlite:' . DB_PATH))->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'unsold_%'")->fetchColumn(), 0);
check('A garde son édition', (int) gallery_sql('vg-a')->query('SELECT COUNT(*) FROM unsold_events')->fetchColumn(), 1);

echo "\nRéécriture des adresses (administrateur de la galerie)\n";
unsold_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => true, 'map' => true]);
check('accueil du module', unsold_url_map('/admin/invendus.php'), '/portail/galerie-invendus.php?s=invendus');
check('page + paramètre', unsold_url_map('/admin/invendus-event.php?id=3&tab=point'), '/portail/galerie-invendus.php?s=invendus-event&id=3&tab=point');
check('dans un lien HTML', unsold_url_map('<a href="/admin/invendus-structures.php">x</a>'), '<a href="/portail/galerie-invendus.php?s=invendus-structures">x</a>');
check('liste publique', unsold_url_map('href="/dons.php"'), 'href="/galerie/vg-a/dons/"');
check('liste publique + paramètre', unsold_url_map('/dons.php?event=2'), '/galerie/vg-a/dons/?event=2');
check('proposer', unsold_url_map('/dons-proposer.php'), '/galerie/vg-a/dons/proposer.php');
check('suivi + jeton', unsold_url_map('/dons-suivi.php?t=abc'), '/galerie/vg-a/dons/suivi.php?t=abc');
check('point de collecte', unsold_url_map('/dons-point.php?t=abc&view=stock'), '/galerie/vg-a/dons/point.php?t=abc&view=stock');
check('tournée', unsold_url_map('/dons-tournee.php?t=abc'), '/galerie/vg-a/dons/tournee.php?t=abc');
check('pas de double réécriture', unsold_url_map(unsold_url_map('/dons-point.php?t=1')), '/galerie/vg-a/dons/point.php?t=1');
check('texte sans adresse inchangé', unsold_url_map('Des dons pour les invendus'), 'Des dons pour les invendus');
check('adresse complète (lien à communiquer)', unsold_abs_url('/dons-suivi.php?t=abc'), 'http://galeries.test/galerie/vg-a/dons/suivi.php?t=abc');
check('retour de formulaire : adresse de la galerie acceptée', unsold_safe_return('/portail/galerie-invendus.php?s=invendus-event&id=3', '/x'), '/portail/galerie-invendus.php?s=invendus-event&id=3');
check('retour de formulaire : adresse étrangère refusée', unsold_safe_return('https://evil.example/', '/x'), '/x');
check('retour de formulaire : autre page du portail refusée', unsold_safe_return('/portail/galeries.php', '/x'), '/x');
echo "\nRéécriture (exploitant : la galerie est précisée)\n";
unsold_gallery_context(['slug' => 'vg-a', 'name' => 'Vide-grenier A', 'limited' => false, 'map' => true]);
check('accueil du module', unsold_url_map('/admin/invendus.php'), '/portail/galerie-invendus.php?s=invendus&g=vg-a');
check('page + paramètre', unsold_url_map('/admin/invendus-event.php?id=3'), '/portail/galerie-invendus.php?s=invendus-event&g=vg-a&id=3');
unsold_url_map_off();
check('export CSV : corps non réécrit', unsold_url_map('/dons.php'), '/dons.php');

echo "\n$checks vérifications, $failures échec(s)\n";
exit($failures ? 1 : 0);
