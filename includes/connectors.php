<?php
/**
 * Connecteurs e-commerce : alimentent le catalogue avec les produits d'une
 * boutique déjà existante (Shopify, WooCommerce, PrestaShop) ou poussés par
 * n'importe quelle plateforme via l'API d'envoi (api/catalog-push.php).
 *
 * Principe : chaque plateforme a un « adaptateur » qui lit sa propre API et
 * renvoie des produits au format commun (voir connector_item_defaults()). Tout
 * le reste — création/mise à jour de la fiche, photos, prix, stock, retrait
 * des produits disparus — est partagé par connector_upsert_item().
 *
 * Sens unique : la boutique distante reste la référence. Rien n'est jamais
 * renvoyé vers elle (une vente faite ici ne décrémente pas son stock).
 *
 * Voir CONNECTEURS.md pour le mode d'emploi et les limites.
 */

require_once __DIR__ . '/functions.php';

/** Produits lus par requête : borne le temps de la requête (photos téléchargées ; fastcgi coupe à ~60 s). */
const CONNECTOR_PAGE_SIZE = 20;
const CONNECTOR_MAX_IMAGES = 4;
const CONNECTOR_MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const CONNECTOR_MAX_IMAGE_PIXELS = 25000000;
const CONNECTOR_MAX_JSON_BYTES = 12 * 1024 * 1024;
const CONNECTOR_SHOPIFY_API_VERSION = '2025-10';

/* ------------------------------------------------------------------ */
/* Plateformes & schéma                                                */
/* ------------------------------------------------------------------ */

function connector_platforms(): array
{
    return [
        'shopify' => ['label' => 'Shopify', 'pull' => true],
        'woocommerce' => ['label' => 'WooCommerce', 'pull' => true],
        'prestashop' => ['label' => 'PrestaShop', 'pull' => true],
        'api' => ['label' => 'Autre plateforme (API d\'envoi)', 'pull' => false],
    ];
}

function connector_platform_label(string $platform): string
{
    return connector_platforms()[$platform]['label'] ?? $platform;
}

