<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/apps.php';

final class AppNamesTest extends TestCase
{
    public function testDefaultNamesFollowTheOwner(): void
    {
        self::assertSame('La Friperie', app_default_name('shop', 'La Friperie'));
        self::assertSame('Article La Friperie', app_default_name('article', 'La Friperie'));
        self::assertSame('Vente La Friperie', app_default_name('vente', 'La Friperie'));
        self::assertSame('Admin Rue des Arts', app_default_name('gallery_admin', 'Rue des Arts'));
        self::assertSame('Rue des Arts', app_default_name('gallery_visitor', 'Rue des Arts'));
    }

    public function testEmptyCustomKeepsDefaultAndCustomWins(): void
    {
        self::assertSame('Article La Friperie', app_resolve_name('article', 'La Friperie', '   '));
        self::assertSame('Photos Nathalie', app_resolve_name('article', 'La Friperie', ' Photos Nathalie '));
    }

    public function testNameCleanRemovesTagsControlsAndCaps(): void
    {
        self::assertSame('Vente La Friperie', app_name_clean("  <b>Vente</b>\n  La   Friperie  "));
        self::assertSame(40, mb_strlen(app_name_clean(str_repeat('é', 60))));
    }

    public function testShortNameCutsAtWordBoundary(): void
    {
        self::assertSame('Vente Friperie', app_short_name('Vente Friperie'));
        self::assertSame('Article La', app_short_name('Article La Friperie de Nathalie'));
        self::assertSame('MotTrèslongSans', app_short_name('MotTrèslongSansEspace'));
    }

    public function testNativeIdsAreStableFamilies(): void
    {
        self::assertSame('fr.brocs.boutique.lafriperie', app_native_id('shop', 'La Friperie'));
        self::assertSame('fr.brocs.article.cafedesarts', app_native_id('article', 'Café des Arts'));
        self::assertSame('fr.brocs.vente.lafriperie', app_native_id('vente', 'La Friperie'));
        self::assertSame('fr.brocs.galerie.admin.ruedesarts', app_native_id('gallery_admin', 'Rue des Arts'));
        self::assertSame('fr.brocs.galerie.visiteur.ruedesarts', app_native_id('gallery_visitor', 'Rue des Arts'));
        self::assertSame('fr.brocs.boutique.s123', app_native_id('shop', '123'));
    }

    public function testManifestIdsDoNotDependOnDisplayName(): void
    {
        self::assertSame('/?app=boutique', app_manifest_id('shop'));
        self::assertSame('/brocante/?app=boutique', app_manifest_id('shop', '/brocante'));
        self::assertSame('/brocante/studio.php?app=article', app_manifest_id('article', '/brocante'));
        self::assertSame('/vente.php?app=vente', app_manifest_id('vente'));
        self::assertSame('/galerie/admin-manifest.php?app=admin&g=rue-des-arts', app_manifest_id('gallery_admin', '', 'rue-des-arts'));
        self::assertSame('/galerie/visiteur.php?app=visiteur&g=rue-des-arts', app_manifest_id('gallery_visitor', '', 'rue-des-arts'));
    }

    public function testGalleryAppNameReadsItsStoredKey(): void
    {
        $gallery = ['name' => 'Rue des Arts', 'apps' => gallery_apps_normalize(['admin' => 'Fête du village', 'visitor' => 'Les puces'])];
        self::assertSame('Fête du village', gallery_app_name($gallery, 'gallery_admin'));
        self::assertSame('Les puces', gallery_app_name($gallery, 'gallery_visitor'));
        self::assertSame('Admin Rue des Arts', gallery_app_name(['name' => 'Rue des Arts', 'apps' => gallery_apps_normalize([])], 'gallery_admin'));
        self::assertSame('Rue des Arts', gallery_app_name(['name' => 'Rue des Arts'], 'gallery_visitor'));
    }

    public function testGalleryAppsNormalization(): void
    {
        self::assertSame(['admin' => '', 'visitor' => '', 'caisse' => ''], gallery_apps_normalize([]));
        self::assertSame(
            ['admin' => 'Fête du village', 'visitor' => 'Les puces', 'caisse' => ''],
            gallery_apps_normalize(['admin' => ' <b>Fête du village</b> ', 'visitor' => 'Les puces'])
        );
    }

    public function testBuvetteCashRegisterAppName(): void
    {
        self::assertSame('Caisse Rue des Arts', gallery_app_name(['name' => 'Rue des Arts'], 'gallery_caisse'));
        self::assertSame('Bar du village', gallery_app_name(['name' => 'Rue des Arts', 'apps' => gallery_apps_normalize(['caisse' => 'Bar du village'])], 'gallery_caisse'));
        self::assertSame('fr.brocs.galerie.caisse.ruedesarts', app_native_id('gallery_caisse', 'Rue des Arts'));
    }

