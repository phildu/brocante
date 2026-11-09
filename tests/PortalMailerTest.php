<?php

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

if (!defined('PORTAL_MAILER_FILE')) define('PORTAL_MAILER_FILE', sys_get_temp_dir() . '/portal-mailer-test-' . getmypid() . '.json');
require_once __DIR__ . '/../includes/portal-mailer.php';

/** Double du client OVH : enregistre les appels, répond selon un scénario. */
final class FakeOvhMailClient
{
    public array $calls = [];
    public function __construct(private bool $offer, private array $accounts = []) {}
    public function get(string $path, $content = null)
    {
        $this->calls[] = ['GET', $path, $content];
        if (preg_match('#^/email/domain/[^/]+$#', $path)) {
            if (!$this->offer) throw new ClientException('absent', new Request('GET', $path), new Response(404));
            return ['domain' => 'bricbrok.com'];
        }
        return $this->accounts;
    }
    public function post(string $path, $content = null)
    {
        $this->calls[] = ['POST', $path, $content];
        return ['id' => 1];
    }
}

final class PortalMailerTest extends TestCase
{
    protected function setUp(): void
    {
        @unlink(PORTAL_MAILER_FILE);
        portal_mailer_transport(null, true);
    }

    protected function tearDown(): void
    {
        @unlink(PORTAL_MAILER_FILE);
        portal_mailer_transport(null, true);
    }

    private function ready(): void
    {
        portal_mailer_save(['pass' => 'Secret123abc', 'from_name' => 'Bricbrok']);
    }

    public function testInactiveWithoutPassword(): void
    {
        $this->assertFalse(portal_mailer_ready());
        $this->assertFalse(portal_mailer_applies('From: Brocs <noreply@bricbrok.com>'));
        $this->assertNull(portal_mailer_dispatch('From: Brocs <noreply@bricbrok.com>', 'a@b.fr', 'Objet', [], 'Corps'));
    }

    public function testAppliesOnlyToPortalDomain(): void
    {
        $this->ready();
        $this->assertTrue(portal_mailer_applies('From: Brocs <noreply@bricbrok.com>'));
        $this->assertTrue(portal_mailer_applies('Brocs <NOREPLY@bricbrok.com>'));
        $this->assertFalse(portal_mailer_applies('From: Boutique <noreply@autreboutique.fr>'));
        $this->assertNull(portal_mailer_dispatch('From: Boutique <noreply@autreboutique.fr>', 'a@b.fr', 'Objet', [], 'Corps'));
    }

    public function testDisabledFlagFallsBackToMail(): void
    {
        $this->ready();
        portal_mailer_save(['enabled' => '0']);
        $this->assertFalse(portal_mailer_ready());
        $this->assertNull(portal_mailer_dispatch('From: Brocs <noreply@bricbrok.com>', 'a@b.fr', 'Objet', [], 'Corps'));
    }

    public function testDispatchRewritesFromAndKeepsName(): void
    {
        $this->ready();
        $seen = null;
        portal_mailer_transport(function (string $from, string $to, string $raw, array $smtp) use (&$seen): array {
            $seen = compact('from', 'to', 'raw', 'smtp');
            return nt_ok();
        });
        $r = portal_mailer_dispatch('From: Équipe Brocs <noreply@bricbrok.com>', 'client@example.org', '=?UTF-8?B?T2JqZXQ=?=',
            ['MIME-Version: 1.0', 'From: pirate@evil.test', 'Reply-To: aide@bricbrok.com'], 'Corps du message');
        $this->assertTrue($r);
        $this->assertSame('contact@bricbrok.com', $seen['from']);
        $this->assertSame('client@example.org', $seen['to']);
        $this->assertSame('ssl0.ovh.net', $seen['smtp']['host']);
        $this->assertSame(465, $seen['smtp']['port']);
        $this->assertSame('contact@bricbrok.com', $seen['smtp']['user']);
        $this->assertSame('Secret123abc', $seen['smtp']['pass']);
        $this->assertStringContainsString('<contact@bricbrok.com>', $seen['raw']);
        $this->assertStringNotContainsString('noreply@bricbrok.com', $seen['raw']);
        $this->assertStringNotContainsString('pirate@evil.test', $seen['raw']);
        $this->assertStringContainsString('Reply-To: aide@bricbrok.com', $seen['raw']);
        $this->assertStringContainsString("\r\n\r\nCorps du message", $seen['raw']);
    }

