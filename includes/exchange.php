<?php

/**
 * Échange de boutiques et de galeries entre deux environnements (prod ↔ préprod ↔ local).
 *
 * Le portail (portail/echange.php) exporte des commerces et des galeries dans un paquet .zip, qu'un autre portail importe :
 *
 *   manifest.json           format, environnement d'origine, liste des éléments et des fichiers (empreinte SHA-1)
 *   shops/<identifiant>.json    boutique : réglages de présentation + tables de contenu (fiches, photos, bandeaux, livraison…)
 *   galleries/<identifiant>.json galerie : présentation, plan, membres, événement, horaires
 *   files/<chemin>          images et vidéos citées par les éléments ci-dessus (uploads/…, assets/tenants/<boutique>/…)
 *
 * Ce qui voyage, c'est le CONTENU. Ne voyagent jamais : commandes, clients, comptes, visites, mots de passe, clés de paiement
 * ou d'IA, adresses propres à un environnement (site_url, domaines), abonnements et options payantes des galeries, pièces
 * d'identité, inscriptions et listes d'attente. Une boutique créée par l'import reçoit un mot de passe d'administration neuf
 * (affiché une seule fois) ; une galerie créée par l'import est hors ligne tant qu'on ne la publie pas.
 *
 * L'import ne supprime rien par défaut : les fiches sont ajoutées ou mises à jour (par numéro), la base est sauvegardée avant
 * toute modification d'une boutique existante, et un fichier déjà présent sous le même nom avec un autre contenu n'est jamais
 * écrasé (le nouveau reçoit un nom voisin et les fiches sont réécrites en conséquence).
 */

require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/app-env.php';
require_once __DIR__ . '/galleries.php';

const EXCHANGE_FORMAT = 1;
const EXCHANGE_APP = 'brocante-echange';
const EXCHANGE_SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';
const EXCHANGE_JSON_MAX = 67108864;      // 64 Mo : un JSON du paquet au-delà est refusé
const EXCHANGE_FILE_MAX = 209715200;     // 200 Mo : un fichier du paquet au-delà est refusé

/** Réglages de tenant.php qui voyagent (le reste — identifiants, adresse, chemins de base — reste propre à chaque environnement). */
const EXCHANGE_SHOP_CONFIG_KEYS = ['name', 'tagline', 'presence_mode', 'logo', 'logo_macaron', 'colors', 'colors_dark', 'template', 'fonts', 'fonts_url', 'categories', 'texts', 'ai'];

/** Réglages d'une galerie qui voyagent. */
const EXCHANGE_GALLERY_KEYS = ['name', 'tagline', 'description', 'presence_mode', 'accent', 'style', 'app_icons', 'members', 'type', 'event', 'map', 'promos'];

/** Fichiers annexes d'une galerie (data/galeries/<id>…) qui voyagent : les horaires et les sponsors (sans leur note interne). Les autres (inscriptions, attente, messages, fidélité, journal, base) restent sur place. */
const EXCHANGE_GALLERY_FILES_CARRIED = ['.hours.json', '.sponsors.json'];

/** Tables de contenu d'une boutique, dans l'ordre d'écriture. */
const EXCHANGE_SHOP_TABLES = ['content', 'products', 'product_photos', 'hero_slides', 'slideshow_slides', 'page_banners', 'media_library', 'shipping_carriers', 'shipping_rates', 'cart_discounts', 'settings'];

/** Tables remplacées en bloc quand le paquet les contient (réglages d'ensemble, sans clé naturelle stable). */
const EXCHANGE_REPLACE_TABLES = ['slideshow_slides', 'hero_slides', 'page_banners', 'media_library', 'shipping_rates', 'shipping_carriers', 'cart_discounts'];

/** Réglages (table settings) qui voyagent : présentation et horaires. Rien qui ressemble à une clé, un secret ou une coordonnée bancaire. */
const EXCHANGE_SETTINGS_ALLOWED = [
    'appearance', 'universes', 'opening_hours', 'opening_closures', 'opening_notice', 'opening_closure_alerts',
    'slideshow_auto_promos', 'slideshow_idle_enabled', 'slideshow_idle_minutes', 'hide_shooting_photos',
    'product_condition_price_rates', 'legal_address', 'legal_lat', 'legal_lng', 'footer_note', 'shooting_custom_types',
];

/** Champs d'une fiche existante conservés par l'import quand « conserver le stock » est coché. */
const EXCHANGE_STOCK_COLUMNS = ['stock', 'sold_channel', 'sold_at', 'restocked_at'];

/* ---------- Outils : chemins, fichiers, JSON ---------- */

/** Racine des fichiers (uploads/, assets/) ; BROCANTE_EXCHANGE_ROOT : dossier jetable pour les tests. */
function exchange_root(): string
{
    return rtrim(getenv('BROCANTE_EXCHANGE_ROOT') ?: dirname(__DIR__), '/');
}

function exchange_slug_valid(string $slug): bool
{
    return (bool) preg_match(EXCHANGE_SLUG_PATTERN, $slug);
}

/** Chemin de fichier autorisé dans un paquet : uploads/… (comme la synchronisation) ou le logo d'une boutique. */
function exchange_file_path_ok(string $path): bool
{
    if (str_contains($path, '..')) return false;
    if (sync_safe_upload_path($path)) return true;
    return (bool) preg_match('#^assets/tenants/[a-z0-9][a-z0-9_-]*/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|webp|svg|gif)$#i', $path);
}

