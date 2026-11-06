<?php
// Manifeste de l'application Studio (installation sur l'écran d'accueil).
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';
require_once __DIR__ . '/includes/apps.php';

$theme = studio_theme_colors();
$base = tenant_base_path();
// « Article Nom du site » par défaut, renommable dans Administration → Réglages du site.
$name = shop_app_name('article');
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
    'id' => app_manifest_id('article', $base),
    'name' => $name,
    'short_name' => app_short_name($name, 15),
    'description' => 'Photographier ses pièces et préparer les fiches de la boutique.',
    'lang' => 'fr',
    'dir' => 'ltr',
    'start_url' => $base . '/studio.php?source=webapp',
    'scope' => $base . '/',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation' => 'portrait',
    'categories' => ['business', 'productivity'],
    'prefer_related_applications' => false,
    'related_applications' => [],
    'background_color' => $theme['bg'],
    'theme_color' => $theme['bg'],
    'icons' => [
        ['src' => shop_app_icon_url('article', 192, $base), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => shop_app_icon_url('article', 512, $base), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Vente', 'short_name' => 'Vente', 'url' => $base . '/caisse.php', 'icons' => [['src' => shop_app_icon_url('vente', 192, $base), 'sizes' => '192x192']]],
        ['name' => 'Mes pièces', 'short_name' => 'Pièces', 'url' => $base . '/studio.php#pieces', 'icons' => [['src' => shop_app_icon_url('article', 192, $base), 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
