<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/gallery-promos.php';

/**
 * Promos d'une galerie : normalisation des articles retenus, fusion avec le catalogue, jeton de l'application de prise de vue.
 */
final class GalleryPromosTest extends TestCase
{
    public function testPhotoAcceptsGalleryFileOrHttpUrl(): void
    {
        self::assertSame('/uploads/galeries/promo-1.jpg', gallery_promo_photo('uploads/galeries/promo-1.jpg'));
        self::assertSame('https://cdn.test/p.jpg', gallery_promo_photo('https://cdn.test/p.jpg'));
        self::assertSame('', gallery_promo_photo('uploads/galeries/../secret.jpg'));
        self::assertSame('', gallery_promo_photo('javascript:alert(1)'));
        self::assertSame('', gallery_promo_photo('/uploads/galeries/p.jpg'));
    }

    public function testPromoNormalization(): void
    {
        self::assertNull(gallery_promo_normalize('pas un tableau'));
        self::assertNull(gallery_promo_normalize(['ref' => 'sans-nom']));

        $p = gallery_promo_normalize([
            'name' => '  Fauteuil  ', 'shop' => 'Atelier Test', 'ref' => '',
            'url' => 'javascript:alert(1)', 'photo' => 'uploads/galeries/f.jpg',
            'source' => 'inconnue', 'price' => '120 €', 'promo_price' => '90 €',
        ]);
        self::assertSame('Fauteuil', $p['name']);
        self::assertSame('shoot', $p['shop']);
        self::assertMatchesRegularExpression('/^shoot-[a-f0-9]{12}$/', $p['ref']);
        self::assertSame('', $p['url']);
        self::assertSame('/uploads/galeries/f.jpg', $p['photo']);
        self::assertSame('manual', $p['source']);
        self::assertSame('Voir l’offre', $p['cta']);
    }

    public function testPromoListDeduplicatesAndCaps(): void
    {
        $list = [];
        for ($i = 0; $i < 10; $i++) {
            $list[] = ['name' => 'Article ' . $i, 'shop' => 'boutique', 'ref' => 'r' . ($i % 8)];
        }
        $out = gallery_promos_normalize($list);
        self::assertCount(GALLERY_PROMOS_MAX, $out);
        $keys = array_map(static fn (array $p): string => $p['shop'] . '|' . $p['ref'], $out);
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testShootingTokenLifecycle(): void
    {
        $empty = gallery_shooting_normalize([]);
        self::assertSame('', $empty['token']);
        self::assertFalse($empty['enabled']);
        self::assertSame('', gallery_shooting_normalize(['token' => 'trop-court'])['token']);

        $gallery = [];
        $token = gallery_shooting_ensure($gallery);
        self::assertMatchesRegularExpression(GALLERY_SHOOTING_TOKEN_PATTERN, $token);
        self::assertTrue($gallery['shooting']['enabled']);
        self::assertTrue(gallery_shooting_authorized($gallery, $token));
        self::assertTrue(gallery_shooting_authorized($gallery, strtoupper($token)));
        self::assertFalse(gallery_shooting_authorized($gallery, str_repeat('b', 32)));
        self::assertFalse(gallery_shooting_authorized($gallery, ''));
        self::assertSame($token, gallery_shooting_ensure($gallery));

        $rotated = gallery_shooting_ensure($gallery, true);
        self::assertNotSame($token, $rotated);
        self::assertFalse(gallery_shooting_authorized($gallery, $token));

        $disabled = ['shooting' => ['token' => $token, 'enabled' => false]];
        self::assertFalse(gallery_shooting_authorized($disabled, $token));
    }

    public function testMergeEnrichesFromCatalogue(): void
    {
        $products = [[
            'shop' => 'boutique', 'ref' => 'abc', 'name' => 'Fauteuil du catalogue',
            'price' => '120 €', 'promo_price' => '90 €', 'url' => 'https://boutique.test/p/abc',
            'photo' => 'https://cdn.test/a.jpg', 'promo_visual' => '', 'promo_cta' => 'Voir',
        ]];
        $promos = [['name' => 'Fauteuil shooting', 'shop' => 'boutique', 'ref' => 'abc', 'photo' => '']];
        $items = gallery_promos_merge($products, $promos);
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame('Fauteuil du catalogue', $item['name']);
        self::assertSame('90 €', $item['promo_price']);
        self::assertSame('https://cdn.test/a.jpg', $item['photo']);
        self::assertSame('https://boutique.test/p/abc', $item['url']);
        self::assertTrue($item['tracked']);
        self::assertTrue($item['fallback']);
    }

    public function testMergeKeepsVisualPriorityAndLegacyPromos(): void
    {
        $products = [
            ['shop' => 'boutique', 'ref' => 'abc', 'name' => 'A', 'promo_visual' => '/uploads/galeries/a.jpg'],
            ['shop' => 'boutique', 'ref' => 'legacy', 'name' => 'L', 'promo_visual' => '/uploads/galeries/l.jpg', 'promo_cta' => 'Profiter'],
        ];
        $items = gallery_promos_merge($products, [['name' => 'A retenu', 'shop' => 'boutique', 'ref' => 'abc']]);
        self::assertCount(2, $items);
        self::assertSame('/uploads/galeries/a.jpg', $items[0]['promo_visual']);
        self::assertFalse($items[0]['fallback']);
        self::assertSame('legacy', $items[1]['ref']);
        self::assertSame('connector', $items[1]['source']);
        self::assertSame('Profiter', $items[1]['cta']);
        self::assertTrue($items[1]['tracked']);
    }

    public function testUntrackedPromoOutsideCatalogue(): void
    {
        $items = gallery_promos_merge([], [['name' => 'Pièce unique', 'shop' => 'atelier', 'ref' => 'x1', 'url' => 'https://atelier.test/x1']]);
        self::assertCount(1, $items);
        self::assertFalse($items[0]['tracked']);
        self::assertTrue($items[0]['fallback']); // pas de visuel promo : la carte reprend la photo de l'article
        self::assertSame('https://atelier.test/x1', $items[0]['url']);
    }

    public function testPromoExists(): void
    {
        $promos = [['shop' => 'boutique', 'ref' => 'abc']];
        self::assertTrue(gallery_promo_exists($promos, 'boutique', 'abc'));
        self::assertFalse(gallery_promo_exists($promos, 'boutique', 'autre'));
        self::assertFalse(gallery_promo_exists([], 'boutique', 'abc'));
    }
}