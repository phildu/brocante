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
        'version' => '1.3.0',
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

if ($path === '/annonces/selection' && $method === 'GET') {
    $stmt = db()->query("SELECT s.selected_at, p.*
        FROM am_export_selection s
        JOIN products p ON p.ref = s.product_ref
        WHERE p.is_hidden = 0 AND p.stock > 0
        ORDER BY s.selected_at, p.sort_order, p.ref
        LIMIT 1000");
    $items = array_map(static function (array $row): array {
        $selectedAt = (string) $row['selected_at'];
        unset($row['selected_at']);
        return [
            'selected_at' => $selectedAt,
            'product' => am_product_payload($row),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    plugin_json([
        'items' => $items,
        'count' => count($items),
        'updated_at' => gmdate('c'),
    ]);
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

// Pièce vendue sur une place de marché : le stock de la boutique baisse (une pièce unique passe à 0 et quitte la sélection).
// Idempotent : une même annonce (external_id) ne retire jamais de stock deux fois.
if ($path === '/annonces/vendu' && $method === 'POST') {
    $ref = trim((string) ($body['product_ref'] ?? $body['ref'] ?? ''));
    $channel = strtolower(trim((string) ($body['channel'] ?? 'leboncoin')));
    $external = trim((string) ($body['external_id'] ?? ''));
    $url = trim((string) ($body['listing_url'] ?? $body['url'] ?? ''));
    $note = mb_substr(trim((string) ($body['note'] ?? '')), 0, 400);
    $qty = max(1, min(50, (int) ($body['quantity'] ?? 1)));
    if ($ref === '' || !in_array($channel, ['leboncoin', 'vinted'], true)) {
        plugin_json(['error' => 'Référence et canal (leboncoin|vinted) requis.'], 422);
    }
    $stmt = db()->prepare('SELECT stock FROM products WHERE ref = ?');
    $stmt->execute([$ref]);
    $stockBefore = $stmt->fetchColumn();
    if ($stockBefore === false) plugin_json(['error' => 'Produit introuvable.'], 404);
    $stockBefore = (int) $stockBefore;
    if ($external !== '') {
        $dup = db()->prepare("SELECT 1 FROM am_listings WHERE product_ref = ? AND channel = ? AND external_id = ? AND status = 'sold' LIMIT 1");
        $dup->execute([$ref, $channel, $external]);
        if ($dup->fetchColumn()) {
            plugin_json(['ok' => true, 'already' => true, 'stock' => $stockBefore, 'sold_out' => $stockBefore <= 0]);
        }
    }
    db()->beginTransaction();
    try {
        decrement_stock_for_order([['ref' => $ref, 'qty' => $qty]]);
        db()->prepare("INSERT INTO am_listings (product_ref, channel, status, listing_url, external_id, note)
            VALUES (?, ?, 'sold', ?, ?, ?)")->execute([$ref, $channel, $url, $external, $note]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        plugin_json(['error' => 'Mise à jour du stock impossible.'], 500);
    }
    $stmt->execute([$ref]);
    $stockAfter = (int) $stmt->fetchColumn();
    if ($stockAfter <= 0) {      // la pièce est vendue : on garde le canal et la date
        db()->prepare('UPDATE products SET sold_channel = ?, sold_at = CURRENT_TIMESTAMP WHERE ref = ?')->execute([$channel, $ref]);
    }
    plugin_json(['ok' => true, 'already' => false, 'stock_before' => $stockBefore, 'stock' => $stockAfter, 'sold_out' => $stockAfter <= 0]);
}

// Champs / valeurs que l'agent n'a pas su remplir : ils alimentent la liste « à renseigner » de la page Correspondances.
if ($path === '/annonces/champs-inconnus' && $method === 'POST') {
    $channel = strtolower(trim((string) ($body['channel'] ?? 'leboncoin')));
    if (!isset(AM_MAP_CHANNELS[$channel])) plugin_json(['error' => 'Canal inconnu (leboncoin ou vinted).'], 422);
    plugin_json(['ok' => true, 'recorded' => am_unknown_record($channel, (array) ($body['items'] ?? []))]);
}

if ($path === '/annonces/correspondances' && $method === 'GET') {
    am_map_ensure();
    plugin_json(['mappings' => db()->query('SELECT channel, field, source_label, target FROM am_mappings ORDER BY channel, field, source_label')->fetchAll(PDO::FETCH_ASSOC)]);
}

plugin_json(['error' => 'Adresse inconnue.'], 404);
