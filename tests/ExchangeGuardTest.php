<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/exchange.php';
require_once __DIR__ . '/../scripts/exchange-baseline.php';

/**
 * Garde-fou de l'échange prod / préprod : à chaque évolution du projet (nouvelle table, nouvelle clé de boutique ou de galerie,
 * nouveau réglage, nouveau fichier annexe de galerie), la CI échoue tant qu'on n'a pas décidé si l'élément voyage.
 * Procédure : voir scripts/exchange-baseline.php.
 */
final class ExchangeGuardTest extends TestCase
{
    private static function baseline(): array
    {
        $data = is_file(EXCHANGE_BASELINE_FILE) ? json_decode((string) file_get_contents(EXCHANGE_BASELINE_FILE), true) : null;
        self::assertIsArray($data, 'tests/fixtures/exchange-baseline.json manquant : lancer php scripts/exchange-baseline.php');
        return $data;
    }

    private static function newItems(string $category): array
    {
        $scan = exchange_scan_project(dirname(__DIR__));
        return array_values(array_diff($scan[$category], self::baseline()[$category] ?? []));
    }

    private static function message(string $what, array $items, string $constant): string
    {
        return "Nouveau(x) $what non encore examiné(s) pour l'échange prod / préprod : " . implode(', ', $items) . ".\n"
            . "Décidez : s'il doit voyager, ajoutez-le à $constant dans includes/exchange.php ; sinon rien à écrire.\n"
            . "Puis lancez « php scripts/exchange-baseline.php » et commitez tests/fixtures/exchange-baseline.json (voir DEPLOIEMENT.md, « Échange de boutiques et de galeries »).";
    }

    public function testNoNewTableWithoutADecision(): void
    {
        self::assertSame([], self::newItems('tables'), self::message('table(s) SQL', self::newItems('tables'), 'EXCHANGE_SHOP_TABLES'));
    }

    public function testNoNewGalleryKeyWithoutADecision(): void
    {
        self::assertSame([], self::newItems('gallery_keys'), self::message('clé(s) de galerie', self::newItems('gallery_keys'), 'EXCHANGE_GALLERY_KEYS'));
    }

    public function testNoNewShopConfigKeyWithoutADecision(): void
    {
        self::assertSame([], self::newItems('tenant_keys'), self::message('clé(s) de configuration de boutique (tenant.php)', self::newItems('tenant_keys'), 'EXCHANGE_SHOP_CONFIG_KEYS'));
    }

    public function testNoNewSettingWithoutADecision(): void
    {
        self::assertSame([], self::newItems('settings'), self::message('réglage(s) nommé(s) (table settings)', self::newItems('settings'), 'EXCHANGE_SETTINGS_ALLOWED'));
    }

    public function testNoNewGalleryFileWithoutADecision(): void
    {
        self::assertSame([], self::newItems('gallery_files'), self::message('fichier(s) annexe(s) de galerie', self::newItems('gallery_files'), 'EXCHANGE_GALLERY_FILES_CARRIED'));
    }

    /** Ce qui voyage doit exister : une table ou une clé renommée ou supprimée ne doit pas rester dans les listes de l'échange. */
    public function testCarriedItemsStillExist(): void
    {
        $scan = exchange_scan_project(dirname(__DIR__));
        self::assertSame([], array_values(array_diff(EXCHANGE_SHOP_TABLES, $scan['tables'])), 'Table de EXCHANGE_SHOP_TABLES introuvable dans le projet (renommée ?).');
        self::assertSame([], array_values(array_diff(EXCHANGE_GALLERY_KEYS, $scan['gallery_keys'])), 'Clé de EXCHANGE_GALLERY_KEYS que gallery_normalize() ne produit plus.');
        self::assertSame([], array_values(array_diff(EXCHANGE_GALLERY_FILES_CARRIED, $scan['gallery_files'])), 'Fichier annexe de EXCHANGE_GALLERY_FILES_CARRIED introuvable dans le code.');
    }

