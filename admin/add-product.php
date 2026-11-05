<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
auth_require_action(basename(__FILE__), (string) ($_POST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

$validCats = array_column(category_list(), 'key');
$cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : 'ceramique';
$name = trim((string) ($_POST['name'] ?? '')) ?: 'Nouvelle pièce';
$ref = next_ref();

$photoPath = store_uploaded_photo('photo', 'product-' . $ref);

analytics_ensure_schema();
$costInput = parse_euros($_POST['cost_price'] ?? null);
$stmt = db()->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, badge, featured, sort_order, cost_cents)
                        VALUES (:ref, :name, :cat, :photo, NULL, :description, :price, :badge, 0, :sort_order, :cost_cents)');
$stmt->execute([
    'ref' => $ref,
    'name' => $name,
    'cat' => $cat,
    'photo' => $photoPath,
    'description' => trim((string) ($_POST['description'] ?? '')) ?: 'Description à compléter.',
    'price' => trim((string) ($_POST['price'] ?? '')) ?: '0 €',
    'badge' => trim((string) ($_POST['badge'] ?? '')) ?: 'Chiné',
    'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
    'cost_cents' => ($costInput === null || !auth_can('boutique.produits.cout')) ? null : (int) round($costInput * 100),
]);

flash_set('Pièce « ' . $name . ' » ajoutée — Réf. N°' . $ref . '.');
header('Location: /admin/catalog.php#produit-' . urlencode($ref));
exit;
