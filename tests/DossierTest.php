<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../dossier/lib.php';

final class DossierTest extends TestCase
{
    private function input(array $overrides = []): array
    {
        return array_merge([
            'assoc_name' => 'Les Vitrines de la Croix-Rousse',
            'assoc_address' => '12 rue des Pierres Plantées',
            'assoc_cp' => '69001',
            'assoc_city' => 'Lyon',
            'siret' => '812 345 678 00019',
            'rep_name' => 'Claire Morel',
            'rep_role' => 'Présidente',
            'letter_date' => '2026-10-31',
            'project_name' => 'Le numérique des commerces',
            'territory' => 'Croix-Rousse',
            'launch' => '2027-03-15',
            'members' => '20',
            'shops' => '20',
            'rate' => '40',
            'sub_monthly' => '25',
            'include_reponse' => '1',
            'include_grille' => '1',
            'include_pieces' => '1',
        ], $overrides);
    }

    public function testCoutParAdherent(): void
    {
        $d = ddoc_parse($this->input());
        $this->assertSame('s', $d['taille']);
        $this->assertSame(1900.0, $d['setup']);
        $this->assertSame(760.0, $d['subsidy']);
        $this->assertSame(1140.0, $d['selffund']);
        $this->assertSame(57.0, $d['per_member']);
        $this->assertSame(6000.0, $d['subs_year']);
    }

    public function testGrilleDeMiseEnPlaceSuitLeNombreDadherents(): void
    {
        $this->assertSame(1900.0, ddoc_parse($this->input(['members' => '30']))['setup']);
        $this->assertSame(3900.0, ddoc_parse($this->input(['members' => '31']))['setup']);
        $this->assertSame(5900.0, ddoc_parse($this->input(['members' => '100']))['setup']);
        // Le devis envoyé reste la référence : un montant saisi l'emporte sur la grille, avec un avertissement.
        $d = ddoc_parse($this->input(['members' => '100', 'setup' => '4200']));
        $this->assertSame(4200.0, $d['setup']);
        $this->assertNotEmpty(ddoc_warnings($d));
    }

    public function testMontantsSaisisTolerantEspacesEtVirgule(): void
    {
        $d = ddoc_parse($this->input(['setup' => '5 900,50', 'rate' => '35,5']));
        $this->assertSame(5900.5, $d['setup']);
        $this->assertSame(35.5, $d['rate']);
        $this->assertSame(2094.68, round($d['subsidy'], 2));
    }

    public function testSiretEtDateNormalises(): void
    {
        $d = ddoc_parse($this->input(['siret' => '812.345.678.00019']));
        $this->assertSame('81234567800019', $d['siret_digits']);
        $this->assertSame('15 mars 2027', $d['launch']);
        $this->assertSame('15 septembre 2026', ddoc_shift_months($d['launch_iso'], -6));
    }

    public function testChampsObligatoires(): void
    {
        $errors = ddoc_errors(ddoc_parse($this->input(['siret' => '1234', 'project_name' => ''])));
        $this->assertArrayHasKey('siret', $errors);
        $this->assertArrayHasKey('project_name', $errors);
        $this->assertArrayNotHasKey('assoc_name', $errors);
    }

    public function testAvertissementsDeTaux(): void
    {
        $this->assertNotEmpty(ddoc_warnings(ddoc_parse($this->input(['rate' => '60']))));
        $this->assertSame([], ddoc_warnings(ddoc_parse($this->input())));
    }

    public function testDossierCompletNumeroteSesPages(): void
    {
        $d = ddoc_parse($this->input());
        $html = ddoc_complet($d);
        $this->assertSame(count(ddoc_docs($d)) + 1, substr_count($html, 'class="sheet"'));
        $this->assertStringContainsString('<span>9 / 9</span>', $html);
        $this->assertStringContainsString('760 €', $html);
        $this->assertStringContainsString('15 septembre 2026', $html);
    }

    public function testArchiveZipContientLesPiecesAnnoncees(): void
    {
        $d = ddoc_parse($this->input());
        $path = ddoc_zip($d);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
        $zip->close();
        @unlink($path);
        $this->assertContains('03-budget-previsionnel.csv', $names);
        $this->assertContains('06-calendrier-instruction.html', $names);
        $this->assertContains('07-compte-tiers.html', $names);
        $this->assertContains('08-pieces-a-joindre.html', $names);
        $this->assertContains('LISEZ-MOI.txt', $names);
    }

    public function testOptionsRetirentDesDocuments(): void
    {
        $d = ddoc_parse($this->input(['include_reponse' => '', 'include_grille' => '', 'include_pieces' => '']));
        $keys = array_column(ddoc_docs($d), 'key');
        $this->assertSame(['lettre', 'note', 'budget', 'calendrier'], $keys);
    }
}
