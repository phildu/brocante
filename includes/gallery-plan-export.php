<?php

// Export haute résolution du plan d'implantation : le plan (fond IGN ou fond importé/stylisé, zones,
// emplacements, tracés, repères, légende, échelle) est rendu côté serveur en 300 dpi, puis emballé
// soit en PDF imprimable (image JPEG pleine page), soit en PSD aplati (compression RLE, résolution
// 300 dpi) importable dans Photoshop et InDesign. Appelé par portail/galerie-plan.php (action « export »).
// Aucun composant externe : GD pour le rendu, PDF et PSD écrits à la main.

const GPX_DPI = 300;

/** Formats de page proposés : pixels à 300 dpi et points PDF (paysage). */
function gpx_pages(): array
{
    return [
        'a4' => ['label' => 'A4 paysage', 'px' => [3508, 2480], 'pt' => [841.89, 595.28]],
        'a3' => ['label' => 'A3 paysage', 'px' => [4961, 3508], 'pt' => [1190.55, 841.89]],
    ];
}

function gpx_mercator(float $lat, float $lng): array
{
    return [$lng * 20037508.34 / 180.0, log(tan((90.0 + $lat) * M_PI / 360.0)) * 20037508.34 / M_PI];
}

/** Emprise [sud, ouest, nord, est] de tout le plan (éléments + fond importé), avec une marge de 8 %. */
function gpx_bounds(array $map): array
{
    $lats = [];
    $lngs = [];
    foreach ((array) ($map['items'] ?? []) as $it) {
        foreach ((array) ($it['pts'] ?? []) as $p) {
            if (is_array($p) && isset($p[0], $p[1])) { $lats[] = (float) $p[0]; $lngs[] = (float) $p[1]; }
        }
    }
    foreach ((array) ($map['background']['bounds'] ?? []) as $p) {
        if (is_array($p) && isset($p[0], $p[1])) { $lats[] = (float) $p[0]; $lngs[] = (float) $p[1]; }
    }
    if (!$lats) {
        $c = (array) ($map['center'] ?? [46.6, 2.4]);
        $lats = [(float) $c[0] - 0.002, (float) $c[0] + 0.002];
        $lngs = [(float) $c[1] - 0.003, (float) $c[1] + 0.003];
    }
    $s = min($lats); $n = max($lats); $w = min($lngs); $e = max($lngs);
    $dlat = max(($n - $s) * 0.08, 1e-4);
    $dlng = max(($e - $w) * 0.08, 1.5e-4);
    return [$s - $dlat, $w - $dlng, $n + $dlat, $e + $dlng];
}

/** Police TrueType disponible (macOS, Linux, Windows) ; null si aucune (repli sur les polices GD). */
function gpx_font(): ?string
{
    foreach ([
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
        '/Library/Fonts/Arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        'C:/Windows/Fonts/arial.ttf',
    ] as $f) if (is_file($f)) return $f;
    return null;
}

/** Texte à la position donnée ; imagettftext si une police existe, sinon police GD agrandie. */
function gpx_text(GdImage $img, float $x, float $y, string $text, int $size, int $color, ?string $font, bool $bold = false, string $align = 'left'): void
{
    if ($text === '') return;
    if ($font !== null) {
        $box = imagettfbbox($size, 0, $font, $text);
        $w = $box[2] - $box[0];
        if ($align === 'center') $x -= $w / 2;
        elseif ($align === 'right') $x -= $w;
        imagettftext($img, $size, 0, (int) round($x), (int) round($y), $color, $font, $text);
        return;
    }
    // Repli : police bitmap GD rendue puis agrandie à la taille voulue.
    $tmp = imagecreatetruecolor(max(1, imagefontwidth(5) * strlen($text)) + 4, imagefontheight(5) + 4);
    imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    imagestring($tmp, 5, 2, 2, $text, imagecolorallocate($tmp, 0, 0, 0));
    $tw = imagefontwidth(5) * strlen($text) + 4;
    $th = imagefontheight(5) + 4;
    $scale = $size / imagefontheight(5);
    $dw = (int) round($tw * $scale);
    $dh = (int) round($th * $scale);
    $dst = imagecreatetruecolor($dw, $dh);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $tmp, 0, 0, 0, 0, $dw, $dh, $tw, $th);
    if ($align === 'center') $x -= $dw / 2;
    elseif ($align === 'right') $x -= $dw;
    imagecopy($img, $dst, (int) round($x), (int) round($y - $dh * 0.8), 0, 0, $dw, $dh);
}

