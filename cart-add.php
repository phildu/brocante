<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /boutique.php');
    exit;
}

$ref = (string) ($_POST['ref'] ?? '');
$product = get_product($ref);

if ($product && is_fixed_price(effective_price($product)) && in_stock($product)) {
    cart_add($ref, 1);
    flash_set('« ' . $product['name'] . ' » ajouté au panier.');
}

$redirect = (string) ($_POST['redirect'] ?? '/boutique.php');
if (!str_starts_with($redirect, '/')) $redirect = '/boutique.php';
header('Location: ' . $redirect);
exit;
