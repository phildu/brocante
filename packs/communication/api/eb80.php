<?php
// Route « eb80 » : l'API d'electroboy80 portée, appelée par son interface (assets/eb80/admin.html) à travers eb80-shim.js :
// chaque appel /api/<chemin>?<paramètres> devient /admin/plugin-api.php?p=communication&r=eb80&path=<chemin>&eb80q=<paramètres>.
// La connexion, le rôle et le jeton CSRF ont déjà été vérifiés par admin/plugin-api.php. Les contrôleurs d'electroboy80 portés
// sont dans eb80/api/ (copies, voir tools/extract-eb80.php) ; un chemin non porté répond vide (lecture) ou « pas encore disponible ».
defined('PLUGIN_RUNTIME') || exit;

$path = '/' . trim((string) ($_GET['path'] ?? ''), '/');
// Les noms (projets, états, fichiers) peuvent contenir espaces et accents : seuls « .. » et les caractères de contrôle sont refusés,
// les contrôleurs d'electroboy80 traitent ensuite les noms eux-mêmes (requêtes préparées, basename()).
if (!preg_match('#^/[^\x00-\x1f\x7f]*$#u', $path) || preg_match('#(?:^|/)\.\.(?:/|$)#', $path)) plugin_json(['error' => 'Chemin invalide.'], 400);
// Les contrôleurs d'electroboy80 lisent leurs propres paramètres : on leur rend ceux de l'appel d'origine.
parse_str((string) ($_GET['eb80q'] ?? ''), $query);
$_GET = $query;
$_SERVER['EB80_API_PATH'] = $path;
$GLOBALS['eb80_session'] = $session;

$parts = explode('/', trim($path, '/'));
$controller = $parts[0] ?? '';

if ($path === '/auth/me') {
    plugin_json(['id' => (int) ($session['id'] ?? 0), 'role' => $role === 'admin' ? 'admin' : 'collaborator', 'name' => $session['name'] !== '' ? $session['name'] : 'Compte principal',
        'email' => '', 'permissions' => $role === 'admin' ? [] : ['editor', 'community'], 'uploads' => '/uploads/' . tenant_slug() . '/']);
}
// Sources de l'éditeur propres à la boutique (sources intégrées déclarées dans eb80-shim.js) : pièces en vente et promos.
if ($method === 'GET' && ($path === '/shop/pieces' || $path === '/shop/promos')) {
    require_once __DIR__ . '/../lib/sources.php';
    plugin_json(['items' => $path === '/shop/pieces' ? com_source_pieces() : com_source_promos()]);
}
// Réglages d'electroboy80 (sa table settings, propre à la boutique) : lecture pour tous, écriture réservée aux administrateurs.
if ($controller === 'settings' && $method !== 'GET' && $role !== 'admin') plugin_json(['error' => 'Réservé aux administrateurs.'], 403);

