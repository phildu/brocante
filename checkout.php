<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /cart.php');
    exit;
}

$lines = cart_lines();
if (!$lines) {
    header('Location: /cart.php');
    exit;
}

if (!stripe_configured()) {
    flash_set("Le paiement n'est pas encore configuré — ajoutez vos clés Stripe dans config.php.", 'error');
    header('Location: /cart.php');
    exit;
}

$content = get_content();

// Chaque option porte une clé ('pickup', 'rate-<id>', 'flat') qui correspond
// à la valeur du bouton radio choisi dans le panier (voir cart.php) — sert
// uniquement à mettre l'option choisie en tête de liste, Stripe présélectionne
// alors la première option de la liste ; le client peut toujours en changer
// sur la page de paiement elle-même.
$shippingOptions = [
    'pickup' => [
        'shipping_rate_data' => [
            'type' => 'fixed_amount',
            'fixed_amount' => ['amount' => 0, 'currency' => 'eur'],
            'display_name' => $content['pickup_label'],
        ],
    ],
];

// Paliers de transporteurs correspondant au poids ET à la destination du
// panier (code postal saisi dans cart.php, voir shipping_zone_for_address())
// — sinon repli sur le tarif fixe des réglages du site (voir
// includes/admin-nav.php -> Frais de port pour la configuration des
// transporteurs).
$destCountry = (string) ($_SESSION['shipping_country'] ?? 'FR');
$destPostalCode = (string) ($_SESSION['shipping_postal_code'] ?? '');
$destZone = shipping_zone_for_address($destCountry, $destPostalCode);
$carrierRates = matching_shipping_rates(cart_total_weight_g($lines), $destZone);
if ($carrierRates) {
    foreach ($carrierRates as $rate) {
        $label = $rate['carrier_name'] . ' — ' . $rate['service_name'];
        if ($rate['delivery_delay'] !== '') {
            $label .= ' (' . $rate['delivery_delay'] . ')';
        }
        $shippingOptions['rate-' . $rate['id']] = [
            'shipping_rate_data' => [
                'type' => 'fixed_amount',
                'fixed_amount' => ['amount' => (int) $rate['price_cents'], 'currency' => 'eur'],
                'display_name' => $label,
            ],
        ];
    }
} else {
    $shippingOptions['flat'] = [
        'shipping_rate_data' => [
            'type' => 'fixed_amount',
            'fixed_amount' => ['amount' => price_to_cents($content['shipping_fee']) ?? 0, 'currency' => 'eur'],
            'display_name' => $content['shipping_label'],
        ],
    ];
}

// Met en tête l'option choisie dans le panier, si elle existe encore
// (le poids a pu changer entre-temps) — sinon l'ordre par défaut reste.
$chosenKey = (string) ($_POST['shipping_choice'] ?? '');
if ($chosenKey !== '' && isset($shippingOptions[$chosenKey])) {
    $shippingOptions = [$chosenKey => $shippingOptions[$chosenKey]] + $shippingOptions;
}
$shippingOptions = array_values($shippingOptions);

$params = [
    'mode' => 'payment',
    'success_url' => SITE_URL . '/checkout-success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => SITE_URL . '/cart.php',
    'shipping_address_collection' => ['allowed_countries' => array_keys(shipping_allowed_countries())],
    'shipping_options' => $shippingOptions,
];

$i = 0;
foreach ($lines as $line) {
    $p = $line['product'];
    $params['line_items'][$i]['quantity'] = $line['qty'];
    $params['line_items'][$i]['price_data']['currency'] = 'eur';
    $params['line_items'][$i]['price_data']['unit_amount'] = $line['unit_cents'];
    $params['line_items'][$i]['price_data']['product_data']['name'] = $p['name'];
    if (!empty($p['photo'])) {
        $params['line_items'][$i]['price_data']['product_data']['images'][0] = SITE_URL . '/' . $p['photo'];
    }
    $i++;
}

try {
    $session = stripe_request('POST', '/checkout/sessions', $params);
} catch (Throwable $e) {
    flash_set('Impossible de créer la session de paiement : ' . $e->getMessage(), 'error');
    header('Location: /cart.php');
    exit;
}

$itemsSnapshot = array_map(function ($line) {
    return [
        'ref' => $line['product']['ref'],
        'name' => $line['product']['name'],
        'qty' => $line['qty'],
        'unit_cents' => $line['unit_cents'],
    ];
}, $lines);

$stmt = db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, status, items) VALUES (?, ?, ?, ?)');
$stmt->execute([
    $session['id'],
    cart_total_cents(),
    'pending',
    json_encode($itemsSnapshot, JSON_UNESCAPED_UNICODE),
]);

header('Location: ' . $session['url'], true, 303);
exit;
