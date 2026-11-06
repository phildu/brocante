<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ra.php';

if (!addon_is_entitled(RA_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
ra_schema();
ra_tick(true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = db()->query("SELECT r.*, p.name AS product_name FROM ra_reservations r LEFT JOIN products p ON p.ref = r.product_ref ORDER BY r.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['reservations' => $rows, 'settings' => ra_settings(), 'stripe' => stripe_configured(), 'now' => time()]);
}
$in = plugin_input();
$id = (int) ($in['id'] ?? 0);
$action = (string) ($in['action'] ?? '');
$err = match ($action) {
    'honor' => ra_honor($id),
    'forfeit' => ra_forfeit($id),
    'refund' => ra_refund($id),
    'settings' => ra_settings_save($in),
    default => 'Action invalide.',
};
if ($err) plugin_json(['error' => $err], 422);
plugin_json(['ok' => true, 'settings' => ra_settings()]);
