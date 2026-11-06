<?php
// Tâche planifiée du module Actions commerciales : fait avancer les opérations et envoie les étapes de campagne dues, même sans visite
// sur le site (TENANT=<boutique>). Lancée par scripts/plugins-cron.php, jamais par le web. Les étapes se verrouillent en base : deux
// exécutions qui se chevauchent n'envoient jamais deux fois le même message.
PHP_SAPI === 'cli' || exit;
define('PLUGIN_RUNTIME', true);

$root = dirname(__DIR__, 3);
chdir($root);
require_once $root . '/includes/functions.php';

// Adresse de la boutique : shop_abs_url() la lit dans la requête, absente en ligne de commande.
$site = parse_url((string) tenant('site_url'));
$_SERVER['HTTP_HOST'] = ($site['host'] ?? 'localhost') . (isset($site['port']) ? ':' . $site['port'] : '');
$_SERVER['HTTPS'] = ($site['scheme'] ?? '') === 'https' ? 'on' : '';

require_once dirname(__DIR__) . '/lib/aco.php';
if (!aco_enabled()) {
    echo 'Module non actif pour cette boutique : rien à faire.';
    exit(0);
}
aco_tick(true);
$st = db()->query("SELECT status, COUNT(*) AS n FROM aco_campaign_steps GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
echo 'Étapes : ' . ($st ? implode(', ', array_map(static fn ($k, $v) => "$k=$v", array_keys($st), $st)) : 'aucune') . '.';
