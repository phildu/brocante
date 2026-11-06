<?php

use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testAccentsAndCase(): void
    {
        self::assertSame('hotel-des-ventes', slugify('Hôtel des Ventes'));
        self::assertSame('metal-bois', slugify('Métal & Bois'));
        self::assertSame('cafe-creme-brulee', slugify('Café — crème brûlée'));
    }

    public function testLigatures(): void
    {
        self::assertSame('oeuf', slugify('Œuf'));
        self::assertSame('caecilia', slugify('Cæcilia'));
    }

    public function testMaxLength(): void
    {
        self::assertSame(60, strlen(slugify(str_repeat('a', 70))));
        self::assertSame('aaa', slugify('aaaaa', 3));
    }

    public function testEmptyFallsBackToMedia(): void
    {
        self::assertSame('media', slugify(''));
        self::assertSame('media', slugify('——'));
    }

    public function testUsedForOfferKeys(): void
    {
        // Clé dérivée d'un nom d'offre via saas_slug (accents et espaces gérés de la même façon).
        $o = saas_offer_normalize(['name' => 'Vente en ligne Découverte', 'monthly' => 990], 'plan');
        self::assertSame('vente-en-ligne-decouverte', $o['key']);
    }
}
