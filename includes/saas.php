<?php

// Vitrine de la plateforme (SaaS) : page d'accueil avec les formules, annuaire des commerces, inscription d'un commerçant.
// Pages publiques : accueil/, annuaire/, inscription/ (et galerie/ pour les galeries commerciales) ; validation des demandes par
// l'exploitant dans le portail (portail/inscriptions.php) ; formules, annuaire et réglages dans portail/offres.php.
//
// Réglages et demandes vivent dans data/ (jamais envoyé par le déploiement) :
//   data/saas.json                 nom de la plateforme, slogan, contact, couleur, formules, commerces masqués de l'annuaire, commerces d'ailleurs
//   data/inscriptions/<id>.json    une demande d'inscription (mot de passe SEULEMENT haché)
//
// Ce fichier ne dépend que de includes/tenant.php et includes/galleries.php (pas de config.php) : il sert les pages publiques, qui
// n'appartiennent à aucun commerce, comme le portail.

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/galleries.php';
require_once __DIR__ . '/stripe-mode.php';
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/tenant-factory.php';
require_once __DIR__ . '/autofeed.php';
require_once __DIR__ . '/oauth.php';

const SAAS_RESERVED_SLUGS = ['galerie', 'annuaire', 'inscription', 'accueil', 'portail', 'admin', 'assets', 'uploads', 'includes', 'data', 'tenants',
    'var', 'scripts', 'api', 'modal', 'static', 'www', 'mail', 'test', 'demo', 'dossier', 'aide'];
const SAAS_CACHE_TTL = 600;

function saas_data_dir(): string
{
    return dirname(__DIR__) . '/data';
}

/** Formules par défaut (modifiables dans le portail : Offres & annuaire). Les limites affichées sont du texte : elles ne sont pas appliquées. */
function saas_default_plans(): array
{
    return [
        ['key' => 'decouverte', 'name' => 'Découverte', 'price' => '0 €', 'period' => '/mois', 'featured' => false,
            'features' => ["Une boutique en ligne à votre image", "Jusqu'à 20 pièces", "Visible dans l'annuaire", "Support par e-mail"]],
        ['key' => 'standard', 'name' => 'Standard', 'price' => '19,90 €', 'period' => '/mois', 'featured' => true,
            'features' => ['Pièces illimitées', 'Photos, fiches et vidéos générées par l\'IA', 'Studio photo sur smartphone', 'Place dans une galerie commerciale', 'Support prioritaire']],
        ['key' => 'premium', 'name' => 'Premium', 'price' => '49,90 €', 'period' => '/mois', 'featured' => false,
            'features' => ['Tout dans Standard', 'Mise en avant dans l\'annuaire et les galeries', 'Adresse et identité personnalisées', 'Accompagnement à la prise en main']],
    ];
}

/** Réglages de la page d'accueil publique : textes du bandeau, images (uploads/saas/), diaporama, assombrissement. */
function saas_home_normalize(array $h): array
{
    $text = static fn ($v, int $max): string => mb_substr(trim((string) $v), 0, $max);
    $images = [];
    foreach ((array) ($h['images'] ?? []) as $image) {
        $image = (string) $image;
        if (!preg_match('#^uploads/saas/[a-z0-9][a-z0-9._-]*\.(?:jpg|png|webp)$#', $image) || in_array($image, $images, true)) continue;
        $images[] = $image;
        if (count($images) >= 6) break;
    }
    return [
        'eyebrow' => $text($h['eyebrow'] ?? '', 120),
        'title' => $text($h['title'] ?? '', 140),
        'lede' => $text($h['lede'] ?? '', 400),
        'images' => $images,
        'slideshow' => !array_key_exists('slideshow', $h) || !empty($h['slideshow']),
        'overlay' => max(0, min(80, (int) ($h['overlay'] ?? 35))),
    ];
}

/** Images du bandeau d'accueil, dans l'ordre d'affichage. */
function saas_home_banners(array $cfg): array
{
    return (array) ($cfg['home']['images'] ?? []);
}

/** Enregistre un bandeau d'accueil (JPEG, PNG ou WebP) redimensionné dans uploads/saas/ ; rend le chemin relatif. */
function saas_banner_store(array $file, int $maxW = 2000): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? "L'image est trop lourde pour le serveur." : "L'image n'a pas pu être envoyée.");
    }
    $info = @getimagesize((string) $file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('Image non reconnue : JPEG, PNG ou WebP.');
    }
    if ((int) ($file['size'] ?? 0) > 12 * 1024 * 1024) throw new InvalidArgumentException("L'image dépasse 12 Mo.");
    $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg((string) $file['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng((string) $file['tmp_name']), default => @imagecreatefromwebp((string) $file['tmp_name']) };
    if (!$src) throw new InvalidArgumentException("L'image est illisible.");
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, $maxW / $w);
    $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    $dir = dirname(__DIR__) . '/uploads/saas';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new InvalidArgumentException('Dossier uploads/saas/ non inscriptible.');
    $name = 'accueil-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
    if (!imagejpeg($dst, "$dir/$name", 82)) throw new InvalidArgumentException("L'image n'a pas pu être enregistrée.");
    return 'uploads/saas/' . $name;
}

/** Supprime une image du bandeau d'accueil (uniquement sous uploads/saas/). */
function saas_banner_delete(string $path): void
{
    if (preg_match('#^uploads/saas/[a-z0-9][a-z0-9._-]*$#', $path)) @unlink(dirname(__DIR__) . '/' . $path);
}

