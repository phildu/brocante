<?php
// Clic sur un visuel promo de la page d'accueil de la boutique : compté, puis redirigé vers la fiche de l'article.
//   GET r=<réf. de l'article> & p=accueil & t=<jeton> (jeton produit par la page, voir includes/promo.php)
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/promo.php';

$ref = (string) ($_GET['r'] ?? '');
$placement = (string) ($_GET['p'] ?? '');
$token = (string) ($_GET['t'] ?? '');
$shop = tenant_slug();
if ($ref !== '' && $placement === 'accueil' && promo_token_valid($shop, $ref, $placement, $token) && !promo_is_bot() && promo_rate_ok()) {
    promo_record($shop, $ref, $placement, 0, 1);
}
header('Cache-Control: no-store');
header('Location: ' . app_prefix('/produit.php') . '?ref=' . rawurlencode($ref), true, 302);
exit;