/** Chemins de fichiers cités dans n'importe quelle structure (chaînes, y compris dans du JSON stocké en texte). */
function exchange_scan_paths($data, array &$found = []): array
{
    if (is_array($data)) {
        foreach ($data as $value) exchange_scan_paths($value, $found);
    } elseif (is_string($data) && $data !== '' && (str_contains($data, 'uploads') || str_contains($data, 'assets'))) {
        $data = str_replace('\\/', '/', $data);   // JSON stocké en texte : « uploads\/x.jpg »
        preg_match_all('#(?:uploads|assets/tenants)/[A-Za-z0-9][A-Za-z0-9._/-]*#', $data, $m);
        foreach ($m[0] as $path) {
            $path = rtrim($path, '.');
            if (exchange_file_path_ok($path)) $found[$path] = true;
        }
    }
    $list = array_keys($found);
    sort($list);
    return $list;
}

/** Remplace des chemins (ancien → nouveau) dans toutes les chaînes d'une structure, y compris dans du JSON stocké en texte (« uploads\/… »). */
function exchange_rewrite_paths($data, array $map)
{
    if (!$map) return $data;
    foreach ($map as $from => $to) {
        $map[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
    }
    return exchange_rewrite_strings($data, $map);
}

function exchange_rewrite_strings($data, array $map)
{
    if (is_array($data)) {
        foreach ($data as $key => $value) $data[$key] = exchange_rewrite_strings($value, $map);
        return $data;
    }
    return is_string($data) && $data !== '' ? strtr($data, $map) : $data;
}

function exchange_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Valeur PHP lisible pour un tenant.php (tableaux indentés de 4 espaces). */
function exchange_php_export($value, int $level = 1): string
{
    if (!is_array($value)) return var_export($value, true);
    if (!$value) return '[]';
    $pad = str_repeat('    ', $level);
    $isList = array_is_list($value);
    $lines = [];
    foreach ($value as $key => $item) {
        $lines[] = $pad . ($isList ? '' : var_export($key, true) . ' => ') . exchange_php_export($item, $level + 1) . ',';
    }
    return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $level - 1) . ']';
}

/** Journal des échanges (data/logs/echange.log, une ligne JSON par opération ; jamais de mot de passe). */
function exchange_log(array $entry): void
{
    $dir = exchange_root() . '/data/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $entry = ['at' => date('c'), 'env' => app_env()] + $entry;
    @file_put_contents($dir . '/echange.log', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- Fichiers : état sur la cible et rangement ---------- */

/**
 * Où ira ce fichier sur la cible : 'new' (chemin libre), 'same' (déjà là, identique) ou 'renamed' (chemin pris par un autre
 * contenu : nom voisin). 'target' est le chemin à utiliser dans les fiches.
 */
function exchange_file_status(string $path, string $sha1): array
{
    $root = exchange_root();
    $abs = $root . '/' . $path;
    if (!is_file($abs)) return ['state' => 'new', 'target' => $path];
    if (sha1_file($abs) === $sha1) return ['state' => 'same', 'target' => $path];
    $alt = exchange_alt_path($path, $sha1);
    if (is_file($root . '/' . $alt) && sha1_file($root . '/' . $alt) === $sha1) return ['state' => 'same', 'target' => $alt];
    return ['state' => 'renamed', 'target' => $alt];
}

/** Nom voisin d'un fichier en conflit : sans tiret ajouté, pour que les noms normalisés des galeries (…-fond-<code>.jpg) restent valides. */
function exchange_alt_path(string $path, string $sha1): string
{
    $info = pathinfo($path);
    return ($info['dirname'] !== '.' ? $info['dirname'] . '/' : '') . $info['filename'] . 'x' . substr($sha1, 0, 8) . '.' . $info['extension'];
}

/** Range un fichier du paquet (déjà extrait dans $tmp) : empreinte et contenu vérifiés, jamais d'écrasement. */
function exchange_store_file(string $path, string $sha1, string $tmp): array
{
    if (!exchange_file_path_ok($path)) return ['ok' => false, 'error' => 'chemin refusé'];
    if (sha1_file($tmp) !== $sha1) return ['ok' => false, 'error' => 'fichier abîmé dans le paquet'];
    $status = exchange_file_status($path, $sha1);
    if ($status['state'] === 'same') return ['ok' => true] + $status;
    if (($err = sync_file_content_error($tmp, pathinfo($path, PATHINFO_EXTENSION))) !== null) return ['ok' => false, 'error' => $err];
    $abs = exchange_root() . '/' . $status['target'];
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['ok' => false, 'error' => 'dossier impossible à créer'];
    if (!@copy($tmp, $abs)) return ['ok' => false, 'error' => 'écriture impossible'];
    @chmod($abs, 0644);
    return ['ok' => true] + $status;
}

/* ---------- Base d'une boutique : lecture et écriture ---------- */

function exchange_setting_allowed(string $name): bool
{
    return in_array($name, EXCHANGE_SETTINGS_ALLOWED, true);
}

/** Colonnes d'une table : [['name' => …, 'type' => …], …] (liste vide si la table n'existe pas). */
function exchange_table_columns(PDO $pdo, string $table): array
{
    if (!in_array($table, EXCHANGE_SHOP_TABLES, true)) return [];
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = ['name' => (string) $c['name'], 'type' => strtoupper((string) $c['type'])];
    }
    return $cols;
}

/** Contenu des tables de la boutique, prêt à être mis dans un paquet. */
function exchange_db_snapshot(PDO $pdo): array
{
    $out = [];
    foreach (EXCHANGE_SHOP_TABLES as $table) {
        $columns = exchange_table_columns($pdo, $table);
        if (!$columns) continue;
        $rows = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
        if ($table === 'settings') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => exchange_setting_allowed((string) $r['name'])));
        }
        $ddl = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $ddl->execute([$table]);
        $out[$table] = ['columns' => $columns, 'ddl' => (string) $ddl->fetchColumn(), 'rows' => $rows];
    }
    return $out;
}