/** Réglages de la plateforme, valeurs par défaut comprises. */
function saas_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = saas_data_dir() . '/saas.json';
    $saved = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $accent = (string) ($saved['accent'] ?? '');
    $plans = [];
    foreach ((array) ($saved['plans'] ?? saas_default_plans()) as $p) {
        if (!is_array($p) || trim((string) ($p['name'] ?? '')) === '') continue;
        $plans[] = [
            'key' => preg_match('/^[a-z0-9-]{2,30}$/', (string) ($p['key'] ?? '')) ? (string) $p['key'] : saas_slug((string) $p['name']),
            'name' => mb_substr(trim((string) $p['name']), 0, 40),
            'price' => mb_substr(trim((string) ($p['price'] ?? '')), 0, 20),
            'period' => mb_substr(trim((string) ($p['period'] ?? '')), 0, 20),
            'featured' => !empty($p['featured']),
            'features' => array_slice(array_values(array_filter(array_map(static fn ($f) => mb_substr(trim((string) $f), 0, 120), (array) ($p['features'] ?? [])))), 0, 10),
        ];
    }
    $cfg = [
        'name' => mb_substr(trim((string) ($saved['name'] ?? '')) ?: 'Brocs', 0, 40),
        'tagline' => mb_substr(trim((string) ($saved['tagline'] ?? '')) ?: 'Votre boutique en ligne, seule ou réunie avec d\'autres commerçants.', 0, 160),
        'contact' => filter_var((string) ($saved['contact'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
        'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '#b5502e',
        // Adresse IP du serveur indiquée aux boutiques qui rattachent un domaine
        // déjà acheté (enregistrement A). Vide : celle du serveur est proposée.
        'domain_ip' => filter_var((string) ($saved['domain_ip'] ?? ''), FILTER_VALIDATE_IP) ?: '',
        // Depuis que le portail enregistre des tarifs (includes/saas-pricing.php), les formules par défaut viennent de la grille par défaut.
        'plans' => saas_pricing()['saved'] ? saas_pricing_legacy_plans() : ($plans ?: saas_default_plans()),
        // Garde-fou : nombre maximal de boutiques GRATUITES créées par 24 h (0 = pas de plafond), puisqu'aucune validation humaine n'est exigée.
        'free_daily_cap' => max(0, min(1000, (int) ($saved['free_daily_cap'] ?? 10))),
        'hidden' => array_values(array_filter(array_map('strval', (array) ($saved['hidden'] ?? ['exemple-librairie'])))),
        'remote' => array_values(array_filter((array) ($saved['remote'] ?? []), static fn ($r) => is_array($r) && gallery_remote_url_valid((string) ($r['url'] ?? '')))),
        'home' => saas_home_normalize((array) ($saved['home'] ?? [])),
    ];
    return $cfg;
}

function saas_config_save(array $cfg): bool
{
    $dir = saas_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $cfg['home'] = saas_home_normalize((array) ($cfg['home'] ?? []));
    $ok = @file_put_contents($dir . '/saas.json', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    @unlink(saas_data_dir() . '/saas/cache.json');
    return $ok;
}

function saas_slug(string $text): string
{
    return gallery_slugify($text);
}

/** Encre lisible (blanc ou presque noir) sur un fond $hex. */
function saas_ink_on(string $hex): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#1c1a17' : '#ffffff';
}

function saas_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function saas_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ── Annuaire des commerces ──────────────────────────────────────────────────

/**
 * Commerces de l'annuaire : les commerces de ce serveur (sauf ceux masqués) et les commerces d'ailleurs déclarés dans les réglages.
 * Chaque entrée : key, name, tagline, url, logo, accent, universes[], count. Mis en cache 10 minutes.
 */
function saas_directory(bool $refresh = false): array
{
    $cache = saas_data_dir() . '/saas/cache.json';
    if (!$refresh && is_file($cache) && time() - filemtime($cache) < SAAS_CACHE_TTL) {
        $data = json_decode((string) file_get_contents($cache), true);
        if (is_array($data)) return $data;
    }
    $cfg = saas_config();
    $origin = saas_origin();
    $shops = [];
    foreach (array_keys(tenant_list()) as $slug) {
        if (in_array($slug, $cfg['hidden'], true)) continue;
        $cat = catalogue_local($slug, $origin);
        if ($cat) $shops[] = saas_directory_entry($slug, $cat, $slug);
    }
    foreach ($cfg['remote'] as $r) {
        $cat = catalogue_remote((string) $r['url']);
        if ($cat) $shops[] = saas_directory_entry('r' . substr(md5((string) $r['url']), 0, 8), $cat, (string) ($r['name'] ?? ''));
    }
    // Commerces déjà en ligne ailleurs, inscrits par la page publique /annuaire/inscrire.php puis validés.
    require_once __DIR__ . '/annuaire-externe.php';
    $known = [];
    foreach ($shops as $s) $known[rtrim((string) $s['url'], '/')] = true;
    foreach (annuaire_ext_directory_entries() as $entry) {
        if (isset($known[rtrim($entry['url'], '/')])) continue;
        $shops[] = $entry;
    }
    usort($shops, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $dir = dirname($cache);
    if (is_dir($dir) || @mkdir($dir, 0755, true)) @file_put_contents($cache, json_encode($shops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $shops;
}

function saas_directory_entry(string $key, array $cat, string $fallbackName): array
{
    $shop = $cat['shop'];
    return [
        'key' => $key,
        'name' => ($shop['name'] ?? '') !== '' ? (string) $shop['name'] : $fallbackName,
        'tagline' => (string) ($shop['tagline'] ?? ''),
        'url' => gallery_safe_url($shop['url'] ?? ''),
        'logo' => gallery_safe_url($shop['logo'] ?? ''),
        'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($shop['accent'] ?? '')) ? (string) $shop['accent'] : '',
        'universes' => array_slice(array_values(array_map('strval', (array) ($shop['universes'] ?? []))), 0, 6),
        'count' => count((array) ($cat['products'] ?? [])),
        'preview' => array_values(array_filter(array_map(static fn ($p) => gallery_safe_url($p['photo'] ?? ''), array_slice((array) ($cat['products'] ?? []), 0, 8)))),
    ];
}

// ── Demandes d'inscription ──────────────────────────────────────────────────

function saas_requests_dir(): string
{
    return saas_data_dir() . '/inscriptions';
}

function saas_request_load(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $file = saas_requests_dir() . '/' . $id . '.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data + ['id' => $id] : null;
}

function saas_request_save(array $req): bool
{
    $dir = saas_requests_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents($dir . '/' . $req['id'] . '.json', json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

/** Toutes les demandes, les plus récentes d'abord. */
function saas_requests(): array
{
    $out = [];
    foreach (glob(saas_requests_dir() . '/*.json') ?: [] as $file) {
        if ($r = saas_request_load(basename($file, '.json'))) $out[] = $r;
    }
    usort($out, static fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
    return $out;
}

/**
 * Une demande occupe-t-elle encore son adresse de boutique ? Non si refusée ou annulée, ou si l'étape 1 (brouillon, paiement en attente)
 * date de plus de 24 h. Une demande PAYÉE ne s'expire jamais : de l'argent a été encaissé.
 */
function saas_request_active(array $r): bool
{
    $status = (string) ($r['status'] ?? '');
    if (in_array($status, ['rejected', 'cancelled'], true)) return false;
    if ($status === 'awaiting_payment' || $status === 'draft') return time() - (int) ($r['created'] ?? 0) < 86400;
    return true;
}

function saas_slug_taken(string $slug, string $exceptId = ''): bool
{
    if (in_array($slug, SAAS_RESERVED_SLUGS, true) || file_exists(dirname(__DIR__) . '/' . $slug) || is_dir(tenant_dir($slug))) return true;
    foreach (saas_requests() as $r) {
        if (saas_request_active($r) && ($r['id'] ?? '') !== $exceptId && ($r['slug'] ?? '') === $slug) return true;
    }
    return false;
}

/** Unités d'une prestation sur devis (le prix est une fourchette, jamais un prix ferme). */
const SAAS_SERVICE_QUOTE_UNITS = ['forfait' => 'forfait', 'seance' => 'la séance', 'mois' => 'par mois', 'intervention' => 'l’intervention'];

/** Prestations personnalisées proposées comme demandes de devis à la création d'une boutique. */
function saas_service_quote_defaults(): array
{
    return [
        'design-personnalise' => ['name' => 'Design personnalisé', 'description' => 'Identité visuelle et mise en page adaptées à votre activité.', 'min_cents' => 15000, 'max_cents' => 60000, 'unit' => 'forfait', 'active' => true],
        'setup' => ['name' => 'Accompagnement au setup', 'description' => 'Aide à la configuration initiale de votre boutique et de ses réglages.', 'min_cents' => 9000, 'max_cents' => 25000, 'unit' => 'forfait', 'active' => true],
        'formation-distance' => ['name' => 'Formation à distance', 'description' => 'Prise en main guidée de votre boutique en visioconférence.', 'min_cents' => 6000, 'max_cents' => 15000, 'unit' => 'seance', 'active' => true],
        'formation-presentiel' => ['name' => 'Formation en présentiel', 'description' => 'Formation personnalisée dans vos locaux ou à proximité, déplacement compris.', 'min_cents' => 25000, 'max_cents' => 70000, 'unit' => 'seance', 'active' => true],
        'hotline' => ['name' => 'Hotline', 'description' => 'Assistance téléphonique selon vos besoins.', 'min_cents' => 4000, 'max_cents' => 12000, 'unit' => 'mois', 'active' => true],
        'chatbot' => ['name' => 'Chatbot', 'description' => 'Étude d’un assistant conversationnel adapté à votre site.', 'min_cents' => 20000, 'max_cents' => 90000, 'unit' => 'forfait', 'active' => true],
    ];
}

function saas_service_quote_file(): string
{
    return saas_data_dir() . '/saas/services-devis.json';
}

/**
 * Catalogue des prestations sur devis, fourchettes comprises : les tarifs réglés dans le portail remplacent les valeurs de départ.
 * Une fourchette n'est jamais un prix ferme : elle informe le client avant sa demande de devis, faite en fin de parcours.
 */
function saas_service_quote_catalog(): array
{
    $saved = is_file(saas_service_quote_file()) ? (json_decode((string) file_get_contents(saas_service_quote_file()), true) ?: []) : [];
    $out = [];
    foreach (saas_service_quote_defaults() as $key => $base) {
        $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
        $min = max(0, min(10000000, (int) ($row['min_cents'] ?? $base['min_cents'])));
        $max = max(0, min(10000000, (int) ($row['max_cents'] ?? $base['max_cents'])));
        if ($max < $min) [$min, $max] = [$max, $min];
        $out[$key] = [
            'name' => mb_substr(trim((string) ($row['name'] ?? $base['name'])), 0, 60) ?: $base['name'],
            'description' => mb_substr(trim((string) ($row['description'] ?? $base['description'])), 0, 300),
            'min_cents' => $min,
            'max_cents' => $max,
            'unit' => isset(SAAS_SERVICE_QUOTE_UNITS[(string) ($row['unit'] ?? '')]) ? (string) $row['unit'] : $base['unit'],
            'active' => array_key_exists('active', $row) ? !empty($row['active']) : true,
        ];
    }
    return $out;
}

/** Enregistre les fourchettes (toutes les lignes du formulaire du portail). */
function saas_service_quote_save(array $rows): bool
{
    $clean = [];
    foreach (saas_service_quote_defaults() as $key => $base) {
        $row = is_array($rows[$key] ?? null) ? $rows[$key] : [];
        $min = max(0, min(10000000, (int) ($row['min_cents'] ?? $base['min_cents'])));
        $max = max(0, min(10000000, (int) ($row['max_cents'] ?? $base['max_cents'])));
        if ($max < $min) [$min, $max] = [$max, $min];
        $clean[$key] = [
            'name' => mb_substr(trim((string) ($row['name'] ?? $base['name'])), 0, 60) ?: $base['name'],
            'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 300),
            'min_cents' => $min,
            'max_cents' => $max,
            'unit' => isset(SAAS_SERVICE_QUOTE_UNITS[(string) ($row['unit'] ?? '')]) ? (string) $row['unit'] : 'forfait',
            'active' => !empty($row['active']),
        ];
    }
    $dir = dirname(saas_service_quote_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents(saas_service_quote_file(), json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

/** « 150 € – 600 € · forfait », « dès 40 € / mois » : fourchette lisible d'une prestation. */
function saas_service_quote_price(array $service): string
{
    $min = (int) ($service['min_cents'] ?? 0);
    $max = (int) ($service['max_cents'] ?? 0);
    $unit = SAAS_SERVICE_QUOTE_UNITS[(string) ($service['unit'] ?? '')] ?? '';
    if ($min <= 0 && $max <= 0) return 'Sur devis';
    $range = $min > 0 && $max > $min ? saas_money($min) . ' – ' . saas_money($max) : ($min > 0 ? saas_money($min) : saas_money($max));
    return $range . ($unit !== '' ? ' · ' . $unit : '');
}

function saas_service_quote_names(array $keys): array
{
    $catalog = saas_service_quote_catalog();
    $names = [];
    foreach ($keys as $key) {
        if (is_string($key) && isset($catalog[$key])) $names[] = $catalog[$key]['name'];
    }
    return array_values(array_unique($names));
}

/** Prestations actives, dans l'ordre du catalogue : la demande de devis ne propose que celles-là. */
function saas_service_quotes_active(): array
{
    return array_filter(saas_service_quote_catalog(), static fn (array $s): bool => !empty($s['active']));
}

/** Noms lisibles des plugins cochés à l'inscription : c'est un intérêt déclaré, pas encore un abonnement. */
function saas_plugin_interest_names(array $ids): array
{
    if (!$ids) return [];
    require_once __DIR__ . '/plugins.php';
    $catalog = plugin_catalog();
    $names = [];
    foreach ($ids as $id) {
        if (is_string($id) && isset($catalog[$id])) $names[] = (string) $catalog[$id]['name'];
    }
    return array_values(array_unique($names));
}

/** Limite : 5 demandes par heure et par adresse IP (compteur dans un fichier, adresse IP hachée). */
function saas_rate_limited(): bool
{
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|saas');
    $dir = saas_requests_dir() . '/.rate';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $file = $dir . '/' . substr($ip, 0, 24) . '.json';
    $times = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $times = array_values(array_filter($times, static fn ($t) => $t > time() - 3600));
    if (count($times) >= 5) return true;
    $times[] = time();
    @file_put_contents($file, json_encode($times));
    return false;
}

/** Le plafond de boutiques gratuites des dernières 24 h est-il atteint ? (formule d'un prix nul ; les formules payantes ne comptent pas) */
function saas_free_cap_reached(): bool
{
    $cfg = saas_config();
    $cap = (int) $cfg['free_daily_cap'];
    if ($cap <= 0) return false;
    $n = 0;
    foreach (saas_requests() as $r) {
        if (!in_array(($r['status'] ?? ''), ['rejected', 'cancelled'], true) && time() - (int) ($r['created'] ?? 0) < 86400 && (saas_req_offer($r)['cents'] ?? 0) === 0) $n++;
    }
    return $n >= $cap;
}

/**
 * ÉTAPE 1 — formule. Crée une demande « draft » avec un jeton secret (lien de reprise) : Stripe recueille l'e-mail pour une formule payante,
 * sinon il est demandé à l'étape de création de boutique. Retourne ['ok' => true, 'request' => …] ou ['ok' => false, 'errors' => …].
 */
/**
 * Champs du titulaire (particulier ou professionnel) et adresse postale, saisis à l'étape Préconfiguration.
 * Les valeurs absentes du formulaire sont reprises de la demande existante ; rien n'est validé ici.
 */
function saas_owner_input(array $in, array $base = []): array
{
    $kind = (string) ($in['owner_kind'] ?? $base['owner_kind'] ?? 'particulier');
    if (!in_array($kind, ['particulier', 'professionnel'], true)) $kind = 'particulier';
    $str = static fn (string $key, int $max): string => mb_substr(trim((string) ($in[$key] ?? $base[$key] ?? '')), 0, $max);
    return [
        'owner_kind' => $kind,
        'company_name' => $str('company_name', 100),
        'siret' => preg_replace('/\s+/', '', $str('siret', 30)),
        'vat' => $str('vat', 20),
        'address' => $str('address', 160),
        'postal_code' => $str('postal_code', 12),
        'city' => $str('city', 80),
        'address_lat' => $str('address_lat', 20),
        'address_lng' => $str('address_lng', 20),
    ];
}

/** Contrôles du titulaire et de l'adresse postale, retournés par nom de champ (vide si tout est valide). */
function saas_owner_validate(array $owner): array
{
    $err = [];
    if (mb_strlen($owner['address']) < 5) $err['address'] = 'Indiquez l’adresse postale (numéro et rue).';
    if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z -]{2,11}$/', $owner['postal_code'])) $err['postal_code'] = 'Indiquez un code postal valide.';
    if ($owner['city'] === '') $err['city'] = 'Indiquez la ville.';
    if ($owner['owner_kind'] === 'professionnel') {
        if ($owner['company_name'] === '') $err['company_name'] = 'Indiquez la raison sociale.';
        if (!preg_match('/^\d{14}$/', $owner['siret'])) $err['siret'] = 'Le SIRET doit comporter 14 chiffres.';
    }
    return $err;
}

function saas_request_start(array $in): array
{
    $err = [];
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $plan = (string) ($in['plan'] ?? '');
    $mode = (string) ($in['site_mode'] ?? 'sales');
    // La galerie choisie détermine la grille de prix (une galerie inconnue ou non publiée est ignorée : tarifs par défaut).
    $gallery = strtolower(trim((string) ($in['galerie'] ?? '')));
    if ($gallery !== '' && !isset(saas_signup_galleries()[$gallery])) $gallery = '';
    if (!in_array($mode, ['sales', 'showcase'], true)) $mode = 'sales';
    $presence = (string) ($in['shop_presence'] ?? 'store');
    if (!in_array($presence, ['store', 'online'], true)) $presence = 'store';
    // Lieu de vente choisi à l'étape 1 : conservé même sans galerie précise, il règle le vocabulaire boutique/stand.
    $placeType = strtolower(trim((string) ($in['lieu'] ?? '')));
    if ($placeType === 'solo' || !preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $placeType)) $placeType = '';
    if ($placeType === '' && $gallery !== '') {
        $placeGallery = gallery_load($gallery);
        $placeType = (string) ($placeGallery['type'] ?? '');
    }
    // IA de la boutique : avec (nom, textes et pré-remplissage assistés) ou sans (aucune génération).
    $aiMode = (string) ($in['ai_mode'] ?? 'with');
    if (!in_array($aiMode, ['with', 'without'], true)) $aiMode = 'with';
    $offer = saas_offer_snapshot($gallery, $plan, (string) ($in['facturation'] ?? 'month'), $mode);
    $quoteCatalog = saas_service_quote_catalog();
    $quoteInput = $in['service_quotes'] ?? [];
    $serviceQuotes = [];
    if (!is_array($quoteInput)) {
        $err['service_quotes'] = 'La sélection des prestations est invalide.';
    } else {
        foreach ($quoteInput as $service) {
            if (!is_string($service) || !isset($quoteCatalog[$service])) {
                $err['service_quotes'] = 'Une prestation sélectionnée n’est pas reconnue.';
                break;
            }
            $serviceQuotes[] = $service;
        }
        $serviceQuotes = array_values(array_unique($serviceQuotes));
    }
    // Plugins cochés à l'étape 1 : c'est un intérêt, pas un achat. Ils restent réglés après la création de la boutique.
    $pluginCatalog = function_exists('plugin_catalog') ? plugin_catalog() : [];
    $plugins = [];
    $pluginInput = $in['plugins'] ?? [];
    if (is_array($pluginInput)) {
        foreach ($pluginInput as $pluginId) {
            if (is_string($pluginId) && isset($pluginCatalog[$pluginId]) && !in_array($pluginId, $plugins, true)) $plugins[] = $pluginId;
        }
    }
    if ($presence === 'online' && function_exists('plugin_requires_store')) {
        $plugins = array_values(array_filter($plugins, static fn (string $id): bool => !plugin_requires_store($id)));
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) $err['email'] = 'Indiquez une adresse e-mail valide.';
    if (!$offer) $err['plan'] = 'Choisissez une formule.';
    if ($err) return ['ok' => false, 'errors' => $err];

    // Place pionnière : le rang et la remise sont figés maintenant, le prix remisé part chez Stripe.
    $lock = function_exists('eb_lock_offer') ? eb_lock_offer($gallery, $offer) : ['offer' => $offer, 'earlybird' => null];
    $offer = $lock['offer'];
    $req = ['id' => bin2hex(random_bytes(8)), 'token' => bin2hex(random_bytes(16)), 'created' => time(), 'status' => 'draft', 'email' => $email, 'plan' => $plan, 'site_mode' => $mode,
        'shop_presence' => $presence, 'ai_mode' => $aiMode, 'place_type' => $placeType, 'offer' => $offer, 'shop_name' => '', 'slug' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'about' => '', 'gallery' => $gallery, 'template' => SHOP_TEMPLATE_DEFAULT,
        'service_quotes' => $serviceQuotes, 'plugins' => $plugins, 'payment' => []];
    if (!empty($lock['earlybird'])) $req['earlybird'] = $lock['earlybird'];
    if (!saas_request_save($req)) return ['ok' => false, 'errors' => ['form' => "Impossible d'enregistrer la demande pour le moment : réessayez dans quelques minutes."]];
    saas_event('signup_started', ['ref' => $req['id'], 'email' => $email, 'plan' => $plan, 'gallery' => $gallery, 'actor' => 'client', 'note' => saas_offer_note($offer, $req['earlybird'] ?? null)]);
    if (!empty($lock['earlybird'])) {
        saas_event('pioneer_granted', ['ref' => $req['id'], 'email' => $email, 'plan' => $plan, 'gallery' => $gallery, 'actor' => 'client',
            'note' => eb_lock_label($lock['earlybird']) . ' · ' . saas_price_text((int) ($offer['cents'] ?? 0), (string) ($offer['billing'] ?? 'month'))]);
    }
    return ['ok' => true, 'request' => $req];
}

/**
 * Où envoyer le client après l'étape 1 (formulaire ou connexion sociale) : la page de paiement Stripe pour une formule payante ; l'étape 3
 * sinon, ou si Stripe répond par une erreur (la demande passe alors en « paiement manuel » : l'exploitant règle avec le client).
 */
function saas_payment_url(array $req, string $origin): string
{
    try {
        $session = saas_stripe_checkout($req, $origin);
        if (empty($session['url']) || empty($session['id'])) throw new RuntimeException('Réponse inattendue de Stripe.');
        $req['status'] = 'awaiting_payment';
        $req['payment'] = ['state' => 'awaiting', 'session' => (string) $session['id'], 'started' => time()];
        saas_request_save($req);
        saas_event('payment_started', ['ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'actor' => 'client']);
        return (string) $session['url'];
    } catch (RuntimeException $e) {
        error_log('saas_payment_url: ' . $e->getMessage());
        $req['payment'] = ['state' => 'manual', 'note' => 'Paiement en ligne indisponible : ' . $e->getMessage()];
        saas_request_save($req);
        saas_event('payment_manual', ['ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'note' => $req['payment']['note'], 'actor' => 'système']);
        return saas_creation_url($req, $origin);
    }
}

/** Adresse du configurateur (étape 2) d'une demande : r + jeton secret. */
function saas_configurateur_url(array $req, string $origin): string
{
    return $origin . '/inscription/configurateur.php?r=' . rawurlencode((string) ($req['id'] ?? '')) . '&k=' . rawurlencode((string) ($req['token'] ?? ''));
}

/** Adresse de l'étape design (étape 3) d'une demande : r + jeton secret. */
function saas_design_url(array $req, string $origin): string
{
    return $origin . '/inscription/design.php?r=' . rawurlencode((string) ($req['id'] ?? '')) . '&k=' . rawurlencode((string) ($req['token'] ?? ''));
}

/** Suite de l'étape 1 : configurateur de boutique (préconfiguration : nom, activité, domaine). */
function saas_after_start_url(array $req, string $origin): string
{
    return saas_configurateur_url($req, $origin);
}

/** Adresse de la page de création (étape 3) d'une demande : r + jeton secret. */
function saas_creation_url(array $req, string $origin): string
{
    return $origin . '/inscription/creer.php?r=' . $req['id'] . '&k=' . $req['token'];
}

/** Charge une demande d'après le couple (r, k) d'un lien ; null si le jeton ne correspond pas. */
function saas_request_by_link(string $id, string $token): ?array
{
    $req = saas_request_load($id);
    return $req && $token !== '' && hash_equals((string) ($req['token'] ?? ''), $token) ? $req : null;
}

/**
 * ÉTAPE 3 — détails de la boutique et e-mail (après le paiement, ou tout de suite pour une formule gratuite). Valide et enregistre dans la demande
 * (le mot de passe seulement haché). Retourne ['ok' => true, 'request' => …] ou ['ok' => false, 'errors' => …].
 */
function saas_request_complete(array $req, array $in): array
{
    $err = [];
    $email = strtolower(trim((string) (!empty($req['email']) ? $req['email'] : ($in['email'] ?? ''))));
    $shopName = mb_substr(trim((string) (!empty($in['shop_name']) ? $in['shop_name'] : (!empty($req['shop_name']) ? $req['shop_name'] : ($req['preferred_shop_name'] ?? '')))), 0, 60);
    $slug = strtolower(trim((string) ($in['slug'] ?? ''))) ?: ($shopName !== '' ? saas_slug($shopName) : '');
    $first = mb_substr(trim((string) ($in['first_name'] ?? '')), 0, 60);
    $last = mb_substr(trim((string) ($in['last_name'] ?? '')), 0, 60);
    $phone = mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30);
    $password = (string) ($in['password'] ?? '');
    $about = mb_substr(trim((string) ($in['about'] ?? $req['about'] ?? '')), 0, 400);
    $gallery = (string) ($req['gallery'] ?? '');   // choisie à l'étape 1 : c'est elle qui a déterminé le prix
    $template = shop_template_valid($req['template'] ?? null) ? (string) $req['template'] : (shop_template_valid($in['template'] ?? null) ? (string) $in['template'] : SHOP_TEMPLATE_DEFAULT);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) $err['email'] = 'Indiquez une adresse e-mail valide.';
    if (empty($req['terms_accepted']) && empty($in['terms'])) $err['terms'] = 'Acceptez les conditions pour continuer.';
    if ($shopName === '') $err['shop_name'] = 'Donnez un nom à votre boutique.';
    elseif (!preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $slug)) $err['slug'] = 'Adresse : 3 à 40 caractères (lettres minuscules, chiffres, tirets).';
    elseif (saas_slug_taken($slug, (string) $req['id'])) $err['slug'] = 'Cette adresse est déjà prise : choisissez-en une autre.';
    if ($first === '') $err['first_name'] = 'Indiquez votre prénom.';
    $generated = false;
    $social = !empty($req['social']);   // inscrit avec Google / Facebook… : le mot de passe est facultatif (la connexion se fait par le réseau social)
    if ($social && $password === '' && (string) ($in['password2'] ?? '') === '') { $password = bin2hex(random_bytes(16)); $generated = true; }
    if (mb_strlen($password) < 8) $err['password'] = 'Choisissez un mot de passe d\'au moins 8 caractères.';
    elseif (!$generated && $password !== (string) ($in['password2'] ?? '')) $err['password2'] = 'Les deux mots de passe ne sont pas identiques.';
    if ($err) return ['ok' => false, 'errors' => $err];

    $req = array_merge($req, ['email' => $email, 'terms_accepted' => !empty($req['terms_accepted']) ? (int) $req['terms_accepted'] : time(), 'shop_name' => $shopName, 'slug' => $slug, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_set' => !$generated, 'autofeed' => !empty($in['autofeed']), 'about' => $about, 'gallery' => $gallery, 'template' => $template, 'details_at' => time()]);
    return saas_request_save($req) ? ['ok' => true, 'request' => $req] : ['ok' => false, 'errors' => ['form' => "Impossible d'enregistrer pour le moment : réessayez dans quelques minutes."]];
}

// ── Génération de la boutique ───────────────────────────────────────────────

/** L'hôte est-il une machine de développement (Herd .test, localhost) ? Alors la boutique garde son adresse de démonstration. */
function saas_is_local_host(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return $host === 'localhost' || str_ends_with($host, '.test') || filter_var($host, FILTER_VALIDATE_IP) !== false;
}

/**
 * Crée la boutique d'une demande (modèle de design choisi, compte du commerçant avec le mot de passe haché de l'inscription),
 * l'ajoute à une galerie uniquement si l'exploitant l'a explicitement sélectionnée via $galleryOverride, marque la demande « approved » et écrit au commerçant.
 * Lève une exception si l'adresse est prise ou si la création échoue. Retourne notamment 'gallery' (galerie demandée) et 'gallery_name' (galerie effectivement rejointe).
 */
function saas_generate_shop(array $req, string $origin, string $galleryOverride = ''): array
{
    $slug = (string) $req['slug'];
    if (is_dir(dirname(__DIR__) . "/tenants/$slug") || file_exists(dirname(__DIR__) . '/' . $slug) || in_array($slug, SAAS_RESERVED_SLUGS, true)) {
        throw new InvalidArgumentException("L'adresse « $slug » est déjà prise.");
    }
    $login = saas_admin_login($req);
    $noAi = ($req['ai_mode'] ?? 'with') === 'without';
    // Bandeau et articles d'exemple (facultatif) : jamais pour une boutique « sans IA ».
    $feed = !$noAi && !empty($req['autofeed']) ? autofeed_form_fields($req) : [];
    $tpl = shop_template_valid($req['template'] ?? null) ? (string) $req['template'] : SHOP_TEMPLATE_DEFAULT;
    // Coordonnées et identité saisies à l'étape 2 (particulier ou professionnel) : contact, pages légales et référencement local.
    $ownerAddress = trim((string) ($req['address'] ?? '') . ' ' . trim(trim((string) ($req['postal_code'] ?? '')) . ' ' . trim((string) ($req['city'] ?? ''))));
    $ownerLegal = [
        'legal_email' => (string) $req['email'],
        'legal_director' => trim((string) ($req['first_name'] ?? '') . ' ' . (string) ($req['last_name'] ?? '')),
    ];
    $ownerLegal['legal_kind'] = ($req['owner_kind'] ?? 'particulier') === 'professionnel' ? 'entreprise' : 'particulier';
    if (($req['owner_kind'] ?? 'particulier') === 'professionnel') {
        $ownerLegal['legal_company'] = (string) ($req['company_name'] ?? '');
        $ownerLegal['legal_siret'] = (string) ($req['siret'] ?? '');
    }
    create_tenant_from_form([
        'name' => $req['shop_name'], 'slug' => $slug, 'url' => saas_is_local_host() ? '' : $origin . '/' . $slug, 'template' => $tpl,
        'sales_enabled' => ($req['site_mode'] ?? saas_req_offer($req)['mode'] ?? 'sales') !== 'showcase',
        'cat' => ['Nos articles'], 'cat_icon' => ['ic-vase'],
        'item_limit' => (int) (saas_req_offer($req)['max_items'] ?? 0),   // limite d'articles de l'offre choisie (0 = sans limite)
        'admin_user' => $login, 'admin_password' => bin2hex(random_bytes(12)), // remplacé juste après par le mot de passe choisi
        'ai_shop' => 'la boutique en ligne de ' . $req['shop_name'] . (($req['about'] ?? '') !== '' ? ' (' . mb_substr((string) $req['about'], 0, 80) . ')' : ''),
        'ai_examples' => mb_substr((string) ($req['about'] ?? ''), 0, 200),
        // Textes de départ (la boutique n'a pas encore de contenu) : modifiables depuis son administration.
        'hero_eyebrow' => 'Nouvelle boutique', 'hero_title' => $req['shop_name'],
        'hero_sub' => ($req['about'] ?? '') !== '' ? mb_substr((string) $req['about'], 0, 160) : 'Bienvenue ! Découvrez nos pièces, choisies une à une.',
        'story_title' => 'Notre histoire',
        'story_text' => "Cette boutique vient d'ouvrir. Présentez ici qui vous êtes, d'où viennent vos pièces et ce qui les rend uniques. Vous pouvez modifier ce texte à tout moment depuis votre administration.",
        'pr0' => 'Des pièces choisies', 'pr0t' => 'Chaque pièce est sélectionnée avec soin.',
        'pr1' => 'Un envoi soigné', 'pr1t' => 'Vos achats sont emballés avec attention.',
        'pr2' => 'Un échange direct', 'pr2t' => 'Une question ? Écrivez-nous, nous répondons.',
        'delivery' => 'Retrait sur place ou envoi postal.',
        'address' => $ownerAddress,
    ] + $ownerLegal + $feed, null);
    if (!saas_set_admin_hash($slug, $login, (string) $req['password_hash'])) {
        throw new RuntimeException("La boutique est créée mais le compte du commerçant n'a pas pu être installé : utilisez « Nouvel accès » dans le portail.");
    }
    $galleryName = '';
    $gallerySlug = strtolower(trim($galleryOverride));
    if ($gallerySlug !== '' && ($g = gallery_load($gallerySlug))) {
        $alreadyMember = false;
        foreach ($g['members'] as $member) {
            if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $slug) {
                $alreadyMember = true;
                break;
            }
        }
        if (!$alreadyMember) {
            $g['members'][] = ['type' => 'local', 'slug' => $slug, 'featured' => false];
        }
        if (gallery_save($g)) {
            $galleryName = $g['name'];
        } else {
            error_log('saas_generate_shop: impossible d’ajouter ' . $slug . ' à la galerie ' . $gallerySlug);
        }
    }
    // Plugins installés d'office (catalogue des plugins → « installé d'office ») : médiathèque, tableau de bord.
    // Ceux qui se vendent à part reçoivent un accès offert, sans quoi leur page resterait fermée.
    require_once __DIR__ . '/plugins.php';
    require_once __DIR__ . '/addons.php';
    try {
        [$defaultPdo, $defaultDb] = plugin_open_tenant_db($slug);
        foreach (plugin_default_installs() as $defaultPluginId) {
            $defaultPlugin = plugin_manifest($defaultPluginId);
            if (!$defaultPlugin) continue;
            plugin_enable($defaultPdo, $defaultDb, $defaultPlugin);
            if (isset(addon_defaults()[$defaultPluginId])) {
                addon_save($defaultPluginId, ['status' => 'complimentary', 'billing_mode' => 'free',
                    'subscribed_at' => gmdate('c'), 'canceled_at' => ''], $defaultPdo);
            }
        }
        $defaultPdo = null;
    } catch (Throwable $e) {
        error_log('saas_generate_shop: plugins par défaut : ' . $e->getMessage());
    }
    // IA gratuite de l'offre choisie : le quota mensuel de la boutique (sans quota dans l'offre, l'IA reste illimitée).
    // Une boutique « sans IA » n'a aucun quota et l'IA y est coupée (portail → Fournisseurs d'IA, réactivable).
    $aiQuota = $noAi ? null : (saas_req_offer($req)['ai_quota_eur'] ?? null);
    if ($aiQuota !== null) saas_ai_policy_set($slug, (float) $aiQuota, 'offre');
    if ($noAi) {
        require_once __DIR__ . '/ai-providers.php';
        ai_provider_update(static function (array $settings) use ($slug): array {
            $settings['shops'][$slug] = false;
            return $settings;
        });
    }
    @unlink(saas_data_dir() . '/saas/cache.json');
    $shopUrl = rtrim(gallery_local_shop_url($slug, tenant_load($slug), $origin), '/') . '/';
    $adminUrl = $shopUrl . 'admin/';
    $cfg = saas_config();
    $mailed = saas_mail((string) $req['email'], 'Votre boutique est prête — ' . $cfg['name'],
        "Bonjour {$req['first_name']},\n\nVotre boutique « {$req['shop_name']} » est créée.\n\nVotre boutique : $shopUrl\nVotre administration : $adminUrl\n"
        . "Identifiant : $login\n"
        . (isset($req['password_set']) && !$req['password_set'] && !empty($req['social'])
            ? 'Connexion : bouton « Continuer avec ' . (OAUTH_PROVIDERS[$req['social']['provider']]['label'] ?? 'votre compte') . " » sur la page de connexion.\n"
            : "Mot de passe : celui que vous avez choisi à l'inscription.\n")
        . "\nÀ très vite,\n" . $cfg['name'] . "\n");
    return ['slug' => $slug, 'login' => $login, 'shop_url' => $shopUrl, 'admin_url' => $adminUrl, 'gallery_name' => $galleryName,
        'gallery' => $gallerySlug !== '' ? $gallerySlug : strtolower(trim((string) ($req['gallery'] ?? ''))), 'mailed' => $mailed];
}

// ── Paiement des formules (Stripe) ──────────────────────────────────────────
// Le paiement des formules passe par le compte Stripe de la PLATEFORME (clé dans .secrets/stripe_plateforme.json, réglée depuis le
// portail → Offres et annuaire) ; il n'a aucun rapport avec les clés Stripe des commerces, qui encaissent leurs propres clients.
// Appels REST sans SDK (comme includes/functions.php).

function saas_stripe_file(): string
{
    return defined('SAAS_STRIPE_FILE') ? (string) SAAS_STRIPE_FILE : dirname(__DIR__) . '/.secrets/stripe_plateforme.json';
}

/** Contenu brut du fichier de clés de la plateforme (ancienne ou nouvelle disposition). */
function saas_stripe_data(): array
{
    $file = saas_stripe_file();
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

function saas_stripe_data_save(array $data): bool
{
    $file = saas_stripe_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if (@file_put_contents($file, json_encode($data)) === false) return false;
    @chmod($file, 0600);
    return true;
}

/**
 * Mode actif de la plateforme : mode enregistré, sinon préfixe de l'ancienne
 * clé unique, sinon test. Les clés des deux modes vivent dans le même fichier
 * (« test » et « live ») ; la paire active est aussi recopiée dans les champs
 * historiques secret_key / webhook_secret pour les outils qui les lisent.
 */
function saas_stripe_mode(?array $data = null): string
{
    $data ??= saas_stripe_data();
    $mode = (string) ($data['mode'] ?? '');
    if (isset(STRIPE_MODES[$mode])) return $mode;
    $mode = stripe_key_mode((string) ($data['secret_key'] ?? ''));
    return $mode !== '' ? $mode : 'test';
}

/** Clés enregistrées pour un mode de la plateforme. */
function saas_stripe_mode_keys(string $mode, ?array $data = null): array
{
    $data ??= saas_stripe_data();
    $entry = is_array($data[$mode] ?? null) ? $data[$mode] : [];
    $keys = [
        'secret_key' => (string) ($entry['secret_key'] ?? ''),
        'webhook_secret' => (string) ($entry['webhook_secret'] ?? ''),
    ];
    // Ancienne disposition : une seule paire, rattachée au mode de son préfixe.
    if (empty($data['test']) && empty($data['live']) && saas_stripe_mode($data) === $mode) {
        if ($keys['secret_key'] === '') $keys['secret_key'] = (string) ($data['secret_key'] ?? '');
        if ($keys['webhook_secret'] === '') $keys['webhook_secret'] = (string) ($data['webhook_secret'] ?? '');
    }
    return $keys;
}

/** ['secret_key' => sk_…, 'webhook_secret' => whsec_… | '', 'api_base' => …, 'mode' => test|live]. */
function saas_stripe_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $d = saas_stripe_data();
    $mode = saas_stripe_mode($d);
    $keys = saas_stripe_mode_keys($mode, $d);
    $base = 'https://api.stripe.com/v1';
    // Adresse de l'API de substitution (faux Stripe de test) : acceptée seulement sur la machine de développement.
    if (is_file(dirname(__DIR__) . '/config.local.php') && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/v1$#', (string) ($d['api_base'] ?? ''))) $base = (string) $d['api_base'];
    return $cfg = [
        'secret_key' => preg_match('/^(?:sk|rk)_(test|live)_[A-Za-z0-9]+$/', (string) $keys['secret_key']) ? (string) $keys['secret_key'] : '',
        'webhook_secret' => preg_match('/^whsec_[A-Za-z0-9]+$/', (string) $keys['webhook_secret']) ? (string) $keys['webhook_secret'] : '',
        'api_base' => $base,
        'mode' => $mode,
    ];
}

function saas_stripe_configured(): bool
{
    return saas_stripe_config()['secret_key'] !== '';
}

function saas_stripe_live(): bool
{
    return saas_stripe_config()['mode'] === 'live';
}

/** Enregistre les clés d'un mode ; $activate bascule la plateforme sur ce mode. */
function saas_stripe_save_mode(string $mode, string $secret, string $webhook, bool $activate = false): bool
{
    if (!isset(STRIPE_MODES[$mode])) return false;
    $data = saas_stripe_data();
    // Ancienne disposition (une seule paire en haut du fichier) : la ranger
    // dans son mode avant d'ajouter quoi que ce soit, sinon l'enregistrement
    // d'un autre mode ferait disparaître la paire active.
    if (empty($data['test']) && empty($data['live'])) {
        $legacy = stripe_key_mode((string) ($data['secret_key'] ?? ''));
        if ($legacy === '') $legacy = 'test';
        $data[$legacy] = [
            'secret_key' => (string) ($data['secret_key'] ?? ''),
            'webhook_secret' => (string) ($data['webhook_secret'] ?? ''),
        ];
        if (!isset(STRIPE_MODES[(string) ($data['mode'] ?? '')])) $data['mode'] = $legacy;
    }
    $entry = is_array($data[$mode] ?? null) ? $data[$mode] : [];
    if ($secret !== '') $entry['secret_key'] = $secret;
    if ($webhook !== '') $entry['webhook_secret'] = $webhook;
    $data[$mode] = $entry;

    $current = $activate ? $mode : saas_stripe_mode($data);
    // Aucune clé dans le mode actif : la paire qu'on vient d'enregistrer devient active.
    if (saas_stripe_mode_keys($current, $data)['secret_key'] === '') $current = $mode;
    $data['mode'] = $current;
    $active = saas_stripe_mode_keys($current, $data);
    $data['secret_key'] = (string) $active['secret_key'];
    $data['webhook_secret'] = (string) $active['webhook_secret'];
    return saas_stripe_data_save($data);
}

/** Enregistre dans le mode actif puis l'active (comportement historique du formulaire). */
function saas_stripe_save(string $secret, string $webhook): bool
{
    return saas_stripe_save_mode(saas_stripe_mode(), $secret, $webhook, true);
}

/** Bascule la plateforme sur un mode. Retourne [bool, message]. */
function saas_stripe_mode_set(string $mode): array
{
    if (!isset(STRIPE_MODES[$mode])) return [false, 'Mode Stripe inconnu.'];
    $data = saas_stripe_data();
    $keys = saas_stripe_mode_keys($mode, $data);
    if ($keys['secret_key'] === '' || !preg_match('/^(?:sk|rk)_' . $mode . '_[A-Za-z0-9]+$/', $keys['secret_key'])) {
        return [false, 'Aucune clé secrète valide pour le mode ' . STRIPE_MODES[$mode] . ' : enregistrez-la d\'abord.'];
    }
    $data['mode'] = $mode;
    $data['secret_key'] = $keys['secret_key'];
    $data['webhook_secret'] = $keys['webhook_secret'];
    return saas_stripe_data_save($data)
        ? [true, 'Stripe de la plateforme : mode ' . STRIPE_MODES[$mode] . '.']
        : [false, 'Impossible d\'écrire le fichier des clés (droits du dossier .secrets).'];
}

/** État des deux modes de la plateforme pour l'affichage. */
function saas_stripe_modes_status(): array
{
    $data = saas_stripe_data();
    $modes = [];
    foreach (array_keys(STRIPE_MODES) as $mode) {
        $keys = saas_stripe_mode_keys($mode, $data);
        $valid = preg_match('/^(?:sk|rk)_' . $mode . '_[A-Za-z0-9]+$/', $keys['secret_key']) === 1;
        $modes[$mode] = [
            'configured' => $valid,
            'webhook' => preg_match('/^whsec_[A-Za-z0-9]+$/', $keys['webhook_secret']) === 1,
            'secret_mask' => $valid ? stripe_key_mask($keys['secret_key']) : '',
        ];
    }
    return ['mode' => saas_stripe_mode($data), 'modes' => $modes];
}

/** Secrets de webhook utilisables : mode actif puis l'autre mode (un paiement en attente peut arriver après la bascule). */
function saas_stripe_webhook_secrets(): array
{
    $data = saas_stripe_data();
    $mode = saas_stripe_mode($data);
    $out = [];
    foreach ([$mode, $mode === 'live' ? 'test' : 'live'] as $m) {
        $secret = (string) saas_stripe_mode_keys($m, $data)['webhook_secret'];
        if (preg_match('/^whsec_[A-Za-z0-9]+$/', $secret) && !in_array($secret, $out, true)) $out[] = $secret;
    }
    return $out;
}

/** Appel à l'API Stripe de la plateforme ; lève une exception avec le message de Stripe en cas d'erreur. */
function saas_stripe_request(string $method, string $path, array $params = []): array
{
    $c = saas_stripe_config();
    if ($c['secret_key'] === '') throw new RuntimeException('Stripe n\'est pas configuré pour la plateforme.');
    $ch = curl_init($c['api_base'] . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $c['secret_key'] . ':', CURLOPT_TIMEOUT => 20];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
    } elseif ($method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    } elseif ($params) {
        $opts[CURLOPT_URL] .= '?' . http_build_query($params, '', '&');
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('Erreur réseau Stripe : ' . curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($body, true) ?? [];
    if ($status >= 400) throw new RuntimeException((string) ($data['error']['message'] ?? 'Erreur Stripe HTTP ' . $status));
    return $data;
}

/** Prix mensuel d'une formule en centimes : 0 = gratuite ; null = prix libre (« sur devis »), jamais payé en ligne. */
function saas_plan_cents(array $plan): ?int
{
    return gallery_price_cents((string) ($plan['price'] ?? ''));
}

/** L'offre choisie par la demande se paie-t-elle en ligne ? (prix > 0 ET Stripe configuré) */
function saas_req_payable(array $req): bool
{
    return saas_stripe_configured() && (saas_req_offer($req)['cents'] ?? 0) > 0;
}

/** Description courte d'une offre figée, pour le journal : « Standard — annuel, 199 € / an, galerie x ». */
function saas_offer_note(?array $o, ?array $earlybird = null): string
{
    if (!$o) return '';
    return $o['name'] . ' — ' . SAAS_BILLING[$o['billing']][1] . ', ' . saas_price_text($o['cents'], $o['billing'])
        . ($o['max_items'] ? ', jusqu\'à ' . $o['max_items'] . ' articles' : '') . (($o['gallery'] ?? '') !== '' ? ', galerie ' . $o['gallery'] : '')
        . (!empty($earlybird['discount_pct']) ? ', ' . eb_lock_label($earlybird) : '');
}

/** Crée la session Stripe Checkout (abonnement mensuel ou annuel, selon l'offre figée) d'une demande ; retourne la session (id, url). */
function saas_stripe_checkout(array $req, string $origin): array
{
    $cfg = saas_config();
    $o = saas_req_offer($req);
    $yearly = $o['billing'] === 'year';
    $params = [
        'mode' => 'subscription', 'locale' => 'fr', 'client_reference_id' => $req['id'],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur', 'unit_amount' => (int) $o['cents'], 'recurring' => ['interval' => $yearly ? 'year' : 'month'],
                'product_data' => ['name' => $cfg['name'] . ' — ' . ($o['model'] === 'tier' ? '' : 'formule ') . $o['name'] . (($o['gallery'] ?? '') !== '' ? ' (galerie ' . $o['gallery'] . ')' : ''),
                    'description' => 'Abonnement ' . ($yearly ? 'annuel' : 'mensuel') . ' à votre boutique en ligne' . ($o['max_items'] ? ' — jusqu\'à ' . $o['max_items'] . ' articles' : '') . (!empty($req['earlybird']['discount_pct']) ? ' — ' . eb_lock_label($req['earlybird']) : '')],
            ],
        ]],
        'metadata' => ['request_id' => $req['id'], 'plan' => $o['key'], 'billing' => $o['billing'], 'gallery' => (string) ($o['gallery'] ?? ''), 'site_mode' => (string) ($o['mode'] ?? 'sales')],
        'subscription_data' => ['metadata' => ['request_id' => $req['id'], 'site_mode' => (string) ($o['mode'] ?? 'sales')]],
        'success_url' => $origin . '/inscription/merci.php?r=' . $req['id'] . '&s={CHECKOUT_SESSION_ID}',
        'cancel_url' => $origin . '/inscription/?annule=' . $req['id'],
    ];
    if (filter_var((string) ($req['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $params['customer_email'] = (string) $req['email'];
    return saas_stripe_request('POST', '/checkout/sessions', $params);
}

/** Vérifie la signature d'un webhook Stripe (en-tête Stripe-Signature, tolérance 5 minutes). */
function saas_stripe_signature_valid(string $payload, string $header, string $secret): bool
{
    $time = 0;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $time = (int) $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if ($time === 0 || abs(time() - $time) > 300) return false;
    $expected = hash_hmac('sha256', $time . '.' . $payload, $secret);
    foreach ($sigs as $sig) if (hash_equals($expected, $sig)) return true;
    return false;
}

/**
 * Enregistre le paiement d'une demande à partir d'une session Stripe Checkout (page de retour OU webhook ; idempotent, protégé par un verrou) :
 * si la session est payée, la demande passe « paid » et le client reçoit par e-mail le lien de création de sa boutique (étape 3).
 * Retourne ['state' => 'paid' | 'already' | 'unpaid' | 'error', 'request' => …].
 */
function saas_mark_paid(string $reqId, array $session, string $origin): array
{
    $dir = saas_requests_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['state' => 'error', 'message' => 'Dossier des demandes inaccessible.'];
    $lock = fopen($dir . '/' . preg_replace('/[^a-f0-9]/', '', $reqId) . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $req = saas_request_load($reqId);
        if (!$req) return ['state' => 'error', 'message' => 'Demande introuvable.'];
        if (($session['client_reference_id'] ?? '') !== $reqId || (($req['payment']['session'] ?? '') !== ($session['id'] ?? '-'))) {
            return ['state' => 'error', 'message' => 'Cette session de paiement ne correspond pas à la demande.'];
        }
        if (in_array($req['status'] ?? '', ['paid', 'pending', 'approved'], true)) return ['state' => 'already', 'request' => $req];
        if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'request' => $req];
        $email = strtolower(trim((string) (($session['customer_details']['email'] ?? '') ?: ($session['customer_email'] ?? '') ?: ($req['email'] ?? ''))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return ['state' => 'error', 'message' => "Le paiement est confirmé, mais Stripe n'a pas transmis d'adresse e-mail valide. Contactez l'assistance pour finaliser la boutique."];
        }

        $req['email'] = $email;
        $req['status'] = 'paid';
        $req['payment'] = array_merge($req['payment'] ?? [], [
            'state' => 'paid', 'paid_at' => time(), 'customer' => (string) ($session['customer'] ?? ''),
            'subscription' => (string) ($session['subscription'] ?? ''), 'amount' => (int) ($session['amount_total'] ?? 0),
        ]);
        if (!saas_request_save($req)) return ['state' => 'error', 'message' => "Paiement confirmé, mais impossible d'enregistrer la demande. Réessayez ou contactez l'assistance."];
        saas_event('payment_confirmed', ['ref' => $req['id'], 'email' => $req['email'], 'plan' => $req['plan'], 'amount' => (int) ($session['amount_total'] ?? 0), 'actor' => 'stripe']);
        $cfg = saas_config();
        $planName = saas_req_offer($req)['name'];
        saas_mail($req['email'], 'Paiement reçu : créez votre boutique — ' . $cfg['name'],
            "Bonjour,\n\nNous avons bien reçu votre paiement (formule $planName). Il ne reste qu'à créer votre boutique : choisissez son nom, son design et votre mot de passe.\n\n"
            . saas_creation_url($req, $origin) . "\n\nCe lien est personnel. Merci !\n" . $cfg['name'] . "\n");
        if ($cfg['contact'] !== '') saas_mail($cfg['contact'], 'Paiement reçu : ' . $req['email'] . ' (' . $planName . ')', "Un paiement vient d'être confirmé. Le client va créer sa boutique.\n\n$origin/portail/inscriptions.php\n");
        return ['state' => 'paid', 'request' => $req];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Identifiant d'administration d'une demande : l'e-mail s'il est accepté par l'administration, sinon l'adresse de la boutique. */
function saas_admin_login(array $req): string
{
    $email = strtolower((string) $req['email']);
    return preg_match('/^[a-z0-9._@-]{3,40}$/', $email) ? $email : (string) $req['slug'];
}

/**
 * Remplace le mot de passe d'administration de tenants/<slug>/tenant.php par un HACHÉ déjà calculé (celui choisi à l'inscription) :
 * le mot de passe en clair n'est jamais conservé. Retourne vrai si le fichier relu contient bien ce hachage.
 */
function saas_set_admin_hash(string $slug, string $login, string $hash): bool
{
    $file = dirname(__DIR__) . "/tenants/$slug/tenant.php";
    $php = (string) @file_get_contents($file);
    $value = "(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
    $php = preg_replace("/^(\\s*)'admin_user'\\s*=>\\s*$value\\s*,/m", '$1' . str_replace('$', '\\$', "'admin_user' => " . var_export($login, true) . ','), $php, 1, $u);
    $php = preg_replace("/^(\\s*)'admin_password'\\s*=>\\s*$value\\s*,/m", '$1' . str_replace('$', '\\$', "'admin_password' => " . var_export($hash, true) . ','), $php, 1, $p);
    if (!$u || !$p) return false;
    $tmp = "$file.tmp";
    file_put_contents($tmp, $php);
    rename($tmp, $file);
    if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
    $saved = tenant_load($slug);
    return $saved['admin_user'] === $login && $saved['admin_password'] === $hash;
}

/**
 * Message e-mail simple (UTF-8), avec un pixel de suivi d'ouverture.
 * Silencieux en cas d'échec : l'inscription ne dépend pas de l'envoi.
 * $source et $context rangent l'envoi dans le suivi (voir includes/mail-tracking.php).
 */
function saas_mail(string $to, string $subject, string $body, string $source = 'plateforme', string $context = ''): bool
{
    require_once __DIR__ . '/mail-tracking.php';
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP);
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    return mail_open_send($to, $subject, $body, $source, [
        'from' => 'From: ' . saas_config()['name'] . " <$from>",
        'log' => __DIR__ . '/../data/mail-outbox.log',
        'local' => $local,
        'context' => $context,
    ]);
}

const SAAS_DOMAIN_FOLLOWUP_DELAY = 7 * 86400;
const SAAS_DOMAIN_FOLLOWUP_MAX = 3;

/** Envoie une relance manuelle de domaine après consentement, avec un délai de sept jours entre les messages. */
function saas_domain_followup_remind(string $requestId): ?string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $requestId)) return 'Identifiant de demande invalide.';
    $lock = fopen(saas_requests_dir() . '/' . $requestId . '.domain-reminder.lock', 'c');
    if ($lock === false) return 'Impossible de verrouiller la relance.';
    try {
        if (!flock($lock, LOCK_EX)) return 'Impossible de verrouiller la relance.';
        $req = saas_request_load($requestId);
        if (!$req) return 'Demande introuvable.';
        $followup = (array) ($req['branding']['domain_followup'] ?? []);
        if (($followup['status'] ?? '') !== 'pending' || empty($followup['consented_at'])) return 'Aucun accord de relance valide pour cette demande.';
        if (in_array(($req['status'] ?? ''), ['cancelled', 'rejected'], true)) return 'Cette demande est fermée.';
        $email = strtolower(trim((string) ($req['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Adresse e-mail invalide.';

        $reminders = array_values(array_filter((array) ($followup['reminders'] ?? []), static fn ($at): bool => is_numeric($at)));
        $last = $reminders ? max(array_map('intval', $reminders)) : (int) ($followup['requested_at'] ?? 0);
        if (count($reminders) >= SAAS_DOMAIN_FOLLOWUP_MAX) return 'Le nombre maximal de relances a été atteint.';
        if ($last <= 0 || time() - $last < SAAS_DOMAIN_FOLLOWUP_DELAY) return 'La prochaine relance sera possible après sept jours.';

        $origin = saas_origin();
        $status = (string) ($req['status'] ?? '');
        if ($status === 'paid') {
            $actionUrl = saas_creation_url($req, $origin);
        } elseif ($status === 'approved') {
            $actionUrl = $origin . '/inscription/identite.php?r=' . rawurlencode((string) $req['id']) . '&k=' . rawurlencode((string) $req['token']);
        } else {
            $actionUrl = $origin . '/inscription/';
        }

        $lines = [];
        foreach ((array) ($followup['suggestions'] ?? []) as $suggestion) {
            if (is_string($suggestion) && trim($suggestion) !== '') $lines[] = '- ' . trim($suggestion);
        }
        foreach ((array) ($followup['domains'] ?? []) as $domain) {
            if (!is_array($domain) || empty($domain['available']) || empty($domain['domain'])) continue;
            $price = isset($domain['price_cents']) ? ' — ' . saas_money((int) $domain['price_cents']) . ' TTC/an (tarif à revérifier)' : '';
            $lines[] = '- ' . (string) $domain['domain'] . $price;
        }
        $options = $lines ? "\n\nLes idées et domaines enregistrés lors de votre inscription :\n" . implode("\n", array_slice($lines, 0, 20)) : '';
        $stopUrl = $origin . '/inscription/relance-domaine.php?r=' . rawurlencode((string) $req['id']) . '&k=' . rawurlencode((string) $req['token']);
        $body = "Bonjour,\n\nVous aviez choisi de réfléchir plus tard au nom de domaine de votre boutique."
            . $options . "\n\nVous pouvez reprendre votre projet ici :\n$actionUrl\n\n"
            . "Cette relance fait suite à votre accord. Pour ne plus recevoir de relance à ce sujet, ouvrez ce lien :\n$stopUrl\n";

        $reminders[] = time();
        $followup['reminders'] = $reminders;
        $req['branding']['domain_followup'] = $followup;
        if (!saas_request_save($req)) return "Impossible d'enregistrer l'envoi avant relance.";
        if (!saas_mail($email, 'Reprendre le choix du nom de domaine — ' . saas_config()['name'], $body)) {
            array_pop($reminders);
            $followup['reminders'] = $reminders;
            $req['branding']['domain_followup'] = $followup;
            if (!saas_request_save($req)) error_log('saas_domain_followup_remind: impossible de restaurer le compteur après échec e-mail');
            return "L'e-mail de relance n'a pas pu être envoyé.";
        }
        saas_event('domain_followup_reminded', [
            'ref' => (string) $req['id'],
            'email' => $email,
            'plan' => (string) ($req['plan'] ?? ''),
            'note' => 'Relance domaine ' . count($reminders) . '/' . SAAS_DOMAIN_FOLLOWUP_MAX,
            'actor' => 'portail',
        ]);
        return null;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ── Mise en page commune des pages publiques ────────────────────────────────

/** Début de page : <head>, en-tête et navigation. $active : accueil | galeries | annuaire | inscription. */
function saas_page_start(string $title, string $description, string $active = ''): void
{
    $cfg = saas_config();
    $accent = $cfg['accent'];
    $ink = saas_ink_on($accent);
    $nav = ['accueil' => ['/', 'Accueil'], 'galeries' => ['/galerie/', 'Galeries'], 'annuaire' => ['/annuaire/', 'Annuaire']];
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= saas_e($title) ?></title>
<meta name="description" content="<?= saas_e($description) ?>">
<meta property="og:title" content="<?= saas_e($title) ?>">
<meta property="og:description" content="<?= saas_e($description) ?>">
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/assets/saas.css">
<link rel="stylesheet" href="/assets/oauth.css">
<link rel="stylesheet" href="/assets/pricing.css">
<style>:root { --accent: <?= saas_e($accent) ?>; --accent-ink: <?= saas_e($ink) ?>; }</style>
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>
<header class="site-head"><div class="wrap bar">
  <a class="brand" href="/"><span class="mark" aria-hidden="true"><?= saas_e(mb_strtoupper(mb_substr($cfg['name'], 0, 1))) ?></span><?= saas_e($cfg['name']) ?></a>
  <nav aria-label="Navigation principale">
    <?php foreach ($nav as $k => [$href, $label]): ?><a href="<?= $href ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?>
    <a href="/#connexion">Se connecter</a>
    <a class="cta" href="/inscription/"<?= $active === 'inscription' ? ' aria-current="page"' : '' ?><?= cta_attrs('site_header_cta') ?>><?= saas_e(cta_label('site_header_cta')) ?></a>
  </nav>
</div></header>
<main id="contenu">
<?php
}

function saas_page_end(): void
{
    $cfg = saas_config();
    ?>
</main>
<footer class="site-foot"><div class="wrap foot">
  <div><strong><?= saas_e($cfg['name']) ?></strong><br><span><?= saas_e($cfg['tagline']) ?></span></div>
  <nav aria-label="Pied de page"><a href="/">Accueil</a><a href="/vide-greniers/">Organiser un vide-grenier</a><a href="/marche-aux-puces/">Gérer un marché aux puces</a><a href="/galerie-marchande/">Créer une galerie marchande</a><a href="/quartier-marchand/">Créer un quartier marchand</a><a href="/marche-de-noel/">Organiser un marché de Noël</a><a href="/marche-producteurs/">Créer un marché de producteurs</a><a href="/galerie/">Galeries</a><a href="/annuaire/">Annuaire</a><a href="/inscription/">Je veux vendre en ligne</a><a href="/dossier/">Dossier de subvention</a><a href="/aide/">Aide</a><a href="/accueil/conditions.php">Conditions d'utilisation</a><?php if ($cfg['contact'] !== ''): ?><a href="mailto:<?= saas_e($cfg['contact']) ?>">Contact</a><?php endif; ?></nav>
</div></footer>
<script src="/assets/pwd-toggle.js" defer></script>
<script src="/assets/cta-track.js?v=1" defer></script>
</body>
</html>
<?php
}

require_once __DIR__ . '/saas-pricing.php';
require_once __DIR__ . '/saas-events.php';
require_once __DIR__ . '/earlybird.php';
require_once __DIR__ . '/cta.php';
