<?php
// Manifeste de l'application « administration de galerie » : le nom affiché sous l'icône est
// « Admin <nom de la galerie> » par défaut, remplaçable dans le portail (galerie → nom des applications).
//   GET ?g=<galerie>   (à défaut, la galerie de l'administrateur connecté)
require_once __DIR__ . '/../includes/galleries.php';

$slug = strtolower((string) ($_GET['g'] ?? ''));
if ($slug === '' && isset($_COOKIE[session_name()])) {
    @session_start();
    $slug = strtolower((string) ($_SESSION['gallery_admin']['slug'] ?? ''));
    @session_write_close();
}
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
if (!$gallery) {
    http_response_code(404);
    echo json_encode(['error' => 'Galerie introuvable.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = gallery_app_name($gallery, 'gallery_admin');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#b5502e';
$icon = '/galerie/icon.php?g=' . rawurlencode($slug);
echo json_encode([
    'id' => app_manifest_id('gallery_admin', '', $slug),
    'name' => $name,
    'short_name' => app_short_name($name, 14),
    'description' => trim((string) ($gallery['tagline'] ?? '')) ?: 'Administration de la galerie : commerces, exposants, plan et événement.',
    'lang' => 'fr',
    'dir' => 'ltr',
    'start_url' => '/portail/galerie-tableau.php?g=' . rawurlencode($slug) . '&source=webapp',
    'scope' => '/portail/',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation' => 'any',
    'categories' => ['business', 'productivity'],
    'prefer_related_applications' => false,
    'related_applications' => [],
    'background_color' => '#ffffff',
    'theme_color' => $accent,
    'icons' => [
        ['src' => $icon . '&s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon . '&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon . '&s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icon . '&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
