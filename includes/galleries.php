<?php

// Galeries commerciales : une galerie regroupe les pièces de plusieurs commerces (une rue, un village, un groupe d'amis…) sur une
// page commune — /galerie/<identifiant>/ — avec un annuaire des commerces et des filtres. Chaque commerce garde sa propre boutique,
// son administration et son panier : la galerie ne fait que présenter et renvoyer vers la fiche de la pièce chez son commerçant.
//
// Un membre est soit un commerce de ce serveur (tenants/<slug>, base lue directement), soit un commerce distant (n'importe quelle adresse
// qui sert /catalogue.php — par exemple le Petit Chalet, hébergé dans un autre dossier). Les galeries vivent dans data/galeries/<slug>.json
// (jamais envoyé par le déploiement) ; le catalogue regroupé est mis en cache 10 minutes.
//
// Ce fichier ne dépend que de includes/tenant.php (pas de config.php) : il sert aussi bien la page publique, qui n'appartient à aucun
// commerce, que le portail.

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/appearance.php';

const GALLERY_CACHE_TTL = 600;
const GALLERY_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,39}$/';

function galleries_dir(): string
{
    return dirname(__DIR__) . '/data/galeries';
}

function gallery_slug_valid(string $slug): bool
{
    return (bool) preg_match(GALLERY_SLUG_PATTERN, $slug);
}

/** Identifiant libre à partir d'un nom : « Rue des Arts » → rue-des-arts ; « La Boutique Payée » → la-boutique-payee (sans dépendre d'iconv). */
function gallery_slugify(string $name): string
{
    $name = mb_strtolower($name);
    if (class_exists('Normalizer')) {
        $name = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($name, Normalizer::FORM_D));
    }
    $name = strtr($name, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o',
        'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n', 'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C', 'Ô' => 'O', 'Î' => 'I']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    return substr($s !== '' ? $s : 'galerie', 0, 40);
}

/** Toutes les galeries, par identifiant. */
function gallery_list(): array
{
    $out = [];
    foreach (glob(galleries_dir() . '/*.json') ?: [] as $file) {
        $slug = basename($file, '.json');
        if (gallery_slug_valid($slug) && ($g = gallery_load($slug))) $out[$slug] = $g;
    }
    ksort($out);
    return $out;
}

function gallery_load(string $slug): ?array
{
    if (!gallery_slug_valid($slug)) return null;
    $file = galleries_dir() . '/' . $slug . '.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) return null;
    return gallery_normalize($slug, $data);
}

function gallery_normalize(string $slug, array $data): array
{
    $members = [];
    foreach ((array) ($data['members'] ?? []) as $m) {
        if (!is_array($m)) continue;
        if (($m['type'] ?? '') === 'local' && preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string) ($m['slug'] ?? ''))) {
            $members[] = ['type' => 'local', 'slug' => (string) $m['slug'], 'featured' => !empty($m['featured'])];
        } elseif (($m['type'] ?? '') === 'remote' && gallery_remote_url_valid((string) ($m['url'] ?? ''))) {
            $members[] = ['type' => 'remote', 'name' => mb_substr(trim((string) ($m['name'] ?? '')), 0, 80), 'url' => rtrim((string) $m['url'], '/'), 'featured' => !empty($m['featured'])];
        }
    }
    $accent = (string) ($data['accent'] ?? '');
    return [
        'slug' => $slug,
        'name' => mb_substr(trim((string) ($data['name'] ?? $slug)), 0, 80) ?: $slug,
        'tagline' => mb_substr(trim((string) ($data['tagline'] ?? '')), 0, 160),
        'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 1200),
        'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '#b5502e',
        'published' => !empty($data['published']),
        'created' => (int) ($data['created'] ?? time()),
        'style' => gallery_style_normalize((array) ($data['style'] ?? [])),
        'members' => $members,
    ];
}

// ── Apparence de la page publique ───────────────────────────────────────────

