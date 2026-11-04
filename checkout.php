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
$firstName = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['contact_first_name'] ?? ''))), 0, 40);
$lastName = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['contact_last_name'] ?? ''))), 0, 40);
if ($firstName === '' && $lastName === '') {   // formulaire resté ouvert avant l'ajout des deux champs
    [$firstName, $lastName] = contact_name_parts((string) ($_POST['contact_name'] ?? ''));
    $firstName = mb_substr($firstName, 0, 40);
    $lastName = mb_substr($lastName, 0, 40);
}
$contact = [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'name' => mb_substr(trim($firstName . ' ' . $lastName), 0, 80),
    'email' => mb_strtolower(trim((string) ($custSess['email'] ?? $_POST['contact_email'] ?? ''))),
    'phone' => mb_substr(trim((string) ($_POST['contact_phone'] ?? '')), 0, 30),
    'create_account' => !empty($_POST['create_account']),   // compte e-mail, y compris pour une session ouverte par un réseau social (sans mot de passe)
    // Livraison pour une autre personne : nom et téléphone du destinataire (l'adresse, elle, est saisie au panier).
    'ship_other' => !empty($_POST['ship_other']),
    'ship_name' => mb_substr(trim((string) ($_POST['ship_name'] ?? '')), 0, 80),
    'ship_phone' => mb_substr(trim((string) ($_POST['ship_phone'] ?? '')), 0, 30),
];
$contactErrors = [];
if ($contact['first_name'] === '') $contactErrors['first_name'] = 'Indiquez votre prénom.';
if ($contact['last_name'] === '') $contactErrors['last_name'] = 'Indiquez votre nom.';
if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($contact['email']) > 120) $contactErrors['email'] = 'Indiquez une adresse e-mail valide.';
$clean = static fn (string $value, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, $max);
// Adresse de l'acheteur : toujours contrôlée, même pour un retrait sur place.
$buyer = [
    'country' => strtoupper(trim((string) ($_POST['buyer_country'] ?? ($_SESSION['buyer_country'] ?? 'FR')))) ?: 'FR',
    'line1' => $clean((string) ($_POST['buyer_line1'] ?? ($_SESSION['buyer_line1'] ?? '')), 120),
    'line2' => '',
    'postal' => $clean((string) ($_POST['buyer_postal'] ?? ($_SESSION['buyer_postal'] ?? '')), 12),
    'city' => $clean((string) ($_POST['buyer_city'] ?? ($_SESSION['buyer_city'] ?? '')), 80),
];
if (!array_key_exists($buyer['country'], shipping_allowed_countries())) $buyer['country'] = 'FR';
$buyerLabel = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['buyer_label'] ?? ($_SESSION['buyer_address_label'] ?? '')))), 0, 160);
$_SESSION['buyer_country'] = $buyer['country'];
$_SESSION['buyer_line1'] = $buyer['line1']; $_SESSION['buyer_postal'] = preg_replace('/[^0-9A-Za-z]/', '', $buyer['postal']); $_SESSION['buyer_city'] = $buyer['city'];
if ($buyerLabel !== '' && $buyer['postal'] !== '') $_SESSION['buyer_address_label'] = $buyerLabel; else unset($_SESSION['buyer_address_label']);
$buyerProblems = [];
if (mb_strlen($buyer['line1']) < 3) $buyerProblems[] = "l'adresse (numéro et rue)";
if ($buyer['postal'] === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,10}$/', $buyer['postal']) || ($buyer['country'] === 'FR' && !preg_match('/^\d{5}$/', $buyer['postal']))) $buyerProblems[] = 'le code postal' . ($buyer['country'] === 'FR' ? ' (5 chiffres)' : '');
if (mb_strlen($buyer['city']) < 2) $buyerProblems[] = 'la ville';
if ($buyerProblems) $contactErrors['buyer_addr'] = 'Complétez votre adresse : ' . implode(', ', $buyerProblems) . '.';