    /** Ce qui ne doit jamais voyager n'est dans aucune liste de l'échange. */
    public function testNeverCarrySecretsOrPersonalData(): void
    {
        $forbiddenTables = ['orders', 'accounts', 'customer_tokens', 'customer_hits', 'admin_users', 'admin_remember', 'password_resets', 'visits', 'stripe_events', 'mail_opens', 'ai_usage', 'consent_log'];
        self::assertSame([], array_values(array_intersect(EXCHANGE_SHOP_TABLES, $forbiddenTables)));
        self::assertSame([], array_values(array_intersect(EXCHANGE_SHOP_CONFIG_KEYS, ['admin_user', 'admin_password', 'site_url', 'db_file', 'secrets_dir', 'seed_file', 'sales_enabled'])));
        self::assertSame([], array_values(array_intersect(EXCHANGE_GALLERY_KEYS, ['admins', 'plan', 'gesture', 'domain', 'modules', 'shooting', 'apps', 'published', 'paused', 'slug', 'created'])));
        foreach (EXCHANGE_SETTINGS_ALLOWED as $name) {
            self::assertDoesNotMatchRegularExpression('/(key|secret|token|password|iban|bic|stripe|salt|sms|whatsapp|smtp)/i', $name, "Réglage « $name » : ressemble à un secret, il ne doit pas voyager.");
        }
        foreach (EXCHANGE_GALLERY_FILES_CARRIED as $suffix) {
            self::assertNotContains($suffix, ['.db', '.waitlist.json', '.slots.json', '.messages.json', '.fidelite.json', '/cni/'], 'Donnée personnelle ou opérationnelle.');
        }
    }

    /**
     * Compatibilité de schéma : une base fraîche (schema.sql) remplie dans chaque table qui voyage se recopie intégralement dans une autre.
     * Échoue si une évolution du schéma (colonne obligatoire, table renommée…) casse l'import.
     */
    public function testEveryCarriedTableRoundTripsOnAFreshDatabase(): void
    {
        $make = static function (): PDO {
            $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec((string) file_get_contents(__DIR__ . '/../schema.sql'));
            return $pdo;
        };
        $src = $make();
        $src->exec("INSERT INTO content (id, site_name) VALUES (1, 'Ma boutique')");
        $src->exec("INSERT INTO products (ref, name, cat) VALUES ('001', 'Lampe', 'divers')");
        $src->exec("INSERT INTO product_photos (product_ref, path) VALUES ('001', 'uploads/a.jpg')");
        $src->exec("INSERT INTO hero_slides (path) VALUES ('uploads/h.jpg')");
        $src->exec("INSERT INTO slideshow_slides (kind, path) VALUES ('photo', 'uploads/s.jpg')");
        $src->exec("INSERT INTO page_banners (page_key, path) VALUES ('boutique', 'uploads/b.jpg')");
        $src->exec("INSERT INTO media_library (path) VALUES ('uploads/m.jpg')");
        $src->exec("INSERT INTO shipping_carriers (name) VALUES ('Colissimo')");
        $src->exec("INSERT INTO shipping_rates (carrier_id, service_name, weight_max_g, price_cents) VALUES (1, 'Standard', 1000, 690)");
        $src->exec("INSERT INTO settings (name, value) VALUES ('opening_hours', 'x')");

        $snapshot = exchange_db_snapshot($src);
        $dst = $make();
        $result = exchange_db_apply($dst, $snapshot);
        self::assertSame([], $result['warnings']);
        foreach (EXCHANGE_SHOP_TABLES as $table) {
            if (!isset($snapshot[$table])) continue;   // table créée plus tard par le code (ex. cart_discounts)
            self::assertSame(
                (int) $src->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn(),
                (int) $dst->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn(),
                "Table $table : lignes perdues à l'import."
            );
        }
        self::assertGreaterThanOrEqual(8, count($snapshot));
    }
}
