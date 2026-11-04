<?php

// Webhook Revolut : notification serveur à serveur, indépendante du navigateur.
// La signature (HMAC-SHA256) est vérifiée avant toute lecture ; l'état de la
// commande est ensuite relu auprès de l'API Revolut, qui fait foi. Répondre 2xx
// évite les relances ; une erreur de signature répond 401.

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

$config = revolut_config();
if (!$config) $reply(400, ['error' => 'Revolut non configuré pour cette boutique']);
if (($config['webhook_secret'] ?? '') === '') $reply(400, ['error' => 'Secret du webhook Revolut absent']);

$payload = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '');
if (!revolut_webhook_valid($payload, $signature, (string) ($_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? ''), $config['webhook_secret'])) {
    $reply(401, ['error' => 'Signature invalide']);
}

$data = json_decode($payload, true);
if (!is_array($data)) $reply(200, ['ok' => true, 'result' => 'corps illisible']);

[$event, $orderData] = revolut_webhook_parts($data);
$revolutId = (string) ($orderData['id'] ?? '');
$reference = (string) ($orderData['merchant_order_data']['reference'] ?? '');

$order = order_by_provider_checkout('revolut', $revolutId);
if (!$order && $reference !== '') {
    $st = db()->prepare("SELECT * FROM orders WHERE payment_reference = ? AND payment_provider = 'revolut' ORDER BY id DESC LIMIT 1");
    $st->execute([$reference]);
    $order = $st->fetch() ?: null;
}
if (!$order) $reply(200, ['ok' => true, 'result' => 'commande inconnue']);

$orderId = (int) $order['id'];
if ($revolutId !== '' && empty($order['provider_checkout_id'])) {
    db()->prepare('UPDATE orders SET provider_checkout_id = ? WHERE id = ?')->execute([$revolutId, $orderId]);
    $order['provider_checkout_id'] = $revolutId;
}

// Seul un état « completed » relu auprès de l'API vaut paiement ; l'événement
// reçu n'est qu'un signal.
try {
    $fresh = revolut_get_order($config, (string) $order['provider_checkout_id']);
} catch (Throwable $e) {
    $reply(500, ['error' => 'Vérification Revolut impossible']);
}

if (revolut_is_paid($fresh)) {
    $amount = (int) ($fresh['amount'] ?? -1);
    $currency = strtoupper((string) ($fresh['currency'] ?? ''));
    if ($amount !== (int) $order['amount_total'] || $currency !== 'EUR') {
        order_event($orderId, 'paiement_echec', 'Montant Revolut incohérent : ' . $amount . ' centimes ' . $currency . ' au lieu de ' . (int) $order['amount_total'] . ' EUR', 'revolut');
        $reply(200, ['ok' => true, 'result' => 'montant incohérent']);
    }
    $done = order_finalize_external($order, 'revolut', revolut_payment_id($fresh), '', revolut_payment_detail($fresh));
    $reply(200, ['ok' => true, 'result' => $done ? 'commande payée' : 'déjà payée']);
}

if (in_array($event, ['ORDER_CANCELLED', 'ORDER_FAILED'], true) && $order['status'] === 'pending') {
    db()->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'pending'")->execute([gmdate('c'), $orderId]);
    order_event($orderId, 'annulee', 'Commande Revolut annulée (' . $event . ')', 'revolut');
    $reply(200, ['ok' => true, 'result' => 'commande annulée']);
}

$reply(200, ['ok' => true, 'result' => 'sans effet']);
