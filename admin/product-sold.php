<?php
// Fiche produit (Catalogue) : vendu / réassort / maintien au catalogue.
//   sold     : stock à 0, avec le canal de vente (facultatif) et la date ; sort de la boutique, sauf si « maintenue au catalogue »
//   reassort : ajoute la quantité indiquée au stock (une pièce vendue est remise en vente)
//   restock  : (ancien bouton) stock à 1
//   keep     : bascule « maintenir au catalogue même vendue » (badge « Vendue » sur la fiche et dans la boutique)
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
    flash_set('Pièce introuvable.', 'error');
    header('Location: /admin/catalog.php');
    exit;
}

$name = (string) $product['name'];
$action = (string) ($_POST['sold_action'] ?? '');

if ($action === 'sold') {
    $channel = (string) ($_POST['sold_channel'] ?? '');
    $channel = array_key_exists($channel, product_channels()) ? $channel : null;
    db()->prepare('UPDATE products SET stock = 0, sold_channel = ?, sold_at = CURRENT_TIMESTAMP WHERE ref = ?')->execute([$channel, $ref]);
    flash_set('Pièce « ' . $name . ' » marquée vendue' . ($channel ? ' (' . product_channels()[$channel] . ')' : '') . ' : '
        . (product_keeps_when_sold($product) ? 'elle reste visible au catalogue avec le badge « Vendue ».' : 'elle ne figure plus dans la boutique.'));
} elseif ($action === 'reassort') {
    $qty = max(1, min(999, (int) ($_POST['restock_qty'] ?? 1)));
    $wasSold = (int) $product['stock'] <= 0;
    db()->prepare('UPDATE products SET stock = MIN(stock + ?, 9999), restocked_at = CURRENT_TIMESTAMP' . ($wasSold ? ', sold_channel = NULL, sold_at = NULL' : '') . ' WHERE ref = ?')->execute([$qty, $ref]);
    flash_set('Réassort de « ' . $name . ' » : +' . $qty . ($wasSold ? ' — la pièce est de nouveau en vente.' : '.'));
} elseif ($action === 'restock') {
    db()->prepare('UPDATE products SET stock = 1, restocked_at = CURRENT_TIMESTAMP, sold_channel = NULL, sold_at = NULL WHERE ref = ?')->execute([$ref]);
    flash_set('Pièce « ' . $name . ' » remise en vente (stock : 1).');
} elseif ($action === 'keep') {
    $keep = product_keeps_when_sold($product) ? 0 : 1;
    db()->prepare('UPDATE products SET keep_when_sold = ? WHERE ref = ?')->execute([$keep, $ref]);
    flash_set($keep ? 'Pièce « ' . $name . ' » maintenue au catalogue, même vendue.' : 'Pièce « ' . $name . ' » : une fois vendue, elle quittera la boutique.');
} else {
    flash_set('Action inconnue.', 'error');
}

header('Location: /admin/catalog.php#produit-' . urlencode($ref));
exit;
