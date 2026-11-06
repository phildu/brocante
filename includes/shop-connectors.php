<?php

// Connecteurs catalogue : lire les articles d'une boutique qui existe déjà (Shopify, WooCommerce, PrestaShop, autre boutique brocs)
// et les présenter au même format que /catalogue.php. Annuaire, galeries et promos s'en servent sans savoir d'où viennent les pièces.
//
// Rien n'est copié durablement : la lecture est mise en cache 15 minutes dans data/connecteurs/ (jamais servi par le web).
// PrestaShop exige la clé de son service web (PrestaShop → Paramètres avancés → Service Web) : elle est rangée dans
// .secrets/connecteurs/<clé>.json et n'apparaît jamais dans une adresse publique.

require_once __DIR__ . '/site-analyzer.php';

const CONNECTOR_CACHE_TTL = 900;
const CONNECTOR_MAX_PRODUCTS = 300;
const CONNECTOR_PROVIDERS = ['shopify', 'woocommerce', 'prestashop', 'brocs'];

function connector_cache_dir(): string
{
    $dir = defined('CONNECTEURS_CACHE_DIR') ? (string) CONNECTEURS_CACHE_DIR : dirname(__DIR__) . '/data/connecteurs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function connector_secrets_dir(): string
{
    $dir = defined('CONNECTEURS_SECRETS_DIR') ? (string) CONNECTEURS_SECRETS_DIR : dirname(__DIR__) . '/.secrets/connecteurs';
    if (!is_dir($dir)) @mkdir($dir, 0705, true);
    return $dir;
}

function connector_key(string $url): string
{
    return substr(sha1(strtolower(rtrim(trim($url), '/'))), 0, 16);
}

function connector_origin(string $url): string
{
    return site_analyzer_origin($url);
}

/** Réglages secrets d'un connecteur (aujourd'hui : la clé API PrestaShop). */
function connector_settings_load(string $url): array
{
    $file = connector_secrets_dir() . '/' . connector_key($url) . '.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

function connector_settings_save(string $url, array $settings): bool
{
    $data = ['api_key' => preg_match('/^[A-Za-z0-9]{8,64}$/', (string) ($settings['api_key'] ?? '')) ? (string) $settings['api_key'] : ''];
    return @file_put_contents(connector_secrets_dir() . '/' . connector_key($url) . '.json', json_encode($data, JSON_UNESCAPED_UNICODE)) !== false;
}

/** Prix lisible (« 35 € », « 12,50 € ») depuis des centimes ; '' si inconnu. */
function connector_money(?int $cents, string $currency = 'EUR'): string
{
    if ($cents === null) return '';
    $text = number_format($cents / 100, 2, ',', ' ');
    $text = str_ends_with($text, ',00') ? substr($text, 0, -3) : $text;
    return $text . ' ' . ($currency === 'EUR' ? '€' : $currency);
}

/** Centimes depuis un montant décimal (« 12.50 », « 12,5 € ») ; null si illisible. */
function connector_cents($amount, int $minorUnit = 2): ?int
{
    if (is_int($amount) || is_float($amount)) return (int) round((float) $amount * (10 ** $minorUnit));
    if (!is_string($amount) || trim($amount) === '') return null;
    if (!preg_match('/-?\d+(?:[.,]\d+)?/', str_replace([' ', "\u{00a0}", "\u{202f}"], '', $amount), $m)) return null;
    return (int) round((float) str_replace(',', '.', $m[0]) * (10 ** $minorUnit));
}

/** Montant en centimes d'un prix mineur WooCommerce (« 1250 » avec currency_minor_unit = 2). */
function connector_woo_cents($amount, int $minorUnit = 2): ?int
{
    if ($amount === null || $amount === '' || !is_numeric($amount)) return null;
    return (int) round((float) $amount * (10 ** (2 - max(0, min(4, $minorUnit)))));
}

function connector_http_json(string $url, array $headers = [], int $maxBytes = 2097152): ?array
{
    $res = site_analyzer_fetch($url, [
        'accept' => 'application/json,text/plain;q=0.5',
        'headers' => $headers,
        'max_bytes' => $maxBytes,
        'timeout' => 8,
        'connect_timeout' => 4,
    ]);
    if (!$res) return null;
    $data = json_decode($res['body'], true);
    return is_array($data) ? $data : null;
}

/** Profil public d'un site (nom, logo, couleur, accroche), mis en cache 6 h. */
function connector_profile(string $url, bool $refresh = false): array
{
    $url = rtrim(trim($url), '/');
    $file = connector_cache_dir() . '/profil-' . connector_key($url) . '.json';
    if (!$refresh && is_file($file) && time() - filemtime($file) < 6 * 3600) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    $profile = site_analyzer_analyze($url, ['probe' => false]) ?: [];
    @file_put_contents($file, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $profile;
}

/** Solution reconnue d'un site : fournie par l'appelant, sinon déduite de la page et des flux publics. */
function connector_detect(string $url, ?array $profile = null): string
{
    if ($profile && in_array($profile['platform'] ?? '', CONNECTOR_PROVIDERS, true)) return (string) $profile['platform'];
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_ends_with($host, '.myshopify.com')) return 'shopify';
    $profile = $profile ?: (site_analyzer_analyze($url, ['probe' => true]) ?: []);
    $platform = (string) ($profile['platform'] ?? '');
    return in_array($platform, CONNECTOR_PROVIDERS, true) ? $platform : '';
}

/** Catalogue vide au bon format. */
function connector_catalogue_shell(array $shop = []): array
{
    return [
        'shop' => array_merge([
            'name' => '', 'tagline' => '', 'url' => '', 'logo' => '', 'accent' => '',
            'sales_enabled' => true, 'universes' => [], 'visitor_url' => '',
        ], $shop),
        'products' => [],
        'generated' => time(),
    ];
}

function connector_map_shopify(array $payload, string $origin, array $profile = []): array
{
    $products = [];
    $universes = [];
    foreach ((array) ($payload['products'] ?? []) as $p) {
        if (!is_array($p) || trim((string) ($p['title'] ?? '')) === '') continue;
        $variants = array_values(array_filter((array) ($p['variants'] ?? []), 'is_array'));
        if (!$variants) continue;
        $priceCents = null;
        $compareCents = null;
        foreach ($variants as $v) {
            $price = connector_cents($v['price'] ?? null);
            if ($price === null) continue;
            if ($priceCents === null || $price < $priceCents) $priceCents = $price;
            $compare = connector_cents($v['compare_at_price'] ?? null);
            if ($compare !== null && $price !== null && $compare > $price && ($compareCents === null || $compare > $compareCents)) $compareCents = $compare;
        }
        if ($priceCents === null) continue;
        $promo = $compareCents !== null && $compareCents > $priceCents ? $priceCents : null;
        $regular = $promo !== null ? $compareCents : $priceCents;
        $cat = trim((string) ($p['product_type'] ?? ''));
        if ($cat === '' && !empty($p['tags'])) $cat = is_array($p['tags']) ? (string) reset($p['tags']) : (string) strtok((string) $p['tags'], ',');
        if ($cat !== '') $universes[$cat] = true;
        $photo = '';
        foreach ((array) ($p['images'] ?? []) as $img) {
            if (is_array($img) && !empty($img['src'])) { $photo = (string) $img['src']; break; }
        }
        $products[] = [
            'ref' => 'shopify-' . (string) ($p['id'] ?? md5((string) $p['title'])),
            'name' => mb_substr(trim((string) $p['title']), 0, 120),
            'cat_label' => mb_substr($cat !== '' ? $cat : 'Boutique', 0, 60),
            'photo' => preg_match('#^https?://#i', $photo) ? $photo : '',
            'price' => connector_money($regular),
            'promo_price' => connector_money($promo),
            'price_cents' => $promo ?? $regular,
            'badge' => $promo !== null ? 'Promo' : '',
            'description' => mb_substr(site_analyzer_text((string) ($p['body_html'] ?? ''), 160), 0, 160),
            'created_at' => ($t = strtotime((string) ($p['created_at'] ?? ''))) ? $t : 0,
            'url' => $origin . '/products/' . rawurlencode((string) ($p['handle'] ?? '')),
            'promo_visual' => '',
            'promo_visual_mobile' => '',
            'promo_cta' => '',
        ];
        if (count($products) >= CONNECTOR_MAX_PRODUCTS) break;
    }
    $cat = connector_catalogue_shell();
    $cat['shop'] = array_merge($cat['shop'], [
        'name' => mb_substr(trim((string) ($profile['name'] ?? '')), 0, 80),
        'tagline' => mb_substr(trim((string) ($profile['tagline'] ?? '')), 0, 160),
        'url' => $origin . '/',
        'logo' => preg_match('#^https?://#i', (string) ($profile['logo'] ?? '')) ? (string) $profile['logo'] : '',
        'accent' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($profile['accent'] ?? '')) ? strtolower((string) $profile['accent']) : '',
        'universes' => array_slice(array_keys($universes), 0, 6),
    ]);
    $cat['products'] = $products;
    return $cat;
}

function connector_map_woocommerce(array $payload, string $origin, array $profile = []): array
{
    $products = [];
    $universes = [];
    foreach ($payload as $p) {
        if (!is_array($p) || trim((string) ($p['name'] ?? '')) === '') continue;
        if (($p['stock_status'] ?? '') === 'outofstock' || ($p['is_in_stock'] ?? true) === false) continue;
        $prices = (array) ($p['prices'] ?? []);
        $minor = (int) ($prices['currency_minor_unit'] ?? 2);
        $currency = (string) ($prices['currency_code'] ?? 'EUR');
        $regular = connector_woo_cents($prices['regular_price'] ?? ($prices['price'] ?? null), $minor);
        $sale = connector_woo_cents($prices['sale_price'] ?? null, $minor);
        if ($regular === null && $sale === null) continue;
        $promo = ($sale !== null && $regular !== null && $sale < $regular) ? $sale : null;
        $price = $promo !== null ? $regular : ($regular ?? $sale);
        $cat = '';
        foreach ((array) ($p['categories'] ?? []) as $c) {
            if (is_array($c) && trim((string) ($c['name'] ?? '')) !== '') { $cat = trim((string) $c['name']); break; }
        }
        if ($cat !== '') $universes[$cat] = true;
        $photo = '';
        foreach ((array) ($p['images'] ?? []) as $img) {
            if (is_array($img) && !empty($img['src'])) { $photo = (string) $img['src']; break; }
        }
        $desc = (string) ($p['short_description'] ?? $p['description'] ?? '');
        $products[] = [
            'ref' => 'woo-' . (string) ($p['id'] ?? md5((string) $p['name'])),
            'name' => mb_substr(site_analyzer_text((string) $p['name'], 120), 0, 120),
            'cat_label' => mb_substr($cat !== '' ? $cat : 'Boutique', 0, 60),
            'photo' => preg_match('#^https?://#i', $photo) ? $photo : '',
            'price' => connector_money($price, $currency),
            'promo_price' => connector_money($promo, $currency),
            'price_cents' => $promo ?? $price,
            'badge' => $promo !== null ? 'Promo' : '',
            'description' => mb_substr(site_analyzer_text($desc, 160), 0, 160),
            'created_at' => ($t = strtotime((string) ($p['date_created'] ?? ''))) ? $t : 0,
            'url' => preg_match('#^https?://#i', (string) ($p['permalink'] ?? '')) ? (string) $p['permalink'] : '',
            'promo_visual' => '',
            'promo_visual_mobile' => '',
            'promo_cta' => '',
        ];
        if (count($products) >= CONNECTOR_MAX_PRODUCTS) break;
    }
    $cat = connector_catalogue_shell();
    $cat['shop'] = array_merge($cat['shop'], [
        'name' => mb_substr(trim((string) ($profile['name'] ?? '')), 0, 80),
        'tagline' => mb_substr(trim((string) ($profile['tagline'] ?? '')), 0, 160),
        'url' => $origin . '/',
        'logo' => preg_match('#^https?://#i', (string) ($profile['logo'] ?? '')) ? (string) $profile['logo'] : '',
        'accent' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($profile['accent'] ?? '')) ? strtolower((string) $profile['accent']) : '',
        'universes' => array_slice(array_keys($universes), 0, 6),
    ]);
    $cat['products'] = $products;
    return $cat;
}

