<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

$fields = [
    'site_name', 'site_tagline',
    'hero_eyebrow', 'hero_title', 'hero_subtitle',
    'story_eyebrow', 'story_title', 'story_text', 'story_photo_caption',
    'principle1_title', 'principle1_text',
    'principle2_title', 'principle2_text',
    'principle3_title', 'principle3_text',
    'contact_address', 'contact_hours', 'contact_delivery',
    'shipping_fee', 'pickup_label', 'shipping_label',
];

$set = [];
$params = [];
foreach ($fields as $f) {
    $set[] = "$f = :$f";
    $params[$f] = trim((string) ($_POST[$f] ?? ''));
}

// Un fichier envoyé prime sur une sélection médiathèque (le JS vide déjà
// l'input file quand on choisit dans la médiathèque, mais on reste défensif).
// La sélection médiathèque n'est acceptée que si le chemin existe bien dans
// media_library, pour ne pas écrire un chemin arbitraire depuis le champ caché.
function media_photo_path(string $postField): ?string
{
    $path = trim((string) ($_POST[$postField] ?? ''));
    if ($path === '') return null;
    $stmt = db()->prepare("SELECT path FROM media_library WHERE path = ? AND type = 'photo' LIMIT 1");
    $stmt->execute([$path]);
    return $stmt->fetchColumn() ?: null;
}

$heroPhoto = store_uploaded_photo('hero_photo', 'hero') ?: media_photo_path('hero_photo_media');
if ($heroPhoto) { $set[] = 'hero_photo = :hero_photo'; $params['hero_photo'] = $heroPhoto; }

$storyPhoto = store_uploaded_photo('story_photo', 'story') ?: media_photo_path('story_photo_media');
if ($storyPhoto) { $set[] = 'story_photo = :story_photo'; $params['story_photo'] = $storyPhoto; }

$sql = 'UPDATE content SET ' . implode(', ', $set) . ' WHERE id = 1';
$stmt = db()->prepare($sql);
$stmt->execute($params);

flash_set('Contenus mis à jour.');
header('Location: /admin/index.php');
exit;