$phoneNorm = customer_phone_normalize($contact['phone'], $buyer['country']);
if ($phoneNorm === null) $contactErrors['phone'] = 'Indiquez un numéro de téléphone valide (par exemple 06 12 34 56 78, ou +32 470 12 34 56 hors de France).';
$shipPhoneNorm = null;
$needsAddress = (string) ($_POST['shipping_choice'] ?? 'pickup') !== 'pickup';
$shipOther = $contact['ship_other'] && $needsAddress;
$contact['ship_other'] = $shipOther;
// Adresse de livraison : celle du destinataire s'il est distinct, sinon celle de l'acheteur.
$dest = $buyer;
if ($shipOther) {
    $dest = [
        'country' => strtoupper(trim((string) ($_POST['dest_country'] ?? ''))) ?: (string) ($_SESSION['shipping_country'] ?? 'FR'),
        'line1' => $clean((string) ($_POST['dest_line1'] ?? ($_SESSION['shipping_line1'] ?? '')), 120),
        'line2' => '',   // rue et complément sont réunis sur la ligne 1 du panier
        'postal' => $clean((string) (($_POST['dest_postal'] ?? '') !== '' ? $_POST['dest_postal'] : ($_SESSION['shipping_postal_code'] ?? '')), 12),
        'city' => $clean((string) ($_POST['dest_city'] ?? ($_SESSION['shipping_city'] ?? '')), 80),
    ];
    if (!array_key_exists($dest['country'], shipping_allowed_countries())) $dest['country'] = 'FR';
}
if ($shipOther) {
    if (mb_strlen($contact['ship_name']) < 2) $contactErrors['ship_name'] = 'Indiquez le nom du destinataire.';
    $shipPhoneNorm = customer_phone_normalize($contact['ship_phone'], $dest['country']);
    if ($shipPhoneNorm === null) $contactErrors['ship_phone'] = 'Indiquez un téléphone valide pour le destinataire (par exemple 06 98 76 54 32).';
}
if (empty($_POST['accept_terms'])) $contactErrors['terms'] = "Pour payer, acceptez les conditions générales de vente, les conditions d'utilisation et la politique de confidentialité.";
// Le panier réaffiche l'adresse et le destinataire si le paiement revient en arrière (validation, erreur Stripe).
$_SESSION['shipping_ui'] = $needsAddress ? (string) ($_POST['shipping_choice'] ?? 'flat') : 'pickup';
if ($shipOther) {
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
cart_contact_cookie_remember($contact);
if ($contactErrors) {
    $_SESSION['checkout_contact_errors'] = $contactErrors;
    flash_set('Vérifiez vos coordonnées : ' . implode(' ', $contactErrors), 'error');
    header('Location: /cart.php#checkout-contact');
    exit;
}
$termsVersion = legal_terms_version();

if (!shop_checkout_ready()) {
    flash_set("Le paiement n'est pas encore configuré — ajoutez un moyen de paiement dans Administration → Réglages.", 'error');
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
// Emballage cadeau : l'option doit être active et le prix est recalculé ici, jamais repris du formulaire.
$giftWrapEnabled = gift_wrap_enabled($content);
$giftWrap = $giftWrapEnabled && !empty($_POST['gift_wrap']);
$giftWrapCents = $giftWrap ? gift_wrap_price_cents($content) : 0;
$giftMessage = '';
if ($giftWrap) {
    $giftMessage = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['gift_message'] ?? ''))), 0, 200);
}
$_SESSION['gift_wrap'] = $giftWrap ? 1 : 0;
if ($giftWrap && $giftMessage !== '') $_SESSION['gift_message'] = $giftMessage; else unset($_SESSION['gift_message']);
// Dons (0 €) et articles sous le seuil de l'administration : retrait uniquement, jamais d'envoi (contrôle côté serveur).
$pickupOnly = cart_pickup_only($lines, $content);
// Remise du panier (paliers de l'administration) : appliquée à Stripe comme un bon de réduction à usage unique.
$discount = cart_discount_for(cart_total_cents(), cart_total_qty($lines));

