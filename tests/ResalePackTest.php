<?php

use PHPUnit\Framework\TestCase;

final class ResalePackTest extends TestCase
{
    private function product(array $over = []): array
    {
        return $over + [
            'ref' => '201', 'name' => 'Théière en grès', 'price' => '25 €', 'description' => 'Belle théière des années 70.',
            'brand' => 'Digoin', 'size_text' => 'H 18 cm', 'materials' => 'grès émaillé', 'colors' => 'bleu', 'etat' => 'bon', 'weight_grams' => 850, 'cat' => '',
        ];
    }

    public function testVintedTitleStartsWithBrand(): void
    {
        $t = resale_pack_text($this->product(), 'vinted');
        self::assertSame('Digoin Théière en grès', $t['title']);
    }

    public function testVintedTitleAddsClothingSizeOnly(): void
    {
        self::assertSame('Levi\'s Jean slim - taille 38', resale_pack_text($this->product(['name' => 'Jean slim', 'brand' => 'Levi\'s', 'size_text' => '38']), 'vinted')['title']);
        self::assertSame('Digoin Théière en grès', resale_pack_text($this->product(['size_text' => 'H 18 cm']), 'vinted')['title']);
    }

    public function testBrandNotDuplicatedWhenAlreadyInName(): void
    {
        $t = resale_pack_text($this->product(['name' => 'Théière Digoin grès']), 'leboncoin');
        self::assertSame('Théière Digoin grès', $t['title']);
    }

    public function testTitleRespectsPlatformLimit(): void
    {
        $long = str_repeat('Théière ancienne ', 10);
        self::assertLessThanOrEqual(70, mb_strlen(resale_pack_text($this->product(['name' => $long]), 'leboncoin')['title']));
        self::assertLessThanOrEqual(100, mb_strlen(resale_pack_text($this->product(['name' => $long]), 'vinted')['title']));
    }

    public function testConditionVocabularyPerPlatform(): void
    {
        self::assertSame('Bon état', resale_pack_condition('bon', 'vinted'));
        self::assertSame('Neuf sans étiquette', resale_pack_condition('neuf_boite', 'vinted'));
        self::assertSame('Satisfaisant', resale_pack_condition('usage', 'vinted'));
        self::assertSame('Neuf', resale_pack_condition('neuf_boite', 'leboncoin'));
        self::assertSame('Pour pièces', resale_pack_condition('pieces', 'leboncoin'));
        self::assertSame('', resale_pack_condition('', 'vinted'));
    }

    public function testPriceOnlyForSingleAmount(): void
    {
        self::assertSame('25', resale_pack_price('25 €'));
        self::assertSame('25,5', resale_pack_price('25,50 €'));
        self::assertSame('', resale_pack_price('sur devis'));
        self::assertSame('', resale_pack_price('25-35 €'));
    }

    public function testDescriptionKeepsFactSheetAndCapsLength(): void
    {
        $t = resale_pack_text($this->product(), 'vinted');
        self::assertStringContainsString("Marque : Digoin", $t['description']);
        self::assertStringContainsString("Poids : 850 g", $t['description']);
        $big = resale_pack_text($this->product(['description' => str_repeat('mot ', 1500)]), 'vinted');
        self::assertLessThanOrEqual(2000, mb_strlen($big['description']));
        self::assertStringContainsString('Marque : Digoin', $big['description']);
    }

    public function testPostedFieldsOverrideSavedProduct(): void
    {
        $p = resale_pack_product($this->product(), ['name' => ' Nouveau nom ', 'weight_grams' => '1500', 'ref' => '999']);
        self::assertSame('Nouveau nom', $p['name']);
        self::assertSame(1500, $p['weight_grams']);
        self::assertSame('201', $p['ref']);
    }

    public function testPhotoFileNames(): void
    {
        self::assertSame('01-photo-principale.jpg', resale_pack_photo_name(1, 'Photo principale', 'uploads/x.JPG'));
        self::assertSame('03-profil-droit.png', resale_pack_photo_name(3, 'Profil droit', 'uploads/y.png'));
    }
}
