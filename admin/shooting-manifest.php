<?php
// Manifeste de la web app de shooting (écran d'accueil du téléphone) : icône composée à partir du logo carré de la boutique,
// comme l'icône du site (pwa-icon.php), pour que l'appli enregistrée porte l'identité de la boutique.
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/studio-theme.php';

$theme = studio_theme_colors();
$base = tenant_base_path();
$site = trim((string) (get_content()['site_name'] ?? '')) ?: (string) tenant('name');

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo json_encode([
    'name' => $site . ' — shooting',
    'short_name' => 'Shooting',
    'lang' => 'fr',
    'id' => $base . '/admin/shop-shooting.php',
    'start_url' => $base . '/admin/shop-shooting.php',
    'scope' => $base . '/admin/',
    'display' => 'standalone',
    'background_color' => $theme['bg'],
    'theme_color' => $theme['bg'],
    'icons' => [
        ['src' => $base . '/pwa-icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $base . '/pwa-icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