// Chaque option porte une clé ('pickup', 'rate-<id>', 'flat') qui correspond
// à la valeur du bouton radio choisi dans le panier (voir cart.php). Une seule
// option est envoyée à Stripe : le client ne peut donc pas changer de mode de
// livraison sur la page de paiement, et le mode enregistré est celui payé.
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

// Le tarif choisi dans le panier a pu disparaître entre-temps (poids ou zone
// modifiés) : on ne remplace pas silencieusement le prix, on renvoie au panier.
$chosenKey = (string) ($_POST['shipping_choice'] ?? '');
if ($pickupOnly) $chosenKey = 'pickup';
$firstShippingKey = '';
foreach (array_keys($shippingOptions) as $optionKey) {
    if ($optionKey !== 'pickup') { $firstShippingKey = (string) $optionKey; break; }
}
if ($chosenKey === '') $chosenKey = $needsAddress ? ($firstShippingKey !== '' ? $firstShippingKey : 'pickup') : 'pickup';
if (!isset($shippingOptions[$chosenKey])) {
    // Tarif précis disparu : le prix ne doit pas changer à l'insu du client.
    if (str_starts_with($chosenKey, 'rate-')) {
        flash_set('Les frais de livraison ont changé (poids ou adresse) : vérifiez votre panier avant de payer.', 'error');
        header('Location: /cart.php');
        exit;
    }
    // Ancien tarif fixe alors que des paliers existent maintenant : premier palier.
    $chosenKey = $firstShippingKey !== '' ? $firstShippingKey : 'pickup';
}
$shippingMode = $chosenKey === 'pickup' ? 'pickup' : 'shipping';
$chosenOption = $shippingOptions[$chosenKey];
$chosenOption['shipping_rate_data']['metadata'] = ['choice' => $chosenKey, 'mode' => $shippingMode];
$shippingLabel = (string) ($chosenOption['shipping_rate_data']['display_name'] ?? '');
$shippingOptionPayload = [$chosenOption];
// Le mode retenu (et non le bouton radio d'origine) décide de l'adresse.
$needsAddress = $shippingMode === 'shipping';
$_SESSION['shipping_ui'] = $needsAddress ? $chosenKey : 'pickup';

// Moyen de paiement : Stripe (flux historique), Monetico ou PayPal. Sans
// sélecteur affiché (un seul moyen), le premier moyen configuré s'applique.
$paymentMethods = shop_payment_methods();
$paymentMethod = (string) ($_POST['payment_method'] ?? '');
if (!isset($paymentMethods[$paymentMethod])) $paymentMethod = (string) array_key_first($paymentMethods);
if ($paymentMethod === '') {
    flash_set("Aucun moyen de paiement n'est disponible pour le moment.", 'error');
    header('Location: /cart.php');
    exit;
}
$_SESSION['checkout_payment_method'] = $paymentMethod;

$shippingCents = (int) ($chosenOption['shipping_rate_data']['fixed_amount']['amount'] ?? 0);
$orderTotal = max(0, cart_total_cents() - (int) $discount['cents']) + $shippingCents + $giftWrapCents;
$declaredAddress = $needsAddress ? implode(', ', array_filter([
    $contact['ship_other'] ? $contact['ship_name'] : $contact['name'],
    $dest['line1'], $dest['line2'], trim($dest['postal'] . ' ' . $dest['city']), $dest['country'],
])) : null;

// Reprise d'une demande déjà validée par le vendeur : on paie normalement.
$resumeRequestId = (int) ($_SESSION['checkout_resume_order'] ?? 0);

