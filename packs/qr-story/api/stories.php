<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/qs.php';

if (!addon_is_entitled(QS_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT p.ref, p.name, p.stock, p.description, s.token, s.epoch, s.origin, s.expertise, s.expert_name, s.published FROM products p LEFT JOIN qs_stories s ON s.product_ref = p.ref';
    $params = [];
    if ($q !== '') { $sql .= ' WHERE p.name LIKE ? OR p.ref LIKE ?'; $params = ['%' . $q . '%', '%' . $q . '%']; }
    $st = db()->prepare($sql . ' ORDER BY p.created_at DESC LIMIT 60');
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['url'] = $r['token'] ? qs_url($r) : '';
    unset($r);
    plugin_json(['products' => $rows, 'signatory' => qs_setting('signatory')]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'save') {
    $err = qs_save((string) ($in['ref'] ?? ''), $in);
    if ($err) plugin_json(['error' => $err], 404);
    plugin_json(['ok' => true]);
}
if ($action === 'settings') {
    qs_setting_set('signatory', (string) ($in['signatory'] ?? ''));
    plugin_json(['ok' => true]);
}
plugin_json(['error' => 'Action invalide.'], 422);
