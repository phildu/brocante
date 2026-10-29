<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';

if (!addon_is_entitled(EV_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fidelite-membres.csv"');
        $q = static fn ($v) => '"' . str_replace('"', '""', ltrim((string) $v, "=+-@\t\r")) . '"';
        echo "\xEF\xBB\xBF" . "E-mail;Nom;Points;Points gagnés;Inscrit le;Dernière visite\r\n";
        foreach (ev_members_list(5000) as $m) echo implode(';', [$q($m['email']), $q($m['name']), (int) $m['points'], (int) $m['earned'], $q($m['created_at']), $q($m['last_seen_at'] ?? '')]) . "\r\n";
        exit;
    }
    plugin_json(['settings' => ev_settings(), 'tiers' => ev_tiers(), 'members' => ev_members_list(), 'stats' => ev_stats(), 'sections' => ev_sections(), 'app_url' => ev_app_url()]);
}
$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$err = match ($action) {
    'settings' => ev_settings_save($in),
    'adjust' => ev_adjust((int) ($in['id'] ?? 0), (int) ($in['points'] ?? 0), trim((string) ($in['note'] ?? ''))),
    'delete' => (static function () use ($in): ?string { ev_member_delete((int) ($in['id'] ?? 0)); return null; })(),
    default => 'Action invalide.',
};
if ($err) plugin_json(['error' => $err], 422);
plugin_json(['ok' => true, 'settings' => ev_settings(), 'tiers' => ev_tiers(), 'members' => ev_members_list(), 'stats' => ev_stats()]);
