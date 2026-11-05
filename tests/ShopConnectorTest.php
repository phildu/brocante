<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/shop-connectors.php';

/**
 * Connecteurs catalogue : conversion des prix, lecture des réponses Shopify / WooCommerce / PrestaShop.
 * Aucune requête réseau : seuls les mappers et helpers purs sont exercés.
 */
final class ShopConnectorTest extends TestCase
{
    public function testMoneyFormatting(): void
    {
        self::assertSame('', connector_money(null));
        self::assertSame('35 €', connector_money(3500));
        self::assertSame('12,50 €', connector_money(1250));
        self::assertSame('1 250,50 €', connector_money(125050));
        self::assertSame('35 USD', connector_money(3500, 'USD'));
    }

    public function testCentsFromDecimalAmounts(): void
    {
        self::assertNull(connector_cents(null));
        self::assertNull(connector_cents(''));
        self::assertNull(connector_cents('sur devis'));
        self::assertSame(1250, connector_cents('12.50'));
        self::assertSame(1250, connector_cents('12,5 €'));
        self::assertSame(125050, connector_cents('1 250,50 €'));
        self::assertSame(1250, connector_cents(12.5));
        self::assertSame(1235, connector_cents('12.345')); // arrondi au centime
    }

    public function testWooCentsRespectsMinorUnit(): void
    {
        self::assertNull(connector_woo_cents(null));
        self::assertNull(connector_woo_cents(''));
        self::assertNull(connector_woo_cents('abc'));
        self::assertSame(1250, connector_woo_cents('1250'));
        self::assertSame(125000, connector_woo_cents('1250', 0));
        self::assertSame(125, connector_woo_cents('1250', 3));
    }

    public function testShopifyMappingKeepsPromoAndCheapestVariant(): void
    {
        $payload = ['products' => [[
            'id' => 1001,
            'title' => ' Fauteuil chiné ',
            'handle' => 'fauteuil-chine',
            'product_type' => 'Décoration',
            'body_html' => '<p>Fauteuil <strong>années 50</strong>.</p>',
            'created_at' => '2024-03-01T10:00:00Z',
            'variants' => [
                ['price' => '90.00', 'compare_at_price' => '120.00'],
                ['price' => '95.00', 'compare_at_price' => null],
            ],
            'images' => [['src' => 'https://cdn.shop.test/fauteuil.jpg']],
        ]]];
        $cat = connector_map_shopify($payload, 'https://shop.test', ['name' => 'Atelier Test', 'tagline' => 'Chine', 'accent' => '#B5502E']);

        self::assertSame('Atelier Test', $cat['shop']['name']);
        self::assertSame('#b5502e', $cat['shop']['accent']);
        self::assertSame(['Décoration'], $cat['shop']['universes']);
        self::assertCount(1, $cat['products']);
        $p = $cat['products'][0];
        self::assertSame('shopify-1001', $p['ref']);
        self::assertSame('Fauteuil chiné', $p['name']);
        self::assertSame('120 €', $p['price']);
        self::assertSame('90 €', $p['promo_price']);
        self::assertSame(9000, $p['price_cents']);
        self::assertSame('Promo', $p['badge']);
        self::assertSame('https://shop.test/products/fauteuil-chine', $p['url']);
        self::assertSame('https://cdn.shop.test/fauteuil.jpg', $p['photo']);
        self::assertSame('Fauteuil années 50.', $p['description']);
    }

    public function testShopifySkipsProductsWithoutPrice(): void
    {
        $cat = connector_map_shopify(['products' => [
            ['id' => 1, 'title' => 'Sans prix', 'variants' => []],
            ['id' => 2, 'title' => '   ', 'variants' => [['price' => '10.00']]],
            ['id' => 3, 'title' => 'Bon', 'variants' => [['price' => '10.00']]],
        ]], 'https://shop.test');
        self::assertCount(1, $cat['products']);
        self::assertSame('Bon', $cat['products'][0]['name']);
    }

