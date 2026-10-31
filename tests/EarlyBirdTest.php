<?php

use PHPUnit\Framework\TestCase;

final class EarlyBirdTest extends TestCase
{
    public function testCampaignNormalizeSortsTiersAndKeepsOneUnlimitedLast(): void
    {
        $c = eb_campaign_normalize([
            'target' => 0,
            'lock_months' => 500,
            'ends_at' => '31/12/2026',
            'tiers' => [
                ['label' => 'Fin', 'discount_pct' => 10],
                ['up_to' => 20, 'label' => 'Vingt', 'discount_pct' => 20],
                ['up_to' => 5, 'label' => 'Cinq', 'discount_pct' => 30],
                ['label' => 'Doublon', 'discount_pct' => 5],
            ],
        ]);
        self::assertSame(1, $c['target']);
        self::assertSame(120, $c['lock_months']);
        self::assertSame('', $c['ends_at']);
        self::assertSame([5, 20, null], array_column($c['tiers'], 'up_to'));
        self::assertSame('Fin', $c['tiers'][2]['label']);
    }

    public function testTierWithoutLabelGetsDefaultLabelAndEmptyTiersAreDropped(): void
    {
        $c = eb_campaign_normalize(['tiers' => [['up_to' => 10, 'discount_pct' => 25], ['up_to' => 20, 'label' => 'Sans remise'], []]]);
        self::assertCount(2, $c['tiers']);
        self::assertSame('Pionnier', $c['tiers'][0]['label']);
        self::assertSame('Sans remise', $c['tiers'][1]['label']);
        self::assertSame(0, $c['tiers'][1]['discount_pct']);
    }

    public function testTierAtRankBoundaries(): void
    {
        $c = eb_default_campaign();
        self::assertSame('Fondateur', eb_tier_at($c, 1)['label']);
        self::assertSame('Fondateur', eb_tier_at($c, 10)['label']);
        self::assertSame('Pionnier', eb_tier_at($c, 11)['label']);
        self::assertSame('Pionnier', eb_tier_at($c, 20)['label']);
        self::assertSame('Early bird', eb_tier_at($c, 21)['label']);
        self::assertSame('Early bird', eb_tier_at($c, 5000)['label']);
        $capped = eb_campaign_normalize(['tiers' => [['up_to' => 5, 'label' => 'Cinq', 'discount_pct' => 10]]]);
        self::assertNull(eb_tier_at($capped, 6));
    }

    public function testDiscountRoundsToTenCentsAndClamps(): void
    {
        self::assertSame(1490, eb_discount_cents(1990, 25));
        self::assertSame(900, eb_discount_cents(995, 10));
        self::assertSame(0, eb_discount_cents(0, 50));
        self::assertSame(1990, eb_discount_cents(1990, 0));
        // Une remise de 100 % est ramenée à 90 %.
        self::assertSame(100, eb_discount_cents(1000, 100));
    }

    public function testProgressCounts(): void
    {
        $c = ['enabled' => true, 'target' => 30, 'ends_at' => ''];
        $p = eb_progress_counts($c, 12, 1000);
        self::assertSame(['enrolled' => 12, 'target' => 30, 'left' => 18, 'pct' => 40, 'closed' => false, 'expired' => false, 'open' => true, 'next_rank' => 13], $p);

        $closed = eb_progress_counts($c, 30, 1000);
        self::assertTrue($closed['closed']);
        self::assertFalse($closed['open']);
        self::assertSame(0, $closed['left']);

        $expired = eb_progress_counts(['enabled' => true, 'target' => 30, 'ends_at' => '2026-01-31'], 12, strtotime('2026-02-01 00:00:00'));
        self::assertTrue($expired['expired']);
        self::assertFalse($expired['open']);

        $disabled = eb_progress_counts(['enabled' => false, 'target' => 10], 0);
        self::assertFalse($disabled['open']);
    }

    public function testApplyToOfferKeepsListPrices(): void
    {
        $offer = ['monthly' => 1990, 'yearly' => 19900, 'yearly_eff' => 19900];
        $o = eb_apply_to_offer($offer, ['label' => 'Fondateur', 'discount_pct' => 40]);
        self::assertSame(1190, $o['monthly']);
        self::assertSame(11940, $o['yearly_eff']);
        self::assertSame(11940, $o['yearly']);
        self::assertSame(1990, $o['list_monthly']);
        self::assertSame(19900, $o['list_yearly_eff']);
        self::assertSame(40, $o['eb_discount_pct']);
        // Sans palier : l'offre est rendue telle quelle.
        self::assertSame($offer, eb_apply_to_offer($offer, null));
        self::assertSame($offer, eb_apply_to_offer($offer, ['label' => 'Gratuit', 'discount_pct' => 0]));
    }

    public function testApplyToSnapshotDiscountsStripeCents(): void
    {
        // Offre figée d'une demande (saas_offer_snapshot) : c'est « cents » que Stripe débite.
        $snapshot = ['key' => 'standard', 'name' => 'Standard', 'model' => 'plan', 'billing' => 'month', 'cents' => 1990, 'max_items' => 500, 'gallery' => 'g'];
        $o = eb_apply_to_offer($snapshot, ['label' => 'Pionnier', 'discount_pct' => 25]);
        self::assertSame(1490, $o['cents']);
        self::assertSame(1990, $o['list_cents']);
        $yearly = eb_apply_to_offer(['billing' => 'year', 'cents' => 19900], ['discount_pct' => 15]);
        self::assertSame(16920, $yearly['cents']);
    }

    public function testDiscountedGridKeepsKeysAndSplit(): void
    {
        $grid = saas_grid_normalize([
            'yearly' => true,
            'plans' => [['key' => 'std', 'name' => 'Standard', 'monthly' => 1990, 'yearly' => 19900]],
            'tiers' => [['key' => 't50', 'max_items' => 50, 'monthly' => 990]],
        ]);
        $d = eb_discounted_grid($grid, ['label' => 'Pionnier', 'discount_pct' => 25]);
        self::assertSame('std', $d['plans'][0]['key']);
        self::assertSame('t50', $d['tiers'][0]['key']);
        self::assertCount(1, $d['plans']);
        self::assertCount(1, $d['tiers']);
        self::assertSame(1490, $d['plans'][0]['monthly']);
        self::assertSame(14930, $d['plans'][0]['yearly_eff']);
        self::assertSame(740, $d['tiers'][0]['monthly']);
        self::assertSame(8250, $d['tiers'][0]['yearly_eff']); // 990 × 12 = 11 880 → 11 000, remisé à 25 %
        self::assertSame(['discount_pct' => 25, 'label' => 'Pionnier'], $d['eb']);
        // Sans palier, la grille est inchangée.
        self::assertSame($grid, eb_discounted_grid($grid, null));
    }

    public function testLockLabel(): void
    {
        self::assertSame('', eb_lock_label(null));
        self::assertSame('', eb_lock_label(['title' => 'Pionniers', 'discount_pct' => 0, 'rank' => 3]));
        self::assertSame('Pionniers −25 % · rang 12', eb_lock_label(['title' => 'Pionniers', 'discount_pct' => 25, 'rank' => 12]));
        self::assertSame('Pionniers −15 %', eb_lock_label(['title' => 'Pionniers', 'discount_pct' => 15]));
    }
}
