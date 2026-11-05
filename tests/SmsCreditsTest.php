<?php

use PHPUnit\Framework\TestCase;

// Fichiers de crédit isolés : les tests n'écrivent jamais data/saas/.
define('SMS_CREDITS_TEST_DIR', sys_get_temp_dir() . '/brocs-sms-credits-tests');
require_once __DIR__ . '/../includes/sms.php';

final class SmsCreditsTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SAAS_SMS_DIR=' . SMS_CREDITS_TEST_DIR);
        foreach (glob(SMS_CREDITS_TEST_DIR . '/*.json') ?: [] as $file) @unlink($file);
    }

    protected function tearDown(): void
    {
        foreach (glob(SMS_CREDITS_TEST_DIR . '/*.json') ?: [] as $file) @unlink($file);
        putenv('SAAS_SMS_DIR');
    }

    public function testSegments(): void
    {
        self::assertSame(1, sms_segments(''));
        self::assertSame(1, sms_segments(str_repeat('a', 160)));
        self::assertSame(2, sms_segments(str_repeat('a', 161)));
        self::assertSame(2, sms_segments(str_repeat('a', 306)));
        self::assertSame(3, sms_segments(str_repeat('a', 307)));
        self::assertSame(1, sms_segments(str_repeat('é', 70)), 'é fait partie de l’alphabet GSM 7 bits');
        self::assertSame(1, sms_segments(str_repeat('🎉', 70)));
        self::assertSame(2, sms_segments(str_repeat('🎉', 71)));
        self::assertSame(3, sms_segments(str_repeat('🎉', 135)));
    }

    public function testPricingDefaultsAndSave(): void
    {
        self::assertSame(['unit_cents' => 9, 'cost_cents' => 4], saas_sms_pricing());
        self::assertTrue(saas_sms_pricing_save(['unit_cents' => '12', 'cost_cents' => '3']));
        self::assertSame(['unit_cents' => 12, 'cost_cents' => 3], saas_sms_pricing());
        // Valeur hors bornes : la valeur par défaut du champ reprend la main.
        self::assertTrue(saas_sms_pricing_save(['unit_cents' => '9999', 'cost_cents' => '-2']));
        self::assertSame(['unit_cents' => 9, 'cost_cents' => 4], saas_sms_pricing());
    }

    public function testDefaultPacks(): void
    {
        $packs = saas_sms_packs();
        self::assertCount(3, $packs);
        self::assertSame('decouverte', $packs[0]['key']);
        self::assertSame(100, $packs[0]['sms']);
        self::assertSame(900, $packs[0]['price_cents']);
        self::assertSame('pro', $packs[2]['key']);
    }

    public function testPacksNormalizeDropsInvalidRowsAndDeduplicatesKeys(): void
    {
        $packs = saas_sms_packs_normalize([
            ['key' => 'pa', 'name' => 'Petit', 'sms' => 10, 'price_cents' => 100],
            ['key' => 'pa', 'name' => 'Doublon', 'sms' => 20, 'price_cents' => 200],
            ['name' => '', 'sms' => 50, 'price_cents' => 500],
            ['key' => 'vide', 'name' => 'Sans SMS', 'sms' => 0, 'price_cents' => 500],
            ['key' => 'gratuit', 'name' => 'Sans prix', 'sms' => 50, 'price_cents' => 0],
            ['key' => 'gr', 'name' => 'Gros', 'sms' => 100, 'price_cents' => 1000],
        ]);
        self::assertCount(3, $packs);
        self::assertSame('pa', $packs[0]['key']);
        self::assertSame('pa-2', $packs[1]['key']);
        self::assertSame('gr', $packs[2]['key']);
    }

    public function testPacksSaveRoundTrip(): void
    {
        self::assertTrue(saas_sms_packs_save([['key' => 'recharge', 'name' => 'Recharge', 'sms' => 250, 'price_cents' => 2000]]));
        $packs = saas_sms_packs();
        self::assertCount(1, $packs);
        self::assertSame(250, saas_sms_pack('recharge')['sms']);
        self::assertNull(saas_sms_pack('inconnu'));
    }

    public function testShopWithoutRecordIsUnlimited(): void
    {
        $acc = sms_credit_account('boutique-a');
        self::assertFalse($acc['enabled']);
        self::assertTrue(sms_credit_allows('boutique-a', 50));
        self::assertTrue(sms_credit_spend('boutique-a', 5, ['to' => '+33612345678']));
        self::assertFalse(sms_credit_account('boutique-a')['enabled'], 'dépenser sans fiche ne crée pas de fiche');
    }

    public function testAddIsIdempotentPerSession(): void
    {
        self::assertSame('added', sms_credit_add('boutique-a', 100, ['session' => 'cs_1', 'pack' => 'decouverte', 'price_cents' => 900]));
        self::assertSame('already', sms_credit_add('boutique-a', 100, ['session' => 'cs_1', 'pack' => 'decouverte', 'price_cents' => 900]));
        $acc = sms_credit_account('boutique-a');
        self::assertTrue($acc['enabled']);
        self::assertSame(100, $acc['left']);
        self::assertCount(1, $acc['purchases']);
        self::assertSame('error', sms_credit_add('boutique-a', 0));
        self::assertSame('error', sms_credit_add('Boutique A', 10), 'identifiant refusé');
    }

    public function testSpendConsumesAndBlocksAtZero(): void
    {
        sms_credit_add('boutique-a', 10, ['session' => 'cs_2']);
        self::assertTrue(sms_credit_allows('boutique-a', 6));
        self::assertTrue(sms_credit_spend('boutique-a', 6, ['to' => '+33612345678', 'purpose' => 'otp']));
        self::assertSame(4, sms_credit_account('boutique-a')['left']);
        self::assertFalse(sms_credit_allows('boutique-a', 5));
        self::assertTrue(sms_credit_allows('boutique-a', 4));
        sms_credit_spend('boutique-a', 4);
        $acc = sms_credit_account('boutique-a');
        self::assertSame(0, $acc['left']);
        self::assertSame(10, $acc['used']);
        self::assertFalse(sms_credit_allows('boutique-a', 1));
        self::assertCount(2, $acc['sends']);
        // Le coût de l'envoi est figé au barème du moment.
        self::assertSame(54, $acc['sends'][0]['price_cents']);
    }

    public function testSendJournalIsTrimmed(): void
    {
        sms_credit_add('boutique-a', 1000, ['session' => 'cs_3']);
        for ($i = 0; $i < SMS_CREDIT_KEEP_SENDS + 5; $i++) sms_credit_spend('boutique-a', 1);
        self::assertCount(SMS_CREDIT_KEEP_SENDS, sms_credit_account('boutique-a')['sends']);
    }

    public function testResetRemovesRecord(): void
    {
        sms_credit_add('boutique-a', 50, ['session' => 'cs_4']);
        self::assertTrue(sms_credit_reset('boutique-a'));
        self::assertFalse(sms_credit_account('boutique-a')['enabled']);
        self::assertFalse(sms_credit_reset('../evasion'));
    }

    public function testMaskPhone(): void
    {
        self::assertSame('+33 •• •• •• 78', sms_credit_mask_phone('+33 6 12 34 56 78'));
        self::assertSame('+33 •• •• •• 78', sms_credit_mask_phone('0612345678'));
        self::assertSame('', sms_credit_mask_phone('pas un numéro'));
    }

    public function testApplySession(): void
    {
        self::assertSame('invalid', sms_credit_apply_session(['metadata' => ['type' => 'ai_credit', 'shop' => 'boutique-a', 'sms' => '100']])['state']);
        self::assertSame('invalid', sms_credit_apply_session(['metadata' => ['type' => 'sms_credit', 'shop' => '../x', 'sms' => '100']])['state']);
        self::assertSame('invalid', sms_credit_apply_session(['metadata' => ['type' => 'sms_credit', 'shop' => 'boutique-a', 'sms' => '0']])['state']);

        $session = ['id' => 'cs_5', 'payment_status' => 'unpaid', 'amount_total' => 900,
            'metadata' => ['type' => 'sms_credit', 'shop' => 'boutique-a', 'pack' => 'decouverte', 'sms' => '100']];
        self::assertSame('unpaid', sms_credit_apply_session($session)['state']);
        self::assertFalse(sms_credit_account('boutique-a')['enabled'], 'une session non payée ne crédite rien');

        $session['payment_status'] = 'paid';
        $first = sms_credit_apply_session($session);
        self::assertSame('added', $first['state']);
        self::assertSame(100, $first['sms']);
        self::assertSame(900, $first['amount']);
        $acc = sms_credit_account('boutique-a');
        self::assertSame(100, $acc['left']);
        self::assertSame(900, $acc['purchases'][0]['price_cents']);
        self::assertSame('stripe', $acc['purchases'][0]['source']);

        self::assertSame('already', sms_credit_apply_session($session)['state'], 'rejeu de la page de retour');
        self::assertSame(100, sms_credit_account('boutique-a')['left']);
    }
}
