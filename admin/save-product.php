<?php
require_once __DIR__ . '/../includes/session.php';
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
$nature = product_nature_resolve($_POST['nature'] ?? '', $_POST['sous_categorie'] ?? '');

$badge = trim((string) ($_POST['badge'] ?? $product['badge']));
// Le champ n'est affiché (et donc soumis) que si la mention est "Promo" —
// hors de ce cas le prix promo n'a pas de sens et repart à vide.
$promoPrice = $badge === 'Promo' ? price_from_input($_POST['promo_price'] ?? '') : '';
$promoNote = '';
$newPrice = price_from_input($_POST['price'] ?? null, (string) $product['price']);
// Le prix promo est une remise : il doit rester sous le prix normal, sinon il est ignoré.
if ($promoPrice !== '' && (price_to_cents($promoPrice) ?? 0) >= (price_to_cents($newPrice) ?? 0)) {
    $promoNote = ' Prix promo ignoré : il doit être inférieur au prix (' . $newPrice . ').';
    $promoPrice = '';
}

$set = 'name = :name, cat = :cat, description = :description, materials = :materials, colors = :colors, brand = :brand, distributor = :distributor, etat = :etat, nature = :nature, sous_categorie = :sous_categorie, price = :price, promo_price = :promo_price, badge = :badge, size_text = :size_text, weight_text = :weight_text, weight_grams = :weight_grams, featured = :featured, is_hidden = :is_hidden, stock = :stock, lot_qty = :lot_qty, channels = :channels';
$params = [
    'name' => trim((string) ($_POST['name'] ?? $product['name'])),
    'cat' => $cat,
    'description' => trim((string) ($_POST['description'] ?? $product['description'])),
    'materials' => trim((string) ($_POST['materials'] ?? $product['materials'])),
    'colors' => product_colors_clean((string) ($_POST['colors'] ?? ($product['colors'] ?? ''))),
    'brand' => mb_substr(trim((string) ($_POST['brand'] ?? ($product['brand'] ?? ''))), 0, 80),
    'distributor' => mb_substr(trim((string) ($_POST['distributor'] ?? ($product['distributor'] ?? ''))), 0, 80),
    'etat' => isset($_POST['etat']) ? (product_condition_key($_POST['etat']) ?: null) : $product['etat'],
    'nature' => isset($_POST['nature']) ? ($nature['nature'] ?: null) : $product['nature'],
    'sous_categorie' => isset($_POST['nature']) ? ($nature['sous_categorie'] ?: null) : $product['sous_categorie'],
    'price' => $newPrice,
    'promo_price' => $promoPrice !== '' ? $promoPrice : null,
    'badge' => $badge,
    'size_text' => trim((string) ($_POST['size_text'] ?? $product['size_text'])),
    'weight_text' => trim((string) ($_POST['weight_text'] ?? $product['weight_text'])),
    'weight_grams' => isset($_POST['weight_grams']) ? max(0, (int) $_POST['weight_grams']) : $product['weight_grams'],
    'featured' => $featuredRequested ? 1 : 0,
    'is_hidden' => !empty($_POST['is_hidden']) ? 1 : 0,
    'stock' => isset($_POST['stock']) ? max(0, (int) $_POST['stock']) : $product['stock'],
    'channels' => isset($_POST['channels_form']) ? (implode(',', product_channel_keys(implode(',', (array) ($_POST['channels'] ?? []))))) : ($product['channels'] ?? null),
    'lot_qty' => isset($_POST['lot_qty']) ? max(1, min(999, (int) $_POST['lot_qty'])) : max(1, (int) ($product['lot_qty'] ?? 1)),
    'ref' => $ref,
];
// Gestion du prix de vente (formulaire du catalogue : « price_fields » signale que les cases à cocher sont à lire, décochées comprises).
if (!empty($_POST['price_fields'])) {
    $floor = price_from_input($_POST['price_floor'] ?? '');
    if ($floor !== '' && (price_to_cents($floor) ?? 0) > (price_to_cents($newPrice) ?? 0)) {
        $promoNote .= ' Prix de vente minimum ignoré : il dépasse le prix affiché (' . $newPrice . ').';
        $floor = '';
    }
    $estMin = pricing_euros($_POST['est_min'] ?? '');
    $estMax = pricing_euros($_POST['est_max'] ?? '');
    if ($estMin === null && $estMax !== null) [$estMin, $estMax] = [$estMax, null];
    if ($estMin !== null && $estMax !== null && $estMax < $estMin) [$estMin, $estMax] = [$estMax, $estMin];
    $newUrl = pricing_safe_url($_POST['new_url'] ?? '');
    if (trim((string) ($_POST['new_url'] ?? '')) !== '' && $newUrl === '') $promoNote .= " Lien du neuf ignoré : adresse non valide (http ou https attendu).";
    $set .= ', price_floor = :price_floor, est_min = :est_min, est_max = :est_max, est_show = :est_show, negotiable = :negotiable, new_url = :new_url, new_price = :new_price, new_show = :new_show';
    $params += ['price_floor' => $floor !== '' ? $floor : null, 'est_min' => $estMin, 'est_max' => $estMax, 'est_show' => !empty($_POST['est_show']) && $estMin !== null ? 1 : 0,
        'negotiable' => !empty($_POST['negotiable']) ? 1 : 0, 'new_url' => $newUrl !== '' ? $newUrl : null, 'new_price' => price_from_input($_POST['new_price'] ?? '') ?: null,
        'new_show' => !empty($_POST['new_show']) && $newUrl !== '' ? 1 : 0];
}
if (isset($_POST['barcode'])) {
    $set .= ', barcode = :barcode';
    $params['barcode'] = product_barcode_clean($_POST['barcode']) ?: null;
}
if ($photoPath) {
    $set .= ', photo = :photo';
    $params['photo'] = $photoPath;
}

$stmt = db()->prepare("UPDATE products SET $set WHERE ref = :ref");
$stmt->execute($params);

// Canal « Leboncoin » coché / décoché : la pièce entre dans / sort de la sélection d'export (liste des articles à publier du bot).
if (isset($_POST['channels_form'])) {
    $wasLbc = in_array('leboncoin', product_channels_of($product), true);
    $isLbc = in_array('leboncoin', product_channels_of($params), true);
    if ($wasLbc !== $isLbc) {
        try {
            if (db()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'am_export_selection'")->fetchColumn()) {
                if ($isLbc) {
                    db()->prepare('INSERT INTO am_export_selection (product_ref) VALUES (?) ON CONFLICT(product_ref) DO NOTHING')->execute([$ref]);
                } else {
                    db()->prepare('DELETE FROM am_export_selection WHERE product_ref = ?')->execute([$ref]);
                }
            }
        } catch (Throwable $e) {
            // sélection indisponible (plugin absent) : le canal reste enregistré sur la fiche
        }
    }
}

flash_set('Pièce « ' . $params['name'] . ' » mise à jour.' . $promoNote, $promoNote !== '' ? 'error' : 'ok');
header('Location: /admin/catalog.php' . (empty($_POST['close']) ? '#produit-' . urlencode($ref) : ''));
exit;
