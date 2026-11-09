<?php

// Logos des moyens de paiement acceptés, affichés dans le pied de page et au
// panier. La liste suit shop_payment_methods() : un fournisseur configuré mais
// décoché dans les réglages n'apparaît pas.

require_once __DIR__ . '/payments.php';

function payment_badge_labels(): array
{
    return [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'cb' => 'Carte Bancaire',
        'paypal' => 'PayPal',
        'wero' => 'Wero',
        'sepa' => 'Virement SEPA',
    ];
}

function payment_badge_file(string $key): string
{
    return dirname(__DIR__) . '/assets/payment-methods/' . $key . '.svg';
}

/** Logos à afficher, dédupliqués et limités aux fichiers réellement présents. */
function payment_badge_keys(): array
{
    $perProvider = [
        'stripe' => ['visa', 'mastercard', 'cb'],
        'monetico' => ['visa', 'mastercard', 'cb'],
        'revolut' => ['visa', 'mastercard', 'cb'],
        'sumup' => ['visa', 'mastercard', 'cb'],
        'paypal' => ['paypal'],
        'sepa' => ['sepa'],
    ];
    $providers = function_exists('shop_payment_methods') ? shop_payment_methods() : [];

    $keys = [];
    foreach (array_keys($providers) as $provider) {
        $logos = $perProvider[$provider] ?? [];
        if ($provider === 'stripe' && function_exists('stripe_wero_enabled') && stripe_wero_enabled()) $logos[] = 'wero';
        foreach ($logos as $key) {
            if (!in_array($key, $keys, true) && is_file(payment_badge_file($key))) $keys[] = $key;
        }
    }
    return $keys;
}

function payment_badges_html(string $label = 'Paiement sécurisé', int $height = 26): string
{
    $keys = payment_badge_keys();
    if (!$keys) return '';

    $labels = payment_badge_labels();
    $html = '<div class="payment-badges" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">';
    if ($label !== '') {
        $html .= '<span class="payment-badges-label" style="font-size:0.68rem;letter-spacing:0.14em;text-transform:uppercase;opacity:0.6;">' . h($label) . '</span>';
    }
    foreach ($keys as $key) {
        $file = payment_badge_file($key);
        $html .= '<img src="/assets/payment-methods/' . h($key) . '.svg?v=' . (int) @filemtime($file) . '"'
            . ' alt="' . h($labels[$key] ?? $key) . '"'
            . ' width="' . (int) round($height * 72 / 44) . '" height="' . $height . '"'
            . ' style="height:' . $height . 'px;width:auto;border-radius:4px;" loading="lazy" decoding="async">';
    }
    return $html . '</div>';
}
