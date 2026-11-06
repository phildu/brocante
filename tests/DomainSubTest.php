<?php

use PHPUnit\Framework\TestCase;

// L'état des adresses en sous-domaine est écrit hors du projet pendant les tests.
if (!defined('DOMAIN_SHOP_DATA_DIR')) {
    define('DOMAIN_SHOP_DATA_DIR', sys_get_temp_dir() . '/brocs-domaines-test-' . getmypid());
}

require_once __DIR__ . '/../includes/domain-sub.php';

final class DomainSubTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        @rmdir(domain_sub_dir());
    }

    private function clear(): void
    {
        foreach (glob(domain_sub_dir() . '/*') ?: [] as $file) @unlink($file);
    }

    private function awaiting(string $slug = 'naty', string $label = 'la-friperie', int $amount = 500): void
    {
        domain_sub_update($slug, static fn (array $d): array => [
            'label' => $label,
            'state' => 'awaiting_payment',
            'amount_cents' => $amount,
            'email' => 'naty@example.com',
            'stripe_session' => 'cs_test_sub',
            'stripe_url' => 'https://checkout.stripe.test/x',
            'created_at' => time(),
        ]);
    }

    private function paidSession(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_test_sub',
            'payment_status' => 'paid',
            'livemode' => false,
            'client_reference_id' => 'sousdomaine:naty',
            'amount_total' => 500,
            'currency' => 'eur',
            'metadata' => ['type' => 'shop_subdomain', 'shop' => 'naty', 'label' => 'la-friperie'],
        ];
    }

    public function testLabelNormalizationCleansUserInput(): void
    {
        $this->assertSame('la-friperie-de-naty', domain_sub_label_normalize('  La Friperie de Naty! '));
        $this->assertSame('ete-a-noel', domain_sub_label_normalize('Été à Noël'));
        $this->assertSame('boutique', domain_sub_label_normalize('https://boutique.bricbrok.com/chemin', 'www.bricbrok.com'));
        $this->assertSame('boutique', domain_sub_label_normalize('boutique.bricbrok.com', 'bricbrok.com'));
        $this->assertSame('', domain_sub_label_normalize('***'));
    }

    public function testLabelProblems(): void
    {
        $this->assertSame('', domain_sub_label_problem('la-friperie'));
        $this->assertNotSame('', domain_sub_label_problem(''));
        $this->assertNotSame('', domain_sub_label_problem('ab'));
        $this->assertNotSame('', domain_sub_label_problem(str_repeat('a', 41)));
        $this->assertNotSame('', domain_sub_label_problem('-debut'));
        $this->assertNotSame('', domain_sub_label_problem('fin-'));
        $this->assertNotSame('', domain_sub_label_problem('xn--punycode'));
        $this->assertNotSame('', domain_sub_label_problem('www'));
        $this->assertNotSame('', domain_sub_label_problem('portail'));
    }

    public function testUrlUsesPlatformDomainWithoutWww(): void
    {
        $this->assertSame('https://naty.bricbrok.com', domain_sub_url('naty', 'www.bricbrok.com'));
        $this->assertSame('http://naty.brocante.test', domain_sub_url('naty', 'brocante.test'));
        $this->assertSame('bricbrok.com', domain_sub_base_host('www.bricbrok.com'));
    }

    public function testConflictsWithRootFoldersAndOtherShops(): void
    {
        $this->assertNotSame('', domain_sub_conflict('admin', 'naty'));
        $this->assertNotSame('', domain_sub_conflict('uploads', 'naty'));
        $this->assertNotSame('', domain_sub_conflict('petit-chalet', 'naty'), 'Le nom d’une autre boutique est refusé.');
        $this->assertSame('', domain_sub_conflict('nouvelle-adresse', 'naty'));

        $this->awaiting('autre', 'la-friperie');
        $this->assertNotSame('', domain_sub_conflict('la-friperie', 'naty'), 'Un nom en cours de paiement ailleurs est réservé.');
        $this->assertSame('', domain_sub_conflict('la-friperie', 'autre'), 'La boutique concernée garde son propre nom.');
    }

    public function testExpiredPendingPaymentFreesTheLabel(): void
    {
        domain_sub_update('autre', static fn (array $d): array => [
            'label' => 'la-friperie', 'state' => 'awaiting_payment', 'created_at' => time() - 2 * 86400,
        ]);
        $this->assertSame('', domain_sub_conflict('la-friperie', 'naty'));
    }

    public function testPaidSessionActivatesOnceAndResolvesHost(): void
    {
        $this->awaiting();
        $this->assertSame([], domain_sub_hosts(), 'Un paiement en attente ne sert pas encore l’adresse.');

        $result = domain_sub_apply_session($this->paidSession());
        $this->assertSame('activated', $result['state']);
        $this->assertSame('active', domain_sub_load('naty')['state']);
        $this->assertSame(['la-friperie' => 'naty'], domain_sub_hosts());
        $this->assertSame('la-friperie', domain_sub_active_label('naty'));

        $again = domain_sub_apply_session($this->paidSession());
        $this->assertSame('already', $again['state']);
    }

    public function testSessionIsRejectedWhenItDoesNotMatch(): void
    {
        $this->awaiting();
        foreach ([
            ['id' => 'cs_autre'],
            ['amount_total' => 100],
            ['client_reference_id' => 'sousdomaine:autre'],
            ['metadata' => ['type' => 'shop_subdomain', 'shop' => 'naty', 'label' => 'un-autre-nom']],
            ['currency' => 'usd'],
        ] as $override) {
            $result = domain_sub_apply_session($this->paidSession($override));
            $this->assertSame('invalid', $result['state'], json_encode($override));
        }
        $this->assertSame('awaiting_payment', domain_sub_load('naty')['state']);
    }

    public function testUnpaidSessionDoesNotActivate(): void
    {
        $this->awaiting();
        $result = domain_sub_apply_session($this->paidSession(['payment_status' => 'unpaid']));
        $this->assertSame('unpaid', $result['state']);
        $this->assertSame('awaiting_payment', domain_sub_load('naty')['state']);
    }

    public function testForeignSessionTypeIsInvalid(): void
    {
        $this->assertSame('invalid', domain_sub_apply_session(['metadata' => ['type' => 'shop_domain_purchase', 'shop' => 'naty']])['state']);
    }

    public function testCancelRemovesPendingButNeverAnActiveAddress(): void
    {
        $this->awaiting();
        domain_sub_cancel('naty');
        $this->assertSame([], domain_sub_load('naty'));

        $this->awaiting();
        domain_sub_apply_session($this->paidSession());
        $this->expectException(RuntimeException::class);
        domain_sub_cancel('naty');
    }

    public function testRemoveDetachesActiveAddress(): void
    {
        $this->awaiting();
        domain_sub_apply_session($this->paidSession());
        domain_sub_remove('naty');
        $this->assertSame([], domain_sub_hosts());
    }

    public function testFreeAddressIsActivatedWithoutStripe(): void
    {
        // saas_config() n'est pas chargé ici : le prix par défaut s'applique, on le force à zéro via l'activation directe.
        domain_sub_update('naty', static fn (array $d): array => ['label' => 'offerte', 'state' => 'awaiting_payment', 'amount_cents' => 0, 'created_at' => time()]);
        $data = domain_sub_activate('naty', ['free' => true]);
        $this->assertSame('active', $data['state']);
        $this->assertTrue($data['free']);
        $this->assertSame(['offerte' => 'naty'], domain_sub_hosts());
    }

    private function activeWithPendingRename(string $to = 'nouveau-nom', int $amount = 200): void
    {
        $this->awaiting();
        domain_sub_apply_session($this->paidSession());
        domain_sub_update('naty', static function (array $d) use ($to, $amount): array {
            $d['rename'] = [
                'label' => $to, 'amount_cents' => $amount, 'stripe_session' => 'cs_test_rename',
                'stripe_url' => 'https://checkout.stripe.test/r', 'created_at' => time(),
            ];
            return $d;
        });
    }

    private function renameSession(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_test_rename',
            'payment_status' => 'paid',
            'livemode' => false,
            'client_reference_id' => 'sousdomaine:naty',
            'amount_total' => 200,
            'currency' => 'eur',
            'metadata' => ['type' => 'shop_subdomain', 'shop' => 'naty', 'label' => 'nouveau-nom', 'rename' => '1'],
        ];
    }

    public function testPaidRenameSwapsLabelOnceAndFreesTheOldName(): void
    {
        $this->activeWithPendingRename();
        $this->assertSame(['la-friperie' => 'naty'], domain_sub_hosts(), 'L’ancien nom reste servi tant que le changement n’est pas payé.');

        $result = domain_sub_apply_session($this->renameSession());
        $this->assertSame('renamed', $result['state']);
        $this->assertSame(['nouveau-nom' => 'naty'], domain_sub_hosts());
        $record = domain_sub_load('naty');
        $this->assertArrayNotHasKey('rename', $record);
        $this->assertSame('la-friperie', $record['renames'][0]['from']);
        $this->assertSame('nouveau-nom', $record['renames'][0]['to']);
        $this->assertSame('', domain_sub_conflict('la-friperie', 'autre'), 'L’ancien nom est de nouveau libre.');

        $again = domain_sub_apply_session($this->renameSession());
        $this->assertSame('already', $again['state']);
        $this->assertCount(1, domain_sub_load('naty')['renames']);
    }

    public function testRenameSessionIsRejectedWhenItDoesNotMatch(): void
    {
        foreach ([
            ['id' => 'cs_autre'],
            ['amount_total' => 1],
            ['client_reference_id' => 'sousdomaine:autre'],
            ['metadata' => ['type' => 'shop_subdomain', 'shop' => 'naty', 'label' => 'autre-nom', 'rename' => '1']],
        ] as $override) {
            $this->clear();
            $this->activeWithPendingRename();
            $result = domain_sub_apply_session($this->renameSession($override));
            $this->assertSame('invalid', $result['state'], json_encode($override));
            $this->assertSame(['la-friperie' => 'naty'], domain_sub_hosts());
        }
    }

    public function testUnpaidRenameDoesNothing(): void
    {
        $this->activeWithPendingRename();
        $this->assertSame('unpaid', domain_sub_apply_session($this->renameSession(['payment_status' => 'unpaid']))['state']);
        $this->assertSame(['la-friperie' => 'naty'], domain_sub_hosts());
    }

    public function testPendingRenameReservesTheNewNameForOthers(): void
    {
        $this->activeWithPendingRename();
        $this->assertNotSame('', domain_sub_conflict('nouveau-nom', 'autre'));
        $this->assertSame('', domain_sub_conflict('nouveau-nom', 'naty'));
        $this->assertSame('nouveau-nom', domain_sub_pending_rename('naty')['label']);
    }

    public function testCancelRenameKeepsTheActiveAddress(): void
    {
        $this->activeWithPendingRename();
        domain_sub_cancel('naty');
        $this->assertSame([], domain_sub_pending_rename('naty'));
        $this->assertSame(['la-friperie' => 'naty'], domain_sub_hosts());
        $this->expectException(RuntimeException::class);
        domain_sub_cancel('naty');
    }

    public function testRenameRequiresAnActiveAddressAndADifferentName(): void
    {
        try {
            domain_sub_rename('naty', 'nouveau-nom', 'a@example.com', 'https://bricbrok.com/admin/domaine.php');
            $this->fail('Sans adresse en service, le renommage doit être refusé.');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('Aucune adresse en service', $ex->getMessage());
        }
        $this->awaiting();
        domain_sub_apply_session($this->paidSession());
        $this->expectException(InvalidArgumentException::class);
        domain_sub_rename('naty', 'La Friperie', 'a@example.com', 'https://bricbrok.com/admin/domaine.php');
    }

    public function testFreeRenameSwapsImmediately(): void
    {
        $this->awaiting();
        domain_sub_apply_session($this->paidSession());
        domain_sub_swap_label('naty', 'gratuit', 0);
        $this->assertSame(['gratuit' => 'naty'], domain_sub_hosts());
        $this->assertSame(0, domain_sub_load('naty')['renames'][0]['amount_cents']);
    }

    public function testCheckoutRefusesAnInvalidOrTakenName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        domain_sub_checkout('naty', 'admin', 'naty@example.com', 'https://bricbrok.com/admin/domaine.php');
    }
}
