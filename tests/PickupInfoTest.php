<?php

use PHPUnit\Framework\TestCase;

final class PickupInfoTest extends TestCase
{
    public function testDelayLabelIsEmptyWhenNotSet(): void
    {
        self::assertSame('', pickup_delay_label(0));
        self::assertSame('', pickup_delay_label(-10));
    }

    public function testDelayLabelFormatsMinutesHoursAndDays(): void
    {
        self::assertSame('5 min', pickup_delay_label(5));
        self::assertSame('1 h', pickup_delay_label(60));
        self::assertSame('1 h 30', pickup_delay_label(90));
        self::assertSame('2 h 05', pickup_delay_label(125));
        self::assertSame('1 jour', pickup_delay_label(1440));
        self::assertSame('3 jours', pickup_delay_label(3 * 1440));
    }

    public function testPickupLineIsEmptyWithoutDelay(): void
    {
        self::assertSame('', product_pickup_line_html(['pickup_delay_minutes' => 0]));
        self::assertSame('', product_pickup_line_html([]));
    }

    public function testPickupLineShowsTheDelay(): void
    {
        $html = product_pickup_line_html(['pickup_delay_minutes' => 15]);
        self::assertStringContainsString('Retrait sur place', $html);
        self::assertStringContainsString('Prêt au retrait sous 15 min', $html);
    }

    public function testPickupLineAppearsOnAnAvailableProduct(): void
    {
        $html = product_price_extras_html([
            'ref' => '001', 'name' => 'Vase', 'price' => '20 €', 'stock' => 1,
            'pickup_delay_minutes' => 30,
        ]);
        self::assertStringContainsString('Prêt au retrait sous 30 min', $html);
    }

    public function testPickupLineIsHiddenOnASoldProduct(): void
    {
        $html = product_price_extras_html([
            'ref' => '001', 'name' => 'Vase', 'price' => '20 €', 'stock' => 0,
            'pickup_delay_minutes' => 30,
        ]);
        self::assertSame('', $html);
    }

    public function testStorageLocationIsNeverShownToTheCustomer(): void
    {
        $product = [
            'ref' => '001', 'name' => 'Vase', 'price' => '20 €', 'stock' => 1,
            'storage_location' => 'Carton 12 · étagère B',
            'pickup_delay_minutes' => 10,
        ];
        self::assertStringNotContainsString('Carton 12', product_pickup_line_html($product));
        self::assertStringNotContainsString('Carton 12', product_price_extras_html($product));
    }

    public function testStorageLocationAloneAddsNothingToThePublicSheet(): void
    {
        self::assertSame('', product_price_extras_html([
            'ref' => '001', 'name' => 'Vase', 'price' => '20 €', 'stock' => 1,
            'storage_location' => 'Carton 12 · étagère B',
        ]));
    }
}