/** Crée la table (réglages d'ensemble absents d'une base ancienne) et ajoute les colonnes qui manquent. Renvoie un avertissement ou null. */
function exchange_ensure_table(PDO $pdo, string $table, array $source): ?string
{
    if (!in_array($table, EXCHANGE_SHOP_TABLES, true)) return "table « $table » non prévue, ignorée";
    if (!exchange_table_columns($pdo, $table)) {
        $ddl = (string) ($source['ddl'] ?? '');
        $ok = preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?"?' . preg_quote($table, '/') . '"?\s*\(/i', $ddl) && !str_contains($ddl, ';');
        if (!$ok) return "table « $table » absente de cette base, ignorée";
        $pdo->exec($ddl);
    }
    $have = array_column(exchange_table_columns($pdo, $table), 'name');
    foreach ((array) ($source['columns'] ?? []) as $col) {
        $name = (string) ($col['name'] ?? '');
        if (!preg_match('/^[a-z_][a-z0-9_]{0,60}$/', $name) || in_array($name, $have, true)) continue;
        $type = in_array($col['type'] ?? '', ['INTEGER', 'REAL', 'TEXT', 'BLOB'], true) ? $col['type'] : 'TEXT';
        $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $name . '" ' . $type);
    }
    return null;
}

/** INSERT / UPDATE d'une ligne en ne gardant que les colonnes que la cible connaît. */
function exchange_write_row(PDO $pdo, string $verb, string $table, array $row, array $targetCols): int
{
    $data = array_intersect_key($row, array_flip($targetCols));
    if (!$data) return 0;
    $cols = '"' . implode('","', array_keys($data)) . '"';
    $pdo->prepare($verb . ' INTO "' . $table . '" (' . $cols . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')')
        ->execute(array_values($data));
    return (int) $pdo->lastInsertId();
}

/**
 * Écrit les tables d'un paquet dans une base de boutique (une transaction : tout ou rien).
 * $opts : delete_missing (supprimer les fiches absentes du paquet, défaut non), keep_stock (garder le stock et l'état de vente des
 * fiches déjà présentes, défaut oui). Renvoie ['stats' => …, 'warnings' => […]].
 */
