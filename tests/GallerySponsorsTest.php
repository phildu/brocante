<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/gallery-modules.php';
require_once __DIR__ . '/../includes/gallery-sponsors.php';

/**
 * Extension « Sponsors & partenaires » : normalisation des fiches, tri par niveau, sélection publique, stockage dans un dossier jetable.
 */
final class GallerySponsorsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gsp-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('BROCANTE_GALLERIES_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('BROCANTE_GALLERIES_DIR');
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testModuleIsRegisteredForEveryTypeAndIncluded(): void
    {
        $m = gm_module('sponsors');
        self::assertNotNull($m);
        self::assertSame([], $m['types']);
        self::assertSame('included', $m['default_mode']);
        self::assertSame('included', gm_default_type_mode('vide-grenier', 'sponsors'));
        self::assertSame('included', gm_default_type_mode('galerie-marchande', 'sponsors'));
    }

    public function testUrlIsHttpOnlyAndGetsHttpsWhenSchemeIsMissing(): void
    {
        self::assertSame('https://exemple.fr', gsp_url('exemple.fr'));
        self::assertSame('http://exemple.fr/a?b=1', gsp_url(' http://exemple.fr/a?b=1 '));
        self::assertSame('', gsp_url('javascript:alert(1)'));
        self::assertSame('', gsp_url('ftp://exemple.fr'));
        self::assertSame('', gsp_url('https://localhost'));
        self::assertSame('', gsp_url('https://exemple.fr/a b'));
        self::assertSame('', gsp_url(''));
    }

    public function testLogoMustBeAGalleryUpload(): void
    {
        self::assertSame('uploads/galeries/x-logo-1.png', gsp_logo('uploads/galeries/x-logo-1.png'));
        self::assertSame('', gsp_logo('https://cdn.test/logo.png'));
        self::assertSame('', gsp_logo('uploads/galeries/../secret.png'));
        self::assertSame('', gsp_logo('/uploads/galeries/x.png'));
        self::assertSame('', gsp_logo('uploads/galeries/x.php'));
    }

    public function testNormalizeRequiresNameAndCleansFields(): void
    {
        self::assertNull(gsp_normalize('pas un tableau'));
        self::assertNull(gsp_normalize(['name' => '   ']));

        $s = gsp_normalize([
            'name' => "  Boulangerie \n du  Marché ", 'tier' => 'inconnu', 'url' => 'boulangerie.fr',
            'description' => str_repeat('é', 300), 'order' => 5000, 'visible' => 0, 'id' => 'pas valide',
        ]);
        self::assertSame('Boulangerie du Marché', $s['name']);
        self::assertSame('partenaire', $s['tier']);
        self::assertSame('https://boulangerie.fr', $s['url']);
        self::assertSame(240, mb_strlen($s['description']));
        self::assertSame(999, $s['order']);
        self::assertFalse($s['visible']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $s['id']);

        // Sans clé « visible » (ajout), la fiche est affichée ; l'identifiant valide est conservé.
        $kept = gsp_normalize(['name' => 'Mairie', 'id' => 'ABCDEF12']);
        self::assertTrue($kept['visible']);
        self::assertSame('abcdef12', $kept['id']);
    }

    public function testListIsSortedByTierThenOrderThenNameAndDeduplicated(): void
    {
        $list = gsp_normalize_list([
            ['id' => 'aaaaaaaa', 'name' => 'Zèbre', 'tier' => 'partenaire', 'order' => 1],
            ['id' => 'bbbbbbbb', 'name' => 'Abeille', 'tier' => 'partenaire', 'order' => 1],
            ['id' => 'cccccccc', 'name' => 'Banque', 'tier' => 'principal'],
            ['id' => 'dddddddd', 'name' => 'Radio', 'tier' => 'media'],
            ['id' => 'eeeeeeee', 'name' => 'Premier', 'tier' => 'partenaire', 'order' => 0],
            ['id' => 'aaaaaaaa', 'name' => 'Doublon', 'tier' => 'principal'],
            ['name' => ''],
        ]);
        self::assertSame(['Banque', 'Premier', 'Abeille', 'Zèbre', 'Radio'], array_column($list, 'name'));
    }

    public function testListIsCapped(): void
    {
        $many = [];
        for ($i = 0; $i < GSP_MAX + 10; $i++) $many[] = ['name' => 'Sponsor ' . $i];
        self::assertCount(GSP_MAX, gsp_normalize_list($many));
    }

    public function testSaveLoadAndPublicSelection(): void
    {
        $slug = 'galerie-test';
        self::assertSame([], gsp_load($slug));
        self::assertTrue(gsp_save($slug, [
            ['id' => '11111111', 'name' => 'Caché', 'tier' => 'sponsor', 'visible' => false],
            ['id' => '22222222', 'name' => 'Mairie', 'tier' => 'institutionnel', 'note' => 'contact interne'],
            ['id' => '33333333', 'name' => 'Banque', 'tier' => 'principal', 'url' => 'banque.fr'],
        ]));
        self::assertFileExists($this->dir . '/galerie-test.sponsors.json');
        self::assertCount(3, gsp_load($slug));

        $public = gsp_public($slug);
        self::assertSame(['principal', 'institutionnel'], array_keys($public));
        self::assertSame('Banque', $public['principal'][0]['name']);

        $stats = gsp_stats(gsp_load($slug));
        self::assertSame(3, $stats['total']);
        self::assertSame(2, $stats['visibles']);
        self::assertSame(1, $stats['par_niveau']['sponsor']);
        self::assertSame(0, $stats['par_niveau']['media']);
    }

    public function testInvalidSlugIsRefused(): void
    {
        self::assertSame('', gsp_file('../evil'));
        self::assertFalse(gsp_save('../evil', [['name' => 'X']]));
        self::assertSame([], gsp_load('../evil'));
    }

    public function testDeleteAllRemovesTheFile(): void
    {
        gsp_save('galerie-test', [['name' => 'Banque']]);
        self::assertFileExists($this->dir . '/galerie-test.sponsors.json');
        gsp_delete_all('galerie-test');
        self::assertFileDoesNotExist($this->dir . '/galerie-test.sponsors.json');
    }
}
