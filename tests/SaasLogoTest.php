<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/saas.php';

final class SaasLogoTest extends TestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) saas_banner_delete($path);
    }

    private function tempPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $file = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($im, $file);
        return $file;
    }

    public function testStoreResizesAndKeepsTransparency(): void
    {
        $tmp = $this->tempPng(1200, 600);
        $path = saas_logo_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
        $this->created[] = $path;
        self::assertMatchesRegularExpression('#^uploads/saas/logo-[a-z0-9]+\.png$#', $path);
        $abs = dirname(__DIR__) . '/' . $path;
        [$w, $h] = getimagesize($abs);
        self::assertSame([600, 300], [$w, $h]);
        $im = imagecreatefrompng($abs);
        self::assertSame(127, (imagecolorat($im, 0, 0) >> 24) & 0x7F);
        @unlink($tmp);
    }

    public function testStoreRejectsNonImage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($tmp, 'pas une image');
        $this->expectException(InvalidArgumentException::class);
        try {
            saas_logo_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
        } finally {
            @unlink($tmp);
        }
    }

    public function testBrandMarkFallsBackToInitial(): void
    {
        self::assertStringContainsString('>B<', saas_brand_mark(['name' => 'Brocs', 'logo' => '']));
        $html = saas_brand_mark(['name' => 'Brocs', 'logo' => 'uploads/saas/logo-ab12cd34.png']);
        self::assertStringContainsString('<img class="brand-logo" src="/uploads/saas/logo-ab12cd34.png"', $html);
    }
}
