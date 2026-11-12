<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/mcp.php';

/** Serveur MCP : validation des champs (helpers purs, sans base) et protocole JSON-RPC sans écriture. */
final class McpTest extends TestCase
{
    public function testPriceNormalisation(): void
    {
        self::assertSame('25 €', mcp_price('25'));
        self::assertSame('25,50 €', mcp_price('25,5'));
        self::assertSame('25,50 €', mcp_price('25.50 €'));
        self::assertSame('', mcp_price('', true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPrices')]
    public function testPriceRefusals(string $raw): void
    {
        $this->expectException(McpToolError::class);
        mcp_price($raw);
    }

    public static function badPrices(): array
    {
        return [[''], ['abc'], ['-5'], ['25-35 €'], ['99999999999']];
    }

    public function testScalarValidators(): void
    {
        self::assertSame(1, mcp_bool(true));
        self::assertSame(0, mcp_bool('false'));
        self::assertSame(7, mcp_int('7', 0, 10, 'x'));
        $this->expectException(McpToolError::class);
        mcp_int(11, 0, 10, 'x');
    }

    public function testUnknownProductFieldIsRefused(): void
    {
        $this->expectException(McpToolError::class);
        $this->expectExceptionMessageMatches('/non modifiable/');
        mcp_product_clean(['cost_cents' => 1]);
    }

    public function testPromoMustBeBelowPriceAndSetsBadge(): void
    {
        $out = mcp_product_clean(['promo_price' => '20'], ['price' => '30 €', 'badge' => 'Chiné']);
        self::assertSame('20 €', $out['promo_price']);
        self::assertSame('Promo', $out['badge']);

        $this->expectException(McpToolError::class);
        mcp_product_clean(['promo_price' => '40'], ['price' => '30 €']);
    }

    public function testRemovingPromoClearsPromoBadge(): void
    {
        $out = mcp_product_clean(['promo_price' => ''], ['price' => '30 €', 'badge' => 'Promo', 'promo_price' => '20 €']);
        self::assertNull($out['promo_price']);
        self::assertSame('', $out['badge']);
    }

    public function testLoweringPriceBelowPromoIsRefused(): void
    {
        $this->expectException(McpToolError::class);
        mcp_product_clean(['price' => '15'], ['price' => '30 €', 'promo_price' => '20 €']);
    }

    public function testProtocolWithoutDatabase(): void
    {
        $init = mcp_handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
        self::assertSame('2025-03-26', $init['result']['protocolVersion']);
        self::assertNull(mcp_handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertSame(-32601, mcp_handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope'])['error']['code']);
        self::assertSame(-32602, mcp_handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'drop_database']])['error']['code']);
    }

    public function testNoDestructiveToolIsExposed(): void
    {
        $names = array_keys(mcp_tools());
        foreach ($names as $n) self::assertDoesNotMatchRegularExpression('/delete|drop|remove|purge/', $n);
        // Les outils d'écriture sont tous déclarés comme tels (pour la sauvegarde et le journal).
        foreach (['product_update', 'product_create', 'content_update', 'setting_set', 'discount_add', 'discount_set_active', 'trash_item', 'trash_restore',
            'shipping_carrier_add', 'shipping_carrier_set', 'shipping_rate_add', 'shipping_rate_update', 'order_set_fulfillment', 'product_photo_add', 'product_photo_update'] as $w) {
            self::assertTrue(mcp_tools()[$w]['write'], $w);
        }
        foreach (['orders_list', 'order_get', 'products_list', 'product_get', 'audit_log', 'trash_list', 'shipping_get', 'media_list', 'product_photos_get'] as $r) {
            self::assertFalse(mcp_tools()[$r]['write'], $r);
        }
    }

    public function testSensitiveActionsAreNotExposed(): void
    {
        // Remboursement, annulation, comptes, clés de paiement : administration uniquement.
        foreach (array_keys(mcp_tools()) as $n) self::assertDoesNotMatchRegularExpression('/refund|cancel|account|password|stripe|key|secret/i', $n);
        self::assertSame(['kind', 'id'], mcp_tools()['trash_item']['schema']['required']);
        // Les champs produit modifiables n'incluent ni photo ni identifiants externes.
        self::assertArrayNotHasKey('photo', mcp_product_fields());
        self::assertArrayHasKey('cost_price', mcp_product_fields());
    }

    public function testNoSecretSettingIsWritable(): void
    {
        foreach (array_keys(mcp_setting_keys()) as $k) self::assertDoesNotMatchRegularExpression('/key|secret|token|password|stripe|mcp/i', $k);
    }
}
