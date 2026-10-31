<?php
// API de la bourse d'échanges : une seule route POST « action » (statut, note, suppression, publication d'une recherche).
// La session, le rôle et le jeton CSRF sont vérifiés par /admin/plugin-api.php avant d'arriver ici.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/bourse.php';

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$id = (int) ($in['id'] ?? 0);

switch ($action) {
    case 'status':
        $error = bourse_set_status($id, (string) ($in['status'] ?? ''));
        if ($error !== null) plugin_json(['error' => $error], 422);
        plugin_json(['ok' => true]);
    case 'note':
        bourse_set_note($id, (string) ($in['notes'] ?? ''));
        plugin_json(['ok' => true]);
    case 'delete':
        bourse_delete($id);
        plugin_json(['ok' => true]);
    case 'create':
        [$newId, $error] = bourse_create_search($in);
        if ($error !== null) plugin_json(['error' => $error], 422);
        plugin_json(['ok' => true, 'id' => $newId]);
    default:
        plugin_json(['error' => 'Action inconnue.'], 422);
}
