<?php

// Retour navigateur depuis la page de paiement Revolut. Le lien signé remplace
// la session (le client peut revenir d'un autre appareil). Le paiement est
// vérifié auprès de l'API, jamais d'après l'adresse seule.

require_once __DIR__ . '/includes/functions.php';

$orderId = (int) ($_GET['order'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');
$order = order_get($orderId);

if (!$order || payment_provider_of($order) !== 'revolut' || !payment_return_token_valid($orderId, 'revolut', $sig)) {
    header('Location: /cart.php?paiement=indisponible', true, 303);
    exit;
}

$config = revolut_config();
if (!$config) {
    header('Location: /cart.php?paiement=indisponible', true, 303);
    exit;
}

if ($order['status'] === 'paid') {
    header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'revolut')), true, 303);
    exit;
}

try {
    $data = revolut_get_order($config, (string) ($order['provider_checkout_id'] ?? ''));
} catch (Throwable $e) {
    order_event($orderId, 'paiement_echec', 'Vérification Revolut impossible : ' . $e->getMessage(), 'revolut');
    header('Location: /cart.php?paiement=attente', true, 303);
    exit;
}

if (revolut_is_paid($data)) {
    $amount = (int) ($data['amount'] ?? -1);
    $currency = strtoupper((string) ($data['currency'] ?? ''));
    if ($amount !== (int) $order['amount_total'] || $currency !== 'EUR') {
        order_event($orderId, 'paiement_echec', 'Montant Revolut incohérent : ' . $amount . ' centimes ' . $currency . ' au lieu de ' . (int) $order['amount_total'] . ' EUR', 'revolut');
        header('Location: /cart.php?paiement=attente', true, 303);
        exit;
    }
    if ($order['status'] === 'pending') {
        order_finalize_external($order, 'revolut', revolut_payment_id($data), '', revolut_payment_detail($data));
    }
    header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'revolut')), true, 303);
    exit;
}

$state = revolut_state($data);
if (in_array($state, ['failed', 'cancelled'], true)) {
    if ($order['status'] === 'pending') {
        db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'")->execute([gmdate('c'), $orderId]);
        order_event($orderId, 'paiement_echec', 'Paiement Revolut ' . $state, 'revolut');
    }
    header('Location: /cart.php?paiement=refuse', true, 303);
    exit;
}

header('Location: /cart.php?paiement=attente', true, 303);
exit;
