<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

$ref = (string) ($_POST['ref'] ?? '');
$product = $ref !== '' ? get_product($ref) : null;

if (!$product) {
    flash_set("Pièce introuvable.", 'error');
    header('Location: /admin/catalog.php');
    exit;
}

$validCats = array_column(category_list(), 'key');
$cat = in_array($_POST['cat'] ?? '', $validCats, true) ? $_POST['cat'] : $product['cat'];

$featuredRequested = !empty($_POST['featured']);
if ($featuredRequested && !$product['featured']) {
    $count = (int) db()->query('SELECT COUNT(*) FROM products WHERE featured = 1')->fetchColumn();
    if ($count >= 3) {
        flash_set("Trois pièces sont déjà mises en avant — décochez-en une avant d'en ajouter une autre.", 'error');
        header('Location: /admin/catalog.php#produit-' . urlencode($ref));
        exit;
    }
}

$photoPath = store_uploaded_photo('photo', 'product-' . $ref);

$badge = trim((string) ($_POST['badge'] ?? $product['badge']));
// Le champ n'est affiché (et donc soumis) que si la mention est "Promo" —
// hors de ce cas le prix promo n'a pas de sens et repart à vide.
$promoPrice = $badge === 'Promo' ? trim((string) ($_POST['promo_price'] ?? '')) : '';

$set = 'name = :name, cat = :cat, description = :description, price = :price, promo_price = :promo_price, badge = :badge, size_text = :size_text, weight_text = :weight_text, weight_grams = :weight_grams, featured = :featured, is_hidden = :is_hidden, stock = :stock';
$params = [
    'name' => trim((string) ($_POST['name'] ?? $product['name'])),
    'cat' => $cat,
    'description' => trim((string) ($_POST['description'] ?? $product['description'])),
    'price' => trim((string) ($_POST['price'] ?? $product['price'])),
    'promo_price' => $promoPrice !== '' ? $promoPrice : null,
    'badge' => $badge,
    'size_text' => trim((string) ($_POST['size_text'] ?? $product['size_text'])),
    'weight_text' => trim((string) ($_POST['weight_text'] ?? $product['weight_text'])),
    'weight_grams' => isset($_POST['weight_grams']) ? max(0, (int) $_POST['weight_grams']) : $product['weight_grams'],
    'featured' => $featuredRequested ? 1 : 0,
    'is_hidden' => !empty($_POST['is_hidden']) ? 1 : 0,
    'stock' => isset($_POST['stock']) ? max(0, (int) $_POST['stock']) : $product['stock'],
    'ref' => $ref,
];
if ($photoPath) {
    $set .= ', photo = :photo';
    $params['photo'] = $photoPath;
}

$stmt = db()->prepare("UPDATE products SET $set WHERE ref = :ref");
$stmt->execute($params);

flash_set('Pièce « ' . $params['name'] . ' » mise à jour.');
header('Location: /admin/catalog.php#produit-' . urlencode($ref));
exit;
