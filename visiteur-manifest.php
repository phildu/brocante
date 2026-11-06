<?php
// Manifeste de la web app des visiteurs (installation sur l'écran d'accueil du téléphone) : nom et icône de la boutique, ouverture sur /visiteur.php.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

$name = (string) (get_content()['site_name'] ?? tenant('name'));
$icon = ltrim(logo_url('square'), '/');
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => $name . ' — espace visiteur',
    'short_name' => mb_substr($name, 0, 12),
    'start_url' => '/visiteur.php',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#2f6b4f',
    'icons' => $icon !== '' ? [['src' => '/' . $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']] : [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
