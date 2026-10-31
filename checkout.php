<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins.php';
if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}
plugin_fire('front_view');   // prix des ventes flash à jour avant de calculer la commande

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /cart.php');
    exit;
}

// Formulaire sans le jeton de la session (autre site, page restée ouverte trop longtemps) : pas de passage à la caisse.
if (!cart_csrf_valid()) {
    flash_set("La page avait expiré : recommencez le passage à la caisse, s'il vous plaît.", 'error');
    header('Location: /cart.php');
    exit;
}

// Pièce du panier payée ailleurs sans retour sur le site : sortie du stock avant de recalculer le panier.
if (shop_checkout_ready()) orders_settle_pending_for(array_keys(cart_get()));

$lines = cart_lines();
if (!$lines) {
    header('Location: /cart.php');
    exit;
}

// Coordonnées du panier : e-mail, téléphone, acceptation des conditions (contrôlés ici, côté serveur) ; mémorisés pour réafficher le panier.
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/includes/oauth.php';
$custSess = customer_session();
$contact = [
    'name' => mb_substr(trim((string) ($_POST['contact_name'] ?? '')), 0, 80),
    'email' => mb_strtolower(trim((string) ($custSess['email'] ?? $_POST['contact_email'] ?? ''))),
    'phone' => mb_substr(trim((string) ($_POST['contact_phone'] ?? '')), 0, 30),
    'create_account' => !$custSess && !empty($_POST['create_account']),
    // Livraison pour une autre personne : nom et téléphone du destinataire (l'adresse, elle, se saisit chez Stripe).
    'ship_other' => !empty($_POST['ship_other']),
    'ship_name' => mb_substr(trim((string) ($_POST['ship_name'] ?? '')), 0, 80),
    'ship_phone' => mb_substr(trim((string) ($_POST['ship_phone'] ?? '')), 0, 30),
];
$contactErrors = [];
if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($contact['email']) > 120) $contactErrors['email'] = 'Indiquez une adresse e-mail valide.';
$phoneNorm = customer_phone_normalize($contact['phone'], (string) ($_SESSION['shipping_country'] ?? 'FR'));
if ($phoneNorm === null) $contactErrors['phone'] = 'Indiquez un numéro de téléphone valide (par exemple 06 12 34 56 78, ou +32 470 12 34 56 hors de France).';
$shipPhoneNorm = null;
if ($contact['ship_other']) {
    if (mb_strlen($contact['ship_name']) < 2) $contactErrors['ship_name'] = 'Indiquez le nom du destinataire.';
    $shipPhoneNorm = customer_phone_normalize($contact['ship_phone'], (string) ($_SESSION['shipping_country'] ?? 'FR'));
    if ($shipPhoneNorm === null) $contactErrors['ship_phone'] = 'Indiquez un téléphone valide pour le destinataire (par exemple 06 98 76 54 32).';
}
if (empty($_POST['accept_terms'])) $contactErrors['terms'] = "Pour payer, acceptez les conditions générales de vente, les conditions d'utilisation et la politique de confidentialité.";
// Adresse de livraison saisie au panier (recopiée dans le formulaire de paiement ; à défaut, celle de la session). Exigée sauf retrait sur place.
$dest = [
    'country' => strtoupper(trim((string) ($_POST['dest_country'] ?? ''))) ?: (string) ($_SESSION['shipping_country'] ?? 'FR'),
    'line1' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['dest_line1'] ?? ($_SESSION['shipping_line1'] ?? '')))), 0, 120),
    'line2' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['dest_line2'] ?? ($_SESSION['shipping_line2'] ?? '')))), 0, 120),
    'postal' => mb_substr(trim((string) (($_POST['dest_postal'] ?? '') !== '' ? $_POST['dest_postal'] : ($_SESSION['shipping_postal_code'] ?? ''))), 0, 12),
    'city' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['dest_city'] ?? ($_SESSION['shipping_city'] ?? '')))), 0, 80),
];
if (!array_key_exists($dest['country'], shipping_allowed_countries())) $dest['country'] = 'FR';
$needsAddress = (string) ($_POST['shipping_choice'] ?? 'pickup') !== 'pickup';
if ($needsAddress) {
    $addrProblems = [];
    if (mb_strlen($dest['line1']) < 3) $addrProblems[] = "l'adresse (numéro et rue)";
    if ($dest['postal'] === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,10}$/', $dest['postal']) || ($dest['country'] === 'FR' && !preg_match('/^\d{5}$/', $dest['postal']))) $addrProblems[] = 'le code postal' . ($dest['country'] === 'FR' ? ' (5 chiffres)' : '');
    if (mb_strlen($dest['city']) < 2) $addrProblems[] = 'la ville';
    if ($addrProblems) $contactErrors['addr'] = 'Complétez l\'adresse de livraison : ' . implode(', ', $addrProblems) . '.';
}
// L'adresse validée devient celle de la session (zone tarifaire et frais de livraison calculés sur elle).
$_SESSION['shipping_country'] = $dest['country'];
$_SESSION['shipping_line1'] = $dest['line1']; $_SESSION['shipping_line2'] = $dest['line2']; $_SESSION['shipping_city'] = $dest['city'];
if ($dest['postal'] !== '') $_SESSION['shipping_postal_code'] = preg_replace('/[^0-9A-Za-z]/', '', $dest['postal']);
$_SESSION['checkout_contact'] = $contact;
if ($contactErrors) {
    $_SESSION['checkout_contact_errors'] = $contactErrors;
    flash_set('Vérifiez vos coordonnées : ' . implode(' ', $contactErrors), 'error');
    header('Location: /cart.php#checkout-contact');
    exit;
}
$termsVersion = legal_terms_version();

