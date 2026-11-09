<?php

use PHPUnit\Framework\TestCase;

// Édition du contenu du centre d'aide : corrections gardées dans un fichier jetable, jamais dans data/saas.
final class AideEditionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/brocs-aide-edit-' . bin2hex(random_bytes(4)) . '.json';
        putenv('BROCANTE_AIDE_EDIT_FILE=' . $this->file);
        require_once __DIR__ . '/../aide/_base.php';
        @unlink($this->file);
        aide_edit_load(true);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('BROCANTE_AIDE_EDIT_FILE');
        aide_edit_load(true);
    }

    private function faq(string $id): ?array
    {
        foreach (aide_import_faq() as $row) {
            if ($row['slug'] === $id) return $row;
        }
        return null;
    }

    public function testNoCorrectionLeavesTheBaseUntouched(): void
    {
        $base = aide_edit_base('faq');
        self::assertNotEmpty($base);
        self::assertCount(count($base), aide_import_faq());
    }

    public function testUpdateOverridesOnlyWhatChanged(): void
    {
        $base = aide_edit_base('faq')[0];
        $id = $base['id'];
        self::assertNull(aide_edit_update('faq', $id, ['question' => 'Nouvelle question ?', 'reponse' => $base['reponse'], 'role' => $base['role'], 'mots_cles' => implode(', ', $base['mots_cles'])]));
        $saved = json_decode((string) file_get_contents($this->file), true);
        self::assertSame(['question' => 'Nouvelle question ?'], $saved['faq']['modifs'][$id]);
        self::assertSame('Nouvelle question ?', $this->faq($id)['titre']);
        self::assertSame($base['reponse'], $this->faq($id)['resume']);
    }

    public function testSavingTheBaseTextAgainDropsTheCorrection(): void
    {
        $base = aide_edit_base('faq')[0];
        $input = ['question' => 'Autre ?', 'reponse' => $base['reponse'], 'role' => $base['role'], 'mots_cles' => implode(', ', $base['mots_cles'])];
        aide_edit_update('faq', $base['id'], $input);
        $input['question'] = $base['question'];
        aide_edit_update('faq', $base['id'], $input);
        self::assertSame([], aide_edit_load()['faq']['modifs']);
    }

    public function testResetRestoresBaseAndUnhides(): void
    {
        $base = aide_edit_base('faq')[0];
        aide_edit_update('faq', $base['id'], ['question' => 'X ?', 'reponse' => 'Y', 'role' => 'tous', 'mots_cles' => '']);
        aide_edit_hide('faq', $base['id'], true);
        self::assertNull($this->faq($base['id']));
        self::assertNull(aide_edit_reset('faq', $base['id']));
        self::assertSame($base['question'], $this->faq($base['id'])['titre']);
    }

    public function testHideAndShow(): void
    {
        $id = aide_edit_base('faq')[0]['id'];
        self::assertNull(aide_edit_hide('faq', $id, true));
        self::assertNull($this->faq($id));
        self::assertNull(aide_edit_hide('faq', $id, false));
        self::assertNotNull($this->faq($id));
        self::assertSame('Fiche introuvable.', aide_edit_hide('faq', 'nexiste-pas', true));
    }

    public function testAddFaqGetsUniqueIdAndAppearsInTheCenter(): void
    {
        $in = ['question' => 'Où est mon colis ?', 'reponse' => 'Dans votre boîte aux lettres.', 'role' => 'visiteur', 'mots_cles' => 'colis, livraison, colis'];
        self::assertNull(aide_edit_add('faq', $in));
        self::assertNull(aide_edit_add('faq', $in));
        $ids = array_column(aide_edit_load()['faq']['ajouts'], 'id');
        self::assertSame(['perso-ou-est-mon-colis', 'perso-ou-est-mon-colis-2'], $ids);
        self::assertSame(['colis', 'livraison'], aide_edit_load()['faq']['ajouts'][0]['mots_cles']);
        self::assertSame('visiteur', $this->faq('perso-ou-est-mon-colis')['role']);
        self::assertNull(aide_edit_delete_added('faq', 'perso-ou-est-mon-colis'));
        self::assertNull($this->faq('perso-ou-est-mon-colis'));
        self::assertNotNull($this->faq('perso-ou-est-mon-colis-2'));
    }

    public function testGlossaryRejectsDuplicateTermsCaseInsensitively(): void
    {
        $existing = aide_edit_base('glossaire')[0]['terme'];
        self::assertSame('Ce terme existe déjà.', aide_edit_add('glossaire', ['terme' => mb_strtoupper($existing), 'definition' => 'x']));
        self::assertNull(aide_edit_add('glossaire', ['terme' => 'Brocanteur', 'definition' => 'Vend des objets anciens.']));
        $titres = array_column(aide_import_glossaire(), 'titre');
        self::assertContains('Brocanteur', $titres);
    }

    public function testEmptyRequiredFieldsAreRefused(): void
    {
        $id = aide_edit_base('faq')[0]['id'];
        self::assertSame('La question est vide.', aide_edit_update('faq', $id, ['question' => '  ', 'reponse' => 'x']));
        self::assertSame('La réponse est vide.', aide_edit_add('faq', ['question' => 'Q ?', 'reponse' => '']));
        self::assertSame('Le terme est vide.', aide_edit_add('glossaire', ['terme' => '', 'definition' => 'd']));
        self::assertFileDoesNotExist($this->file);
    }

    public function testTextIsCleanedAndBounded(): void
    {
        self::assertSame("a\nb", aide_edit_clean("a\x00\r\nb\x07", 50, true));
        self::assertSame('a b', aide_edit_clean("a \n\t b", 50));
        self::assertSame(5, mb_strlen(aide_edit_clean('éééééééééé', 5)));
    }

    public function testTutorialStepsKeepTheirOtherFields(): void
    {
        $base = aide_edit_base('tutoriels')[0];
        $slug = $base['slug'];
        $in = ['titre' => $base['titre'], 'resume' => $base['resume'], 'role' => $base['role'],
            'etapes' => [0 => ['titre' => 'Nouveau titre d’étape', 'texte' => $base['etapes'][0]['texte']]]];
        self::assertNull(aide_edit_update('tutoriels', $slug, $in));
        $data = aide_edit_apply('tutoriels.json', ['tutoriels' => [$base]]);
        $step = $data['tutoriels'][0]['etapes'][0];
        self::assertSame('Nouveau titre d’étape', $step['titre']);
        self::assertSame($base['etapes'][0]['lien'] ?? null, $step['lien'] ?? null);
        self::assertSame($base['etapes'][1]['titre'], $data['tutoriels'][0]['etapes'][1]['titre']);
    }

    public function testUnknownTypeAndOtherFilesAreIgnored(): void
    {
        self::assertSame('Type de fiche inconnu.', aide_edit_update('nimporte', 'x', []));
        self::assertSame(['a' => 1], aide_edit_apply('videos.json', ['a' => 1]));
    }

    public function testCorrectedContentReachesTheSearchIndex(): void
    {
        $id = aide_edit_base('faq')[0]['id'];
        aide_edit_update('faq', $id, ['question' => 'Zorglub ?', 'reponse' => 'Réponse inédite.', 'role' => 'tous', 'mots_cles' => 'zorglub']);
        $titres = array_column(aide_import_faq(), 'titre');
        self::assertContains('Zorglub ?', $titres);
    }
    private function tutorialInput(array $over = []): array
    {
        return array_merge([
            'titre' => 'Publier une annonce', 'resume' => 'Mettre une annonce en ligne en trois clics.', 'role' => 'vendeur',
            'prerequis' => "Une boutique ouverte\n\n  Une photo  ", 'url_cible' => '/admin/index.php?s=pieces', 'mots_cles' => 'annonce, publier',
            'etapes' => [
                0 => ['titre' => 'Ouvrir l’administration', 'texte' => 'Allez sur /admin/.', 'a_voir' => 'Le tableau de bord', 'lien' => '/admin/'],
                1 => ['titre' => '', 'texte' => '', 'a_voir' => '', 'lien' => ''],
                2 => ['titre' => 'Publier', 'texte' => 'Cliquez sur Publier.', 'a_voir' => '', 'lien' => 'https://evil.example/'],
            ],
            'erreurs' => [0 => ['symptome' => 'Rien ne s’affiche', 'cause' => '', 'solution' => 'Rechargez la page.'], 1 => ['symptome' => '', 'cause' => '', 'solution' => '']],
        ], $over);
    }

    private function tutorial(string $slug): ?array
    {
        foreach (aide_import_tutoriels() as $row) {
            if ($row['slug'] === $slug) return $row;
        }
        return null;
    }

    public function testCreateTutorialAppearsWithStepsAndErrors(): void
    {
        self::assertNull(aide_edit_add('tutoriels', $this->tutorialInput()));
        $t = $this->tutorial('perso-publier-une-annonce');
        self::assertNotNull($t);
        self::assertSame('vendeur', $t['role']);
        self::assertSame(['Une boutique ouverte', 'Une photo'], $t['prerequis']);
        self::assertCount(2, $t['etapes']);
        self::assertSame('/admin/', $t['etapes'][0]['lien']);
        self::assertSame('Le tableau de bord', $t['etapes'][0]['ecran']);
        self::assertSame('', $t['etapes'][1]['lien'], 'un lien externe est refusé');
        self::assertCount(1, $t['erreurs']);
        self::assertSame('/admin/index.php?s=pieces', $t['url_cible']);
        self::assertContains('perso-publier-une-annonce', array_column(aide_import_tutoriels(), 'slug'));
    }

    public function testCreateTutorialNeedsAStepAndCompleteRows(): void
    {
        self::assertSame('Ajoutez au moins une étape.', aide_edit_add('tutoriels', $this->tutorialInput(['etapes' => [['titre' => '', 'texte' => '']]])));
        self::assertSame('L’étape 1 a besoin d’un titre et d’un texte.', aide_edit_add('tutoriels', $this->tutorialInput(['etapes' => [['titre' => 'Seul', 'texte' => '']]])));
        self::assertSame('L’erreur fréquente 1 a besoin d’un symptôme et d’une solution.', aide_edit_add('tutoriels', $this->tutorialInput(['erreurs' => [['symptome' => 'x', 'solution' => '']]])));
        self::assertSame('Le titre est vide.', aide_edit_add('tutoriels', $this->tutorialInput(['titre' => ' '])));
        self::assertFileDoesNotExist($this->file);
    }

    public function testCreatedTutorialSlugsAreUniqueAndEditable(): void
    {
        aide_edit_add('tutoriels', $this->tutorialInput());
        aide_edit_add('tutoriels', $this->tutorialInput());
        self::assertSame(['perso-publier-une-annonce', 'perso-publier-une-annonce-2'], array_column(aide_edit_load()['tutoriels']['ajouts'], 'slug'));
        self::assertNull(aide_edit_update('tutoriels', 'perso-publier-une-annonce-2', $this->tutorialInput(['titre' => 'Titre revu', 'etapes' => [['titre' => 'Seule étape', 'texte' => 'Voilà.']]])));
        $t = $this->tutorial('perso-publier-une-annonce-2');
        self::assertSame('Titre revu', $t['titre']);
        self::assertCount(1, $t['etapes']);
        self::assertNull(aide_edit_delete_added('tutoriels', 'perso-publier-une-annonce-2'));
        self::assertNull($this->tutorial('perso-publier-une-annonce-2'));
        self::assertNotNull($this->tutorial('perso-publier-une-annonce'));
    }
}
