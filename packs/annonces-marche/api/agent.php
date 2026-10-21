<?php
// Agent externe = extension Chrome : /api/annonces/… authentifié par jeton (pas de session).
defined('PLUGIN_RUNTIME') || exit;

require_once dirname(__DIR__, 3) . '/includes/share.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/catalog.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $agentPath; // fourni par plugin-agent.php, ex. /annonces/catalogue

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Annonces-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$body = [];
if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($decoded) ? $decoded : [];
}

// Ping public (sans jeton) : utile pour vérifier l'URL boutique.
if ($path === '/annonces/ping' && $method === 'GET') {
    $tokenOk = am_token_valid();
    plugin_json([
        'ok' => true,
        'shop' => (string) (get_content()['site_name'] ?? tenant('name')),
        'slug' => tenant_slug(),
        'entitled' => am_is_entitled(),
        'authenticated' => $tokenOk,
        'version' => '1.0.0',
    ]);
}

am_agent_require('plugin_json');

if ($path === '/annonces/catalogue' && $method === 'GET') {
    plugin_json(am_catalogue(
        (string) ($_GET['q'] ?? ''),
        (int) ($_GET['limit'] ?? 30),
        (int) ($_GET['offset'] ?? 0)
    ));
}

if (preg_match('#^/annonces/produit/([A-Za-z0-9_-]{1,40})$#', $path, $m) && $method === 'GET') {
    $product = am_product_by_ref($m[1]);
    if (!$product) plugin_json(['error' => 'Produit introuvable ou indisponible.'], 404);
    plugin_json(['product' => $product]);
}

if ($path === '/annonces/marquer' && $method === 'POST') {
    $ref = trim((string) ($body['product_ref'] ?? $body['ref'] ?? ''));
    $channel = strtolower(trim((string) ($body['channel'] ?? '')));
    $status = strtolower(trim((string) ($body['status'] ?? 'published')));
    $url = trim((string) ($body['listing_url'] ?? $body['url'] ?? ''));
    $external = trim((string) ($body['external_id'] ?? ''));
    $note = mb_substr(trim((string) ($body['note'] ?? '')), 0, 400);
    if ($ref === '' || !in_array($channel, ['leboncoin', 'vinted'], true)) {
        plugin_json(['error' => 'Référence et canal (leboncoin|vinted) requis.'], 422);
    }
    if (!in_array($status, ['draft', 'published', 'error', 'removed'], true)) {
        plugin_json(['error' => 'Statut invalide.'], 422);
    }
    $exists = db()->prepare('SELECT 1 FROM products WHERE ref = ?');
    $exists->execute([$ref]);
    if (!$exists->fetchColumn()) plugin_json(['error' => 'Produit introuvable.'], 404);
    $publishedAt = $status === 'published' ? gmdate('c') : null;
    db()->prepare('INSERT INTO am_listings (product_ref, channel, status, listing_url, external_id, note, published_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$ref, $channel, $status, $url, $external, $note, $publishedAt]);
    plugin_json(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

plugin_json(['error' => 'Adresse inconnue.'], 404);
