<?php

use PHPUnit\Framework\TestCase;

// L'état des domaines de boutique est écrit hors du projet pendant les tests :
// aucun fichier réel de data/domaines n'est touché.
define('DOMAIN_SHOP_DATA_DIR', sys_get_temp_dir() . '/brocs-domaines-test-' . getmypid());

require_once __DIR__ . '/../includes/domain-shop.php';

final class DomainShopTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        @rmdir(DOMAIN_SHOP_DATA_DIR);
    }

    private function clear(): void
    {
        foreach (glob(DOMAIN_SHOP_DATA_DIR . '/*') ?: [] as $file) @unlink($file);
    }

    private function owner(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'organization' => '',
            'legal_form' => 'individual',
            'address' => '1 rue des Fleurs',
            'postal_code' => '75001',
            'city' => 'Paris',
            'country' => 'FR',
            'phone' => '06 12 34 56 78',
            'email' => 'marie@example.com',
        ];
    }

    public function testStateLabelsAreFrench(): void
    {
        $this->assertSame('Devis prêt', domain_shop_state_label('quoted'));
        $this->assertSame('En attente de paiement', domain_shop_state_label('awaiting_payment'));
        $this->assertSame('Commande en cours', domain_shop_state_label('ordering'));
        $this->assertSame('Domaine commandé', domain_shop_state_label('ordered'));
        $this->assertSame('Paiement de test (aucune commande OVH)', domain_shop_state_label('test_paid'));
        $this->assertSame('Vérification en cours', domain_shop_state_label('paid_needs_review'));
        $this->assertSame('État inconnu', domain_shop_state_label('nawak'));
    }

    public function testSlugValidation(): void
    {
        $this->assertTrue(domain_shop_slug_valid('brocante-du-chalet'));
        $this->assertTrue(domain_shop_slug_valid('shop_01'));
        $this->assertFalse(domain_shop_slug_valid('../etc'));
        $this->assertFalse(domain_shop_slug_valid('Brocante'));
        $this->assertFalse(domain_shop_slug_valid('-debut'));
        $this->assertFalse(domain_shop_slug_valid(''));
        $this->assertFalse(domain_shop_slug_valid(str_repeat('a', 62)));
    }

    public function testOwnerFromPostNormalizesInput(): void
    {
        $owner = domain_shop_owner_from_post([
            'owner_first_name' => '  Marie ',
            'owner_last_name' => 'Dupont',
            'owner_country' => 'fr',
            'owner_email' => ' Marie@Example.COM ',
            'owner_phone' => '06 12 34 56 78',
        ]);
        $this->assertSame('Marie', $owner['first_name']);
        $this->assertSame('FR', $owner['country']);
        $this->assertSame('marie@example.com', $owner['email']);
        $this->assertSame('individual', $owner['legal_form']);
    }

    public function testOwnerValidateReturnsOvhPhone(): void
    {
        $owner = domain_shop_owner_validate($this->owner());
        $this->assertSame('+33612345678', $owner['phone']);
    }

    public function testOwnerValidateRejectsMissingFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indiquez la ville');
        domain_shop_owner_validate($this->owner(['city' => '  ']));
    }

    public function testOwnerValidateRejectsInvalidEmailAndCountry(): void
    {
        try {
            domain_shop_owner_validate($this->owner(['email' => 'pas-un-email']));
            $this->fail('Un e-mail invalide doit être refusé.');
        } catch (InvalidArgumentException $ex) {
            $this->assertStringContainsString('e-mail', $ex->getMessage());
        }
        try {
            domain_shop_owner_validate($this->owner(['country' => 'France']));
            $this->fail('Un pays non ISO doit être refusé.');
        } catch (InvalidArgumentException $ex) {
            $this->assertStringContainsString('ISO', $ex->getMessage());
        }
    }

    public function testOwnerValidateRequiresOrganizationForCompanies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entreprise');
        domain_shop_owner_validate($this->owner(['legal_form' => 'corporation', 'organization' => '']));
    }

    public function testOwnerValidateAcceptsCompanyWithOrganization(): void
    {
        $owner = domain_shop_owner_validate($this->owner(['legal_form' => 'corporation', 'organization' => 'Brocs SAS']));
        $this->assertSame('Brocs SAS', $owner['organization']);
    }

    public function testOwnerValidateRejectsUnknownLegalForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        domain_shop_owner_validate($this->owner(['legal_form' => 'sarl-secrete']));
    }

    public function testLoadReturnsEmptyStateWithoutFile(): void
    {
        $this->assertSame(['search' => null, 'purchase' => null], domain_shop_load('inconnue'));
    }

    public function testUpdateWritesAtomicallyAndRoundTrips(): void
    {
        $data = domain_shop_update('brocante-test', static function (array $d): array {
            $d['purchase'] = ['state' => 'quoted', 'quote' => ['domain' => 'brocante.fr']];
            return $d;
        });
        $this->assertSame('quoted', $data['purchase']['state']);

        $file = DOMAIN_SHOP_DATA_DIR . '/brocante-test.json';
        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame('brocante.fr', domain_shop_load('brocante-test')['purchase']['quote']['domain']);
        $this->assertSame([], glob(DOMAIN_SHOP_DATA_DIR . '/.domaine-*') ?: []);
    }

    public function testUpdateLeavesStateUntouchedWhenCallbackFails(): void
    {
        domain_shop_update('brocante-test', static fn (array $d): array => ['purchase' => ['state' => 'ordered']] + $d);
        try {
            domain_shop_update('brocante-test', static function (array $d): array {
                throw new RuntimeException('refus');
            });
            $this->fail('L’échec du callback doit remonter.');
        } catch (RuntimeException $ex) {
            $this->assertSame('refus', $ex->getMessage());
        }
        $this->assertSame('ordered', domain_shop_load('brocante-test')['purchase']['state']);
    }

    /** État d'une commande en attente de paiement, prête à recevoir une session Stripe. */
    private function awaitingPayment(): void
    {
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => [
            'state' => 'awaiting_payment',
            'quote' => ['domain' => 'brocs.fr', 'amount_cents' => 1200, 'ovh_cents' => 1000, 'cart_id' => '42'],
            'owner' => ['email' => 'titulaire@example.com'],
            'stripe_session' => 'cs_test_demo',
            'created_at' => time(),
        ]] + $d);
    }

    private function paidSession(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_test_demo',
            'payment_status' => 'paid',
            'livemode' => false,
            'client_reference_id' => 'domaine:plateforme',
            'amount_total' => 1200,
            'currency' => 'eur',
            'metadata' => ['type' => 'shop_domain_purchase', 'shop' => 'plateforme', 'domain' => 'brocs.fr', 'cart_id' => '42'],
        ];
    }

    public function testTestModePaymentIsRecordedWithoutOvhOrder(): void
    {
        $this->awaitingPayment();
        $result = domain_shop_apply_session($this->paidSession());
        $this->assertSame('test', $result['state']);
        $this->assertSame('test_paid', domain_shop_load('plateforme')['purchase']['state']);

        $again = domain_shop_apply_session($this->paidSession());
        $this->assertSame('already', $again['state']);
        $this->assertSame('test_paid', $again['purchase']['state']);
    }

    public function testArchiveMovesFinishedPurchaseToHistory(): void
    {
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => [
            'state' => 'ordered',
            'quote' => ['domain' => 'brocs.fr', 'amount_cents' => 1200],
            'stripe_session' => 'cs_live_demo',
            'ovh_order_id' => '9911',
            'ordered_at' => time(),
        ]] + $d);
        domain_shop_archive_purchase('plateforme');
        $data = domain_shop_load('plateforme');
        $this->assertNull($data['purchase']);
        $this->assertSame('brocs.fr', $data['history'][0]['quote']['domain']);
        $this->assertSame('9911', $data['history'][0]['ovh_order_id']);
        $this->assertNotEmpty($data['history'][0]['archived_at']);
    }

    public function testArchiveRefusesUnpaidPurchase(): void
    {
        $this->awaitingPayment();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('confirmée par OVH');
        domain_shop_archive_purchase('plateforme');
    }

    public function testArchivedSessionIsAlreadyApplied(): void
    {
        domain_shop_update('plateforme', static fn (array $d): array => [
            'history' => [[
                'state' => 'ordered',
                'quote' => ['domain' => 'brocs.fr'],
                'stripe_session' => 'cs_test_demo',
                'archived_at' => time(),
            ]],
        ] + $d);
        $result = domain_shop_apply_session($this->paidSession());
        $this->assertSame('already', $result['state']);
        $this->assertSame('ordered', $result['purchase']['state']);
        $this->assertNull(domain_shop_load('plateforme')['purchase'], 'La commande archivée ne doit pas revenir dans l’état courant.');
    }

    private function orderedState(int $orderedAt, string $domain = 'brocs.fr'): array
    {
        return [
            'state' => 'ordered',
            'quote' => ['domain' => $domain, 'amount_cents' => 1099],
            'ovh_order_id' => '9911',
            'owner' => ['email' => 'marie@example.com'],
            'ordered_at' => $orderedAt,
        ];
    }

    public function testRenewalIsDueSixtyDaysBeforeTheAnniversary(): void
    {
        $now = time();
        $purchase = $this->orderedState($now - 310 * 86400);
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => $purchase] + $d);
        $due = domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now);
        $this->assertCount(1, $due);
        $this->assertSame('brocs.fr', $due[0]['domain']);
        $this->assertSame('plateforme', $due[0]['slug']);
        $this->assertSame('9911', $due[0]['order_id']);
        $this->assertSame('marie@example.com', $due[0]['owner_email']);
    }

    public function testRenewalWaitsForTheWindow(): void
    {
        $now = time();
        $purchase = $this->orderedState($now - 100 * 86400);
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => $purchase] + $d);
        $this->assertSame([], domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now));
    }

    public function testRenewalCoversArchivedPurchases(): void
    {
        $now = time();
        $history = [$this->orderedState($now - 400 * 86400, 'brocs.net')];
        domain_shop_update('plateforme', static fn (array $d): array => ['history' => $history] + $d);
        $due = domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now);
        $this->assertCount(1, $due);
        $this->assertSame('brocs.net', $due[0]['domain']);
    }

    public function testRenewalIsAcknowledgedOnce(): void
    {
        $now = time();
        $purchase = $this->orderedState($now - 320 * 86400);
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => $purchase] + $d);
        domain_shop_renewals_ack(domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now));
        $this->assertSame([], domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now));
    }

    public function testRenewalFollowsTheNextAnniversaryAfterAcknowledgingTheFirst(): void
    {
        $now = time();
        $purchase = $this->orderedState($now - 310 * 86400);
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => $purchase] + $d);
        domain_shop_renewals_ack(domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now));
        $this->assertSame([], domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now));
        $next = domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now + 365 * 86400);
        $this->assertCount(1, $next);
        $this->assertGreaterThan($now + 300 * 86400, $next[0]['renews_at']);
    }

    public function testOldPurchasePointsToTheUpcomingAnniversary(): void
    {
        $now = time();
        $purchase = $this->orderedState($now - 1050 * 86400);
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => $purchase] + $d);
        $due = domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now);
        $this->assertCount(1, $due);
        $this->assertGreaterThan($now, $due[0]['renews_at']);
        $this->assertLessThanOrEqual($now + DOMAIN_SHOP_RENEWAL_WINDOW, $due[0]['renews_at']);
    }

    public function testDryRunReminderMarksNothing(): void
    {
        $now = time();
        $purchase = $this->orderedState($now - 310 * 86400);
        domain_shop_update('plateforme', static fn (array $d): array => ['purchase' => $purchase] + $d);
        $this->assertSame(1, domain_shop_renewal_remind(true, $now));
        $this->assertCount(1, domain_shop_renewals_due(DOMAIN_SHOP_RENEWAL_WINDOW, $now));
    }

    public function testCorruptFileIsReadAsEmptyState(): void
    {
        $previous = ini_set('error_log', sys_get_temp_dir() . '/brocs-domaines-test.log');
        try {
            @mkdir(DOMAIN_SHOP_DATA_DIR, 0700, true);
            file_put_contents(DOMAIN_SHOP_DATA_DIR . '/brocante-test.json', '{cassé');
            $this->assertSame(['search' => null, 'purchase' => null], domain_shop_load('brocante-test'));
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink(sys_get_temp_dir() . '/brocs-domaines-test.log');
        }
    }
}
