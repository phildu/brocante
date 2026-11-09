<?php

// Serveur MCP (Model Context Protocol) du commerce : permet à un assistant (Claude Code…) de lire et de modifier les données
// du site (catalogue, contenus, réglages, remises, commandes en lecture seule). Entrée : /mcp.php (JSON-RPC 2.0 sur HTTP POST).
//
// Sécurité :
//  - jeton unique par commerce, jamais conservé en clair (empreinte SHA-256 dans la table settings), créé et révoqué depuis
//    Administration → Accès MCP ; HTTPS obligatoire (sauf en local) ; blocage temporaire après trop d'échecs ;
//  - aucune suppression définitive : on masque un produit ou on désactive une remise ;
//  - chaque écriture est précédée d'une sauvegarde de la base (data/backups/…-mcp-*.db, au plus une toutes les 10 minutes)
//    et journalisée avec les valeurs avant / après (table mcp_audit) ;
//  - liste blanche de champs : aucune clé, aucun mot de passe, aucune table d'administration n'est exposé.
// Voir MCP.md.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/app-env.php';
require_once __DIR__ . '/backup.php';

const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_MAX_BODY = 512 * 1024;
const MCP_AUTH_MAX_FAILS = 10;      // échecs par adresse IP…
const MCP_AUTH_WINDOW_SECONDS = 600; // …sur cette durée avant blocage
const MCP_BACKUP_MIN_INTERVAL = 600;
const MCP_BACKUP_KEEP = 30;

/* ------------------------------------------------------------------ */
/* Schéma, jeton, journal                                              */
/* ------------------------------------------------------------------ */

function mcp_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    db()->exec('CREATE TABLE IF NOT EXISTS mcp_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip TEXT NOT NULL DEFAULT \'\',
        tool TEXT NOT NULL,
        args TEXT,
        outcome TEXT NOT NULL DEFAULT \'ok\',
        before_json TEXT,
        after_json TEXT
    )');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mcp_audit_at ON mcp_audit(at)');
    $done = true;
}

