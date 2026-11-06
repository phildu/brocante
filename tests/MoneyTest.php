<?php

use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testIsFixedPrice(): void
    {
        self::assertFalse(is_fixed_price(null));
        self::assertFalse(is_fixed_price(''));
        self::assertFalse(is_fixed_price('sur devis'));
        self::assertFalse(is_fixed_price('abc'));
        self::assertTrue(is_fixed_price('120 €'));
        self::assertTrue(is_fixed_price('1 250,50 €'));
    }

    public function testIsFixedPriceRejectsRangesAndEstimates(): void
    {
        self::assertFalse(is_fixed_price('Dès 120 €'));
        self::assertFalse(is_fixed_price('dès 120 €'));
        self::assertFalse(is_fixed_price('à partir de 50 €'));
        self::assertFalse(is_fixed_price('environ 30 €'));
        self::assertTrue(is_fixed_price('120-180 €')); // la fourchette n'est pas détectée ici : seuls « dès / à partir / environ / sur devis » la rendent non fixe.
    }

    public function testPriceToCents(): void
    {
        self::assertNull(price_to_cents(null));
        self::assertNull(price_to_cents(''));
        self::assertNull(price_to_cents('sur devis'));
        self::assertSame(2500, price_to_cents('25'));
        self::assertSame(2550, price_to_cents('25,50'));
        self::assertSame(2550, price_to_cents('25.5'));
        self::assertSame(125000, price_to_cents('1 250 €'));
    }

    public function testPriceToCentsHandlesNarrowNoBreakSpace(): void
    {
        // Prix copié-collé depuis une page web : « 1 250 € » avec U+00A0 / U+202F.
        self::assertSame(125000, price_to_cents("1\xc2\xa0250 €"));
        self::assertSame(125000, price_to_cents("1\xe2\x80\xaf250 €"));
    }

    public function testPromoAppliedOnlyWhenBadgePriceAndFixed(): void
    {
        $p = ['price' => '100 €', 'badge' => 'Promo', 'promo_price' => '80 €'];
        self::assertTrue(has_promo_price($p));
        self::assertSame('80 €', effective_price($p));

        $noBadge = ['price' => '100 €', 'promo_price' => '80 €'];
        self::assertFalse(has_promo_price($noBadge));
        self::assertSame('100 €', effective_price($noBadge));

        $rangePromo = ['price' => '100 €', 'badge' => 'Promo', 'promo_price' => 'dès 80 €'];
        self::assertFalse(has_promo_price($rangePromo));
        self::assertSame('100 €', effective_price($rangePromo));
    }

    public function testHEscapesQuotes(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;l&#039;amour&lt;/a&gt;', h('<a href="x">l\'amour</a>'));
        self::assertSame('', h(null));
    }
}
