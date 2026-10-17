<?php
// POST (multipart) : envoi d'un fichier par morceaux de COM_CHUNK_BYTES (un seul morceau pour un petit fichier).
// Champs : upload_id (16 caractères hexadécimaux choisis par le navigateur), index, total, size, name, file (le morceau).
// Le premier morceau doit déjà être une image ou une vidéo ; le dernier recolle, contrôle et range le fichier.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$uploadId = (string) ($_POST['upload_id'] ?? '');
$index = (int) ($_POST['index'] ?? -1);
$total = (int) ($_POST['total'] ?? 0);
$size = (int) ($_POST['size'] ?? 0);
$name = mb_substr((string) ($_POST['name'] ?? 'media'), 0, 120);
$chunk = $_FILES['file'] ?? null;
if (!preg_match('/^[a-f0-9]{16}$/', $uploadId) || $total < 1 || $index < 0 || $index >= $total || $size < 1 || $size > COM_VIDEO_MAX_BYTES
    || $total !== (int) ceil($size / COM_CHUNK_BYTES)) {
    plugin_json(['error' => "L'envoi a échoué (paramètres invalides), réessayez."], 400);
}
if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || (int) $chunk['size'] !== ($index === $total - 1 ? $size - $index * COM_CHUNK_BYTES : COM_CHUNK_BYTES)) {
    plugin_json(['error' => "L'envoi a échoué (morceau incomplet), réessayez."], 400);
}
$part = com_tmp_dir() . '/' . tenant_slug() . '-' . $uploadId . '.part';
if ($index === 0) {
    com_tmp_cleanup();
    $kind = com_sniff_start($chunk['tmp_name']);
    if ($kind === null) plugin_json(['error' => 'Format non pris en charge (JPEG, PNG, WebP, GIF, MP4, MOV ou WebM).'], 415);
    if ($size > ($kind === 'photo' ? COM_IMAGE_MAX_BYTES : COM_VIDEO_MAX_BYTES)) {
        plugin_json(['error' => 'Fichier trop lourd (' . round(($kind === 'photo' ? COM_IMAGE_MAX_BYTES : COM_VIDEO_MAX_BYTES) / 1048576) . ' Mo au plus).'], 413);
    }
    file_put_contents($part, '');
}
if (!is_file($part) || filesize($part) < $index * COM_CHUNK_BYTES) plugin_json(['error' => "L'envoi a été interrompu : renvoyez le fichier."], 409);
$fh = fopen($part, 'cb');
if (!$fh || !flock($fh, LOCK_EX)) plugin_json(['error' => "L'envoi a échoué, réessayez."], 500);
ftruncate($fh, $index * COM_CHUNK_BYTES);
fseek($fh, 0, SEEK_END);
$written = fwrite($fh, (string) file_get_contents($chunk['tmp_name']));
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
if ($written !== (int) $chunk['size']) plugin_json(['error' => 'Disque plein ou écriture impossible, réessayez.'], 507);
if ($index < $total - 1) plugin_json(['ok' => true, 'done' => false]);

clearstatcache(true, $part);
if (filesize($part) !== $size) {
    @unlink($part);
    plugin_json(['error' => 'Fichier incomplet : renvoyez-le.'], 422);
}
try {
    $item = com_store_file($part, $name, 'Médiathèque (envoi)');
} catch (InvalidArgumentException $e) {
    @unlink($part);
    plugin_json(['error' => $e->getMessage()], 415);
}
plugin_json(['ok' => true, 'done' => true, 'item' => $item]);
