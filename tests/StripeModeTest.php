<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/stripe-mode.php';

/**
 * Bascule test / réel des clés Stripe d'une boutique : les deux paires sont
 * conservées et la paire active est recopiée dans les fichiers historiques
 * lus par le paiement et les webhooks.
 */
final class StripeModeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/stripe-mode-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function active(string $kind): string
    {
        return stripe_read_secret($this->dir . '/stripe_' . $kind . '.key');
    }

    public function testFreshDirectoryStartsInTestWithoutKeys(): void
    {
        self::assertSame('test', stripe_mode_current($this->dir));
        self::assertFalse(stripe_mode_ready('test', $this->dir));
        self::assertFalse(stripe_mode_ready('live', $this->dir));
    }

    public function testSavingTestKeysActivatesThemImmediately(): void
    {
        [$ok, $message] = stripe_mode_save('test', [
            'secret' => 'sk_test_abc123',
            'publishable' => 'pk_test_abc123',
            'webhook' => 'whsec_abc123',
        ], $this->dir);

        self::assertTrue($ok, $message);
        self::assertSame('sk_test_abc123', $this->active('secret'));
        self::assertSame('pk_test_abc123', $this->active('publishable'));
        self::assertSame('whsec_abc123', $this->active('webhook'));
        self::assertSame('test', stripe_mode_current($this->dir));
    }

    public function testSavingTheOtherModeDoesNotChangeTheActivePairUntilActivated(): void
    {
        stripe_mode_save('test', ['secret' => 'sk_test_abc123'], $this->dir);
        [$ok, $message] = stripe_mode_save('live', ['secret' => 'sk_live_live123'], $this->dir);

        self::assertTrue($ok, $message);
        self::assertSame('sk_test_abc123', $this->active('secret'), 'Le mode test reste actif.');
        self::assertSame('test', stripe_mode_current($this->dir));
        self::assertTrue(stripe_mode_ready('live', $this->dir));

        [$ok, $message] = stripe_mode_activate('live', $this->dir);
        self::assertTrue($ok, $message);
        self::assertSame('sk_live_live123', $this->active('secret'));
        self::assertSame('sk_test_abc123', stripe_mode_keys('test', $this->dir)['secret'], 'Les clés test sont conservées.');
        self::assertSame('live', stripe_mode_current($this->dir));
    }

    public function testEmptyFieldsKeepExistingKeys(): void
    {
        stripe_mode_save('test', ['secret' => 'sk_test_abc123', 'webhook' => 'whsec_abc123'], $this->dir);
        [$ok, $message] = stripe_mode_save('test', ['secret' => '', 'publishable' => 'pk_test_abc123', 'webhook' => ''], $this->dir);

        self::assertTrue($ok, $message);
        self::assertSame('sk_test_abc123', $this->active('secret'));
        self::assertSame('whsec_abc123', $this->active('webhook'));
        self::assertSame('pk_test_abc123', $this->active('publishable'));
    }

    public function testKeysOfTheWrongModeAreRefused(): void
    {
        [$ok, $message] = stripe_mode_save('live', ['secret' => 'sk_test_abc123'], $this->dir);
        self::assertFalse($ok);
        self::assertStringContainsString('sk_live_', $message);
        self::assertSame('', $this->active('secret'));
    }

    public function testPublishableAndSecretAreNotSwappableHere(): void
    {
        // L'inversion est corrigée avant l'appel (admin, portail, pack) ; le
        // helper refuse une clé publiable placée dans le champ secret.
        [$ok] = stripe_mode_save('test', ['secret' => 'pk_test_abc123'], $this->dir);
        self::assertFalse($ok);
    }

    public function testLegacySinglePairInfersItsMode(): void
    {
        file_put_contents($this->dir . '/stripe_secret.key', 'sk_live_legacy123');
        file_put_contents($this->dir . '/stripe_webhook.key', 'whsec_legacy123');

        self::assertSame('live', stripe_mode_current($this->dir));
        self::assertTrue(stripe_mode_ready('live', $this->dir));
        self::assertSame('whsec_legacy123', stripe_mode_keys('live', $this->dir)['webhook']);
    }

    public function testActivationRequiresASecretKey(): void
    {
        [$ok, $message] = stripe_mode_activate('live', $this->dir);
        self::assertFalse($ok);
        self::assertStringContainsString('Aucune clé secrète', $message);
        self::assertSame('test', stripe_mode_current($this->dir));
    }

    public function testModeFileIsPrivate(): void
    {
        stripe_mode_save('test', ['secret' => 'sk_test_abc123'], $this->dir);
        stripe_mode_activate('test', $this->dir);
        self::assertSame(0600, fileperms($this->dir . '/stripe_mode') & 0777);
        self::assertSame(0600, fileperms($this->dir . '/stripe_secret.key') & 0777);
    }
}
