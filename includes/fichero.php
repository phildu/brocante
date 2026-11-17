<?php
/**
 * Étiquettes QR pour l'imprimante Fichero D11s (AiYin) : 96 pixels de large,
 * ~30 mm de long à 203 dpi. Le navigateur envoie ensuite le bitmap en Bluetooth.
 *
 * Le protocole (commandes AiYin 10 FF FE …) est dans assets/fichero-print.js.
 */

require_once __DIR__ . '/qrcode.php';

const FICHERO_PRINT_WIDTH = 96;
const FICHERO_LABEL_HEIGHT = 240;
const FICHERO_BYTES_PER_ROW = 12;

/** En-tête ESC/POS GS v 0 (largeur en octets, hauteur en lignes, little-endian). */
function fichero_raster_header(int $height, int $widthPx = FICHERO_PRINT_WIDTH): string
{
    if ($height < 1 || $height > 2000) {
        throw new InvalidArgumentException('Hauteur d’étiquette hors limites.');
    }
    if ($widthPx < 8 || $widthPx % 8 !== 0) {
        throw new InvalidArgumentException('Largeur d’étiquette invalide.');
    }
    return "\x1d\x76\x30\x00" . pack('v', intdiv($widthPx, 8)) . pack('v', $height);
}

/**
 * Bitmap 1 bit, MSB à gauche, 1 = noir. $dark[$y][$x] vrai = pixel imprimé.
 *
 * @param array<int,array<int,bool>> $dark
 */
function fichero_pack_bitmap(array $dark, int $width, int $height): string
{
    if ($width % 8 !== 0) {
        throw new InvalidArgumentException('Largeur non multiple de 8.');
    }
    $out = '';
    for ($y = 0; $y < $height; $y++) {
        $row = $dark[$y] ?? [];
        for ($x = 0; $x < $width; $x += 8) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                if (!empty($row[$x + $bit])) {
                    $byte |= 1 << (7 - $bit);
                }
            }
            $out .= chr($byte);
        }
    }
    return $out;
}

/**
 * QR centré sur une étiquette D11s (fond blanc).
 *
 * @return array<int,array<int,bool>>
 */
function fichero_qr_bitmap(QrCode $qr, int $canvasW = FICHERO_PRINT_WIDTH, int $canvasH = FICHERO_LABEL_HEIGHT, int $quiet = 2): array
{
    $mod = $qr->size + $quiet * 2;
    $scale = max(1, intdiv($canvasW, $mod));
    $drawn = $mod * $scale;
    $ox = intdiv($canvasW - $drawn, 2);
    $oy = intdiv($canvasH - $drawn, 2);
    $dark = array_fill(0, $canvasH, array_fill(0, $canvasW, false));
    for ($y = 0; $y < $qr->size; $y++) {
        for ($x = 0; $x < $qr->size; $x++) {
            if (!$qr->modules[$y][$x]) {
                continue;
            }
            $px = $ox + ($x + $quiet) * $scale;
            $py = $oy + ($y + $quiet) * $scale;
            for ($dy = 0; $dy < $scale; $dy++) {
                for ($dx = 0; $dx < $scale; $dx++) {
                    $xx = $px + $dx;
                    $yy = $py + $dy;
                    if ($xx >= 0 && $xx < $canvasW && $yy >= 0 && $yy < $canvasH) {
                        $dark[$yy][$xx] = true;
                    }
                }
            }
        }
    }
    return $dark;
}

/** Bitmap packed d'un texte (URL de paiement ou de fiche). */
function fichero_raster_from_text(string $text, int $height = FICHERO_LABEL_HEIGHT): string
{
    $qr = QrCode::encodeText($text);
    $dark = fichero_qr_bitmap($qr, FICHERO_PRINT_WIDTH, $height);
    $raster = fichero_pack_bitmap($dark, FICHERO_PRINT_WIDTH, $height);
    $expected = FICHERO_BYTES_PER_ROW * $height;
    if (strlen($raster) !== $expected) {
        throw new RuntimeException('Bitmap Fichero de taille inattendue.');
    }
    return $raster;
}

function fichero_font_path(): string
{
    return dirname(__DIR__) . '/assets/fonts/Poppins-SemiBold.ttf';
}

/** Tronque le texte pour qu'il tienne en largeur (points GD). */
function fichero_fit_text(string $font, int $maxSize, int $minSize, int $maxWidth, string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [$minSize, ''];
    }
    for ($size = $maxSize; $size >= $minSize; $size--) {
        $bb = imagettfbbox($size, 0, $font, $text);
        if ($bb && abs($bb[2] - $bb[0]) <= $maxWidth) {
            return [$size, $text];
        }
    }
    $size = $minSize;
    while (mb_strlen($text) > 1) {
        $text = mb_substr($text, 0, mb_strlen($text) - 1);
        $shown = $text . '…';
        $bb = imagettfbbox($size, 0, $font, $shown);
        if ($bb && abs($bb[2] - $bb[0]) <= $maxWidth) {
            return [$size, $shown];
        }
    }
    return [$size, $text];
}

