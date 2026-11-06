<?php
defined('PLUGIN_RUNTIME') || exit;

require_once __DIR__ . '/../lib/catalog.php';
require_once __DIR__ . '/../lib/settings.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $query = trim((string) ($_GET['q'] ?? ''));
    $view = (string) ($_GET['view'] ?? 'catalogue');
    if (!in_array($view, ['catalogue', 'selected'], true)) {
        plugin_json(['error' => 'Vue invalide.'], 422);
    }
    $where = ['p.is_hidden = 0', 'p.stock > 0'];
    if ($view === 'selected') $where[] = 's.product_ref IS NOT NULL';
    $params = [];
    if ($query !== '') {
        $where[] = '(p.name LIKE ? OR p.ref LIKE ? OR p.brand LIKE ? OR p.description LIKE ?)';
        $like = '%' . $query . '%';
        $params = [$like, $like, $like, $like];
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM products p LEFT JOIN am_export_selection s ON s.product_ref = p.ref WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = db()->prepare("SELECT p.ref, p.name, p.brand, p.price, p.promo_price, s.selected_at
        FROM products p LEFT JOIN am_export_selection s ON s.product_ref = p.ref
        WHERE $whereSql ORDER BY p.sort_order, p.ref LIMIT $limit OFFSET $offset");
    $stmt->execute($params);

    $selectionCount = (int) db()->query('SELECT COUNT(*) FROM am_export_selection s
        JOIN products p ON p.ref = s.product_ref WHERE p.is_hidden = 0 AND p.stock > 0')->fetchColumn();
    plugin_json([
        'items' => array_map(static function (array $row): array {
            return [
                'ref' => (string) $row['ref'],
                'name' => (string) $row['name'],
                'brand' => (string) ($row['brand'] ?? ''),
                'price' => (string) ($row['promo_price'] ?: $row['price'] ?: ''),
                'selected' => $row['selected_at'] !== null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
        'total' => $total,
        'selected_count' => $selectionCount,
        'view' => $view,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

$input = plugin_input();
if (!am_is_entitled()) {
    plugin_json(['error' => 'Option non active : activez l’essai ou l’abonnement avant de modifier la sélection.'], 402);
}
$action = (string) ($input['action'] ?? '');
if ($action === 'clear') {
    db()->exec('DELETE FROM am_export_selection');
    plugin_json(['ok' => true, 'selected_count' => 0]);
}
if (!in_array($action, ['select', 'deselect'], true)) {
    plugin_json(['error' => 'Action invalide.'], 422);
}

$ref = trim((string) ($input['product_ref'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ref)) {
    plugin_json(['error' => 'Référence produit manquante ou invalide.'], 422);
}
$product = db()->prepare('SELECT 1 FROM products WHERE ref = ? AND is_hidden = 0 AND stock > 0');
$product->execute([$ref]);
if (!$product->fetchColumn()) plugin_json(['error' => 'Produit introuvable ou indisponible.'], 404);

if ($action === 'select') {
    $alreadySelected = db()->prepare('SELECT 1 FROM am_export_selection WHERE product_ref = ?');
    $alreadySelected->execute([$ref]);
    if (!$alreadySelected->fetchColumn() && (int) db()->query('SELECT COUNT(*) FROM am_export_selection')->fetchColumn() >= 1000) {
        plugin_json(['error' => 'La sélection est limitée à 1 000 articles. Retirez des articles avant d’en ajouter.'], 409);
    }
    db()->prepare('INSERT INTO am_export_selection (product_ref) VALUES (?)
        ON CONFLICT(product_ref) DO NOTHING')->execute([$ref]);
} else {
    db()->prepare('DELETE FROM am_export_selection WHERE product_ref = ?')->execute([$ref]);
}

$selectedCount = (int) db()->query('SELECT COUNT(*) FROM am_export_selection s
    JOIN products p ON p.ref = s.product_ref WHERE p.is_hidden = 0 AND p.stock > 0')->fetchColumn();
plugin_json(['ok' => true, 'selected_count' => $selectedCount]);