if (!shop_checkout_ready()) {
    flash_set("Le paiement n'est pas encore configuré — ajoutez vos clés Stripe dans config.php.", 'error');
    header('Location: /cart.php');
    exit;
}

// Pièce en cours de paiement par un autre client (sa page Stripe est encore ouverte) : pas de deuxième paiement en parallèle.
$held = cart_held_by_others();
foreach ($lines as $line) {
    if ($line['qty'] > product_free_qty($line['product'], $held)) {
        flash_set(product_held_message($line['product']['name']), 'error');
        header('Location: /cart.php');
        exit;
    }
}
checkout_expire_own_sessions();

$content = get_content();
// Dons (0 €) et articles sous le seuil de l'administration : retrait uniquement, jamais d'envoi (contrôle côté serveur).
$pickupOnly = cart_pickup_only($lines, $content);
// Remise du panier (paliers de l'administration) : appliquée à Stripe comme un bon de réduction à usage unique.
$discount = cart_discount_for(cart_total_cents(), cart_total_qty($lines));

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
$carrierRates = $pickupOnly ? [] : matching_shipping_rates(cart_total_weight_g($lines), $destZone);
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
} elseif (!$pickupOnly) {
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
    // Page de paiement fermée après CHECKOUT_HOLD_MINUTES : la réservation des pièces ne dure pas plus longtemps.
    'expires_at' => time() + CHECKOUT_HOLD_MINUTES * 60,
];

// Stripe Connect : si le compte Stripe de la boutique est relié à la plateforme, le paiement passe par la plateforme qui reverse la vente au commerçant
// et garde la commission (includes/connect.php). Sinon, paiement habituel avec les clés de la boutique.
$connect = connect_plan(cart_total_cents() - $discount['cents']);
if ($connect) {
    $params['payment_intent_data'] = ['application_fee_amount' => $connect['fee'], 'transfer_data' => ['destination' => $connect['account']], 'metadata' => ['shop' => tenant_slug()]];
}

