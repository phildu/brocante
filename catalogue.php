<?php
// Catalogue public de la boutique, en JSON : ses infos et ses pièces visibles (non masquées, en stock).
// Les galeries commerciales (includes/galleries.php, page /galerie/<identifiant>/) le lisent pour présenter les pièces de plusieurs
// commerces au même endroit — y compris ceux hébergés ailleurs. Aucune donnée privée : uniquement ce que montre déjà la boutique.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/galleries.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$config = tenant();
// Même adresse de la boutique que celle que la galerie calcule pour un commerce de ce serveur (voir gallery_local_shop_url()).
$shopUrl = gallery_local_shop_url(tenant_slug(), $config, $requestOrigin);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode(catalogue_export(db(), $config, $shopUrl, gallery_origin($shopUrl)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