/** @return array<int,array<int,bool>> */
function fichero_datamatrix(string $url): array
{
    $vendor = dirname(__DIR__) . '/vendor/tecnickcom';
    if (!is_file($vendor . '/tc-lib-barcode/src/Barcode.php') || !is_file($vendor . '/tc-lib-color/src/Model.php')) {
        throw new RuntimeException('Bibliothèque Data Matrix indisponible sur ce serveur.');
    }
    static $registered = false;
    if (!$registered) {
        // Le déploiement exclut l'autoload Composer ; seules ces deux bibliothèques sont chargées ici.
        spl_autoload_register(static function (string $class) use ($vendor): void {
            foreach (['Com\\Tecnick\\Barcode\\' => 'tc-lib-barcode', 'Com\\Tecnick\\Color\\' => 'tc-lib-color'] as $prefix => $package) {
                if (str_starts_with($class, $prefix)) {
                    $file = $vendor . '/' . $package . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) require_once $file;
                    return;
                }
            }
        });
        $registered = true;
    }
    $barcode = (new \Com\Tecnick\Barcode\Barcode())->getBarcodeObj('DATAMATRIX', $url);
    return array_map(static fn (array $row): array => array_map(static fn ($pixel): bool => $pixel === '1', $row), $barcode->getGridArray());
}

/**
 * Étiquette D11s : QR en haut, prix / nom / réf en dessous (comme l'aperçu).
 */
function fichero_raster_from_label(string $url, string $name, string $price, string $ref, int $height = FICHERO_LABEL_HEIGHT, string $code = 'qr'): string
{
    if (!in_array($code, ['qr', 'datamatrix'], true)) {
        throw new InvalidArgumentException('Type de code inconnu.');
    }
    $width = FICHERO_PRINT_WIDTH;
    $font = fichero_font_path();
    if (!function_exists('imagecreatetruecolor') || !is_readable($font)) {
        if ($code !== 'qr') throw new RuntimeException('GD ou la police nécessaire à l’étiquette est indisponible.');
        return fichero_raster_from_text($url, $height);
    }

    $im = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $white);

    $qr = $code === 'qr' ? QrCode::encodeText($url) : null;
    $matrix = $qr ? $qr->modules : fichero_datamatrix($url);
    $rows = count($matrix);
    $cols = count($matrix[0]);
    $quiet = 1;
    $textBlock = 72;
    $scale = min(intdiv($width - 2, $cols + 2 * $quiet), intdiv($height - $textBlock - 4, $rows + 2 * $quiet));
    if ($scale < 2 && $code === 'datamatrix') throw new InvalidArgumentException('Lien trop long pour imprimer ce code lisiblement sur une Fichero.');
    $scale = max(1, $scale);
    $drawnW = ($cols + 2 * $quiet) * $scale;
    $drawnH = ($rows + 2 * $quiet) * $scale;
    $ox = intdiv($width - $drawnW, 2);
    $oy = 1;
    for ($y = 0; $y < $rows; $y++) {
        for ($x = 0; $x < $cols; $x++) {
            if (!$matrix[$y][$x]) {
                continue;
            }
            $px = $ox + ($x + $quiet) * $scale;
            $py = $oy + ($y + $quiet) * $scale;
            imagefilledrectangle($im, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
        }
    }

    $maxW = $width - 4;
    $ty = $oy + $drawnH + 12;
    $lines = [
        [$price, 13, 8],
        [$name, 9, 7],
        [$ref !== '' ? 'Réf. ' . $ref : '', 8, 6],
    ];
    foreach ($lines as [$raw, $maxSize, $minSize]) {
        [$size, $shown] = fichero_fit_text($font, $maxSize, $minSize, $maxW, $raw);
        if ($shown === '') {
            continue;
        }
        $bb = imagettfbbox($size, 0, $font, $shown);
        $tw = abs($bb[2] - $bb[0]);
        $th = abs($bb[1] - $bb[7]);
        $tx = max(1, intdiv($width - $tw, 2) - (int) $bb[0]);
        $baseline = $ty - (int) $bb[7];
        if ($baseline + $th > $height - 1) {
            break;
        }
        imagettftext($im, $size, 0, $tx, $baseline, $black, $font, $shown);
        $ty += $th + 4;
    }

    $dark = [];
    for ($y = 0; $y < $height; $y++) {
        $row = [];
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($im, $x, $y);
            $r = ($rgb >> 16) & 0xff;
            $g = ($rgb >> 8) & 0xff;
            $b = $rgb & 0xff;
            $row[$x] = ($r + $g + $b) < 650;
        }
        $dark[$y] = $row;
    }

    $raster = fichero_pack_bitmap($dark, $width, $height);
    $expected = FICHERO_BYTES_PER_ROW * $height;
    if (strlen($raster) !== $expected) {
        throw new RuntimeException('Bitmap Fichero de taille inattendue.');
    }
    return $raster;
}

/**
 * Job d'impression pour une pièce : URL de paiement si possible, sinon la fiche.
 *
 * @param array<string,mixed> $product
 * @return array{ref:string,name:string,price:string,url:string,width:int,height:int,raster:string}
 */
function fichero_job_for_product(array $product, string $code = 'qr'): array
{
    $ref = (string) $product['ref'];
    $payable = function_exists('shop_qr_payable') && shop_qr_payable($product);
    $base = function_exists('tenant_base_path') ? tenant_base_path() : '';
    $url = share_origin() . $base . '?' . ($payable ? 'b' : 'p') . '=' . rawurlencode($ref);
    $height = FICHERO_LABEL_HEIGHT;
    $name = (string) ($product['name'] ?? '');
    $price = function_exists('effective_price') ? (string) effective_price($product) : (string) ($product['price'] ?? '');
    return [
        'ref' => $ref,
        'name' => $name,
        'price' => $price,
        'url' => $url,
        'width' => FICHERO_PRINT_WIDTH,
        'height' => $height,
        'raster' => base64_encode(fichero_raster_from_label($url, $name, $price, $ref, $height, $code)),
    ];
}
