<?php

// Retour navigateur depuis Monetico. Monetico renvoie un POST depuis son
// domaine : le cookie de session SameSite=Lax n'est pas envoyé. Cette page ne
// touche donc pas à la session ; elle authentifie la réponse par le MAC, puis
// redirige vers le récapitulatif avec un jeton signé.

require_once __DIR__ . '/includes/functions.php';

$config = monetico_config();
if (!$config) {
    header('Location: /cart.php?paiement=indisponible', true, 303);
    exit;
}

$check = monetico_validate_response($_POST, $config);
if (!$check['ok']) {
    error_log('Monetico retour refusé : ' . $check['message']);
    if (!empty($check['order'])) {
        order_event((int) $check['order']['id'], 'paiement_echec', $check['message'], 'monetico');
    }
    header('Location: /cart.php?paiement=refuse', true, 303);
    exit;
}

$order = $check['order'];
$orderId = (int) $order['id'];

if ($check['paid']) {
    $detail = trim(implode(' ', array_filter([
        $check['brand'] ?? '',
        $check['masked_card'] ?? '',
        $check['payment_method'] ?? '',
    ])));
    $paymentId = (string) (($check['authorization'] ?? '') !== '' ? $check['authorization'] : ($order['payment_reference'] ?? ''));
    order_finalize_external($order, 'monetico', $paymentId, (string) ($check['authorization'] ?? ''), $detail);

    $fresh = order_get($orderId);
    if ($fresh && $fresh['status'] === 'paid') {
        $sig = payment_return_token($orderId, 'monetico');
        header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode($sig), true, 303);
        exit;
    }
    header('Location: /cart.php?paiement=attente', true, 303);
    exit;
}

if ($order['status'] === 'pending') {
    order_event($orderId, 'paiement_echec', $check['message'], 'monetico');
}
header('Location: /cart.php?paiement=refuse', true, 303);
exit;
