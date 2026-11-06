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
        self::assertSame(['admin' => '', 'visitor' => ''], gallery_apps_normalize([]));
        self::assertSame(
            ['admin' => 'Fête du village', 'visitor' => 'Les puces'],
            gallery_apps_normalize(['admin' => ' <b>Fête du village</b> ', 'visitor' => 'Les puces'])
        );
    }
}