function mcp_client_ip(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Nouveau jeton (affiché une seule fois). Remplace et révoque le précédent. */
function mcp_token_create(): string
{
    $token = 'bbmcp_' . bin2hex(random_bytes(24));
    shop_setting_set('mcp_token_hash', hash('sha256', $token));
    shop_setting_set('mcp_token_created', gmdate('Y-m-d H:i:s'));
    return $token;
}

function mcp_token_revoke(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    db()->exec("DELETE FROM settings WHERE name IN ('mcp_token_hash', 'mcp_token_created')");
}

function mcp_token_active(): bool
{
    return shop_setting('mcp_token_hash', '') !== '';
}

function mcp_token_valid(string $token): bool
{
    $hash = shop_setting('mcp_token_hash', '');
    if ($hash === '' || !preg_match('/^bbmcp_[a-f0-9]{48}$/', $token)) return false;
    return hash_equals($hash, hash('sha256', $token));
}

function mcp_auth_blocked(string $ip): bool
{
    mcp_ensure_schema();
    $stmt = db()->prepare("SELECT COUNT(*) FROM mcp_audit WHERE tool = 'auth' AND outcome = 'refus' AND ip = ? AND at >= datetime('now', ?)");
    $stmt->execute([$ip, '-' . MCP_AUTH_WINDOW_SECONDS . ' seconds']);
    return (int) $stmt->fetchColumn() >= MCP_AUTH_MAX_FAILS;
}

/**
 * Décision d'accès : 'ok' (jeton valable, jamais bloqué), 'bloque' (jeton faux et trop d'échecs récents depuis cette IP : 429),
 * 'refus' (jeton faux ou absent : 401, l'échec est journalisé). Le blocage ne touche que les jetons faux.
 */
function mcp_access(string $token, string $ip): string
{
    if ($token !== '' && mcp_token_valid($token)) return 'ok';
    if (str_starts_with($token, 'bbmcpo_')) {
        require_once __DIR__ . '/mcp-oauth.php';
        if (mcp_oauth_access_valid($token)) return 'ok';
    }
    if (mcp_auth_blocked($ip)) return 'bloque';
    mcp_audit('auth', null, 'refus');
    return 'refus';
}

function mcp_audit(string $tool, ?array $args, string $outcome, $before = null, $after = null): void
{
    try {
        mcp_ensure_schema();
        $json = static fn ($v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        db()->prepare('INSERT INTO mcp_audit (ip, tool, args, outcome, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([mcp_client_ip(), $tool, $json($args), mb_substr($outcome, 0, 300), $json($before), $json($after)]);
        // Le journal ne grossit pas indéfiniment : 180 jours.
        if (random_int(1, 50) === 1) db()->exec("DELETE FROM mcp_audit WHERE at < datetime('now', '-180 days')");
    } catch (Throwable $e) {
        error_log('MCP journal : ' . $e->getMessage());
    }
}

/** Sauvegarde de la base avant écriture (une par requête, espacées d'au moins 10 minutes). */
function mcp_backup_before_write(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $dir = db_backup_dir();
    $prefix = db_backup_prefix() . '-mcp-';
    $existing = glob($dir . '/' . $prefix . '*.db') ?: [];
    rsort($existing);
    if ($existing && (time() - (int) @filemtime($existing[0])) < MCP_BACKUP_MIN_INTERVAL) return;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Sauvegarde impossible : dossier data/backups inaccessible. Écriture refusée.');
    $target = $dir . '/' . $prefix . date('Ymd-His') . '.db';
    db()->exec('VACUUM INTO ' . db()->quote($target));
    @chmod($target, 0600);
    $all = glob($dir . '/' . $prefix . '*.db') ?: [];
    rsort($all);
    foreach (array_slice($all, MCP_BACKUP_KEEP) as $old) @unlink($old);
}

/* ------------------------------------------------------------------ */
/* Validation commune                                                  */
/* ------------------------------------------------------------------ */

class McpToolError extends RuntimeException {}

function mcp_str($v, int $max): string
{
    if (!is_scalar($v) && $v !== null) throw new McpToolError('Valeur texte attendue.');
    return mb_substr(trim((string) $v), 0, $max);
}

function mcp_bool($v): int
{
    if (is_bool($v)) return $v ? 1 : 0;
    if ($v === 1 || $v === '1' || $v === 'true') return 1;
    if ($v === 0 || $v === '0' || $v === 'false') return 0;
    throw new McpToolError('Booléen attendu (true ou false).');
}

function mcp_int($v, int $min, int $max, string $label): int
{
    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) throw new McpToolError("$label : entier attendu.");
    $n = (int) $v;
    if ($n < $min || $n > $max) throw new McpToolError("$label : entre $min et $max.");
    return $n;
}

/** Prix : « 25 », « 25,5 », « 25.50 € » → « 25 € », « 25,50 € ». Refuse tout ce qui n'est pas un montant simple. */
function mcp_price($v, bool $allowEmpty = false): string
{
    $raw = trim((string) $v);
    if ($raw === '') {
        if ($allowEmpty) return '';
        throw new McpToolError('Prix vide.');
    }
    $n = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], preg_replace('/€|euros?$/iu', '', $raw));
    if (!is_numeric($n) || (float) $n < 0 || (float) $n >= 10000000) throw new McpToolError("Prix invalide : « $raw » (nombre en euros attendu, ex. 25 ou 25,50).");
    return price_from_input($raw);
}

/** Champs produit modifiables : nom => [type, contrainte]. */
function mcp_product_fields(): array
{
    return [
        'name' => 'Nom (≤ 200 caractères)',
        'description' => 'Description (texte)',
        'price' => 'Prix en euros (« 25 », « 25,50 »)',
        'promo_price' => 'Prix promo, inférieur au prix ; vide = retirer la promo (le badge « Promo » suit)',
        'badge' => 'Mention affichée sur la fiche (« Chiné », « Promo », « Nouveau »…)',
        'stock' => 'Stock (entier ≥ 0)',
        'is_hidden' => 'true = masquer la fiche du site',
        'featured' => 'true = mettre en avant (3 maximum)',
        'cat' => 'Clé d\'univers (voir tool site_overview)',
        'materials' => 'Matières',
        'colors' => 'Couleurs, séparées par des virgules',
        'brand' => 'Marque',
        'etat' => 'État (clé ou libellé, ex. « bon état »)',
        'size_text' => 'Dimensions affichées',
        'weight_text' => 'Poids affiché',
        'weight_grams' => 'Poids en grammes (frais de port)',
        'lot_qty' => 'Pièces vendues ensemble (1 = pièce seule)',
        'negotiable' => 'true = bouton « Faire une offre »',
    ];
}

