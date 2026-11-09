<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/prospect-audit.php';
require_once __DIR__ . '/../includes/prospect-audit-report.php';

// Audit des prospects : lecture d'une page, scores web et réseaux, priorité d'appel et rendus de rapport.
final class ProspectAuditTest extends TestCase
{
    private function page(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
  <title>Brocante du Canal · meubles et objets anciens à Lyon</title>
  <meta name="description" content="Brocante du Canal, sélection de meubles et objets anciens au cœur de Lyon, ouvert du mardi au samedi.">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="https://brocante-canal.fr/">
  <meta property="og:title" content="Brocante du Canal">
</head>
<body>
  <h1>Brocante du Canal</h1>
  <p>Horaires : du mardi au samedi, 10 h – 19 h.</p>
  <a href="tel:+33400000000">04 00 00 00 00</a>
  <a href="mailto:contact@brocante-canal.fr">Écrire</a>
  <a href="https://www.facebook.com/brocanteducanal">Facebook</a>
  <a href="https://www.instagram.com/brocanteducanal/">Instagram</a>
  <a href="https://www.facebook.com/sharer/sharer.php?u=https://brocante-canal.fr">Partager sur Facebook</a>
  <script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","name":"Brocante du Canal"}</script>
</body>
</html>
HTML;
    }

    public function testParsePageKeepsUsefulSignals(): void
    {
        $m = pa_parse_html($this->page(), 'https://brocante-canal.fr/');
        $this->assertStringContainsString('width=device-width', $m['viewport']);
        $this->assertSame(1, $m['h1_count']);
        $this->assertSame(1, $m['tel_links']);
        $this->assertSame(1, $m['mailto_links']);
        $this->assertTrue($m['has_local_business']);
        $this->assertTrue($m['has_hours']);
        $this->assertContains('localbusiness', $m['jsonld_types']);
    }

    public function testSocialLinksIgnoreShareButtons(): void
    {
        $m = pa_parse_html($this->page(), 'https://brocante-canal.fr/');
        $this->assertSame('https://www.facebook.com/brocanteducanal', $m['social_links']['facebook'] ?? '');
        $this->assertSame('https://www.instagram.com/brocanteducanal', $m['social_links']['instagram'] ?? '');
    }

    public function testWebScoreOfAbsentSiteIsZero(): void
    {
        $site = pa_audit_site('', []);
        $this->assertSame('absent', $site['state']);
        $this->assertSame(0, $site['score']);
        $this->assertSame('Aucun site web', $site['gaps'][0]['label']);
    }

    public function testWebScoreRewardsDeclaredViewportAndHttps(): void
    {
        $site = [
            'state' => 'ok',
            'fetch' => ['final_url' => 'https://brocante-canal.fr/', 'status' => 200, 'redirects' => 0, 'ttfb_ms' => 120, 'total_ms' => 300, 'bytes' => 20000, 'error' => ''],
            'metrics' => pa_parse_html($this->page(), 'https://brocante-canal.fr/'),
        ];
        $scored = pa_score_web($site);
        $criteria = array_column($scored['criteria'], 'points', 'key');
        $this->assertSame(15, $criteria['mobile']);
        $this->assertGreaterThanOrEqual(15, $criteria['dispo']);
        $this->assertGreaterThan(40, $scored['score']);
    }

    public function testSocialScoreUnknownWhenNothingWasChecked(): void
    {
        $social = pa_score_social(['checked' => false, 'platforms' => []]);
        $this->assertNull($social['score']);
        $this->assertSame('social_unknown', $social['gaps'][0]['key']);
    }

    public function testSocialScoreZeroWhenSiteHasNoSocialLink(): void
    {
        $social = pa_score_social(['checked' => true, 'platforms' => [], 'followers_total' => 0]);
        $this->assertSame(0, $social['score']);
        $this->assertSame('social_absent', $social['gaps'][0]['key']);
    }

