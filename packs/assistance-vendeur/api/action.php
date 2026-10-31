<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$by = (string) (($session['name'] ?? '') !== '' ? $session['name'] : 'administrateur');
$id = (int) ($in['id'] ?? 0);

$alert = static function (int $id): ?array {
    $st = db()->prepare('SELECT * FROM av_alerts WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    return $a ?: null;
};

if ($action === 'seen') {
    $upTo = (int) ($in['up_to'] ?? 0);
    db()->prepare('UPDATE av_alerts SET seen_at = ? WHERE id <= ? AND seen_at IS NULL')->execute([time(), $upTo]);
    plugin_json(['ok' => true, 'counts' => ['unseen' => (int) db()->query('SELECT COUNT(*) FROM av_alerts WHERE seen_at IS NULL')->fetchColumn()]]);
}
if ($action === 'ack') {
    $a = $alert($id);
    if (!$a) plugin_json(['error' => 'Alerte introuvable.'], 404);
    db()->prepare('UPDATE av_alerts SET handled_at = ?, handled_by = ?, action = ? WHERE id = ?')
        ->execute([time(), $by, 'traité', $id]);
    plugin_json(av_admin_state() + ['message' => 'Alerte traitée.']);
}
if ($action === 'hold') {
    $a = $alert($id);
    if (!$a) plugin_json(['error' => 'Alerte introuvable.'], 404);
    $minutes = (int) ($in['minutes'] ?? av_setting('hold_minutes', '20'));
    $scope = (string) ($in['scope'] ?? 'visitor') === 'all' ? 'all' : 'visitor';
    $visitor = $scope === 'visitor' ? (string) $a['visitor'] : '';
    if ($err = av_hold_create((string) $a['ref'], $visitor, $minutes, $by, $id)) plugin_json(['error' => $err], 422);
    db()->prepare('UPDATE av_alerts SET action = ?, seen_at = COALESCE(seen_at, ?) WHERE id = ?')
        ->execute(['pièce retenue ' . $minutes . ' min', time(), $id]);
    plugin_json(av_admin_state() + ['message' => 'Pièce retenue ' . $minutes . ' minutes'
        . ($scope === 'visitor' ? ' pour ce visiteur' : ' (vente en ligne suspendue pour tous)') . '.']);
}
if ($action === 'release') {
    $n = av_hold_release((string) ($in['ref'] ?? ($alert($id)['ref'] ?? '')), $id);
    plugin_json(av_admin_state() + ['message' => $n > 0 ? 'Pièce de nouveau en vente.' : 'Aucune retenue à lever.']);
}
plugin_json(['error' => 'Action invalide.'], 422);