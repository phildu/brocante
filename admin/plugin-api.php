<?php
// API JSON d'un plugin : /admin/plugin-api.php?p=<plugin>&r=<route> (voir includes/plugins.php).
// Session de l'administration de la boutique obligatoire, rôle déclaré par la route, jeton CSRF (en-tête X-CSRF-Token ou
// champ « csrf ») pour toute méthode autre que GET. Le fichier de la route répond avec plugin_json().
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plugins.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Réponse JSON et fin de la requête. */
function plugin_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corps de la requête : JSON (application/json) ou champs de formulaire. */
function plugin_input(): array
{
    if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Plugin API : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    plugin_json(['error' => 'Erreur interne du module.'], 500);
});

$session = admin_session();
if ($session === null) plugin_json(['error' => 'Connexion requise.'], 401);
$role = $session['role'];

$plugin = plugin_manifest((string) ($_GET['p'] ?? ''));
$route = $plugin['api'][(string) ($_GET['r'] ?? '')] ?? null;
$status = $plugin ? plugin_shop_status($plugin['id']) : 'absent';
if (!$plugin || !$route || !in_array($status, ['ready', 'update', 'newer'], true)) plugin_json(['error' => 'Module ou route introuvable.'], 404);
if (!plugin_role_allowed($route['roles'], $role)) plugin_json(['error' => 'Accès refusé pour votre rôle.'], 403);
if ($status !== 'ready') plugin_json(['error' => 'Module en cours de mise à jour, réessayez plus tard.'], 503);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, $route['methods'], true)) {
    header('Allow: ' . implode(', ', $route['methods']));
    plugin_json(['error' => 'Méthode non autorisée.'], 405);
}
if ($method !== 'GET') {
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals(admin_csrf_token(), $token)) plugin_json(['error' => 'Jeton expiré : rechargez la page.'], 400);
}

define('PLUGIN_RUNTIME', true);
(static function () use ($plugin, $route, $role, $method, $session): void {
    include $plugin['dir'] . '/' . $route['file'];
})();
plugin_json(['error' => 'La route n\'a rien répondu.'], 500);
