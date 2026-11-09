<?php

// Sonde de supervision : 200 quand tout va bien, 503 sinon. Réponse volontairement
// courte en HTTP (le détail reste dans data/logs et dans la sortie CLI) ; le
// moniteur externe n'a besoin que du code de statut.
ini_set('display_errors', '0');

try {
    require __DIR__ . '/config.php';
} catch (RuntimeException $e) {
    if (!str_starts_with($e->getMessage(), 'Commerce introuvable')) {
        throw $e;
    }
    // Installation « portail » neuve : aucun commerce par défaut (les commerces d'essai locaux ne sont
    // jamais déployés), donc ni base ni secrets de commerce à vérifier. La sonde répond 200 et annonce
    // l'environnement ; le contrôle complet reprend dès qu'un commerce par défaut existe.
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex');
    echo json_encode(['ok' => true, 'env' => app_env(), 'time' => date('c'), 'db' => 'sans commerce', 'issues' => []], JSON_UNESCAPED_UNICODE), "\n";
    exit;
}
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
