<?php

use PHPUnit\Framework\TestCase;

// Base SQLite isolée : le laboratoire CTA ne touche jamais data/saas pendant les tests.
define('CTA_DATA_DIR', sys_get_temp_dir() . '/brocs-cta-tests');
require_once __DIR__ . '/../includes/cta.php';

final class CtaTest extends TestCase
{
    protected function setUp(): void
    {
        cta_db()->exec('DELETE FROM cta_variants; DELETE FROM cta_counters; DELETE FROM cta_hits;');
    }

    public function testRegistryEntriesAreComplete(): void
    {
        $registry = cta_registry();
        self::assertNotEmpty($registry);
        foreach ($registry as $slot => $meta) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $slot);
            foreach (['group', 'where', 'page', 'url', 'label'] as $key) {
                self::assertArrayHasKey($key, $meta, $slot);
                self::assertNotSame('', $meta[$key], $slot . '.' . $key);
            }
        }
        self::assertSame(20, count($registry));
    }

    public function testLabelCleaning(): void
    {
        self::assertSame('Je veux vendre', cta_clean_label("  <b>Je</b>\n veux\tvendre  "));
        self::assertSame('Créer ma galerie', cta_clean_label('Créer ma galerie'));
        self::assertSame('', cta_clean_label('   '));
        self::assertSame(10, mb_strlen(cta_clean_label(str_repeat('a', 40), 10)));
    }

    public function testWeightClamping(): void
    {
        self::assertSame(0, cta_clean_weight(-5));
        self::assertSame(0, cta_clean_weight('abc'));
        self::assertSame(42, cta_clean_weight('42'));
        self::assertSame(CTA_MAX_WEIGHT, cta_clean_weight(500));
    }

    public function testDefaultPickUsesPageFallbackThenRegistryLabel(): void
    {
        $pick = cta_pick('annuaire_cta', 'Libellé de la page');
        self::assertSame('default', $pick['variant']);
        self::assertSame('Libellé de la page', $pick['label']);
        self::assertSame(0, $pick['id']);

        $fallbackless = cta_pick('site_header_cta');
        self::assertSame('default', $fallbackless['variant']);
        self::assertSame(cta_registry()['site_header_cta']['label'], $fallbackless['label']);
    }

    public function testWeightedPickExcludesZeroWeight(): void
    {
        $save = cta_save_variants('home_hero_primary', ['', ''], ['Ancienne', 'Gagnante'], [0, 80]);
        self::assertTrue($save['ok']);
        $rows = cta_variants('home_hero_primary');
        self::assertCount(2, $rows);

        $pick = cta_pick('home_hero_primary', 'Défaut');
        self::assertSame('v' . $rows[1]['id'], $pick['variant']);
        self::assertSame('Gagnante', $pick['label']);
    }

    public function testSaveValidation(): void
    {
        self::assertFalse(cta_save_variants('inconnu', [''], ['A'], [1])['ok']);
        self::assertStringContainsString('Au moins une', cta_save_variants('home_hero_gallery', ['', ''], ['A', 'B'], [0, 0])['error']);
        self::assertStringContainsString('au moins un libellé', cta_save_variants('home_hero_gallery', [''], ['   '], [1])['error']);

        $tooMany = cta_save_variants('home_hero_gallery', array_fill(0, 7, ''), array_fill(0, 7, 'Variante'), array_fill(0, 7, 1));
        self::assertFalse($tooMany['ok']);
        self::assertStringContainsString('Six variantes', $tooMany['error']);
    }

    public function testSaveUpdatesExistingIdsAndKeepsCountersAttached(): void
    {
        cta_save_variants('home_hero_login', ['', ''], ['A', 'B'], [1, 1]);
        $rows = cta_variants('home_hero_login');
        $id = (int) $rows[0]['id'];
        cta_record('home_hero_login', 'v' . $id, 10, 4);

        $save = cta_save_variants('home_hero_login', [(string) $id], ['A reformulé'], [2]);
        self::assertTrue($save['ok']);
        $after = cta_variants('home_hero_login');
        self::assertCount(1, $after);
        self::assertSame($id, (int) $after[0]['id']);
        self::assertSame('A reformulé', $after[0]['label']);
        self::assertSame(2, (int) $after[0]['weight']);

        $map = cta_stats_map(gmdate('Y-m-d'), gmdate('Y-m-d'), 'home_hero_login');
        self::assertArrayHasKey('home_hero_login|v' . $id, $map);
        self::assertSame(10, $map['home_hero_login|v' . $id]['impressions']);
    }

    public function testRemovedRowsAreDeleted(): void
    {
        cta_save_variants('home_hero_help', ['', ''], ['A', 'B'], [1, 1]);
        $rows = cta_variants('home_hero_help');
        cta_save_variants('home_hero_help', [(string) $rows[0]['id']], ['A'], [1]);
        $after = cta_variants('home_hero_help');
        self::assertCount(1, $after);
        self::assertSame('A', $after[0]['label']);
    }

    public function testClearVariantsKeepsCounters(): void
    {
        cta_save_variants('home_types_cta', [''], ['A'], [1]);
        $id = (int) cta_variants('home_types_cta')[0]['id'];
        cta_record('home_types_cta', 'v' . $id, 5, 2);

        self::assertTrue(cta_clear_variants('home_types_cta'));
        self::assertSame([], cta_variants('home_types_cta'));
        self::assertSame('default', cta_pick('home_types_cta', 'Retour')['variant']);

        $map = cta_stats_map(gmdate('Y-m-d'), gmdate('Y-m-d'), 'home_types_cta');
        self::assertArrayHasKey('home_types_cta|v' . $id, $map);
    }

    public function testTokenIsBoundToSlotAndVariant(): void
    {
        $token = cta_token('home_final_primary', 'v12');
        self::assertTrue(cta_token_valid('home_final_primary', 'v12', $token));
        self::assertFalse(cta_token_valid('home_final_primary', 'v13', $token));
        self::assertFalse(cta_token_valid('home_final_gallery', 'v12', $token));
        self::assertFalse(cta_token_valid('home_final_primary', 'v12', ''));
    }

    public function testRecordAggregatesImpressionsClicksAndCtr(): void
    {
        self::assertTrue(cta_record('home_final_gallery', 'default', 3, 0));
        self::assertTrue(cta_record('home_final_gallery', 'default', 2, 1));
        self::assertFalse(cta_record('inconnu', 'default', 1, 0));
        self::assertFalse(cta_record('home_final_gallery', 'x9', 1, 0));

        $stats = cta_stats(gmdate('Y-m-d'), gmdate('Y-m-d'), 'home_final_gallery');
        self::assertCount(1, $stats);
        self::assertSame(5, $stats[0]['impressions']);
        self::assertSame(1, $stats[0]['clicks']);
        self::assertSame(20.0, $stats[0]['ctr']);

        self::assertSame([], cta_stats('2000-01-01', '2000-01-02', 'home_final_gallery'));
    }

    public function testBotDetection(): void
    {
        self::assertTrue(cta_is_bot(''));
        self::assertTrue(cta_is_bot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        self::assertTrue(cta_is_bot('curl/8.7.1'));
        self::assertFalse(cta_is_bot('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1'));
    }

    public function testAttrsCarrySlotVariantAndToken(): void
    {
        $html = cta_attrs('annuaire_cta', 'Libellé');
        self::assertStringContainsString('data-cta="annuaire_cta"', $html);
        self::assertStringContainsString('data-cta-variant="default"', $html);
        self::assertStringContainsString('data-cta-token="' . cta_token('annuaire_cta', 'default') . '"', $html);
    }
}
