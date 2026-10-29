<?php
// Tables de correspondance boutique ↔ Leboncoin / Vinted (administration).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/mappings.php';

am_map_ensure();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $rows = db()->query('SELECT id, channel, field, source_label, source_norm, target FROM am_mappings ORDER BY channel, field, source_label')->fetchAll(PDO::FETCH_ASSOC);
    $unknown = db()->query("SELECT id, channel, field_label, field_type, source_value, product_ref, options, seen, status, last_seen
        FROM am_unknown_fields ORDER BY status, last_seen DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($unknown as &$u) $u['options'] = json_decode((string) $u['options'], true) ?: [];
    unset($u);
    plugin_json([
        'channels' => AM_MAP_CHANNELS,
        'fields' => array_map(static fn ($f) => ['label' => $f[0], 'help' => $f[1]], am_map_fields()),
        'mappings' => $rows,
        'unknown' => $unknown,
        'suggestions' => am_map_suggestions(),
    ]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'save') {
    $err = am_map_save(
        (string) ($in['channel'] ?? ''),
        (string) ($in['field'] ?? ''),
        (string) ($in['source'] ?? ''),
        (string) ($in['target'] ?? ''),
        (string) ($in['source_label'] ?? '')
    );
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true]);
}
if ($action === 'delete') {
    db()->prepare('DELETE FROM am_mappings WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
    plugin_json(['ok' => true]);
}
if ($action === 'ignore' || $action === 'unignore') {
    db()->prepare('UPDATE am_unknown_fields SET status = ? WHERE id = ?')->execute([$action === 'ignore' ? 'ignored' : 'open', (int) ($in['id'] ?? 0)]);
    plugin_json(['ok' => true]);
}
if ($action === 'forget') {
    db()->prepare('DELETE FROM am_unknown_fields WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
    plugin_json(['ok' => true]);
}
plugin_json(['error' => 'Action invalide.'], 422);