const GALLERY_STYLE_CHOICES = [
    'banner_pos' => ['top' => 'Haut', 'center' => 'Centre', 'bottom' => 'Bas'],
    'hero_height' => ['compact' => 'Compact', 'normal' => 'Normal', 'tall' => 'Grand'],
    'hero_align' => ['left' => 'À gauche', 'center' => 'Centré'],
    'radius' => ['square' => 'Angles droits', 'soft' => 'Arrondis doux', 'round' => 'Très arrondis'],
    'card_ratio' => ['4/5' => 'Portrait (4:5)', '3/4' => 'Portrait haut (3:4)', '1/1' => 'Carré', '3/2' => 'Paysage (3:2)'],
    'density' => ['airy' => 'Aérée', 'compact' => 'Serrée'],
    'per_page' => ['12' => '12 pièces', '24' => '24 pièces', '48' => '48 pièces', '96' => '96 pièces'],
    'default_sort' => ['recent' => 'Nouveautés', 'prix-asc' => 'Prix croissant', 'prix-desc' => 'Prix décroissant', 'nom' => 'Nom'],
    'dark_mode' => ['auto' => 'Suit l\'appareil du visiteur', 'light' => 'Toujours clair'],
];

/** Réglages d'apparence d'une galerie, valeurs sûres (couleurs #rrggbb, choix dans des listes fermées, images sous uploads/galeries/). */
function gallery_style_normalize(array $s): array
{
    $color = static fn ($v): string => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? strtolower((string) $v) : '';
    $choice = static fn (string $key, $v, string $default): string => isset(GALLERY_STYLE_CHOICES[$key][(string) $v]) ? (string) $v : $default;
    $image = static fn ($v): string => preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*\.(?:jpg|png|webp)$#', (string) $v) ? (string) $v : '';
    $text = static fn ($v, int $max): string => mb_substr(trim((string) $v), 0, $max);
    return [
        'bg' => $color($s['bg'] ?? ''), 'ink' => $color($s['ink'] ?? ''), 'hero_color' => $color($s['hero_color'] ?? ''),
        'banner' => $image($s['banner'] ?? ''), 'logo' => $image($s['logo'] ?? ''),
        'banner_overlay' => max(0, min(80, (int) ($s['banner_overlay'] ?? 35))),
        'banner_pos' => $choice('banner_pos', $s['banner_pos'] ?? '', 'center'),
        'hero_height' => $choice('hero_height', $s['hero_height'] ?? '', 'normal'),
        'hero_align' => $choice('hero_align', $s['hero_align'] ?? '', 'left'),
        'font_display' => isset(APPEARANCE_FONTS['display'][(string) ($s['font_display'] ?? '')]) ? (string) $s['font_display'] : '',
        'font_body' => isset(APPEARANCE_FONTS['body'][(string) ($s['font_body'] ?? '')]) ? (string) $s['font_body'] : '',
        'radius' => $choice('radius', $s['radius'] ?? '', 'soft'),
        'card_ratio' => $choice('card_ratio', $s['card_ratio'] ?? '', '4/5'),
        'density' => $choice('density', $s['density'] ?? '', 'airy'),
        'per_page' => (int) $choice('per_page', $s['per_page'] ?? '', '24'),
        'default_sort' => $choice('default_sort', $s['default_sort'] ?? '', 'recent'),
        'dark_mode' => $choice('dark_mode', $s['dark_mode'] ?? '', 'auto'),
        'show_stats' => !array_key_exists('show_stats', $s) || !empty($s['show_stats']),
        'show_cta' => !array_key_exists('show_cta', $s) || !empty($s['show_cta']),
        'cta_label' => $text($s['cta_label'] ?? '', 50),
        'shops_title' => $text($s['shops_title'] ?? '', 60), 'shops_lede' => $text($s['shops_lede'] ?? '', 200), 'pieces_title' => $text($s['pieces_title'] ?? '', 60),
        'footer_text' => $text($s['footer_text'] ?? '', 300),
    ];
}

/**
 * Habillage calculé d'une galerie pour la page publique : 'css' (variables CSS, clair puis sombre), 'fonts' (adresse Google Fonts ou ''), 'allow_dark'
 * (la feuille de base peut-elle suivre le mode sombre ?), 'hero_ink' (couleur du texte de l'en-tête), 'radius', 'card_min'.
 * Sans couleur de fond ni de texte choisies, les teintes d'origine de la page sont gardées (seuls l'accent et l'en-tête changent).
 */
