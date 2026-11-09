<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/edge-proxy.php';

// Requêtes passées par Cloudflare : IP du visiteur et nom de sous-domaine d'origine.
final class EdgeProxyTest extends TestCase
{
    private const CF_IP = '172.67.161.1';
    private const CF_IP6 = '2606:4700:3030::6815:f0d';

    public function testCloudflareRanges(): void
    {
        $this->assertTrue(edge_proxy_is_cloudflare(self::CF_IP));
        $this->assertTrue(edge_proxy_is_cloudflare('104.21.15.13'));
        $this->assertTrue(edge_proxy_is_cloudflare('162.159.1.1'));
        $this->assertTrue(edge_proxy_is_cloudflare(self::CF_IP6));
        $this->assertFalse(edge_proxy_is_cloudflare('213.186.33.3'));
        $this->assertFalse(edge_proxy_is_cloudflare('127.0.0.1'));
        $this->assertFalse(edge_proxy_is_cloudflare('2001:41d0:1:1b00:213:186:33:3'));
        $this->assertFalse(edge_proxy_is_cloudflare('pas-une-ip'));
        $this->assertFalse(edge_proxy_is_cloudflare(''));
    }

    public function testSubdomainRestoredFromCloudflare(): void
    {
        $o = edge_proxy_overrides([
            'REMOTE_ADDR' => self::CF_IP,
            'HTTP_HOST' => 'bricbrok.com',
            'HTTP_X_ORIG_HOST' => 'La-Friperie.bricbrok.com',
            'HTTP_CF_CONNECTING_IP' => '82.12.34.56',
        ]);
        $this->assertSame('la-friperie.bricbrok.com', $o['HTTP_HOST']);
        $this->assertSame('82.12.34.56', $o['REMOTE_ADDR']);
    }

    public function testWwwHostAcceptsSubdomainOfBase(): void
    {
        $o = edge_proxy_overrides([
            'REMOTE_ADDR' => self::CF_IP,
            'HTTP_HOST' => 'www.bricbrok.com',
            'HTTP_X_ORIG_HOST' => 'naty.bricbrok.com:443',
        ]);
        $this->assertSame('naty.bricbrok.com', $o['HTTP_HOST']);
    }

    public function testClientIpHeaderIgnoredOutsideCloudflare(): void
    {
        $o = edge_proxy_overrides([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'bricbrok.com',
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
        ]);
        $this->assertArrayNotHasKey('REMOTE_ADDR', $o);
    }

    public function testSubdomainRestoredWhenHostingHidesProxyIp(): void
    {
        // OVH renseigne REMOTE_ADDR avec l'IP du visiteur, pas celle de Cloudflare.
        $o = edge_proxy_overrides([
            'REMOTE_ADDR' => '2a01:e0a:1160:c90::1',
            'HTTP_HOST' => 'bricbrok.com',
            'HTTP_X_ORIG_HOST' => 'naty.bricbrok.com',
        ]);
        $this->assertSame('naty.bricbrok.com', $o['HTTP_HOST']);
        $this->assertArrayNotHasKey('REMOTE_ADDR', $o);
    }

    #[DataProvider('badOrigins')]
    public function testOtherDomainsRejected(string $orig): void
    {
        $o = edge_proxy_overrides([
            'REMOTE_ADDR' => self::CF_IP,
            'HTTP_HOST' => 'bricbrok.com',
            'HTTP_X_ORIG_HOST' => $orig,
        ]);
        $this->assertArrayNotHasKey('HTTP_HOST', $o);
    }

    /** @return array<string,array{string}> */
    public static function badOrigins(): array
    {
        return [
            'autre domaine' => ['naty.evil.com'],
            'suffixe trompeur' => ['naty.bricbrok.com.evil.com'],
            'deux niveaux' => ['a.b.bricbrok.com'],
            'domaine nu' => ['bricbrok.com'],
            'caractères interdits' => ['na ty.bricbrok.com'],
            'tiret en tête' => ['-naty.bricbrok.com'],
            'vide' => [''],
        ];
    }

    public function testInvalidClientIpKeepsRemoteAddr(): void
    {
        $o = edge_proxy_overrides([
            'REMOTE_ADDR' => self::CF_IP,
            'HTTP_HOST' => 'bricbrok.com',
            'HTTP_CF_CONNECTING_IP' => 'n\'importe quoi',
        ]);
        $this->assertArrayNotHasKey('REMOTE_ADDR', $o);
    }

    public function testNoProxyNoChange(): void
    {
        $this->assertSame([], edge_proxy_overrides(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'brocante.test']));
        $this->assertSame([], edge_proxy_overrides([]));
    }
}