    public function testWooCommerceMapping(): void
    {
        $payload = [
            [
                'id' => 5,
                'name' => 'Vase &amp; fleurs',
                'stock_status' => 'instock',
                'permalink' => 'https://shop.test/p/5',
                'short_description' => '<p>Beau vase</p>',
                'date_created' => '2024-02-01T08:00:00',
                'prices' => [
                    'price' => '1500', 'regular_price' => '2000', 'sale_price' => '1500',
                    'currency_minor_unit' => 2, 'currency_code' => 'EUR',
                ],
                'categories' => [['name' => 'Maison']],
                'images' => [['src' => 'https://cdn.shop.test/vase.jpg']],
            ],
            [
                'id' => 6,
                'name' => 'Vendu',
                'stock_status' => 'outofstock',
                'prices' => ['regular_price' => '1000'],
            ],
        ];
        $cat = connector_map_woocommerce($payload, 'https://shop.test');
        self::assertCount(1, $cat['products']);
        $p = $cat['products'][0];
        self::assertSame('woo-5', $p['ref']);
        self::assertSame('Vase & fleurs', $p['name']);
        self::assertSame('20 €', $p['price']);
        self::assertSame('15 €', $p['promo_price']);
        self::assertSame(1500, $p['price_cents']);
        self::assertSame('Maison', $p['cat_label']);
        self::assertSame(['Maison'], $cat['shop']['universes']);
        self::assertSame('https://shop.test/p/5', $p['url']);
    }

    public function testPrestaShopMapping(): void
    {
        $payload = ['products' => ['product' => [[
            'id' => 12,
            'active' => '1',
            'name' => [['id' => '1', 'value' => 'Lampe de bureau']],
            'final_price' => '29.90',
            'category_default' => 'Lumière',
            'link_rewrite' => [['id' => '1', 'value' => 'lampe-de-bureau']],
            'id_default_image' => '7',
            'description_short' => [['id' => '1', 'value' => '<p>Belle lampe</p>']],
            'date_add' => '2024-01-02 10:00:00',
        ]]]];
        $cat = connector_map_prestashop($payload, 'https://shop.test');
        self::assertCount(1, $cat['products']);
        $p = $cat['products'][0];
        self::assertSame('presta-12', $p['ref']);
        self::assertSame('Lampe de bureau', $p['name']);
        self::assertSame('29,90 €', $p['price']);
        self::assertSame(2990, $p['price_cents']);
        self::assertSame('https://shop.test/12-7-home_default/lampe-de-bureau.jpg', $p['photo']);
        self::assertSame('https://shop.test/12-lampe-de-bureau.html', $p['url']);
        self::assertSame('Belle lampe', $p['description']);
    }

    public function testPrestaShopValueAndInactiveProducts(): void
    {
        self::assertSame('Texte', connector_ps_value('Texte'));
        self::assertSame('Premier', connector_ps_value([['id' => '1', 'value' => 'Premier'], ['id' => '2', 'value' => 'Second']]));
        self::assertSame('', connector_ps_value([]));

        $cat = connector_map_prestashop(['products' => [
            ['id' => 1, 'active' => '0', 'name' => 'Caché', 'final_price' => '10.00'],
            ['id' => 2, 'active' => '1', 'name' => 'Visible', 'final_price' => '10.00'],
        ]], 'https://shop.test');
        self::assertCount(1, $cat['products']);
        self::assertSame('Visible', $cat['products'][0]['name']);
    }

    public function testDetectionUsesProfileOrHost(): void
    {
        self::assertSame('woocommerce', connector_detect('https://shop.test', ['platform' => 'woocommerce']));
        self::assertSame('shopify', connector_detect('https://atelier.myshopify.com'));
        self::assertSame('', connector_detect('https://shop.test', ['platform' => 'wix']));
    }

    public function testCatalogueShellAndKeys(): void
    {
        $shell = connector_catalogue_shell(['name' => 'Test', 'url' => 'https://shop.test']);
        self::assertSame('Test', $shell['shop']['name']);
        self::assertSame([], $shell['products']);
        self::assertTrue($shell['shop']['sales_enabled']);
        self::assertSame(connector_key('https://Shop.test/'), connector_key('https://shop.test'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', connector_key('https://shop.test'));
        self::assertSame('https://shop.test', connector_origin('https://shop.test/boutique?x=1'));
    }
}