function gallery_theme(array $g): array
{
    $s = $g['style'];
    $accent = $g['accent'];
    $heroBg = $s['hero_color'] !== '' ? $s['hero_color'] : $accent;
    $heroInk = $s['banner'] !== '' ? '#ffffff' : appearance_on($heroBg);
    $vars = ['--accent' => $accent, '--accent-ink' => appearance_on($accent), '--hero-bg' => $heroBg, '--hero-ink' => $heroInk,
        '--radius' => ['square' => '4px', 'soft' => '14px', 'round' => '26px'][$s['radius']], '--ratio' => str_replace('/', ' / ', $s['card_ratio']),
        '--card-min' => $s['density'] === 'compact' ? '170px' : '210px'];
    if ($s['font_display'] !== '') $vars['--display'] = appearance_font_stack('display', $s['font_display']);
    if ($s['font_body'] !== '') $vars['--body'] = appearance_font_stack('body', $s['font_body']);
    $light = $dark = [];
    $custom = $s['bg'] !== '' || $s['ink'] !== '';
    if ($custom) {
        $pal = appearance_derive(['bg' => $s['bg'] !== '' ? $s['bg'] : '#f7f3ec', 'ink' => $s['ink'] !== '' ? $s['ink'] : '#221f1a', 'accent' => $accent, 'accent-2' => $accent]);
        foreach (['bg', 'surface', 'surface-2', 'ink', 'ink-soft', 'line'] as $k) $light['--' . $k] = $pal['light'][$k];
        foreach (['bg', 'surface', 'surface-2', 'ink', 'ink-soft', 'line', 'accent', 'accent-ink'] as $k) $dark['--' . $k] = $pal['dark'][$k];
    }
    $decl = static function (array $a): string { $o = ''; foreach ($a as $k => $v) $o .= $k . ':' . $v . ';'; return $o; };
    $css = ':root{' . $decl($vars + $light) . '}';
    $allowDark = $s['dark_mode'] === 'auto';
    if ($custom && $allowDark) $css .= '@media (prefers-color-scheme: dark){:root{' . $decl($dark) . '}}';
    if (!$allowDark) $css .= ':root{color-scheme:light}';
    $fonts = $s['font_display'] !== '' || $s['font_body'] !== '' ? appearance_fonts_url($s['font_display'] ?: 'Fraunces', $s['font_body'] ?: 'Archivo') : '';
    return ['css' => $css, 'fonts' => $fonts, 'allow_dark' => $allowDark && !$custom, 'hero_ink' => $heroInk];
}

/**
 * Enregistre une image de galerie envoyée par formulaire ($file = élément de $_FILES) sous uploads/galeries/ : redimensionnée (largeur max $maxW),
 * JPEG pour une bannière, PNG (transparence gardée) pour un logo. Retourne le chemin relatif. Lève InvalidArgumentException si le fichier ne convient pas.
 */
function gallery_image_store(array $file, string $slug, string $kind, int $maxW): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? "L'image est trop lourde pour le serveur." : "L'image n'a pas pu être envoyée.");
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new InvalidArgumentException('Image non reconnue : JPEG, PNG ou WebP.');
    if (($file['size'] ?? 0) > 12 * 1024 * 1024) throw new InvalidArgumentException("L'image dépasse 12 Mo.");
    $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']), default => @imagecreatefromwebp($file['tmp_name']) };
    if (!$src) throw new InvalidArgumentException("L'image est illisible.");
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, $maxW / $w);
    [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    $dst = imagecreatetruecolor($nw, $nh);
    $png = $kind === 'logo';
    if ($png) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = dirname(__DIR__) . '/uploads/galeries';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new InvalidArgumentException("Dossier uploads/galeries/ non inscriptible.");
    $name = $slug . '-' . $kind . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . ($png ? '.png' : '.jpg');
    $ok = $png ? imagepng($dst, "$dir/$name", 6) : imagejpeg($dst, "$dir/$name", 82);
    if (!$ok) throw new InvalidArgumentException("L'image n'a pas pu être enregistrée.");
    return 'uploads/galeries/' . $name;
}

