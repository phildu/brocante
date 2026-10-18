<?php
// GET : toute la médiathèque de la boutique (éléments, vidéos en ligne, dossiers, espace occupé, limites d'envoi).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$items = array_values(com_items());
$links = com_links();
$folders = array_values(array_unique(array_filter(array_merge(array_column($items, 'folder'), array_column($links, 'folder')), 'strlen')));
sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
$ownBytes = array_sum(array_map(static fn (array $i): int => $i['own'] ? (int) $i['bytes'] : 0, $items));
plugin_json([
    'items' => $items,
    'links' => $links,
    'folders' => $folders,
    'stats' => ['count' => count($items) + count($links), 'own_bytes' => $ownBytes, 'missing_web' => count(array_filter($items, static fn ($i) => $i['type'] === 'photo' && !$i['web_url'] && !$i['missing']))],
    'limits' => ['chunk' => COM_CHUNK_BYTES, 'image' => COM_IMAGE_MAX_BYTES, 'video' => COM_VIDEO_MAX_BYTES],
    'ai' => com_ai_ready(),
]);
