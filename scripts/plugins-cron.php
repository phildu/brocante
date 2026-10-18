<?php
// Tâche planifiée des plugins (OVH « Tâches planifiées », ou cron sur un serveur) : pour chaque boutique de ce serveur où un plugin
// est actif et à jour, lance ses tâches (clé « cron » de son manifeste, voir includes/plugins.php). Chaque tâche tourne dans un
// processus PHP à part avec TENANT=<boutique> : les constantes de config.php (base, clés) sont propres à une boutique.
//
//   php scripts/plugins-cron.php [--plugin=communication] [--tenant=petit-chalet]
//
// Journal : var/log/plugins-cron.log (jamais servi ni déployé). Une tâche ne doit jamais tourner deux fois en même temps pour une
// boutique : c'est à elle de se verrouiller (voir packs/communication/cron/rs-schedule.php).
PHP_SAPI === 'cli' || exit;

$root = dirname(__DIR__);
require $root . '/includes/tenant.php';
require $root . '/includes/plugins.php';

$opts = getopt('', ['plugin:', 'tenant:']);
$logDir = $root . '/var/log';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$log = static function (string $line) use ($logDir): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $line;
    echo $line, "\n";
    @file_put_contents($logDir . '/plugins-cron.log', $line . "\n", FILE_APPEND | LOCK_EX);
};
if (!function_exists('proc_open')) {
    $log('proc_open indisponible sur ce serveur : impossible de lancer les tâches des boutiques.');
    exit(1);
}

$ran = 0;
foreach (tenant_list() as $slug => $cfg) {
    if (!empty($opts['tenant']) && $opts['tenant'] !== $slug) continue;
    $dbFile = tenant_file($cfg, 'db_file');
    if (!is_file($dbFile)) continue;
    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = 1');
        $states = plugin_states($pdo);
    } catch (Throwable $e) {
        $log("$slug : base illisible (" . $e->getMessage() . ')');
        continue;
    }
    $pdo = null;
    foreach ($states as $id => $row) {
        if (!empty($opts['plugin']) && $opts['plugin'] !== $id) continue;
        $m = plugin_manifest($id);
        if (!$m || !$m['cron'] || plugin_status($row, $m) !== 'ready') continue;
        foreach ($m['cron'] as $task) {
            $env = array_merge(getenv(), ['TENANT' => $slug, 'PLUGIN_CRON' => '1']);
            $proc = proc_open([PHP_BINARY, $m['dir'] . '/' . $task['file']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            if (!is_resource($proc)) {
                $log("$slug · $id · {$task['file']} : lancement impossible");
                continue;
            }
            $out = trim((string) stream_get_contents($pipes[1]) . ' ' . (string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $log("$slug · $id · {$task['file']} : code $code · " . mb_substr(preg_replace('/\s+/', ' ', $out), 0, 400));
            $ran++;
        }
    }
}
$log("Terminé : $ran tâche(s).");
