<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';
require_once dirname(__DIR__, 3) . '/includes/saas.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

if (am_is_entitled() && am_setting('subscription_status') === 'active') {
    plugin_json(['error' => 'Cette boutique est déjà abonnée.'], 409);
}
if (!saas_stripe_configured()) {
    plugin_json(['error' => 'Le paiement n\'est pas encore configuré sur la plateforme. Contactez l\'exploitant, ou démarrez l\'essai gratuit.'], 503);
}

$slug = tenant_slug();
$name = (string) (get_content()['site_name'] ?? tenant('name'));
$cents = am_monthly_cents();
$addon = am_addon_name();

try {
    $session = saas_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'subscription',
        'locale' => 'fr',
        'client_reference_id' => 'addon:' . $slug,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $cents,
                'recurring' => ['interval' => 'month'],
                'product_data' => [
                    'name' => $addon . ' — ' . $name,
                    'description' => 'Option mensuelle : assistant de dépôt Leboncoin / Vinted depuis le catalogue de la boutique.',
                ],
            ],
        ]],
        'metadata' => [
            'type' => 'marketplace_addon',
            'shop' => $slug,
            'plugin' => 'annonces-marche',
        ],
        'subscription_data' => [
            'metadata' => [
                'type' => 'marketplace_addon',
                'shop' => $slug,
                'plugin' => 'annonces-marche',
            ],
        ],
        'success_url' => shop_abs_url('/admin/plugin.php?p=annonces-marche&v=merci') . '&s={CHECKOUT_SESSION_ID}',
        'cancel_url' => shop_abs_url('/admin/plugin.php?p=annonces-marche&v=index'),
    ]);
} catch (Throwable $e) {
    error_log('annonces-marche subscribe: ' . $e->getMessage());
    plugin_json(['error' => 'Impossible d\'ouvrir le paiement : ' . $e->getMessage()], 502);
}

if (empty($session['url'])) plugin_json(['error' => 'Stripe n\'a pas renvoyé d\'adresse de paiement.'], 502);
plugin_json(['ok' => true, 'url' => $session['url']]);
