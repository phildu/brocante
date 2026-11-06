<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/recommendations.php';

final class RecommendationTest extends TestCase
{
    private function ceramic(): array
    {
        return [
            'ref' => '201', 'name' => 'Grand vase céramique bleu brillant', 'description' => 'Vase en céramique émaillée',
            'cat' => 'ceramique', 'nature' => 'decoration', 'sous_categorie' => 'vases',
            'colors' => 'bleu marine, blanc', 'materials' => 'céramique émaillée, terre cuite',
            'price' => '45 €', 'size_text' => '30 cm', 'etat' => 'bon',
        ];
    }

    public function testTokensIgnoreAccentsAndGrammarWords(): void
    {
        $tokens = rec_text_tokens('Le Grand vase céramique bleu — très joli');
        self::assertContains('vase', $tokens);
        self::assertContains('ceramique', $tokens);
        self::assertContains('bleu', $tokens);
        self::assertNotContains('grand', $tokens);
        self::assertNotContains('tres', $tokens);
    }

    public function testValuesOverlapHandlesPhraseVariants(): void
    {
        self::assertSame(2, rec_values_overlap(['bleu marine', 'blanc'], ['bleu', 'blanc']));
        self::assertSame(0, rec_values_overlap(['rouge'], ['bleu']));
    }

    public function testSimilarProductScoresHigherThanUnrelatedOne(): void
    {
        $base = $this->ceramic();
        $close = array_merge($base, ['ref' => '202', 'name' => 'Vase céramique bleu émaillé', 'price' => '50 €']);
        $far = ['ref' => '189', 'name' => 'Malle ancienne en bois patiné', 'description' => '', 'cat' => 'bois', 'price' => '150 €'];

        $closeScore = rec_similarity_score($base, $close);
        $farScore = rec_similarity_score($base, $far);

        self::assertGreaterThan($farScore + 10, $closeScore);
    }

    public function testPriceSimilarityFallsToZeroBeyondThreeTimes(): void
    {
        self::assertSame(1.0, rec_price_similarity(4000, 4000));
        self::assertSame(0.0, rec_price_similarity(1000, 4000));
        self::assertSame(0.0, rec_price_similarity(null, 4000));
    }

    public function testHabitAffinityFavoursTheCeramicUniverse(): void
    {
        $profile = rec_empty_profile();
        $profile['has_signals'] = true;
        $profile['cats'] = ['ceramique' => 1.0];
        $profile['colors'] = ['bleu' => 1.0];
        $profile['materials'] = ['ceramique emailee' => 1.0];
        $profile['terms'] = ['vase' => 1.0];
        $profile['avg_price'] = 4500;

        $base = $this->ceramic();
        $wood = ['ref' => '189', 'name' => 'Malle ancienne en bois patiné', 'cat' => 'bois', 'price' => '150 €'];

        self::assertGreaterThan(rec_affinity_score($wood, $profile) + 5, rec_affinity_score($base, $profile));
    }

    public function testProfileWithoutSignalHasNoAffinity(): void
    {
        $profile = rec_empty_profile();
        self::assertSame(0.0, rec_affinity_score($this->ceramic(), $profile));
    }
}
