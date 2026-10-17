<?php
// POST {id} : supprime une note (administrateurs seulement, voir plugin.json).
defined('PLUGIN_RUNTIME') || exit;

$id = (int) (plugin_input()['id'] ?? 0);
$stmt = db()->prepare('DELETE FROM hello_notes WHERE id = ?');
$stmt->execute([$id]);
plugin_json(['ok' => $stmt->rowCount() === 1]);
