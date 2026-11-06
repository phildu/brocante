<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rc.php';

if (!addon_is_entitled(RC_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = db()->query("SELECT o.id, o.email, o.amount_total, o.created_at, r.number, r.token, r.emailed_at
        FROM orders o LEFT JOIN rc_receipts r ON r.order_id = o.id WHERE o.status = 'paid' ORDER BY o.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['url'] = $r['token'] ? rc_public_url($r) : '';
        unset($r['token']);
    }
    plugin_json(['orders' => $rows]);
}

$in = plugin_input();
$receipt = rc_issue((int) ($in['order_id'] ?? 0));
if (!$receipt) plugin_json(['error' => 'Commande introuvable ou non payée.'], 404);
$action = (string) ($in['action'] ?? 'issue');
if ($action === 'send') {
    if (!rc_send($receipt)) plugin_json(['error' => "Envoi impossible : adresse e-mail de l'acheteur absente ou invalide, ou serveur de messagerie indisponible."], 422);
    plugin_json(['ok' => true, 'message' => 'Reçu envoyé à ' . $receipt['email'] . '.']);
}
plugin_json(['ok' => true, 'number' => $receipt['number'], 'url' => rc_public_url($receipt)]);
