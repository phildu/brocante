<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Media Links API
 * GET /api/media/links
 */

require_once __DIR__ . '/../compat.php';

$path = getPath();
$method = getMethod();

// Routes
if ($path === '/media/links' && $method === 'GET') {
    handleGetMediaLinks();
} else {
    errorResponse('Route non trouvée', 404);
}

/**
 * GET /api/media/links
 * Liste tous les liens médias (YouTube, etc.)
 */
function handleGetMediaLinks() {
    $db = getDb();
    
    $stmt = $db->query("SELECT * FROM media_links ORDER BY created_at DESC");
    $links = $stmt->fetchAll();
    
    jsonResponse($links);
}