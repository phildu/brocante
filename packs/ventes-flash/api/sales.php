<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vf.php';

if (!addon_is_entitled(VF_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
vf_tick(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = db()->query('SELECT s.*, (SELECT COUNT(*) FROM vf_items i WHERE i.sale_id = s.id) AS pieces FROM vf_sales s ORDER BY s.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['percent_now'] = in_array($r['status'], ['running'], true) ? vf_percent($r, time()) : 0;
        $r['in_scope'] = in_array($r['status'], ['scheduled'], true) ? count(vf_scope($r)) : (int) $r['pieces'];
    }
    unset($r);
    plugin_json(['sales' => $rows, 'now' => time()]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'cancel') {
    if (!vf_cancel((int) ($in['id'] ?? 0))) plugin_json(['error' => 'Vente introuvable ou déjà terminée.'], 404);
    plugin_json(['ok' => true, 'message' => "Vente annulée : les prix d'origine sont rétablis."]);
}
if ($action === 'create') {
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    $start = strtotime((string) ($in['starts_at'] ?? ''));
    $end = strtotime((string) ($in['ends_at'] ?? ''));
    $stepMin = (int) ($in['step_minutes'] ?? 0);
    $stepPct = (float) str_replace(',', '.', (string) ($in['step_percent'] ?? 0));
    $maxPct = (float) str_replace(',', '.', (string) ($in['max_percent'] ?? 0));
    $refs = implode(',', array_filter(array_map(static fn ($r) => preg_replace('/[^A-Za-z0-9_-]/', '', trim($r)), explode(',', (string) ($in['refs'] ?? '')))));
    if ($name === '') plugin_json(['error' => 'Donnez un nom à la vente.'], 422);
    if (!$start || !$end || $end <= $start) plugin_json(['error' => 'Dates invalides : la fin doit suivre le début.'], 422);
    if ($end <= time()) plugin_json(['error' => 'La fin de la vente est déjà passée.'], 422);
    if ($stepMin < 1 || $stepMin > 1440) plugin_json(['error' => 'Le pas doit être de 1 à 1440 minutes.'], 422);
    if ($stepPct <= 0 || $stepPct > 50) plugin_json(['error' => 'La baisse par pas doit être comprise entre 0,1 et 50 %.'], 422);
    if ($maxPct < $stepPct || $maxPct > 90) plugin_json(['error' => 'Le plafond doit être au moins égal à la baisse par pas, et au plus 90 %.'], 422);
    db()->prepare('INSERT INTO vf_sales (name, starts_at, ends_at, step_minutes, step_percent, max_percent, refs) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$name, $start, $end, $stepMin, $stepPct, $maxPct, $refs]);
    vf_tick(true);
    plugin_json(['ok' => true, 'message' => 'Vente flash programmée.']);
}
plugin_json(['error' => 'Action invalide.'], 422);
