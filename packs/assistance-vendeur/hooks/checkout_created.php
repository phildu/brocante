<?php
// Paiement ouvert : la pièce est sur le point d'être achetée, le vendeur peut la retenir ou répondre au visiteur.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

if (!av_active() || !av_bool('alert_checkout')) return;
$orderId = (int) ($payload['order_id'] ?? 0);
if ($orderId <= 0) return;
$st = db()->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) return;

$items = json_decode((string) ($order['items'] ?? ''), true) ?: [];
$names = [];
$ref = '';
foreach ($items as $item) {
    $name = trim((string) ($item['name'] ?? ''));
    if ($name !== '') $names[] = '« ' . $name . ' »';
    if ($ref === '' && !empty($item['ref'])) $ref = (string) $item['ref'];
}
$amount = (int) ($order['amount_total'] ?? 0);
$detail = 'Commande #' . $orderId . ' · ' . number_format($amount / 100, 2, ',', ' ') . ' €'
    . ($names ? ' · ' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : '') : '')
    . ((string) ($order['email'] ?? '') !== '' ? ' · ' . $order['email'] : '')
    . ((string) ($order['phone'] ?? '') !== '' ? ' · ' . $order['phone'] : '');

av_alert_create([
    'visitor' => av_session_token(false),
    'kind' => 'checkout',
    'src' => 'order:' . $orderId,
    'ref' => $ref,
    'product_name' => (string) (($items[0]['name'] ?? '') ?: ''),
    'detail' => $detail,
    'email' => (string) ($order['email'] ?? ''),
    'phone' => (string) ($order['phone'] ?? ''),
]);