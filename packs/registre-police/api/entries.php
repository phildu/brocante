<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/pr.php';

if (!addon_is_entitled(PR_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    pr_sync(true);
    $rows = db()->query('SELECT * FROM pr_entries ORDER BY number DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['missing'] = pr_missing($r);
    unset($r);
    $missing = db()->query('SELECT ref, name FROM products WHERE ref NOT IN (SELECT product_ref FROM pr_entries) ORDER BY created_at DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    [$ok, $n, $bad] = pr_verify();
    plugin_json(['entries' => $rows, 'not_registered' => $missing, 'integrity' => ['ok' => $ok, 'lines' => $n, 'first_bad' => $bad], 'since' => pr_setting('since')]);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
if ($action === 'update') {
    $err = pr_update((int) ($in['id'] ?? 0), $in);
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true]);
}
if ($action === 'register') {
    $refs = array_slice(array_values(array_filter(array_map(static fn ($r) => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r), (array) ($in['refs'] ?? [])))), 0, 200);
    $n = 0;
    foreach ($refs as $r) if (pr_create($r)) $n++;
    plugin_json(['ok' => true, 'created' => $n]);
}
plugin_json(['error' => 'Action invalide.'], 422);
