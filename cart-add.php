<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /boutique.php');
    exit;
}

$ref = (string) ($_POST['ref'] ?? '');
$product = get_product($ref);

$redirect = (string) ($_POST['redirect'] ?? '/boutique.php');
if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) $redirect = '/boutique.php';
// « Commander » : le bouton envoie redirect = la page du panier, on y va directement ; sinon un bouton « Commander » accompagne la confirmation.
$toCart = app_unprefix(strtok($redirect, '?#')) === '/cart.php';

if ($product && is_fixed_price(effective_price($product)) && in_stock($product)) {
    cart_add($ref, 1);
    flash_set('« ' . $product['name'] . ' » ajouté au panier.', 'ok', $toCart ? null : ['label' => 'Commander', 'url' => '/cart.php']);
}
header('Location: ' . $redirect);
exit;
