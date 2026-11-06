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
require_once __DIR__ . '/apps.php';
require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/gallery-map.php';
require_once __DIR__ . '/gallery-modules.php';
require_once __DIR__ . '/gallery-event.php';
require_once __DIR__ . '/gallery-promos.php';

const GALLERY_CACHE_TTL = 600;
const GALLERY_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,39}$/';

function galleries_dir(): string
{
    // BROCANTE_GALLERIES_DIR : dossier jetable pour les tests (scripts/test-buvette-galerie.php).
    return getenv('BROCANTE_GALLERIES_DIR') ?: dirname(__DIR__) . '/data/galeries';
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
    $type = preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', (string) ($data['type'] ?? '')) ? (string) $data['type'] : 'marchande';
    // Les lieux de vente du type (boutique, stand…) bornent les types de zone acceptés dans le plan.
    if (!function_exists('gt_type') && is_file(__DIR__ . '/gallery-types.php')) require_once __DIR__ . '/gallery-types.php';
    $typeLocations = function_exists('gt_type') ? (gt_type($type)['locations'] ?? null) : null;
    return [
        'slug' => $slug,
        'name' => mb_substr(trim((string) ($data['name'] ?? $slug)), 0, 80) ?: $slug,
        'tagline' => mb_substr(trim((string) ($data['tagline'] ?? '')), 0, 160),
        'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 1200),
        'presence_mode' => business_presence_mode($data['presence_mode'] ?? 'online'),
        'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '#b5502e',
        'published' => !empty($data['published']),
        'paused' => !empty($data['paused']),
        'created' => (int) ($data['created'] ?? time()),
        'style' => gallery_style_normalize((array) ($data['style'] ?? [])),
        'apps' => gallery_apps_normalize((array) ($data['apps'] ?? [])),
        'members' => $members,
        'admins' => gallery_admins_normalize((array) ($data['admins'] ?? [])),
        'plan' => gallery_plan_normalize((array) ($data['plan'] ?? [])),
        'gesture' => gallery_gesture_normalize($data['gesture'] ?? null),
        'type' => $type,
        'domain' => gallery_domain_normalize((string) ($data['domain'] ?? '')),
        'modules' => gallery_modules_normalize((array) ($data['modules'] ?? [])),
        'promos' => gallery_promos_normalize($data['promos'] ?? []),
        'shooting' => gallery_shooting_normalize($data['shooting'] ?? []),
        'event' => gallery_event_normalize((array) ($data['event'] ?? [])),
        'map' => gallery_map_normalize((array) ($data['map'] ?? []), array_map('gallery_member_key', $members), $typeLocations),
    ];
}

/**
 * Geste commercial attaché à une galerie : gratuité d'accès (kind=forever ou until) ou avantage de facturation
 * (kind=credit ou discount). Seuls forever et until maintiennent la galerie ouverte malgré l'offre.
 */
function gallery_gesture_normalize($gesture): ?array
{
    if (!is_array($gesture) || !in_array($gesture['kind'] ?? '', ['forever', 'until', 'credit', 'discount'], true)) return null;
    $kind = (string) $gesture['kind'];
    $until = max(0, (int) ($gesture['until'] ?? 0));
    return [
        'kind' => $kind,
        'until' => in_array($kind, ['until', 'credit', 'discount'], true) ? $until : 0,
        'amount_cents' => $kind === 'credit' ? max(0, min(1000000, (int) ($gesture['amount_cents'] ?? 0))) : 0,
        'percent' => $kind === 'discount' ? max(0, min(100, (int) ($gesture['percent'] ?? 0))) : 0,
        'note' => mb_substr(trim((string) ($gesture['note'] ?? '')), 0, 300),
        'created' => (int) ($gesture['created'] ?? time()),
    ];
}

