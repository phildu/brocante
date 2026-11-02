<?php
// Webhook Stripe de la boutique (clés propres au commerce, paiement direct).
// À déclarer dans le tableau de bord Stripe : <adresse du site>/stripe-webhook.php
// Événements : checkout.session.completed, checkout.session.async_payment_succeeded,
// checkout.session.async_payment_failed, checkout.session.expired, charge.refunded,
// charge.dispute.created / updated / closed.
// Le secret de signature (whsec_…) se colle dans Administration → Réglages → Stripe.
// Les paiements Connect sont reçus par inscription/webhook.php (webhook de la plateforme).
require_once __DIR__ . '/includes/functions.php';

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');

$secretFile = SECRETS_DIR . '/stripe_webhook.key';
$secret = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
if ($secret === '' || !str_starts_with($secret, 'whsec_')) {
    exit('webhook non configuré');
}

$payload = (string) file_get_contents('php://input');
$header = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (!order_stripe_signature_valid($payload, $header, $secret)) {
    exit('signature invalide');
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    exit('événement illisible');
}

try {
    $result = order_apply_stripe_event($event);
    error_log('webhook stripe boutique ' . tenant_slug() . ' ' . $event['type'] . ' → ' . $result);
    http_response_code(200);
    echo 'ok: ' . $result;
} catch (Throwable $e) {
    error_log('webhook stripe boutique ' . tenant_slug() . ' : ' . $e->getMessage());
    http_response_code(500);
    echo 'erreur';
}
