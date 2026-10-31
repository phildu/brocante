<?php
// Ajout au panier : signal d'intention pour le vendeur (désactivé par défaut dans les réglages).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

if (!av_active() || !av_bool('alert_cart_add')) return;
$ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['ref'] ?? ''));
if ($ref === '') return;
$token = av_session_token(false);
if ($token === '') return;
$product = av_product_info($ref);
if (!$product) return;
if (av_recent_alert($token, 'cart_add', $ref, max(5, (int) av_setting('cooldown_minutes', '20')))) return;
av_alert_create([
    'visitor' => $token,
    'kind' => 'cart_add',
    'ref' => $ref,
    'product_name' => $product['name'],
    'detail' => 'Ajoutée au panier' . ($product['price'] !== '' ? ' · ' . $product['price'] : ''),
]);