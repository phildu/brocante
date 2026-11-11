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

/**
 * Job d'impression pour une pièce : URL de paiement si possible, sinon la fiche.
 *
 * @param array<string,mixed> $product
 * @return array{ref:string,name:string,price:string,url:string,width:int,height:int,raster:string}
 */
function fichero_job_for_product(array $product): array
{
    $url = (function_exists('shop_qr_payable') && shop_qr_payable($product))
        ? shop_qr_pay_url($product)
        : shop_abs_url('/produit.php?ref=' . rawurlencode((string) $product['ref']));
    $height = FICHERO_LABEL_HEIGHT;
    return [
        'ref' => (string) $product['ref'],
        'name' => (string) ($product['name'] ?? ''),
        'price' => function_exists('effective_price') ? (string) effective_price($product) : (string) ($product['price'] ?? ''),
        'url' => $url,
        'width' => FICHERO_PRINT_WIDTH,
        'height' => $height,
        'raster' => base64_encode(fichero_raster_from_text($url, $height)),
    ];
}