/** Crée les tables/colonnes à la volée, comme analytics_ensure_schema() : aucune migration à lancer. */
function connectors_ensure_schema(): void
{
    static $done = false;
    if ($done) return;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS import_sources (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      platform TEXT NOT NULL,
      base_url TEXT NOT NULL DEFAULT '',
      settings TEXT NOT NULL DEFAULT '{}',
      is_active INTEGER NOT NULL DEFAULT 1,
      last_sync_at TEXT,
      last_sync_status TEXT,
      last_sync_summary TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $ok = analytics_add_column('products', 'source_id', 'INTEGER');
    $ok = analytics_add_column('products', 'external_id', 'TEXT') && $ok;
    $ok = analytics_add_column('products', 'external_url', 'TEXT') && $ok;
    $ok = analytics_add_column('products', 'synced_at', 'TEXT') && $ok;
    if (!$ok) return; // base sans table products : on réessaiera
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_products_source_ext ON products(source_id, external_id) WHERE source_id IS NOT NULL');
    $done = true;
}

function connector_default_settings(): array
{
    return [
        'default_cat' => 'curiosites',
        'publish_mode' => 'hidden',   // hidden = relecture avant mise en ligne ; visible = publié directement
        'overwrite_content' => 0,     // 1 = une synchro réécrit aussi nom et description
        'sync_stock' => 1,            // 1 = le stock distant écrase le stock local à chaque synchro
        'badge' => 'Sélection',
        'vat_rate' => 20,             // PrestaShop : prix de l'API hors taxes
    ];
}

function connector_source_list(): array
{
    connectors_ensure_schema();
    $rows = db()->query('SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.source_id = s.id) AS product_count
                         FROM import_sources s ORDER BY s.id')->fetchAll();
    foreach ($rows as &$r) $r['settings'] = connector_decode_settings($r['settings']);
    return $rows;
}

function connector_source_get(int $id): ?array
{
    connectors_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM import_sources WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) return null;
    $r['settings'] = connector_decode_settings($r['settings']);
    return $r;
}

function connector_decode_settings(?string $json): array
{
    $d = json_decode((string) $json, true);
    return array_merge(connector_default_settings(), is_array($d) ? $d : []);
}

/** Réglages venant d'un formulaire, ramenés à des valeurs valides. */
function connector_clean_settings(array $in): array
{
    $def = connector_default_settings();
    $cats = array_column(category_list(), 'key');
    $badge = trim((string) ($in['badge'] ?? $def['badge']));
    return [
        'default_cat' => in_array($in['default_cat'] ?? '', $cats, true) ? $in['default_cat'] : $def['default_cat'],
        'publish_mode' => ($in['publish_mode'] ?? '') === 'visible' ? 'visible' : 'hidden',
        'overwrite_content' => !empty($in['overwrite_content']) ? 1 : 0,
        'sync_stock' => !empty($in['sync_stock']) ? 1 : 0,
        'badge' => mb_substr($badge !== '' ? $badge : $def['badge'], 0, 30),
        'vat_rate' => max(0, min(40, (float) str_replace(',', '.', (string) ($in['vat_rate'] ?? $def['vat_rate'])))),
    ];
}

/* ------------------------------------------------------------------ */
/* Identifiants (hors base : fichiers .secrets/, comme les clés Stripe) */
/* ------------------------------------------------------------------ */

function connector_secrets_dir(): string
{
    return defined('CONNECTOR_SECRETS_DIR') ? CONNECTOR_SECRETS_DIR : __DIR__ . '/../.secrets';
}

function connector_secrets_path(int $id): string
{
    return connector_secrets_dir() . '/connector-' . $id . '.json';
}

function connector_secrets_load(int $id): array
{
    $f = connector_secrets_path($id);
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function connector_secrets_save(int $id, array $secrets): void
{
    $dir = connector_secrets_dir();
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    // Au cas où le serveur web servirait ce dossier : interdit l'accès direct.
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $f = connector_secrets_path($id);
    file_put_contents($f, json_encode($secrets, JSON_UNESCAPED_SLASHES));
    @chmod($f, 0600);
}

function connector_secrets_delete(int $id): void
{
    @unlink(connector_secrets_path($id));
}

/* ------------------------------------------------------------------ */
/* Adresses                                                            */
/* ------------------------------------------------------------------ */

function connector_testing(): bool
{
    return defined('CONNECTOR_ALLOW_PRIVATE') && CONNECTOR_ALLOW_PRIVATE;
}

/**
 * Normalise l'adresse saisie par l'admin. Renvoie [url, erreur]. Shopify : on
 * ne garde que le domaine ; WooCommerce/PrestaShop : le sous-dossier éventuel
 * est conservé (site installé dans /boutique).
 */
function connector_normalize_base_url(string $raw, string $platform): array
{
    $raw = trim($raw);
    if ($raw === '') return ['', "L'adresse de la boutique est obligatoire."];
    if (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;
    $p = parse_url($raw);
    if (!$p || empty($p['host'])) return ['', "Adresse illisible : indiquez par exemple ma-boutique.myshopify.com."];
    if (isset($p['user']) || isset($p['pass'])) return ['', "L'adresse ne doit pas contenir d'identifiant."];
    $scheme = strtolower($p['scheme'] ?? 'https');
    if ($scheme !== 'https' && !connector_testing()) return ['', "L'adresse doit commencer par https:// (les identifiants ne doivent jamais circuler en clair)."];
    $host = strtolower($p['host']);
    if (!connector_testing() && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
        return ['', "Nom de domaine invalide : « $host »."];
    }
    $url = $scheme . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    if ($platform !== 'shopify') {
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        // Ne garde que des segments de chemin simples ; on retire une éventuelle fin d'API.
        $path = preg_replace('#/(wp-json|api|admin)(/.*)?$#i', '', $path);
        if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) $url .= $path;
    }
    return [$url, null];
}

/* ------------------------------------------------------------------ */
/* Réseau (avec garde anti-SSRF)                                       */
/* ------------------------------------------------------------------ */

/**
 * Valide une URL sortante et renvoie [host, port, ip] — l'IP est ensuite
 * « épinglée » dans curl pour qu'un second résolveur DNS ne puisse pas
 * renvoyer une adresse interne entre le contrôle et la connexion.
 */
function connector_resolve_public(string $url): array
{
    $p = parse_url($url);
    $scheme = strtolower($p['scheme'] ?? '');
    $host = strtolower($p['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') throw new RuntimeException('Adresse non autorisée.');
    if (isset($p['user']) || isset($p['pass'])) throw new RuntimeException('Adresse non autorisée.');
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!connector_testing()) {
        if ($scheme !== 'https') throw new RuntimeException('Seules les adresses https:// sont acceptées.');
        if (!in_array($port, [80, 443], true)) throw new RuntimeException('Port non autorisé.');
    }
    if (str_contains($host, ':') || str_starts_with($host, '[')) throw new RuntimeException('Adresse IPv6 non prise en charge.');

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new RuntimeException("Nom de domaine introuvable : $host.");
    foreach ($ips as $ip) {
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($public === false && !connector_testing()) throw new RuntimeException('Cette adresse pointe vers un réseau privé : refusée.');
    }
    return [$host, $port, $ips[0]];
}

/**
 * GET d'une URL distante. Redirections suivies à la main (3 max, chacune
 * revalidée) ; les identifiants ne sont envoyés qu'au domaine d'origine.
 * Renvoie ['status' => int, 'headers' => [minuscule => valeur], 'body' => string].
 *
 * @param array $opts userpwd, headers[], timeout, max_bytes
 */
function connector_http_get(string $url, array $opts = []): array
{
    $maxBytes = (int) ($opts['max_bytes'] ?? CONNECTOR_MAX_JSON_BYTES);
    $strip = fn(string $h) => preg_replace('/^www\./', '', strtolower($h));
    $originHost = $strip((string) parse_url($url, PHP_URL_HOST));

    for ($hop = 0; $hop <= 3; $hop++) {
        [$host, $port, $ip] = connector_resolve_public($url);
        $sameOrigin = $strip($host) === $originHost;

        $headers = [];
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        $curl = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 25),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE => ["$host:$port:$ip"],
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, image/*;q=0.8, */*;q=0.5', 'User-Agent: BrocanteConnector/1.0'], $sameOrigin ? ($opts['headers'] ?? []) : []),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $k = strtolower(trim($parts[0]));
                    $headers[$k] = isset($headers[$k]) ? $headers[$k] . ', ' . trim($parts[1]) : trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$tooBig, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) { $tooBig = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($sameOrigin && !empty($opts['userpwd'])) $curl[CURLOPT_USERPWD] = $opts['userpwd'];
        if (connector_testing()) $curl[CURLOPT_PROXY] = '';
        curl_setopt_array($ch, $curl);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooBig) throw new RuntimeException('Réponse trop volumineuse.');
        if ($ok === false) throw new RuntimeException("Connexion impossible à $host ($err).");

        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            $next = $headers['location'];
            if (!preg_match('#^https?://#i', $next)) {
                $base = parse_url($url);
                $next = ($base['scheme'] ?? 'https') . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '')
                    . (str_starts_with($next, '/') ? $next : '/' . $next);
            }
            $url = $next;
            continue;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
    throw new RuntimeException('Trop de redirections.');
}

