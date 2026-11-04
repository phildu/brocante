<?php

use PHPUnit\Framework\TestCase;

final class RestockTest extends TestCase
{
    public function testLineIsEmptyWhenRestockIsNotEnabled(): void
    {
        self::assertSame('', product_restock_line_html(['restock_possible' => 0]));
        self::assertSame('', product_restock_line_html([]));
    }

    public function testLineShowsDelayAndPrice(): void
    {
        $html = product_restock_line_html([
            'restock_possible' => 1,
            'restock_delay' => '10 jours',
            'restock_price' => '25 €',
        ]);
        self::assertStringContainsString('Réassort possible', $html);
        self::assertStringContainsString('Délai : 10 jours', $html);
        self::assertStringContainsString('Prix : 25 €', $html);
    }

    public function testLineWorksWithoutDelayOrPrice(): void
    {
        $html = product_restock_line_html(['restock_possible' => 1]);
        self::assertStringContainsString('Réassort possible', $html);
        self::assertStringNotContainsString('Délai', $html);
        self::assertStringNotContainsString('Prix', $html);
    }

    public function testDelayIsEscaped(): void
    {
        $html = product_restock_line_html(['restock_possible' => 1, 'restock_delay' => '<script>alert(1)</script>']);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testLineIsShownOnASoldProduct(): void
    {
        $html = product_price_extras_html([
            'ref' => '001', 'name' => 'Vase', 'price' => '20 €', 'stock' => 0,
            'restock_possible' => 1, 'restock_delay' => '2 semaines', 'restock_price' => '30 €',
        ]);
        self::assertStringContainsString('Réassort possible', $html);
        self::assertStringContainsString('Délai : 2 semaines', $html);
        self::assertStringContainsString('Prix : 30 €', $html);
    }
}
