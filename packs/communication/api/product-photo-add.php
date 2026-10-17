<?php
// POST {ref, path} : ajoute un élément de la médiathèque à la galerie d'une fiche produit (sans copier le fichier).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$in = plugin_input();
$product = get_product((string) ($in['ref'] ?? ''));
if (!$product) plugin_json(['error' => 'Fiche produit introuvable.'], 404);
$item = com_find_item((string) ($in['path'] ?? ''));
if (!$item || $item['missing']) plugin_json(['error' => 'Élément introuvable dans la médiathèque.'], 404);
$dup = db()->prepare('SELECT 1 FROM product_photos WHERE product_ref = ? AND path = ?');
$dup->execute([$product['ref'], $item['path']]);
if ($dup->fetchColumn()) plugin_json(['error' => 'Cette image est déjà dans la galerie de la fiche.'], 409);
add_product_photo($product['ref'], $item['path'], $item['title'] !== '' ? $item['title'] : 'Photo', false, $item['type']);
sync_cover_photo($product['ref']);
plugin_json(['ok' => true]);
