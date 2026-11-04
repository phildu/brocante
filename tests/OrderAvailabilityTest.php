<?php

use PHPUnit\Framework\TestCase;

/**
 * Validation de la disponibilité avant paiement : jetons signés, dates
 * d'expiration et textes lus par le vendeur. Aucune base, aucun réseau.
 */
final class OrderAvailabilityTest extends TestCase
{
    private const SECRET = 'cle-de-test-0123456789';

    public function testSignatureIsStableAndRoleBound(): void
    {
        $exp = 1_900_000_000;
        $seller = oa_sign(self::SECRET, 42, 'seller', $exp);
        self::assertSame($seller, oa_sign(self::SECRET, 42, 'seller', $exp));
        self::assertSame(64, strlen($seller));
        self::assertNotSame($seller, oa_sign(self::SECRET, 42, 'buyer', $exp));
        self::assertNotSame($seller, oa_sign(self::SECRET, 43, 'seller', $exp));
        self::assertNotSame($seller, oa_sign('autre-secret', 42, 'seller', $exp));
        self::assertNotSame($seller, oa_sign(self::SECRET, 42, 'seller', $exp + 1));
    }

    public function testSignatureValidityDependsOnRoleAndExpiry(): void
    {
        $now = 1_800_000_000;
        $exp = $now + 600;
        $token = oa_sign(self::SECRET, 7, 'buyer', $exp);

        self::assertTrue(oa_sign_valid(self::SECRET, 7, 'buyer', $token, $exp, $now));
        self::assertFalse(oa_sign_valid(self::SECRET, 7, 'buyer', $token, $exp, $exp));
        self::assertFalse(oa_sign_valid(self::SECRET, 7, 'buyer', $token, $exp, $exp + 1));
        self::assertFalse(oa_sign_valid(self::SECRET, 8, 'buyer', $token, $exp, $now));
        self::assertFalse(oa_sign_valid(self::SECRET, 7, 'seller', $token, $exp, $now));
        self::assertFalse(oa_sign_valid('', 7, 'buyer', $token, $exp, $now));
        self::assertFalse(oa_sign_valid(self::SECRET, 7, 'buyer', str_repeat('0', 64), $exp, $now));
    }

    public function testExpiryWindowsFollowTheOrderDates(): void
    {
        $created = gmdate('c', 1_800_000_000);
        $order = ['created_at' => $created, 'availability_deadline' => ''];
        self::assertSame(1_800_000_000 + OA_REQUEST_DAYS * 86400, oa_expiry($order, 'seller'));
        self::assertSame(1_800_000_000 + (OA_REQUEST_DAYS + OA_BUYER_LINK_DAYS) * 86400, oa_expiry($order, 'buyer'));

        $deadline = gmdate('c', 1_800_100_000);
        self::assertSame(1_800_100_000, oa_expiry(['created_at' => $created, 'availability_deadline' => $deadline], 'pay'));
        // Sans date limite enregistrée : le délai de paiement réglé s'applique à partir de la création.
        $fallback = oa_expiry(['created_at' => $created, 'availability_deadline' => ''], 'pay');
        self::assertGreaterThanOrEqual(1_800_000_000 + 6 * 3600, $fallback);
        self::assertLessThanOrEqual(1_800_000_000 + 168 * 3600, $fallback);
    }

    public function testOrderIsOpenOnlyWhilePendingOrWithinDeadline(): void
    {
        $now = time();
        self::assertTrue(oa_is_open(['status' => 'awaiting', 'availability_status' => 'pending']));
        self::assertTrue(oa_is_open(['status' => 'awaiting', 'availability_status' => 'available', 'availability_deadline' => gmdate('c', $now + 3600)]));
        self::assertFalse(oa_is_open(['status' => 'awaiting', 'availability_status' => 'available', 'availability_deadline' => gmdate('c', $now - 60)]));
        self::assertFalse(oa_is_open(['status' => 'awaiting', 'availability_status' => 'expired']));
        self::assertFalse(oa_is_open(['status' => 'awaiting', 'availability_status' => 'refused']));
        self::assertFalse(oa_is_open(['status' => 'paid', 'availability_status' => 'available', 'availability_deadline' => gmdate('c', $now + 3600)]));
        self::assertFalse(oa_is_open(['status' => 'awaiting']));   // commande historique sans validation
    }

    public function testItemsSummaryAndPayload(): void
    {
        $order = [
            'items' => json_encode([
                ['ref' => 'A1', 'name' => 'Vase bleu', 'qty' => 1, 'unit_cents' => 1200],
                ['ref' => 'B2', 'name' => 'Lampe', 'qty' => 2, 'unit_cents' => 500],
            ], JSON_UNESCAPED_UNICODE),
            'availability_payload' => json_encode(['cart' => ['A1' => 1]], JSON_UNESCAPED_UNICODE),
        ];
        self::assertSame('1 × Vase bleu, 2 × Lampe', oa_items_summary($order));
        self::assertSame(['cart' => ['A1' => 1]], oa_payload($order));
        self::assertSame([], oa_payload(['availability_payload' => 'pas du json']));
    }

    public function testStatusAndChannelLabels(): void
    {
        self::assertSame('En attente de votre validation', oa_status_label('pending'));
        self::assertSame('Pièce vendue — demande refusée', oa_status_label('refused'));
        self::assertSame('inattendu', oa_status_label('inattendu'));
        self::assertSame('WhatsApp', oa_channel_labels()['whatsapp']);
        self::assertSame('Automatique', oa_channel_labels()['auto']);
    }
}