/** GET JSON avec messages d'erreur lisibles par l'admin. Renvoie [données, en-têtes]. */
function connector_get_json(string $url, array $opts = []): array
{
    $r = connector_http_get($url, $opts);
    $s = $r['status'];
    if ($s === 401 || $s === 403) throw new RuntimeException("Accès refusé (HTTP $s) : vérifiez les identifiants et leurs droits de lecture sur les produits.");
    if ($s === 404) throw new RuntimeException('Adresse introuvable (HTTP 404) : vérifiez l\'adresse de la boutique et que son API est activée.');
    if ($s === 429) throw new RuntimeException('La boutique limite le débit (HTTP 429) : réessayez dans une minute.');
    if ($s >= 400) throw new RuntimeException("La boutique a répondu par une erreur (HTTP $s).");
    $data = json_decode($r['body'], true);
    if (!is_array($data)) throw new RuntimeException("Réponse inattendue (pas du JSON) : l'adresse n'est sans doute pas celle d'une boutique de ce type.");
    return [$data, $r['headers']];
}

/* ------------------------------------------------------------------ */
/* Outils de normalisation                                             */
/* ------------------------------------------------------------------ */

function connector_item_defaults(): array
{
    return [
        'external_id' => '',
        'name' => '',
        'description' => '',
        'price_cents' => null,    // prix facturé
        'compare_cents' => null,  // ancien prix (barré) si le produit est en promotion
        'stock' => null,          // null = non géré par la plateforme
        'weight_grams' => 0,
        'category_hint' => '',
        'images' => [],
        'image_userpwd' => '',    // identifiants à présenter pour télécharger les images (PrestaShop)
        'url' => '',
    ];
}