/** Valeur multilingue PrestaShop (name, description, link_rewrite) : premier texte non vide. */
function connector_ps_value($value): string
{
    if (is_string($value)) return $value;
    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_array($item) && isset($item['value'])) return (string) $item['value'];
            if (is_string($item)) return $item;
        }
    }
    return '';
}

function connector_map_prestashop(array $payload, string $origin, array $profile = []): array
{
    $products = [];
    $universes = [];
    $rows = (array) ($payload['products'] ?? []);
    if (isset($payload['products']['product'])) $rows = (array) $payload['products']['product'];
    foreach ($rows as $p) {
        if (!is_array($p)) continue;
        if (isset($p['active']) && (string) $p['active'] === '0') continue;
        $name = connector_ps_value($p['name'] ?? '');
        if (trim($name) === '') continue;
        $price = connector_cents($p['final_price'] ?? ($p['price'] ?? null));
        if ($price === null) continue;
        $cat = connector_ps_value($p['category_default'] ?? '');
        if ($cat !== '') $universes[$cat] = true;
        $id = (string) ($p['id'] ?? '');
        $rewrite = connector_ps_value($p['link_rewrite'] ?? '');
        $imageId = (string) ($p['id_default_image'] ?? '');
        if ($imageId === '' && !empty($p['associations']['images']['image'][0]['id'])) $imageId = (string) $p['associations']['images']['image'][0]['id'];
        $photo = $imageId !== '' && $id !== '' ? $origin . '/' . $id . '-' . $imageId . '-home_default/' . ($rewrite !== '' ? rawurlencode($rewrite) : 'p') . '.jpg' : '';
        $products[] = [
            'ref' => 'presta-' . ($id !== '' ? $id : md5($name)),
            'name' => mb_substr(site_analyzer_text($name, 120), 0, 120),
            'cat_label' => mb_substr($cat !== '' ? $cat : 'Boutique', 0, 60),
            'photo' => $photo,
            'price' => connector_money($price),
            'promo_price' => '',
            'price_cents' => $price,
            'badge' => '',
            'description' => mb_substr(site_analyzer_text(connector_ps_value($p['description_short'] ?? ($p['description'] ?? '')), 160), 0, 160),
            'created_at' => ($t = strtotime((string) ($p['date_add'] ?? ''))) ? $t : 0,
            'url' => $rewrite !== '' ? $origin . '/' . $id . '-' . rawurlencode($rewrite) . '.html' : $origin . '/index.php?controller=product&id_product=' . rawurlencode($id),
            'promo_visual' => '',
            'promo_visual_mobile' => '',
            'promo_cta' => '',
        ];
        if (count($products) >= CONNECTOR_MAX_PRODUCTS) break;
    }
    $cat = connector_catalogue_shell();
    $cat['shop'] = array_merge($cat['shop'], [
        'name' => mb_substr(trim((string) ($profile['name'] ?? '')), 0, 80),
        'tagline' => mb_substr(trim((string) ($profile['tagline'] ?? '')), 0, 160),
        'url' => $origin . '/',
        'logo' => preg_match('#^https?://#i', (string) ($profile['logo'] ?? '')) ? (string) $profile['logo'] : '',
        'accent' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($profile['accent'] ?? '')) ? strtolower((string) $profile['accent']) : '',
        'universes' => array_slice(array_keys($universes), 0, 6),
    ]);
    $cat['products'] = $products;
    return $cat;
}

