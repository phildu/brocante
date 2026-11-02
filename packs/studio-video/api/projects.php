<?php
// Projets du Studio vidéo : GET (liste ou ?id=), POST (créer ou mettre à jour), DELETE (?id=).
// Seule la préparation est conservée ici (script, plans, montage) ; les fichiers vidéo restent dans le navigateur.
defined('PLUGIN_RUNTIME') || exit;

require_once dirname(__DIR__) . '/lib/stv.php';

$in = $method === 'POST' ? plugin_input() : [];
$id = (int) ($_GET['id'] ?? (is_array($in) ? ($in['id'] ?? 0) : 0));

if ($method === 'GET') {
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM studio_projects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) plugin_json(['error' => 'Projet introuvable.'], 404);
        plugin_json(['ok' => true, 'project' => stv_project_out($row)]);
    }
    $rows = db()->query('SELECT id, title, kind, target_seconds, status, updated_at FROM studio_projects ORDER BY updated_at DESC, id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
    plugin_json(['ok' => true, 'projects' => $rows]);
}

if ($method === 'DELETE') {
    if ($id <= 0) plugin_json(['error' => 'Projet manquant.'], 422);
    db()->prepare('DELETE FROM studio_projects WHERE id = ?')->execute([$id]);
    plugin_json(['ok' => true, 'deleted' => $id]);
}

if ($method !== 'POST') plugin_json(['error' => 'Méthode non autorisée.'], 405);

if (!is_array($in)) plugin_json(['error' => 'Données illisibles.'], 422);

$base = [];
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM studio_projects WHERE id = ?');
    $stmt->execute([$id]);
    $base = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$base) plugin_json(['error' => 'Projet introuvable.'], 404);
}

$fields = stv_clean_project($in, $base);
if ($fields['title'] === '') $fields['title'] = 'Vidéo du ' . date('d/m/Y');
$fields['status'] = $fields['script'] !== '' && $fields['shots'] ? ($fields['status'] === 'done' ? 'done' : 'ready') : 'draft';

$values = [
    $fields['title'], $fields['kind'], $fields['audience'], $fields['tone'], $fields['target_seconds'], $fields['format'],
    $fields['brief'], $fields['script'],
    json_encode($fields['shots'], JSON_UNESCAPED_UNICODE), json_encode($fields['titles'], JSON_UNESCAPED_UNICODE),
    json_encode($fields['checklist'], JSON_UNESCAPED_UNICODE), json_encode($fields['hashtags'], JSON_UNESCAPED_UNICODE),
    json_encode($fields['plan'], JSON_UNESCAPED_UNICODE), $fields['status'],
];

if ($id > 0) {
    db()->prepare('UPDATE studio_projects SET title = ?, kind = ?, audience = ?, tone = ?, target_seconds = ?, format = ?, brief = ?, script = ?, shots_json = ?, titles_json = ?, checklist_json = ?, hashtags_json = ?, plan_json = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute(array_merge($values, [$id]));
} else {
    db()->prepare('INSERT INTO studio_projects (title, kind, audience, tone, target_seconds, format, brief, script, shots_json, titles_json, checklist_json, hashtags_json, plan_json, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute($values);
    $id = (int) db()->lastInsertId();
}

$stmt = db()->prepare('SELECT * FROM studio_projects WHERE id = ?');
$stmt->execute([$id]);
plugin_json(['ok' => true, 'project' => stv_project_out((array) $stmt->fetch(PDO::FETCH_ASSOC))]);
