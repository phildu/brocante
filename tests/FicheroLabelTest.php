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
}
