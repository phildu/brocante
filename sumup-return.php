<?php

// Retour navigateur depuis la page de paiement SumUp (bouton « retour au site »
// de la page de succès). Le lien signé remplace la session (le client peut
// revenir d'un autre appareil). Le paiement est vérifié auprès de l'API, jamais
// d'après l'adresse seule.

require_once __DIR__ . '/includes/functions.php';

$orderId = (int) ($_GET['order'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');
$order = order_get($orderId);

if (!$order || payment_provider_of($order) !== 'sumup' || !payment_return_token_valid($orderId, 'sumup', $sig)) {
    header('Location: /cart.php?paiement=indisponible', true, 303);
    exit;
}

$config = sumup_config();
if (!$config) {
    header('Location: /cart.php?paiement=indisponible', true, 303);
    exit;
}

if ($order['status'] === 'paid') {
    header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'sumup')), true, 303);
    exit;
}

try {
    $data = sumup_get_checkout($config, (string) ($order['provider_checkout_id'] ?? ''));
} catch (Throwable $e) {
    order_event($orderId, 'paiement_echec', 'Vérification SumUp impossible : ' . $e->getMessage(), 'sumup');
    header('Location: /cart.php?paiement=attente', true, 303);
    exit;
}

if (sumup_is_paid($data)) {
    $amount = sumup_checkout_cents($data);
    $currency = strtoupper((string) ($data['currency'] ?? ''));
    if ($amount !== (int) $order['amount_total'] || $currency !== 'EUR') {
        order_event($orderId, 'paiement_echec', 'Montant SumUp incohérent : ' . $amount . ' centimes ' . $currency . ' au lieu de ' . (int) $order['amount_total'] . ' EUR', 'sumup');
        header('Location: /cart.php?paiement=attente', true, 303);
        exit;
    }
    if ($order['status'] === 'pending') {
        order_finalize_external($order, 'sumup', sumup_payment_id($data), '', sumup_payment_detail($data));
    }
    header('Location: /checkout-success.php?order_id=' . $orderId . '&sig=' . rawurlencode(payment_return_token($orderId, 'sumup')), true, 303);
    exit;
}

// FAILED : le client peut encore réessayer sur la page SumUp, la commande reste
// en attente. EXPIRED : la page n'est plus utilisable, la commande est annulée.
$state = sumup_state($data);
if ($state === 'expired') {
    if ($order['status'] === 'pending') {
        db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'")->execute([gmdate('c'), $orderId]);
        order_event($orderId, 'paiement_echec', 'Paiement SumUp expiré', 'sumup');
    }
    header('Location: /cart.php?paiement=refuse', true, 303);
    exit;
}
if ($state === 'failed') {
    header('Location: /cart.php?paiement=refuse', true, 303);
    exit;
}

header('Location: /cart.php?paiement=attente', true, 303);
exit;
