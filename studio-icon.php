<?php
// Icône de l'application Studio : un appareil photo blanc sur la couleur d'accent du commerce.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio-theme.php';

$size = (int) ($_GET['s'] ?? 192);
$size = in_array($size, [120, 152, 167, 180, 192, 512], true) ? $size : 192;
$accent = studio_theme_colors()['accent'];

$img = imagecreatetruecolor($size, $size);
imageantialias($img, true);
[$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');
$bg = imagecolorallocate($img, $r, $g, $b);
$white = imagecolorallocate($img, 255, 255, 255);
imagefilledrectangle($img, 0, 0, $size, $size, $bg);

// Corps, bosse du viseur, objectif (tout reste dans les 80 % centraux : zone sûre des icônes « maskable »).
$u = $size / 100;
imagefilledrectangle($img, (int) (24 * $u), (int) (36 * $u), (int) (76 * $u), (int) (66 * $u), $white);
imagefilledrectangle($img, (int) (38 * $u), (int) (30 * $u), (int) (62 * $u), (int) (37 * $u), $white);
imagefilledellipse($img, (int) (50 * $u), (int) (51 * $u), (int) (22 * $u), (int) (22 * $u), $bg);
imagefilledellipse($img, (int) (50 * $u), (int) (51 * $u), (int) (14 * $u), (int) (14 * $u), $white);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($img);
