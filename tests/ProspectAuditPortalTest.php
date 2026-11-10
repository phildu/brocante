<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/prospect-audit.php';
require_once __DIR__ . '/../includes/prospect-audit-jobs.php';

// Audit lancé depuis le portail : lecture d'une ligne de carnet (colonnes et
// champs personnalisés), audits par lots, rapports finalisés et isolement des
// carnets. Aucun appel réseau : les prospects n'ont ni site ni page sociale.
final class ProspectAuditPortalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/audit-jobs-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($this->root, 0775, true);
        putenv('AUDIT_REPORTS_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        foreach (glob($this->root . '/*/*') ?: [] as $dir) @rmdir($dir);
        foreach (glob($this->root . '/*') ?: [] as $dir) @rmdir($dir);
        @rmdir($this->root);
        putenv('AUDIT_REPORTS_DIR');
    }

    private function row(array $extra = []): array
    {
        return $extra + [
            'business' => 'Brocante du Test', 'name' => 'Jeanne Martin', 'phone' => '06 12 34 56 78', 'email' => 'jeanne@exemple.fr',
            'website' => '', 'address' => '1 rue du Marché', 'city' => 'Lyon', 'zone' => 'Centre', 'notes' => 'Rencontrée au marché',
            'status' => 'contacte', 'priority' => 1, 'custom_fields' => '{}',
        ];
    }

    public function testRowMappingReadsColumnsAndCustomFields(): void
    {
        $row = $this->row(['custom_fields' => json_encode([
            'Site web' => 'https://brocante-test.fr',
            'Instagram' => '@brocante.test',
            'Facebook' => 'https://www.facebook.com/brocantetest',
            'Abonnés Instagram' => '1,2 k',
            'Note Google' => '4,5',
            'Avis Google' => '27',
            'Dernière publication' => 'il y a 3 jours',
        ])]);
        $prospect = pa_prospect_from_row($row);
        $this->assertSame('Brocante du Test', $prospect['business']);
        $this->assertSame('Jeanne Martin', $prospect['contact']);
        $this->assertSame('https://brocante-test.fr', $prospect['website']);
        $this->assertSame('https://www.instagram.com/brocante.test', $prospect['instagram']);
        $this->assertSame('https://www.facebook.com/brocantetest', $prospect['facebook']);
        $this->assertSame('1,2 k', $prospect['ig_followers']);
        $this->assertSame('4,5', $prospect['google_rating']);
        $this->assertSame('27', $prospect['google_reviews']);
        $this->assertSame('il y a 3 jours', $prospect['last_post']);
        $this->assertTrue($prospect['social_checked']);
        $this->assertSame('contacte', $prospect['status']);
        $this->assertSame(1, $prospect['priority']);
    }

    public function testWebsiteColumnWinsOverCustomField(): void
    {
        $row = $this->row([
            'website' => 'https://colonne-test.fr',
            'custom_fields' => json_encode(['Site web' => 'https://ancien-champ-test.fr']),
        ]);
        $this->assertSame('https://colonne-test.fr', pa_prospect_from_row($row)['website']);
    }

    public function testBareWordIsNotTurnedIntoSocialProfile(): void
    {
        $prospect = pa_prospect_from_row($this->row(['custom_fields' => json_encode(['Instagram' => 'Lyon'])]));
        $this->assertSame('', $prospect['instagram']);
        $this->assertSame('https://www.instagram.com/brocante.lyon', pa_handle_url('@brocante.lyon', 'instagram'));
    }

    public function testBatchesAdvanceOnlyOnSavedResultsAndFinishWritesAllFormats(): void
    {
        $scope = 'galerie-brocante-test';
        $prospects = [];
        foreach (['A', 'B', 'C'] as $name) $prospects[] = ['business' => $name, 'status' => 'a_contacter'];

        $created = paj_create($scope, $prospects, ['social' => false]);
        $this->assertSame(3, $created['total']);

        $take = paj_take($scope, $created['id'], 2);
        $this->assertCount(2, $take['prospects']);
        $this->assertSame(0, $take['offset']);
        // Les deux prospects pris mais pas encore enregistrés sont repris au lot suivant.
        $this->assertSame(0, paj_take($scope, $created['id'], 2)['offset']);

        $results = [];
        foreach ($take['prospects'] as $prospect) $results[] = pa_audit_prospect($prospect, ['no_social' => true]);
        $progress = paj_append($scope, $created['id'], $results);
        $this->assertSame(2, $progress['done']);
        $this->assertSame(3, $progress['total']);

        $take = paj_take($scope, $created['id'], 2);
        $this->assertCount(1, $take['prospects']);
        $this->assertSame(2, $take['offset']);
        paj_append($scope, $created['id'], [pa_audit_prospect($take['prospects'][0], ['no_social' => true])]);

        $this->assertNull(paj_take($scope, $created['id'], 2));      // tout est audité : rien à reprendre

        $finished = paj_finish($scope, $created['id'], 'carnet de test');
        foreach (['html', 'csv', 'md', 'json'] as $format) {
            $this->assertFileExists($finished['files'][$format]);
            $this->assertNotNull(paj_file($scope, $created['id'], $format));
        }
        $report = json_decode((string) file_get_contents($finished['files']['json']), true);
        $this->assertSame(3, $report['count']);
        $this->assertSame([1, 2, 3], array_column($report['prospects'], 'rank'));
        $this->assertSame('carnet de test', $report['source']);
        $this->assertStringContainsString('Aucun site web', (string) file_get_contents($finished['files']['md']));

        $list = paj_list($scope);
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['finished']);
        $this->assertSame(3, $list[0]['count']);
        $this->assertNull(paj_file($scope, $created['id'], 'pdf'));
    }

    public function testUnknownReportAndOtherCarnetAreRejected(): void
    {
        $scope = 'galerie-brocante-test';
        $created = paj_create($scope, [['business' => 'A']], ['social' => false]);
        $this->assertNull(paj_load($scope, 'deadbeefdeadbeef'));
        $this->assertNull(paj_load($scope, '../../etc/passwd'));
        $this->assertNull(paj_load('galerie-autre', $created['id']));
        $this->assertSame([], paj_list('galerie-autre'));
        $this->expectException(InvalidArgumentException::class);
        paj_dir($scope, '../autre');
    }

    public function testPruneKeepsOnlyTheTenLatestAudits(): void
    {
        $scope = 'galerie-brocante-test';
        for ($i = 0; $i < 12; $i++) {
            paj_create($scope, [['business' => 'Prospect ' . $i]], ['social' => false]);
        }
        $this->assertCount(PAJ_KEEP, glob($this->root . '/' . paj_scope($scope) . '/*', GLOB_ONLYDIR) ?: []);
    }
}
