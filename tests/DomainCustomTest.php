<?php

use PHPUnit\Framework\TestCase;

// État des adresses personnalisées écrit hors du projet ; les contrôles DNS
// sont remplacés par une table de réponses, aucun réseau n'est interrogé.
define('DOMAIN_CUSTOM_DATA_DIR', sys_get_temp_dir() . '/brocs-domaines-custom-test-' . getmypid() . '/custom');

require_once __DIR__ . '/../includes/domain-custom.php';

final class DomainCustomTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        @rmdir(DOMAIN_CUSTOM_DATA_DIR);
        @rmdir(dirname(DOMAIN_CUSTOM_DATA_DIR));
    }

    private function clear(): void
    {
        foreach (glob(DOMAIN_CUSTOM_DATA_DIR . '/*') ?: [] as $file) @unlink($file);
        foreach (glob(dirname(DOMAIN_CUSTOM_DATA_DIR) . '/*.json') ?: [] as $file) @unlink($file);
    }

    /** Faux résolveur DNS : table « nom:type » → enregistrements. */
    private function lookup(array $map): callable
    {
        return static fn (string $name, int $type): array => (array) ($map[$name . ':' . $type] ?? []);
    }

    private function txt(string $name, string $value): array
    {
        return [$name . ':' . DNS_TXT => [['host' => $name, 'type' => 'TXT', 'txt' => $value]]];
    }

    private function a(string $name, string ...$ips): array
    {
        return [$name . ':' . DNS_A => array_map(static fn (string $ip): array => ['host' => $name, 'type' => 'A', 'ip' => $ip], $ips)];
    }

    private function attached(string $slug = 'boutique-a', string $domain = 'mon-domaine.fr'): array
    {
        return domain_custom_attach($slug, $domain, 'bricbrok.com');
    }

    private function dnsOk(string $slug = 'boutique-a', string $domain = 'mon-domaine.fr'): array
    {
        $record = $this->attached($slug, $domain);
        $token = 'brocs-verify=' . $record['token'];
        return domain_custom_check($slug, $this->lookup($this->txt('_brocs.' . $domain, $token) + $this->a($domain, '213.186.33.3')), ['213.186.33.3']);
    }

    public function testStateLabelsAreFrench(): void
    {
        $this->assertSame('Preuve DNS à ajouter', domain_custom_state_label('pending'));
        $this->assertSame('Propriété confirmée', domain_custom_state_label('verified'));
        $this->assertSame('DNS en place', domain_custom_state_label('dns_ok'));
        $this->assertSame('Mise en service demandée', domain_custom_state_label('ready'));
        $this->assertSame('En service', domain_custom_state_label('active'));
        $this->assertSame('État inconnu', domain_custom_state_label('nawak'));
    }

    public function testAttachNormalizesAndCreatesPendingRecord(): void
    {
        $record = $this->attached('boutique-a', ' HTTPS://WWW.Mon-Domaine.fr/chemin ');
        $this->assertSame('mon-domaine.fr', $record['domain']);
        $this->assertSame('pending', $record['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $record['token']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms(DOMAIN_CUSTOM_DATA_DIR . '/boutique-a.json')), -4));
    }

    public function testAttachRejectsInvalidDomains(): void
    {
        foreach (['', 'mon domaine.fr', 'localhost', '192.168.1.1', '-foo.fr', 'foo'] as $input) {
            try {
                $this->attached('boutique-a', $input);
                $this->fail('Le domaine « ' . $input . ' » doit être refusé.');
            } catch (InvalidArgumentException $ex) {
                $this->assertStringContainsString('domaine valide', $ex->getMessage());
            }
        }
    }

    public function testAttachRefusesPlatformSubdomain(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('domaine de la plateforme');
        $this->attached('boutique-a', 'admin.bricbrok.com');
    }

    public function testAttachRefusesDomainOfAnotherShop(): void
    {
        $this->attached('boutique-a', 'mon-domaine.fr');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('déjà rattaché');
        $this->attached('boutique-b', 'mon-domaine.fr');
    }

    public function testAttachReplacesOwnDomainWithNewToken(): void
    {
        $first = $this->attached();
        $second = $this->attached();
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame('pending', domain_custom_load('boutique-a')['state']);
    }

    public function testAttachRefusesDomainPurchasedForAnotherShop(): void
    {
        @mkdir(dirname(DOMAIN_CUSTOM_DATA_DIR), 0700, true);
        file_put_contents(dirname(DOMAIN_CUSTOM_DATA_DIR) . '/autre-boutique.json', json_encode([
            'purchase' => ['state' => 'ordered', 'quote' => ['domain' => 'achete.fr']],
        ]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('acheté pour une autre boutique');
        $this->attached('boutique-a', 'achete.fr');
    }

    public function testCheckStaysPendingWithoutDnsProof(): void
    {
        $this->attached();
        $record = domain_custom_check('boutique-a', $this->lookup([]), ['213.186.33.3']);
        $this->assertSame('pending', $record['state']);
        $this->assertSame([], $record['observed']['txt']);
    }

    public function testCheckVerifiesOwnershipWithTxtRecord(): void
    {
        $record = $this->attached();
        $lookup = $this->lookup($this->txt('_brocs.mon-domaine.fr', 'brocs-verify=' . $record['token']));
        $checked = domain_custom_check('boutique-a', $lookup, ['213.186.33.3']);
        $this->assertSame('verified', $checked['state']);
        $this->assertGreaterThan(0, $checked['ownership_at']);
        $this->assertSame(0, $checked['routing_at']);
    }

    public function testCheckAcceptsTxtOnTheApexDomain(): void
    {
        $record = $this->attached();
        $lookup = $this->lookup($this->txt('mon-domaine.fr', 'brocs-verify=' . $record['token']));
        $checked = domain_custom_check('boutique-a', $lookup, ['213.186.33.3']);
        $this->assertSame('verified', $checked['state']);
    }

    public function testCheckConfirmsRoutingOnlyWithExpectedIp(): void
    {
        $record = $this->attached();
        $token = 'brocs-verify=' . $record['token'];
        $proof = $this->txt('_brocs.mon-domaine.fr', $token);

        $wrong = domain_custom_check('boutique-a', $this->lookup($proof + $this->a('mon-domaine.fr', '203.0.113.9')), ['213.186.33.3']);
        $this->assertSame('verified', $wrong['state']);
        $this->assertSame(['203.0.113.9'], $wrong['observed']['a']);

        $right = domain_custom_check('boutique-a', $this->lookup($proof + $this->a('mon-domaine.fr', '213.186.33.3')), ['213.186.33.3']);
        $this->assertSame('dns_ok', $right['state']);
        $this->assertGreaterThan(0, $right['routing_at']);
    }

    public function testOwnershipStaysProvenWhenTxtDisappears(): void
    {
        $this->dnsOk();
        $checked = domain_custom_check('boutique-a', $this->lookup([]), ['213.186.33.3']);
        $this->assertSame('verified', $checked['state']);
        $this->assertGreaterThan(0, $checked['ownership_at']);
    }

    public function testRequestActivationRequiresDnsOk(): void
    {
        $this->attached();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('preuve DNS');
        domain_custom_request_activation('boutique-a');
    }

    public function testRequestThenActivateMakesTheShopResolveOnTheDomain(): void
    {
        $this->dnsOk();
        $ready = domain_custom_request_activation('boutique-a');
        $this->assertSame('ready', $ready['state']);
        $this->assertSame([], domain_custom_hosts(), 'Une demande n’est pas encore en service.');
        $this->assertSame('', domain_custom_active_host('boutique-a'));

        $active = domain_custom_activate('boutique-a');
        $this->assertSame('active', $active['state']);
        $this->assertSame(['mon-domaine.fr' => 'boutique-a'], domain_custom_hosts());
        $this->assertSame('mon-domaine.fr', domain_custom_active_host('boutique-a'));
    }

    public function testActivateRefusesUnreadyRequest(): void
    {
        $this->attached();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pas prête');
        domain_custom_activate('boutique-a');
    }

    public function testRemoveStopsResolution(): void
    {
        $this->dnsOk();
        domain_custom_request_activation('boutique-a');
        domain_custom_activate('boutique-a');
        domain_custom_remove('boutique-a');
        $this->assertSame([], domain_custom_load('boutique-a'));
        $this->assertSame([], domain_custom_hosts());
        $this->assertFileDoesNotExist(DOMAIN_CUSTOM_DATA_DIR . '/boutique-a.json');
    }

    private const HOSTING = ['service' => 'arrimage.com', 'path' => 'www/brocs', 'max' => 0];

    /** Fausse API OVH : mémorise les appels, renvoie la liste d'alias demandée et un numéro de tâche. */
    private function fakeApi(array &$calls, array $existing = [], ?string $failOn = null): callable
    {
        return static function (string $method, string $path, array $params = []) use (&$calls, $existing, $failOn) {
            $calls[] = [$method, $path, $params];
            if ($failOn !== null && ($params['domain'] ?? '') === $failOn) {
                throw new RuntimeException('OVH refuse ' . $failOn);
            }
            if ($method === 'GET') return $existing;
            if ($method === 'POST') return ['id' => 100 + count($calls), 'function' => 'attachedDomain/create', 'status' => 'todo'];
            return null;
        };
    }

    public function testHostingAttachDeclaresDomainAndWwwWithSsl(): void
    {
        $this->dnsOk();
        $calls = [];
        $record = domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls), self::HOSTING);

        $this->assertSame('GET', $calls[0][0]);
        $this->assertSame('/hosting/web/arrimage.com/attachedDomain', $calls[0][1]);
        $posts = array_values(array_filter($calls, static fn (array $c): bool => $c[0] === 'POST'));
        $this->assertSame(['mon-domaine.fr', 'www.mon-domaine.fr'], array_column(array_column($posts, 2), 'domain'));
        $this->assertSame('www/brocs', $posts[0][2]['path']);
        $this->assertTrue($posts[0][2]['ssl'], 'Le certificat Let’s Encrypt est demandé avec l’alias.');
        $this->assertTrue($posts[0][2]['bypassDNSConfiguration'], 'Le DNS du client n’est jamais modifié par OVH.');
        $this->assertSame(['mon-domaine.fr', 'www.mon-domaine.fr'], $record['hosting']['domains']);
        $this->assertSame('', $record['hosting']['error']);
        $this->assertSame('dns_ok', $record['state'], 'Le rattachement ne change pas l’état : l’activation reste manuelle.');
    }

    public function testHostingAttachSkipsAliasesAlreadyInPlace(): void
    {
        $this->dnsOk();
        $calls = [];
        $record = domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls, ['Mon-Domaine.fr']), self::HOSTING);
        $posts = array_values(array_filter($calls, static fn (array $c): bool => $c[0] === 'POST'));
        $this->assertSame(['www.mon-domaine.fr'], array_column(array_column($posts, 2), 'domain'));
        $this->assertSame(['mon-domaine.fr', 'www.mon-domaine.fr'], $record['hosting']['domains']);
    }

    public function testHostingAttachRespectsTheConfiguredCeiling(): void
    {
        $this->dnsOk();
        $calls = [];
        try {
            domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls, ['a.fr', 'b.fr', 'c.fr']), ['max' => 4] + self::HOSTING);
            $this->fail('Le plafond aurait dû refuser le rattachement.');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('Plafond de 4 domaines', $ex->getMessage());
        }
        $this->assertSame([], array_filter($calls, static fn (array $c): bool => $c[0] === 'POST'), 'Aucun alias n’est créé au-delà du plafond.');
    }

    public function testHostingAttachKeepsApexWhenOnlyWwwFails(): void
    {
        $this->dnsOk();
        $calls = [];
        $record = domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls, [], 'www.mon-domaine.fr'), self::HOSTING);
        $this->assertSame(['mon-domaine.fr'], $record['hosting']['domains']);
        $this->assertStringContainsString('www.mon-domaine.fr', $record['hosting']['www_error']);
    }

    public function testHostingAttachFailsWhenApexIsRefused(): void
    {
        $this->dnsOk();
        $calls = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OVH refuse mon-domaine.fr');
        domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls, [], 'mon-domaine.fr'), self::HOSTING);
    }

    public function testHostingAttachRequiresProvenDomainAndSettings(): void
    {
        $this->attached();
        $calls = [];
        try {
            domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls), self::HOSTING);
            $this->fail('Un domaine non prouvé ne doit pas être rattaché.');
        } catch (RuntimeException $ex) {
            $this->assertStringContainsString('propriété', $ex->getMessage());
        }
        $this->assertSame([], $calls);

        $this->dnsOk();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pas réglé');
        domain_custom_hosting_attach('boutique-a', $this->fakeApi($calls), []);
    }

    public function testRequestActivationAttachesHostingWhenConfigured(): void
    {
        $this->dnsOk();
        $calls = [];
        $ready = domain_custom_request_activation('boutique-a', $this->fakeApi($calls), self::HOSTING);
        $this->assertSame('ready', $ready['state']);
        $this->assertSame(['mon-domaine.fr', 'www.mon-domaine.fr'], $ready['hosting']['domains']);
        $this->assertSame([], domain_custom_hosts(), 'Les alias créés ne mettent pas la boutique en service.');
    }

    public function testRequestActivationSurvivesAHostingFailure(): void
    {
        $this->dnsOk();
        $previous = ini_set('error_log', sys_get_temp_dir() . '/brocs-domaines-custom-test.log');
        try {
            $calls = [];
            $ready = domain_custom_request_activation('boutique-a', $this->fakeApi($calls, [], 'mon-domaine.fr'), self::HOSTING);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertSame('ready', $ready['state'], 'La demande reste enregistrée même si OVH refuse.');
        $this->assertStringContainsString('OVH refuse', (string) $ready['hosting']['error']);
        $this->assertArrayNotHasKey('domains', $ready['hosting']);
    }

    public function testRemoveDetachesHostingAliases(): void
    {
        $this->dnsOk();
        $calls = [];
        domain_custom_request_activation('boutique-a', $this->fakeApi($calls), self::HOSTING);
        $calls = [];
        $warning = domain_custom_remove('boutique-a', $this->fakeApi($calls));
        $this->assertSame('', $warning);
        $this->assertSame(
            [
                ['DELETE', '/hosting/web/arrimage.com/attachedDomain/mon-domaine.fr?bypassDNSConfiguration=true', []],
                ['DELETE', '/hosting/web/arrimage.com/attachedDomain/www.mon-domaine.fr?bypassDNSConfiguration=true', []],
            ],
            $calls
        );
        $this->assertSame([], domain_custom_load('boutique-a'));
    }

    public function testRemoveWithoutHostingAliasesNeverCallsOvh(): void
    {
        $this->dnsOk();
        $calls = [];
        $this->assertSame('', domain_custom_remove('boutique-a', $this->fakeApi($calls)));
        $this->assertSame([], $calls);
    }

    public function testRemoveStillDetachesWhenOvhRefusesAliasRemoval(): void
    {
        $this->dnsOk();
        $calls = [];
        domain_custom_request_activation('boutique-a', $this->fakeApi($calls), self::HOSTING);
        $previous = ini_set('error_log', sys_get_temp_dir() . '/brocs-domaines-custom-test.log');
        try {
            $warning = domain_custom_remove('boutique-a', static function (): never {
                throw new RuntimeException('HTTP 403');
            });
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString('retirez-les à la main', $warning);
        $this->assertSame([], domain_custom_load('boutique-a'), 'Le domaine est détaché même si les alias restent.');
    }

    public function testCheckRefusesWithoutAttachedDomain(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Aucun domaine');
        domain_custom_check('boutique-a', $this->lookup([]), []);
    }

    public function testCorruptFileIsReadAsEmptyState(): void
    {
        $previous = ini_set('error_log', sys_get_temp_dir() . '/brocs-domaines-custom-test.log');
        try {
            @mkdir(DOMAIN_CUSTOM_DATA_DIR, 0700, true);
            file_put_contents(DOMAIN_CUSTOM_DATA_DIR . '/boutique-a.json', '{cassé');
            $this->assertSame([], domain_custom_load('boutique-a'));
            $this->assertSame([], domain_custom_hosts());
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink(sys_get_temp_dir() . '/brocs-domaines-custom-test.log');
        }
    }
}