/** Offre souscrite par la galerie (voir includes/gallery-offers.php) ; null pour une galerie créée à la main (active, sans limite). */
function gallery_plan_normalize(array $p): ?array
{
    if (empty($p['offer']) || !preg_match('/^[a-z0-9_-]{1,30}$/', (string) $p['offer'])) return null;
    return ['offer' => (string) $p['offer'], 'status' => in_array($p['status'] ?? '', ['trial', 'active', 'expired', 'canceled'], true) ? (string) $p['status'] : 'trial',
        'billing' => ($p['billing'] ?? '') === 'year' ? 'year' : 'month', 'max_members' => max(1, min(1000, (int) ($p['max_members'] ?? 1))),
        'trial_until' => (int) ($p['trial_until'] ?? 0), 'started' => (int) ($p['started'] ?? 0), 'ended' => (int) ($p['ended'] ?? 0),
        'stripe_subscription_id' => preg_match('/^[A-Za-z0-9_]{0,80}$/', (string) ($p['stripe_subscription_id'] ?? '')) ? (string) ($p['stripe_subscription_id'] ?? '') : '',
        'stripe_customer_id' => preg_match('/^[A-Za-z0-9_]{0,80}$/', (string) ($p['stripe_customer_id'] ?? '')) ? (string) ($p['stripe_customer_id'] ?? '') : '',
        'stripe_session_id' => preg_match('/^[A-Za-z0-9_]{0,120}$/', (string) ($p['stripe_session_id'] ?? '')) ? (string) ($p['stripe_session_id'] ?? '') : ''];
}

