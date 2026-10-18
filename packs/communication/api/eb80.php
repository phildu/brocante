<?php
// Route « eb80 » : l'API d'electroboy80 portée, appelée par son interface (assets/eb80/admin.html) à travers eb80-shim.js :
// chaque appel /api/<chemin>?<paramètres> devient /admin/plugin-api.php?p=communication&r=eb80&path=<chemin>&eb80q=<paramètres>.
// La connexion, le rôle et le jeton CSRF ont déjà été vérifiés par admin/plugin-api.php. Les contrôleurs d'electroboy80 portés
// sont dans eb80/api/ (copies, voir tools/extract-eb80.php) ; un chemin non porté répond vide (lecture) ou « pas encore disponible ».
defined('PLUGIN_RUNTIME') || exit;

$path = '/' . trim((string) ($_GET['path'] ?? ''), '/');
if (!preg_match('#^/[a-z0-9/_.-]*$#i', $path) || str_contains($path, '..')) plugin_json(['error' => 'Chemin invalide.'], 400);
// Les contrôleurs d'electroboy80 lisent leurs propres paramètres : on leur rend ceux de l'appel d'origine.
parse_str((string) ($_GET['eb80q'] ?? ''), $query);
$_GET = $query;
$_SERVER['EB80_API_PATH'] = $path;
$GLOBALS['eb80_session'] = $session;

$parts = explode('/', trim($path, '/'));
$controller = $parts[0] ?? '';

if ($path === '/auth/me') {
    plugin_json(['id' => (int) ($session['id'] ?? 0), 'role' => 'admin', 'name' => $session['name'] !== '' ? $session['name'] : 'Compte principal',
        'email' => '', 'permissions' => []]);
}
// Réglages d'electroboy80 (sa table settings, propre à la boutique) : lecture pour tous, écriture réservée aux administrateurs.
if ($controller === 'settings' && $method !== 'GET' && $role !== 'admin') plugin_json(['error' => 'Réservé aux administrateurs.'], 403);

$dir = __DIR__ . '/../eb80/api/';
$ported = ['media' => 'media.php', 'settings' => 'settings.php', 'generate-web-images' => 'generate-web-images.php'];
// Sous-chemin combiné, comme dans electroboy80 (/media/links → media-links.php), parmi les contrôleurs portés seulement.
if (count($parts) > 1 && $controller === 'media' && implode('-', $parts) === 'media-links' && is_file($dir . 'media-links.php')) {
    require $dir . 'media-links.php';
    exit;
}
if (isset($ported[$controller]) && is_file($dir . $ported[$controller])) {
    require $dir . $ported[$controller];
    exit;
}
if ($method === 'GET') plugin_json([]);
plugin_json(['error' => 'Fonction pas encore disponible dans la boutique.'], 501);
