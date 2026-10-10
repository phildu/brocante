<?php
// Webhook Stripe de la plateforme (à déclarer dans le tableau de bord Stripe : <domaine>/inscription/webhook.php, événements
// checkout.session.completed, customer.subscription.deleted, et pour le suivi de l'activité : invoice.paid, invoice.payment_failed, charge.refunded). La signature est vérifiée avec le « secret de signature » (whsec_…) enregistré
// dans le portail → Offres et annuaire. Sert de filet : la boutique est générée même si le client ferme la page de retour.
require_once __DIR__ . '/../includes/saas.php';

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');

$secret = saas_stripe_config()['webhook_secret'];
$payload = (string) file_get_contents('php://input');
if ($secret === '' || !saas_stripe_signature_valid($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret)) {
    exit('signature invalide');
}
$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) exit('événement illisible');

$obj = $event['data']['object'] ?? [];
$origin = saas_origin();
saas_event('webhook', ['note' => (string) $event['type'], 'actor' => 'stripe']);

/** Demande dont l'abonnement (ou le client) Stripe est celui de l'objet reçu. */
$requestOf = static function (array $o): ?array {
    $sub = (string) ($o['subscription'] ?? '');
    $cus = (string) ($o['customer'] ?? '');
    foreach (saas_requests() as $r) {
        $p = $r['payment'] ?? [];
        if (($sub !== '' && ($p['subscription'] ?? '') === $sub) || ($sub === '' && $cus !== '' && ($p['customer'] ?? '') === $cus)) return $r;
    }
    return null;
};

if ($event['type'] === 'checkout.session.completed') {
    $reqId = (string) ($obj['client_reference_id'] ?? '');
    if (preg_match('/^[a-f0-9]{16}$/', $reqId)) {
        // Enregistre le paiement et envoie au client le lien de création de sa boutique (la boutique, elle, se crée à l'étape 3).
        $res = saas_mark_paid($reqId, $obj, $origin);
        error_log('webhook stripe checkout.session.completed ' . $reqId . ' → ' . $res['state']);
    }
} elseif ($event['type'] === 'customer.subscription.deleted') {
    // Abonnement résilié : on le note sur la demande (l'exploitant décide de la suite dans le portail).
    $sub = (string) ($obj['id'] ?? '');
    foreach (saas_requests() as $r) {
        if ($sub !== '' && ($r['payment']['subscription'] ?? '') === $sub) {
            $r['payment']['state'] = 'canceled';
            $r['payment']['canceled_at'] = time();
            saas_request_save($r);
            saas_event('subscription_canceled', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'actor' => 'stripe']);
        }
    }
} elseif ($event['type'] === 'invoice.paid' && ($obj['billing_reason'] ?? '') === 'subscription_cycle') {
    // Renouvellement mensuel (le premier paiement, lui, est déjà compté par checkout.session.completed).
    if ($r = $requestOf($obj)) saas_event('payment_renewed', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'amount' => (int) ($obj['amount_paid'] ?? 0), 'actor' => 'stripe']);
} elseif ($event['type'] === 'invoice.payment_failed') {
    if ($r = $requestOf($obj)) saas_event('payment_failed', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'amount' => (int) ($obj['amount_due'] ?? 0), 'actor' => 'stripe']);
} elseif ($event['type'] === 'charge.refunded') {
    if ($r = $requestOf($obj)) saas_event('payment_refunded', ['ref' => $r['id'], 'slug' => $r['slug'] ?? '', 'email' => $r['email'], 'plan' => $r['plan'], 'amount' => (int) ($obj['amount_refunded'] ?? 0), 'actor' => 'stripe']);
}
http_response_code(200);
echo 'ok';