/** Valide et normalise les champs reçus ; retourne [colonne => valeur SQL]. */
function mcp_product_clean(array $in, array $current = []): array
{
    $allowed = mcp_product_fields();
    $unknown = array_diff(array_keys($in), array_keys($allowed));
    if ($unknown) throw new McpToolError('Champ(s) non modifiable(s) : ' . implode(', ', $unknown) . '. Champs permis : ' . implode(', ', array_keys($allowed)) . '.');
    $out = [];
    foreach ($in as $k => $v) {
        switch ($k) {
            case 'name':
                $out[$k] = mcp_str($v, 200);
                if ($out[$k] === '') throw new McpToolError('Le nom ne peut pas être vide.');
                break;
            case 'description': $out[$k] = mcp_str($v, 20000); break;
            case 'price': $out[$k] = mcp_price($v); break;
            case 'promo_price': $out[$k] = mcp_price($v, true); break;
            case 'badge': $out[$k] = mcp_str($v, 40); break;
            case 'stock': $out[$k] = mcp_int($v, 0, 100000, 'stock'); break;
            case 'is_hidden': case 'featured': case 'negotiable': $out[$k] = mcp_bool($v); break;
            case 'cat':
                $keys = array_column(category_list(), 'key');
                if (!in_array($v, $keys, true)) throw new McpToolError('Univers inconnu : « ' . mcp_str($v, 60) . ' ». Univers valides : ' . implode(', ', $keys) . '.');
                $out[$k] = $v;
                break;
            case 'materials': $out[$k] = mcp_str($v, 300); break;
            case 'colors': $out[$k] = product_colors_clean(mcp_str($v, 200)); break;
            case 'brand': $out[$k] = mcp_str($v, 80); break;
            case 'etat':
                $key = product_condition_key(mcp_str($v, 60));
                if ($key === '' && trim((string) $v) !== '') throw new McpToolError('État inconnu : « ' . mcp_str($v, 60) . ' ».');
                $out[$k] = $key !== '' ? $key : null;
                break;
            case 'size_text': case 'weight_text': $out[$k] = mcp_str($v, 60); break;
            case 'weight_grams': $out[$k] = mcp_int($v, 0, 300000, 'weight_grams'); break;
            case 'lot_qty': $out[$k] = mcp_int($v, 1, 999, 'lot_qty'); break;
        }
    }
    // Cohérence prix / promo, comme le formulaire du catalogue.
    $price = $out['price'] ?? (string) ($current['price'] ?? '');
    if (array_key_exists('promo_price', $out)) {
        if ($out['promo_price'] !== '') {
            if ((price_to_cents($out['promo_price']) ?? 0) >= (price_to_cents($price) ?? 0)) throw new McpToolError('Le prix promo (' . $out['promo_price'] . ') doit être inférieur au prix (' . $price . ').');
            $out['badge'] = 'Promo';
        } else {
            $out['promo_price'] = null;
            if (!array_key_exists('badge', $out) && ($current['badge'] ?? '') === 'Promo') $out['badge'] = '';
        }
    } elseif (array_key_exists('price', $out) && !empty($current['promo_price']) && (price_to_cents((string) $current['promo_price']) ?? 0) >= (price_to_cents($price) ?? 0)) {
        throw new McpToolError('Le nouveau prix (' . $price . ') passe sous le prix promo en cours (' . $current['promo_price'] . ') : modifiez aussi promo_price (vide pour la retirer).');
    }
    return $out;
}

function mcp_product_public(array $p): array
{
    $keep = array_merge(['ref', 'photo', 'sort_order', 'created_at', 'source_id', 'external_url', 'synced_at'], array_keys(mcp_product_fields()));
    $o = [];
    foreach ($keep as $k) if (array_key_exists($k, $p)) $o[$k] = $p[$k];
    return $o;
}