function connector_fetch_shopify(string $origin, array $profile): ?array
{
    $all = [];
    for ($page = 1; $page <= 4; $page++) {
        $data = connector_http_json($origin . '/products.json?limit=250&page=' . $page);
        $rows = is_array($data) ? (array) ($data['products'] ?? []) : [];
        if (!$rows) break;
        $all = array_merge($all, $rows);
        if (count($rows) < 250) break;
    }
    if (!$all) return null;
    return connector_map_shopify(['products' => $all], $origin, $profile);
}

function connector_fetch_woocommerce(string $origin, array $profile): ?array
{
    $all = [];
    for ($page = 1; $page <= 3; $page++) {
        $data = connector_http_json($origin . '/wp-json/wc/store/v1/products?per_page=100&page=' . $page);
        $rows = is_array($data) && array_is_list($data) ? $data : [];
        if (!$rows) break;
        $all = array_merge($all, $rows);
        if (count($rows) < 100) break;
    }
    if (!$all) return null;
    return connector_map_woocommerce($all, $origin, $profile);
}

function connector_fetch_prestashop(string $origin, array $profile, string $apiKey): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $apiKey)) return null;
    $query = '?output_format=JSON&display=full&limit=0,' . CONNECTOR_MAX_PRODUCTS;
    $data = connector_http_json($origin . '/api/products' . $query, ['Authorization: Basic ' . base64_encode($apiKey . ':')], 4194304);
    if (!$data) {
        $data = connector_http_json($origin . '/api/products?ws_key=' . rawurlencode($apiKey) . '&output_format=JSON&display=full&limit=0,300', [], 4194304);
    }
    if (!$data) return null;
    return connector_map_prestashop($data, $origin, $profile);
}