function exchange_db_apply(PDO $pdo, array $tables, array $opts = []): array
{
    $deleteMissing = !empty($opts['delete_missing']);
    $keepStock = !array_key_exists('keep_stock', $opts) || !empty($opts['keep_stock']);
    $stats = ['products_new' => 0, 'products_updated' => 0, 'products_removed' => 0, 'photos' => 0, 'tables' => []];
    $warnings = [];

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->beginTransaction();
    try {
        foreach (EXCHANGE_SHOP_TABLES as $table) {
            if (!isset($tables[$table])) continue;
            if (($warn = exchange_ensure_table($pdo, $table, (array) $tables[$table])) !== null) {
                $warnings[] = $warn;
                unset($tables[$table]);
            }
        }
        $cols = static fn (string $t): array => array_column(exchange_table_columns($pdo, $t), 'name');

        // Textes de la page d'accueil, de l'histoire, des contacts et de la livraison : une seule ligne.
        if (!empty($tables['content']['rows'][0])) {
            $row = $tables['content']['rows'][0];
            $row['id'] = 1;
            exchange_write_row($pdo, 'INSERT OR REPLACE', 'content', $row, $cols('content'));
            $stats['tables']['content'] = 1;
        }

        // Fiches : ajoutées ou mises à jour par numéro.
        $refs = [];
        if (isset($tables['products'])) {
            $productCols = $cols('products');
            $existing = array_flip($pdo->query('SELECT ref FROM products')->fetchAll(PDO::FETCH_COLUMN));
            foreach ($tables['products']['rows'] as $row) {
                $ref = trim((string) ($row['ref'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ref) || trim((string) ($row['name'] ?? '')) === '') {
                    $warnings[] = 'fiche ignorée (numéro ou nom invalide) : ' . mb_substr($ref, 0, 40);
                    continue;
                }
                $refs[$ref] = true;
                $row['ref'] = $ref;
                if (isset($existing[$ref])) {
                    $data = array_intersect_key($row, array_flip($productCols));
                    unset($data['ref']);
                    if ($keepStock) $data = array_diff_key($data, array_flip(EXCHANGE_STOCK_COLUMNS));
                    if ($data) {
                        $sets = implode(', ', array_map(static fn ($c) => '"' . $c . '" = ?', array_keys($data)));
                        $pdo->prepare('UPDATE products SET ' . $sets . ' WHERE ref = ?')->execute([...array_values($data), $ref]);
                    }
                    $stats['products_updated']++;
                } else {
                    exchange_write_row($pdo, 'INSERT', 'products', $row, $productCols);
                    $stats['products_new']++;
                }
            }
            if ($deleteMissing) {
                $del = $pdo->prepare('DELETE FROM products WHERE ref = ?');
                foreach (array_keys($existing) as $ref) {
                    if (!isset($refs[$ref])) {
                        $del->execute([$ref]);
                        $stats['products_removed']++;
                    }
                }
            }
            $stats['tables']['products'] = $stats['products_new'] + $stats['products_updated'];
        }

        // Photos : celles des fiches du paquet sont remplacées ; les identifiants changent, les détourages (cutout_of) suivent.
        if (isset($tables['product_photos']) && $refs) {
            $photoCols = $cols('product_photos');
            $byRef = [];
            foreach ($tables['product_photos']['rows'] as $row) {
                $ref = (string) ($row['product_ref'] ?? '');
                if (isset($refs[$ref])) $byRef[$ref][] = $row;
            }
            $del = $pdo->prepare('DELETE FROM product_photos WHERE product_ref = ?');
            $idMap = [];
            foreach (array_keys($refs) as $ref) {
                $del->execute([$ref]);
                foreach ($byRef[$ref] ?? [] as $row) {
                    $oldId = $row['id'] ?? null;
                    unset($row['id']);
                    $newId = exchange_write_row($pdo, 'INSERT', 'product_photos', $row, $photoCols);
                    if ($oldId !== null) $idMap[(int) $oldId] = $newId;
                    $stats['photos']++;
                }
            }
            if (in_array('cutout_of', $photoCols, true)) {
                $fix = $pdo->prepare('UPDATE product_photos SET cutout_of = ? WHERE id = ?');
                foreach ($byRef as $rows) {
                    foreach ($rows as $row) {
                        $old = (int) ($row['id'] ?? 0);
                        $source = (int) ($row['cutout_of'] ?? 0);
                        if ($old && $source) $fix->execute([$idMap[$source] ?? null, $idMap[$old] ?? 0]);
                    }
                }
            }
            $stats['tables']['product_photos'] = $stats['photos'];
        }

        // Réglages d'ensemble : remplacés en bloc (enfants avant parents pour les clés étrangères).
        $productRefs = array_flip($pdo->query('SELECT ref FROM products')->fetchAll(PDO::FETCH_COLUMN));
        foreach (EXCHANGE_REPLACE_TABLES as $table) {
            if (isset($tables[$table])) $pdo->exec('DELETE FROM "' . $table . '"');
        }
        foreach (array_reverse(EXCHANGE_REPLACE_TABLES) as $table) {
            if (!isset($tables[$table])) continue;
            $tableCols = $cols($table);
            $count = 0;
            foreach ($tables[$table]['rows'] as $row) {
                if ($table === 'slideshow_slides' && !empty($row['product_ref']) && !isset($productRefs[$row['product_ref']])) {
                    $warnings[] = 'diapositive ignorée : la fiche ' . $row['product_ref'] . ' n\'existe pas sur cette boutique';
                    continue;
                }
                exchange_write_row($pdo, 'INSERT', $table, $row, $tableCols);
                $count++;
            }
            $stats['tables'][$table] = $count;
        }

        // Réglages nommés : seulement la liste autorisée, quoi que contienne le paquet.
        if (isset($tables['settings'])) {
            $set = $pdo->prepare('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)');
            $count = 0;
            foreach ($tables['settings']['rows'] as $row) {
                if (!exchange_setting_allowed((string) ($row['name'] ?? ''))) continue;
                $set->execute([(string) $row['name'], (string) ($row['value'] ?? '')]);
                $count++;
            }
            $stats['tables']['settings'] = $count;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['stats' => $stats, 'warnings' => $warnings];
}

/* ---------- Boutiques ---------- */

function exchange_shop_config_pick(array $config): array
{
    return array_intersect_key($config, array_flip(EXCHANGE_SHOP_CONFIG_KEYS));
}

function exchange_open_pdo(string $file): PDO
{
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/** Boutiques de cet environnement avec leur nombre de fiches : [identifiant => ['name', 'products', 'has_db']]. */
function exchange_shop_inventory(): array
{
    $out = [];
    foreach (tenant_list() as $slug => $config) {
        $count = null;
        $file = tenant_file($config, 'db_file');
        if (is_file($file)) {
            try {
                $count = (int) exchange_open_pdo($file)->query('SELECT COUNT(*) FROM products')->fetchColumn();
            } catch (Throwable $e) {
                $count = null;
            }
        }
        $out[$slug] = ['name' => (string) $config['name'], 'products' => $count, 'has_db' => is_file($file)];
    }
    return $out;
}

/** Galeries de cet environnement : [identifiant => ['name', 'members']]. */
function exchange_gallery_inventory(): array
{
    $out = [];
    foreach (gallery_list() as $slug => $gallery) {
        $out[$slug] = ['name' => (string) $gallery['name'], 'members' => count((array) $gallery['members'])];
    }
    return $out;
}

/** Paquet d'une boutique de cet environnement : ['package' => …, 'files' => [chemins présents], 'missing' => [chemins cités mais absents]]. */
function exchange_shop_export(string $slug): array
{
    if (!exchange_slug_valid($slug) || $slug[0] === '_') throw new InvalidArgumentException("Identifiant de boutique invalide : $slug");
    $file = tenant_dir($slug) . '/tenant.php';
    if (!is_file($file)) throw new RuntimeException("Boutique introuvable : $slug");
    $config = tenant_load($slug);
    $raw = (array) require $file;
    $dbFile = tenant_file($config, 'db_file');
    $tables = is_file($dbFile) ? exchange_db_snapshot(exchange_open_pdo($dbFile)) : [];
    $package = ['kind' => 'shop', 'slug' => $slug, 'name' => (string) $config['name'], 'config' => exchange_shop_config_pick($raw), 'tables' => $tables];

    $files = $missing = [];
    foreach (exchange_scan_paths($package) as $path) {
        // Le logo d'une autre boutique ou un asset du dépôt ne se transfère pas : seuls assets/tenants/<cette boutique>/ et uploads/.
        if (str_starts_with($path, 'assets/tenants/') && !str_starts_with($path, "assets/tenants/$slug/")) continue;
        if (is_file(exchange_root() . '/' . $path)) $files[] = $path; else $missing[] = $path;
    }
    return ['package' => $package, 'files' => $files, 'missing' => $missing];
}

/** Sauvegarde cohérente de la base avant modification : data/backups/<base>-echange-<horodatage>.db. */
function exchange_backup_db(PDO $pdo, string $dbFile): string
{
    $dir = exchange_root() . '/data/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('dossier de sauvegarde impossible à créer (data/backups)');
    $target = $dir . '/' . basename($dbFile, '.db') . '-echange-' . date('Ymd-His') . '.db';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
    @chmod($target, 0600);
    return $target;
}

function exchange_tenant_php(string $name, array $config): string
{
    return "<?php\n\n// Configuration du commerce : " . str_replace(["\n", '?>'], ' ', $name)
        . " (écrite par l'échange entre environnements).\n\nreturn " . exchange_php_export($config) . ";\n";
}

function exchange_random_password(): string
{
    return substr(strtr(base64_encode(random_bytes(12)), '+/=', 'abc'), 0, 14);
}

/**
 * Importe une boutique (paquet déjà débarrassé de ses chemins renommés). $siteBase : « https://hôte » de cet environnement,
 * pour l'adresse d'une boutique créée. Renvoie ['created' => bool, 'password' => ?string, 'backup' => ?string, 'stats' => …, 'warnings' => […]].
 */
function exchange_shop_import(array $package, array $opts, string $siteBase): array
{
    $slug = (string) ($package['slug'] ?? '');
    if (!exchange_slug_valid($slug) || $slug[0] === '_') throw new InvalidArgumentException("Identifiant de boutique invalide : $slug");
    $dir = tenant_dir($slug);
    $tenantFile = $dir . '/tenant.php';
    $exists = is_file($tenantFile);
    $incoming = exchange_shop_config_pick((array) ($package['config'] ?? []));
    $name = (string) ($incoming['name'] ?? $package['name'] ?? $slug);
    $password = null;
    $backup = null;
    $createdDb = false;

    if ($exists) {
        copy($tenantFile, $tenantFile . '.bak-' . date('Ymd-His'));
        $config = array_replace((array) require $tenantFile, $incoming);
    } else {
        $password = exchange_random_password();
        $config = $incoming + ['name' => $name];
        $config['site_url'] = rtrim($siteBase, '/') . '/' . $slug;
        $config['admin_user'] = 'admin';
        $config['admin_password'] = password_hash($password, PASSWORD_DEFAULT);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException("Impossible de créer tenants/$slug (droits d'écriture manquants).");
        file_put_contents($dir . '/seed-data.json', exchange_json([
            'brand' => ['name' => $name, 'tagline' => (string) ($incoming['tagline'] ?? '')],
            'hero' => ['eyebrow' => '', 'title' => $name, 'subtitle' => '', 'photo' => ''],
            'story' => ['eyebrow' => '', 'title' => '', 'text' => '', 'photo' => '', 'photoCaption' => '', 'principles' => []],
            'contact' => ['address' => '', 'hours' => '', 'delivery' => ''],
            'featuredRefs' => [], 'products' => [],
        ]) . "\n");
    }
    file_put_contents($tenantFile, exchange_tenant_php($name, $config));

    $shop = tenant_load($slug);
    $dbFile = tenant_file($shop, 'db_file');
    try {
        if (!is_file($dbFile)) {
            require_once __DIR__ . '/seed.php';
            seed_tenant($shop);
            $createdDb = true;
        }
        $pdo = exchange_open_pdo($dbFile);
        if (!$createdDb) $backup = exchange_backup_db($pdo, $dbFile);
        $result = exchange_db_apply($pdo, (array) ($package['tables'] ?? []), $opts);
    } catch (Throwable $e) {
        if (!$exists) {
            // Boutique créée par cet import : on la retire pour ne pas laisser une coquille vide.
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
            if ($createdDb) foreach (['', '-wal', '-shm', '-journal'] as $s) @unlink($dbFile . $s);
        }
        throw $e;
    }
    return ['created' => !$exists, 'password' => $password, 'backup' => $backup] + $result;
}

/* ---------- Galeries ---------- */

/** Paquet d'une galerie de cet environnement (même forme que exchange_shop_export). */
function exchange_gallery_export(string $slug): array
{
    $gallery = gallery_load($slug);
    if (!$gallery) throw new RuntimeException("Galerie introuvable : $slug");
    $package = ['kind' => 'gallery', 'slug' => $slug, 'name' => (string) $gallery['name'], 'data' => array_intersect_key($gallery, array_flip(EXCHANGE_GALLERY_KEYS))];
    // Horaires et fermetures (sans le journal des alertes envoyées aux visiteurs).
    if (is_file(galleries_dir() . '/' . $slug . '.hours.json')) {
        require_once __DIR__ . '/gallery-hours.php';
        $package['hours'] = array_diff_key(gh_read($slug), ['alerts' => 1]);
    }
    // Sponsors et partenaires (la note interne reste sur place) ; les logos sont repris par le balayage des chemins ci-dessous.
    if (is_file(galleries_dir() . '/' . $slug . '.sponsors.json')) {
        require_once __DIR__ . '/gallery-sponsors.php';
        $package['sponsors'] = array_map(static fn (array $f): array => array_diff_key($f, ['note' => 1]), gsp_load($slug));
    }
    $files = $missing = [];
    foreach (exchange_scan_paths($package) as $path) {
        if (is_file(exchange_root() . '/' . $path)) $files[] = $path; else $missing[] = $path;
    }
    return ['package' => $package, 'files' => $files, 'missing' => $missing];
}

/** Importe une galerie : créée hors ligne, ou mise à jour en gardant administrateurs, options, abonnement, adresse et publication de la cible. */
function exchange_gallery_import(array $package): array
{
    $slug = (string) ($package['slug'] ?? '');
    if (!gallery_slug_valid($slug)) throw new InvalidArgumentException("Identifiant de galerie invalide : $slug");
    $incoming = array_intersect_key((array) ($package['data'] ?? []), array_flip(EXCHANGE_GALLERY_KEYS));
    $existing = gallery_load($slug);
    $backup = null;
    if ($existing) {
        $file = galleries_dir() . '/' . $slug . '.json';
        $backup = $file . '.bak-' . date('Ymd-His');
        copy($file, $backup);
        $merged = array_replace($existing, $incoming);
    } else {
        $merged = $incoming + ['slug' => $slug, 'published' => false, 'paused' => false, 'created' => time()];
    }
    $merged['slug'] = $slug;
    if (!gallery_save($merged)) throw new RuntimeException("Écriture de la galerie impossible (droits sur data/galeries).");

    $warnings = [];
    if (!empty($package['hours']) && is_array($package['hours'])) {
        require_once __DIR__ . '/gallery-hours.php';
        $hoursFile = galleries_dir() . '/' . $slug . '.hours.json';
        if (is_file($hoursFile)) copy($hoursFile, $hoursFile . '.bak-' . date('Ymd-His'));
        $h = $package['hours'];
        if (!gh_save($slug, is_array($h['schedule'] ?? null) ? $h['schedule'] : null, (array) ($h['closures'] ?? []), (string) ($h['notice'] ?? ''))) {
            $warnings[] = 'horaires non enregistrés (droits sur data/galeries)';
        }
    }
    if (isset($package['sponsors']) && is_array($package['sponsors'])) {
        require_once __DIR__ . '/gallery-sponsors.php';
        $sponsorsFile = gsp_file($slug);
        // Les notes internes de la cible sont conservées pour les fiches qui existent déjà ici.
        $notes = array_column(gsp_load($slug), 'note', 'id');
        if ($sponsorsFile !== '' && is_file($sponsorsFile)) copy($sponsorsFile, $sponsorsFile . '.bak-' . date('Ymd-His'));
        $list = array_map(static fn ($f) => is_array($f) ? array_diff_key($f, ['note' => 1]) + ['note' => (string) ($notes[$f['id'] ?? ''] ?? '')] : $f, $package['sponsors']);
        if (!gsp_save($slug, $list)) $warnings[] = 'sponsors non enregistrés (droits sur data/galeries)';
    }
    foreach ((array) ($merged['members'] ?? []) as $member) {
        if (($member['type'] ?? '') === 'local' && !is_file(tenant_dir((string) $member['slug']) . '/tenant.php')) {
            $warnings[] = 'membre « ' . $member['slug'] . ' » : cette boutique n\'existe pas sur cet environnement (importez-la aussi)';
        }
    }
    if (!$existing) $warnings[] = 'galerie créée hors ligne : publiez-la quand elle est prête ; options payantes, administrateurs et adresse sont à régler ici';
    return ['created' => !$existing, 'backup' => $backup, 'warnings' => $warnings];
}

/* ---------- Paquet .zip ---------- */

/**
 * Construit le paquet. $shops / $galleries : identifiants à exporter. $withFiles : joindre images et vidéos.
 * Renvoie le manifeste (sans les avertissements d'absence : clé 'missing').
 */
function exchange_build_zip(array $shops, array $galleries, string $dest, bool $withFiles = true): array
{
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Impossible de créer le paquet.');
    $manifest = [
        'app' => EXCHANGE_APP, 'format' => EXCHANGE_FORMAT, 'created' => date('c'),
        'source' => ['env' => app_env(), 'host' => (string) ($_SERVER['HTTP_HOST'] ?? php_uname('n'))],
        'shops' => [], 'galleries' => [], 'files' => [], 'missing' => [],
    ];
    $allFiles = [];
    foreach (array_values(array_unique($shops)) as $slug) {
        $e = exchange_shop_export($slug);
        $zip->addFromString("shops/$slug.json", exchange_json($e['package']));
        $manifest['shops'][] = ['slug' => $slug, 'name' => $e['package']['name'], 'products' => count($e['package']['tables']['products']['rows'] ?? [])];
        foreach ($e['files'] as $p) $allFiles[$p] = true;
        foreach ($e['missing'] as $p) $manifest['missing'][$p] = true;
    }
    foreach (array_values(array_unique($galleries)) as $slug) {
        $e = exchange_gallery_export($slug);
        $zip->addFromString("galleries/$slug.json", exchange_json($e['package']));
        $manifest['galleries'][] = ['slug' => $slug, 'name' => $e['package']['name']];
        foreach ($e['files'] as $p) $allFiles[$p] = true;
        foreach ($e['missing'] as $p) $manifest['missing'][$p] = true;
    }
    if ($withFiles) {
        foreach (array_keys($allFiles) as $path) {
            $abs = exchange_root() . '/' . $path;
            $manifest['files'][$path] = ['sha1' => sha1_file($abs), 'size' => filesize($abs)];
            $zip->addFile($abs, "files/$path");
            $zip->setCompressionName("files/$path", ZipArchive::CM_STORE);   // images et vidéos sont déjà compressées
        }
    }
    $manifest['missing'] = array_keys($manifest['missing']);
    $zip->addFromString('manifest.json', exchange_json($manifest));
    if (!$zip->close()) throw new RuntimeException('Écriture du paquet impossible.');
    return $manifest;
}

/** Lit un JSON du paquet (taille bornée). */
function exchange_zip_json(ZipArchive $zip, string $name): array
{
    $stat = $zip->statName($name);
    if (!$stat || $stat['size'] > EXCHANGE_JSON_MAX) throw new RuntimeException("Paquet invalide : $name absent ou trop gros.");
    $data = json_decode((string) $zip->getFromName($name), true);
    if (!is_array($data)) throw new RuntimeException("Paquet invalide : $name illisible.");
    return $data;
}

/** Ouvre et contrôle un paquet : ['manifest' => …, 'shops' => [slug => paquet], 'galleries' => [slug => paquet]]. */
function exchange_read_zip(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::RDONLY) !== true) throw new RuntimeException('Ce fichier n\'est pas un paquet .zip lisible.');
    try {
        if (!$zip->statName('manifest.json')) throw new RuntimeException('Ce zip n\'est pas un paquet d\'échange (manifest.json absent).');
        $manifest = exchange_zip_json($zip, 'manifest.json');
        if (($manifest['app'] ?? '') !== EXCHANGE_APP) throw new RuntimeException('Ce zip n\'est pas un paquet d\'échange de ce site.');
        if ((int) ($manifest['format'] ?? 0) !== EXCHANGE_FORMAT) throw new RuntimeException('Format de paquet ' . (int) ($manifest['format'] ?? 0) . ' non pris en charge (attendu : ' . EXCHANGE_FORMAT . '). Mettez ce site à jour.');
        $shops = $galleries = [];
        foreach ((array) ($manifest['shops'] ?? []) as $item) {
            $slug = (string) ($item['slug'] ?? '');
            if (!exchange_slug_valid($slug) || $slug[0] === '_') continue;
            $pkg = exchange_zip_json($zip, "shops/$slug.json");
            if (($pkg['kind'] ?? '') === 'shop' && ($pkg['slug'] ?? '') === $slug) $shops[$slug] = $pkg;
        }
        foreach ((array) ($manifest['galleries'] ?? []) as $item) {
            $slug = (string) ($item['slug'] ?? '');
            if (!gallery_slug_valid($slug)) continue;
            $pkg = exchange_zip_json($zip, "galleries/$slug.json");
            if (($pkg['kind'] ?? '') === 'gallery' && ($pkg['slug'] ?? '') === $slug) $galleries[$slug] = $pkg;
        }
        $files = [];
        foreach ((array) ($manifest['files'] ?? []) as $path => $meta) {
            if (exchange_file_path_ok((string) $path) && preg_match('/^[0-9a-f]{40}$/', (string) ($meta['sha1'] ?? '')) && $zip->statName("files/$path")) {
                $files[(string) $path] = ['sha1' => (string) $meta['sha1'], 'size' => (int) ($meta['size'] ?? 0)];
            }
        }
        $manifest['files'] = $files;
    } finally {
        $zip->close();
    }
    return ['manifest' => $manifest, 'shops' => $shops, 'galleries' => $galleries];
}

/** Extrait un fichier du paquet vers $dest (taille bornée, jamais d'écriture ailleurs). */
function exchange_zip_extract(string $zipFile, string $path, string $dest): bool
{
    if (!exchange_file_path_ok($path)) return false;
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) return false;
    try {
        $stat = $zip->statName("files/$path");
        if (!$stat || $stat['size'] > EXCHANGE_FILE_MAX || !($in = $zip->getStream("files/$path"))) return false;
        $out = fopen($dest, 'wb');
        $copied = stream_copy_to_stream($in, $out, EXCHANGE_FILE_MAX + 1);
        fclose($in);
        fclose($out);
        return $copied !== false && $copied <= EXCHANGE_FILE_MAX;
    } finally {
        $zip->close();
    }
}

/* ---------- Aperçu et import d'un paquet ---------- */

/** Fichiers cités par un élément du paquet et présents dans le paquet : [chemin => sha1]. */
function exchange_item_files(array $package, array $manifestFiles): array
{
    $out = [];
    foreach (exchange_scan_paths($package) as $path) {
        if (isset($manifestFiles[$path])) $out[$path] = $manifestFiles[$path]['sha1'];
    }
    return $out;
}

/**
 * Aperçu : ce que l'import ferait sur cet environnement, sans rien écrire.
 * Renvoie ['manifest' => …, 'shops' => [slug => infos], 'galleries' => [slug => infos], 'warnings' => […]].
 */
function exchange_plan(string $zipFile, array $opts = []): array
{
    $pkg = exchange_read_zip($zipFile);
    $manifest = $pkg['manifest'];
    $plan = ['manifest' => $manifest, 'shops' => [], 'galleries' => [], 'warnings' => []];
    if (($manifest['source']['env'] ?? '') === app_env()) {
        $plan['warnings'][] = 'Ce paquet vient du même environnement (' . app_env() . ') : vérifiez que c\'est bien voulu.';
    }
    $fileState = static function (array $files): array {
        $n = ['new' => 0, 'same' => 0, 'renamed' => 0];
        foreach ($files as $path => $sha1) $n[exchange_file_status($path, $sha1)['state']]++;
        return $n;
    };
    foreach ($pkg['shops'] as $slug => $shop) {
        $exists = is_file(tenant_dir($slug) . '/tenant.php');
        $incoming = array_column((array) ($shop['tables']['products']['rows'] ?? []), 'ref');
        $info = ['name' => (string) ($shop['name'] ?? $slug), 'exists' => $exists, 'products_in' => count($incoming), 'products_new' => count($incoming), 'products_updated' => 0, 'products_removed' => 0];
        if ($exists) {
            $file = tenant_file(tenant_load($slug), 'db_file');
            $have = [];
            if (is_file($file)) {
                try {
                    $have = exchange_open_pdo($file)->query('SELECT ref FROM products')->fetchAll(PDO::FETCH_COLUMN);
                } catch (Throwable $e) {
                    $plan['warnings'][] = "Boutique $slug : base illisible ({$e->getMessage()}).";
                }
            }
            $info['products_updated'] = count(array_intersect($incoming, $have));
            $info['products_new'] = count($incoming) - $info['products_updated'];
            $info['products_removed'] = !empty($opts['delete_missing']) ? count(array_diff($have, $incoming)) : 0;
        }
        $info['files'] = $fileState(exchange_item_files($shop, $manifest['files']));
        $plan['shops'][$slug] = $info;
    }
    foreach ($pkg['galleries'] as $slug => $gallery) {
        $info = ['name' => (string) ($gallery['name'] ?? $slug), 'exists' => gallery_load($slug) !== null, 'warnings' => []];
        foreach ((array) ($gallery['data']['members'] ?? []) as $member) {
            if (($member['type'] ?? '') === 'local' && !is_file(tenant_dir((string) $member['slug']) . '/tenant.php') && !isset($pkg['shops'][$member['slug']])) {
                $info['warnings'][] = 'membre « ' . $member['slug'] . ' » absent de cet environnement et du paquet';
            }
        }
        $info['files'] = $fileState(exchange_item_files($gallery, $manifest['files']));
        $plan['galleries'][$slug] = $info;
    }
    foreach ((array) ($manifest['missing'] ?? []) as $path) {
        $plan['warnings'][] = 'Fichier cité mais absent à l\'export : ' . $path;
    }
    return $plan;
}

/**
 * Importe les éléments choisis. Les éléments sont traités un par un : l'échec de l'un n'empêche pas les autres.
 * Renvoie une liste de résultats ['kind', 'slug', 'ok', 'message', 'created', 'password', 'warnings', 'stats'].
 */
function exchange_import_zip(string $zipFile, array $shopSlugs, array $gallerySlugs, array $opts, string $siteBase): array
{
    $pkg = exchange_read_zip($zipFile);
    $manifestFiles = $pkg['manifest']['files'];
    $results = [];
    $tmpDir = sys_get_temp_dir() . '/echange-' . bin2hex(random_bytes(6));
    @mkdir($tmpDir, 0700, true);

    $stage = static function (array $package) use ($zipFile, $manifestFiles, $tmpDir): array {
        $map = [];
        $warnings = [];
        foreach (exchange_item_files($package, $manifestFiles) as $path => $sha1) {
            $tmp = $tmpDir . '/f';
            if (!exchange_zip_extract($zipFile, $path, $tmp)) {
                $warnings[] = "fichier $path illisible dans le paquet";
                continue;
            }
            $r = exchange_store_file($path, $sha1, $tmp);
            @unlink($tmp);
            if (empty($r['ok'])) {
                $warnings[] = "fichier $path refusé : " . ($r['error'] ?? 'erreur');
            } elseif ($r['target'] !== $path) {
                $map[$path] = $r['target'];
            }
        }
        return [$map, $warnings];
    };

    foreach ($shopSlugs as $slug) {
        if (!isset($pkg['shops'][$slug])) continue;
        $row = ['kind' => 'shop', 'slug' => $slug, 'name' => (string) ($pkg['shops'][$slug]['name'] ?? $slug), 'ok' => false];
        try {
            [$map, $warnings] = $stage($pkg['shops'][$slug]);
            $shop = exchange_rewrite_paths($pkg['shops'][$slug], $map);
            $r = exchange_shop_import($shop, $opts, $siteBase);
            $s = $r['stats'];
            $row += ['created' => $r['created'], 'password' => $r['password'], 'stats' => $s, 'warnings' => array_merge($warnings, $r['warnings'])];
            $row['ok'] = true;
            $row['message'] = ($r['created'] ? 'créée' : 'mise à jour') . " : {$s['products_new']} fiche(s) ajoutée(s), {$s['products_updated']} mise(s) à jour"
                . ($s['products_removed'] ? ", {$s['products_removed']} supprimée(s)" : '') . ", {$s['photos']} photo(s)";
        } catch (Throwable $e) {
            $row += ['created' => false, 'password' => null, 'warnings' => [], 'message' => 'échec : ' . $e->getMessage()];
        }
        $results[] = $row;
    }
    foreach ($gallerySlugs as $slug) {
        if (!isset($pkg['galleries'][$slug])) continue;
        $row = ['kind' => 'gallery', 'slug' => $slug, 'name' => (string) ($pkg['galleries'][$slug]['name'] ?? $slug), 'ok' => false];
        try {
            [$map, $warnings] = $stage($pkg['galleries'][$slug]);
            $r = exchange_gallery_import(exchange_rewrite_paths($pkg['galleries'][$slug], $map));
            $row += ['created' => $r['created'], 'password' => null, 'warnings' => array_merge($warnings, $r['warnings'])];
            $row['ok'] = true;
            $row['message'] = $r['created'] ? 'créée (hors ligne)' : 'mise à jour';
        } catch (Throwable $e) {
            $row += ['created' => false, 'password' => null, 'warnings' => [], 'message' => 'échec : ' . $e->getMessage()];
        }
        $results[] = $row;
    }

    foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir);
    exchange_log([
        'action' => 'import', 'from' => $pkg['manifest']['source'] ?? null, 'options' => array_intersect_key($opts, ['delete_missing' => 1, 'keep_stock' => 1]),
        'items' => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'slug' => $r['slug'], 'ok' => $r['ok'], 'message' => $r['message'] ?? ''], $results),
    ]);
    return $results;
}
