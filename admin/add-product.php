<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

$validCats = array_column(category_list(), 'key');
$cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : 'ceramique';
$name = trim((string) ($_POST['name'] ?? '')) ?: 'Nouvelle pièce';
$ref = next_ref();

$photoPath = store_uploaded_photo('photo', 'product-' . $ref);

$stmt = db()->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, badge, featured, sort_order)
                        VALUES (:ref, :name, :cat, :photo, NULL, :description, :price, :badge, 0, :sort_order)');
$stmt->execute([
    'ref' => $ref,
    'name' => $name,
    'cat' => $cat,
    'photo' => $photoPath,
    'description' => trim((string) ($_POST['description'] ?? '')) ?: 'Description à compléter.',
    'price' => trim((string) ($_POST['price'] ?? '')) ?: '0 €',
    'badge' => trim((string) ($_POST['badge'] ?? '')) ?: 'Chiné',
    'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
]);

flash_set('Pièce « ' . $name . ' » ajoutée — Réf. N°' . $ref . '.');
header('Location: /admin/catalog.php#produit-' . urlencode($ref));
exit;
