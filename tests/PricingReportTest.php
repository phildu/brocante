<?php

use PHPUnit\Framework\TestCase;

defined('PLUGIN_RUNTIME') || define('PLUGIN_RUNTIME', true);
require_once dirname(__DIR__) . '/packs/annonces-marche/lib/pricing.php';

/** Rapport de prix : lecture des relevés de l'extension et verdict face à la fourchette de la plateforme. */
final class PricingReportTest extends TestCase
{
    public function testSuggestionIsReadFromNote(): void
    {
        self::assertSame(['min' => 8.5, 'max' => 15.0], am_suggestion_from_note('[h:0123456789abcdef] [s:8.5-15] published'));
        self::assertSame(['min' => 12.0, 'max' => 12.0], am_suggestion_from_note('[s:12-12] drafted'));
        self::assertNull(am_suggestion_from_note('[s:?] prepared'));
        self::assertNull(am_suggestion_from_note('published'));
        self::assertNull(am_suggestion_from_note('[s:15-8] published'), 'fourchette inversée ignorée');
    }

    public function testVerdictUsesRangeAndTolerance(): void
    {
        self::assertSame('lower', am_price_verdict(30.0, 8.0, 15.0)['state']);
        self::assertSame(15.0, am_price_verdict(30.0, 8.0, 15.0)['target']);
        self::assertSame('raise', am_price_verdict(5.0, 8.0, 15.0)['state']);
        self::assertSame(8.0, am_price_verdict(5.0, 8.0, 15.0)['target']);
        self::assertSame('ok', am_price_verdict(10.0, 8.0, 15.0)['state']);
        self::assertSame('ok', am_price_verdict(16.0, 8.0, 15.0)['state'], '16 € est à moins de 10 % de 15 €');
        self::assertSame('lower', am_price_verdict(17.0, 8.0, 15.0)['state']);
        self::assertSame('Baisser à 15 €', am_price_verdict(30.0, 8.0, 15.0)['label']);
    }
}