// Validation de la disponibilité avant paiement : aucun paiement n'est ouvert,
// le vendeur est prévenu (SMS, WhatsApp ou e-mail) et la commande attend sa
// réponse. Le client paie ensuite depuis le lien qu'il reçoit.
if (oa_enabled() && $resumeRequestId <= 0) {
    $requestItems = array_map(static function ($line) {
        return [
            'ref' => $line['product']['ref'],
            'name' => $line['product']['name'],
            'qty' => $line['qty'],
            'unit_cents' => $line['unit_cents'],
        ];
    }, $lines);
    $payload = [
        'cart' => cart_get(),
        'contact' => $contact,
        'buyer' => $buyer,
        'dest' => $dest,
        'buyer_country' => (string) ($_SESSION['buyer_country'] ?? $buyer['country']),
        'buyer_line1' => (string) ($_SESSION['buyer_line1'] ?? $buyer['line1']),
        'buyer_postal' => (string) ($_SESSION['buyer_postal'] ?? $buyer['postal']),
        'buyer_city' => (string) ($_SESSION['buyer_city'] ?? $buyer['city']),
        'buyer_address_label' => (string) ($_SESSION['buyer_address_label'] ?? ''),
        'shipping_country' => (string) ($_SESSION['shipping_country'] ?? 'FR'),
        'shipping_line1' => (string) ($_SESSION['shipping_line1'] ?? ''),
        'shipping_line2' => '',
        'shipping_postal_code' => (string) ($_SESSION['shipping_postal_code'] ?? ''),
        'shipping_city' => (string) ($_SESSION['shipping_city'] ?? ''),
        'shipping_ui' => $needsAddress ? $chosenKey : 'pickup',
        'payment_method' => $paymentMethod,
        'reward_code' => (string) ($_SESSION['reward_code'] ?? ''),
        'gift_wrap' => $giftWrap,
        'gift_wrap_cents' => $giftWrapCents,
        'gift_message' => $giftMessage,
    ];
    try {
        $requestId = oa_create([
            'amount_total' => $orderTotal,
            'shipping_cents' => $shippingCents,
            'gift_wrap' => $giftWrap,
            'gift_wrap_cents' => $giftWrapCents,
            'gift_message' => $giftMessage,
            'items' => $requestItems,
            'email' => $contact['email'],
            'phone' => $phoneNorm,
            'terms_at' => gmdate('c'),
            'terms_version' => $termsVersion,
            'create_account' => $contact['create_account'],
            'ship_name' => $contact['ship_other'] ? $contact['ship_name'] : '',
            'ship_phone' => $contact['ship_other'] ? (string) $shipPhoneNorm : '',
            'declared_address' => $declaredAddress,
            'buyer_address' => implode(', ', array_filter([$buyer['line1'], trim($buyer['postal'] . ' ' . $buyer['city']), $buyer['country']])),
            'shop_slug' => tenant_slug(),
            'shipping_mode' => $shippingMode,
            'shipping_label' => $shippingLabel,
        ], $payload);
    } catch (Throwable $e) {
        flash_set('Impossible d\'enregistrer votre demande : ' . $e->getMessage(), 'error');
        header('Location: /cart.php');
        exit;
    }
    checkout_remember_order($requestId);
    oa_notify_seller($requestId);
    try {
        oa_send_buyer_received($requestId);
    } catch (Throwable $e) {
        error_log('validation disponibilité (accusé client) : ' . $e->getMessage());
    }
    cart_clear();
    $request = order_get($requestId);
    header('Location: ' . ($request ? oa_buyer_url($request) : '/demande.php?o=' . $requestId), true, 303);
    exit;
}