if ($discount['cents'] > 0) {
    try {
        $coupon = stripe_request('POST', '/coupons', [
            'amount_off' => $discount['cents'], 'currency' => 'eur', 'duration' => 'once', 'max_redemptions' => 1,
            'name' => mb_substr(cart_discount_label($discount['tier']), 0, 40),
        ], (bool) $connect);
        $params['discounts'][0]['coupon'] = $coupon['id'];
    } catch (Throwable $e) {
        flash_set("Impossible d'appliquer la remise : " . $e->getMessage(), 'error');
        header('Location: /cart.php');
        exit;
    }
}

// Client connecté par un réseau social : son adresse e-mail est préremplie sur la page de paiement.
require_once __DIR__ . '/includes/oauth.php';
// L'e-mail et l'adresse de livraison saisis au panier sont préremplis sur la page de paiement (client Stripe portant l'adresse ; modifiable par le client).
$params['customer_email'] = $contact['email'];
if ($needsAddress) {
    try {
        $recipient = $contact['ship_other'] ? $contact['ship_name'] : $contact['name'];
        $cust = ['email' => $contact['email'], 'name' => $contact['name'], 'phone' => $phoneNorm,
            'shipping' => ['name' => $recipient !== '' ? $recipient : $contact['email'], 'phone' => $contact['ship_other'] ? $shipPhoneNorm : $phoneNorm,
                'address' => ['line1' => $dest['line1'], 'line2' => $dest['line2'], 'city' => $dest['city'], 'postal_code' => $dest['postal'], 'country' => $dest['country']]]];
        $stripeCustomer = stripe_request('POST', '/customers', $cust, (bool) $connect);
        if (!empty($stripeCustomer['id'])) {
            unset($params['customer_email']);
            $params['customer'] = $stripeCustomer['id'];
            $params['customer_update'] = ['shipping' => 'auto'];
        }
    } catch (Throwable $e) {
        error_log('checkout prefill client Stripe : ' . $e->getMessage());       // sans incidence : la page de paiement redemande l'adresse
    }
}
$declaredAddress = $needsAddress ? implode(', ', array_filter([$contact['ship_other'] ? $contact['ship_name'] : $contact['name'], $dest['line1'], $dest['line2'], trim($dest['postal'] . ' ' . $dest['city']), $dest['country']])) : null;

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
    $session = stripe_request('POST', '/checkout/sessions', $params, (bool) $connect);
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

$stmt = db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, status, items, payment_via, connect_fee_cents, connect_gallery_cents, email, phone, terms_at, terms_version, create_account, ship_name, ship_phone, declared_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $session['id'],
    cart_total_cents() - $discount['cents'],
    'pending',
    json_encode($itemsSnapshot, JSON_UNESCAPED_UNICODE),
    $connect ? 'connect' : null,
    $connect ? $connect['fee'] : null,
    $connect ? $connect['gallery_cents'] : null,
    $contact['email'],
    $phoneNorm,
    gmdate('c'),
    $termsVersion,
    $contact['create_account'] ? 1 : 0,
    $contact['ship_other'] ? $contact['ship_name'] : null,
    $contact['ship_other'] ? $shipPhoneNorm : null,
    $declaredAddress,
]);
$orderId = (int) db()->lastInsertId();
checkout_remember_order($orderId);
// « Créer mon compte » : lien pour choisir son mot de passe (le compte n'existe qu'une fois l'adresse confirmée par ce lien).
if ($contact['create_account']) {
    try {
        $existingAcc = customer_account($contact['email']);
        if (!$existingAcc || empty($existingAcc['password_hash'])) customer_send_link($contact['email'], $contact['name'], (string) $phoneNorm, 'setup');
    } catch (Throwable $e) {
        error_log('checkout create_account : ' . $e->getMessage());
    }
}
plugin_fire('checkout_created', ['order_id' => $orderId, 'code' => (string) ($_SESSION['reward_code'] ?? '')]);   // réserve la récompense jusqu'au paiement

header('Location: ' . $session['url'], true, 303);
exit;
