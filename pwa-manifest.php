<?php
// Manifeste de la boutique (installation sur l'écran d'accueil du téléphone, « web app ») : nom, couleurs et icône de la boutique.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';
require_once __DIR__ . '/includes/apps.php';

$theme = studio_theme_colors();
$tag = trim((string) get_content()['site_tagline']) ?: (string) tenant('tagline');
$base = tenant_base_path();
// Nom affiché sous l'icône : personnalisé dans Administration → Réglages du site, sinon le nom de la boutique.
$name = shop_app_name('shop');
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
    'id' => app_manifest_id('shop', $base),
    'name' => $name,
    'short_name' => app_short_name($name, 14),
    'description' => $tag,
    'lang' => 'fr',
    'dir' => 'ltr',
    'start_url' => $base . '/?source=webapp',
    'scope' => $base . '/',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation' => 'any',
    'categories' => ['shopping', 'lifestyle'],
    'prefer_related_applications' => false,
    'related_applications' => [],
    'background_color' => $theme['bg'],
    'theme_color' => $theme['bg'],
    'icons' => [
        ['src' => shop_app_icon_url('shop', 192, $base), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => shop_app_icon_url('shop', 512, $base), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
