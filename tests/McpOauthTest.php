<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/mcp-oauth.php';

/** OAuth du serveur MCP : validations pures (adresses de retour, PKCE) et documents de découverte. */
final class McpOauthTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('redirects')]
    public function testRedirectUriRules(string $uri, bool $ok): void
    {
        self::assertSame($ok, mcp_oauth_redirect_uri_ok($uri));
    }

    public static function redirects(): array
    {
        return [
            ['https://claude.ai/api/mcp/auth_callback', true],
            ['http://localhost:33418/callback', true],
            ['http://127.0.0.1:8080/cb', true],
            ['http://example.com/cb', false],          // http hors machine locale
            ['https://user:pw@example.com/cb', false],  // identifiants dans l'adresse
            ['https://example.com/cb#frag', false],     // fragment
            ['javascript:alert(1)', false],
            ['', false],
            ['https://example.com/a b', false],
        ];
    }

    public function testPkceS256(): void
    {
        $verifier = str_repeat('a', 43) . '-._~';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        self::assertTrue(mcp_oauth_challenge_ok($challenge));
        self::assertTrue(mcp_oauth_pkce_ok($verifier, $challenge));
        self::assertFalse(mcp_oauth_pkce_ok($verifier . 'x', $challenge));
        self::assertFalse(mcp_oauth_pkce_ok('court', $challenge));          // verifier trop court
        self::assertFalse(mcp_oauth_pkce_ok($verifier, str_repeat('A', 43)));
        self::assertFalse(mcp_oauth_challenge_ok('trop-court'));
    }

    public function testDiscoveryDocumentsPointToTheShop(): void
    {
        $_SERVER['HTTP_HOST'] = 'pre.example.test';
        $_SERVER['HTTPS'] = 'on';
        $GLOBALS['mcp_oauth_base'] = '/ma-boutique';
        try {
            self::assertSame('https://pre.example.test/ma-boutique/mcp.php', mcp_oauth_resource_url());
            $server = mcp_oauth_server_document();
            self::assertSame('https://pre.example.test/ma-boutique', $server['issuer']);
            self::assertSame('https://pre.example.test/ma-boutique/mcp-token.php', $server['token_endpoint']);
            self::assertSame(['S256'], $server['code_challenge_methods_supported']);
            self::assertSame(['none'], $server['token_endpoint_auth_methods_supported']);
            self::assertSame('https://pre.example.test/ma-boutique/mcp-oauth-meta.php?doc=resource', mcp_oauth_resource_metadata_url());
        } finally {
            unset($GLOBALS['mcp_oauth_base'], $_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
        }
    }

    public function testOAuthTokenPrefixesNeverMatchTheStaticToken(): void
    {
        self::assertFalse(preg_match('/^bbmcp_[a-f0-9]{48}$/', MCP_OAUTH_ACCESS_PREFIX . str_repeat('a', 48)) === 1);
        self::assertFalse(mcp_oauth_access_valid('bbmcp_' . str_repeat('a', 48)));
    }
}
