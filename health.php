<?php

// Sonde de supervision : 200 quand tout va bien, 503 sinon. Réponse volontairement
// courte en HTTP (le détail reste dans data/logs et dans la sortie CLI) ; le
// moniteur externe n'a besoin que du code de statut.
ini_set('display_errors', '0');

require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/watchdog.php';

$health = wd_health();
$full = PHP_SAPI === 'cli';
$health['env'] = app_env();

if (!$full) {
    $health = [
        'ok' => $health['ok'],
        'env' => app_env(),
        'time' => $health['time'],
        'db' => $health['db'],
        'issues' => array_keys($health['issues']),
    ];
}

http_response_code($health['ok'] ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex');
echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($full ? JSON_PRETTY_PRINT : 0)), "\n";
