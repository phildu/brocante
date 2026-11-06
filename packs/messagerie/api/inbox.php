<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ms.php';

if (!addon_is_entitled(MS_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = db()->query("SELECT m.*, p.name AS product_name FROM ms_messages m LEFT JOIN products p ON p.ref = m.product_ref ORDER BY m.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['messages' => $rows, 'settings' => ms_settings()]);
}
$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'reply') {
    $err = ms_reply((int) ($in['id'] ?? 0), (string) ($in['body'] ?? ''));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true]);
}
if ($action === 'read') {
    db()->prepare("UPDATE ms_messages SET status = 'read' WHERE id = ? AND direction = 'in' AND status = 'new'")->execute([(int) ($in['id'] ?? 0)]);
    plugin_json(['ok' => true]);
}
if ($action === 'settings') {
    if (!empty($in['whatsapp']) && ms_wa_number((string) $in['whatsapp']) === '') plugin_json(['error' => 'Numéro WhatsApp invalide (ex. 06 12 34 56 78 ou +33 6 12 34 56 78).'], 422);
    ms_settings_save($in);
    plugin_json(['ok' => true, 'settings' => ms_settings()]);
}
plugin_json(['error' => 'Action invalide.'], 422);
