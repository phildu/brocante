<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/fichero.php';

final class FicheroLabelTest extends TestCase
{
    public function testRasterHeaderIsGsV0ForD11s(): void
    {
        $h = fichero_raster_header(240);
        self::assertSame(8, strlen($h));
        self::assertSame("\x1d\x76\x30\x00\x0c\x00\xf0\x00", $h);
    }

    public function testPackBitmapMsbFirstBlackIsOne(): void
    {
        $dark = [array_fill(0, 16, false)];
        $dark[0][0] = true;
        $dark[0][7] = true;
        $packed = fichero_pack_bitmap($dark, 16, 1);
        self::assertSame(2, strlen($packed));
        self::assertSame(0b10000001, ord($packed[0]));
        self::assertSame(0, ord($packed[1]));
    }

    public function testRasterFromUrlHasExpectedSizeAndInk(): void
    {
        $raster = fichero_raster_from_text('https://www.bricbrok.com/payer.php?ref=ABC12');
        self::assertSame(FICHERO_BYTES_PER_ROW * FICHERO_LABEL_HEIGHT, strlen($raster));
        $ink = 0;
        for ($i = 0, $n = strlen($raster); $i < $n; $i++) {
            $ink += substr_count(decbin(ord($raster[$i])), '1');
        }
        self::assertGreaterThan(80, $ink);
        self::assertLessThan(strlen($raster) * 8 * 0.6, $ink);
    }

    public function testQrBitmapFitsWidth(): void
    {
        $qr = QrCode::encodeText('https://exemple.fr/p/x');
        $dark = fichero_qr_bitmap($qr);
        self::assertCount(FICHERO_LABEL_HEIGHT, $dark);
        self::assertCount(FICHERO_PRINT_WIDTH, $dark[0]);
    }

    public function testPreparedLabelHasTextBelowQr(): void
    {
        if (!function_exists('imagecreatetruecolor') || !is_readable(fichero_font_path())) {
            self::markTestSkipped('GD ou police Poppins indisponible.');
        }
        $raster = fichero_raster_from_label(
            'https://petit-chalet.brocante.test?p=ABC12',
            'Chaise cannage',
            '25,00 €',
            'ABC12'
        );
        self::assertSame(FICHERO_BYTES_PER_ROW * FICHERO_LABEL_HEIGHT, strlen($raster));

        $minX = FICHERO_PRINT_WIDTH;
        $maxX = -1;
        for ($y = 0; $y < 100; $y++) {
            for ($x = 0; $x < FICHERO_PRINT_WIDTH; $x++) {
                $byte = ord($raster[$y * FICHERO_BYTES_PER_ROW + intdiv($x, 8)]);
                if (($byte & (1 << (7 - ($x % 8)))) !== 0) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                }
            }
        }
        self::assertGreaterThan(80, $maxX - $minX, 'Le QR doit utiliser presque toute la largeur imprimable.');

        $underQr = substr($raster, FICHERO_BYTES_PER_ROW * 80, FICHERO_BYTES_PER_ROW * 50);
        $ink = 0;
        for ($i = 0, $n = strlen($underQr); $i < $n; $i++) {
            $ink += substr_count(decbin(ord($underQr[$i])), '1');
        }
        self::assertGreaterThan(30, $ink, 'Le nom et le prix doivent s’imprimer sous le QR.');
    }

    public function testDataMatrixIsGeneratedForAnUrl(): void
    {
        $matrix = fichero_datamatrix('https://www.bricbrok.com?p=ABC12');
        self::assertCount(22, $matrix);
        self::assertCount(22, $matrix[0]);
        self::assertTrue($matrix[0][0]);
        self::assertFalse($matrix[0][1]);
        $raster = fichero_raster_from_label('https://www.bricbrok.com?p=ABC12', 'Chaise', '25 €', 'ABC12', 240, 'datamatrix');
        self::assertSame(FICHERO_BYTES_PER_ROW * FICHERO_LABEL_HEIGHT, strlen($raster));
        self::assertNotSame(fichero_raster_from_label('https://www.bricbrok.com?p=ABC12', 'Chaise', '25 €', 'ABC12'), $raster);
    }

    public function testDataMatrixRejectsOversizedUrlRatherThanPrintingTinyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lien trop long');
        fichero_raster_from_label('https://example.com/' . str_repeat('a', 200), '', '', '', 240, 'datamatrix');
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        fichero_raster_from_label('https://example.com/', '', '', '', 240, 'micro');
    }
}
