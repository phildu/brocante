<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

if (!shop_can_add_items()) {
    flash_set(shop_limit_message(), 'error');
    header('Location: /admin/catalog.php');
    exit;
}

$validCats = array_column(category_list(), 'key');
$cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : default_category_key();
$name = trim((string) ($_POST['name'] ?? '')) ?: 'Nouvelle pièce';
$ref = next_ref();
$nature = product_nature_resolve($_POST['nature'] ?? '', $_POST['sous_categorie'] ?? '');
$weightGrams = max(0, min(300000, (int) ($_POST['weight_grams'] ?? 0)));
$weightText = mb_substr(trim((string) ($_POST['weight_text'] ?? '')), 0, 60) ?: product_weight_text($weightGrams);

$photoPath = store_uploaded_photo('photo', 'product-' . $ref);

$stmt = db()->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, materials, etat, nature, sous_categorie, size_text, weight_text, weight_grams, price, badge, featured, sort_order)
                        VALUES (:ref, :name, :cat, :photo, NULL, :description, :materials, :etat, :nature, :sous_categorie, :size_text, :weight_text, :weight_grams, :price, :badge, 0, :sort_order)');
$stmt->execute([
    'ref' => $ref,
    'name' => $name,
    'cat' => $cat,
    'photo' => $photoPath,
    'description' => trim((string) ($_POST['description'] ?? '')) ?: 'Description à compléter.',
    'materials' => trim((string) ($_POST['materials'] ?? '')),
    'etat' => product_condition_key($_POST['etat'] ?? '') ?: null,
    'nature' => $nature['nature'] ?: null,
    'size_text' => mb_substr(trim((string) ($_POST['size_text'] ?? '')), 0, 60),
    'weight_text' => $weightText,
    'weight_grams' => $weightGrams,
    'sous_categorie' => $nature['sous_categorie'] ?: null,
    'price' => trim((string) ($_POST['price'] ?? '')) ?: '0 €',
    'badge' => trim((string) ($_POST['badge'] ?? '')) ?: 'Chiné',
    'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
]);

flash_set('Pièce « ' . $name . ' » ajoutée — Réf. N°' . $ref . '.');
header('Location: /admin/catalog.php#produit-' . urlencode($ref));
exit;