/** « 12.50 », « 12,50 € », 12.5 (float) → 1250. null si illisible ou négatif. */
function connector_money_to_cents($v): ?int
{
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return $v < 0 ? null : (int) round($v * 100);
    $s = preg_replace('/[^\d.,-]/u', '', (string) $v);
    if ($s === '' || str_starts_with($s, '-')) return null;
    $lastComma = strrpos($s, ',');
    $lastDot = strrpos($s, '.');
    if ($lastComma !== false && $lastDot !== false) {
        // « 1.234,56 » ou « 1,234.56 » : le dernier séparateur est la virgule décimale
        $dec = $lastComma > $lastDot ? ',' : '.';
        $thou = $dec === ',' ? '.' : ',';
        $s = str_replace($thou, '', $s);
        $s = str_replace($dec, '.', $s);
    } elseif ($lastComma !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (int) round(((float) $s) * 100) : null;
}

function connector_format_price(int $cents): string
{
    $s = number_format($cents / 100, 2, ',', ' ');
    return preg_replace('/,00$/', '', $s) . ' €';
}

function connector_weight_to_grams($value, string $unit = 'kg'): int
{
    if (!is_numeric($value)) return 0;
    $factor = ['kg' => 1000, 'g' => 1, 'lbs' => 453.592, 'lb' => 453.592, 'oz' => 28.3495][strtolower($unit)] ?? 1000;
    return max(0, (int) round(((float) $value) * $factor));
}

function connector_html_to_text(string $html): string
{
    $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
    $html = preg_replace('~<\s*(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>~i', "\n", $html);
    $html = preg_replace('~<\s*li\b[^>]*>~i', '• ', $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/ ?\n ?/', "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return mb_substr(trim($t), 0, 4000);
}

/** Rattache le produit à l'une des catégories de la boutique d'après des mots-clés ; sinon la catégorie par défaut. */
function connector_guess_category(string $text, string $default): string
{
    $map = [
        'ceramique' => '/c[ée]ramique|faïence|faience|porcelaine|poterie|grès|gres\b|vase|bol\b|assiette|tasse|pichet|théière|theiere|cruche/iu',
        'lumiere' => '/lampe|luminaire|bougie|bougeoir|lustre|applique|lanterne|chandelier|abat-jour/iu',
        'textile' => '/textile|plaid|coussin|nappe|rideau|tapis|linge|couverture|tissu|broderie|torchon/iu',
        'jardin' => '/jardin|pot de fleur|jardinière|jardiniere|arrosoir|outdoor|plein air|cache-pot/iu',
        'bois' => '/mobilier|chaise|table\b|tabouret|commode|armoire|bois|buffet|étagère|etagere|fauteuil|banc\b|bureau/iu',
    ];
    foreach ($map as $key => $re) {
        if (preg_match($re, $text)) return $key;
    }
    return $default;
}

/* ------------------------------------------------------------------ */
/* Adaptateurs : un par plateforme                                     */
/* Chacun renvoie ['items' => [...], 'next' => ?curseur, 'skipped' => n] */
/* ------------------------------------------------------------------ */

function connector_cursor_page(?string $cursor): int
{
    return $cursor !== null && preg_match('/^page:(\d{1,6})$/', $cursor, $m) ? max(1, (int) $m[1]) : 1;
}

function connector_fetch_page(array $source, ?string $cursor): array
{
    $secrets = connector_secrets_load((int) $source['id']);
    return match ($source['platform']) {
        'shopify' => connector_fetch_shopify($source, $secrets, $cursor),
        'woocommerce' => connector_fetch_woocommerce($source, $secrets, $cursor),
        'prestashop' => connector_fetch_prestashop($source, $secrets, $cursor),
        default => throw new RuntimeException("Cette source n'est pas synchronisable depuis ici (elle envoie ses produits elle-même)."),
    };
}

/* --- Shopify -------------------------------------------------------- */

function connector_fetch_shopify(array $source, array $secrets, ?string $cursor): array
{
    $base = rtrim($source['base_url'], '/');
    $token = trim((string) ($secrets['token'] ?? ''));
    $skipped = 0;

    if ($token !== '') {
        // API Admin : stocks exacts, pagination par curseur (en-tête Link).
        $q = $cursor !== null && preg_match('/^pi:([A-Za-z0-9_.=-]{1,500})$/', $cursor, $m)
            ? 'limit=' . CONNECTOR_PAGE_SIZE . '&page_info=' . rawurlencode($m[1])
            : 'limit=' . CONNECTOR_PAGE_SIZE . '&status=active';
        [$data, $hdr] = connector_get_json($base . '/admin/api/' . CONNECTOR_SHOPIFY_API_VERSION . '/products.json?' . $q,
            ['headers' => ['X-Shopify-Access-Token: ' . $token]]);
        $next = null;
        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>;\s*rel="next"/i', $hdr['link'] ?? '', $m)) $next = 'pi:' . urldecode($m[1]);
        $tracked = true;
    } else {
        // Sans clé : catalogue public /products.json (désactivé sur certaines boutiques).
        $page = connector_cursor_page($cursor);
        [$data, ] = connector_get_json($base . '/products.json?limit=' . CONNECTOR_PAGE_SIZE . '&page=' . $page);
        $next = count($data['products'] ?? []) >= CONNECTOR_PAGE_SIZE ? 'page:' . ($page + 1) : null;
        $tracked = false;
    }

    $items = [];
    foreach ($data['products'] ?? [] as $prod) {
        if (!is_array($prod)) continue;
        if (($prod['status'] ?? 'active') !== 'active') { $skipped++; continue; }
        $variants = $prod['variants'] ?? [];
        $images = [];
        $byId = [];
        foreach ($prod['images'] ?? [] as $img) {
            if (!empty($img['src'])) { $images[] = $img['src']; $byId[$img['id'] ?? 0] = $img['src']; }
        }
        $type = trim(($prod['product_type'] ?? '') . ' ' . (is_array($prod['tags'] ?? null) ? implode(' ', $prod['tags']) : ($prod['tags'] ?? '')));
        $multi = count($variants) > 1;
        foreach ($variants as $v) {
            $it = connector_item_defaults();
            $it['external_id'] = ($prod['id'] ?? '') . ':' . ($v['id'] ?? '');
            $vt = trim((string) ($v['title'] ?? ''));
            $it['name'] = trim((string) ($prod['title'] ?? '')) . ($multi && $vt !== '' && $vt !== 'Default Title' ? ' — ' . $vt : '');
            $it['description'] = connector_html_to_text((string) ($prod['body_html'] ?? ''));
            $it['price_cents'] = connector_money_to_cents($v['price'] ?? null);
            $cmp = connector_money_to_cents($v['compare_at_price'] ?? null);
            $it['compare_cents'] = ($cmp !== null && $it['price_cents'] !== null && $cmp > $it['price_cents']) ? $cmp : null;
            if ($tracked) {
                $it['stock'] = !empty($v['inventory_management']) ? max(0, (int) ($v['inventory_quantity'] ?? 0)) : null;
            } else {
                $it['stock'] = array_key_exists('available', $v) ? ($v['available'] ? 1 : 0) : null;
            }
            $it['weight_grams'] = max(0, (int) round((float) ($v['grams'] ?? 0)));
            $it['category_hint'] = $type;
            $own = $byId[$v['image_id'] ?? 0] ?? null;
            $it['images'] = $own ? array_values(array_unique(array_merge([$own], $images))) : $images;
            $it['url'] = !empty($prod['handle']) ? $base . '/products/' . rawurlencode($prod['handle']) : '';
            $items[] = $it;
        }
    }
    return ['items' => $items, 'next' => $next, 'skipped' => $skipped];
}

/* --- WooCommerce ---------------------------------------------------- */

function connector_fetch_woocommerce(array $source, array $secrets, ?string $cursor): array
{
    $key = trim((string) ($secrets['key'] ?? ''));
    $secret = trim((string) ($secrets['secret'] ?? ''));
    if ($key === '' || $secret === '') throw new RuntimeException('Clé et secret WooCommerce manquants.');
    $api = rtrim($source['base_url'], '/') . '/wp-json/wc/v3';
    $opts = ['userpwd' => $key . ':' . $secret];

    $page = connector_cursor_page($cursor);
    [$products, $hdr] = connector_get_json($api . '/products?per_page=' . CONNECTOR_PAGE_SIZE . '&page=' . $page . '&status=publish', $opts);
    if (isset($products['code'])) throw new RuntimeException('WooCommerce : ' . ($products['message'] ?? $products['code']));
    $totalPages = (int) ($hdr['x-wp-totalpages'] ?? 1);
    $next = $page < $totalPages ? 'page:' . ($page + 1) : null;

    $unit = 'kg';
    try {
        [$u] = connector_get_json($api . '/settings/products/woocommerce_weight_unit', $opts);
        if (!empty($u['value'])) $unit = (string) $u['value'];
    } catch (Throwable $e) { /* unité par défaut : kg */ }

    $items = [];
    $skipped = 0;
    foreach ($products as $p) {
        if (!is_array($p)) continue;
        $type = $p['type'] ?? 'simple';
        $images = array_values(array_filter(array_map(fn($i) => $i['src'] ?? '', $p['images'] ?? [])));
        $cats = implode(' ', array_map(fn($c) => $c['name'] ?? '', $p['categories'] ?? []));
        $desc = connector_html_to_text((string) (($p['description'] ?? '') !== '' ? $p['description'] : ($p['short_description'] ?? '')));

        if ($type === 'variable') {
            [$vars] = connector_get_json($api . '/products/' . (int) $p['id'] . '/variations?per_page=100', $opts);
            foreach ($vars as $v) {
                if (!is_array($v) || ($v['status'] ?? 'publish') !== 'publish') continue;
                $it = connector_woo_item($p, $v, $unit, $desc, $cats, $images);
                $suffix = trim(implode(', ', array_filter(array_map(fn($a) => $a['option'] ?? '', $v['attributes'] ?? []))));
                $it['external_id'] = $p['id'] . ':' . $v['id'];
                $it['name'] = trim((string) $p['name']) . ($suffix !== '' ? ' — ' . $suffix : '');
                if (!empty($v['image']['src'])) $it['images'] = array_values(array_unique(array_merge([$v['image']['src']], $images)));
                $items[] = $it;
            }
        } elseif ($type === 'simple') {
            $items[] = connector_woo_item($p, $p, $unit, $desc, $cats, $images);
        } else {
            $skipped++; // groupé / externe / abonnement : pas de prix achetable ici
        }
    }
    return ['items' => $items, 'next' => $next, 'skipped' => $skipped];
}

/** $p = produit parent, $v = le même produit (simple) ou l'une de ses variations. */
function connector_woo_item(array $p, array $v, string $unit, string $desc, string $cats, array $images): array
{
    $isVariation = (int) ($p['id'] ?? 0) !== (int) ($v['id'] ?? 0);
    $it = connector_item_defaults();
    $it['external_id'] = (string) $p['id'];
    $it['name'] = trim((string) ($p['name'] ?? ''));
    $it['description'] = $desc;
    $regular = connector_money_to_cents($v['regular_price'] ?? null);
    $current = connector_money_to_cents($v['price'] ?? null);
    $it['price_cents'] = $current ?? $regular;
    $it['compare_cents'] = (!empty($v['on_sale']) && $regular !== null && $it['price_cents'] !== null && $regular > $it['price_cents']) ? $regular : null;

    if (($v['manage_stock'] ?? false) === true) {
        $it['stock'] = max(0, (int) ($v['stock_quantity'] ?? 0));
    } elseif (($v['stock_status'] ?? 'instock') === 'outofstock') {
        $it['stock'] = 0;
    } elseif ($isVariation && !empty($p['manage_stock'])) {
        $it['stock'] = max(0, (int) ($p['stock_quantity'] ?? 0)); // la variation partage le stock du parent
    }

    $w = ($v['weight'] ?? '') !== '' ? $v['weight'] : ($p['weight'] ?? '');
    $it['weight_grams'] = connector_weight_to_grams($w, $unit);
    $it['category_hint'] = $cats;
    $it['images'] = $images;
    $it['url'] = (string) ($p['permalink'] ?? '');
    return $it;
}

/* --- PrestaShop ----------------------------------------------------- */

function connector_fetch_prestashop(array $source, array $secrets, ?string $cursor): array
{
    $key = trim((string) ($secrets['key'] ?? ''));
    if ($key === '') throw new RuntimeException('Clé de webservice PrestaShop manquante.');
    $base = rtrim($source['base_url'], '/');
    $opts = ['userpwd' => $key . ':'];

    $offset = $cursor !== null && preg_match('/^offset:(\d{1,7})$/', $cursor, $m) ? (int) $m[1] : 0;
    [$data] = connector_get_json($base . '/api/products?output_format=JSON&display=full&filter[active]=1&limit=' . $offset . ',' . CONNECTOR_PAGE_SIZE, $opts);
    $products = $data['products'] ?? [];
    if (!is_array($products)) $products = [];
    $next = count($products) >= CONNECTOR_PAGE_SIZE ? 'offset:' . ($offset + CONNECTOR_PAGE_SIZE) : null;

    // Stocks : un seul appel pour toute la page (déclinaisons ignorées : id_product_attribute = 0).
    $stocks = [];
    $ids = array_values(array_filter(array_map(fn($p) => (int) ($p['id'] ?? 0), $products)));
    if ($ids) {
        try {
            [$sd] = connector_get_json($base . '/api/stock_availables?output_format=JSON&display=[id_product,quantity]&filter[id_product_attribute]=[0]&filter[id_product]=[' . implode('|', $ids) . ']', $opts);
            foreach ($sd['stock_availables'] ?? [] as $row) $stocks[(int) $row['id_product']] = (int) $row['quantity'];
        } catch (Throwable $e) { /* stock inconnu → non géré */ }
    }

    $lang = (int) ($source['settings']['lang_id'] ?? 0);
    $pick = function ($field) use ($lang): string {
        if (is_array($field)) {
            foreach ($field as $f) {
                if (is_array($f) && ($lang === 0 || (int) ($f['id'] ?? 0) === $lang)) return (string) ($f['value'] ?? '');
            }
            $f = reset($field);
            return is_array($f) ? (string) ($f['value'] ?? '') : '';
        }
        return (string) $field;
    };

    $vat = (float) ($source['settings']['vat_rate'] ?? 0);
    $items = [];
    foreach ($products as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) continue;
        $it = connector_item_defaults();
        $it['external_id'] = (string) $id;
        $it['name'] = trim($pick($p['name'] ?? ''));
        $desc = $pick($p['description'] ?? '');
        $it['description'] = connector_html_to_text($desc !== '' ? $desc : $pick($p['description_short'] ?? ''));
        $ht = connector_money_to_cents($p['price'] ?? null);
        $it['price_cents'] = $ht === null ? null : (int) round($ht * (1 + $vat / 100));
        $it['stock'] = $stocks[$id] ?? null;
        $it['weight_grams'] = connector_weight_to_grams($p['weight'] ?? 0, 'kg');
        $it['category_hint'] = $it['name'];
        $imgIds = array_map(fn($i) => (int) ($i['id'] ?? 0), $p['associations']['images'] ?? []);
        $default = (int) ($p['id_default_image'] ?? 0);
        usort($imgIds, fn($a, $b) => ($b === $default) <=> ($a === $default));
        foreach (array_filter($imgIds) as $iid) $it['images'][] = $base . '/api/images/products/' . $id . '/' . $iid;
        $it['image_userpwd'] = $opts['userpwd'];
        $it['url'] = $base . '/index.php?controller=product&id_product=' . $id;
        $items[] = $it;
    }
    return ['items' => $items, 'next' => $next, 'skipped' => 0];
}

/* ------------------------------------------------------------------ */
/* Photos                                                              */
/* ------------------------------------------------------------------ */

/**
 * Télécharge une image distante et la renvoie réencodée en JPEG (redimensionnée,
 * fond blanc sous la transparence), ou null. Le réencodage via GD neutralise
 * au passage tout contenu qui ne serait pas réellement une image.
 */
function connector_download_image(string $url, string $userpwd = ''): ?string
{
    try {
        $r = connector_http_get($url, ['userpwd' => $userpwd, 'max_bytes' => CONNECTOR_MAX_IMAGE_BYTES, 'timeout' => 20]);
    } catch (Throwable $e) {
        return null;
    }
    if ($r['status'] !== 200) return null;
    $info = @getimagesizefromstring($r['body']);
    if (!$info || $info[0] * $info[1] > CONNECTOR_MAX_IMAGE_PIXELS) return null;
    $src = @imagecreatefromstring($r['body']);
    if (!$src) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 1400 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, 82);
    return ob_get_clean() ?: null;
}

