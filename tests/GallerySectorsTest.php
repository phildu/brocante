<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/galleries.php';

/**
 * Sous-zones (secteurs) d'un plan : rôle conservé à la normalisation, identifiants uniques, commerces rattachés au plus petit secteur.
 */
final class GallerySectorsTest extends TestCase
{
    private static function square(float $lat, float $lng, float $d): array
    {
        return [[$lat, $lng], [$lat, $lng + $d], [$lat + $d, $lng + $d], [$lat + $d, $lng]];
    }

    private function plan(): array
    {
        $sq = [self::class, 'square'];
        return gallery_map_normalize(['items' => [
            ['kind' => 'polygon', 'role' => 'territoire', 'label' => 'Marché', 'pts' => $sq(48.0, 2.0, 0.01)],
            ['kind' => 'polygon', 'role' => 'secteur', 'label' => 'Halle A', 'id' => 'secta001', 'pts' => $sq(48.001, 2.001, 0.003)],
            ['kind' => 'polygon', 'role' => 'secteur', 'label' => 'Halle A', 'id' => 'sectb001', 'pts' => $sq(48.006, 2.006, 0.003)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '1', 'shop' => 'paul', 'pts' => $sq(48.002, 2.002, 0.0005)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '2', 'pts' => $sq(48.0025, 2.0025, 0.0005)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '3', 'shop' => 'lea', 'pts' => $sq(48.007, 2.007, 0.0005)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '4', 'shop' => 'zoe', 'pts' => $sq(48.0095, 2.0005, 0.0005)],
        ]], ['paul', 'lea', 'zoe']);
    }

    public function testSectorRoleSurvivesNormalizationAndIsNotASlot(): void
    {
        $roles = array_count_values(array_column($this->plan()['items'], 'role'));
        self::assertSame(2, $roles['secteur']);
        self::assertSame(4, $roles['slot']);
    }

    public function testSlugsAreUniqueAndShopsGoToTheirSector(): void
    {
        $sectors = gallery_map_sectors($this->plan());
        self::assertSame(['halle-a', 'halle-a-2'], array_column($sectors, 'slug'));
        self::assertSame(['paul'], $sectors[0]['shops']);
        self::assertSame(2, $sectors[0]['slots']);
        self::assertSame(['lea'], $sectors[1]['shops']);
    }

    public function testShopOutsideEverySectorAndUnknownSlug(): void
    {
        $all = array_merge(...array_column(gallery_map_sectors($this->plan()), 'shops'));
        self::assertNotContains('zoe', $all);
        self::assertNull(gallery_map_sector($this->plan(), 'inconnu'));
        self::assertSame([], gallery_map_sectors(null));
    }

    public function testSmallestSectorWinsWhenNested(): void
    {
        $sq = [self::class, 'square'];
        $map = gallery_map_normalize(['items' => [
            ['kind' => 'polygon', 'role' => 'secteur', 'label' => 'Site', 'pts' => $sq(48.0, 2.0, 0.01)],
            ['kind' => 'polygon', 'role' => 'secteur', 'label' => 'Halle', 'pts' => $sq(48.001, 2.001, 0.003)],
            ['kind' => 'polygon', 'role' => 'slot', 'shop' => 'paul', 'pts' => $sq(48.002, 2.002, 0.0005)],
        ]], ['paul']);
        $by = array_column(gallery_map_sectors($map), 'shops', 'slug');
        self::assertSame(['paul'], $by['halle']);
        self::assertSame([], $by['site']);
    }
}
