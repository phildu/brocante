<?php
// Icône de l'application Vente : une caisse blanche sur la couleur d'accent du commerce.
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

// Ticket qui sort de la caisse, corps, écran et tiroir — tout reste dans les 80 % centraux (zone sûre « maskable »).
$u = $size / 100;
imagefilledrectangle($img, (int) (41 * $u), (int) (24 * $u), (int) (59 * $u), (int) (42 * $u), $white);
imagefilledrectangle($img, (int) (24 * $u), (int) (40 * $u), (int) (76 * $u), (int) (72 * $u), $white);
imagefilledrectangle($img, (int) (31 * $u), (int) (46 * $u), (int) (69 * $u), (int) (56 * $u), $bg);
imagefilledrectangle($img, (int) (31 * $u), (int) (63 * $u), (int) (69 * $u), (int) (65 * $u), $bg);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($img);
