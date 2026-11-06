<?php

use PHPUnit\Framework\TestCase;

final class SlideshowPromoTest extends TestCase
{
    public function testDiscountPercentage(): void
    {
        self::assertSame(24, slideshow_promo_discount(['price' => '42 €', 'promo_price' => '32 €']));
        self::assertSame(38, slideshow_promo_discount(['price' => '32 €', 'promo_price' => '20 €']));
    }

    public function testNoDiscountWhenPromoPriceIsNotLower(): void
    {
        self::assertSame(0, slideshow_promo_discount(['price' => '20 €', 'promo_price' => '20 €']));
        self::assertSame(0, slideshow_promo_discount(['price' => '20 €', 'promo_price' => '25 €']));
    }

    public function testNoDiscountWithoutUsablePrices(): void
    {
        self::assertSame(0, slideshow_promo_discount(['price' => '', 'promo_price' => '20 €']));
        self::assertSame(0, slideshow_promo_discount(['price' => 'sur devis', 'promo_price' => '20 €']));
        self::assertSame(0, slideshow_promo_discount([]));
    }

    public function testSpacedPricesAreReadCorrectly(): void
    {
        self::assertSame(20, slideshow_promo_discount(['price' => '1 250 €', 'promo_price' => '1 000 €']));
    }
}
