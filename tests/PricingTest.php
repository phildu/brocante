<?php

use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    public function testPlanWithoutNameIsRejected(): void
    {
        self::assertNull(saas_offer_normalize(['monthly' => 990], 'plan'));
    }

    public function testTierWithoutNameGetsGeneratedLabel(): void
    {
        $capped = saas_offer_normalize(['max_items' => 500, 'monthly' => 1990], 'tier');
        self::assertSame("Jusqu'à 500 articles", $capped['name']);
        $unlimited = saas_offer_normalize(['monthly' => 6990], 'tier');
        self::assertSame('Articles illimités', $unlimited['name']);
    }

    public function testPricesAreClampedAndNullWhenEmpty(): void
    {
        $o = saas_offer_normalize(['name' => 'X', 'monthly' => 99999999, 'yearly' => -5, 'max_items' => 0], 'plan');
        self::assertSame(10000000, $o['monthly']);
        self::assertSame(0, $o['yearly']);
        self::assertSame(1, $o['max_items']);
        $devis = saas_offer_normalize(['name' => 'X', 'monthly' => ''], 'plan');
        self::assertNull($devis['monthly']);
        $notNumeric = saas_offer_normalize(['name' => 'X', 'monthly' => 'abc'], 'plan');
        self::assertNull($notNumeric['monthly']);
    }

    public function testInvalidKeyFallsBackToSlug(): void
    {
        $o = saas_offer_normalize(['name' => 'Vente en ligne', 'monthly' => 990], 'plan');
        self::assertSame('vente-en-ligne', $o['key']);
        $valid = saas_offer_normalize(['key' => 'std-2', 'name' => 'Standard', 'monthly' => 990], 'plan');
        self::assertSame('std-2', $valid['key']);
    }

    public function testAiQuotaParsing(): void
    {
        self::assertNull(saas_offer_normalize(['name' => 'X'], 'plan')['ai_quota_eur']);
        self::assertNull(saas_offer_normalize(['name' => 'X', 'ai_quota_eur' => 'abc'], 'plan')['ai_quota_eur']);
        self::assertSame(2.5, saas_offer_normalize(['name' => 'X', 'ai_quota_eur' => '2,5'], 'plan')['ai_quota_eur']);
        self::assertSame(0.0, saas_offer_normalize(['name' => 'X', 'ai_quota_eur' => '-3'], 'plan')['ai_quota_eur']);
    }

    public function testGridClampsDiscount(): void
    {
        $g = saas_grid_normalize(['discount_pct' => 150, 'plans' => [['name' => 'A']]]);
        self::assertSame(90, $g['discount_pct']);
        $g = saas_grid_normalize(['discount_pct' => -5, 'plans' => [['name' => 'A']]]);
        self::assertSame(0, $g['discount_pct']);
    }

    public function testGridDeduplicatesKeysAcrossPlansAndTiers(): void
    {
        $g = saas_grid_normalize([
            'plans' => [['key' => 'ab', 'name' => 'P', 'monthly' => 100], ['key' => 'ab', 'name' => 'Q', 'monthly' => 200]],
            'tiers' => [['key' => 'ab', 'max_items' => 100]],
        ]);
        self::assertSame(['ab', 'ab-2'], array_column($g['plans'], 'key'));
        self::assertSame('ab-3', $g['tiers'][0]['key']);
    }

    public function testGridSortsTiersWithUnlimitedLast(): void
    {
        $g = saas_grid_normalize([
            'tiers' => [
                ['max_items' => 500],
                ['max_items' => null],
                ['max_items' => 20],
            ],
        ]);
        self::assertSame([20, 500, null], array_column($g['tiers'], 'max_items'));
    }

    public function testOfferCentsByBilling(): void
    {
        $offer = ['monthly' => 990, 'yearly_eff' => 9500];
        self::assertSame(990, saas_offer_cents($offer, 'month'));
        self::assertSame(9500, saas_offer_cents($offer, 'year'));
        self::assertNull(saas_offer_cents(['monthly' => 990], 'year'));
    }

    public function testPriceText(): void
    {
        self::assertSame('Sur devis', saas_price_text(null));
        self::assertSame('Gratuit', saas_price_text(0));
        self::assertSame('9,90 € / mois', saas_price_text(990));
        self::assertSame('99 € / an', saas_price_text(9900, 'year'));
    }

    public function testSavingCents(): void
    {
        self::assertSame(4000, saas_offer_saving_cents(['monthly' => 1990, 'yearly_eff' => 23880 - 4000]));
        self::assertSame(0, saas_offer_saving_cents(['monthly' => null, 'yearly_eff' => 1000]));
        // Un paiement annuel plus cher qu'un mensuel ne rapporte pas d'économie négative.
        self::assertSame(0, saas_offer_saving_cents(['monthly' => 100, 'yearly_eff' => 2000]));
    }

    public function testLimitLabel(): void
    {
        self::assertSame('', saas_offer_limit_label(['model' => 'plan', 'max_items' => null]));
        self::assertSame('Articles illimités', saas_offer_limit_label(['model' => 'tier', 'max_items' => null]));
        self::assertSame("Jusqu'à 500 articles", saas_offer_limit_label(['model' => 'tier', 'max_items' => 500]));
        self::assertSame("Jusqu'à 1 000 articles", saas_offer_limit_label(['model' => 'tier', 'max_items' => 1000]));
    }
}