/** Capture JPEG du fond IGN (WMS Géoplateforme, sans clé) pour une emprise Web Mercator donnée. */
function gpx_wms(array $bbox, string $base, int $width, int $height): ?string
{
    $sat = $base === 'sat';
    $url = 'https://data.geopf.fr/wms-r/wms?' . http_build_query([
        'SERVICE' => 'WMS', 'VERSION' => '1.3.0', 'REQUEST' => 'GetMap',
        'LAYERS' => $sat ? 'ORTHOIMAGERY.ORTHOPHOTOS' : 'GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2',
        'STYLES' => $sat ? '' : 'normal', 'FORMAT' => 'image/jpeg',
        'CRS' => 'EPSG:3857', 'BBOX' => implode(',', array_map(static fn ($v) => round($v, 3), $bbox)),
        'WIDTH' => max(1, $width), 'HEIGHT' => max(1, $height),
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Bricbrok-galerie/1.0 (+https://bricbrok.com)', CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if ($body === false || $code !== 200 || !str_starts_with($type, 'image/')) return null;
    return (string) $body;
}

/**
 * Rend le plan en 300 dpi pour le format de page choisi. -> GdImage.
 * Lève RuntimeException si le plan est vide ou si le fond IGN est indisponible sans fond de repli.
 */
function gpx_render(array $gallery, array $map, string $pageKey): GdImage
{
    $pages = gpx_pages();
    if (!isset($pages[$pageKey])) $pageKey = 'a4';
    [$W, $H] = $pages[$pageKey]['px'];
    $img = imagecreatetruecolor($W, $H);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white);
    $ink = imagecolorallocate($img, 35, 32, 28);
    $soft = imagecolorallocate($img, 110, 104, 96);
    $font = gpx_font();

    [$bs, $bw, $bn, $be] = gpx_bounds($map);
    [$mx0, $my0] = gpx_mercator($bs, $bw);
    [$mx1, $my1] = gpx_mercator($bn, $be);
    $mercW = $mx1 - $mx0;
    $mercH = $my1 - $my0;

    // Zone de carte : toute la page moins une marge ; le contenu est centré sans déformation.
    $mgX = (int) round($W * 0.03);
    $mgY = (int) round($H * 0.04);
    $areaX = $mgX; $areaY = $mgY; $areaW = $W - 2 * $mgX; $areaH = $H - 2 * $mgY;
    $scale = min($areaW / $mercW, $areaH / $mercH);
    $drawW = (int) round($mercW * $scale);
    $drawH = (int) round($mercH * $scale);
    $offX = $areaX + (int) (($areaW - $drawW) / 2);
    $offY = $areaY + (int) (($areaH - $drawH) / 2);
    $px = static function (float $lat, float $lng) use ($mx0, $my1, $scale, $offX, $offY): array {
        [$x, $y] = gpx_mercator($lat, $lng);
        return [$offX + ($x - $mx0) * $scale, $offY + ($my1 - $y) * $scale];
    };

    // Fond : image importée / stylisée si présente, sinon capture IGN de l'emprise, sinon grille papier.
    $bg = null;
    $bgPath = (string) ($map['background']['path'] ?? '');
    if ($bgPath !== '' && is_file(dirname(__DIR__) . '/' . $bgPath)) {
        $bg = @imagecreatefromstring((string) file_get_contents(dirname(__DIR__) . '/' . $bgPath));
    }
    if ($bg) {
        [$bs0, $bw0] = $map['background']['bounds'][0];
        [$bn0, $be0] = $map['background']['bounds'][1];
        [$gx0, $gy0] = $px($bn0, $bw0);   // coin haut-gauche du fond
        [$gx1, $gy1] = $px($bs0, $be0);   // coin bas-droit
        imagecopyresampled($img, $bg, (int) round($gx0), (int) round($gy0), 0, 0,
            (int) round($gx1 - $gx0), (int) round($gy1 - $gy0), imagesx($bg), imagesy($bg));
    } elseif (($map['base'] ?? 'osm') !== 'none') {
        $wms = gpx_wms([$mx0, $my0, $mx1, $my1], (string) ($map['base'] ?? 'osm'), $drawW, $drawH);
        if ($wms !== null) {
            $tile = @imagecreatefromstring($wms);
            if ($tile) {
                imagecopyresampled($img, $tile, $offX, $offY, 0, 0, $drawW, $drawH, imagesx($tile), imagesy($tile));
            }
        }
    } else {
        $grid = imagecolorallocate($img, 226, 221, 210);
        $step = max(20, (int) round($H / 60));
        for ($x = $offX; $x <= $offX + $drawW; $x += $step) imageline($img, $x, $offY, $x, $offY + $drawH, $grid);
        for ($y = $offY; $y <= $offY + $drawH; $y += $step) imageline($img, $offX, $y, $offX + $drawW, $y, $grid);
    }

    // Éléments du plan : zones et territoires (remplis translucides), emplacements, tracés, repères.
    $alphaOf = static fn (int $hex, int $alpha): int => imagecolorallocatealpha($img, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255, $alpha);
    $hexOf = static function (string $c): int {
        $c = ltrim($c, '#');
        return $c !== '' ? (int) hexdec($c) : 0xb5502e;
    };
    foreach ((array) ($map['items'] ?? []) as $it) {
        $pts = [];
        foreach ((array) ($it['pts'] ?? []) as $p) if (is_array($p) && isset($p[0], $p[1])) $pts[] = $px((float) $p[0], (float) $p[1]);
        if (!$pts) continue;
        $hex = $hexOf((string) ($it['color'] ?? '#b5502e'));
        $kind = (string) ($it['kind'] ?? '');
        if ($kind === 'polygon') {
            $flat = [];
            foreach ($pts as $p) { $flat[] = (int) round($p[0]); $flat[] = (int) round($p[1]); }
            $isZone = in_array((string) ($it['role'] ?? ''), ['zone', 'territoire'], true);
            imagefilledpolygon($img, $flat, $alphaOf($hex, $isZone ? 88 : 55));
            imagesetthickness($img, $isZone ? 4 : 3);
            imagepolygon($img, $flat, imagecolorallocate($img, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255));
            imagesetthickness($img, 1);
        } elseif ($kind === 'line') {
            imagesetthickness($img, 4);
            for ($i = 1; $i < count($pts); $i++) {
                imageline($img, (int) round($pts[$i - 1][0]), (int) round($pts[$i - 1][1]), (int) round($pts[$i][0]), (int) round($pts[$i][1]),
                    imagecolorallocate($img, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255));
            }
            imagesetthickness($img, 1);
        } else {
            [$cx, $cy] = $pts[0];
            $r = max(8, (int) round($H / 260));
            imagefilledellipse($img, (int) $cx, (int) $cy, $r * 2, $r * 2, imagecolorallocate($img, ($hex >> 16) & 255, ($hex >> 8) & 255, $hex & 255));
            imageellipse($img, (int) $cx, (int) $cy, $r * 2 + 6, $r * 2 + 6, $white);
        }
        // Étiquette : numéro pour un emplacement, nom pour une zone ou un repère.
        $label = trim((string) ($it['num'] ?? '')) !== '' ? (string) $it['num'] : (string) ($it['label'] ?? '');
        if ($label === '') continue;
        $cx = 0; $cy = 0;
        foreach ($pts as $p) { $cx += $p[0]; $cy += $p[1]; }
        $cx /= count($pts); $cy /= count($pts);
        $size = max(14, (int) round($H / 150));
        if ($kind === 'marker') {
            $box = imagecolorallocatealpha($img, 255, 255, 255, 30);
            imagefilledrectangle($img, (int) ($cx + 14), (int) ($cy - $size), (int) ($cx + 14 + strlen($label) * $size * 0.62), (int) ($cy + 6), $box);
            gpx_text($img, $cx + 18, $cy + $size * 0.35, $label, $size, $ink, $font, true);
        } else {
            $tw = strlen($label) * $size * 0.6;
            imagefilledrectangle($img, (int) ($cx - $tw / 2 - 4), (int) ($cy - $size), (int) ($cx + $tw / 2 + 4), (int) ($cy + 6), imagecolorallocatealpha($img, 255, 255, 255, 40));
            gpx_text($img, $cx, $cy + $size * 0.35, $label, $size, $ink, $font, true, 'center');
        }
    }

    // Cartouche : titre, date, échelle graphique, nord et mention du fond.
    $pad = (int) round($W * 0.012);
    $titleSize = max(26, (int) round($H / 62));
    $metaSize = max(15, (int) round($H / 130));
    $name = trim((string) ($gallery['name'] ?? 'Plan'));
    imagefilledrectangle($img, $pad, $pad, $pad + (int) ($W * 0.42), $pad + (int) ($titleSize * 2.6), imagecolorallocatealpha($img, 255, 255, 255, 25));
    gpx_text($img, $pad + 14, $pad + $titleSize + 6, $name, $titleSize, $ink, $font, true);
    gpx_text($img, $pad + 14, $pad + $titleSize * 2.2, 'Plan d\'implantation — ' . date('d/m/Y'), $metaSize, $soft, $font);

    // Échelle graphique : distance ronde tenant dans un quart de la largeur.
    $latc = ($bs + $bn) / 2;
    $mpp = cos(deg2rad($latc)) / $scale;   // mètres au sol par pixel
    $target = $mpp * ($W * 0.22);
    $nice = 5;
    foreach ([5, 10, 20, 50, 100, 200, 500, 1000, 2000] as $cand) if ($cand <= $target) $nice = $cand;
    $barPx = (int) round($nice / $mpp);
    $bx = $pad + 14;
    $by = $H - $pad - (int) ($metaSize * 2.2);
    imagefilledrectangle($img, $bx - 8, $by - $metaSize - 10, $bx + $barPx + 8, $by + $metaSize + 12, imagecolorallocatealpha($img, 255, 255, 255, 25));
    imagefilledrectangle($img, $bx, $by, $bx + $barPx, $by + 8, $ink);
    imageline($img, $bx, $by - 6, $bx, $by + 14, $ink);
    imageline($img, $bx + $barPx, $by - 6, $bx + $barPx, $by + 14, $ink);
    gpx_text($img, $bx, $by - 12, $nice >= 1000 ? ($nice / 1000) . ' km' : $nice . ' m', $metaSize, $ink, $font, true);

    // Flèche du nord.
    $nx = $W - $pad - (int) ($W * 0.05);
    $ny = $pad + (int) ($H * 0.06);
    imagefilledpolygon($img, [$nx, $ny - 34, $nx - 16, $ny + 18, $nx, $ny + 6, $nx + 16, $ny + 18], $ink);
    gpx_text($img, $nx, $ny + 44, 'N', $metaSize, $ink, $font, true, 'center');

    $credit = $bg !== null || $bgPath !== '' ? 'Fond importé / stylisé d\'après © IGN' : 'Fond de carte © IGN (Géoportail)';
    gpx_text($img, $W - $pad - 8, $H - $pad - 8, $credit, max(12, (int) round($H / 200)), $soft, $font, false, 'right');

    return $img;
}

/** Raster -> octets JPEG (qualité 90) pour l'incrustation PDF. */
function gpx_jpeg(GdImage $img, int $quality = 90): string
{
    ob_start();
    imagejpeg($img, null, $quality);
    return (string) ob_get_clean();
}

/** PDF une page (paysage) contenant le raster pleine page. $pt = [largeur, hauteur] en points. */
function gpx_pdf(string $jpeg, int $pxW, int $pxH, array $pt): string
{
    [$pageW, $pageH] = $pt;
    $img = @imagecreatefromstring($jpeg);
    $w = $img ? imagesx($img) : $pxW;
    $h = $img ? imagesy($img) : $pxH;

    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . rtrim(rtrim(number_format($pageW, 2, '.', ''), '0'), '.') . ' '
        . rtrim(rtrim(number_format($pageH, 2, '.', ''), '0'), '.') . "] "
        . "/Resources << /XObject << /Im1 4 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 5 0 R >>";
    $objects[5] = "<< /Length %LEN5% >>\nstream\nq " . rtrim(rtrim(number_format($pageW, 2, '.', ''), '0'), '.') . ' 0 0 '
        . rtrim(rtrim(number_format($pageH, 2, '.', ''), '0'), '.') . " 0 0 cm /Im1 Do Q\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $stream4 = $jpeg;
    $content5 = "q " . rtrim(rtrim(number_format($pageW, 2, '.', ''), '0'), '.') . ' 0 0 '
        . rtrim(rtrim(number_format($pageH, 2, '.', ''), '0'), '.') . " 0 0 cm /Im1 Do Q";
    foreach ([1, 2, 3] as $n) {
        $offsets[$n] = strlen($pdf);
        $pdf .= $n . " 0 obj\n" . $objects[$n] . "\nendobj\n";
    }
    $offsets[4] = strlen($pdf);
    $pdf .= "4 0 obj\n<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB "
        . "/BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($stream4) . " >>\nstream\n" . $stream4 . "\nendstream\nendobj\n";
    $offsets[5] = strlen($pdf);
    $pdf .= "5 0 obj\n<< /Length " . strlen($content5) . " >>\nstream\n" . $content5 . "\nendstream\nendobj\n";
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($n = 1; $n <= 5; $n++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

/** Encodage PackBits d'une ligne (une planète de canal). */
function gpx_packbits(string $row): string
{
    $n = strlen($row);
    $out = '';
    $i = 0;
    while ($i < $n) {
        $r = 1;
        while ($i + $r < $n && $r < 128 && $row[$i + $r] === $row[$i]) $r++;
        if ($r >= 3) {
            $out .= chr(257 - $r) . $row[$i];
            $i += $r;
            continue;
        }
        $lit = '';
        while ($i < $n && strlen($lit) < 128) {
            $r = 1;
            while ($i + $r < $n && $r < 128 && $row[$i + $r] === $row[$i]) $r++;
            if ($r >= 3) break;
            $lit .= $row[$i];
            $i++;
        }
        if ($lit !== '') $out .= chr(strlen($lit) - 1) . $lit;
    }
    return $out;
}

/** PSD aplati (8BPS v1, RVB 8 bits, compression RLE) avec la résolution d'impression dans les ressources. */
function gpx_psd(GdImage $img, int $dpi): string
{
    $w = imagesx($img);
    $h = imagesy($img);
    $psd = '8BPS' . pack('n', 1) . str_repeat("\0", 6) . pack('n', 3) . pack('N', $h) . pack('N', $w) . pack('n', 8) . pack('n', 3);
    $psd .= pack('N', 0);   // données de mode couleur

    // Ressource « résolution » (0x03ED) : dpi en fixe 16.16, unités en pouces.
    $res = pack('N', $dpi << 16) . pack('n', 1) . pack('n', 1) . pack('N', $dpi << 16) . pack('n', 1) . pack('n', 1);
    $psd .= '8BIM' . pack('n', 0x03ED) . pack('C', 0) . "\0" . pack('N', strlen($res)) . $res;

    $psd .= pack('N', 0);   // pas de calques : image aplatie
    $psd .= pack('n', 1);   // compression RLE

    $counts = '';
    $data = '';
    for ($c = 0; $c < 3; $c++) {
        $shift = (2 - $c) * 8;   // R, G, B
        for ($y = 0; $y < $h; $y++) {
            $row = '';
            for ($x = 0; $x < $w; $x++) {
                $row .= chr((imagecolorat($img, $x, $y) >> $shift) & 255);
            }
            $enc = gpx_packbits($row);
            $counts .= pack('n', strlen($enc));
            $data .= $enc;
        }
    }
    return $psd . $counts . $data;
}