function connector_fetch_brocs(string $origin): ?array
{
    $data = connector_http_json($origin . '/catalogue.php', [], 2097152);
    if (!is_array($data) || !isset($data['shop'], $data['products']) || !is_array($data['products'])) return null;
    $data['generated'] = (int) ($data['generated'] ?? time());
    return $data;
}

/**
 * Catalogue normalisé d'une boutique existante (shape identique à /catalogue.php), ou null si le site
 * n'expose pas de catalogue lisible. $opts : provider (forcé), api_key (PrestaShop), profile (déjà analysé), refresh.
 */
function connector_catalogue(string $url, array $opts = [], bool $refresh = false): ?array
{
    $url = rtrim(trim($url), '/');
    if (!site_analyzer_url_valid($url)) return null;
    $profile = (array) ($opts['profile'] ?? []);
    if (!$profile) $profile = connector_profile($url, $refresh);
    $provider = (string) ($opts['provider'] ?? '');
    if (!in_array($provider, CONNECTOR_PROVIDERS, true)) $provider = connector_detect($url, $profile);
    if ($provider === '') return null;

    $apiKey = (string) ($opts['api_key'] ?? ($provider === 'prestashop' ? (string) (connector_settings_load($url)['api_key'] ?? '') : ''));
    $cacheFile = connector_cache_dir() . '/catalogue-' . connector_key($url) . '-' . $provider . '-' . substr(sha1($apiKey), 0, 8) . '.json';
    if (!$refresh && is_file($cacheFile) && time() - filemtime($cacheFile) < CONNECTOR_CACHE_TTL) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['shop'], $cached['products'])) return $cached;
    }

    $origin = connector_origin($url);
    try {
        $catalogue = match ($provider) {
            'shopify' => connector_fetch_shopify($origin, $profile),
            'woocommerce' => connector_fetch_woocommerce($origin, $profile),
            'prestashop' => connector_fetch_prestashop($origin, $profile, $apiKey),
            'brocs' => connector_fetch_brocs($origin),
            default => null,
        };
    } catch (Throwable $e) {
        error_log('Connecteur ' . $provider . ' (' . $origin . ') : ' . $e->getMessage());
        $catalogue = null;
    }
    if (!$catalogue) return null;
    if (($catalogue['shop']['name'] ?? '') === '') $catalogue['shop']['name'] = (string) ($profile['name'] ?? (string) parse_url($origin, PHP_URL_HOST));
    $catalogue['provider'] = $provider;
    $catalogue['generated'] = time();
    @file_put_contents($cacheFile, json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $catalogue;
}

/** Vide le cache d'un site (bouton « Actualiser »). */
function connector_cache_clear(string $url): void
{
    foreach (glob(connector_cache_dir() . '/*-' . connector_key($url) . '-*.json') ?: [] as $file) @unlink($file);
    @unlink(connector_cache_dir() . '/profil-' . connector_key($url) . '.json');
}