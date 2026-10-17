<?php
// POST {url} : importe une image ou une vidéo depuis une adresse web (voir com_fetch_url() pour les protections).
// Une adresse YouTube ou Vimeo est ajoutée comme vidéo en ligne (lien), sans téléchargement.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$url = trim((string) (plugin_input()['url'] ?? ''));
if ($url === '' || strlen($url) > 2000) plugin_json(['error' => 'Adresse manquante.'], 422);
if (com_parse_video_link($url)) plugin_json(['error' => 'Adresse YouTube ou Vimeo : utilisez « Ajouter une vidéo en ligne ».'], 422);
try {
    [$tmp, $name] = com_fetch_url($url, COM_VIDEO_MAX_BYTES);
    try {
        $item = com_store_file($tmp, $name, 'Import par adresse');
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e;
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    plugin_json(['error' => $e->getMessage()], 422);
}
plugin_json(['ok' => true, 'item' => $item]);
