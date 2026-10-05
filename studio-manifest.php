<?php
// Manifeste de l'application Studio (installation sur l'écran d'accueil).
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';

$theme = studio_theme_colors();
$site = (string) get_content()['site_name'];
$base = tenant_base_path();
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => $site . ' — Studio',
    'short_name' => 'Studio',
    'description' => 'Photographier ses pièces et préparer les fiches de la boutique.',
    'lang' => 'fr',
    'start_url' => $base . '/studio.php',
    'scope' => $base . '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => $theme['bg'],
    'theme_color' => $theme['bg'],
    'icons' => [
        ['src' => $base . '/studio-icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $base . '/studio-icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