/** Supprime une image de galerie (uniquement sous uploads/galeries/). */
function gallery_image_delete(string $path): void
{
    if (preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*$#', $path)) @unlink(dirname(__DIR__) . '/' . $path);
}

function gallery_save(array $gallery): bool
{
    $slug = (string) ($gallery['slug'] ?? '');
    if (!gallery_slug_valid($slug)) return false;
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $ok = @file_put_contents($dir . '/' . $slug . '.json', json_encode(gallery_normalize($slug, $gallery), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    gallery_cache_clear($slug);
    return $ok;
}

function gallery_delete(string $slug): void
{
    if (!gallery_slug_valid($slug)) return;
    if ($g = gallery_load($slug)) foreach (['banner', 'logo'] as $f) gallery_image_delete($g['style'][$f]);
    @unlink(galleries_dir() . '/' . $slug . '.json');
    gallery_cache_clear($slug);
}

/** Jeton du lien d'aperçu d'une galerie non publiée (?apercu=…) : propre à l'installation, impossible à deviner. */
function gallery_preview_token(array $gallery): string
{
    $dir = galleries_dir();
    $file = $dir . '/.salt';
    if (!is_file($file) && (is_dir($dir) || @mkdir($dir, 0755, true))) @file_put_contents($file, bin2hex(random_bytes(16)));
    return substr(hash_hmac('sha256', $gallery['slug'] . '|' . $gallery['created'], (string) @file_get_contents($file)), 0, 24);
}

function gallery_cache_file(string $slug): string
{
    return galleries_dir() . '/cache/' . $slug . '.json';
}

function gallery_cache_clear(string $slug): void
{
    @unlink(gallery_cache_file($slug));
}

/** Adresse d'un commerce distant : http(s), sans identifiants, hors adresses privées ou locales (sauf développement local). */
function gallery_remote_url_valid(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    $host = strtolower($p['host']);
    $local = $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $local = !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    // Les adresses locales ne sont acceptées que sur la machine de développement (config.local.php présent).
    return !$local || is_file(dirname(__DIR__) . '/config.local.php');
}

// ── Catalogue d'un commerce ─────────────────────────────────────────────────

/** Montant en centimes d'un prix libre (« 35,00 € », « dès 12 € »), ou null. */
function gallery_price_cents(?string $price): ?int
{
    if (!$price || !preg_match('/(\d[\d\s]*)(?:[,.](\d{1,2}))?/u', $price, $m)) return null;
    return (int) str_replace([' ', "\u{00a0}"], '', $m[1]) * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);
}

/** « https://hôte » d'une adresse, sans chemin. */
function gallery_origin(string $url): string
{
    $p = parse_url($url);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
}

/**
 * Catalogue public d'un commerce : ses infos et ses pièces visibles (non masquées, en stock), lues dans SA base.
 * Sert à la fois le flux /catalogue.php de chaque boutique et la lecture directe des commerces de ce serveur.
 *
 * @param string $shopUrl  adresse de la boutique (liens des pièces) ; @param string $assetOrigin  domaine qui sert assets/ et uploads/
 */
function catalogue_export(PDO $pdo, array $config, string $shopUrl, string $assetOrigin): array
{
    $setting = static function (string $name) use ($pdo) {
        try {
            $s = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
            $s->execute([$name]);
            return json_decode((string) $s->fetchColumn(), true) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    };
    $content = [];
    try { $content = $pdo->query('SELECT site_name, site_tagline FROM content WHERE id = 1')->fetch() ?: []; } catch (Throwable $e) {}

    $logos = $setting('logos') ?: [];
    $logo = (string) ($logos['square'] ?? $logos['horizontal'] ?? $config['logo_macaron'] ?? $config['logo'] ?? '');
    $appearance = $setting('appearance');
    $accent = (string) ($appearance['colors']['accent'] ?? $config['colors']['accent'] ?? '');

    $universes = $setting('universes') ?: ($config['categories'] ?? []);
    $labels = [];
    foreach ($universes as $u) {
        if (!empty($u['key'])) $labels[(string) $u['key']] = (string) ($u['label'] ?? $u['key']);
    }

    $products = [];
    try {
        $rows = $pdo->query("SELECT ref, name, cat, photo, price, promo_price, badge, description, created_at FROM products WHERE is_hidden = 0 AND stock > 0 ORDER BY sort_order, ref")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        $price = trim((string) $r['price']);
        $promo = trim((string) $r['promo_price']);
        $effective = gallery_price_cents($promo !== '' ? $promo : $price);
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string) $r['description'])));
        $products[] = [
            'ref' => (string) $r['ref'],
            'name' => (string) $r['name'],
            'cat' => (string) $r['cat'],
            'cat_label' => $labels[(string) $r['cat']] ?? ucfirst(str_replace(['-', '_'], ' ', (string) $r['cat'])),
            'photo' => $r['photo'] ? $assetOrigin . '/' . ltrim((string) $r['photo'], '/') : '',
            'price' => $price,
            'promo_price' => $promo,
            'price_cents' => $effective,
            'badge' => (string) $r['badge'],
            'description' => mb_substr($desc, 0, 160),
            'created_at' => (int) $r['created_at'],
            'url' => rtrim($shopUrl, '/') . '/produit.php?ref=' . rawurlencode((string) $r['ref']),
        ];
    }
    return [
        'shop' => [
            'name' => (string) (($content['site_name'] ?? '') !== '' ? $content['site_name'] : ($config['name'] ?? '')),
            'tagline' => (string) (($content['site_tagline'] ?? '') !== '' ? $content['site_tagline'] : ($config['tagline'] ?? '')),
            'url' => rtrim($shopUrl, '/') . '/',
            'logo' => $logo !== '' ? $assetOrigin . '/' . ltrim($logo, '/') : '',
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '',
            'universes' => array_values(array_unique(array_values($labels))),
        ],
        'products' => $products,
        'generated' => time(),
    ];
}

