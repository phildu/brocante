<?php

use PHPUnit\Framework\TestCase;

// Tout est écrit dans un dossier jetable : fichiers (BROCANTE_EXCHANGE_ROOT) et galeries (BROCANTE_GALLERIES_DIR).
require_once __DIR__ . '/../includes/exchange.php';

/**
 * Échange de boutiques et de galeries entre environnements (includes/exchange.php) : ce qui voyage, ce qui ne voyage pas,
 * fusion sans perte, fichiers jamais écrasés, paquet .zip aller-retour.
 */
final class ExchangeTest extends TestCase
{
    private string $dir;
    private ?string $oldRoot;
    private ?string $oldGalleries;

    // PNG 1×1 valide.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/echange-test-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/galeries', 0775, true);
        mkdir($this->dir . '/uploads', 0775, true);
        $this->oldRoot = getenv('BROCANTE_EXCHANGE_ROOT') ?: null;
        $this->oldGalleries = getenv('BROCANTE_GALLERIES_DIR') ?: null;
        putenv('BROCANTE_EXCHANGE_ROOT=' . $this->dir);
        putenv('BROCANTE_GALLERIES_DIR=' . $this->dir . '/galeries');
    }

    protected function tearDown(): void
    {
        putenv($this->oldRoot === null ? 'BROCANTE_EXCHANGE_ROOT' : 'BROCANTE_EXCHANGE_ROOT=' . $this->oldRoot);
        putenv($this->oldGalleries === null ? 'BROCANTE_GALLERIES_DIR' : 'BROCANTE_GALLERIES_DIR=' . $this->oldGalleries);
        $this->rrmdir($this->dir);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) continue;
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function shopDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
        return $pdo;
    }

    private function product(string $ref, string $name, array $extra = []): array
    {
        return array_replace(['ref' => $ref, 'name' => $name, 'cat' => 'divers', 'price' => '10 €', 'stock' => 1], $extra);
    }

    private function table(PDO $pdo, string $name, array $rows): array
    {
        return ['columns' => exchange_table_columns($pdo, $name), 'ddl' => '', 'rows' => $rows];
    }

    /* ---------- chemins ---------- */

    public function testScanFindsOnlySafePathsEvenInsideStoredJson(): void
    {
        $data = ['a' => 'uploads/p1.jpg', 'b' => ['x' => json_encode(['banner' => 'uploads/galeries/g-ban-1.png', 'bad' => 'uploads/../secret.jpg', 'imp' => 'uploads/import/t.jpg'])],
            'c' => 'assets/tenants/naty/logo.svg', 'd' => 'texte sans chemin', 'e' => 'voir uploads/p2.webp.'];
        self::assertSame(
            ['assets/tenants/naty/logo.svg', 'uploads/galeries/g-ban-1.png', 'uploads/p1.jpg', 'uploads/p2.webp'],
            exchange_scan_paths($data)
        );
    }

    public function testRewriteReplacesRenamedPathsEverywhere(): void
    {
        $out = exchange_rewrite_paths(['p' => 'uploads/a.jpg', 'n' => [json_encode(['k' => 'uploads/a.jpg'])], 'o' => 'uploads/b.jpg', 'i' => 5], ['uploads/a.jpg' => 'uploads/ax12345678.jpg']);
        self::assertSame('uploads/ax12345678.jpg', $out['p']);
        self::assertStringContainsString('uploads\\/ax12345678.jpg', $out['n'][0]);   // la forme échappée du JSON reste échappée
        self::assertSame('uploads/b.jpg', $out['o']);
        self::assertSame(5, $out['i']);
    }

    /* ---------- ce qui voyage ---------- */

    public function testShopConfigKeepsPresentationAndDropsSecretsAndAddresses(): void
    {
        $picked = exchange_shop_config_pick([
            'name' => 'Fripe', 'tagline' => 'x', 'site_url' => 'https://prod.example', 'admin_user' => 'admin', 'admin_password' => '$2y$hash',
            'db_file' => 'data/f.db', 'secrets_dir' => '.secrets/f', 'sales_enabled' => false, 'stripe_secret' => 'sk_live_x',
            'colors' => ['bg' => '#fff'], 'categories' => [['key' => 'a', 'label' => 'A', 'icon' => 'ic-vase']],
        ]);
        self::assertSame(['name', 'tagline', 'colors', 'categories'], array_keys($picked));
    }

    public function testSnapshotOnlyKeepsAllowedSettings(): void
    {
        $pdo = $this->shopDb();
        $ins = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
        foreach (['appearance' => '{}', 'opening_hours' => 'lun-ven', 'sepa_iban' => 'FR76…', 'badge_secret' => 'zzz', 'admin_password_override' => 'x', 'visitor_salt' => 's'] as $k => $v) {
            $ins->execute([$k, $v]);
        }
        $names = array_column(exchange_db_snapshot($pdo)['settings']['rows'], 'name');
        sort($names);
        self::assertSame(['appearance', 'opening_hours'], $names);
    }

    public function testSnapshotNeverIncludesOrdersOrAccounts(): void
    {
        $snap = exchange_db_snapshot($this->shopDb());
        foreach (['orders', 'accounts', 'visits', 'admin_users', 'mail_opens'] as $table) {
            self::assertArrayNotHasKey($table, $snap);
        }
        self::assertArrayHasKey('products', $snap);
    }

    /* ---------- écriture en base ---------- */

    public function testImportAddsAndUpdatesWithoutDeletingByDefault(): void
    {
        $pdo = $this->shopDb();
        $pdo->exec("INSERT INTO products (ref, name, cat, price, stock) VALUES ('001', 'Ancien nom', 'divers', '5 €', 0), ('999', 'Propre à la cible', 'divers', '1 €', 3)");
        $tables = ['products' => $this->table($pdo, 'products', [$this->product('001', 'Nouveau nom', ['stock' => 7]), $this->product('002', 'Nouvelle fiche')])];
        $r = exchange_db_apply($pdo, $tables, ['keep_stock' => false]);
        self::assertSame(1, $r['stats']['products_new']);
        self::assertSame(1, $r['stats']['products_updated']);
        self::assertSame(0, $r['stats']['products_removed']);
        $rows = $pdo->query('SELECT ref, name, stock FROM products ORDER BY ref')->fetchAll();
        self::assertSame(['001', '002', '999'], array_column($rows, 'ref'));
        self::assertSame('Nouveau nom', $rows[0]['name']);
        self::assertSame(7, (int) $rows[0]['stock']);
    }

    public function testKeepStockPreservesTargetStockAndSaleState(): void
    {
        $pdo = $this->shopDb();
        $pdo->exec("INSERT INTO products (ref, name, cat, price, stock) VALUES ('001', 'Lampe', 'divers', '5 €', 0)");
        $r = exchange_db_apply($pdo, ['products' => $this->table($pdo, 'products', [$this->product('001', 'Lampe 2', ['stock' => 9])])]);
        self::assertSame(1, $r['stats']['products_updated']);
        $row = $pdo->query("SELECT name, stock FROM products WHERE ref = '001'")->fetch();
        self::assertSame('Lampe 2', $row['name']);
        self::assertSame(0, (int) $row['stock']);
    }

    public function testDeleteMissingMirrorsThePackage(): void
    {
        $pdo = $this->shopDb();
        $pdo->exec("INSERT INTO products (ref, name, cat) VALUES ('001', 'A', 'x'), ('002', 'B', 'x')");
        $pdo->exec("INSERT INTO product_photos (product_ref, path) VALUES ('002', 'uploads/b.jpg')");
        $r = exchange_db_apply($pdo, ['products' => $this->table($pdo, 'products', [$this->product('001', 'A')])], ['delete_missing' => true]);
        self::assertSame(1, $r['stats']['products_removed']);
        self::assertSame(['001'], $pdo->query('SELECT ref FROM products')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM product_photos')->fetchColumn());
    }

    public function testPhotosAreReplacedPerProductAndCutoutLinkFollowsNewIds(): void
    {
        $pdo = $this->shopDb();
        $pdo->exec("INSERT INTO products (ref, name, cat) VALUES ('001', 'A', 'x'), ('777', 'Autre', 'x')");
        $pdo->exec("INSERT INTO product_photos (product_ref, path) VALUES ('001', 'uploads/old.jpg'), ('777', 'uploads/keep.jpg')");
        $tables = [
            'products' => $this->table($pdo, 'products', [$this->product('001', 'A')]),
            'product_photos' => $this->table($pdo, 'product_photos', [
                ['id' => 40, 'product_ref' => '001', 'path' => 'uploads/a.jpg', 'type' => 'photo', 'label' => 'Photo', 'sort_order' => 0],
                ['id' => 41, 'product_ref' => '001', 'path' => 'uploads/a-cut.png', 'type' => 'photo', 'label' => 'Détourée', 'sort_order' => 1, 'cutout_of' => 40],
                ['id' => 42, 'product_ref' => '404', 'path' => 'uploads/orpheline.jpg'],
            ]),
        ];
        exchange_db_apply($pdo, $tables);
        $paths = $pdo->query("SELECT path FROM product_photos WHERE product_ref = '001' ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['uploads/a.jpg', 'uploads/a-cut.png'], $paths);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM product_photos WHERE product_ref = '777'")->fetchColumn());
        $cut = $pdo->query("SELECT cutout_of FROM product_photos WHERE path = 'uploads/a-cut.png'")->fetchColumn();
        self::assertSame('uploads/a.jpg', $pdo->query('SELECT path FROM product_photos WHERE id = ' . (int) $cut)->fetchColumn());
    }

    public function testSettingsFromPackageAreFilteredAgain(): void
    {
        $pdo = $this->shopDb();
        exchange_db_apply($pdo, ['settings' => $this->table($pdo, 'settings', [
            ['name' => 'opening_notice', 'value' => 'Fermé le 1er'], ['name' => 'sepa_iban', 'value' => 'FR76'], ['name' => 'admin_password_override', 'value' => 'pwned'],
        ])]);
        self::assertSame(['opening_notice'], $pdo->query('SELECT name FROM settings')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testReplaceTablesAndUnknownColumnsAreHandled(): void
    {
        $pdo = $this->shopDb();
        $pdo->exec("INSERT INTO hero_slides (path, caption) VALUES ('uploads/old.jpg', 'ancienne')");
        $src = $this->table($pdo, 'hero_slides', [['id' => 1, 'path' => 'uploads/new.jpg', 'caption' => 'neuve', 'colonne_future' => 'x']]);
        $src['columns'][] = ['name' => 'colonne_future', 'type' => 'TEXT'];
        $r = exchange_db_apply($pdo, ['hero_slides' => $src]);
        self::assertSame(1, $r['stats']['tables']['hero_slides']);
        self::assertSame([['uploads/new.jpg', 'x']], array_map('array_values', $pdo->query('SELECT path, colonne_future FROM hero_slides')->fetchAll()));
    }

    public function testFailureRollsBackEverything(): void
    {
        $pdo = $this->shopDb();
        $pdo->exec("INSERT INTO products (ref, name, cat) VALUES ('001', 'Avant', 'x')");
        $bad = $this->table($pdo, 'media_library', [['id' => 1, 'type' => 'photo', 'path' => null]]);   // path NOT NULL
        try {
            exchange_db_apply($pdo, ['products' => $this->table($pdo, 'products', [$this->product('001', 'Après')]), 'media_library' => $bad]);
            self::fail('Une exception était attendue.');
        } catch (PDOException $e) {
            self::assertSame('Avant', $pdo->query("SELECT name FROM products WHERE ref = '001'")->fetchColumn());
        }
    }

    /* ---------- fichiers ---------- */

    private function tmpFile(string $content): string
    {
        $f = tempnam($this->dir, 'tmp');
        file_put_contents($f, $content);
        return $f;
    }

    public function testStoreFileNewSameAndRenamedWithoutOverwriting(): void
    {
        $png = base64_decode(self::PNG);
        $r = exchange_store_file('uploads/a.png', sha1($png), $this->tmpFile($png));
        self::assertTrue($r['ok']);
        self::assertSame('new', $r['state']);
        self::assertFileExists($this->dir . '/uploads/a.png');

        $again = exchange_store_file('uploads/a.png', sha1($png), $this->tmpFile($png));
        self::assertSame('same', $again['state']);
    }

    public function testRenamedFileKeepsTheOriginalIntact(): void
    {
        $png = base64_decode(self::PNG);
        file_put_contents($this->dir . '/uploads/b.png', 'ORIGINAL');
        $status = exchange_file_status('uploads/b.png', sha1($png));
        self::assertSame('renamed', $status['state']);
        self::assertSame('uploads/bx' . substr(sha1($png), 0, 8) . '.png', $status['target']);
        $r = exchange_store_file('uploads/b.png', sha1($png), $this->tmpFile($png));
        self::assertTrue($r['ok']);
        self::assertSame('ORIGINAL', file_get_contents($this->dir . '/uploads/b.png'));
        self::assertFileExists($this->dir . '/' . $status['target']);
        self::assertSame('same', exchange_file_status('uploads/b.png', sha1($png))['state']);
    }

    public function testStoreFileRefusesCorruptedDisguisedAndUnsafe(): void
    {
        $png = base64_decode(self::PNG);
        self::assertFalse(exchange_store_file('uploads/c.png', str_repeat('0', 40), $this->tmpFile($png))['ok']);
        $php = '<?php echo 1;';
        self::assertFalse(exchange_store_file('uploads/d.jpg', sha1($php), $this->tmpFile($php))['ok']);
        self::assertFalse(exchange_store_file('uploads/../e.png', sha1($png), $this->tmpFile($png))['ok']);
        self::assertFalse(exchange_store_file('index.php', sha1($png), $this->tmpFile($png))['ok']);
        self::assertFileDoesNotExist($this->dir . '/uploads/d.jpg');
    }

    /* ---------- galeries et paquet .zip ---------- */

    private function makeGallery(string $slug, array $extra = []): void
    {
        $png = base64_decode(self::PNG);
        mkdir($this->dir . '/uploads/galeries', 0775, true);
        file_put_contents($this->dir . '/uploads/galeries/' . $slug . '-logo.png', $png);
        self::assertTrue(gallery_save($extra + [
            'slug' => $slug, 'name' => 'Rue des Arts', 'tagline' => 'Marché', 'published' => true,
            'style' => ['logo' => 'uploads/galeries/' . $slug . '-logo.png'],
            'admins' => [['email' => 'prod@example.com', 'name' => 'Prod', 'hash' => password_hash('secret', PASSWORD_DEFAULT), 'must_change' => false, 'created' => 1, 'last_login' => 0]],
            'domain' => 'rue-des-arts.fr',
            'shooting' => ['token' => 'tok', 'enabled' => true],
            'modules' => ['carte' => ['enabled' => true, 'status' => 'active', 'price_cents' => 900]],
            'event' => ['title' => 'Vide-grenier', 'date' => '2026-12-01'],
        ]));
    }

    public function testGalleryExportDropsAccessAndBillingData(): void
    {
        $this->makeGallery('rue-des-arts');
        $e = exchange_gallery_export('rue-des-arts');
        $data = $e['package']['data'];
        foreach (['admins', 'domain', 'shooting', 'modules', 'plan', 'published', 'slug'] as $key) {
            self::assertArrayNotHasKey($key, $data);
        }
        self::assertSame('Vide-grenier', $data['event']['title']);
        self::assertSame(['uploads/galeries/rue-des-arts-logo.png'], $e['files']);
    }

    public function testZipRoundTripCreatesGalleryOfflineAndKeepsFiles(): void
    {
        $this->makeGallery('rue-des-arts');
        $zip = $this->dir . '/paquet.zip';
        $manifest = exchange_build_zip([], ['rue-des-arts'], $zip);
        self::assertSame(EXCHANGE_FORMAT, $manifest['format']);
        self::assertSame(['uploads/galeries/rue-des-arts-logo.png'], array_keys($manifest['files']));

        // Autre environnement : dossier vide.
        $target = sys_get_temp_dir() . '/echange-target-' . bin2hex(random_bytes(4));
        mkdir($target . '/galeries', 0775, true);
        putenv('BROCANTE_EXCHANGE_ROOT=' . $target);
        putenv('BROCANTE_GALLERIES_DIR=' . $target . '/galeries');
        try {
            $plan = exchange_plan($zip);
            self::assertFalse($plan['galleries']['rue-des-arts']['exists']);
            self::assertSame(1, $plan['galleries']['rue-des-arts']['files']['new']);

            $res = exchange_import_zip($zip, [], ['rue-des-arts'], [], 'https://pre.example');
            self::assertTrue($res[0]['ok']);
            self::assertTrue($res[0]['created']);
            $g = gallery_load('rue-des-arts');
            self::assertFalse($g['published'], 'une galerie importée est créée hors ligne');
            self::assertSame([], $g['admins']);
            self::assertSame('', $g['domain']);
            self::assertSame('Vide-grenier', $g['event']['title']);
            self::assertFileExists($target . '/uploads/galeries/rue-des-arts-logo.png');
            self::assertFileExists($target . '/data/logs/echange.log');
        } finally {
            $this->rrmdir($target);
        }
    }

    public function testGalleryUpdateKeepsTargetAdminsDomainAndPublication(): void
    {
        $this->makeGallery('rue-des-arts');
        $package = exchange_gallery_export('rue-des-arts')['package'];
        $package['data']['name'] = 'Rue des Arts (v2)';
        $package['data']['event']['title'] = 'Nouveau titre';

        // La cible a sa propre version : autres administrateurs, domaine, hors ligne.
        $existing = gallery_load('rue-des-arts');
        $existing['admins'] = [['email' => 'cible@example.com', 'name' => 'Cible', 'hash' => 'h', 'must_change' => false, 'created' => 2, 'last_login' => 0]];
        $existing['domain'] = 'cible.fr';
        $existing['published'] = false;
        gallery_save($existing);

        $r = exchange_gallery_import($package);
        self::assertFalse($r['created']);
        self::assertFileExists($r['backup']);
        $g = gallery_load('rue-des-arts');
        self::assertSame('Rue des Arts (v2)', $g['name']);
        self::assertSame('Nouveau titre', $g['event']['title']);
        self::assertSame('cible@example.com', $g['admins'][0]['email']);
        self::assertSame('cible.fr', $g['domain']);
        self::assertFalse($g['published']);
    }

    public function testGalleryHoursTravelWithoutTheAlertLog(): void
    {
        $this->makeGallery('rue-des-arts');
        require_once __DIR__ . '/../includes/gallery-hours.php';
        gh_save('rue-des-arts', null, [], 'Fermé le 25 décembre');
        $e = exchange_gallery_export('rue-des-arts');
        self::assertSame('Fermé le 25 décembre', $e['package']['hours']['notice']);
        self::assertArrayNotHasKey('alerts', $e['package']['hours']);

        $e['package']['hours']['notice'] = 'Ouvert tous les jours';
        $r = exchange_gallery_import($e['package']);
        self::assertSame([], array_filter($r['warnings'], static fn ($w) => str_contains($w, 'horaires')));
        self::assertSame('Ouvert tous les jours', gh_read('rue-des-arts')['notice']);
    }

    public function testGallerySponsorsTravelWithoutTheInternalNote(): void
    {
        $this->makeGallery('rue-des-arts');
        require_once __DIR__ . '/../includes/gallery-sponsors.php';
        gsp_save('rue-des-arts', [['id' => 'aaaaaaaa', 'name' => 'Mairie', 'tier' => 'institutionnel', 'note' => 'contact : Mme X']]);
        $e = exchange_gallery_export('rue-des-arts');
        self::assertSame('Mairie', $e['package']['sponsors'][0]['name']);
        self::assertArrayNotHasKey('note', $e['package']['sponsors'][0]);

        // Cible : la fiche existe déjà avec sa propre note, une autre disparaît au profit du paquet.
        gsp_save('rue-des-arts', [['id' => 'aaaaaaaa', 'name' => 'Ancien nom', 'note' => 'note locale'], ['id' => 'bbbbbbbb', 'name' => 'Autre']]);
        $r = exchange_gallery_import($e['package']);
        self::assertSame([], array_filter($r['warnings'], static fn ($w) => str_contains($w, 'sponsors')));
        $list = gsp_load('rue-des-arts');
        self::assertCount(1, $list);
        self::assertSame('Mairie', $list[0]['name']);
        self::assertSame('note locale', $list[0]['note']);
    }

    public function testGalleryImportWarnsAboutMissingLocalMembers(): void
    {
        $r = exchange_gallery_import(['slug' => 'avec-membres', 'name' => 'X', 'data' => ['name' => 'X', 'members' => [['type' => 'local', 'slug' => 'boutique-inexistante-xyz']]]]);
        self::assertTrue($r['created']);
        self::assertStringContainsString('boutique-inexistante-xyz', implode(' ', $r['warnings']));
    }

    public function testReadZipRefusesForeignArchives(): void
    {
        $notZip = $this->dir . '/pas-un-zip.zip';
        file_put_contents($notZip, 'texte');
        try {
            exchange_read_zip($notZip);
            self::fail('Exception attendue');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('paquet', $e->getMessage());
        }

        $foreign = $this->dir . '/autre.zip';
        $z = new ZipArchive();
        $z->open($foreign, ZipArchive::CREATE);
        $z->addFromString('manifest.json', json_encode(['app' => 'autre-chose', 'format' => 1]));
        $z->close();
        $this->expectException(RuntimeException::class);
        exchange_read_zip($foreign);
    }

    public function testReadZipRefusesNewerFormat(): void
    {
        $f = $this->dir . '/futur.zip';
        $z = new ZipArchive();
        $z->open($f, ZipArchive::CREATE);
        $z->addFromString('manifest.json', json_encode(['app' => EXCHANGE_APP, 'format' => EXCHANGE_FORMAT + 1]));
        $z->close();
        $this->expectExceptionMessage('non pris en charge');
        exchange_read_zip($f);
    }

    public function testReadZipIgnoresUnsafeFilePaths(): void
    {
        $f = $this->dir . '/piege.zip';
        $z = new ZipArchive();
        $z->open($f, ZipArchive::CREATE);
        $z->addFromString('manifest.json', json_encode(['app' => EXCHANGE_APP, 'format' => EXCHANGE_FORMAT, 'shops' => [], 'galleries' => [],
            'files' => ['uploads/../../index.php' => ['sha1' => str_repeat('a', 40)], 'index.php' => ['sha1' => str_repeat('a', 40)]]]));
        $z->addFromString('files/uploads/../../index.php', 'x');
        $z->addFromString('files/index.php', 'x');
        $z->close();
        self::assertSame([], exchange_read_zip($f)['manifest']['files']);
    }
}
