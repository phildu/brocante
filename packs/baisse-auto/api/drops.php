<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/bp.php';

if (!addon_is_entitled(BP_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $log = db()->query('SELECT l.*, p.name FROM bp_log l LEFT JOIN products p ON p.ref = l.product_ref ORDER BY l.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['settings' => bp_settings(), 'due' => bp_due(), 'log' => $log]);
}
$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'settings') {
    $err = bp_settings_save($in);
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'settings' => bp_settings()]);
}
if ($action === 'apply') {
    $refs = array_map('strval', (array) ($in['refs'] ?? []));
    $done = 0;
    $errors = [];
    foreach (bp_due() as $d) {
        if (!in_array($d['ref'], $refs, true)) continue;
        $e = bp_apply($d, 'manuel');
        if ($e === null) $done++; else $errors[] = $d['ref'] . ' : ' . $e;
    }
    plugin_json(['ok' => true, 'applied' => $done, 'errors' => $errors]);
}
if ($action === 'undo') {
    $err = bp_undo((int) ($in['id'] ?? 0));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true]);
}
plugin_json(['error' => 'Action invalide.'], 422);
