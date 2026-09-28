<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json');

// Appel synchrone (pas de tâche de fond) : contrairement à l'amélioration de
// netteté (qui doit vraiment appeler Gemini en mode image-à-image, coûteux),
// une simple description texte reste rapide UNE FOIS l'image réduite avant
// l'envoi (voir downscale_for_ai()) — ce qui évite complètement de dépendre
// d'exec()/shell_exec(), désactivés sur un hébergement mutualisé comme OVH.

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['media']['tmp_name']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Fichier manquant.']);
    exit;
}

if (!GEMINI_API_KEY) {
    echo json_encode(['ok' => false, 'error' => 'Clé Gemini absente — suggestions IA indisponibles.']);
    exit;
}

$tmpName = $_FILES['media']['tmp_name'];
$origName = (string) ($_FILES['media']['name'] ?? '');

$info = @getimagesize($tmpName);
if (!$info) {
    // getimagesize() (et Gemini) ne lisent que JPEG/PNG/WEBP/GIF — un fichier
    // HEIC/HEIF (format par défaut des photos iPhone/Mac) a un type MIME
    // "image/..." côté navigateur mais échoue ici : le message doit le dire
    // clairement plutôt que de laisser croire qu'une vidéo a été envoyée.
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $error = in_array($ext, ['heic', 'heif'], true)
        ? "Format HEIC/HEIF non pris en charge (ni par la suggestion IA, ni par l'ajout à la médiathèque) — exportez d'abord la photo en JPEG : réglages iPhone → Appareil photo → Formats → \"Le plus compatible\"."
        : "Format d'image non reconnu pour la suggestion IA (JPEG, PNG, WEBP ou GIF attendu).";
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

[$width, $height] = $info;
$maxDim = 1400;
$resample = max($width, $height) > $maxDim
    ? "Cette image ({$width}×{$height}px) sera automatiquement réduite à son enregistrement pour un chargement rapide, tout en gardant une bonne qualité."
    : null;

$small = downscale_for_ai($tmpName) ?? $tmpName;
$text = gemini_describe_image($small, build_media_describe_prompt());
if ($small !== $tmpName) @unlink($small);

$suggestion = $text ? parse_media_describe_response($text) : null;
if (!$suggestion) {
    echo json_encode([
        'ok' => false,
        'error' => 'Suggestion IA indisponible pour le moment.',
        'resample' => $resample,
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'label' => $suggestion['label'],
    'tags' => $suggestion['tags'],
    'slug' => slugify($suggestion['label']),
    'resample' => $resample,
]);
