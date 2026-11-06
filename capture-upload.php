<?php
// Reçoit UN fichier (photo ou courte vidéo) envoyé par la page de prise de vue
// du téléphone (capture.php) et l'ajoute à la médiathèque. Pas de connexion :
// le jeton du code QR autorise cet envoi, rien d'autre. Réponse JSON.
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function capture_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    capture_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}

// Requête plus lourde que post_max_size : PHP la vide entièrement (jeton compris), ce n'est pas un code expiré.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    capture_reply(['ok' => false, 'code' => 'too_big', 'error' => 'Fichier trop lourd pour ce serveur (maximum ' . round(capture_max_file_bytes() / 1048576) . ' Mo) : filmez plus court ou en qualité plus basse.'], 413);
}

$session = capture_session_by_token((string) ($_POST['t'] ?? ''));
if (!$session) {
    capture_reply(['ok' => false, 'code' => 'expired', 'error' => 'Ce code a expiré ou a été fermé : affichez-en un nouveau sur l\'ordinateur.'], 403);
}
if (capture_upload_count((int) $session['id']) >= CAPTURE_MAX_UPLOADS) {
    capture_reply(['ok' => false, 'code' => 'full', 'error' => 'Nombre maximal de fichiers atteint pour ce code (' . CAPTURE_MAX_UPLOADS . ') : affichez-en un nouveau.'], 429);
}

// Vidéo envoyée par morceaux : chaque requête porte un morceau (≤ 5 Mo), recollé ici.
if (isset($_POST['upload_id'])) {
    $uploadId = (string) $_POST['upload_id'];
    $index = (int) ($_POST['index'] ?? -1);
    $total = (int) ($_POST['total'] ?? 0);
    $size = (int) ($_POST['size'] ?? 0);
    $chunkBytes = capture_chunk_bytes();
    if (!preg_match('/^[a-f0-9]{16}$/', $uploadId) || $total < 1 || $index < 0 || $index >= $total
        || $size < 1 || $size > CAPTURE_VIDEO_MAX_BYTES || $total !== (int) ceil($size / $chunkBytes)) {
        capture_reply(['ok' => false, 'code' => 'upload', 'error' => "L'envoi a échoué, réessayez."], 400);
    }
    $chunk = $_FILES['file'] ?? null;
    if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $chunk['size'] > $chunkBytes
        || $chunk['size'] !== ($index === $total - 1 ? $size - $index * $chunkBytes : $chunkBytes)) {
        capture_reply(['ok' => false, 'code' => 'upload', 'error' => "L'envoi a échoué, réessayez."], 400);
    }

    $part = capture_part_path($session, $uploadId);
    if ($index === 0) {
        capture_part_cleanup();
        // Contrôle précoce : le premier morceau d'une vidéo en montre déjà le format.
        if (!in_array(capture_sniff_mime($chunk['tmp_name']), CAPTURE_VIDEO_MIMES, true)) {
            capture_reply(['ok' => false, 'code' => 'type', 'error' => 'Format non pris en charge (photo, ou vidéo mp4, mov, webm).'], 415);
        }
        file_put_contents($part, '');
    }
    // Un morceau renvoyé après coupure de réseau : on repart de son début, sans doublon.
    if (!is_file($part) || filesize($part) < $index * $chunkBytes) {
        capture_reply(['ok' => false, 'code' => 'order', 'error' => "L'envoi a été interrompu : renvoyez la vidéo."], 409);
    }
    $fh = fopen($part, 'cb');
    if (!$fh || !flock($fh, LOCK_EX)) {
        capture_reply(['ok' => false, 'code' => 'upload', 'error' => "L'envoi a échoué, réessayez."], 500);
    }
    ftruncate($fh, $index * $chunkBytes);
    fseek($fh, 0, SEEK_END);
    $written = fwrite($fh, (string) file_get_contents($chunk['tmp_name']));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($written !== (int) $chunk['size']) {
        capture_reply(['ok' => false, 'code' => 'upload', 'error' => 'Disque plein ou écriture impossible, réessayez.'], 507);
    }
    capture_session_touch((int) $session['id']);

    if ($index < $total - 1) {
        capture_reply(['ok' => true, 'done' => false]);
    }

    clearstatcache(true, $part);
    if (filesize($part) !== $size || !in_array(capture_sniff_mime($part), CAPTURE_VIDEO_MIMES, true)) {
        @unlink($part);
        capture_reply(['ok' => false, 'code' => 'upload', 'error' => 'Vidéo incomplète ou illisible, renvoyez-la.'], 422);
    }
    $path = capture_store_video($part, (string) ($_POST['name'] ?? 'video.mp4'));
    if (!$path) {
        @unlink($part);
        capture_reply(['ok' => false, 'code' => 'type', 'error' => 'Format non pris en charge (photo, ou vidéo mp4, mov, webm).'], 415);
    }
    $mediaId = add_media_item('video', $path, 'Vidéo du téléphone ' . date('d/m H:i'), 'Téléphone (code QR)', '');
    capture_record_upload((int) $session['id'], $mediaId);
    capture_reply(['ok' => true, 'done' => true, 'type' => 'video', 'id' => $mediaId, 'count' => capture_upload_count((int) $session['id'])]);
}

// Un fichier trop gros pour PHP arrive vide (post_max_size dépassé) ou en erreur.
$upload = $_FILES['file'] ?? null;
if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $tooBig = !$upload || in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || empty($_POST);
    capture_reply(['ok' => false, 'code' => $tooBig ? 'too_big' : 'upload', 'error' => $tooBig
        ? 'Fichier trop lourd pour ce serveur (maximum ' . round(capture_max_file_bytes() / 1048576) . ' Mo) : filmez plus court ou en qualité plus basse.'
        : "L'envoi a échoué, réessayez."], 413);
}

// Vérifie le contenu réel, pas seulement l'extension : une image lisible, ou une vidéo reconnue.
$tmp = $upload['tmp_name'];
$isImage = (bool) @getimagesize($tmp);
if (!$isImage) {
    $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) : 'video/mp4';
    if (!in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'], true)) {
        capture_reply(['ok' => false, 'code' => 'type', 'error' => 'Format non pris en charge (photo, ou vidéo mp4, mov, webm).'], 415);
    }
}

$result = store_uploaded_media('file', 'telephone');
if (!$result) {
    capture_reply(['ok' => false, 'code' => 'type', 'error' => 'Fichier non pris en charge (photo, ou vidéo mp4, mov, webm).'], 415);
}

$label = ($result['type'] === 'video' ? 'Vidéo' : 'Photo') . ' du téléphone ' . date('d/m H:i');
$mediaId = add_media_item($result['type'], $result['path'], $label, 'Téléphone (code QR)', '');
capture_record_upload((int) $session['id'], $mediaId);

capture_reply(['ok' => true, 'type' => $result['type'], 'id' => $mediaId, 'count' => capture_upload_count((int) $session['id'])]);
