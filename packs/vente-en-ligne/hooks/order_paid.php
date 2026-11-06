<?php
// Paiement confirmé : une ligne dans le journal de suivi de la commande.
defined('PLUGIN_RUNTIME') || exit;

try {
    require_once dirname(__DIR__) . '/lib/vel.php';
    $orderId = (int) ($payload['order_id'] ?? 0);
    if ($orderId <= 0) return;
    $stmt = db()->prepare('SELECT email, amount_total, shipping_method FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return;
    $message = 'Paiement confirmé — ' . format_cents((int) $order['amount_total']);
    if ((string) ($order['shipping_method'] ?? '') !== '') $message .= ' · ' . $order['shipping_method'];
    vel_events_add($orderId, 'paiement', $message);
} catch (Throwable $e) {
    error_log('vente-en-ligne (order_paid) : ' . $e->getMessage());
}
