<?php

use PHPUnit\Framework\TestCase;

// Journal IA (fournisseur) et notation des résultats par les testeurs : base SQLite en mémoire,
// aucun fichier de boutique touché.
final class AiRatingTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ai_usage_ensure_schema($this->pdo);
        ai_rating_ensure_schema($this->pdo);
    }

    private function usage(string $kind, string $model, float $cost = 0.01, string $provider = '', ?string $at = null): int
    {
        $this->pdo->prepare('INSERT INTO ai_usage (at, kind, provider, model, cost_usd, script, ref) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$at ?? gmdate('Y-m-d H:i:s'), $kind, $provider, $model, $cost, 'test.php', 'REF-' . bin2hex(random_bytes(2))]);
        return (int) $this->pdo->lastInsertId();
    }

    private function tester(string $key, string $name = 'Testeur'): array
    {
        return ['key' => $key, 'name' => $name, 'id' => null];
    }

    public function testProviderDerivedFromModel(): void
    {
        self::assertSame('Anthropic Claude', ai_usage_provider('claude-sonnet-4'));
        self::assertSame('Google Gemini', ai_usage_provider('gemini-2.5-flash'));
        self::assertSame('Google Gemini', ai_usage_provider('', 'image'));
        self::assertSame('Google Veo', ai_usage_provider('veo-3.1-fast'));
        self::assertSame('SiliconFlow', ai_usage_provider('wan-2.2'));
        self::assertSame('Modal', ai_usage_provider('rembg-modal'));
        self::assertSame('fal.ai', ai_usage_provider('rembg'));
        self::assertSame('OpenAI', ai_usage_provider('gpt-4o'));
        self::assertSame('Mistral AI', ai_usage_provider('mistral-large'));
        self::assertSame('Autre', ai_usage_provider('modele-inconnu'));
        self::assertSame('Non précisé', ai_usage_provider('', 'text'));
    }

    public function testLegacyLedgerGetsProviderBackfilled(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE ai_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            kind TEXT NOT NULL, model TEXT NOT NULL DEFAULT '', tokens_in INTEGER NOT NULL DEFAULT 0, tokens_out INTEGER NOT NULL DEFAULT 0,
            cost_usd REAL NOT NULL DEFAULT 0, script TEXT NOT NULL DEFAULT '', ref TEXT)");
        $pdo->exec("INSERT INTO ai_usage (kind, model, cost_usd) VALUES ('text', 'gemini-2.5-flash', 0.002), ('video', 'veo-3.1-fast', 0.6), ('cutout', 'rembg', 0.001)");

        ai_usage_ensure_schema($pdo);

        $columns = $pdo->query('PRAGMA table_info(ai_usage)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('provider', $columns);
        $providers = $pdo->query('SELECT kind, provider FROM ai_usage ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('Google Gemini', $providers['text']);
        self::assertSame('Google Veo', $providers['video']);
        self::assertSame('fal.ai', $providers['cutout']);

        // Deuxième appel : la migration est idempotente et ne réécrit pas les fournisseurs explicites.
        $pdo->exec("UPDATE ai_usage SET provider = 'Maison' WHERE kind = 'text'");
        ai_usage_ensure_schema($pdo);
        self::assertSame('Maison', $pdo->query("SELECT provider FROM ai_usage WHERE kind = 'text'")->fetchColumn());
    }

    public function testSaveAndUpsertPerTester(): void
    {
        $usageId = $this->usage('text', 'gemini-2.5-flash');
        $a = $this->tester('acct:1', 'Alice');
        $b = $this->tester('acct:2', 'Bruno');

        $first = ai_rating_save($this->pdo, $usageId, 3, 'Cadrage moyen', $a);
        self::assertSame(3, (int) $first['stars']);
        self::assertSame('Google Gemini', $first['provider'], 'Le fournisseur vient du journal IA.');
        self::assertSame('text', $first['kind']);

        $again = ai_rating_save($this->pdo, $usageId, 5, 'Finalement très bon', $a);
        self::assertSame(5, (int) $again['stars']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_ratings')->fetchColumn(), 'Un testeur ne crée pas de doublon sur le même résultat.');

        ai_rating_save($this->pdo, $usageId, 4, '', $b);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_ratings')->fetchColumn(), 'Deux testeurs notent le même résultat.');

        self::assertNull(ai_rating_save($this->pdo, $usageId, 0, '', $a));
        self::assertNull(ai_rating_save($this->pdo, $usageId, 6, '', $a));
        self::assertNull(ai_rating_save($this->pdo, 9999, 5, '', $a));
    }

    public function testCommentIsTrimmedAndCapped(): void
    {
        $usageId = $this->usage('image', 'gemini-2.5-flash-image');
        $row = ai_rating_save($this->pdo, $usageId, 4, '   ' . str_repeat('fondu ', 120), $this->tester('main'));
        self::assertSame(500, mb_strlen($row['comment']));
        self::assertSame('fondu', mb_substr($row['comment'], 0, 5));
    }

    public function testProviderIsInferredForOldUsageRows(): void
    {
        $usageId = $this->usage('video', 'wan-2.2', 0.3, '');
        $row = ai_rating_save($this->pdo, $usageId, 2, '', $this->tester('main'));
        self::assertSame('SiliconFlow', $row['provider']);
    }

    public function testStatsEffectivenessAndCoverage(): void
    {
        $g1 = $this->usage('text', 'gemini-2.5-flash', 0.01, 'Google Gemini');
        $g2 = $this->usage('text', 'gemini-2.5-flash', 0.01, 'Google Gemini');
        $c1 = $this->usage('video', 'claude-sonnet-4', 0.2, 'Anthropic Claude');
        $this->usage('search', 'gemini-2.5-flash', 0.03, 'Google Gemini');

        ai_rating_save($this->pdo, $g1, 5, '', $this->tester('acct:1'));
        ai_rating_save($this->pdo, $g2, 4, '', $this->tester('acct:2'));
        ai_rating_save($this->pdo, $c1, 2, '', $this->tester('acct:1'));

        $stats = ai_rating_stats($this->pdo);
        self::assertSame(3, $stats['n']);
        self::assertEqualsWithDelta(3.67, $stats['avg'], 0.01);
        self::assertSame(2, $stats['effective']);
        self::assertEqualsWithDelta(2 / 3, $stats['rate'], 1e-6);
        self::assertSame(3, $stats['rated']);
        self::assertSame(4, $stats['calls']);
        self::assertEqualsWithDelta(0.75, $stats['coverage'], 1e-6);
        self::assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 1, 5 => 1], $stats['distribution']);

        self::assertSame(2, $stats['by_provider']['Google Gemini']['n']);
        self::assertEqualsWithDelta(1.0, $stats['by_provider']['Google Gemini']['rate'], 1e-6);
        self::assertEqualsWithDelta(0.0, $stats['by_provider']['Anthropic Claude']['rate'], 1e-6);
        self::assertSame(2, $stats['by_kind']['text']['n']);
        self::assertSame(1, $stats['by_kind']['video']['n']);
    }

    public function testStatsWithoutRatings(): void
    {
        $stats = ai_rating_stats($this->pdo);
        self::assertSame(0, $stats['n']);
        self::assertNull($stats['avg']);
        self::assertNull($stats['rate']);
        self::assertNull($stats['coverage']);
        self::assertSame(0, $stats['calls']);
        self::assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], $stats['distribution']);
        self::assertSame([], $stats['by_provider']);
        self::assertSame('—', ai_rating_rate_label($stats['rate']));
    }

    public function testPendingUsageOnlyInsideWindowAndUnrated(): void
    {
        $old = $this->usage('text', 'gemini-2.5-flash', 0.01, '', gmdate('Y-m-d H:i:s', time() - 13 * 3600));
        $fresh = $this->usage('image', 'gemini-2.5-flash-image', 0.04);

        $tester = 'acct:1';
        $pending = ai_rating_pending_usage($this->pdo, $tester);
        self::assertSame($fresh, (int) $pending['id']);

        ai_rating_save($this->pdo, $fresh, 4, '', $this->tester($tester));
        self::assertSame($fresh, (int) ai_rating_pending_usage($this->pdo, 'acct:2')['id'], 'Chaque testeur voit le dernier résultat qu\'il n\'a pas encore noté.');
        self::assertNull(ai_rating_pending_usage($this->pdo, $tester), 'Un résultat déjà noté n\'est plus proposé.');

        ai_rating_save($this->pdo, $fresh, 3, '', $this->tester('acct:2'));
        self::assertNull(ai_rating_pending_usage($this->pdo, 'acct:2', 12), 'L\'appel de 13 h est hors de la fenêtre de 12 h.');
        self::assertSame($old, (int) ai_rating_pending_usage($this->pdo, 'acct:2', 24)['id'], 'Une fenêtre plus large le retrouve.');
    }

    public function testUsageAndMineMaps(): void
    {
        $a = $this->usage('text', 'gemini-2.5-flash');
        $b = $this->usage('text', 'gemini-2.5-flash');
        ai_rating_save($this->pdo, $a, 5, '', $this->tester('acct:1'));
        ai_rating_save($this->pdo, $a, 3, '', $this->tester('acct:2'));
        ai_rating_save($this->pdo, $b, 2, '', $this->tester('acct:2'));

        $map = ai_rating_usage_map($this->pdo, [$a, $b, 12345, 'x']);
        self::assertSame(2, $map[$a]['n']);
        self::assertEqualsWithDelta(4.0, $map[$a]['avg'], 1e-6);
        self::assertSame(1, $map[$a]['effective']);
        self::assertSame(1, $map[$b]['n']);
        self::assertArrayNotHasKey(12345, $map);

        self::assertSame([$a => 5], ai_rating_mine_map($this->pdo, [$a, $b], 'acct:1'));
        self::assertSame([$a => 3, $b => 2], ai_rating_mine_map($this->pdo, [$a, $b], 'acct:2'));
        self::assertSame([], ai_rating_usage_map($this->pdo, []));
    }

    public function testRecentAndLabels(): void
    {
        $a = $this->usage('image', 'gemini-2.5-flash-image');
        $b = $this->usage('video', 'veo-3.1-fast');
        ai_rating_save($this->pdo, $a, 4, 'Bonne lumière', $this->tester('acct:1', 'Alice'));
        ai_rating_save($this->pdo, $b, 1, 'Pièce déformée', $this->tester('acct:2', 'Bruno'));

        $recent = ai_rating_recent($this->pdo, 1);
        self::assertCount(1, $recent);
        self::assertSame('Pièce déformée', $recent[0]['comment']);
        self::assertSame('Bruno', $recent[0]['tester_name']);

        self::assertSame('4 / 5 — Bon', ai_rating_label(4));
        self::assertSame('5 / 5 — Excellent', ai_rating_label(9), 'Une note hors barème est ramenée dans 1–5.');
        self::assertSame('75 %', ai_rating_rate_label(0.75));
        self::assertSame('100 %', ai_rating_rate_label(1.0));
        self::assertSame('—', ai_rating_rate_label(null));
    }

    public function testWidgetIsSilentOutsideShopAdmin(): void
    {
        self::assertSame('', ai_rating_widget_html(), 'En CLI (tests), le widget ne s\'affiche jamais.');
    }
}
