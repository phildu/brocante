<?php
// Tâche planifiée du pack Communication : publie les publications programmées dues de la boutique (TENANT=<boutique>), avec le
// code d'electroboy80 (eb80/api/rs-schedule.php, route /rs/schedule/run). Lancée par scripts/plugins-cron.php, jamais par le web.
// Verrou par boutique : deux exécutions qui se chevauchent ne publient pas deux fois.
PHP_SAPI === 'cli' || exit;
define('PLUGIN_RUNTIME', true);

$root = dirname(__DIR__, 3);
chdir($root);
require_once $root . '/includes/functions.php';
require_once dirname(__DIR__) . '/lib/media.php';

// Adresse de la boutique : appBaseUrl() d'electroboy80 la lit dans la requête, absente en ligne de commande.
$site = parse_url((string) tenant('site_url'));
$_SERVER['HTTP_HOST'] = ($site['host'] ?? 'localhost') . (isset($site['port']) ? ':' . $site['port'] : '');
$_SERVER['HTTPS'] = ($site['scheme'] ?? '') === 'https' ? 'on' : '';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['EB80_API_PATH'] = '/rs/schedule/run';
$GLOBALS['eb80_session'] = ['id' => 0, 'name' => 'Tâche planifiée', 'role' => 'admin'];

require_once dirname(__DIR__) . '/eb80/compat.php';
$lock = fopen(dirname(EB80_DB_PATH) . '/' . tenant_slug() . '.cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Exécution précédente encore en cours : rien à faire.';
    exit(0);
}
// Jeton attendu par la route d'electroboy80 : créé à la première exécution (il ne sert qu'à cette tâche, jamais exposé).
$token = getSetting('rs_cron_token');
if ($token === '') {
    $token = bin2hex(random_bytes(24));
    getDb()->prepare("INSERT OR REPLACE INTO settings (key, value, label, section, type) VALUES ('rs_cron_token', ?, 'Jeton de la tâche planifiée', 'rs', 'text')")->execute([$token]);
}
eb80_fix_schedule_table();
$_GET['token'] = $token;
require dirname(__DIR__) . '/eb80/api/rs-schedule.php';
