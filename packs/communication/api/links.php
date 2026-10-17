<?php
// GET : vidéos en ligne (YouTube, Vimeo). POST {url, title, folder} : en ajoute une (lien seulement, rien n'est téléchargé).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

if ($method === 'POST') {
    $in = plugin_input();
    $video = com_parse_video_link((string) ($in['url'] ?? ''));
    if (!$video) plugin_json(['error' => 'Adresse YouTube ou Vimeo attendue.'], 422);
    $url = $video['platform'] === 'youtube' ? 'https://www.youtube.com/watch?v=' . $video['video_id'] : 'https://vimeo.com/' . $video['video_id'];
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200) ?: ($video['platform'] === 'youtube' ? 'Vidéo YouTube' : 'Vidéo Vimeo');
    db()->prepare('INSERT INTO com_media_links (url, platform, video_id, title, folder) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(platform, video_id) DO UPDATE SET title = excluded.title, folder = excluded.folder')
        ->execute([$url, $video['platform'], $video['video_id'], $title, mb_substr(trim((string) ($in['folder'] ?? '')), 0, 60)]);
}
plugin_json(['ok' => true, 'links' => com_links()]);
