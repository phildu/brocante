<?php
// Capture jointe à un retour testeur. Les fichiers vivent hors du web
// (data/saas/feedback-media) : cette page les sert aux seuls comptes du
// portail, après vérification que le retour existe et que le nom demandé est
// bien celui de sa pièce jointe.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/feedback.php';

global $portailLocal;
if (empty($_SESSION['portail_user']) && empty($portailLocal)) {
    http_response_code(403);
    exit('Accès réservé au portail.');
}

$id = (string) ($_GET['id'] ?? '');
$record = feedback_find($id);
$name = (string) ($record['attachment'] ?? '');
if (!$record || $name === '' || (isset($_GET['f']) && !hash_equals($name, (string) $_GET['f']))) {
    http_response_code(404);
    exit('Capture introuvable.');
}

$path = feedback_media_path($name);
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('Capture introuvable.');
}

$info = @getimagesize($path);
$types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
$mime = $info ? ($types[$info[2]] ?? '') : '';
if ($mime === '') {
    http_response_code(415);
    exit('Format non pris en charge.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
