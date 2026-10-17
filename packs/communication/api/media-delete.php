<?php
// POST {path} : retire une ressource de la médiathèque. Une photo de fiche se gère dans la galerie du produit. Le fichier n'est
// effacé du disque que s'il a été envoyé par le pack (uploads/<boutique>/) et n'est utilisé nulle part ailleurs dans la boutique.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$item = com_find_item((string) (plugin_input()['path'] ?? ''));
if (!$item) plugin_json(['error' => 'Élément introuvable dans la médiathèque.'], 404);
if (!$item['media_id']) plugin_json(['error' => 'Photo de fiche : supprimez-la depuis la galerie du produit.'], 409);

db()->prepare('DELETE FROM media_library WHERE id = ?')->execute([$item['media_id']]);
$fileKept = true;
if ($item['own'] && !com_is_referenced($item['path'])) {
    $meta = db()->prepare('SELECT web_path FROM com_media_meta WHERE path = ?');
    $meta->execute([$item['path']]);
    $web = (string) $meta->fetchColumn();
    @unlink(com_root() . '/' . $item['path']);
    if ($web !== '' && $web !== $item['path'] && com_is_own($web)) @unlink(com_root() . '/' . $web);
    db()->prepare('DELETE FROM com_media_meta WHERE path = ?')->execute([$item['path']]);
    $fileKept = false;
}
plugin_json(['ok' => true, 'file_kept' => $fileKept]);
