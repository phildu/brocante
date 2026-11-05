<?php

use PHPUnit\Framework\TestCase;

// Fichier partagé isolé : les tests n'écrivent jamais data/sms-partage.json.
define('SMS_TEST_FILE', sys_get_temp_dir() . '/brocs-sms-shared-tests/sms-partage.json');
putenv('SMS_SHARED_FILE=' . SMS_TEST_FILE);
require_once __DIR__ . '/../includes/sms.php';

final class SmsSharedTest extends TestCase
{
    private const ENV_NAMES = ['OVH_SMS_ACCOUNT', 'OVH_SMS_LOGIN', 'OVH_SMS_PASSWORD', 'OVH_SMS_FROM', 'OVH_SMS_NOSTOP', 'SMS_PROVIDER', 'TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM'];

    protected function setUp(): void
    {
        @unlink(SMS_TEST_FILE);
        foreach (self::ENV_NAMES as $name) putenv($name);
        $GLOBALS['db_pdo_override'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        @unlink(SMS_TEST_FILE);
        unset($GLOBALS['db_pdo_override']);
        foreach (self::ENV_NAMES as $name) putenv($name);
    }

    private function saveShared(array $values): void
    {
        self::assertTrue(sms_shared_save($values + sms_shared()));
    }

    public function testSenderValidation(): void
    {
        self::assertTrue(sms_sender_valid('BROCS'));
        self::assertTrue(sms_sender_valid('A1B2C3D4E5F'));
        self::assertTrue(sms_sender_valid('33612345678'));
        self::assertFalse(sms_sender_valid('A1B2C3D4E5F6'));
        self::assertFalse(sms_sender_valid('La fripe à Naty'));
        self::assertFalse(sms_sender_valid('+336123456789'));
        self::assertFalse(sms_sender_valid('1234567890123456'));
        self::assertFalse(sms_sender_valid(''));
    }

    public function testPhoneE164(): void
    {
        self::assertSame('+33612345678', sms_phone_e164('06 12 34 56 78'));
        self::assertSame('+33612345678', sms_phone_e164('0033612345678'));
        self::assertSame('+33612345678', sms_phone_e164('+33 6 12 34 56 78'));
        self::assertNull(sms_phone_e164('123'));
        self::assertNull(sms_phone_e164('pas un numéro'));
    }

    public function testMergeKeepsStoredSecretsAndClearsOtherFields(): void
    {
        $current = array_replace(sms_shared(), [
            'sms_provider' => 'ovh',
            'ovh_sms_account' => 'sms-pd4-1',
            'ovh_sms_login' => 'brocs',
            'ovh_sms_password' => 'motdepasse',
            'ovh_sms_from' => 'BROCS',
        ]);
        [$merged, $warn] = sms_shared_merge($current, [
            'sms_provider' => 'twilio',
            'ovh_sms_account' => '',
            'ovh_sms_login' => '',
            'ovh_sms_password' => '',
            'ovh_sms_from' => '',
            'twilio_sid' => 'AC123',
            'twilio_token' => '',
            'twilio_from' => '+33600000000',
        ]);
        self::assertSame('', $warn);
        self::assertSame('twilio', $merged['sms_provider']);
        self::assertSame('', $merged['ovh_sms_account']);
        self::assertSame('', $merged['ovh_sms_login']);
        self::assertSame('motdepasse', $merged['ovh_sms_password'], 'un mot de passe vide conserve la valeur enregistrée');
        self::assertSame('AC123', $merged['twilio_sid']);
        self::assertSame('+33600000000', $merged['twilio_from']);
    }

    public function testMergeRejectsInvalidSenderAndUnknownProvider(): void
    {
        $current = array_replace(sms_shared(), ['sms_provider' => 'ovh', 'ovh_sms_from' => 'BROCS']);
        [$merged, $warn] = sms_shared_merge($current, ['sms_provider' => 'nexmo', 'ovh_sms_from' => 'Expéditeur trop long']);
        self::assertNotSame('', $warn);
        self::assertSame('BROCS', $merged['ovh_sms_from']);
        self::assertSame('', $merged['sms_provider']);
    }

    public function testSaveRoundTripInPrivateFile(): void
    {
        self::assertTrue(sms_shared_save([
            'sms_provider' => 'ovh',
            'ovh_sms_account' => 'sms-pd4-1',
            'ovh_sms_login' => 'brocs',
            'ovh_sms_password' => 'motdepasse',
            'ovh_sms_from' => 'BROCS',
        ]));
        self::assertFileExists(SMS_TEST_FILE);
        self::assertSame('0600', substr(sprintf('%o', fileperms(SMS_TEST_FILE)), -4));
        $stored = sms_shared();
        self::assertSame('sms-pd4-1', $stored['ovh_sms_account']);
        self::assertSame('brocs', sms_shared_get('ovh_sms_login'));
        self::assertSame('', sms_shared_get('twilio_sid'));
    }

    public function testShopThenPortalThenEnvironment(): void
    {
        $this->saveShared(['ovh_sms_account' => 'sms-partage', 'ovh_sms_login' => 'portail']);
        self::assertSame('sms-partage', sms_ovh_config()['account'], 'le portail sert de dernier recours');
        self::assertSame('sms-partage', sms_ovh_config(SMS_SOURCE_SHARED)['account']);
        self::assertSame('', sms_ovh_config(SMS_SOURCE_SHOP)['account'], 'le formulaire de boutique ne recopie pas l’héritage');
        self::assertSame('portail', sms_setting_origin('ovh_sms_account', 'OVH_SMS_ACCOUNT'));

        putenv('OVH_SMS_ACCOUNT=sms-env');
        self::assertSame('sms-env', sms_ovh_config()['account'], 'la variable d’environnement passe avant le portail');
        self::assertSame('sms-partage', sms_ovh_config(SMS_SOURCE_SHARED)['account']);
        self::assertSame('env', sms_setting_origin('ovh_sms_account', 'OVH_SMS_ACCOUNT'));
    }

    public function testShopSettingsWinOverPortal(): void
    {
        $this->saveShared(['ovh_sms_account' => 'sms-partage', 'ovh_sms_login' => 'portail', 'ovh_sms_password' => 'portail-secret', 'ovh_sms_from' => 'PORTAIL']);
        shop_setting_set('ovh_sms_account', 'sms-boutique');
        try {
            self::assertSame('sms-boutique', sms_ovh_config()['account']);
            self::assertSame('portail', sms_ovh_config()['login'], 'les champs vides de la boutique viennent du portail');
            self::assertSame('shop', sms_setting_origin('ovh_sms_account', 'OVH_SMS_ACCOUNT'));
        } finally {
            $GLOBALS['db_pdo_override']->exec('DELETE FROM settings');
        }
    }

    public function testProviderPick(): void
    {
        self::assertSame('ovh', sms_pick_provider('', true, false));
        self::assertSame('twilio', sms_pick_provider('', false, true));
        self::assertSame('ovh', sms_pick_provider('', true, true), 'OVH passe avant Twilio en automatique');
        self::assertSame('twilio', sms_pick_provider('twilio', true, true));
        self::assertSame('ovh', sms_pick_provider('twilio', true, false), 'un fournisseur choisi mais incomplet laisse la place à l’autre');
        self::assertSame('', sms_pick_provider('ovh', false, false));
    }

    public function testSharedProviderNameUsesPortalOnly(): void
    {
        $this->saveShared([
            'ovh_sms_account' => 'sms-pd4-1', 'ovh_sms_login' => 'brocs', 'ovh_sms_password' => 'p', 'ovh_sms_from' => 'BROCS',
        ]);
        self::assertSame('ovh', sms_shared_provider_name());
        self::assertSame('ovh', sms_provider_name(), 'une boutique sans identifiants reprend ceux du portail');

        $this->saveShared(['sms_provider' => 'twilio']);
        self::assertSame('ovh', sms_shared_provider_name(), 'Twilio choisi mais incomplet : OVH reste utilisé');

        $this->saveShared([
            'ovh_sms_account' => '', 'ovh_sms_login' => '', 'ovh_sms_password' => '', 'ovh_sms_from' => '',
            'twilio_sid' => 'AC123', 'twilio_token' => 'tok', 'twilio_from' => '+33600000000',
        ]);
        self::assertSame('twilio', sms_shared_provider_name());
    }
}