/** Écrit un JPEG dans uploads/ et renvoie son chemin relatif. */
function connector_store_jpeg(string $jpeg, string $baseName): ?string
{
    $dir = defined('CONNECTOR_UPLOADS_DIR') ? CONNECTOR_UPLOADS_DIR : __DIR__ . '/../uploads'; // surchargé par les tests
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $baseName . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    return file_put_contents($dir . '/' . $file, $jpeg) === false ? null : 'uploads/' . $file;
}

/* ------------------------------------------------------------------ */
/* Création / mise à jour d'une fiche                                  */
/* ------------------------------------------------------------------ */

/**
 * Importe un produit au format commun. Renvoie 'created' ou 'updated' ;
 * lève une RuntimeException (message affichable) si le produit est inutilisable.
 *
 * @param array $imageCache url => JPEG déjà téléchargé (partagé entre variantes d'un même produit)
 */
function connector_upsert_item(array $source, array $item, string $runId, array &$imageCache): string
{
    connectors_ensure_schema();
    $item = array_merge(connector_item_defaults(), $item);
    $s = $source['settings'];
    $sid = (int) $source['id'];

    $extId = trim((string) $item['external_id']);
    if ($extId === '' || strlen($extId) > 190) throw new RuntimeException('identifiant produit absent ou trop long');
    $name = mb_substr(trim((string) $item['name']), 0, 200);
    if ($name === '') throw new RuntimeException("« $extId » : nom absent");
    if ($item['price_cents'] === null) throw new RuntimeException("« $name » : prix absent ou illisible");

    $price = connector_format_price((int) $item['price_cents']);
    $promo = $item['compare_cents'] !== null && $item['compare_cents'] > $item['price_cents'];
    $priceText = $promo ? connector_format_price((int) $item['compare_cents']) : $price;
    $promoText = $promo ? $price : null;
    $url = preg_match('#^https?://#i', (string) $item['url']) ? mb_substr($item['url'], 0, 500) : null;

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM products WHERE source_id = ? AND external_id = ?');
    $stmt->execute([$sid, $extId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $set = ['price = :price', 'promo_price = :promo', 'external_url = :url', 'synced_at = :run'];
        $params = ['price' => $priceText, 'promo' => $promoText, 'url' => $url, 'run' => $runId, 'ref' => $existing['ref']];
        if ($promo) {
            $set[] = 'badge = :badge';
            $params['badge'] = 'Promo';
        } elseif ($existing['badge'] === 'Promo') {
            $set[] = 'badge = :badge';
            $params['badge'] = $s['badge'];
        }
        if ($s['sync_stock']) { $set[] = 'stock = :stock'; $params['stock'] = $item['stock'] ?? 1; }
        if ($item['weight_grams'] > 0) { $set[] = 'weight_grams = :w'; $params['w'] = (int) $item['weight_grams']; }
        if ($s['overwrite_content']) {
            $set[] = 'name = :name';
            $params['name'] = $name;
            if ($item['description'] !== '') { $set[] = 'description = :desc'; $params['desc'] = $item['description']; }
        }
        $pdo->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE ref = :ref')->execute($params);

        // Photos : seulement si la fiche n'en a aucune (téléchargement raté la première fois).
        $has = $pdo->prepare('SELECT COUNT(*) FROM product_photos WHERE product_ref = ?');
        $has->execute([$existing['ref']]);
        if ((int) $has->fetchColumn() === 0) connector_attach_images((string) $existing['ref'], $item, $imageCache);
        return 'updated';
    }

    // Création : images d'abord (réseau), insertion ensuite (verrou SQLite le plus court possible).
    $jpegs = connector_collect_images($item, $imageCache);
    $cat = connector_guess_category($item['category_hint'] . ' ' . $name, $s['default_cat']);

    for ($try = 0; ; $try++) {
        try {
            $pdo->beginTransaction();
            $ref = next_ref();
            $pdo->prepare('INSERT INTO products (ref, name, cat, photo, icon, description, price, promo_price, badge, weight_grams, stock, is_hidden, featured, sort_order, source_id, external_id, external_url, synced_at)
                           VALUES (:ref, :name, :cat, NULL, NULL, :desc, :price, :promo, :badge, :w, :stock, :hidden, 0, :sort, :sid, :ext, :url, :run)')
                ->execute([
                    'ref' => $ref, 'name' => $name, 'cat' => $cat,
                    'desc' => $item['description'] !== '' ? $item['description'] : 'Description à compléter.',
                    'price' => $priceText, 'promo' => $promoText,
                    'badge' => $promo ? 'Promo' : $s['badge'],
                    'w' => (int) $item['weight_grams'],
                    'stock' => $item['stock'] ?? 1,
                    'hidden' => $s['publish_mode'] === 'visible' ? 0 : 1,
                    'sort' => (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn(),
                    'sid' => $sid, 'ext' => $extId, 'url' => $url, 'run' => $runId,
                ]);
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Numéro de réf pris entre-temps par une autre requête : on retente une fois.
            if ($try >= 1 || !str_contains($e->getMessage(), 'UNIQUE')) throw new RuntimeException("« $name » : enregistrement impossible");
        }
    }
    connector_write_images($ref, $jpegs);
    return 'created';
}

/** @return string[] JPEG binaires (4 max), dans l'ordre de la plateforme. */
function connector_collect_images(array $item, array &$cache): array
{
    $out = [];
    foreach (array_slice(array_unique($item['images']), 0, CONNECTOR_MAX_IMAGES) as $url) {
        if (!array_key_exists($url, $cache)) $cache[$url] = connector_download_image($url, (string) $item['image_userpwd']);
        if ($cache[$url] !== null) $out[] = $cache[$url];
    }
    return $out;
}

function connector_write_images(string $ref, array $jpegs): void
{
    foreach ($jpegs as $i => $jpeg) {
        $path = connector_store_jpeg($jpeg, 'import-' . $ref);
        if ($path) add_product_photo($ref, $path, $i === 0 ? 'Photo principale' : 'Photo');
    }
}

function connector_attach_images(string $ref, array $item, array &$cache): void
{
    connector_write_images($ref, connector_collect_images($item, $cache));
}

/* ------------------------------------------------------------------ */
/* Synchronisation                                                     */
/* ------------------------------------------------------------------ */

function connector_new_run_id(): string
{
    return date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

function connector_valid_run_id(string $id): bool
{
    return (bool) preg_match('/^\d{14}-[a-f0-9]{6}$/', $id);
}

/**
 * Lit UNE page de la boutique distante et l'importe. À appeler en boucle
 * jusqu'à ce que 'next' soit null (l'admin le fait depuis le navigateur, un
 * cron depuis scripts/connectors-sync.php), puis connector_finish_run().
 *
 * @return array{created:int,updated:int,skipped:int,failed:int,errors:string[],next:?string}
 */
function connector_sync_page(array $source, ?string $cursor, string $runId): array
{
    @set_time_limit(120);
    $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'next' => null];
    $page = connector_fetch_page($source, $cursor);
    $cache = [];
    foreach ($page['items'] as $item) {
        try {
            $res[connector_upsert_item($source, $item, $runId, $cache)]++;
        } catch (Throwable $e) {
            $res['failed']++;
            if (count($res['errors']) < 5) $res['errors'][] = $e->getMessage();
        }
    }
    $res['skipped'] = (int) $page['skipped'];
    $res['next'] = $page['next'];
    return $res;
}

/**
 * Clôt une synchro complète : les produits de cette source que la boutique
 * distante ne renvoie plus passent en rupture (stock 0, jamais supprimés), et
 * le résultat est mémorisé. Renvoie le nombre de produits retirés.
 *
 * $complete = false (page en erreur) : rien n'est retiré. Une boutique qui
 * ne renvoie rien du tout (erreur d'accès, API vidée) ne retire rien non plus.
 */
function connector_finish_run(array $source, string $runId, bool $complete, array $totals): int
{
    $pdo = db();
    $sid = (int) $source['id'];
    $seen = $pdo->prepare('SELECT COUNT(*) FROM products WHERE source_id = ? AND synced_at = ?');
    $seen->execute([$sid, $runId]);
    $seenCount = (int) $seen->fetchColumn();

    $retired = 0;
    $note = '';
    if ($complete && $seenCount > 0) {
        $st = $pdo->prepare('UPDATE products SET stock = 0 WHERE source_id = ? AND stock > 0 AND (synced_at IS NULL OR synced_at <> ?)');
        $st->execute([$sid, $runId]);
        $retired = $st->rowCount();
    } elseif ($complete) {
        $note = ' La boutique n\'a renvoyé aucun produit : rien n\'a été retiré par précaution.';
    }

    $summary = sprintf('%d créé(s), %d mis à jour, %d retiré(s) de la vente, %d ignoré(s), %d en échec.%s',
        $totals['created'] ?? 0, $totals['updated'] ?? 0, $retired, $totals['skipped'] ?? 0, $totals['failed'] ?? 0, $note);
    $status = !$complete ? 'error' : (($totals['failed'] ?? 0) > 0 ? 'partial' : 'ok');
    $pdo->prepare('UPDATE import_sources SET last_sync_at = ?, last_sync_status = ?, last_sync_summary = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s'), $status, $summary, $sid]);
    return $retired;
}

/** Synchronisation complète d'une source dans un seul processus (cron / ligne de commande). */
function connector_sync_all_pages(array $source, ?callable $progress = null): array
{
    $run = connector_new_run_id();
    $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $cursor = null;
    $complete = true;
    $errors = [];
    for ($guard = 0; $guard < 2000; $guard++) {
        try {
            $r = connector_sync_page($source, $cursor, $run);
        } catch (Throwable $e) {
            $complete = false;
            $errors[] = $e->getMessage();
            break;
        }
        foreach (['created', 'updated', 'skipped', 'failed'] as $k) $totals[$k] += $r[$k];
        array_push($errors, ...$r['errors']);
        if ($progress) $progress($r);
        if ($r['next'] === null) break;
        $cursor = $r['next'];
    }
    $retired = connector_finish_run($source, $run, $complete, $totals);
    return $totals + ['retired' => $retired, 'complete' => $complete, 'errors' => $errors];
}

/** Supprime une source ; ses produits restent au catalogue, simplement détachés. */
function connector_delete_source(int $id): void
{
    $pdo = db();
    $pdo->prepare('UPDATE products SET source_id = NULL, external_id = NULL, external_url = NULL, synced_at = NULL WHERE source_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM import_sources WHERE id = ?')->execute([$id]);
    connector_secrets_delete($id);
}

/* ------------------------------------------------------------------ */
/* API d'envoi (plateformes qui poussent leurs produits)               */
/* ------------------------------------------------------------------ */

/** Jeton au format « <id source>.<aléa> » ; seul son empreinte est conservée. */
function connector_api_new_token(int $sourceId): string
{
    $token = $sourceId . '.' . bin2hex(random_bytes(24));
    $secrets = connector_secrets_load($sourceId);
    $secrets['token_hash'] = hash('sha256', $token);
    connector_secrets_save($sourceId, $secrets);
    return $token;
}

/** Source API correspondant au jeton fourni, ou null. */
function connector_api_source_for_token(string $token): ?array
{
    if (!preg_match('/^(\d{1,9})\.[a-f0-9]{48}$/', $token, $m)) return null;
    $source = connector_source_get((int) $m[1]);
    if (!$source || $source['platform'] !== 'api' || !$source['is_active']) return null;
    $hash = (string) (connector_secrets_load((int) $source['id'])['token_hash'] ?? '');
    return $hash !== '' && hash_equals($hash, hash('sha256', $token)) ? $source : null;
}

/**
 * Convertit un produit reçu en JSON (format documenté dans CONNECTEURS.md)
 * vers le format commun. « active: false » met le produit en rupture.
 */
function connector_item_from_api(array $in): array
{
    $it = connector_item_defaults();
    $it['external_id'] = trim((string) ($in['external_id'] ?? ''));
    $it['name'] = trim((string) ($in['name'] ?? ''));
    $it['description'] = connector_html_to_text((string) ($in['description'] ?? ''));
    $it['price_cents'] = connector_money_to_cents($in['price'] ?? null);
    $cmp = connector_money_to_cents($in['compare_at_price'] ?? null);
    $it['compare_cents'] = ($cmp !== null && $it['price_cents'] !== null && $cmp > $it['price_cents']) ? $cmp : null;
    $it['stock'] = isset($in['stock']) && is_numeric($in['stock']) ? max(0, (int) $in['stock']) : null;
    if (array_key_exists('active', $in) && !$in['active']) $it['stock'] = 0;
    $it['weight_grams'] = isset($in['weight_grams']) && is_numeric($in['weight_grams']) ? max(0, (int) $in['weight_grams']) : 0;
    $it['category_hint'] = trim((string) ($in['category'] ?? ''));
    $it['images'] = array_values(array_filter(array_map('strval', (array) ($in['images'] ?? [])), fn($u) => preg_match('#^https?://#i', $u)));
    $it['url'] = trim((string) ($in['url'] ?? ''));
    return $it;
}