    public function testActiveProfileScoresHigherThanAbandonedOne(): void
    {
        $base = [
            'present' => true, 'name' => 'Brocante du Canal', 'bio_len' => 120, 'has_image' => true,
            'has_link' => true, 'followers' => 1800, 'confidence' => 80, 'blocked' => false, 'posts' => 40,
        ];
        $active = pa_score_social(['checked' => true, 'followers_total' => 1800, 'platforms' => [
            'instagram' => $base + ['key' => 'instagram', 'label' => 'Instagram', 'last_post_days' => 2],
            'facebook' => $base + ['key' => 'facebook', 'label' => 'Facebook', 'last_post_days' => 15],
        ]]);
        $abandoned = pa_score_social(['checked' => true, 'followers_total' => 40, 'platforms' => [
            'instagram' => ['present' => true, 'name' => '', 'bio_len' => 0, 'has_image' => false, 'has_link' => false,
                'followers' => 40, 'confidence' => 60, 'blocked' => true, 'posts' => 0, 'last_post_days' => 700,
                'key' => 'instagram', 'label' => 'Instagram'],
        ]]);
        $this->assertGreaterThan($abandoned['score'], $active['score']);
        $this->assertGreaterThanOrEqual(80, $active['score']);
        $this->assertContains('social_unverified', array_column($abandoned['gaps'], 'key'));
        $this->assertContains('social_inactive', array_column($abandoned['gaps'], 'key'));
    }

    public function testPriorityPutsReachableWeakProspectFirst(): void
    {
        $weak = pa_score_prospect(['score' => 5, 'gaps' => []], ['score' => null, 'platforms' => [], 'gaps' => []], ['phone' => '0600000000', 'priority' => 1]);
        $strong = pa_score_prospect(['score' => 92, 'gaps' => []], ['score' => 88, 'platforms' => [], 'gaps' => []], ['email' => 'x@y.fr', 'phone' => '0600000000', 'priority' => 1]);
        $this->assertGreaterThan($strong['priority'], $weak['priority']);
        $this->assertSame('priorite_1', $weak['tier']);
        $this->assertSame('deja_equipe', $strong['tier']);
    }

    public function testTierThresholds(): void
    {
        $this->assertSame('priorite_1', pa_tier(75)[0]);
        $this->assertSame('priorite_2', pa_tier(74)[0]);
        $this->assertSame('priorite_3', pa_tier(45)[0]);
        $this->assertSame('deja_equipe', pa_tier(44)[0]);
    }

    public function testHumanCountAndRelativeDates(): void
    {
        $this->assertSame(1200, pa_human_count('1,2 k'));
        $this->assertSame(12500, pa_human_count('12 500'));
        $this->assertSame(1500000, pa_human_count('1.5M'));
        $this->assertNull(pa_human_count('—'));
        $this->assertSame(0, pa_days_since('aujourd’hui'));
        $this->assertSame(1, pa_days_since('hier'));
        $this->assertSame(21, pa_days_since('il y a 3 semaines'));
    }

    public function testReportRendersPriorityCountsAndNoPlaceholder(): void
    {
        $prospect = [
            'rank' => 1, 'name' => 'Brocante Test', 'contact' => 'Marie', 'phone' => '0600000000', 'email' => '',
            'city' => 'Lyon', 'zone' => 'Presqu’île', 'website' => '',
            'site' => ['state' => 'absent', 'fetch' => null, 'metrics' => [], 'score' => 0, 'gaps' => []],
            'social' => ['checked' => false, 'platforms' => [], 'followers_total' => null],
            'scores' => [
                'web' => 0, 'social' => null, 'global' => 0, 'priority' => 93,
                'tier' => 'priorite_1', 'tier_label' => 'Priorité 1 · à contacter cette semaine',
                'angle' => 'Aucun site web : proposer une vitrine claire.', 'social_confidence' => 0,
                'gaps' => [['key' => 'absent', 'label' => 'Aucun site web', 'lost' => 100, 'detail' => 'Aucune adresse']],
            ],
        ];
        $report = [
            'generated_at' => '09/11/2026 à 12:00', 'source' => 'test', 'duration' => '0 s',
            'stats' => [
                'count' => 1, 'priorite_1' => 1, 'priorite_2' => 0, 'priorite_3' => 0, 'deja_equipe' => 0,
                'no_site' => 1, 'inaccessible' => 0, 'no_https' => 0, 'not_mobile' => 0,
                'no_social' => 0, 'social_unverified' => 0, 'avg_web' => 0, 'avg_social' => null, 'avg_global' => 0,
                'followers_total' => 0, 'with_contact' => 1,
            ],
            'prospects' => [$prospect],
        ];
        $md = pa_report_markdown($report);
        $this->assertStringContainsString('**1** prospect(s) à contacter cette semaine', $md);
        $this->assertStringContainsString('Brocante Test', $md);
        $csv = pa_report_csv($report);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Brocante Test', $csv);
        $html = pa_report_html($report);
        $this->assertStringNotContainsString('__P1__', $html);
        $this->assertStringContainsString('Brocante Test', $html);
    }
}
