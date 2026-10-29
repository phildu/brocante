<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rt.php';

if (!addon_is_entitled(RT_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
rt_tick(true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $q = trim((string) ($_GET['find'] ?? ''));
    if ($q !== '') {
        $r = rt_find($q);
        if (!$r) plugin_json(['error' => 'Billet introuvable.'], 404);
        plugin_json(['pickup' => $r]);
    }
    $rows = db()->query('SELECT r.*, p.name AS product_name FROM rt_pickups r LEFT JOIN products p ON p.ref = r.product_ref ORDER BY r.id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['pickups' => $rows, 'settings' => rt_settings(), 'stripe' => stripe_configured(), 'now' => time()]);
}
$in = plugin_input();
$id = (int) ($in['id'] ?? 0);
$err = match ((string) ($in['action'] ?? '')) {
    'collect' => rt_collect($id),
    'release' => rt_release($id),
    'settings' => rt_settings_save($in),
    default => 'Action invalide.',
};
if ($err) plugin_json(['error' => $err], 422);
plugin_json(['ok' => true, 'settings' => rt_settings()]);
