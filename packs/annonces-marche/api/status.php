<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';
require_once dirname(__DIR__, 3) . '/includes/saas.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

$ent = am_entitlement();
$listings = (int) db()->query('SELECT COUNT(*) FROM am_listings')->fetchColumn();
$shopUrl = shop_abs_url('/');
plugin_json([
    'ok' => true,
    'shop' => [
        'slug' => tenant_slug(),
        'name' => (string) (get_content()['site_name'] ?? tenant('name')),
        'url' => $shopUrl,
        'api_base' => rtrim($shopUrl, '/') . '/api/annonces',
    ],
    'entitlement' => $ent,
    'stripe_ready' => saas_stripe_configured(),
    'listings_count' => $listings,
]);