    public function testIconModesKeepTheirPreviousDefaults(): void
    {
        // Sans réglage, chaque application garde l'icône qu'elle avait : logo pour la boutique et la galerie, pictogramme pour le reste.
        self::assertSame('logo', app_icon_default_mode('shop'));
        self::assertSame('logo', app_icon_default_mode('gallery_admin'));
        self::assertSame('logo', app_icon_default_mode('gallery_visitor'));
        self::assertSame('basic', app_icon_default_mode('article'));
        self::assertSame('basic', app_icon_default_mode('vente'));
        self::assertSame('basic', app_icon_default_mode('gallery_caisse'));
    }

    public function testIconModeIsCleaned(): void
    {
        self::assertSame('basic', app_icon_mode_clean(' BASIC ', 'shop'));
        self::assertSame('logo', app_icon_mode_clean('logo', 'vente'));
        self::assertSame('logo', app_icon_mode_clean('n\'importe quoi', 'shop'));
        self::assertSame('basic', app_icon_mode_clean('', 'vente'));
        self::assertSame('basic', app_icon_mode_clean(null, 'article'));
        self::assertSame(
            ['admin' => 'basic', 'visitor' => 'logo', 'caisse' => 'logo'],
            gallery_app_icons_normalize(['admin' => 'basic', 'visitor' => 'zzz', 'caisse' => 'logo'])
        );
        self::assertSame('logo', gallery_app_icon_mode(['app_icons' => []], 'gallery_admin'));
        self::assertSame('basic', gallery_app_icon_mode(['app_icons' => ['admin' => 'basic']], 'gallery_admin'));
    }

    public function testEveryAppHasItsOwnGlyphAndRendersAtEverySize(): void
    {
        $glyphs = [];
        foreach (['shop', 'article', 'vente', 'gallery_admin', 'gallery_visitor', 'gallery_caisse'] as $app) {
            $glyphs[] = app_icon_glyph($app);
            foreach ([16, 192, 512] as $size) {
                $img = app_icon_render($app, $size, 'basic', ['accent' => '#b5502e']);
                self::assertSame($size, imagesx($img));
            }
        }
        self::assertCount(6, array_unique($glyphs), 'un pictogramme par application');
    }

    public function testLogoModeUsesTheLogoElseTheInitial(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $logo = imagecreatetruecolor(40, 20);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 0, 0));
        imagepng($logo, $png);
        $style = ['accent' => '#0000ff', 'bg' => '#00ff00', 'logo' => $png, 'initial' => 'R', 'fallback' => 'square'];
        $withLogo = app_icon_render('gallery_admin', 100, 'logo', $style);
        self::assertSame(0xFF0000, imagecolorat($withLogo, 50, 50) & 0xFFFFFF, 'le logo est au centre');
        self::assertSame(0x00FF00, imagecolorat($withLogo, 2, 2) & 0xFFFFFF, 'fond de la couleur demandée');
        $noLogo = app_icon_render('gallery_admin', 100, 'logo', ['logo' => null] + $style);
        self::assertSame(0x0000FF, imagecolorat($noLogo, 2, 2) & 0xFFFFFF, 'sans logo : initiale sur la couleur d\'accent');
        $basic = app_icon_render('gallery_admin', 100, 'basic', $style);
        self::assertSame(0x0000FF, imagecolorat($basic, 2, 2) & 0xFFFFFF, 'icône de base : couleur d\'accent');
        unlink($png);
    }

    public function testIconVersionChangesWithTheChoice(): void
    {
        self::assertNotSame(app_icon_version('basic', null, '#112233'), app_icon_version('logo', null, '#112233'));
        self::assertNotSame(app_icon_version('basic', null, '#112233'), app_icon_version('basic', null, '#445566'));
        self::assertSame(app_icon_version('basic', null, '#112233'), app_icon_version('basic', null, '#112233'));
    }

    public function testGalleryIconUrl(): void
    {
        $g = ['slug' => 'rue-des-arts', 'accent' => '#b5502e', 'app_icons' => ['caisse' => 'basic']];
        $url = gallery_app_icon_url($g, 'gallery_caisse', 192);
        self::assertStringStartsWith('/galerie/icon.php?g=rue-des-arts&app=gallery_caisse&v=', $url);
        self::assertStringEndsWith('&s=192', $url);
        self::assertStringContainsString('&mode=logo', gallery_app_icon_url($g, 'gallery_caisse', 120, 'logo'), 'aperçu d\'un autre choix');
        self::assertStringNotContainsString('&mode=', $url);
    }
}