/* ------------------------------------------------------------------ */
/* Outils                                                              */
/* ------------------------------------------------------------------ */

/** Champs de la table content modifiables (comme Administration → Contenus). */
function mcp_content_fields(): array
{
    return [
        'site_name', 'site_tagline', 'hero_eyebrow', 'hero_title', 'hero_subtitle', 'story_eyebrow', 'story_title', 'story_text',
        'story_photo_caption', 'principle1_title', 'principle1_text', 'principle2_title', 'principle2_text', 'principle3_title',
        'principle3_text', 'contact_address', 'contact_hours', 'contact_delivery', 'shipping_fee', 'pickup_label', 'shipping_label',
        'pickup_only_below', 'pickup_only_message', 'gift_wrap_enabled', 'gift_wrap_price', 'gift_wrap_label',
    ];
}

/** Réglages (table settings) modifiables : clé => [longueur max, description]. Aucune clé secrète. */
function mcp_setting_keys(): array
{
    return [
        'footer_note' => [200, 'Mention du pied de page'],
        'opening_hours' => [4000, 'Horaires d\'ouverture (JSON de l\'administration : à ne modifier que sur demande explicite)'],
        'opening_notice' => [500, 'Message d\'information affiché avec les horaires'],
        'legal_address' => [500, 'Adresse légale'],
    ];
}

