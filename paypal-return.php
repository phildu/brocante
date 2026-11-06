<?php

// Retour depuis PayPal : capture la commande (l'argent n'est prélevé qu'ici),
// vérifie le montant et la devise, puis confirme la commande locale.
// Le lien est signé (order + sig) : il reste valable même sans cookie.

require_once __DIR__ . '/includes/functions.php';

$orderId = (int) ($_GET['order'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');
$paypalOrderId = trim((string) ($_GET['token'] ?? ''));

$fail = static function (string $reason): void {
    header('Location: /cart.php?paiement=refuse', true, 303);
    exit;
};

if ($orderId <= 0 || !payment_return_token_valid($orderId, 'paypal', $sig)) $fail('lien invalide');
$order = order_get($orderId);
if (!$order || payment_provider_of($order) !== 'paypal') $fail('commande inconnue');
if ($paypalOrderId === '') $fail('numéro PayPal absent');
if ((string) ($order['provider_checkout_id'] ?? '') !== $paypalOrderId) $fail('commande PayPal différente');

if ($order['status'] === 'paid') {
    header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode($sig), true, 303);
    exit;
}

$config = paypal_config();
if (!$config) $fail('PayPal non configuré');

try {
    $capture = paypal_capture_order($config, $paypalOrderId, (string) ($order['payment_reference'] ?? ''));
} catch (Throwable $e) {
    // Un double retour (rechargement de page) retrouve une capture déjà faite.
    if (str_contains($e->getMessage(), 'ORDER_ALREADY_CAPTURED') || str_contains($e->getMessage(), 'ORDER_ALREADY_COMPLETED')) {
        try {
            $capture = paypal_get_order($config, $paypalOrderId);
        } catch (Throwable $retry) {
            order_event($orderId, 'paiement_echec', 'Vérification PayPal impossible : ' . $retry->getMessage(), 'paypal');
            $fail('vérification impossible');
        }
    } else {
        order_event($orderId, 'paiement_echec', 'Capture PayPal refusée : ' . $e->getMessage(), 'paypal');
        $fail('capture refusée');
    }
}

$data = paypal_capture_data($capture);

if (!paypal_is_paid($capture)) {
    order_event($orderId, 'paiement_echec', 'PayPal : statut ' . ($data['order_status'] ?: 'inconnu') . ' / ' . ($data['capture_status'] ?: 'sans capture'), 'paypal');
    header('Location: /cart.php?paiement=attente', true, 303);
    exit;
}

if ($data['amount_cents'] !== (int) $order['amount_total'] || $data['currency'] !== 'EUR') {
    order_event($orderId, 'paiement_echec', 'Montant PayPal incohérent : ' . ($data['amount_cents'] ?? '?') . ' centimes ' . $data['currency'] . ' au lieu de ' . (int) $order['amount_total'] . ' EUR', 'paypal');
    error_log('PayPal : montant incohérent pour la commande ' . $orderId);
    header('Location: /cart.php?paiement=attente', true, 303);
    exit;
}

$detail = trim('PayPal ' . $data['email']);
order_finalize_external($order, 'paypal', $data['capture_id'], '', $detail);

header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode($sig), true, 303);
exit;
