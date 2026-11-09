<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/saas.php';

// Logo (trois déclinaisons) et favicon du portail : choix de l'image, repli, balise <link rel="icon">, chemins autorisés.
final class SaasBrandTest extends TestCase
{
    public function testDefaultsToBricbrokAssetsWhenNothingUploaded(): void
    {
        self::assertSame('assets/bricbrok/bricbrok-etal-icone.svg', saas_brand_image([], 'square'));
        self::assertSame('assets/bricbrok/bricbrok-etal-horizontal.svg', saas_brand_image([], 'horizontal'));
        self::assertSame('assets/bricbrok/bricbrok-etal-favicon.svg', saas_brand_image([], 'favicon'));
        self::assertSame('', saas_brand_image([], 'square', false), 'sans image d’origine : vide');
    }

    public function testVariantsFallBackLikeAShop(): void
    {
        $cfg = ['logos' => ['square' => 'uploads/saas/logo-portail-square-ab12cd34.png'], 'logo' => 'uploads/saas/logo-ab12cd34.png', 'favicon' => ''];
        self::assertSame('uploads/saas/logo-portail-square-ab12cd34.png', saas_brand_image($cfg, 'vertical'), 'sans vertical : le carré');
        self::assertSame('uploads/saas/logo-ab12cd34.png', saas_brand_image($cfg, 'horizontal'), 'sans horizontal : l’ancien logo unique');
        self::assertSame('uploads/saas/logo-portail-square-ab12cd34.png', saas_brand_image($cfg, 'favicon'), 'sans favicon : le carré');
        $cfg['favicon'] = 'uploads/saas/favicon-portail-ab12cd34.ico';
        self::assertSame('uploads/saas/favicon-portail-ab12cd34.ico', saas_brand_image($cfg, 'favicon'));
    }

    public function testFaviconTagCarriesTheRightType(): void
    {
        self::assertStringContainsString('type="image/svg+xml" href="/assets/bricbrok/bricbrok-etal-favicon.svg"', saas_favicon_html([]));
        self::assertStringContainsString('type="image/x-icon" href="/uploads/saas/favicon-portail-ab12cd34.ico"', saas_favicon_html(['favicon' => 'uploads/saas/favicon-portail-ab12cd34.ico']));
        self::assertStringContainsString('type="image/png"', saas_favicon_html(['favicon' => 'uploads/saas/favicon-portail-ab12cd34.png']));
    }

    public function testBrandMarkPrefersSquareThenLegacyLogo(): void
    {
        $html = saas_brand_mark(['name' => 'Brocs', 'logos' => ['square' => 'uploads/saas/logo-portail-square-ab12cd34.svg'], 'logo' => 'uploads/saas/logo-ab12cd34.png']);
        self::assertStringContainsString('src="/uploads/saas/logo-portail-square-ab12cd34.svg"', $html);
        self::assertStringContainsString('>B<', saas_brand_mark(['name' => 'Brocs']), 'sans image : l’initiale, pas l’étal d’origine');
    }

    public function testOnlyPortalBrandFilesAreAccepted(): void
    {
        foreach (['uploads/saas/logo-portail-horizontal-ab12cd34.png', 'uploads/saas/logo-portail-vertical-ab12cd34.svg',
                  'uploads/saas/logo-portail-square-ab12cd34.webp', 'uploads/saas/favicon-portail-ab12cd34.ico'] as $ok) {
            self::assertSame(1, preg_match(SAAS_BRAND_PATH, $ok), $ok);
        }
        foreach (['uploads/saas/logo-portail-square-ab12cd34.php', 'uploads/saas/../../config.php', 'uploads/logo-petit-chalet-square-ab12cd34.png',
                  'uploads/saas/logo-portail-banner-ab12cd34.png', '/etc/passwd', 'uploads/saas/favicon-portail-ZZ.ico'] as $bad) {
            self::assertSame(0, preg_match(SAAS_BRAND_PATH, $bad), $bad);
        }
    }

    public function testDeleteIgnoresOtherFiles(): void
    {
        $other = dirname(__DIR__) . '/uploads/saas/brand-test-keep.txt';
        @mkdir(dirname($other), 0755, true);
        file_put_contents($other, 'x');
        saas_brand_delete('uploads/saas/brand-test-keep.txt');
        self::assertFileExists($other);
        @unlink($other);
    }

    public function testFaviconRejectsOtherFormats(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fav');
        $im = imagecreatetruecolor(8, 8);
        imagegif($im, $tmp);
        try {
            $this->expectException(InvalidArgumentException::class);
            saas_brand_store('favicon', ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'name' => 'f.gif']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUnknownVariantIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        saas_brand_store('banner', ['error' => UPLOAD_ERR_NO_FILE]);
    }
}
