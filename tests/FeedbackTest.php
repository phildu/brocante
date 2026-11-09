<?php

use PHPUnit\Framework\TestCase;

// Les retours vivent hors du dossier du projet pendant les tests : aucun
// fichier réel n'est lu ou écrit, et l'envoi d'e-mails est neutralisé.
define('FEEDBACK_DATA_DIR', sys_get_temp_dir() . '/brocs-feedback-test-' . getmypid());
define('FEEDBACK_MAIL_STUB', true);

require_once __DIR__ . '/../includes/feedback.php';

final class FeedbackTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = FEEDBACK_DATA_DIR;
        $this->clear();
        feedback_settings_save($this->baseSettings());
    }

    protected function tearDown(): void
    {
        $this->clear();
        @rmdir($this->dir);
    }

    private function clear(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? array_map('unlink', glob($file . '/*') ?: []) : null;
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
    }

    private function baseSettings(): array
    {
        return [
            'widget' => true,
            'open_to_all' => false,
            'include_beta_testers' => false,
            'testers' => ['shops' => ['demo'], 'galleries' => []],
            'notify_email' => 'ops@example.test',
            'reply_days' => 7,
        ];
    }

    private function add(string $message, string $type = 'bug', array $extra = []): array
    {
        return feedback_add(array_merge([
            'shop' => 'demo',
            'type' => $type,
            'message' => $message,
            'email' => 'testeur@example.test',
        ], $extra), 42, 'Testeur Démo');
    }

    public function testSettingsNormalize(): void
    {
        $s = feedback_settings_normalize([
            'testers' => ['shops' => 'Demo, demo; pas un slug !', 'galleries' => ['Rue des Arts']],
            'notify_email' => 'PAS-UN-EMAIL',
            'reply_days' => 400,
            'widget' => false,
        ]);
        self::assertSame(['demo', 'pas', 'un', 'slug'], $s['testers']['shops']);
        self::assertSame([], $s['testers']['galleries']);
        self::assertSame('', $s['notify_email']);
        self::assertSame(90, $s['reply_days']);
        self::assertFalse($s['widget']);
        self::assertTrue($s['include_beta_testers']);
    }

    public function testSettingsSaveKeepsProgramStart(): void
    {
        $first = feedback_settings_save($this->baseSettings());
        self::assertGreaterThan(0, $first['since']);
        $again = feedback_settings_save(['reply_days' => 20, 'notify_email' => 'invalide'] + $this->baseSettings());
        self::assertSame($first['since'], $again['since']);
        self::assertSame('', $again['notify_email'], "Une adresse invalide n'est pas enregistrée.");
    }

    public function testWidgetOnlyForTestersUnlessOpenToAll(): void
    {
        self::assertTrue(feedback_widget_enabled('demo'));
        self::assertFalse(feedback_widget_enabled('autre'));
        feedback_settings_save(['open_to_all' => true] + $this->baseSettings());
        self::assertTrue(feedback_widget_enabled('autre'));
    }

    public function testAddRecordsContext(): void
    {
        $f = $this->add('Le bouton de publication ne répond plus.', 'bug', [
            'severity' => 'blocker',
            'page' => '/admin/produits.php?x=1',
            'url' => 'https://brocs.test/la-fripe-a-naty/admin/produits.php?x=1#top',
            'page_title' => 'Produits',
            'viewport' => '1440 × 900',
            'ua' => 'Mozilla/5.0',
        ]);
        self::assertMatchesRegularExpression('/^fb[0-9]{6}-[a-f0-9]{8}$/', $f['id']);
        self::assertSame('new', $f['status']);
        self::assertSame('high', $f['priority']);
        self::assertSame('blocker', $f['severity']);
        self::assertSame('demo', $f['shop']);
        self::assertSame(42, $f['account_id']);
        self::assertSame('Testeur Démo', $f['account']);
        self::assertSame('/admin/produits.php?x=1', $f['page']);
        self::assertSame('https://brocs.test/la-fripe-a-naty/admin/produits.php?x=1', $f['url']);
        self::assertSame('1440 × 900', $f['context']['viewport']);
        self::assertSame('testeur@example.test', $f['email']);
        self::assertCount(1, feedback_all());
    }

    public function testAddDropsInvalidUrl(): void
    {
        $f = $this->add('Le bouton de publication ne répond toujours pas.', 'bug', [
            'url' => 'javascript:alert(1)',
        ]);
        self::assertSame('', $f['url']);
    }

    public function testAddRejectsShortMessageAndUnknownShop(): void
    {
        try {
            $this->add('court');
            self::fail('Un message trop court doit être refusé.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('10 caractères', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        feedback_add(['shop' => 'autre', 'type' => 'idea', 'message' => 'Une idée tout à fait valable.']);
    }

    public function testDuplicateWithinThreeMinutesReturnsSameRecord(): void
    {
        $a = $this->add('Le panier se vide quand je change de page.');
        $b = $this->add('Le panier se vide quand je change de page.');
        self::assertSame($a['id'], $b['id']);
        self::assertCount(1, feedback_all());
    }

    public function testCustomerScopesHistoryAndRateLimit(): void
    {
        $client = static fn (string $message, string $email = 'client@example.test'): array => feedback_add([
            'shop' => 'demo',
            'audience' => 'customer',
            'email' => $email,
            'type' => 'other',
            'message' => $message,
        ], null, 'Client Démo');

        $first = $client('Le bouton de recherche ne répond pas sur mobile.');
        $other = $client('Le bouton de recherche ne répond pas sur mobile.', 'autre@example.test');
        self::assertNotSame($first['id'], $other['id'], 'Deux clients peuvent envoyer le même texte sans être fusionnés.');
        self::assertSame('client', $first['source']);
        self::assertSame([$first['id']], array_column(feedback_records_for_email('demo', 'CLIENT@example.test'), 'id'));

        $client('Deuxième retour du même client.');
        $client('Troisième retour du même client.');
        $client('Quatrième retour du même client.');
        $client('Cinquième retour du même client.');
        $this->expectException(InvalidArgumentException::class);
        $client('Sixième retour du même client.');
    }

    public function testFiltersByStatusTypeAndText(): void
    {
        $bug = $this->add('La recherche ignore les accents dans les titres.');
        $idea = $this->add('Ajouter un bouton pour dupliquer une fiche.', 'idea');
        feedback_update($idea['id'], ['status' => 'done'], 'Ops');
        self::assertCount(2, feedback_list(['statut' => 'all']));
        self::assertSame([$bug['id']], array_column(feedback_list(['statut' => 'open']), 'id'));
        self::assertSame([$idea['id']], array_column(feedback_list(['statut' => 'done']), 'id'));
        self::assertSame([$idea['id']], array_column(feedback_list(['type' => 'idea']), 'id'));
        self::assertSame([$bug['id']], array_column(feedback_list(['q' => 'accents']), 'id'));
        self::assertCount(2, feedback_list(['q' => 'demo']));
        self::assertCount(2, feedback_list(['jours' => 7]));
        self::assertCount(0, feedback_list(['module' => 'rien']));
    }

    public function testUpdateLogsStatusAndResetsResolution(): void
    {
        $f = $this->add('Les photos PNG perdent leur transparence à l’envoi.', 'bug');
        $qualified = feedback_update($f['id'], ['status' => 'qualified'], 'Ops');
        self::assertSame('qualified', $qualified['status']);
        self::assertCount(1, $qualified['status_log']);
        self::assertSame(['new', 'qualified'], [$qualified['status_log'][0]['from'], $qualified['status_log'][0]['to']]);

        $done = feedback_update($f['id'], ['status' => 'done', 'priority' => 'low'], 'Ops');
        self::assertSame('done', $done['status']);
        self::assertSame('low', $done['priority']);
        self::assertNotSame('', $done['resolved_at']);

        $reopened = feedback_update($f['id'], ['status' => 'in_progress'], 'Ops');
        self::assertSame('', $reopened['resolved_at']);
        self::assertCount(3, $reopened['status_log']);
    }

    public function testNoteIsStored(): void
    {
        $f = $this->add('La facture PDF affiche un total arrondi au centime.');
        $noted = feedback_note_add($f['id'], 'Même cause que le ticket 412.', 'Ops');
        self::assertCount(1, $noted['notes']);
        self::assertSame('Même cause que le ticket 412.', $noted['notes'][0]['text']);
        $this->expectException(InvalidArgumentException::class);
        feedback_note_add($f['id'], ' ');
    }

    public function testReplyMarksWaitingAndFeedsUnread(): void
    {
        $f = $this->add('Le lien de suivi de commande renvoie une erreur 500.');
        self::assertSame(0, feedback_shop_unread('demo'));
        $replied = feedback_reply($f['id'], 'C’est corrigé, merci pour le signalement précis.', 'Ops', 'testeur@example.test');
        self::assertSame('waiting', $replied['status']);
        self::assertNotSame('', $replied['first_reply_at']);
        self::assertCount(1, $replied['replies']);
        self::assertSame('testeur@example.test', $replied['email']);
        self::assertSame(1, feedback_shop_unread('demo'));
        feedback_mark_shop_seen('demo');
        self::assertSame(0, feedback_shop_unread('demo'));
    }

    public function testReplyWithoutAnyAddressIsRefused(): void
    {
        feedback_settings_save(['notify_email' => ''] + $this->baseSettings());
        $f = feedback_add(['shop' => 'demo', 'type' => 'other', 'message' => 'Un retour sans adresse de réponse.'], null, '');
        $this->expectException(InvalidArgumentException::class);
        feedback_reply($f['id'], 'Réponse impossible sans adresse.', 'Ops');
    }

    public function testStatsWeeklyAndParticipation(): void
    {
        $first = $this->add('Le menu mobile reste ouvert après un clic sur un lien.');
        $this->add('Proposer un export des ventes en CSV.', 'idea');
        feedback_update($first['id'], ['status' => 'done'], 'Ops');

        $all = feedback_all();
        $stats = feedback_stats($all, feedback_settings_load());
        self::assertSame(2, $stats['total']);
        self::assertSame(1, $stats['open']);
        self::assertSame(1, $stats['new']);
        self::assertSame(1, $stats['by_type']['bug']);
        self::assertSame(1, $stats['by_type']['idea']);
        self::assertSame(1, $stats['done_7']);
        self::assertSame(1, $stats['testers']);
        self::assertSame(0, $stats['silent']);
        self::assertSame(1, $stats['active_shops_30']);

        $weekly = feedback_weekly($all);
        self::assertCount(8, $weekly);
        self::assertSame(2, $weekly[7]['count']);

        $participation = feedback_participation($all, feedback_settings_load());
        self::assertCount(1, $participation);
        self::assertSame('demo', $participation[0]['shop']);
        self::assertSame(2, $participation[0]['count']);
        self::assertSame('active', $participation[0]['state']);
    }

    public function testCsvIncludesEveryRecord(): void
    {
        $this->add('Un CSV doit reprendre ce retour.');
        [$headers, $rows] = feedback_csv(feedback_all());
        self::assertSame('Identifiant', $headers[0]);
        self::assertCount(1, $rows);
        self::assertSame('Administration boutique', $rows[0][2]);
        self::assertSame('Un CSV doit reprendre ce retour.', $rows[0][11]);
    }

    public function testSurfacesDefaultsKeepExistingBehaviourAndPortailIsOffByDefault(): void
    {
        $s = feedback_settings_normalize([]);
        self::assertSame(['admin' => true, 'front' => true, 'portail' => false], $s['surfaces']);
        // Des réglages enregistrés avant la distinction des espaces gardent le widget back end et front end.
        self::assertTrue(feedback_widget_enabled('demo', 'admin'));
        self::assertTrue(feedback_widget_enabled('demo', 'front'));
        self::assertFalse(feedback_portail_widget_enabled());
    }

    public function testEachSurfaceHasItsOwnSwitchAndTheMasterSwitchCutsAll(): void
    {
        feedback_settings_save(['surfaces' => ['admin' => true, 'front' => false, 'portail' => true]] + $this->baseSettings());
        self::assertTrue(feedback_widget_enabled('demo', 'admin'));
        self::assertFalse(feedback_widget_enabled('demo', 'front'));
        self::assertTrue(feedback_portail_widget_enabled());
        self::assertFalse(feedback_widget_enabled('demo', 'portail'), 'Le portail ne passe pas par la liste des boutiques.');

        feedback_settings_save(['widget' => false, 'surfaces' => ['admin' => true, 'front' => true, 'portail' => true]] + $this->baseSettings());
        self::assertFalse(feedback_widget_enabled('demo', 'admin'));
        self::assertFalse(feedback_portail_widget_enabled());
    }

    public function testBackAndFrontRecordsCarryTheirSurface(): void
    {
        $team = $this->add('Retour envoyé depuis le back office.');
        $client = feedback_add([
            'shop' => 'demo', 'audience' => 'customer', 'email' => 'client@example.test',
            'type' => 'other', 'message' => 'Retour envoyé depuis le site public.',
        ], null, 'Client');
        self::assertSame('admin', $team['surface']);
        self::assertSame('front', $client['surface']);
        self::assertSame([$client['id']], array_column(feedback_list(['surface' => 'front']), 'id'));
        self::assertSame([$team['id']], array_column(feedback_list(['surface' => 'admin']), 'id'));
    }

    public function testFrontSubmissionRefusedWhenFrontSwitchIsOff(): void
    {
        feedback_settings_save(['surfaces' => ['admin' => true, 'front' => false, 'portail' => false]] + $this->baseSettings());
        $this->expectException(InvalidArgumentException::class);
        feedback_add([
            'shop' => 'demo', 'audience' => 'customer', 'email' => 'client@example.test',
            'type' => 'bug', 'message' => 'Le site public est désactivé pour les retours.',
        ], null, 'Client');
    }

    public function testRecordsWithoutSurfaceAreDerivedFromTheirSource(): void
    {
        self::assertSame('front', feedback_record_normalize(['id' => 'x', 'source' => 'client'])['surface']);
        self::assertSame('admin', feedback_record_normalize(['id' => 'x', 'source' => 'equipe'])['surface']);
    }

    public function testPortailFeedbackNeedsItsSwitchAndHasNoShop(): void
    {
        $in = ['surface' => 'portail', 'type' => 'idea', 'message' => 'Une idée pour le tableau du portail.'];
        try {
            feedback_add($in, null, 'Exploitant');
            self::fail('Le portail est fermé par défaut.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('portail', $e->getMessage());
        }

        feedback_settings_save(['surfaces' => ['admin' => true, 'front' => true, 'portail' => true]] + $this->baseSettings());
        $f = feedback_add($in + ['gallery' => 'rue-des-arts'], null, 'Exploitant');
        self::assertSame('portail', $f['surface']);
        self::assertSame('', $f['shop']);
        self::assertSame('Portail', $f['shop_name']);
        self::assertSame('rue-des-arts', $f['gallery']);
        self::assertSame('equipe', $f['source']);
    }

    public function testPortailRecordsAreScopedAndTrackUnreadReplies(): void
    {
        feedback_settings_save(['surfaces' => ['admin' => true, 'front' => true, 'portail' => true]] + $this->baseSettings());
        $shop = $this->add('Un retour de boutique qui ne doit pas apparaître au portail.');
        $all = feedback_add(['surface' => 'portail', 'type' => 'bug', 'message' => 'Retour portail de l\'exploitant sans galerie.'], null, 'Exploitant');
        $mine = feedback_add(['surface' => 'portail', 'gallery' => 'rue-des-arts', 'type' => 'bug', 'message' => 'Retour portail de la galerie Rue des Arts.'], null, 'Admin galerie');
        feedback_add(['surface' => 'portail', 'gallery' => 'autre-galerie', 'type' => 'bug', 'message' => 'Retour portail d\'une autre galerie.'], null, 'Autre');

        self::assertCount(3, feedback_portail_records());
        self::assertNotContains($shop['id'], array_column(feedback_portail_records(), 'id'));
        self::assertSame([$mine['id']], array_column(feedback_portail_records('rue-des-arts'), 'id'));

        feedback_reply($mine['id'], 'Merci, corrigé.', 'Exploitant', 'admin@example.test');
        self::assertSame(1, feedback_portail_unread('rue-des-arts'));
        self::assertSame(0, feedback_portail_unread('autre-galerie'));
        feedback_mark_portail_seen('rue-des-arts');
        self::assertSame(0, feedback_portail_unread('rue-des-arts'));
        self::assertSame(0, feedback_portail_unread());
        self::assertContains($all['id'], array_column(feedback_list(['surface' => 'portail']), 'id'));
    }

    public function testPortailFeedbackIsExcludedFromShopParticipation(): void
    {
        feedback_settings_save(['surfaces' => ['admin' => true, 'front' => true, 'portail' => true]] + $this->baseSettings());
        feedback_add(['surface' => 'portail', 'type' => 'other', 'message' => 'Retour portail hors participation.'], null, 'Exploitant');
        self::assertSame(0, feedback_stats()['active_shops_30']);
        self::assertSame(['demo'], array_column(feedback_participation(), 'shop'));
    }
}
