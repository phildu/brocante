<?php

// Webhook du terminal SumUp Solo (événement « solo.transaction.updated »).
// Comme pour le paiement en ligne, SumUp ne signe pas la notification : l'adresse
// porte la vente et un jeton signé propre à la boutique, et la transaction est
// toujours relue auprès de l'API à partir de l'identifiant enregistré sur la
// vente. Une vente annulée trop tôt à la caisse mais finalement encaissée par
// le lecteur est enregistrée quand même (l'argent est reçu).

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pos.php';

header('Content-Type: application/json; charset=utf-8');

$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!sumup_config()) $reply(400, ['error' => 'SumUp non configuré pour cette boutique']);

$orderId = (int) ($_GET['order'] ?? 0);
if (!payment_return_token_valid($orderId, 'sumup', (string) ($_GET['sig'] ?? ''))) {
    $reply(401, ['error' => 'Jeton invalide']);
}

$order = order_get($orderId);
if (!$order || payment_provider_of($order) !== 'sumup' || strpos((string) $order['shipping_method'], 'Vente en boutique') !== 0) {
    $reply(200, ['ok' => true, 'result' => 'vente inconnue']);
}

$data = json_decode((string) file_get_contents('php://input'), true);
$event = is_array($data) ? (string) ($data['event_type'] ?? '') : '';
if ($event !== '' && $event !== 'solo.transaction.updated') $reply(200, ['ok' => true, 'result' => 'événement ignoré']);

if ($order['status'] === 'paid') $reply(200, ['ok' => true, 'result' => 'déjà payée']);

try {
    $state = pos_sumup_refresh($order);
} catch (Throwable $e) {
    $reply(500, ['error' => 'Vérification SumUp impossible']);
}

if ($state === 'failed' && $order['status'] === 'pending') {
    db()->prepare("UPDATE orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$orderId]);
    order_event($orderId, 'paiement_echec', 'Paiement refusé ou abandonné sur le terminal SumUp', 'sumup');
}
$reply(200, ['ok' => true, 'result' => $state === 'paid' ? 'vente enregistrée' : ($state === 'failed' ? 'paiement refusé' : 'en attente')]);