// Paiement chez un prestataire autre que Stripe : la commande est créée ici,
// puis le client est envoyé sur la page sécurisée du prestataire. Le retour
// (et l'IPN Monetico) confirme le paiement — voir monetico-return.php,
// monetico-ipn.php et paypal-return.php.
if ($paymentMethod !== 'stripe') {
    $provider = $paymentMethod;
    $reference = payment_reference();
    $externalItems = array_map(static function ($line) {
        return [
            'ref' => $line['product']['ref'],
            'name' => $line['product']['name'],
            'qty' => $line['qty'],
            'unit_cents' => $line['unit_cents'],
        ];
    }, $lines);

    try {
        $stmt = db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, shipping_cents, status, items, payment_provider, payment_reference, provider_checkout_id, email, phone, terms_at, terms_version, create_account, ship_name, ship_phone, declared_address, shop_slug, shipping_mode, shipping_label, gift_wrap, gift_wrap_cents, gift_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $provider . ':' . $reference,
            $orderTotal,
            $shippingCents,
            'pending',
            json_encode($externalItems, JSON_UNESCAPED_UNICODE),
            $provider,
            $reference,
            $reference,
            $contact['email'],
            $phoneNorm,
            gmdate('c'),
            $termsVersion,
            $contact['create_account'] ? 1 : 0,
            $contact['ship_other'] ? $contact['ship_name'] : null,
            $contact['ship_other'] ? $shipPhoneNorm : null,
            $declaredAddress,
            tenant_slug(),
            $shippingMode,
            $shippingLabel,
            $giftWrap ? 1 : 0,
            $giftWrapCents,
            $giftMessage !== '' ? $giftMessage : null,
        ]);
    } catch (Throwable $e) {
        flash_set('Impossible de créer la commande : ' . $e->getMessage(), 'error');
        header('Location: /cart.php');
        exit;
    }
    $orderId = (int) db()->lastInsertId();
    checkout_remember_order($orderId);
    if ($resumeRequestId > 0) oa_mark_converted($orderId);
    if ($contact['create_account']) {
        try {
            $existingAcc = customer_account($contact['email']);
            if (!$existingAcc || empty($existingAcc['password_hash'])) customer_send_link($contact['email'], $contact['name'], (string) $phoneNorm, 'setup');
        } catch (Throwable $e) {
            error_log('checkout create_account : ' . $e->getMessage());
        }
    }
    plugin_fire('checkout_created', ['order_id' => $orderId, 'code' => (string) ($_SESSION['reward_code'] ?? '')]);

    $order = order_get($orderId);
    $cancelExternal = static function (int $id, string $provider, string $reason): void {
        db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'")->execute([gmdate('c'), $id]);
        order_event($id, 'annulee', 'Ouverture du paiement impossible : ' . $reason, $provider);
    };

    if ($provider === 'sepa') {
        // Virement SEPA : la commande est créée tout de suite, les pièces sont
        // réservées et le client reçoit les coordonnées bancaires. Le paiement
        // n'est confirmé que lorsque le commerçant a constaté le virement.
        if (!sepa_configured() || !$order) {
            $cancelExternal($orderId, 'sepa', 'coordonnées bancaires absentes');
            flash_set("Le virement SEPA n'est pas disponible pour cette boutique.", 'error');
            header('Location: /cart.php');
            exit;
        }
        order_reserve_stock($orderId);
        db()->prepare('UPDATE orders SET payment_deadline = ? WHERE id = ?')
            ->execute([gmdate('c', time() + sepa_config()['delay_days'] * 86400), $orderId]);
        $order = order_get($orderId) ?: $order;
        cart_clear();
        try {
            order_send_transfer_instructions($orderId);
        } catch (Throwable $e) {
            error_log('instructions virement ' . $orderId . ' : ' . $e->getMessage());
        }
        $sig = payment_return_token($orderId, 'sepa');
        header('Location: /sepa-instructions.php?order=' . $orderId . ($sig !== '' ? '&sig=' . rawurlencode($sig) : ''), true, 303);
        exit;
    }

    if ($provider === 'revolut') {
        $revolut = revolut_config();
        if (!$revolut || !$order) {
            $cancelExternal($orderId, 'revolut', 'réglages absents');
            flash_set("Revolut n'est pas configuré pour cette boutique.", 'error');
            header('Location: /cart.php');
            exit;
        }
        try {
            $returnUrl = rtrim(tenant_site_url(), '/') . '/revolut-return.php?order=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'revolut'));
            $revolutOrder = revolut_create_order(
                $revolut,
                $orderTotal,
                $reference,
                $returnUrl,
                $contact['email'],
                (string) ($content['site_name'] ?? 'Boutique')
            );
            $checkoutUrl = revolut_checkout_url($revolutOrder);
            $revolutId = (string) ($revolutOrder['id'] ?? '');
            if ($revolutId === '' || $checkoutUrl === '') throw new RuntimeException("Revolut n'a pas renvoyé de lien de paiement.");
            db()->prepare('UPDATE orders SET provider_checkout_id = ? WHERE id = ?')->execute([$revolutId, $orderId]);
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        } catch (Throwable $e) {
            $cancelExternal($orderId, 'revolut', $e->getMessage());
            flash_set("Le paiement Revolut n'a pas pu être ouvert : " . $e->getMessage(), 'error');
            header('Location: /cart.php');
            exit;
        }
    }

    if ($provider === 'monetico') {
        $monetico = monetico_config();
        if (!$monetico || !$order) {
            $cancelExternal($orderId, 'monetico', 'réglages absents');
            flash_set("Monetico n'est pas configuré pour cette boutique.", 'error');
            header('Location: /cart.php');
            exit;
        }
        $fields = monetico_payment_fields($order, $monetico, $orderTotal, $reference, $contact['email'], (string) ($content['site_name'] ?? 'Boutique'), rtrim(SITE_URL, '/'));
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paiement sécurisé</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f2ec;color:#2a2420;font-family:system-ui,-apple-system,sans-serif;}
  .box{max-width:420px;padding:28px;text-align:center}
  .spinner{width:34px;height:34px;margin:0 auto 18px;border:3px solid #d8d0c4;border-top-color:#8a5a2b;border-radius:50%;animation:spin 1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  button{margin-top:18px;padding:12px 22px;border:0;border-radius:8px;background:#2a2420;color:#fff;font-size:1rem;cursor:pointer}
</style>
</head>
<body>
<div class="box">
  <div class="spinner" aria-hidden="true"></div>
  <h1 style="font-size:1.2rem;margin:0 0 8px;">Redirection vers le paiement sécurisé</h1>
  <p style="margin:0;color:#6b6259;">Vous allez être redirigé vers Monetico. Ne fermez pas cette page.</p>
  <form method="post" action="<?= h(monetico_payment_url($monetico)) ?>" id="monetico-form">
    <?php foreach ($fields as $fieldName => $fieldValue): ?>
      <input type="hidden" name="<?= h($fieldName) ?>" value="<?= h($fieldValue) ?>">
    <?php endforeach; ?>
    <noscript><button type="submit">Payer maintenant</button></noscript>
  </form>
</div>
<script>document.getElementById('monetico-form').submit();</script>
</body>
</html><?php
        exit;
    }

    if ($provider === 'paypal') {
        $paypal = paypal_config();
        if (!$paypal || !$order) {
            $cancelExternal($orderId, 'paypal', 'réglages absents');
            flash_set("PayPal n'est pas configuré pour cette boutique.", 'error');
            header('Location: /cart.php');
            exit;
        }
        try {
            $returnUrl = rtrim(SITE_URL, '/') . '/paypal-return.php?order=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'paypal'));
            $paypalOrder = paypal_create_order(
                $paypal,
                $orderTotal,
                $reference,
                $returnUrl,
                rtrim(SITE_URL, '/') . '/cart.php?payment=cancelled',
                (string) ($content['site_name'] ?? 'Boutique')
            );
            $paypalId = (string) ($paypalOrder['id'] ?? '');
            $approve = paypal_approve_url($paypalOrder);
            if ($paypalId === '' || $approve === '') throw new RuntimeException("PayPal n'a pas renvoyé de lien de paiement.");
            db()->prepare('UPDATE orders SET provider_checkout_id = ? WHERE id = ?')->execute([$paypalId, $orderId]);
            header('Location: ' . $approve, true, 303);
            exit;
        } catch (Throwable $e) {
            $cancelExternal($orderId, 'paypal', $e->getMessage());
            flash_set("Le paiement PayPal n'a pas pu être ouvert : " . $e->getMessage(), 'error');
            header('Location: /cart.php');
            exit;
        }
    }
}

