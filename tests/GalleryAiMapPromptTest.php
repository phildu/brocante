<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/galleries.php';
require_once __DIR__ . '/../includes/gallery-ai-map.php';

/**
 * Stylisation IA du fond : niveau de simplification, consigne personnalisée, réglages enregistrés avec le plan.
 */
final class GalleryAiMapPromptTest extends TestCase
{
    public function testLevelThreeKeepsTheOriginalRule(): void
    {
        $p = gaim_prompt('aquarelle', 'osm', 3);
        self::assertStringContainsString('Do not add, remove or displace streets', $p);
        self::assertStringContainsString('watercolour', $p);
    }

    public function testSimplifiedLevelsAddTheirRuleAndRelaxTheKeepRule(): void
    {
        $p = gaim_prompt('aquarelle', 'sat', 5);
        self::assertStringContainsString('Simplify strongly', $p);
        self::assertStringNotContainsString('Do not add, remove or displace streets', $p);
        self::assertStringContainsString('Do not invent or displace streets', $p);
        self::assertStringContainsString('Strictly top-down', $p);
        self::assertStringContainsString('Remove all text', $p);
    }

    public function testCustomTextReplacesThePresetLook(): void
    {
        $p = gaim_prompt('croquis', 'osm', 3, 'crayon de couleur pastel');
        self::assertStringContainsString('Redraw this map view as crayon de couleur pastel.', $p);
        self::assertStringNotContainsString('ink sketch', $p);
    }

    public function testSettingsAreSanitised(): void
    {
        $s = gallery_ai_map_settings('inconnu', 99, "  a\n\tb  " . str_repeat('x', 500));
        self::assertSame('aquarelle', $s['style']);
        self::assertSame(5, $s['level']);
        self::assertSame(400, mb_strlen($s['custom']));
        self::assertStringStartsWith('a b x', $s['custom']);
        self::assertSame(3, gallery_ai_map_settings('bd', 0, '')['level']);
        self::assertSame(1, gallery_ai_map_settings('bd', -4, '')['level']);
    }

    public function testSettingsTravelWithThePlan(): void
    {
        $m = gallery_map_normalize(['center' => [48.0, 2.0], 'ai' => ['style' => 'retro', 'level' => '4', 'custom' => ' pastel ']]);
        self::assertSame(['style' => 'retro', 'level' => 4, 'custom' => 'pastel'], $m['ai']);
        $d = gallery_map_normalize(['center' => [48.0, 2.0]]);
        self::assertSame(['style' => 'aquarelle', 'level' => 3, 'custom' => ''], $d['ai']);
    }
}
