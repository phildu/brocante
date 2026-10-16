<?php
// Clic sur un visuel promo d'une galerie commerciale : compté, puis redirigé vers la fiche de l'article dans sa boutique.
//   GET g=<galerie> & s=<clé du commerce> & r=<réf. de l'article> & t=<jeton> (jeton produit par la page de la galerie, voir includes/promo.php)
// L'adresse de destination vient du catalogue de la galerie (jamais de la requête) : pas de redirection libre.
require_once __DIR__ . '/../includes/galleries.php';
require_once __DIR__ . '/../includes/promo.php';

$g = strtolower((string) ($_GET['g'] ?? ''));
$shop = (string) ($_GET['s'] ?? '');
$ref = (string) ($_GET['r'] ?? '');
$gallery = gallery_slug_valid($g) ? gallery_load($g) : null;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$target = '';
if ($gallery && $gallery['published']) {
    foreach (gallery_catalogue($gallery, $origin)['products'] as $p) {
        if ($p['shop'] === $shop && $p['ref'] === $ref) { $target = $p['url']; break; }
    }
}
if ($target === '') { header('Location: ' . ($gallery ? '/galerie/' . $g . '/' : '/galerie/'), true, 302); exit; }
$placement = 'galerie:' . $g;
if (promo_token_valid($shop, $ref, $placement, (string) ($_GET['t'] ?? '')) && !promo_is_bot() && promo_rate_ok()) {
    promo_record($shop, $ref, $placement, 0, 1);
}
header('Cache-Control: no-store');
header('Location: ' . $target, true, 302);
exit;
