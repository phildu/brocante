<?php

use PHPUnit\Framework\TestCase;

defined('PLUGIN_RUNTIME') || define('PLUGIN_RUNTIME', true);
require_once dirname(__DIR__) . '/packs/annonces-marche/lib/mappings.php';

/** Correspondance « Univers » propre à une catégorie : transmise à l'extension sans toucher aux autres pièces. */
final class UniversMappingTest extends TestCase
{
    private function rows(): array
    {
        return ['leboncoin' => ['autre:univers' => ['paniers' => 'Maison']]];
    }

    public function testValueForTheProductCategoryIsSent(): void
    {
        $m = am_map_payload(['nature_label' => 'Paniers'], $this->rows());
        self::assertSame('Maison', $m['leboncoin']['autre']['univers']);
    }

    public function testOtherCategoriesAreNotForced(): void
    {
        self::assertSame([], am_map_payload(['nature_label' => 'Robes'], $this->rows()), 'une robe ne reçoit pas l’univers des paniers');
    }

    public function testCategoryValueBeatsFieldDefault(): void
    {
        $rows = ['leboncoin' => ['autre:univers' => ['*' => 'Femme', 'paniers' => 'Maison']]];
        self::assertSame('Maison', am_map_payload(['subcategory_label' => 'Paniers'], $rows)['leboncoin']['autre']['univers']);
        self::assertSame('Femme', am_map_payload(['nature_label' => 'Robes'], $rows)['leboncoin']['autre']['univers']);
    }
}