/** Comptes administrateurs de la galerie (voir includes/gallery-admin.php) : adresse, nom, mot de passe haché. */
function gallery_admins_normalize(array $admins): array
{
    $out = [];
    foreach ($admins as $a) {
        $email = mb_strtolower(trim((string) ($a['email'] ?? '')));
        if (!is_array($a) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($a['hash']) || isset($out[$email])) continue;
        $out[$email] = ['email' => $email, 'name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 80), 'hash' => (string) $a['hash'],
            'must_change' => !empty($a['must_change']), 'created' => (int) ($a['created'] ?? 0), 'last_login' => (int) ($a['last_login'] ?? 0)];
    }
    return array_values($out);
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
    // Bannières : liste sans doublon (six au plus) ; les galeries enregistrées avant le diaporama n'ont que « banner ».
    $banners = [];
    foreach ((array) ($s['banners'] ?? []) as $b) {
        $path = $image($b);
        if ($path !== '' && !in_array($path, $banners, true)) $banners[] = $path;
        if (count($banners) >= 6) break;
    }
    if (!$banners && ($legacy = $image($s['banner'] ?? '')) !== '') $banners[] = $legacy;
    return [
        'bg' => $color($s['bg'] ?? ''), 'ink' => $color($s['ink'] ?? ''), 'hero_color' => $color($s['hero_color'] ?? ''),
        'banner' => $banners[0] ?? '', 'banners' => $banners, 'logo' => $image($s['logo'] ?? ''),
        'banner_slideshow' => array_key_exists('banner_slideshow', $s) ? !empty($s['banner_slideshow']) : count($banners) > 1,
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

/** Bannières d'une galerie, dans l'ordre d'affichage (la première sert d'image de partage) ; six au plus. */
function gallery_banner_images(array $style): array
{
    $valid = static fn ($v): bool => (bool) preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*\.(?:jpg|png|webp)$#', (string) $v);
    $banners = [];
    foreach ((array) ($style['banners'] ?? []) as $path) {
        if ($valid($path) && !in_array($path, $banners, true)) $banners[] = (string) $path;
    }
    if (!$banners && $valid($style['banner'] ?? '')) $banners[] = (string) $style['banner'];
    return array_slice($banners, 0, 6);
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

/** Nom de domaine propre normalisé (« https://www.Exemple.fr/ » → « exemple.fr ») ; chaîne vide si ce n'est pas un domaine public. */
function gallery_domain_normalize(string $host): string
{
    $host = mb_strtolower(trim($host));
    $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
    $host = preg_replace('~[/:?#].*$~', '', (string) $host);
    $host = rtrim((string) $host, '.');
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);
    if (strlen($host) > 253) return '';
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) return '';
    return $host;
}

/** Galerie qui a réservé ce nom de domaine (autre que $exceptSlug), ou null. */
function gallery_domain_owner(string $host, string $exceptSlug = ''): ?string
{
    $host = gallery_domain_normalize($host);
    if ($host === '') return null;
    foreach (gallery_list() as $slug => $gallery) {
        if ($slug !== $exceptSlug && ($gallery['domain'] ?? '') === $host) return (string) $slug;
    }
    return null;
}

/** Galerie servie sur ce nom de domaine, ou null. */
function gallery_from_host(string $host): ?array
{
    static $cache = [];
    $host = gallery_domain_normalize($host);
    if ($host === '') return null;
    if (array_key_exists($host, $cache)) return $cache[$host];
    $cache[$host] = null;
    foreach (gallery_list() as $gallery) {
        if (($gallery['domain'] ?? '') === $host) { $cache[$host] = $gallery; break; }
    }
    return $cache[$host];
}

/** Adresse publique d'une galerie : son domaine propre s'il est réglé, sinon le chemin /galerie/<identifiant>/. */
function gallery_public_url(array $gallery, string $origin = ''): string
{
    $domain = (string) ($gallery['domain'] ?? '');
    if ($domain !== '') return 'https://' . $domain . '/';
    $origin = $origin !== '' ? $origin : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return rtrim($origin, '/') . '/galerie/' . rawurlencode((string) ($gallery['slug'] ?? '')) . '/';
}

function gallery_delete(string $slug): bool
{
    if (!gallery_slug_valid($slug)) return false;
    $gallery = gallery_load($slug);
    $file = galleries_dir() . '/' . $slug . '.json';
    if (!$gallery || !is_file($file)) return false;
    foreach (array_unique(array_filter(array_merge(gallery_banner_images($gallery['style']), [(string) ($gallery['style']['logo'] ?? ''), (string) ($gallery['map']['background']['path'] ?? '')]))) as $relative) {
        if (!preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*$#', $relative)) continue;
        $image = dirname(__DIR__) . '/' . $relative;
        if (is_file($image) && !@unlink($image)) return false;
    }
    if (!@unlink($file)) return false;
    // Base SQL des extensions de la galerie (includes/gallery-sql.php) : elle disparaît avec elle.
    foreach (['.db', '.db-wal', '.db-shm', '.db-journal'] as $suffix) @unlink(galleries_dir() . '/' . $slug . $suffix);
    @unlink(galleries_dir() . '/' . $slug . '.hours.json');   // horaires et journal des alertes de fermeture (includes/gallery-hours.php)
    gallery_cache_clear($slug);
    return true;
}

/** Export JSON portable sans comptes administrateurs, abonnement Stripe ni données de réservation. */
function gallery_export_payload(string $slug): ?array
{
    $gallery = gallery_load($slug);
    if (!$gallery) return null;
    $style = $gallery['style'];
    $assets = [];
    $files = [];
    foreach (gallery_banner_images($style) as $i => $path) $files[$i === 0 ? 'banner' : 'banner' . ($i + 1)] = $path;
    $files['logo'] = (string) ($style['logo'] ?? '');
    $style['banner'] = '';
    $style['banners'] = [];
    $style['logo'] = '';
    foreach ($files as $kind => $relative) {
        if ($relative === '') continue;
        if (!preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*$#', $relative)) {
            throw new RuntimeException('Chemin d’image de galerie non valide.');
        }
        $content = @file_get_contents(dirname(__DIR__) . '/' . $relative);
        if ($content === false || strlen($content) > 12 * 1024 * 1024) {
            throw new RuntimeException('Impossible de lire une image de la galerie pour l’export.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Une image de la galerie a un format non pris en charge.');
        }
        $assets[$kind] = ['mime' => $mime, 'data' => base64_encode($content)];
    }
    unset($gallery['admins'], $gallery['plan'], $gallery['gesture'], $gallery['modules'], $gallery['domain']);
    $gallery['style'] = $style;
    return ['format' => 'brocante-gallery', 'version' => 1, 'gallery' => $gallery, 'assets' => $assets];
}

/** Importe les images d’un export de galerie après validation et réencodage par gallery_image_store(). */
function gallery_import_assets(array $assets, string $slug): array
{
    $paths = [];
    try {
        foreach ($assets as $kind => $asset) {
            if (!preg_match('/^(?:banner|banner[2-6]|logo)$/', (string) $kind)) continue;
            $maxW = $kind === 'logo' ? 500 : 2000;
            if (!is_array($asset) || !is_string($asset['data'] ?? null) || strlen($asset['data']) > 18 * 1024 * 1024) {
                throw new InvalidArgumentException('Données d’image invalides dans le fichier importé.');
            }
            $binary = base64_decode($asset['data'], true);
            if ($binary === false || strlen($binary) > 12 * 1024 * 1024) {
                throw new InvalidArgumentException('Image invalide ou trop volumineuse dans le fichier importé.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'gallery-import-');
            if ($tmp === false || file_put_contents($tmp, $binary) === false) {
                if (is_string($tmp)) @unlink($tmp);
                throw new RuntimeException('Impossible de préparer une image importée.');
            }
            try {
                $paths[$kind] = gallery_image_store([
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $tmp,
                    'size' => strlen($binary),
                ], $slug, $kind, $maxW);
            } finally {
                @unlink($tmp);
            }
        }
        return $paths;
    } catch (Throwable $e) {
        foreach ($paths as $path) gallery_image_delete($path);
        throw $e;
    }
}

/** Enregistre une galerie importée sans restaurer les comptes d’administration ni la facturation source. */
function gallery_import_payload(array $payload, string $slug, ?array $existing = null): array
{
    if (($payload['format'] ?? '') !== 'brocante-gallery' || ($payload['version'] ?? null) !== 1
        || !is_array($payload['gallery'] ?? null) || !gallery_slug_valid($slug)) {
        throw new InvalidArgumentException('Le fichier n’est pas un export de galerie Brocante pris en charge.');
    }
    if ($existing !== null && ($existing['slug'] ?? '') !== $slug) {
        throw new InvalidArgumentException('La galerie cible ne correspond pas à la galerie à remplacer.');
    }
    $source = $payload['gallery'];
    $style = (array) ($source['style'] ?? []);
    $style['banner'] = '';
    $style['banners'] = [];
    $style['logo'] = '';
    $data = $source;
    $data['slug'] = $slug;
    $data['style'] = $style;
    $data['admins'] = $existing['admins'] ?? [];
    $data['plan'] = $existing['plan'] ?? null;
    $data['gesture'] = $existing['gesture'] ?? null;
    $data['domain'] = $existing['domain'] ?? '';
    $data['created'] = $existing['created'] ?? time();
    if ($existing === null) {
        $data['published'] = false;
        $data['paused'] = false;
    }
    $gallery = gallery_normalize($slug, $data);
    $assetPaths = gallery_import_assets((array) ($payload['assets'] ?? []), $slug);
    $banners = [];
    foreach ($assetPaths as $kind => $path) {
        if ($kind === 'logo') $gallery['style']['logo'] = $path;
        else $banners[] = $path;
    }
    if ($banners) {
        $gallery['style']['banners'] = array_slice($banners, 0, 6);
        $gallery['style']['banner'] = $banners[0];
    }
    if (!gallery_save($gallery)) {
        foreach ($assetPaths as $path) gallery_image_delete($path);
        throw new RuntimeException('Impossible d’enregistrer la galerie importée.');
    }
    if ($existing !== null) {
        $oldImages = array_merge(gallery_banner_images($existing['style']), [(string) ($existing['style']['logo'] ?? '')]);
        $newImages = array_merge(gallery_banner_images($gallery['style']), [(string) ($gallery['style']['logo'] ?? '')]);
        foreach (array_unique(array_filter($oldImages)) as $oldPath) {
            if (!in_array($oldPath, $newImages, true)) gallery_image_delete($oldPath);
        }
    }
    return $gallery;
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
/** Adresse de l'espace visiteur de la boutique si le plugin est activé, à jour et sous licence dans sa base ($pdo) ; sinon ''. */
function catalogue_visitor_url(PDO $pdo, string $shopUrl): string
{
    try {
        $p = $pdo->query("SELECT enabled, version, migration FROM plugins WHERE id = 'espace-visiteur'")->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int) $p['enabled'] !== 1) return '';
        require_once __DIR__ . '/plugins.php';
        $m = plugin_manifest('espace-visiteur');
        if (!$m || plugin_status($p, $m) !== 'ready') return '';
        $a = $pdo->query("SELECT status, trial_until FROM addon_access WHERE module = 'espace-visiteur'")->fetch(PDO::FETCH_ASSOC);
        if (!$a) return '';
        $ok = in_array($a['status'], ['active', 'complimentary'], true) || ($a['status'] === 'trial' && (int) $a['trial_until'] > time());
        return $ok ? rtrim($shopUrl, '/') . '/visiteur.php' : '';
    } catch (Throwable $e) {
        return '';
    }
}

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
    // Visuels promo (photo + encart CTA) des articles en promo : repris par les galeries tant qu'ils disent le prix actuel.
    $visuals = [];
    try {
        foreach ($pdo->query('SELECT ref, path, path_mobile, headline, cta, price, promo_price FROM promo_visuals')->fetchAll(PDO::FETCH_ASSOC) as $v) $visuals[(string) $v['ref']] = $v;
    } catch (Throwable $e) {
        $visuals = [];
    }
    foreach ($rows as $r) {
        $price = trim((string) $r['price']);
        $promo = trim((string) $r['promo_price']);
        $pv = $visuals[(string) $r['ref']] ?? null;
        $pvFresh = $pv && (string) $r['badge'] === 'Promo' && $promo !== '' && $price === (string) $pv['price'] && $promo === (string) $pv['promo_price'];
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
            'promo_visual' => $pvFresh ? $assetOrigin . '/' . ltrim((string) $pv['path'], '/') : '',
            'promo_visual_mobile' => $pvFresh && !empty($pv['path_mobile']) ? $assetOrigin . '/' . ltrim((string) $pv['path_mobile'], '/') : '',
            'promo_cta' => $pvFresh ? (string) $pv['cta'] : '',
        ];
    }
    return [
        'shop' => [
            'name' => (string) (($content['site_name'] ?? '') !== '' ? $content['site_name'] : ($config['name'] ?? '')),
            'tagline' => (string) (($content['site_tagline'] ?? '') !== '' ? $content['site_tagline'] : ($config['tagline'] ?? '')),
            'url' => rtrim($shopUrl, '/') . '/',
            'logo' => $logo !== '' ? $assetOrigin . '/' . ltrim($logo, '/') : '',
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '',
            'sales_enabled' => !array_key_exists('sales_enabled', $config) || !empty($config['sales_enabled']),
            'universes' => array_values(array_unique(array_values($labels))),
            'visitor_url' => catalogue_visitor_url($pdo, $shopUrl),
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
    if (!shop_state_is_public(shop_state_load($slug))) return null;
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

/** Catalogue d'un commerce distant : flux /catalogue.php, puis connecteur du site existant ; null en cas d'échec. */
function catalogue_remote(string $url): ?array
{
    if (!gallery_remote_url_valid($url)) return null;
    $ch = curl_init(rtrim($url, '/') . '/catalogue.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = is_string($body) && $status === 200 ? json_decode($body, true) : null;
    if (is_array($data) && isset($data['shop'], $data['products']) && is_array($data['products'])) {
        return $data;
    }
    // Site déjà en ligne hors Brocs (Shopify, WooCommerce, PrestaShop…) : lecture par connecteur.
    require_once __DIR__ . '/shop-connectors.php';
    $linked = connector_catalogue($url);
    if ($linked) return $linked;
    error_log("catalogue_remote($url): HTTP $status");
    return null;
}

/** Garde seulement une adresse http(s) (images et liens venant d'un flux distant). */
function gallery_safe_url(?string $url): string
{
    $url = trim((string) $url);
    return preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : '';
}

/**
 * Catalogue regroupé d'une galerie : ['shops' => [...], 'products' => [...], 'skipped' => [noms des membres injoignables], 'generated' => t].
 * Mis en cache 10 minutes (fichier) ; $refresh force la relecture et $useCache=false sert aux aperçus temporaires non persistants.
 */
function gallery_catalogue(array $gallery, string $requestOrigin, bool $refresh = false, bool $useCache = true): array
{
    $cache = gallery_cache_file($gallery['slug']);
    if ($useCache && !$refresh && is_file($cache) && time() - filemtime($cache) < GALLERY_CACHE_TTL) {
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
            'sales_enabled' => !array_key_exists('sales_enabled', $shop) || !empty($shop['sales_enabled']),
            'url' => gallery_safe_url($shop['url'] ?? ($m['url'] ?? '')),
            'logo' => gallery_safe_url($shop['logo'] ?? ''),
            'visitor_url' => gallery_safe_url($shop['visitor_url'] ?? ''),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($shop['accent'] ?? '')) ? (string) $shop['accent'] : '',
            'featured' => !empty($m['featured']),
            'order' => $i,
            'count' => 0,
        ];
        $salesEnabled = $shop['sales_enabled'];
        foreach ($cat['products'] as $p) {
            if (!is_array($p) || (string) ($p['name'] ?? '') === '' || gallery_safe_url($p['url'] ?? '') === '') continue;
            $products[] = [
                'shop' => $key,
                'ref' => (string) ($p['ref'] ?? ''),
                'name' => mb_substr((string) $p['name'], 0, 120),
                'cat_label' => mb_substr((string) ($p['cat_label'] ?? 'Divers'), 0, 60) ?: 'Divers',
                'photo' => gallery_safe_url($p['photo'] ?? ''),
                'price' => $salesEnabled ? mb_substr((string) ($p['price'] ?? ''), 0, 40) : '',
                'promo_price' => $salesEnabled ? mb_substr((string) ($p['promo_price'] ?? ''), 0, 40) : '',
                'price_cents' => $salesEnabled && isset($p['price_cents']) && $p['price_cents'] !== null ? (int) $p['price_cents'] : null,
                'badge' => $salesEnabled || ($p['badge'] ?? '') !== 'Promo' ? mb_substr((string) ($p['badge'] ?? ''), 0, 30) : '',
                'description' => mb_substr((string) ($p['description'] ?? ''), 0, 160),
                'created_at' => (int) ($p['created_at'] ?? 0),
                'url' => gallery_safe_url($p['url']),
                'promo_visual' => $salesEnabled ? gallery_safe_url($p['promo_visual'] ?? '') : '',
                'promo_visual_mobile' => $salesEnabled ? gallery_safe_url($p['promo_visual_mobile'] ?? '') : '',
                'promo_cta' => $salesEnabled ? mb_substr((string) ($p['promo_cta'] ?? ''), 0, 40) : '',
            ];
            $shop['count']++;
        }
        $shops[$key] = $shop;
    }
    $data = ['shops' => $shops, 'products' => $products, 'skipped' => $skipped, 'generated' => time()];
    $dir = dirname($cache);
    if ($useCache && (is_dir($dir) || @mkdir($dir, 0755, true))) {
        @file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return $data;
}

/** Galeries commerciales publiées dont la boutique courante est membre : [['name' => …, 'url' => …], …]. */
function shop_member_galleries(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $me = tenant_slug();
    if ($me === '' || !is_dir(galleries_dir())) return $cache;
    require_once __DIR__ . '/gallery-offers.php';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = $scheme . '://' . tenant_base_host();
    foreach (gallery_list() as $slug => $g) {
        if (empty($g['published']) || !go_is_live($g)) continue;
        foreach ($g['members'] as $m) {
            if ($m['type'] === 'local' && $m['slug'] === $me) {
                $cache[] = ['name' => $g['name'], 'url' => gallery_public_url($g, $origin)];
                break;
            }
        }
    }
    return $cache;
}