/**
 * Adresse publique d'un commerce de ce serveur.
 * - En ligne : son site_url réel ; s'il n'en a pas de réel, <domaine de la requête>/<identifiant>.
 * - Sur la machine de développement (config.local.php présent, jamais déployé) : toujours sa boutique LOCALE, jamais l'adresse en ligne :
 *   avec Herd (domaine en .test) une adresse par commerce, http://<identifiant>.brocenstock.test/ ; sans Herd (php -S), <domaine>/<identifiant>.
 */
function gallery_local_shop_url(string $slug, array $config, string $requestOrigin): string
{
    $url = rtrim((string) ($config['site_url'] ?? ''), '/');
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $placeholder = $host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test') || str_ends_with($host, '.example.com');
    $dev = is_file(dirname(__DIR__) . '/config.local.php');
    if (!$placeholder && !$dev) return $url;

    $p = parse_url($requestOrigin);
    $scheme = $p['scheme'] ?? 'http';
    $reqHost = strtolower((string) ($p['host'] ?? 'localhost'));
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $base = tenant_base_host($reqHost); // retire un éventuel sous-domaine de commerce (naty.brocenstock.test → brocenstock.test)
    if (str_ends_with($base, '.test')) return "$scheme://$slug.$base$port";
    return rtrim($requestOrigin, '/') . '/' . $slug;
}

/** Catalogue d'un commerce de ce serveur, lu directement dans sa base ; null s'il n'a pas (encore) de base. */
function catalogue_local(string $slug, string $requestOrigin): ?array
{
    if (!is_file(tenant_dir($slug) . '/tenant.php')) return null;
    $config = tenant_load($slug);
    $file = tenant_file($config, 'db_file');
    if (!is_file($file)) return null;
    try {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = 1');
        $shopUrl = gallery_local_shop_url($slug, $config, $requestOrigin);
        return catalogue_export($pdo, $config, $shopUrl, gallery_origin($shopUrl));
    } catch (Throwable $e) {
        error_log("catalogue_local($slug): " . $e->getMessage());
        return null;
    }
}

