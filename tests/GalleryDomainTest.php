<?php

use PHPUnit\Framework\TestCase;

// L'état des adresses est écrit hors du projet pendant les tests (la constante est définie par DomainShopTest quand toute la suite tourne).
if (!defined('DOMAIN_SHOP_DATA_DIR')) {
    define('DOMAIN_SHOP_DATA_DIR', sys_get_temp_dir() . '/brocs-domaines-test-' . getmypid());
}

require_once __DIR__ . '/../includes/galleries.php';
require_once __DIR__ . '/../includes/domain-sub.php';
require_once __DIR__ . '/../includes/domain-custom.php';
require_once __DIR__ . '/../includes/domain-shop.php';

/**
 * Adresse d'une galerie (sous-domaine, domaine rattaché) : même parcours que pour une boutique,
 * mais l'adresse en service est recopiée dans le champ « domain » de la galerie.
 */
final class GalleryDomainTest extends TestCase
{
    private string $galleriesDir;
    private ?string $previousGalleriesDir;
    private ?string $previousHost;

    protected function setUp(): void
    {
        $this->galleriesDir = sys_get_temp_dir() . '/brocs-galeries-test-' . getmypid();
        @mkdir($this->galleriesDir, 0777, true);
        $this->previousGalleriesDir = getenv('BROCANTE_GALLERIES_DIR') ?: null;
        putenv('BROCANTE_GALLERIES_DIR=' . $this->galleriesDir);
        $this->previousHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'bricbrok.com';
        $this->clear();
        $this->gallery('vide-grenier');
    }

