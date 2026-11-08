<?php

use PHPUnit\Framework\TestCase;

// Le module de vente démarre une session : on l'empêche d'envoyer des en-têtes pendant les tests.
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');

$tmp = sys_get_temp_dir() . '/vente-scope-test-' . getmypid();
@mkdir($tmp . '/galeries', 0775, true);
putenv('BROCANTE_GALLERIES_DIR=' . $tmp . '/galeries');
putenv('VENTE_DATA_DIR=' . $tmp . '/vente');

require_once __DIR__ . '/../portail/vente/_bootstrap.php';
require_once __DIR__ . '/../includes/vente-scope.php';

/**
 * Extension « Force de vente » d'une galerie : carnet séparé par galerie, reprise de l'ancienne liste JSON sans perte, désinscription,
 * relances encadrées et accès conservé pour les galeries qui avaient déjà des prospects.
 */
final class VenteScopeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) getenv('VENTE_DATA_DIR');
        foreach (glob(galleries_dir() . '/*') ?: [] as $f) @unlink($f);
        foreach (glob($this->dir . '/galeries/*') ?: [] as $f) @unlink($f);
    }

    private function legacy(string $slug, array $list): void
    {
        file_put_contents(galleries_dir() . '/' . $slug . '.prospects.json', json_encode($list));
    }

    private function carnet(string $slug): PDO
    {
        $file = vs_gallery_db_file($slug);
        @mkdir(dirname($file), 0775, true);
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        fv_schema($pdo);
        fv_migrate($pdo);
        return $pdo;
    }

    private function sample(): array
    {
        return [
            ['id' => 'aa11', 'name' => 'Boutique de Marie', 'email' => 'marie@exemple.fr', 'phone' => '06 12 34 56 78', 'note' => 'rencontrée au marché', 'status' => 'relance',
                'custom_fields' => ['Instagram' => '@marie'], 'created' => 1700000000, 'contacts' => [1700100000, 1700400000], 'optout' => false],
            ['id' => 'bb22', 'name' => 'Chez Paul', 'email' => '', 'phone' => '', 'note' => '', 'status' => 'refus', 'custom_fields' => [], 'created' => 1700000500, 'contacts' => [], 'optout' => true],
            ['id' => 'cc33', 'name' => 'Atelier Zoé', 'email' => 'zoe@exemple.fr', 'phone' => '', 'note' => '', 'status' => 'inscrit', 'created' => 1700001000],
        ];
    }

    public function testCarnetFilesAreSeparatePerGallery(): void
    {
        self::assertNotSame(vs_gallery_db_file('rue-a'), vs_gallery_db_file('rue-b'));
        self::assertStringEndsWith('/galeries/rue-a.db', vs_gallery_db_file('rue-a'));
        self::assertSame('', vs_gallery_db_file('../etc'));
        self::assertSame('/galerie/rue-a/vente/tournees.php', vs_gallery_url('rue-a', 'tournees.php'));
    }

    public function testMigrationCopiesEverythingAndKeepsTheSourceFile(): void
    {
        $this->legacy('rue-a', $this->sample());
        $pdo = $this->carnet('rue-a');
        [$added, $sent] = vs_gallery_migrate($pdo, 'rue-a');
        self::assertSame(3, $added);
        self::assertSame(2, $sent);

        $marie = $pdo->query("SELECT * FROM prospects WHERE legacy_id = 'aa11'")->fetch();
        self::assertSame('Boutique de Marie', $marie['business']);
        self::assertSame('contacte', $marie['status']);
        self::assertSame('rencontrée au marché', $marie['notes']);
        self::assertSame(['Instagram' => '@marie'], json_decode($marie['custom_fields'], true));
        self::assertSame('2023-11-14', substr($marie['created_at'], 0, 10));
        self::assertSame(1, (int) $pdo->query("SELECT optout FROM prospects WHERE legacy_id = 'bb22'")->fetchColumn());
        self::assertSame('perdu', $pdo->query("SELECT status FROM prospects WHERE legacy_id = 'bb22'")->fetchColumn());
        self::assertSame('boutique_creee', $pdo->query("SELECT status FROM prospects WHERE legacy_id = 'cc33'")->fetchColumn());
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM activities WHERE kind = 'email'")->fetchColumn());

        // Le fichier d'origine reste en place, une sauvegarde est posée à côté.
        self::assertFileExists(ga_file('rue-a', 'prospects'));
        self::assertNotEmpty(glob(ga_file('rue-a', 'prospects') . '.bak-*'));
        self::assertCount(3, ga_read('rue-a', 'prospects'));
    }

    public function testMigrationIsRepeatableWithoutDuplicates(): void
    {
        $this->legacy('rue-a', $this->sample());
        $pdo = $this->carnet('rue-a');
        vs_gallery_migrate($pdo, 'rue-a');
        self::assertSame([0, 0], vs_gallery_migrate($pdo, 'rue-a'));
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM prospects')->fetchColumn());
        // Même sans le drapeau de fin, un identifiant déjà repris n'est pas copié deux fois.
        $pdo->exec("DELETE FROM settings WHERE name = 'migration_json'");
        vs_gallery_migrate($pdo, 'rue-a');
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM prospects')->fetchColumn());
    }

    public function testGalleryWithoutOldListGetsAnEmptyCarnet(): void
    {
        $pdo = $this->carnet('rue-b');
        self::assertSame([0, 0], vs_gallery_migrate($pdo, 'rue-b'));
        self::assertTrue(vs_gallery_migrated('rue-b'));
        self::assertSame([], ga_prospects('rue-b'));
    }

    public function testGaProspectsReadsTheCarnetOnceMigrated(): void
    {
        $this->legacy('rue-a', $this->sample());
        self::assertCount(3, ga_prospects('rue-a'));            // pas encore repris : le fichier d'origine
        $pdo = $this->carnet('rue-a');
        vs_gallery_migrate($pdo, 'rue-a');
        $pdo->prepare("UPDATE prospects SET notes = 'modifiée' WHERE legacy_id = 'aa11'")->execute();
        $list = ga_prospects('rue-a');                          // maintenant : le carnet fait foi
        self::assertCount(3, $list);
        self::assertSame('aa11', $list[0]['id']);
        self::assertSame('Boutique de Marie', $list[0]['name']);
        self::assertSame('modifiée', $list[0]['note']);
        self::assertSame('relance', $list[0]['status'] === 'contacte' ? 'relance' : $list[0]['status']);
        self::assertCount(2, $list[0]['contacts']);
        self::assertTrue($list[1]['optout']);
        self::assertSame('refus', $list[1]['status']);
        self::assertSame('inscrit', $list[2]['status']);
    }

    public function testOldUnsubscribeLinkStillWorks(): void
    {
        $this->legacy('rue-a', $this->sample());
        $token = ga_token('rue-a', 'prospect', 'aa11');
        // Avant la reprise : le fichier d'origine reçoit la désinscription, la reprise la conserve.
        self::assertTrue(ga_stop('rue-a', 'prospect', 'aa11', $token));
        $pdo = $this->carnet('rue-a');
        vs_gallery_migrate($pdo, 'rue-a');
        self::assertSame(1, (int) $pdo->query("SELECT optout FROM prospects WHERE legacy_id = 'aa11'")->fetchColumn());
        // Après la reprise : le carnet reçoit la désinscription d'un autre ancien lien.
        self::assertTrue(ga_stop('rue-a', 'prospect', 'cc33', ga_token('rue-a', 'prospect', 'cc33')));
        self::assertSame(1, (int) $pdo->query("SELECT optout FROM prospects WHERE legacy_id = 'cc33'")->fetchColumn());
        // Un jeton falsifié ne désinscrit personne.
        self::assertFalse(ga_stop('rue-a', 'prospect', 'bb22', 'faux'));
    }

    public function testSendRulesProtectProspects(): void
    {
        $this->legacy('rue-a', $this->sample());
        $pdo = $this->carnet('rue-a');
        vs_gallery_migrate($pdo, 'rue-a');
        $g = ['slug' => 'rue-a', 'name' => 'Rue A', 'type' => 'puces'];
        $id = fn (string $legacy): int => (int) $pdo->query("SELECT id FROM prospects WHERE legacy_id = '$legacy'")->fetchColumn();
        $body = "Bonjour {nom}, voici une proposition pour rejoindre la galerie.\n{desinscription}";

        self::assertStringContainsString('{desinscription}', (string) vs_gallery_send($pdo, $g, $id('aa11'), 'Objet', 'Bonjour, un long message sans lien de retrait.'));
        self::assertSame('Rédigez un objet et un message.', vs_gallery_send($pdo, $g, $id('aa11'), '', $body));
        self::assertStringContainsString("pas d'adresse", (string) vs_gallery_send($pdo, $g, $id('bb22'), 'Objet', $body));
        $pdo->exec("UPDATE prospects SET optout = 1 WHERE legacy_id = 'cc33'");
        self::assertStringContainsString('ne plus être contacté', (string) vs_gallery_send($pdo, $g, $id('cc33'), 'Objet', $body));
        // Marie a reçu 2 messages dont le dernier en novembre 2023 : un troisième partirait, mais pas 4 déjà reçus.
        $pdo->prepare("INSERT INTO activities (prospect_id, member_id, kind, outcome, notes, occurred_at) VALUES (?, 0, 'email', 'envoye', ?, ?), (?, 0, 'email', 'envoye', ?, ?)")
            ->execute([$id('aa11'), VS_SENT_NOTE, '2023-12-01 10:00:00', $id('aa11'), VS_SENT_NOTE, '2023-12-10 10:00:00']);
        self::assertStringContainsString('plus relancé', (string) vs_gallery_send($pdo, $g, $id('aa11'), 'Objet', $body));
        // Un message tout récent bloque l'envoi suivant.
        $pdo->exec("DELETE FROM activities");
        $pdo->prepare("INSERT INTO activities (prospect_id, member_id, kind, outcome, notes, occurred_at) VALUES (?, 0, 'email', 'envoye', ?, ?)")
            ->execute([$id('aa11'), VS_SENT_NOTE, date('Y-m-d H:i:s')]);
        self::assertStringContainsString('moins de 3 jours', (string) vs_gallery_send($pdo, $g, $id('aa11'), 'Objet', $body));
    }

    public function testGalleryWithOldProspectsKeepsAccessToTheExtension(): void
    {
        $withList = ['slug' => 'rue-a', 'name' => 'Rue A', 'type' => 'puces'];
        $without = ['slug' => 'rue-b', 'name' => 'Rue B', 'type' => 'puces'];
        $this->legacy('rue-a', $this->sample());
        self::assertTrue(gm_enabled($withList, 'vente'));
        self::assertSame('complimentary', gm_state($withList, 'vente')['status']);
        self::assertFalse(gm_enabled($without, 'vente'));
        self::assertNotNull(gm_module('vente'));
    }
}
