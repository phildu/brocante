<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/annuaire-externe.php';

/**
 * Fiches annuaire des commerces déjà en ligne : normalisation, soumission publique, validation, compteurs.
 * Le dossier de données est un dossier temporaire ; aucun fichier du projet n'est touché.
 */
final class AnnuaireExterneTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/brocs-annuaire-ext-' . getmypid();
        if (!defined('ANNUAIRE_EXTERNE_DIR')) define('ANNUAIRE_EXTERNE_DIR', $this->dir);
        if (!defined('ANNUAIRE_CACHE_FILE')) define('ANNUAIRE_CACHE_FILE', $this->dir . '/cache-test.json');
        if (!is_dir($this->dir)) mkdir($this->dir, 0755, true);
        $this->cleanDir();
    }

    protected function tearDown(): void
    {
        $this->cleanDir();
        @rmdir($this->dir);
    }

    private function cleanDir(): void
    {
        foreach (array_merge(glob($this->dir . '/*') ?: [], glob($this->dir . '/.*') ?: []) as $file) {
            if (is_file($file)) @unlink($file);
        }
    }

    private function profile(string $url = 'https://atelier.test', string $name = 'Atelier Test'): array
    {
        return ['url' => $url, 'name' => $name, 'tagline' => 'Chine et brocante', 'platform' => 'shopify'];
    }

    public function testKeyAndUrlValidation(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', annuaire_ext_key('https://atelier.test'));
        self::assertSame(annuaire_ext_key('https://Atelier.test/'), annuaire_ext_key('https://atelier.test'));
        self::assertTrue(annuaire_ext_valid_url('https://atelier.test/boutique'));
        self::assertFalse(annuaire_ext_valid_url('ftp://atelier.test'));
        self::assertFalse(annuaire_ext_valid_url('javascript:alert(1)'));
    }

    public function testNormalizeRecord(): void
    {
        $r = annuaire_ext_normalize([
            'url' => 'https://atelier.test/',
            'name' => '  Atelier   Test ',
            'universes' => 'Brocante, Décoration; Brocante, Lumière',
            'accent' => '#B5502E',
            'email' => 'pas-un-email',
            'platform' => 'Shopify!',
            'status' => 'inconnu',
            'gallery' => 'x',
        ]);
        self::assertSame(annuaire_ext_key('https://atelier.test'), $r['id']);
        self::assertSame('https://atelier.test', $r['url']);
        self::assertSame('Atelier Test', $r['name']);
        self::assertSame(['Brocante', 'Décoration', 'Lumière'], $r['universes']);
        self::assertSame('#b5502e', $r['accent']);
        self::assertSame('', $r['email']);
        self::assertSame('generic', $r['platform']);
        self::assertSame('pending', $r['status']);
        self::assertSame('', $r['gallery']);

        $list = annuaire_ext_normalize(['url' => 'https://atelier.test', 'universes' => ['Brocante', 'Brocante', ' ']]);
        self::assertSame(['Brocante'], $list['universes']);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $record = annuaire_ext_normalize($this->profile() + ['contact_email' => 'jean@atelier.test']);
        self::assertTrue(annuaire_ext_save($record));
        self::assertFileExists($this->dir . '/' . $record['id'] . '.json');
        $loaded = annuaire_ext_load($record['id']);
        self::assertSame('Atelier Test', $loaded['name']);
        self::assertSame('jean@atelier.test', $loaded['contact_email']);
        self::assertNull(annuaire_ext_load('invalide'));
        self::assertNull(annuaire_ext_load(str_repeat('z', 16)));
    }

    public function testPublicSubmissionNeedsContactEmailAndStartsPending(): void
    {
        $refused = annuaire_ext_submit($this->profile(), []);
        self::assertFalse($refused['ok']);

        $ok = annuaire_ext_submit($this->profile(), ['contact_email' => 'jean@atelier.test']);
        self::assertTrue($ok['ok']);
        self::assertSame('pending', $ok['record']['status']);
        self::assertSame('public', $ok['record']['source']);

        $again = annuaire_ext_submit($this->profile(), ['contact_email' => 'jean@atelier.test']);
        self::assertFalse($again['ok']);
        self::assertStringContainsString('déjà en cours', $again['error']);
    }

    public function testRejectedListingCanBeSubmittedAgain(): void
    {
        $sub = annuaire_ext_submit($this->profile(), ['contact_email' => 'jean@atelier.test']);
        $reviewed = annuaire_ext_review($sub['record']['id'], 'rejected', 'Adresse incomplète');
        self::assertSame('rejected', $reviewed['status']);
        self::assertSame('Adresse incomplète', $reviewed['note']);
        self::assertNull(annuaire_ext_review($sub['record']['id'], 'peut-être'));

        $again = annuaire_ext_submit($this->profile('https://atelier.test/', 'Atelier corrigé'), ['contact_email' => 'jean@atelier.test']);
        self::assertTrue($again['ok']);
        self::assertSame('Atelier corrigé', $again['record']['name']);
        self::assertSame('pending', $again['record']['status']);
    }

    public function testPortalSubmissionIsPublishedDirectly(): void
    {
        $ok = annuaire_ext_submit($this->profile(), [], 'portal');
        self::assertTrue($ok['ok']);
        self::assertSame('approved', $ok['record']['status']);
        self::assertSame('portal', $ok['record']['source']);
        self::assertGreaterThan(0, $ok['record']['reviewed']);

        $dupe = annuaire_ext_submit($this->profile(), [], 'portal');
        self::assertFalse($dupe['ok']);
        self::assertStringContainsString('déjà dans l’annuaire', $dupe['error']);
    }

    public function testListPendingAndDirectoryEntry(): void
    {
        $pending = annuaire_ext_submit($this->profile(), ['contact_email' => 'jean@atelier.test'])['record'];
        $approved = annuaire_ext_submit($this->profile('https://autre.test', 'Autre'), [], 'portal')['record'];
        self::assertSame(1, annuaire_ext_pending_count());
        self::assertSame([$pending['id']], array_column(annuaire_ext_list('pending'), 'id'));
        self::assertSame([$approved['id']], array_column(annuaire_ext_list('approved'), 'id'));

        $entries = annuaire_ext_directory_entries();
        self::assertCount(1, $entries);
        $e = $entries[0];
        self::assertSame('x' . $approved['id'], $e['key']);
        self::assertSame('Autre', $e['name']);
        self::assertSame('https://autre.test', $e['url']);
        self::assertTrue($e['external']);
        self::assertSame('shopify', $e['platform']);
        self::assertSame(0, $e['count']);
    }

    public function testEntryFallsBackToHostWhenNameIsEmpty(): void
    {
        $record = annuaire_ext_normalize(['url' => 'https://sans-nom.test', 'name' => '', 'status' => 'approved']);
        self::assertTrue(annuaire_ext_save($record));
        $entries = annuaire_ext_directory_entries();
        self::assertSame('sans-nom.test', $entries[0]['name']);
    }

    public function testRateLimit(): void
    {
        self::assertTrue(annuaire_ext_rate_limit('203.0.113.5', 2));
        self::assertTrue(annuaire_ext_rate_limit('203.0.113.5', 2));
        self::assertFalse(annuaire_ext_rate_limit('203.0.113.5', 2));
        self::assertTrue(annuaire_ext_rate_limit('203.0.113.6', 2));
    }

    public function testPlatformLabels(): void
    {
        self::assertSame('Shopify', annuaire_ext_platform_label('shopify'));
        self::assertSame('WooCommerce', annuaire_ext_platform_label('woocommerce'));
        self::assertSame('PrestaShop', annuaire_ext_platform_label('prestashop'));
        self::assertSame('Site existant', annuaire_ext_platform_label('generic'));
        self::assertSame('Site existant', annuaire_ext_platform_label('inconnu'));
    }
}