$params = [
    'mode' => 'payment',
    'success_url' => SITE_URL . '/checkout-success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => SITE_URL . '/cart.php',
    'metadata' => ['type' => 'shop_order', 'shop' => tenant_slug(), 'shipping_mode' => $shippingMode, 'shipping_choice' => $chosenKey, 'gift_wrap' => $giftWrap ? '1' : '0'],
    // L'adresse n'est demandée que pour une expédition ; un retrait n'en a pas besoin.
    'shipping_options' => $shippingOptionPayload,
    // Page de paiement fermée après CHECKOUT_HOLD_MINUTES : la réservation des pièces ne dure pas plus longtemps.
    'expires_at' => time() + CHECKOUT_HOLD_MINUTES * 60,
];
if ($needsAddress) {
    $params['shipping_address_collection'] = ['allowed_countries' => array_keys(shipping_allowed_countries())];
}

// Stripe Connect : si le compte Stripe de la boutique est relié à la plateforme, le paiement passe par la plateforme qui reverse la vente au commerçant
// et garde la commission (includes/connect.php). Sinon, paiement habituel avec les clés de la boutique.
$connect = connect_plan(cart_total_cents() - $discount['cents']);
if ($connect) {
    $params['payment_intent_data'] = ['application_fee_amount' => $connect['fee'], 'transfer_data' => ['destination' => $connect['account']],
        'metadata' => ['type' => 'shop_order', 'shop' => tenant_slug(), 'shipping_mode' => $shippingMode]];
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
// L'e-mail, l'adresse de l'acheteur et, pour une expédition, l'adresse de livraison sont préremplis sur la page de paiement
// (client Stripe ; le client peut les modifier).
$params['customer_email'] = $contact['email'];
$buyerAddressText = implode(', ', array_filter([$buyer['line1'], $buyer['line2'], trim($buyer['postal'] . ' ' . $buyer['city']), $buyer['country']]));
try {
    $cust = ['email' => $contact['email'], 'name' => $contact['name'], 'phone' => $phoneNorm,
        'address' => ['line1' => $buyer['line1'], 'line2' => $buyer['line2'], 'city' => $buyer['city'], 'postal_code' => $buyer['postal'], 'country' => $buyer['country']]];
    if ($needsAddress) {
        $recipient = $contact['ship_other'] ? $contact['ship_name'] : $contact['name'];
        $cust['shipping'] = ['name' => $recipient !== '' ? $recipient : $contact['email'], 'phone' => $contact['ship_other'] ? $shipPhoneNorm : $phoneNorm,
            'address' => ['line1' => $dest['line1'], 'line2' => $dest['line2'], 'city' => $dest['city'], 'postal_code' => $dest['postal'], 'country' => $dest['country']]];
    }
    $stripeCustomer = stripe_request('POST', '/customers', $cust, (bool) $connect);
    if (!empty($stripeCustomer['id'])) {
        unset($params['customer_email']);
        $params['customer'] = $stripeCustomer['id'];
        if ($needsAddress) $params['customer_update'] = ['shipping' => 'auto'];
    }
} catch (Throwable $e) {
    error_log('checkout prefill client Stripe : ' . $e->getMessage());       // sans incidence : la page de paiement redemande l'adresse
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
if ($giftWrapCents > 0) {
    $params['line_items'][$i]['quantity'] = 1;
    $params['line_items'][$i]['price_data']['currency'] = 'eur';
    $params['line_items'][$i]['price_data']['unit_amount'] = $giftWrapCents;
    $params['line_items'][$i]['price_data']['product_data']['name'] = gift_wrap_label($content);
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

$stmt = db()->prepare('INSERT INTO orders (stripe_session_id, amount_total, status, items, payment_via, connect_fee_cents, connect_gallery_cents, email, phone, terms_at, terms_version, create_account, ship_name, ship_phone, declared_address, buyer_address, shop_slug, shipping_mode, shipping_label, gift_wrap, gift_wrap_cents, gift_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
    $buyerAddressText,
    tenant_slug(),
    $shippingMode,
    $shippingLabel,
    $giftWrap ? 1 : 0,
    $giftWrapCents,
    $giftMessage !== '' ? $giftMessage : null,
]);
$orderId = (int) db()->lastInsertId();
checkout_remember_order($orderId);
if ($resumeRequestId > 0) oa_mark_converted($orderId);
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
