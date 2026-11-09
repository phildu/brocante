<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/pos-express.php';

/**
 * Vente express en caisse : prix convenu sur place, mode d'encaissement pour le journal,
 * vendeur et avertissement du gérant, messages de l'expert. Aucune base, aucun réseau.
 */
final class VenteExpressTest extends TestCase
{
    #[DataProvider('catalogPrices')]
    public function testCatalogPriceIsOnlyAUniqueAmount(string $price, ?int $expected): void
    {
        self::assertSame($expected, pos_catalog_cents(['price' => $price, 'badge' => '']));
    }

    public static function catalogPrices(): array
    {
        return [
            'prix unique' => ['25 €', 2500],
            'avec centimes' => ['12,50 €', 1250],
            'vide : à négocier' => ['', null],
            'zéro : à négocier' => ['0 €', null],
            'fourchette' => ['25-35 €', null],
            'à partir de' => ['dès 12 €', null],
            'sur devis' => ['sur devis', null],
        ];
    }

    public function testPromoPriceIsTheCatalogPriceWhenActive(): void
    {
        self::assertSame(1800, pos_catalog_cents(['price' => '25 €', 'badge' => 'Promo', 'promo_price' => '18 €']));
    }

    public function testPayModeSeparatesCashCardAndOnline(): void
    {
        self::assertSame('especes', pos_pay_mode(['shipping_method' => 'Vente en boutique — espèces']));
        self::assertSame('carte', pos_pay_mode(['shipping_method' => 'Vente en boutique — carte']));
        self::assertSame('carte', pos_pay_mode(['shipping_method' => 'Vente en boutique — carte (terminal SumUp)']));
        self::assertSame('en_ligne', pos_pay_mode(['shipping_method' => 'Colissimo']));
        self::assertSame('en_ligne', pos_pay_mode([]));
        self::assertSame('Espèces', pos_pay_mode_label('especes'));
        self::assertSame('En ligne', pos_pay_mode_label('inconnu'));
    }

    public function testManagerIsOnlyWarnedForAnotherSeller(): void
    {
        self::assertSame([null, null, 0], pos_seller_fields(null));
        // Compte principal du commerce : c'est le gérant.
        self::assertSame(['Gérant', null, 0], pos_seller_fields(['id' => null, 'role' => 'admin', 'name' => '']));
        // Autre administrateur : pas d'avertissement (c'est un gérant).
        self::assertSame(['Paul', 3, 0], pos_seller_fields(['id' => 3, 'role' => 'admin', 'name' => 'Paul']));
        // Vendeur de la caisse : le gérant est prévenu.
        self::assertSame(['Marie', 7, 1], pos_seller_fields(['id' => 7, 'role' => 'vendeur', 'name' => 'Marie']));
        self::assertSame(['Vendeur', 8, 1], pos_seller_fields(['id' => 8, 'role' => 'vendeur', 'name' => '']));
    }

    public function testManagerMessageListsSellerLinesAndNegotiatedPrice(): void
    {
        $order = [
            'id' => 12, 'sold_by' => 'Marie', 'amount_total' => 4500, 'shipping_method' => 'Vente en boutique — espèces',
            'items' => json_encode([
                ['ref' => 'EXP1', 'name' => 'Lampe en laiton', 'qty' => 1, 'unit_cents' => 3500, 'negotiated' => true, 'express' => true],
                ['ref' => '014', 'name' => 'Vase', 'qty' => 1, 'unit_cents' => 1000],
            ], JSON_UNESCAPED_UNICODE),
        ];
        [$subject, $body] = pos_manager_message($order, 'Chez Naty');
        self::assertSame('Vente de 45,00 € par Marie — Lampe en laiton (+1)', $subject);
        self::assertStringContainsString('Marie vient d\'enregistrer une vente en caisse (Chez Naty)', $body);
        self::assertStringContainsString('• Lampe en laiton — 35,00 € · pièce hors catalogue · prix convenu sur place', $body);
        self::assertStringContainsString('• Vase — 10,00 €' . "\n", $body);
        self::assertStringContainsString('Total : 45,00 € · espèces', $body);
        self::assertStringContainsString('journal des ventes', $body);
    }

    public function testAppraisalMessageCarriesPhotoSuggestionAndAnswerLink(): void
    {
        $a = ['seller_name' => 'Léa', 'ai_hint' => '25-35 €', 'note' => 'Signée au dos ?'];
        $p = ['name' => 'Théière en grès', 'description' => 'Théière ancienne, légère égrenure.'];
        [$subject, $body] = pos_appraisal_message($a, $p, 'https://exemple.test/avis-expertise.php?t=abc', 'https://exemple.test/uploads/p.jpg');
        self::assertSame('Avis demandé par Léa — Théière en grès', $subject);
        self::assertStringContainsString('Suggestion automatique (à titre indicatif) : 25-35 €', $body);
        self::assertStringContainsString('Mot du vendeur : Signée au dos ?', $body);
        self::assertStringContainsString('Photo : https://exemple.test/uploads/p.jpg', $body);
        self::assertStringContainsString('https://exemple.test/avis-expertise.php?t=abc', $body);
    }

    public function testAppraisalMessageWorksWithoutAiHintNorNote(): void
    {
        [, $body] = pos_appraisal_message(['seller_name' => ''], ['name' => ''], 'https://exemple.test/a', '');
        self::assertStringContainsString('Un vendeur vous demande d\'estimer une pièce', $body);
        self::assertStringNotContainsString('Suggestion automatique', $body);
        self::assertStringNotContainsString('Photo :', $body);
    }

    public function testAppraisalTokenMustBe32HexCharacters(): void
    {
        self::assertNull(pos_appraisal_by_token('../../etc/passwd'));
        self::assertNull(pos_appraisal_by_token(''));
        self::assertNull(pos_appraisal_by_token(str_repeat('g', 32)));
    }
}
