<?php
// Icône de la web app : le logo carré de la boutique (zone sûre des icônes « maskable ») sur la couleur de fond de la boutique ;
// à défaut de logo lisible, l'initiale de la boutique sur la couleur d'accent.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';

$size = (int) ($_GET['s'] ?? 192);
$size = in_array($size, [16, 32, 48, 120, 152, 167, 180, 192, 512], true) ? $size : 192;
$theme = studio_theme_colors();
$hex = static fn (string $c): array => sscanf($c, '#%02x%02x%02x');

$img = imagecreatetruecolor($size, $size);
imageantialias($img, true);
[$r, $g, $b] = $hex($theme['bg']);
imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));

$logo = null;
$rel = ltrim((string) logo_url('square'), '/');
$file = $rel !== '' ? realpath(__DIR__ . '/' . $rel) : false;
if ($file && str_starts_with($file, realpath(__DIR__)) && is_file($file) && filesize($file) < 8 * 1024 * 1024) {
    $logo = @imagecreatefromstring((string) file_get_contents($file)) ?: null;
}
if ($logo) {
    $box = (int) round($size * 0.74);                     // 80 % centraux : zone sûre « maskable »
    $w = imagesx($logo);
    $h = imagesy($logo);
    $k = min($box / $w, $box / $h);
    $dw = max(1, (int) round($w * $k));
    $dh = max(1, (int) round($h * $k));
    imagealphablending($img, true);
    imagecopyresampled($img, $logo, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
} else {
    [$r, $g, $b] = $hex($theme['accent']);
    $accent = imagecolorallocate($img, $r, $g, $b);
    imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.7), (int) ($size * 0.7), $accent);
    $name = trim((string) (get_content()['site_name'] ?? '')) ?: (string) tenant('name');
    $letter = mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $name), 0, 1));
    $font = null;
    foreach (['/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $f) if (is_file($f)) { $font = $f; break; }
    $white = imagecolorallocate($img, 255, 255, 255);
    if ($font && function_exists('imagettftext') && $letter !== '') {
        $fs = $size * 0.34;
        $bb = imagettfbbox($fs, 0, $font, $letter);
        imagettftext($img, $fs, 0, (int) (($size - ($bb[2] - $bb[0])) / 2 - $bb[0]), (int) (($size - ($bb[1] - $bb[7])) / 2 - $bb[7]), $white, $font, $letter);
    } else {
        imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.22), (int) ($size * 0.22), $white);
    }
}
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($img);
