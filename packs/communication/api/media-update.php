<?php
// POST {path, title, alt, credit, tags, folder, in_gallery} : informations d'un élément. Le titre et les mots-clés d'une
// ressource de media_library y sont aussi recopiés, pour que la page classique et les sélecteurs affichent les mêmes.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$in = plugin_input();
$item = com_find_item((string) ($in['path'] ?? ''));
if (!$item) plugin_json(['error' => 'Élément introuvable dans la médiathèque.'], 404);
$cut = static fn (string $key, int $max): string => mb_substr(trim((string) ($in[$key] ?? $item[$key] ?? '')), 0, $max);
$fields = [
    'title' => $cut('title', 200),
    'alt' => $cut('alt', 300),
    'credit' => $cut('credit', 150),
    'tags' => com_tags((string) ($in['tags'] ?? $item['tags'])),
    'folder' => preg_replace('/\s+/', ' ', $cut('folder', 60)),
    'in_gallery' => !empty($in['in_gallery']) ? 1 : 0,
];
com_meta_save($item['path'], $fields);
if ($item['media_id']) {
    db()->prepare('UPDATE media_library SET label = ?, tags = ? WHERE id = ?')
        ->execute([$fields['title'] !== '' ? $fields['title'] : 'Média', $fields['tags'], $item['media_id']]);
}
plugin_json(['ok' => true, 'item' => com_find_item($item['path'])]);
