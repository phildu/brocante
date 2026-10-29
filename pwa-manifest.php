<?php
// Manifeste de la boutique (installation sur l'écran d'accueil du téléphone, « web app ») : nom, couleurs et icône de la boutique.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';

$theme = studio_theme_colors();
$site = trim((string) get_content()['site_name']) ?: (string) tenant('name');
$tag = trim((string) get_content()['site_tagline']) ?: (string) tenant('tagline');
$base = tenant_base_path();
// Nom court sous l'icône : 14 caractères au plus, sans couper un mot.
$short = mb_strlen($site) > 14 ? mb_substr($site, 0, 14) : $site;
if (mb_strlen($site) > 14 && ($sp = mb_strrpos($short, ' ')) > 3) $short = mb_substr($short, 0, $sp);
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => $site,
    'short_name' => $short,
    'description' => $tag,
    'lang' => 'fr',
    'id' => $base . '/',
    'start_url' => $base . '/?source=webapp',
    'scope' => $base . '/',
    'display' => 'standalone',
    'background_color' => $theme['bg'],
    'theme_color' => $theme['bg'],
    'icons' => [
        ['src' => $base . '/pwa-icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $base . '/pwa-icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