/** Catalogue d'un commerce distant : flux /catalogue.php de sa boutique (8 s au plus) ; null en cas d'échec. */
function catalogue_remote(string $url): ?array
{
    if (!gallery_remote_url_valid($url)) return null;
    $ch = curl_init(rtrim($url, '/') . '/catalogue.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = is_string($body) && $status === 200 ? json_decode($body, true) : null;
    if (!is_array($data) || !isset($data['shop'], $data['products']) || !is_array($data['products'])) {
        error_log("catalogue_remote($url): HTTP $status");
        return null;
    }
    return $data;
}

/** Garde seulement une adresse http(s) (images et liens venant d'un flux distant). */
function gallery_safe_url(?string $url): string
{
    $url = trim((string) $url);
    return preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : '';
}

/**
 * Catalogue regroupé d'une galerie : ['shops' => [...], 'products' => [...], 'skipped' => [noms des membres injoignables], 'generated' => t].
 * Mis en cache 10 minutes (fichier) ; $refresh force la relecture.
 */
function gallery_catalogue(array $gallery, string $requestOrigin, bool $refresh = false): array
{
    $cache = gallery_cache_file($gallery['slug']);
    if (!$refresh && is_file($cache) && time() - filemtime($cache) < GALLERY_CACHE_TTL) {
        $data = json_decode((string) file_get_contents($cache), true);
        if (is_array($data)) return $data;
    }
    $shops = [];
    $products = [];
    $skipped = [];
    foreach ($gallery['members'] as $i => $m) {
        $cat = $m['type'] === 'local' ? catalogue_local($m['slug'], $requestOrigin) : catalogue_remote($m['url']);
        $key = $m['type'] === 'local' ? $m['slug'] : 'r' . substr(md5($m['url']), 0, 8);
        if (!$cat) { $skipped[] = $m['type'] === 'local' ? $m['slug'] : ($m['name'] ?: $m['url']); continue; }
        $shop = $cat['shop'];
        $shop = [
            'key' => $key,
            'name' => ($shop['name'] ?? '') !== '' ? (string) $shop['name'] : ($m['name'] ?? $key),
            'tagline' => (string) ($shop['tagline'] ?? ''),
            'url' => gallery_safe_url($shop['url'] ?? ($m['url'] ?? '')),
            'logo' => gallery_safe_url($shop['logo'] ?? ''),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($shop['accent'] ?? '')) ? (string) $shop['accent'] : '',
            'featured' => !empty($m['featured']),
            'order' => $i,
            'count' => 0,
        ];
        foreach ($cat['products'] as $p) {
            if (!is_array($p) || (string) ($p['name'] ?? '') === '' || gallery_safe_url($p['url'] ?? '') === '') continue;
            $products[] = [
                'shop' => $key,
                'ref' => (string) ($p['ref'] ?? ''),
                'name' => mb_substr((string) $p['name'], 0, 120),
                'cat_label' => mb_substr((string) ($p['cat_label'] ?? 'Divers'), 0, 60) ?: 'Divers',
                'photo' => gallery_safe_url($p['photo'] ?? ''),
                'price' => mb_substr((string) ($p['price'] ?? ''), 0, 40),
                'promo_price' => mb_substr((string) ($p['promo_price'] ?? ''), 0, 40),
                'price_cents' => isset($p['price_cents']) && $p['price_cents'] !== null ? (int) $p['price_cents'] : null,
                'badge' => mb_substr((string) ($p['badge'] ?? ''), 0, 30),
                'description' => mb_substr((string) ($p['description'] ?? ''), 0, 160),
                'created_at' => (int) ($p['created_at'] ?? 0),
                'url' => gallery_safe_url($p['url']),
            ];
            $shop['count']++;
        }
        $shops[$key] = $shop;
    }
    $data = ['shops' => $shops, 'products' => $products, 'skipped' => $skipped, 'generated' => time()];
    $dir = dirname($cache);
    if (is_dir($dir) || @mkdir($dir, 0755, true)) @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $data;
}
