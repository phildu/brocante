<?php

use PHPUnit\Framework\TestCase;

// Le recrutement vit hors du dossier du projet pendant les tests : aucun
// fichier réel n'est lu ou écrit.
define('RC_DATA_DIR', sys_get_temp_dir() . '/brocs-recrutement-test-' . getmypid());

require_once __DIR__ . '/../includes/recrutement.php';

final class RecrutementTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = RC_DATA_DIR;
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        @rmdir($this->dir);
    }

    private function clear(): void
    {
        foreach (glob($this->dir . '/{offres,candidatures}/*.json', GLOB_BRACE) ?: [] as $file) @unlink($file);
        foreach (glob($this->dir . '/{offres,candidatures}', GLOB_BRACE) ?: [] as $dir) @rmdir($dir);
        foreach (glob($this->dir . '/*') ?: [] as $file) is_dir($file) ? @rmdir($file) : @unlink($file);
    }

    private function offer(array $extra = []): array
    {
        return rc_offer_save(array_merge([
            'track' => 'emploi',
            'title' => 'Développeur PHP freelance',
            'contract' => 'freelance',
            'domains' => ['developpement'],
        ], $extra));
    }

    public function testOfferNormalizeDefaultsAreSafe(): void
    {
        $offer = rc_offer_normalize(['track' => 'inconnu', 'title' => '  Test   rapide ', 'slots' => 9999, 'domains' => ['design', 'design', 'nope']]);
        self::assertSame('emploi', $offer['track']);
        self::assertSame('Test rapide', $offer['title']);
        self::assertSame(500, $offer['slots']);
        self::assertSame(['design'], $offer['domains']);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $offer['code']);
        self::assertSame('ouvert', $offer['status']);
    }

    public function testOfferSaveLoadAndFindByCode(): void
    {
        $offer = $this->offer();
        self::assertNotNull($offer);
        self::assertSame($offer['id'], rc_offer_load($offer['id'])['id']);
        self::assertSame($offer['id'], rc_offer_find_code(strtolower($offer['code']))['id']);
        self::assertNull(rc_offer_find_code('ZZZZZZZZ'));
    }

    public function testOfferClosedByDeadline(): void
    {
        $open = $this->offer(['deadline' => '2999-01-01']);
        $closed = $this->offer(['deadline' => '2000-01-01']);
        $draft = $this->offer(['status' => 'ferme']);
        self::assertTrue(rc_offer_is_open($open));
        self::assertFalse(rc_offer_is_open($closed));
        self::assertFalse(rc_offer_is_open($draft));
    }

    public function testApplicationRequiresIdentityDomainsAndConsent(): void
    {
        [, $errors] = rc_application_from_input([
            'first' => '', 'last' => '', 'email' => 'pas-un-email', 'domains' => [],
        ], null);
        self::assertNotEmpty($errors);
        self::assertContains("L'adresse e-mail n'est pas valide.", $errors);
        self::assertContains('Choisissez au moins un domaine, même « Autre / à préciser ».', $errors);
        self::assertContains('Cochez l’accord pour envoyer votre candidature.', $errors);
    }

    public function testApplicationSavedAndCountedPending(): void
    {
        $offer = $this->offer(['track' => 'primo']);
        [$application, $errors] = rc_application_from_input([
            'first' => 'Léa',
            'last' => 'Martin',
            'email' => 'LEA@example.test',
            'city' => 'Nantes',
            'domains' => ['design', 'tests'],
            'availability' => '3-5 h par semaine',
            'motivation' => "Je peux relire les parcours et tester sur mobile.",
            'consent' => '1',
        ], $offer);
        self::assertSame([], $errors);
        $saved = rc_application_save($application);
        self::assertNotNull($saved);
        self::assertSame('lea@example.test', $saved['email']);
        self::assertSame('primo', $saved['track']);
        self::assertSame('recue', $saved['status']);
        self::assertSame($offer['id'], $saved['offer_id']);
        self::assertSame(1, rc_application_pending_count());
        self::assertSame(1, rc_offer_candidate_count($offer['id']));
        self::assertTrue(rc_application_exists('lea@example.test', $offer['id']));
    }

    public function testDuplicateApplicationOnSameOfferIsRefused(): void
    {
        $offer = $this->offer();
        [$application] = rc_application_from_input([
            'first' => 'Sam', 'last' => 'Petit', 'email' => 'sam@example.test',
            'domains' => ['developpement'], 'consent' => '1',
        ], $offer);
        rc_application_save($application);
        [, $errors] = rc_application_from_input([
            'first' => 'Sam', 'last' => 'Petit', 'email' => 'sam@example.test',
            'domains' => ['developpement'], 'consent' => '1',
        ], $offer);
        self::assertContains('Une candidature avec cette adresse a déjà été reçue pour ce besoin.', $errors);
    }

    public function testStatusLabelsAdaptToVolunteers(): void
    {
        self::assertSame('Confirmé', rc_status_label('integree', 'benevole'));
        self::assertSame('Intégré', rc_status_label('integree', 'emploi'));
        self::assertSame('À traiter', rc_status_label('recue'));
    }

    public function testSettingsRejectInvalidNotificationEmail(): void
    {
        self::assertFalse(rc_settings_save(['notify' => 'pas-un-email', 'open' => true, 'intro' => 'Bonjour']));
        self::assertTrue(rc_settings_save(['notify' => 'recrutement@example.test', 'open' => true, 'intro' => 'Bonjour']));
    }

    public function testExportRowsCarryOfferTitleAndStatus(): void
    {
        $offer = $this->offer();
        [$application] = rc_application_from_input([
            'first' => 'Noé', 'last' => 'Blanc', 'email' => 'noe@example.test',
            'domains' => ['developpement'], 'consent' => '1',
        ], $offer);
        rc_application_save($application);
        $rows = rc_export_rows(rc_applications());
        self::assertSame('Nom', $rows[0][0]);
        self::assertSame('Noé Blanc', $rows[1][0]);
        self::assertSame($offer['title'], $rows[1][5]);
        self::assertSame('À traiter', $rows[1][7]);
    }
}
