<?php
// Entrée des agents externes des plugins : /api/<chemin> (réécrit vers plugin-agent.php?agent_path=<chemin> par le .htaccess,
// router.php et LocalValetDriver.php), aussi sous /<commerce>/api/… La boutique est celle de l'adresse. Pas de session ni de jeton
// CSRF : un plugin déclare ses préfixes dans son manifeste (agent_api) et sa route authentifie l'agent elle-même (jeton). Le plugin
// doit être activé et à jour dans la boutique. Exemple : l'agent de rendu de la Vidéo IA (pack Communication, /api/video-jobs/…).
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Réponse JSON et fin de la requête. */
function plugin_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Plugin agent : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    plugin_json(['error' => 'Erreur interne du module.'], 500);
});

$agentPath = '/' . trim((string) ($_GET['agent_path'] ?? ''), '/');
if (!preg_match('#^/[a-z0-9-]+(?:/[a-z0-9-]+)*$#', $agentPath)) plugin_json(['error' => 'Adresse inconnue.'], 404);
$plugin = null;
$route = null;
foreach (plugin_catalog() as $m) {
    foreach ($m['agent_api'] as $a) {
        if ($agentPath === $a['prefix'] || str_starts_with($agentPath, $a['prefix'] . '/')) { $plugin = $m; $route = $a; break 2; }
    }
}
if (!$plugin || plugin_shop_status($plugin['id']) !== 'ready') plugin_json(['error' => 'Adresse inconnue.'], 404);

define('PLUGIN_RUNTIME', true);
(static function () use ($plugin, $route, $agentPath): void {
    include $plugin['dir'] . '/' . $route['file'];
})();
plugin_json(['error' => 'La route n\'a rien répondu.'], 500);