function mcp_tools(): array
{
    $id = ['type' => 'string', 'description' => 'Référence du produit (ex. « 042 »).'];
    return [
        'site_overview' => [
            'write' => false,
            'description' => 'Vue d\'ensemble du site : environnement, nom, univers valides, nombre de produits (visibles / masqués / en rupture), commandes par statut, remises actives.',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
            'run' => static function (array $a): array {
                $pdo = db();
                $one = static fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
                $orders = [];
                try { foreach ($pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status') as $r) $orders[$r['status']] = (int) $r['n']; } catch (Throwable $e) {}
                $c = get_content();
                return [
                    'environnement' => app_env(), 'site' => $c['site_name'], 'accroche' => $c['site_tagline'],
                    'univers' => category_list(),
                    'produits' => ['total' => $one('SELECT COUNT(*) FROM products'), 'masques' => $one('SELECT COUNT(*) FROM products WHERE is_hidden = 1'), 'en_rupture' => $one('SELECT COUNT(*) FROM products WHERE stock <= 0'), 'mis_en_avant' => $one('SELECT COUNT(*) FROM products WHERE featured = 1')],
                    'commandes_par_statut' => $orders,
                    'remises_actives' => count(cart_discounts_list(true)),
                ];
            },
        ],
        'products_list' => [
            'write' => false,
            'description' => 'Liste les produits (résumé). Filtres : q (nom, référence, marque), cat, hidden (true/false), out_of_stock (true), limit (≤ 100), offset.',
            'schema' => ['type' => 'object', 'properties' => [
                'q' => ['type' => 'string'], 'cat' => ['type' => 'string'], 'hidden' => ['type' => 'boolean'], 'out_of_stock' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer', 'default' => 30], 'offset' => ['type' => 'integer', 'default' => 0],
            ]],
            'run' => static function (array $a): array {
                $where = []; $p = [];
                if (($q = mcp_str($a['q'] ?? '', 100)) !== '') { $where[] = '(name LIKE :q OR ref LIKE :q OR brand LIKE :q)'; $p['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; }
                if (($cat = mcp_str($a['cat'] ?? '', 60)) !== '') { $where[] = 'cat = :cat'; $p['cat'] = $cat; }
                if (array_key_exists('hidden', $a)) $where[] = 'is_hidden = ' . mcp_bool($a['hidden']);
                if (!empty($a['out_of_stock'])) $where[] = 'stock <= 0';
                $limit = isset($a['limit']) ? mcp_int($a['limit'], 1, 100, 'limit') : 30;
                $offset = isset($a['offset']) ? mcp_int($a['offset'], 0, 1000000, 'offset') : 0;
                $sql = ' FROM products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
                $st = db()->prepare('SELECT COUNT(*)' . $sql); $st->execute($p);
                $total = (int) $st->fetchColumn();
                $st = db()->prepare('SELECT ref, name, cat, price, promo_price, badge, stock, is_hidden, featured' . $sql . " ORDER BY CAST(ref AS INTEGER) DESC LIMIT $limit OFFSET $offset");
                $st->execute($p);
                return ['total' => $total, 'produits' => $st->fetchAll(PDO::FETCH_ASSOC)];
            },
        ],
        'product_get' => [
            'write' => false,
            'description' => 'Fiche complète d\'un produit (champs modifiables et métadonnées).',
            'schema' => ['type' => 'object', 'properties' => ['ref' => $id], 'required' => ['ref']],
            'run' => static function (array $a): array {
                $p = get_product(mcp_str($a['ref'] ?? '', 40));
                if (!$p) throw new McpToolError('Produit introuvable.');
                return mcp_product_public($p);
            },
        ],
        'product_update' => [
            'write' => true,
            'description' => 'Modifie des champs d\'un produit (seuls les champs fournis changent). Champs : ' . implode(', ', array_keys(mcp_product_fields())) . '. Attention : prix et stock d\'un produit importé d\'une boutique connectée sont réécrits à la prochaine synchro. dry_run=true montre le changement sans l\'appliquer.',
            'schema' => ['type' => 'object', 'properties' => [
                'ref' => $id,
                'fields' => ['type' => 'object', 'description' => 'Champs à modifier : ' . json_encode(mcp_product_fields(), JSON_UNESCAPED_UNICODE)],
                'dry_run' => ['type' => 'boolean', 'default' => false],
            ], 'required' => ['ref', 'fields']],
            'run' => static function (array $a): array {
                $ref = mcp_str($a['ref'] ?? '', 40);
                $cur = get_product($ref);
                if (!$cur) throw new McpToolError('Produit introuvable.');
                if (!is_array($a['fields'] ?? null) || !$a['fields']) throw new McpToolError('« fields » : objet non vide attendu.');
                $new = mcp_product_clean($a['fields'], $cur);
                if (!empty($new['featured']) && !$cur['featured'] && (int) db()->query('SELECT COUNT(*) FROM products WHERE featured = 1')->fetchColumn() >= 3) {
                    throw new McpToolError('Trois pièces sont déjà mises en avant : retirez-en une d\'abord.');
                }
                $before = array_intersect_key($cur, $new);
                $changed = array_filter($new, static fn ($v, $k) => ($cur[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH);
                $result = ['ref' => $ref, 'avant' => $before, 'apres' => $new, 'inchange' => !$changed];
                if (!empty($a['dry_run']) || !$changed) return $result + ['applique' => false];
                mcp_backup_before_write();
                $set = implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($new)));
                db()->prepare("UPDATE products SET $set WHERE ref = :__ref")->execute($new + ['__ref' => $ref]);
                return $result + ['applique' => true, '_audit' => [$before, $new]];
            },
        ],
        'product_create' => [
            'write' => true,
            'description' => 'Crée un produit. Il est MASQUÉ par défaut (is_hidden=true) : relisez-le puis publiez-le avec product_update (is_hidden=false). Obligatoire : name, price.',
            'schema' => ['type' => 'object', 'properties' => [
                'fields' => ['type' => 'object', 'description' => 'Mêmes champs que product_update ; name et price obligatoires.'],
            ], 'required' => ['fields']],
            'run' => static function (array $a): array {
                $f = $a['fields'] ?? null;
                if (!is_array($f) || empty($f['name']) || !array_key_exists('price', $f)) throw new McpToolError('« fields.name » et « fields.price » sont obligatoires.');
                $f += ['is_hidden' => true, 'cat' => default_category_key(), 'description' => 'Description à compléter.', 'badge' => 'Chiné'];
                $new = mcp_product_clean($f);
                mcp_backup_before_write();
                $ref = next_ref();
                $new += ['ref' => $ref, 'sort_order' => (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn()];
                $cols = array_keys($new);
                db()->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ')')->execute($new);
                return ['ref' => $ref, 'cree' => $new, '_audit' => [null, $new]];
            },
        ],
        'content_get' => [
            'write' => false,
            'description' => 'Textes et réglages de contenu du site (page d\'accueil, histoire, principes, contact, livraison, emballage cadeau), et réglages autorisés (pied de page, horaires…).',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
            'run' => static function (array $a): array {
                $c = get_content();
                $settings = [];
                foreach (array_keys(mcp_setting_keys()) as $k) $settings[$k] = shop_setting($k, '');
                return ['contenu' => array_intersect_key($c, array_flip(mcp_content_fields())), 'reglages' => $settings];
            },
        ],
        'content_update' => [
            'write' => true,
            'description' => 'Modifie des textes du site (seuls les champs fournis changent). Champs : ' . implode(', ', mcp_content_fields()) . '. Les photos ne sont pas modifiables ici. dry_run=true pour prévisualiser.',
            'schema' => ['type' => 'object', 'properties' => [
                'fields' => ['type' => 'object'], 'dry_run' => ['type' => 'boolean', 'default' => false],
            ], 'required' => ['fields']],
            'run' => static function (array $a): array {
                $in = $a['fields'] ?? null;
                if (!is_array($in) || !$in) throw new McpToolError('« fields » : objet non vide attendu.');
                $unknown = array_diff(array_keys($in), mcp_content_fields());
                if ($unknown) throw new McpToolError('Champ(s) inconnu(s) : ' . implode(', ', $unknown) . '.');
                $new = [];
                foreach ($in as $k => $v) {
                    $v = mcp_str($v, 20000);
                    if (in_array($k, ['pickup_only_below', 'gift_wrap_price'], true)) {
                        $n = str_replace(',', '.', $v);
                        if ($v === '') $v = $k === 'gift_wrap_price' ? '0' : '10';
                        elseif (!is_numeric($n) || (float) $n < 0 || (float) $n >= 100000) throw new McpToolError("$k : montant en euros attendu.");
                        else $v = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.') ?: '0';
                    } elseif ($k === 'gift_wrap_enabled') {
                        $v = mcp_bool($v === '' ? 0 : $v) ? '1' : '0';
                    } elseif ($k === 'site_name' && $v === '') {
                        throw new McpToolError('site_name ne peut pas être vide.');
                    }
                    $new[$k] = $v;
                }
                $cur = get_content();
                $before = array_intersect_key($cur, $new);
                $result = ['avant' => $before, 'apres' => $new];
                if (!empty($a['dry_run']) || ($before == $new)) return $result + ['applique' => false];
                mcp_backup_before_write();
                $set = implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($new)));
                db()->prepare("UPDATE content SET $set WHERE id = 1")->execute($new);
                return $result + ['applique' => true, '_audit' => [$before, $new]];
            },
        ],
        'setting_set' => [
            'write' => true,
            'description' => 'Modifie un réglage autorisé : ' . implode(', ', array_keys(mcp_setting_keys())) . '.',
            'schema' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['key', 'value']],
            'run' => static function (array $a): array {
                $key = (string) ($a['key'] ?? '');
                $allowed = mcp_setting_keys();
                if (!isset($allowed[$key])) throw new McpToolError('Réglage non modifiable. Permis : ' . implode(', ', array_keys($allowed)) . '.');
                $value = mcp_str($a['value'] ?? '', $allowed[$key][0]);
                if ($key === 'opening_hours' && $value !== '') {
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) throw new McpToolError('opening_hours : JSON invalide.');
                }
                $before = shop_setting($key, '');
                if ($before === $value) return ['cle' => $key, 'inchange' => true, 'applique' => false];
                mcp_backup_before_write();
                shop_setting_set($key, $value);
                return ['cle' => $key, 'avant' => $before, 'apres' => $value, 'applique' => true, '_audit' => [[$key => $before], [$key => $value]]];
            },
        ],
        'discounts_list' => [
            'write' => false,
            'description' => 'Liste les remises du panier (paliers), actives ou non.',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
            'run' => static function (array $a): array {
                return ['remises' => array_map(static fn ($t) => $t + ['description' => cart_discount_describe($t)], cart_discounts_list(false))];
            },
        ],
        'discount_add' => [
            'write' => true,
            'description' => 'Ajoute un palier de remise du panier. kind=total (seuil en euros) ou qty (seuil en nombre d\'articles) ; type=percent (valeur 1-100) ou fixed (valeur en euros).',
            'schema' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['total', 'qty']], 'threshold' => ['type' => ['number', 'string']],
                'type' => ['type' => 'string', 'enum' => ['percent', 'fixed']], 'value' => ['type' => ['number', 'string']],
                'label' => ['type' => 'string', 'description' => 'Nom affiché au client (≤ 60 car., facultatif).'],
            ], 'required' => ['kind', 'threshold', 'type', 'value']],
            'run' => static function (array $a): array {
                $kind = ($a['kind'] ?? '') === 'qty' ? 'qty' : (($a['kind'] ?? '') === 'total' ? 'total' : throw new McpToolError('kind : total ou qty.'));
                $type = ($a['type'] ?? '') === 'fixed' ? 'fixed' : (($a['type'] ?? '') === 'percent' ? 'percent' : throw new McpToolError('type : percent ou fixed.'));
                $threshold = $kind === 'qty' ? mcp_int($a['threshold'] ?? null, 1, 1000, 'threshold') : (price_to_cents((string) ($a['threshold'] ?? '')) ?? 0);
                if ($threshold < 1) throw new McpToolError('Seuil invalide (supérieur à 0).');
                if ($type === 'percent') {
                    $pc = (float) str_replace(',', '.', (string) ($a['value'] ?? ''));
                    if ($pc <= 0 || $pc > 100) throw new McpToolError('Pourcentage : entre 1 et 100.');
                    $value = (int) round($pc);
                } else {
                    $value = price_to_cents((string) ($a['value'] ?? '')) ?? 0;
                    if ($value < 1) throw new McpToolError('Montant de remise invalide (supérieur à 0).');
                }
                $label = mcp_str($a['label'] ?? '', 60);
                mcp_backup_before_write();
                db()->prepare('INSERT INTO cart_discounts (kind, threshold, type, value, label) VALUES (?, ?, ?, ?, ?)')->execute([$kind, $threshold, $type, $value, $label]);
                $row = ['id' => (int) db()->lastInsertId(), 'kind' => $kind, 'threshold' => $threshold, 'type' => $type, 'value' => $value, 'label' => $label];
                return ['cree' => $row + ['description' => cart_discount_describe($row)], '_audit' => [null, $row]];
            },
        ],
        'discount_set_active' => [
            'write' => true,
            'description' => 'Active ou désactive un palier de remise (aucune suppression).',
            'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'active' => ['type' => 'boolean']], 'required' => ['id', 'active']],
            'run' => static function (array $a): array {
                $id = mcp_int($a['id'] ?? null, 1, PHP_INT_MAX, 'id');
                $active = mcp_bool($a['active'] ?? null);
                $st = db()->prepare('SELECT * FROM cart_discounts WHERE id = ?'); $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new McpToolError('Palier introuvable.');
                if ((int) $row['is_active'] === $active) return ['id' => $id, 'inchange' => true, 'applique' => false];
                mcp_backup_before_write();
                db()->prepare('UPDATE cart_discounts SET is_active = ? WHERE id = ?')->execute([$active, $id]);
                return ['id' => $id, 'active' => (bool) $active, 'applique' => true, '_audit' => [['is_active' => (int) $row['is_active']], ['is_active' => $active]]];
            },
        ],
        'orders_list' => [
            'write' => false,
            'description' => 'Liste les commandes (lecture seule) : id, date, statut, préparation, total en centimes, e-mail. Filtres : status, fulfillment_status, limit (≤ 50), offset.',
            'schema' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string'], 'fulfillment_status' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'default' => 20], 'offset' => ['type' => 'integer', 'default' => 0],
            ]],
            'run' => static function (array $a): array {
                $where = []; $p = [];
                foreach (['status', 'fulfillment_status'] as $f) if (($v = mcp_str($a[$f] ?? '', 40)) !== '') { $where[] = "$f = :$f"; $p[$f] = $v; }
                $limit = isset($a['limit']) ? mcp_int($a['limit'], 1, 50, 'limit') : 20;
                $offset = isset($a['offset']) ? mcp_int($a['offset'], 0, 1000000, 'offset') : 0;
                $st = db()->prepare('SELECT id, created_at, status, fulfillment_status, amount_total, shipping_method, email, tracking_number FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT $limit OFFSET $offset");
                $st->execute($p);
                return ['commandes' => $st->fetchAll(PDO::FETCH_ASSOC)];
            },
        ],
        'order_get' => [
            'write' => false,
            'description' => 'Détail d\'une commande (lecture seule) : articles, livraison, statut.',
            'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            'run' => static function (array $a): array {
                $st = db()->prepare('SELECT id, created_at, status, fulfillment_status, amount_total, shipping_cents, shipping_method, shipping_address, email, tracking_number, items, gift_wrap, gift_message, source_channel FROM orders WHERE id = ?');
                $st->execute([mcp_int($a['id'] ?? null, 1, PHP_INT_MAX, 'id')]);
                $o = $st->fetch(PDO::FETCH_ASSOC);
                if (!$o) throw new McpToolError('Commande introuvable.');
                $items = json_decode((string) $o['items'], true);
                if (is_array($items)) $o['items'] = $items;
                return $o;
            },
        ],
        'audit_log' => [
            'write' => false,
            'description' => 'Journal des écritures faites par ce MCP (valeurs avant / après), pour contrôler ou annuler un changement. limit ≤ 50.',
            'schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'default' => 20]]],
            'run' => static function (array $a): array {
                mcp_ensure_schema();
                $limit = isset($a['limit']) ? mcp_int($a['limit'], 1, 50, 'limit') : 20;
                $rows = db()->query("SELECT id, at, tool, args, outcome, before_json, after_json FROM mcp_audit WHERE tool != 'auth' AND (before_json IS NOT NULL OR after_json IS NOT NULL) ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
                return ['journal' => $rows];
            },
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* JSON-RPC                                                            */
/* ------------------------------------------------------------------ */

function mcp_rpc_error($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

/** Traite un message JSON-RPC ; retourne la réponse, ou null pour une notification. */
function mcp_handle(array $msg): ?array
{
    $id = $msg['id'] ?? null;
    $method = (string) ($msg['method'] ?? '');
    $isNotification = !array_key_exists('id', $msg);
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    switch ($method) {
        case 'initialize':
            $wanted = (string) ($params['protocolVersion'] ?? '');
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => in_array($wanted, MCP_PROTOCOL_VERSIONS, true) ? $wanted : MCP_PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'brocante-mcp', 'version' => '1.0.0'],
                'instructions' => 'Site ' . app_env() . ' : les écritures sont sauvegardées et journalisées. Utilisez dry_run avant une modification risquée et confirmez avec l\'utilisateur avant d\'écrire sur la prod.',
            ]];
        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
        case 'tools/list':
            $list = [];
            foreach (mcp_tools() as $name => $t) {
                $list[] = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['schema'],
                    'annotations' => ['readOnlyHint' => !$t['write'], 'destructiveHint' => false, 'idempotentHint' => !$t['write']]];
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $list]];
        case 'tools/call':
            $name = (string) ($params['name'] ?? '');
            $tools = mcp_tools();
            if (!isset($tools[$name])) return mcp_rpc_error($id, -32602, 'Outil inconnu : ' . mb_substr($name, 0, 60));
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $out = $tools[$name]['run']($args);
                $audit = $out['_audit'] ?? null;
                unset($out['_audit']);
                if ($tools[$name]['write']) mcp_audit($name, $args, !empty($out['applique']) || isset($out['cree']) ? 'ok' : 'sans effet', $audit[0] ?? null, $audit[1] ?? null);
                $text = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]];
            } catch (McpToolError $e) {
                if ($tools[$name]['write']) mcp_audit($name, $args, 'refus : ' . $e->getMessage());
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]];
            } catch (Throwable $e) {
                error_log('MCP ' . $name . ' : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
                if ($tools[$name]['write']) mcp_audit($name, $args, 'erreur interne');
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'Erreur interne : rien n\'a été confirmé, vérifiez avec product_get / audit_log avant de réessayer.']], 'isError' => true]];
            }
        default:
            if ($isNotification) return null; // notifications/initialized, notifications/cancelled…
            return mcp_rpc_error($id, -32601, 'Méthode inconnue : ' . mb_substr($method, 0, 60));
    }
}
