<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ac.php';

if (!addon_is_entitled(AC_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = db()->query("SELECT a.id, a.email, a.keywords, a.max_price_cents, a.status, a.created_at, a.last_notified_at,
        (SELECT COUNT(*) FROM ac_sent s WHERE s.alert_id = a.id) AS sent FROM ac_alerts a WHERE a.status <> 'stopped' ORDER BY a.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['alerts' => $rows]);
}
$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'delete') {
    db()->prepare("UPDATE ac_alerts SET status = 'stopped' WHERE id = ?")->execute([(int) ($in['id'] ?? 0)]);
    plugin_json(['ok' => true]);
}
if ($action === 'scan') {
    $n = ac_scan(true);
    plugin_json(['ok' => true, 'sent' => $n, 'message' => $n ? "$n e-mail(s) d'alerte envoyé(s)." : 'Aucune nouvelle pièce ne correspond aux alertes actives.']);
}
plugin_json(['error' => 'Action invalide.'], 422);
