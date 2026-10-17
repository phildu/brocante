<?php
// POST {id} : retire une vidéo en ligne de la médiathèque.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$stmt = db()->prepare('DELETE FROM com_media_links WHERE id = ?');
$stmt->execute([(int) (plugin_input()['id'] ?? 0)]);
plugin_json(['ok' => $stmt->rowCount() === 1, 'links' => com_links()]);
