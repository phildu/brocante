<?php

// Webhook SumUp (return_url du checkout) : notification serveur à serveur,
// indépendante du navigateur. SumUp ne signe pas ses notifications : l'adresse
// porte la commande et un jeton signé propre à la boutique, et l'état est
// toujours relu auprès de l'API à partir de l'identifiant enregistré sur la
// commande (jamais celui du corps reçu). Répondre 2xx évite les relances.

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

$config = sumup_config();
if (!$config) $reply(400, ['error' => 'SumUp non configuré pour cette boutique']);

$orderId = (int) ($_GET['order'] ?? 0);
if (!payment_return_token_valid($orderId, 'sumup', (string) ($_GET['sig'] ?? ''))) {
    $reply(401, ['error' => 'Jeton invalide']);
}

$order = order_get($orderId);
if (!$order || payment_provider_of($order) !== 'sumup') $reply(200, ['ok' => true, 'result' => 'commande inconnue']);

$data = json_decode((string) file_get_contents('php://input'), true);
$event = is_array($data) ? strtoupper((string) ($data['event_type'] ?? '')) : '';
if ($event !== '' && $event !== 'CHECKOUT_STATUS_CHANGED') $reply(200, ['ok' => true, 'result' => 'événement ignoré']);

try {
    $fresh = sumup_get_checkout($config, (string) ($order['provider_checkout_id'] ?? ''));
} catch (Throwable $e) {
    $reply(500, ['error' => 'Vérification SumUp impossible']);
}

if (sumup_is_paid($fresh)) {
    $amount = sumup_checkout_cents($fresh);
    $currency = strtoupper((string) ($fresh['currency'] ?? ''));
    if ($amount !== (int) $order['amount_total'] || $currency !== 'EUR') {
        order_event($orderId, 'paiement_echec', 'Montant SumUp incohérent : ' . $amount . ' centimes ' . $currency . ' au lieu de ' . (int) $order['amount_total'] . ' EUR', 'sumup');
        $reply(200, ['ok' => true, 'result' => 'montant incohérent']);
    }
    $done = order_finalize_external($order, 'sumup', sumup_payment_id($fresh), '', sumup_payment_detail($fresh));
    $reply(200, ['ok' => true, 'result' => $done ? 'commande payée' : 'déjà payée']);
}

if (sumup_state($fresh) === 'expired' && $order['status'] === 'pending') {
    db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'")->execute([gmdate('c'), $orderId]);
    order_event($orderId, 'annulee', 'Paiement SumUp expiré', 'sumup');
    $reply(200, ['ok' => true, 'result' => 'commande annulée']);
}

$reply(200, ['ok' => true, 'result' => 'sans effet']);
