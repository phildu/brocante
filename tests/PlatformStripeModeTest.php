<?php

use PHPUnit\Framework\TestCase;

// Fichier de clés de la plateforme redirigé vers un dossier temporaire : aucun
// risque de toucher aux vraies clés de .secrets.
define('SAAS_STRIPE_FILE', sys_get_temp_dir() . '/stripe-plateforme-' . bin2hex(random_bytes(4)) . '.json');
require_once __DIR__ . '/../includes/saas.php';

/**
 * Bascule test / réel du compte Stripe de la plateforme : les deux paires
 * cohabitent dans le même JSON et les champs historiques suivent le mode actif.
 */
final class PlatformStripeModeTest extends TestCase
{
    protected function setUp(): void
    {
        if (is_file(SAAS_STRIPE_FILE)) unlink(SAAS_STRIPE_FILE);
    }

    protected function tearDown(): void
    {
        if (is_file(SAAS_STRIPE_FILE)) unlink(SAAS_STRIPE_FILE);
    }

    private function writeData(array $data): void
    {
        file_put_contents(SAAS_STRIPE_FILE, json_encode($data));
    }

    public function testLegacySinglePairIsRattachedToItsMode(): void
    {
        $this->writeData(['secret_key' => 'sk_test_legacy123', 'webhook_secret' => 'whsec_legacy123']);

        $status = saas_stripe_modes_status();
        self::assertSame('test', $status['mode']);
        self::assertTrue($status['modes']['test']['configured']);
        self::assertFalse($status['modes']['live']['configured']);
        self::assertSame(['whsec_legacy123'], saas_stripe_webhook_secrets());
    }

    public function testSavingTheOtherModeKeepsTheLegacyPairActive(): void
    {
        $this->writeData(['secret_key' => 'sk_test_legacy123', 'webhook_secret' => 'whsec_legacy123']);

        self::assertTrue(saas_stripe_save_mode('live', 'sk_live_live123', 'whsec_live123'));
        $data = json_decode((string) file_get_contents(SAAS_STRIPE_FILE), true);

        self::assertSame('test', $data['mode']);
        self::assertSame('sk_test_legacy123', $data['secret_key']);
        self::assertSame('sk_test_legacy123', $data['test']['secret_key']);
        self::assertSame('sk_live_live123', $data['live']['secret_key']);
    }

    public function testModeSetSwitchesBothTheModeAndTheHistoricalFields(): void
    {
        saas_stripe_save_mode('test', 'sk_test_abc123', 'whsec_test123');
        saas_stripe_save_mode('live', 'sk_live_abc123', 'whsec_live123');

        [$ok, $message] = saas_stripe_mode_set('live');
        self::assertTrue($ok, $message);
        $data = json_decode((string) file_get_contents(SAAS_STRIPE_FILE), true);
        self::assertSame('live', $data['mode']);
        self::assertSame('sk_live_abc123', $data['secret_key']);
        self::assertSame('whsec_live123', $data['webhook_secret']);
        self::assertSame('live', saas_stripe_modes_status()['mode']);
    }

    public function testModeSetRefusesAModeWithoutSecret(): void
    {
        saas_stripe_save_mode('test', 'sk_test_abc123', '');

        [$ok, $message] = saas_stripe_mode_set('live');
        self::assertFalse($ok);
        self::assertStringContainsString('Aucune clé secrète', $message);
        self::assertSame('test', saas_stripe_modes_status()['mode']);
    }

    public function testModeSetRefusesASecretOfTheWrongMode(): void
    {
        $this->writeData(['mode' => 'live', 'live' => ['secret_key' => 'sk_test_abc123', 'webhook_secret' => '']]);

        [$ok] = saas_stripe_mode_set('live');
        self::assertFalse($ok);
    }

    public function testWebhookSecretsAcceptBothModes(): void
    {
        saas_stripe_save_mode('test', 'sk_test_abc123', 'whsec_test123');
        saas_stripe_save_mode('live', 'sk_live_abc123', 'whsec_live123');

        $secrets = saas_stripe_webhook_secrets();
        self::assertContains('whsec_test123', $secrets);
        self::assertContains('whsec_live123', $secrets);
    }

    public function testSavingAWebhookForTheOtherModeDoesNotEraseTheActiveSecret(): void
    {
        saas_stripe_save_mode('test', 'sk_test_abc123', 'whsec_test123');
        saas_stripe_save_mode('live', 'sk_live_abc123', 'whsec_live123');
        saas_stripe_mode_set('live');
        saas_stripe_save_mode('test', '', 'whsec_test456');

        $data = json_decode((string) file_get_contents(SAAS_STRIPE_FILE), true);
        self::assertSame('live', $data['mode']);
        self::assertSame('sk_live_abc123', $data['secret_key']);
        self::assertSame('whsec_live123', $data['webhook_secret']);
        self::assertSame('sk_test_abc123', $data['test']['secret_key']);
        self::assertSame('whsec_test456', $data['test']['webhook_secret']);
    }

    public function testApiBaseOfTheLocalFakeStripeIsPreserved(): void
    {
        $this->writeData(['mode' => 'test', 'test' => ['secret_key' => 'sk_test_abc123', 'webhook_secret' => ''], 'api_base' => 'http://127.0.0.1:8099/v1']);

        saas_stripe_save_mode('live', 'sk_live_abc123', '');
        $data = json_decode((string) file_get_contents(SAAS_STRIPE_FILE), true);

        self::assertSame('http://127.0.0.1:8099/v1', $data['api_base']);
    }
}
