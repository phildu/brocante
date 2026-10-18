<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * generate-web-images.php — Migration one-shot
 * Génère les versions _web.jpg pour toutes les images existantes.
 * À lancer UNE FOIS en CLI ou depuis le navigateur (admin seulement).
 * Supprimer ce fichier après usage.
 */

defined('UPLOAD_DIR') || define('UPLOAD_DIR', __DIR__ . '/../uploads/');
$MAX_PX  = 1200;
$QUALITY = 82;

if (php_sapi_name() !== 'cli') {
    // Protection minimale si accès navigateur
    require_once __DIR__ . '/../compat.php';
    requireAuth(['admin']);
    header('Content-Type: text/plain; charset=utf-8');
}

if (!function_exists('imagecreatefromjpeg')) {
    die("GD non disponible sur ce serveur.\n");
}

$images = glob(UPLOAD_DIR . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
$done = 0; $skipped = 0; $errors = 0;

foreach ($images as $src) {
    $fn   = basename($src);
    $base = pathinfo($fn, PATHINFO_FILENAME);

    // Ne pas retraiter les _web.jpg eux-mêmes
    if (str_ends_with($base, '_web')) { $skipped++; continue; }

    $webPath = UPLOAD_DIR . $base . '_web.jpg';
    if (file_exists($webPath)) { $skipped++; continue; }

    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    $im  = match($ext) {
        'jpg','jpeg' => @imagecreatefromjpeg($src),
        'png'        => @imagecreatefrompng($src),
        'gif'        => @imagecreatefromgif($src),
        'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default      => false,
    };

    if (!$im) {
        echo "ERREUR  $fn (lecture GD impossible)\n";
        $errors++;
        continue;
    }

    $srcW  = imagesx($im);
    $srcH  = imagesy($im);
    $ratio = min($MAX_PX / $srcW, $MAX_PX / $srcH, 1.0);
    $dstW  = (int)round($srcW * $ratio);
    $dstH  = (int)round($srcH * $ratio);

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Fond blanc pour PNG transparents
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);


    if (imagejpeg($dst, $webPath, $QUALITY)) {
        $origKo = (int)(filesize($src) / 1024);
        $webKo  = (int)(filesize($webPath) / 1024);
        echo "OK      $fn  {$srcW}x{$srcH} → {$dstW}x{$dstH}  {$origKo}Ko → {$webKo}Ko\n";
        $done++;
    } else {
        echo "ERREUR  $fn (écriture JPEG impossible)\n";
        $errors++;
    }

}

echo "\n--- Terminé : $done générés, $skipped ignorés, $errors erreurs ---\n";
