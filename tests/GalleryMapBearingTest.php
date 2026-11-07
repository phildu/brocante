<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/galleries.php';

/**
 * Rotation du fond de carte : enregistrée avec le plan, bornée à ±180°.
 */
final class GalleryMapBearingTest extends TestCase
{
    private function map(array $extra): array
    {
        return gallery_map_normalize(['center' => [48.0, 2.0], 'zoom' => 17] + $extra);
    }

    public function testBearingIsKept(): void
    {
        self::assertSame(35, $this->map(['bearing' => 35])['bearing']);
        self::assertSame(-90, $this->map(['bearing' => '-90'])['bearing']);
    }

    public function testBearingDefaultsAndIsClamped(): void
    {
        self::assertSame(0, $this->map([])['bearing']);
        self::assertSame(180, $this->map(['bearing' => 999])['bearing']);
        self::assertSame(-180, $this->map(['bearing' => -999])['bearing']);
    }
}
