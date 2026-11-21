<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/galleries.php';
require_once __DIR__ . '/../includes/gallery-modules.php';
require_once __DIR__ . '/../includes/gallery-visit.php';

/**
 * Extension « Visite guidée » : proposée aux marchés aux puces, réglages normalisés, étapes bâties depuis les secteurs du plan.
 */
final class GalleryVisitTest extends TestCase
{
    private string $dir;
    /** @var string|false */
    private $previousDir;

    protected function setUp(): void
    {
        $this->previousDir = getenv('BROCANTE_GALLERIES_DIR');
        $this->dir = sys_get_temp_dir() . '/gvisit-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('BROCANTE_GALLERIES_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDir === false ? 'BROCANTE_GALLERIES_DIR' : 'BROCANTE_GALLERIES_DIR=' . $this->previousDir);
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    private static function square(float $lat, float $lng, float $d): array
    {
        return [[$lat, $lng], [$lat, $lng + $d], [$lat + $d, $lng + $d], [$lat + $d, $lng]];
    }

    private static function shops(): array
    {
        $shop = static fn (string $name, string $tagline = ''): array => ['name' => $name, 'tagline' => $tagline, 'logo' => '', 'url' => '', 'accent' => '', 'featured' => false, 'order' => 0, 'count' => 0];
        return ['paul' => $shop('Chez Paul', 'Vinyles'), 'lea' => $shop('Léa Brocante'), 'zoe' => $shop('Zoé', 'Linge ancien')];
    }

    private function gallery(bool $withSectors): array
    {
        $sq = [self::class, 'square'];
        $items = $withSectors ? [
            ['kind' => 'polygon', 'role' => 'secteur', 'label' => 'Hall Louis', 'id' => 'secta001', 'note' => 'Le grand hall couvert.', 'pts' => $sq(48.001, 2.001, 0.003)],
            ['kind' => 'polygon', 'role' => 'secteur', 'label' => 'Cour', 'id' => 'sectb001', 'pts' => $sq(48.006, 2.006, 0.003)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '1', 'shop' => 'paul', 'pts' => $sq(48.002, 2.002, 0.0005)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '3', 'shop' => 'lea', 'pts' => $sq(48.007, 2.007, 0.0005)],
            ['kind' => 'polygon', 'role' => 'slot', 'num' => '4', 'shop' => 'zoe', 'pts' => $sq(48.0195, 2.0005, 0.0005)],   // hors de tout secteur
        ] : [];
        return gallery_normalize('puces-test', [
            'name' => 'Puces de test', 'type' => 'puces', 'published' => true,
            'members' => [['type' => 'local', 'slug' => 'paul'], ['type' => 'local', 'slug' => 'lea'], ['type' => 'local', 'slug' => 'zoe']],
            'map' => ['items' => $items],
        ]);
    }

    public function testModuleIsOnlyForFleaMarketsAndIncludedThere(): void
    {
        $m = gm_module('visite');
        self::assertNotNull($m);
        self::assertSame(['puces'], $m['types']);
        self::assertTrue(gm_enabled($this->gallery(false), 'visite'));
        $other = $this->gallery(false);
        $other['type'] = 'marchande';
        self::assertFalse(gm_enabled($other, 'visite'));
    }

    public function testNormalizeCleansSettings(): void
    {
        self::assertSame(['show' => true, 'intro' => '', 'notes' => []], gv_normalize(null));
        $s = gv_normalize(['show' => 0, 'intro' => "  Bienvenue \r\n  chez  nous ", 'notes' => ['secta001' => '  Le hall ', 'pas valide' => 'x', 'vide' => '   ', 'long' => str_repeat('é', 900)]]);
        self::assertFalse($s['show']);
        self::assertSame("Bienvenue \n chez nous", $s['intro']);
        self::assertSame(['secta001', 'long'], array_keys($s['notes']));
        self::assertSame('Le hall', $s['notes']['secta001']);
        self::assertSame(GV_NOTE_MAX, mb_strlen($s['notes']['long']));
    }

    public function testSaveLoadAndDelete(): void
    {
        self::assertTrue(gv_save('puces-test', ['intro' => 'Salut', 'notes' => ['ailleurs' => 'Plus loin']]));
        self::assertFileExists($this->dir . '/puces-test.visite.json');
        self::assertSame('Salut', gv_load('puces-test')['intro']);
        self::assertSame('Plus loin', gv_load('puces-test')['notes']['ailleurs']);
        gv_delete_all('puces-test');
        self::assertFileDoesNotExist($this->dir . '/puces-test.visite.json');
        self::assertSame('', gv_file('../evil'));
        self::assertFalse(gv_save('../evil', []));
    }

    public function testOneStepPerSectorThenTheShopsOutsideAnySector(): void
    {
        $steps = gv_steps($this->gallery(true), self::shops(), gv_normalize([]));
        self::assertSame(['accueil', 'secteur', 'secteur', 'ailleurs', 'fin'], array_column($steps, 'kind'));
        self::assertSame('Hall Louis', $steps[1]['title']);
        self::assertSame('Le grand hall couvert.', $steps[1]['text']);   // la note du secteur sert de texte par défaut
        self::assertSame(['paul'], array_keys($steps[1]['shops']));
        self::assertSame(['lea'], array_keys($steps[2]['shops']));
        self::assertSame(['zoe'], array_keys($steps[3]['shops']));
        self::assertSame('Ailleurs sur le site', $steps[3]['title']);
    }

    public function testOrganizerNoteAndIntroOverrideDefaults(): void
    {
        $steps = gv_steps($this->gallery(true), self::shops(), gv_normalize(['intro' => 'Bienvenue !', 'notes' => ['secta001' => 'Commencez ici.']]));
        self::assertSame('Bienvenue !', $steps[0]['text']);
        self::assertSame('Commencez ici.', $steps[1]['text']);
    }

    public function testWithoutSectorsEachShopIsAStop(): void
    {
        $steps = gv_steps($this->gallery(false), self::shops(), gv_normalize(['notes' => ['lea' => 'Notre coup de cœur.']]));
        self::assertSame(['accueil', 'marchand', 'marchand', 'marchand', 'fin'], array_column($steps, 'kind'));
        self::assertSame('Vinyles', $steps[1]['text']);               // accroche du marchand par défaut
        self::assertSame('Notre coup de cœur.', $steps[2]['text']);
    }

    public function testSectorWithoutShopIsSkippedAndNoShopMeansNoTour(): void
    {
        self::assertSame([], gv_steps($this->gallery(true), [], gv_normalize([])));
        $steps = gv_steps($this->gallery(true), ['paul' => self::shops()['paul']], gv_normalize([]));
        self::assertSame(['accueil', 'secteur', 'fin'], array_column($steps, 'kind'));   // « Cour » n'a aucun marchand présent
    }

    public function testButtonVisibilityFollowsPublicationSwitchAndShops(): void
    {
        $g = $this->gallery(true);
        self::assertTrue(gv_visible($g, self::shops()));
        self::assertFalse(gv_visible($g, []));
        gv_save('puces-test', ['show' => false]);
        self::assertFalse(gv_visible($g, self::shops()));
        gv_save('puces-test', ['show' => true]);
        $g['published'] = false;
        self::assertFalse(gv_visible($g, self::shops()));
    }
}