// Réglages lus par un community manager : electroboy80 renvoie tout à un collaborateur habilité ; la boutique masque les valeurs
// secrètes (jetons, clés, mots de passe : règle de reglages-publics.php) — l'interface voit qu'un réseau est configuré, sans le jeton.
if ($controller === 'settings' && $method === 'GET' && $role !== 'admin') {
    require_once __DIR__ . '/../eb80/compat.php';
    [$data, $status] = eb80_capture(static function (): void { require __DIR__ . '/../eb80/api/settings.php'; });
    // Réglages « private » pour les visiteurs mais sans secret, nécessaires à l'équipe : la configuration des sources de l'éditeur.
    $equipe = ['editor_sources_private'];
    $masque = static fn(string $key, $value) => (!in_array($key, $equipe, true) && !eb80_reglage_est_public($key) && (string) $value !== '') ? '••••••' : $value;
    foreach ((array) ($data['settings'] ?? []) as $key => $value) $data['settings'][$key] = $masque((string) $key, $value);
    foreach ((array) ($data['settingsList'] ?? []) as $i => $row) {
        if (is_array($row) && isset($row['key'])) $data['settingsList'][$i]['value'] = $masque((string) $row['key'], $row['value'] ?? '');
    }
    plugin_json($data, $status);
}
// Réglages de l'administrateur : ceux en double avec la plateforme (clé Gemini, connexion sociale) sont lus et écrits chez elle,
// ceux qu'aucune fonction de la boutique n'utilise sont ignorés (lib/reglages.php) ; le reste va dans la base d'electroboy80.
if ($path === '/settings' && $role === 'admin' && in_array($method, ['GET', 'PATCH'], true)) {
    require_once __DIR__ . '/../eb80/compat.php';
    require_once __DIR__ . '/../lib/reglages.php';
    if ($method === 'GET') {
        [$data, $status] = eb80_capture(static function (): void { require __DIR__ . '/../eb80/api/settings.php'; });
        plugin_json(is_array($data) && $status === 200 ? com_reglages_superposer($data) : $data, $status);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) plugin_json(['error' => 'Requête invalide.'], 400);
    try {
        [$rest, $saved] = com_reglages_enregistrer($body);
    } catch (RuntimeException $e) {
        plugin_json(['error' => $e->getMessage()], 500);
    }
    if (!$rest) plugin_json(['message' => 'Paramètres mis à jour', 'updated' => $saved]);
    $GLOBALS['eb80_json_input'] = $rest;
    [$data, $status] = eb80_capture(static function (): void { require __DIR__ . '/../eb80/api/settings.php'; });
    if (is_array($data) && isset($data['updated']) && is_array($data['updated'])) $data['updated'] = array_merge($saved, $data['updated']);
    plugin_json($data, $status);
}
// Réseaux sociaux. Les publications programmées ne partent que par la tâche planifiée (scripts/plugins-cron.php), jamais par le web.
if ($path === '/rs/schedule/run') plugin_json(['error' => 'Réservé à la tâche planifiée.'], 403);
// Jetons et diagnostic : réservés aux administrateurs (le community manager publie et programme).
if (preg_match('#^/rs/(?:[a-z]+-refresh-token|test/|diagnose|tiktok-callback)#', $path) && $role !== 'admin') plugin_json(['error' => 'Réservé aux administrateurs.'], 403);
// Vidéo IA : le jeton de l'agent de rendu ne se crée que par l'administrateur ; l'agent lui-même passe par /api/video-jobs/…
// (plugin-agent.php, api/agent.php), jamais par cette route de l'administration.
if ($path === '/video-jobs/worker-token' && $role !== 'admin') plugin_json(['error' => 'Réservé aux administrateurs.'], 403);
if ($path === '/video-jobs/next' || preg_match('#^/video-jobs/\d+/result$#', $path)) plugin_json(['error' => 'Réservé à l\'agent de rendu.'], 403);

$dir = __DIR__ . '/../eb80/api/';
$ported = ['media' => 'media.php', 'settings' => 'settings.php', 'generate-web-images' => 'generate-web-images.php', 'ai-generate' => 'ai-generate.php'];
$ported['social-feed'] = 'social-feed.php';
$ported['video-jobs'] = 'video-jobs.php'; // file de rendu de la Vidéo IA ; /video-jobs/ai → video-jobs-ai.php (relais du fichier)
// Même aiguillage qu'electroboy80 (api/entry.php) pour /rs/… : retour TikTok, programmation, le reste dans rs-v2.php.
if ($controller === 'rs') {
    if ($path === '/rs/tiktok-callback') {
        header('Content-Type: text/html; charset=utf-8');
        require $dir . 'rs-tiktok-callback.php';
        exit;
    }
    if (str_starts_with($path, '/rs/schedule')) {
        require_once __DIR__ . '/../eb80/compat.php';
        eb80_fix_schedule_table();
    }
    require $dir . (str_starts_with($path, '/rs/schedule') ? 'rs-schedule.php' : 'rs-v2.php');
    exit;
}
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
