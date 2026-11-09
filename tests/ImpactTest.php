<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/impact.php';

final class ImpactTest extends TestCase
{
    public function testFamilyFromCategoryKey(): void
    {
        self::assertSame('ceramique', impact_family('ceramique'));
        self::assertSame('livre', impact_family('romans'));
        self::assertSame('livre', impact_family('beaux-livres'));
        self::assertSame('textile', impact_family('Vêtements'));
        self::assertSame('bois', impact_family('Meubles'));
        self::assertSame('curiosites', impact_family('truc-inconnu'));
        self::assertSame('curiosites', impact_family(''));
    }

    public function testPieceUsesWeightOrDefault(): void
    {
        // 1 kg de textile : 20 kg CO₂e/kg × 0,9 = 18 kg ; 7000 L × 0,9 = 6300 L
        $p = impact_piece('textile', 1000);
        self::assertEqualsWithDelta(18.0, $p['co2'], 0.001);
        self::assertEqualsWithDelta(6300.0, $p['water'], 0.001);
        // sans poids : poids moyen de la famille (600 g)
        self::assertEqualsWithDelta(10.8, impact_piece('textile', 0)['co2'], 0.001);
    }

    public function testChannelGroups(): void
    {
        self::assertSame('marketplace', impact_channel_group('vinted'));
        self::assertSame('marketplace', impact_channel_group('leboncoin'));
        self::assertSame('online', impact_channel_group('ecommerce'));
        self::assertSame('physical', impact_channel_group('stand'));
        self::assertSame('physical', impact_channel_group(''));
    }

    public function testAggregateSplitsChannelsAndAddsUp(): void
    {
        $r = impact_aggregate([
            ['channel' => 'vinted', 'cat' => 'textile', 'weight' => 1000, 'qty' => 2],
            ['channel' => 'ecommerce', 'cat' => 'ceramique', 'weight' => 1000, 'qty' => 1],
            ['channel' => 'stand', 'cat' => 'bois', 'weight' => 2000, 'qty' => 1],
            ['channel' => 'inconnu-xyz', 'cat' => 'bois', 'weight' => 2000, 'qty' => 0],
        ], 3, ['co2' => 5.0, 'water' => 100.0, 'family' => 'curiosites'], ['gemini_text' => 1000]);

        self::assertSame(2, $r['groups']['marketplace']['rows']['vinted']['pieces']);
        self::assertEqualsWithDelta(36.0, $r['groups']['marketplace']['total']['co2'], 0.001);
        self::assertSame(0, $r['groups']['marketplace']['rows']['leboncoin']['pieces']);
        self::assertSame(1, $r['groups']['online']['total']['pieces']);
        // boutique saisie (3 × 5 kg) + stand (2 kg bois × 3,5 × 0,9 = 6,3 kg)
        self::assertSame(4, $r['groups']['physical']['total']['pieces']);
        self::assertEqualsWithDelta(15.0 + 6.3, $r['groups']['physical']['total']['co2'], 0.001);
        self::assertSame(7, $r['total']['pieces']);
        self::assertArrayNotHasKey('inconnu-xyz', $r['groups']['physical']['rows']);
        // le coût numérique est retiré du bilan net
        self::assertEqualsWithDelta(1000 * 0.00003, $r['digital']['co2'], 0.0001);
        self::assertEqualsWithDelta($r['total']['co2'] - $r['digital']['co2'], $r['net']['co2'], 0.0001);
    }

    public function testEmptyReport(): void
    {
        $r = impact_aggregate([]);
        self::assertSame(0, $r['total']['pieces']);
        self::assertSame(0.0, $r['total']['co2']);
        self::assertSame([], $r['families']);
    }

    public function testFormatting(): void
    {
        self::assertSame('1,5 kg', impact_fmt_co2(1.5));
        self::assertSame('120 kg', impact_fmt_co2(120.0));
        self::assertSame('1,2 t', impact_fmt_co2(1200.0));
        self::assertSame('850 L', impact_fmt_water(850.0));
        self::assertSame('12,4 m³', impact_fmt_water(12400.0));
    }

    public function testMergeSumsReports(): void
    {
        $a = impact_aggregate([['channel' => 'vinted', 'cat' => 'textile', 'weight' => 1000, 'qty' => 1]], 0, null, ['gemini_text' => 100]);
        $b = impact_aggregate([
            ['channel' => 'vinted', 'cat' => 'textile', 'weight' => 1000, 'qty' => 2],
            ['channel' => 'stand', 'cat' => 'bois', 'weight' => 2000, 'qty' => 1],
        ]);
        $m = impact_merge([$a, $b]);

        self::assertSame(4, $m['total']['pieces']);
        self::assertSame(3, $m['groups']['marketplace']['rows']['vinted']['pieces']);
        self::assertEqualsWithDelta($a['total']['co2'] + $b['total']['co2'], $m['total']['co2'], 0.001);
        self::assertSame(1, $m['groups']['physical']['rows']['stand']['pieces']);
        self::assertSame(['textile', 'bois'], array_keys($m['families']));
        self::assertSame(100, $m['digital']['calls']);
        self::assertEqualsWithDelta($m['total']['co2'] - $m['digital']['co2'], $m['net']['co2'], 0.0001);
        self::assertSame(0, impact_merge([])['total']['pieces']);
    }
}