    public function testDispatchFailureReturnsFalseAndInvalidRecipientIsRefused(): void
    {
        $this->ready();
        portal_mailer_transport(fn() => nt_fail('SMTP : 535 refusé', true));
        $this->assertFalse(portal_mailer_dispatch('From: Brocs <noreply@bricbrok.com>', 'client@example.org', 'O', [], 'C'));
        $this->assertFalse(portal_mailer_dispatch('From: Brocs <noreply@bricbrok.com>', "x@y.fr\r\nBcc: z@z.fr", 'O', [], 'C'));
    }

    public function testSaveIsPrivateKeepsPasswordAndValidates(): void
    {
        $this->ready();
        portal_mailer_save(['pass' => '', 'from_name' => 'Autre']);
        $this->assertSame('Secret123abc', portal_mailer_config()['pass']);
        $this->assertSame('Autre', portal_mailer_config()['from_name']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms(PORTAL_MAILER_FILE)), -4));
        foreach ([['account' => 'Bad Name'], ['domain' => 'pas un domaine'], ['port' => '99999'], ['secure' => 'rien'], ['host' => "x\ny"]] as $bad) {
            try {
                portal_mailer_save($bad);
                $this->fail('Réglage invalide accepté : ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGeneratedPasswordMeetsRules(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $p = portal_mailer_generate_password();
            $this->assertSame(24, strlen($p));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $p);
            $this->assertMatchesRegularExpression('/[A-Z]/', $p);
            $this->assertMatchesRegularExpression('/[a-z]/', $p);
            $this->assertMatchesRegularExpression('/[0-9]/', $p);
        }
        $this->assertNotSame(portal_mailer_generate_password(), portal_mailer_generate_password());
    }

    public function testOvhEnsureCreatesMailboxAndStoresPassword(): void
    {
        $client = new FakeOvhMailClient(true, []);
        $r = portal_mailer_ovh_ensure(false, $client);
        $this->assertSame('created', $r['action']);
        $post = array_values(array_filter($client->calls, fn($c) => $c[0] === 'POST'));
        $this->assertCount(1, $post);
        $this->assertSame('/email/domain/bricbrok.com/account', $post[0][1]);
        $this->assertSame('contact', $post[0][2]['accountName']);
        $this->assertSame($post[0][2]['password'], portal_mailer_config()['pass']);
        $this->assertTrue(portal_mailer_ready());
        $this->assertStringNotContainsString($post[0][2]['password'], $r['message']);
    }

    public function testOvhEnsureIsIdempotentWhenMailboxAndPasswordExist(): void
    {
        $this->ready();
        $client = new FakeOvhMailClient(true, ['contact']);
        $r = portal_mailer_ovh_ensure(false, $client);
        $this->assertSame('unchanged', $r['action']);
        $this->assertCount(0, array_filter($client->calls, fn($c) => $c[0] === 'POST'));
        $this->assertSame('Secret123abc', portal_mailer_config()['pass']);
    }

    public function testOvhEnsureResetsPasswordOfExistingMailbox(): void
    {
        $this->ready();
        $client = new FakeOvhMailClient(true, ['contact']);
        $r = portal_mailer_ovh_ensure(true, $client);
        $this->assertSame('password_reset', $r['action']);
        $post = array_values(array_filter($client->calls, fn($c) => $c[0] === 'POST'));
        $this->assertSame('/email/domain/bricbrok.com/account/contact/changePassword', $post[0][1]);
        $this->assertSame($post[0][2]['password'], portal_mailer_config()['pass']);
        $this->assertNotSame('Secret123abc', portal_mailer_config()['pass']);
    }

    public function testOvhEnsureWithoutKnownPasswordRenewsIt(): void
    {
        $client = new FakeOvhMailClient(true, ['contact']);
        $this->assertSame('password_reset', portal_mailer_ovh_ensure(false, $client)['action']);
        $this->assertTrue(portal_mailer_ready());
    }

    public function testOvhEnsureExplainsMissingMxPlanOffer(): void
    {
        $client = new FakeOvhMailClient(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MX Plan/');
        portal_mailer_ovh_ensure(false, $client);
    }

    public function testDnsCheck(): void
    {
        $ok = portal_mailer_dns_check('bricbrok.com', fn(string $h, string $t): array => $t === 'MX'
            ? [['target' => 'mx1.mail.ovh.net']]
            : [['txt' => 'v=spf1 include:mx.ovh.com ~all']]);
        $this->assertSame(['mx' => true, 'spf' => true], $ok);
        $ko = portal_mailer_dns_check('bricbrok.com', fn() => []);
        $this->assertSame(['mx' => false, 'spf' => false], $ko);
    }
}