    protected function tearDown(): void
    {
        $this->clear();
        foreach (glob($this->galleriesDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->galleriesDir);
        putenv($this->previousGalleriesDir === null ? 'BROCANTE_GALLERIES_DIR' : 'BROCANTE_GALLERIES_DIR=' . $this->previousGalleriesDir);
        if ($this->previousHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $this->previousHost;
    }

    private function clear(): void
    {
        foreach ([domain_sub_dir(), domain_custom_dir()] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
        }
        foreach (glob(domain_shop_dir() . '/*.json') ?: [] as $file) @unlink($file);
    }

    private function gallery(string $slug, array $extra = []): void
    {
        $this->assertTrue(gallery_save($extra + [
            'slug' => $slug,
            'name' => 'Galerie ' . $slug,
            'admins' => [['email' => 'orga@example.com', 'name' => 'Orga', 'hash' => 'x']],
        ]));
    }

    private function awaiting(string $key, string $label, int $amount = 500, string $session = 'cs_test_gal'): void
    {
        domain_sub_update($key, static fn (array $d): array => [
            'label' => $label, 'state' => 'awaiting_payment', 'amount_cents' => $amount, 'email' => 'orga@example.com',
            'stripe_session' => $session, 'stripe_url' => 'https://checkout.stripe.test/g', 'created_at' => time(),
        ]);
    }

    private function paidSession(string $key, string $label, int $amount = 500, string $session = 'cs_test_gal', array $meta = []): array
    {
        return [
            'id' => $session, 'payment_status' => 'paid', 'livemode' => false, 'client_reference_id' => 'sousdomaine:' . $key,
            'amount_total' => $amount, 'currency' => 'eur',
            'metadata' => $meta + ['type' => 'shop_subdomain', 'shop' => $key, 'label' => $label],
        ];
    }

    private function readyCustom(string $key, string $domain): void
    {
        domain_custom_update($key, static fn (array $d): array => [
            'domain' => $domain, 'state' => 'ready', 'token' => 'abc', 'created_at' => time(),
        ]);
    }

    public function testOwnerKeys(): void
    {
        $this->assertSame('g~vide-grenier', domain_owner_gallery_key('vide-grenier'));
        $this->assertTrue(domain_owner_is_gallery('g~vide-grenier'));
        $this->assertSame('vide-grenier', domain_owner_gallery_slug('g~vide-grenier'));
        $this->assertTrue(domain_owner_key_valid('g~vide-grenier'));
        $this->assertTrue(domain_owner_key_valid('naty'));
        $this->assertFalse(domain_owner_key_valid('g~'));
        $this->assertFalse(domain_owner_key_valid('g~A'));
        $this->assertFalse(domain_owner_key_valid('g~../x'));
        $this->assertFalse(domain_owner_key_valid('na~ty'), 'Le « ~ » est réservé aux galeries.');
        $this->assertSame('galerie', domain_owner_noun('g~vide-grenier'));
        $this->assertSame('boutique', domain_owner_noun('naty'));
        $this->assertSame('/galerie/vide-grenier/', domain_owner_legacy_path('g~vide-grenier'));
        $this->assertSame('/naty/', domain_owner_legacy_path('naty'));
        $this->assertTrue(domain_owner_exists('g~vide-grenier'));
        $this->assertFalse(domain_owner_exists('g~inconnue'));
        $this->assertSame('orga@example.com', domain_owner_email('g~vide-grenier'));
    }

    public function testReturnUrlKeepsAnExistingQuery(): void
    {
        $this->assertSame('https://x.test/p?s={CHECKOUT_SESSION_ID}', domain_return_url('https://x.test/p'));
        $this->assertSame('https://x.test/p?g=a&s={CHECKOUT_SESSION_ID}', domain_return_url('https://x.test/p?g=a'));
    }

    public function testPaidSubdomainServesTheGalleryAndNotAShop(): void
    {
        $key = domain_owner_gallery_key('vide-grenier');
        $this->awaiting($key, 'vide-grenier-rouen');
        $this->assertSame('', gallery_load('vide-grenier')['domain'], 'Rien n’est servi avant le paiement.');

        $result = domain_sub_apply_session($this->paidSession($key, 'vide-grenier-rouen'));
        $this->assertSame('activated', $result['state']);
        $this->assertSame('vide-grenier-rouen.bricbrok.com', domain_sub_active_host($key));
        $this->assertSame('vide-grenier-rouen.bricbrok.com', gallery_load('vide-grenier')['domain']);
        $this->assertSame('vide-grenier', gallery_from_host('vide-grenier-rouen.bricbrok.com')['slug']);
        $this->assertSame([], domain_sub_hosts(), 'La résolution des boutiques ignore les galeries.');
        $this->assertSame('https://vide-grenier-rouen.bricbrok.com/', gallery_public_url(gallery_load('vide-grenier')));

        $this->assertSame('already', domain_sub_apply_session($this->paidSession($key, 'vide-grenier-rouen'))['state']);
    }

    public function testRenameMovesTheGalleryToTheNewAddress(): void
    {
        $key = domain_owner_gallery_key('vide-grenier');
        $this->awaiting($key, 'ancien-nom');
        domain_sub_apply_session($this->paidSession($key, 'ancien-nom'));

        domain_sub_update($key, static function (array $d): array {
            $d['rename'] = ['label' => 'nouveau-nom', 'amount_cents' => 200, 'stripe_session' => 'cs_test_ren', 'stripe_url' => 'https://checkout.stripe.test/r', 'created_at' => time()];
            return $d;
        });
        $result = domain_sub_apply_session($this->paidSession($key, 'nouveau-nom', 200, 'cs_test_ren', ['rename' => '1']));
        $this->assertSame('renamed', $result['state']);
        $this->assertSame('nouveau-nom.bricbrok.com', gallery_load('vide-grenier')['domain']);
        $this->assertNull(gallery_from_host('ancien-nom.bricbrok.com'));
        $this->assertSame('vide-grenier', gallery_from_host('nouveau-nom.bricbrok.com')['slug']);
    }

    public function testRemovingTheAddressClearsOnlyWhatWasPublishedByIt(): void
    {
        $key = domain_owner_gallery_key('vide-grenier');
        $this->awaiting($key, 'vide-grenier-rouen');
        domain_sub_apply_session($this->paidSession($key, 'vide-grenier-rouen'));
        domain_sub_remove($key);
        $this->assertSame('', gallery_load('vide-grenier')['domain']);

        // Un domaine saisi à la main par l'exploitant n'est pas touché.
        $this->gallery('vide-grenier', ['domain' => 'saisi-a-la-main.fr']);
        $this->awaiting($key, 'autre-nom', 500, 'cs_test_2');
        domain_sub_apply_session($this->paidSession($key, 'autre-nom', 500, 'cs_test_2'));
        $this->assertSame('autre-nom.bricbrok.com', gallery_load('vide-grenier')['domain'], 'Le sous-domaine en service remplace la saisie manuelle.');
        $this->gallery('vide-grenier', ['domain' => 'saisi-a-la-main.fr']);
        domain_sub_remove($key);
        $this->assertSame('saisi-a-la-main.fr', gallery_load('vide-grenier')['domain']);
    }

    public function testAttachedDomainWinsOverTheSubdomainAndFallsBack(): void
    {
        $key = domain_owner_gallery_key('vide-grenier');
        $this->awaiting($key, 'vide-grenier-rouen');
        domain_sub_apply_session($this->paidSession($key, 'vide-grenier-rouen'));

        $this->readyCustom($key, 'ma-galerie.fr');
        $this->assertSame('vide-grenier-rouen.bricbrok.com', gallery_load('vide-grenier')['domain'], 'Une demande non activée ne sert rien.');
        $record = domain_custom_activate($key);
        $this->assertSame('active', $record['state']);
        $this->assertSame('ma-galerie.fr', gallery_load('vide-grenier')['domain']);
        $this->assertSame('vide-grenier', gallery_from_host('www.ma-galerie.fr')['slug']);
        $this->assertSame([], domain_custom_hosts(), 'La résolution des boutiques ignore les galeries.');

        domain_custom_remove($key);
        $this->assertSame('vide-grenier-rouen.bricbrok.com', gallery_load('vide-grenier')['domain'], 'Le sous-domaine reprend la main.');
    }

    public function testNamesAreSharedBetweenShopsAndGalleries(): void
    {
        $gallery = domain_owner_gallery_key('vide-grenier');
        $this->awaiting($gallery, 'le-nom');
        $this->assertNotSame('', domain_sub_conflict('le-nom', 'naty'), 'Une boutique ne prend pas le nom d’une galerie.');
        $this->assertSame('', domain_sub_conflict('le-nom', $gallery), 'La galerie garde son propre nom.');

        $this->awaiting('naty', 'nom-boutique', 500, 'cs_test_shop');
        $this->gallery('autre-galerie');
        $this->assertNotSame('', domain_sub_conflict('nom-boutique', domain_owner_gallery_key('autre-galerie')), 'Une galerie ne prend pas le nom d’une boutique.');
        $this->assertNotSame('', domain_sub_conflict('admin', $gallery), 'Les dossiers de la plateforme restent réservés.');
    }

    public function testManualGalleryDomainBlocksOtherOwners(): void
    {
        $this->gallery('vide-grenier', ['domain' => 'ma-galerie.fr']);
        $this->assertNotSame('', domain_custom_conflict('ma-galerie.fr', 'naty'));
        $this->assertSame('', domain_custom_conflict('ma-galerie.fr', domain_owner_gallery_key('vide-grenier')));
        $this->gallery('autre-galerie', ['domain' => 'pris.bricbrok.com']);
        $this->assertNotSame('', domain_sub_conflict('pris', 'naty'));
    }

    public function testGalleryPurchaseSessionIsAcceptedForTheGalleryKey(): void
    {
        $key = domain_owner_gallery_key('vide-grenier');
        $this->assertTrue(domain_shop_slug_valid($key));
        $session = [
            'id' => 'cs_x', 'payment_status' => 'unpaid',
            'metadata' => ['type' => 'shop_domain_purchase', 'shop' => $key, 'domain' => 'ma-galerie.fr'],
        ];
        $this->assertSame('unpaid', domain_shop_apply_session($session)['state']);
        $this->assertSame('invalid', domain_shop_apply_session(['metadata' => ['type' => 'shop_domain_purchase', 'shop' => 'g~']])['state']);
    }
}
