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
require_once __DIR__ . '/templates.php';
require_once __DIR__ . '/tenant-factory.php';
require_once __DIR__ . '/oauth.php';

const SAAS_RESERVED_SLUGS = ['galerie', 'annuaire', 'inscription', 'accueil', 'portail', 'admin', 'assets', 'uploads', 'includes', 'data', 'tenants',
    'var', 'scripts', 'api', 'modal', 'static', 'www', 'mail', 'test', 'demo'];
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
        'plans' => $plans ?: saas_default_plans(),
        // Garde-fou : nombre maximal de boutiques GRATUITES créées par 24 h (0 = pas de plafond), puisqu'aucune validation humaine n'est exigée.
        'free_daily_cap' => max(0, min(1000, (int) ($saved['free_daily_cap'] ?? 10))),
        'hidden' => array_values(array_filter(array_map('strval', (array) ($saved['hidden'] ?? ['exemple-librairie'])))),
        'remote' => array_values(array_filter((array) ($saved['remote'] ?? []), static fn ($r) => is_array($r) && gallery_remote_url_valid((string) ($r['url'] ?? '')))),
    ];
    return $cfg;
}

function saas_config_save(array $cfg): bool
{
    $dir = saas_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
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
 * Une demande occupe-t-elle encore son adresse et son e-mail ? Non si refusée, ou si l'étape 1 (brouillon, paiement en attente) date de
 * plus de 24 h. Une demande PAYÉE ne s'expire jamais : de l'argent a été encaissé.
 */
function saas_request_active(array $r): bool
{
    $status = (string) ($r['status'] ?? '');
    if ($status === 'rejected') return false;
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

function saas_email_taken(string $email, string $exceptId = ''): bool
{
    foreach (saas_requests() as $r) {
        if (saas_request_active($r) && ($r['id'] ?? '') !== $exceptId && strcasecmp((string) ($r['email'] ?? ''), $email) === 0) return true;
    }
    foreach (tenant_list() as $t) {
        if (strcasecmp((string) ($t['admin_user'] ?? ''), $email) === 0) return true;
    }
    return false;
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
    $free = array_column(array_filter($cfg['plans'], static fn (array $p): bool => (saas_plan_cents($p) ?? 0) === 0), 'key');
    $n = 0;
    foreach (saas_requests() as $r) {
        if (($r['status'] ?? '') !== 'rejected' && time() - (int) ($r['created'] ?? 0) < 86400 && in_array($r['plan'] ?? '', $free, true)) $n++;
    }
    return $n >= $cap;
}

/**
 * ÉTAPE 1 — formule et e-mail. Crée une demande « draft » avec un jeton secret (lien de reprise) : le paiement (formule payante) puis la
 * création de la boutique (nom, adresse, design, compte) viennent ensuite. Retourne ['ok' => true, 'request' => …] ou ['ok' => false, 'errors' => …].
 */
function saas_request_start(array $in): array
{
    $err = [];
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $plan = (string) ($in['plan'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 60) $err['email'] = 'Indiquez une adresse e-mail valide.';
    elseif (saas_email_taken($email)) $err['email'] = 'Un compte ou une demande existe déjà avec cette adresse.';
    if (!isset(array_column(saas_config()['plans'], 'name', 'key')[$plan])) $err['plan'] = 'Choisissez une formule.';
    if (empty($in['terms'])) $err['terms'] = 'Acceptez les conditions pour continuer.';
    if ($err) return ['ok' => false, 'errors' => $err];

    $req = ['id' => bin2hex(random_bytes(8)), 'token' => bin2hex(random_bytes(16)), 'created' => time(), 'status' => 'draft', 'email' => $email, 'plan' => $plan,
        'shop_name' => '', 'slug' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'about' => '', 'gallery' => '', 'template' => SHOP_TEMPLATE_DEFAULT, 'payment' => []];
    return saas_request_save($req) ? ['ok' => true, 'request' => $req]
        : ['ok' => false, 'errors' => ['form' => "Impossible d'enregistrer la demande pour le moment : réessayez dans quelques minutes."]];
}

/**
 * Où envoyer le client après l'étape 1 (formulaire ou connexion sociale) : la page de paiement Stripe pour une formule payante ; l'étape 3
 * sinon, ou si Stripe répond par une erreur (la demande passe alors en « paiement manuel » : l'exploitant règle avec le client).
 */
function saas_payment_url(array $req, array $plan, string $origin): string
{
    try {
        $session = saas_stripe_checkout($req, $plan, $origin);
        if (empty($session['url']) || empty($session['id'])) throw new RuntimeException('Réponse inattendue de Stripe.');
        $req['status'] = 'awaiting_payment';
        $req['payment'] = ['state' => 'awaiting', 'session' => (string) $session['id'], 'started' => time()];
        saas_request_save($req);
        return (string) $session['url'];
    } catch (RuntimeException $e) {
        error_log('saas_payment_url: ' . $e->getMessage());
        $req['payment'] = ['state' => 'manual', 'note' => 'Paiement en ligne indisponible : ' . $e->getMessage()];
        saas_request_save($req);
        return saas_creation_url($req, $origin);
    }
}

/** Suite de l'étape 1 : paiement en ligne si la formule s'y prête, sinon étape 3 (avec la mention « paiement à régler » d'une formule payante). */
function saas_after_start_url(array $req, string $origin): string
{
    $plan = array_column(saas_config()['plans'], null, 'key')[$req['plan']];
    if (saas_plan_payable($plan)) return saas_payment_url($req, $plan, $origin);
    if ((saas_plan_cents($plan) ?? 0) > 0) {
        $req['payment'] = ['state' => 'manual', 'note' => 'Paiement en ligne non configuré : à régler avec le client.'];
        saas_request_save($req);
    }
    return saas_creation_url($req, $origin);
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
 * ÉTAPE 3 — détails de la boutique (après le paiement, ou tout de suite pour une formule gratuite). Valide et enregistre dans la demande
 * (le mot de passe seulement haché). Retourne ['ok' => true, 'request' => …] ou ['ok' => false, 'errors' => …].
 */
function saas_request_complete(array $req, array $in): array
{
    $err = [];
    $shopName = mb_substr(trim((string) ($in['shop_name'] ?? '')), 0, 60);
    $slug = strtolower(trim((string) ($in['slug'] ?? ''))) ?: ($shopName !== '' ? saas_slug($shopName) : '');
    $first = mb_substr(trim((string) ($in['first_name'] ?? '')), 0, 60);
    $last = mb_substr(trim((string) ($in['last_name'] ?? '')), 0, 60);
    $phone = mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30);
    $password = (string) ($in['password'] ?? '');
    $about = mb_substr(trim((string) ($in['about'] ?? '')), 0, 400);
    $gallery = strtolower(trim((string) ($in['gallery'] ?? '')));
    $template = (string) ($in['template'] ?? SHOP_TEMPLATE_DEFAULT);

    if (!shop_template_valid($template)) $err['template'] = 'Choisissez un modèle de design.';
    if ($shopName === '') $err['shop_name'] = 'Donnez un nom à votre boutique.';
    elseif (!preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $slug)) $err['slug'] = 'Adresse : 3 à 40 caractères (lettres minuscules, chiffres, tirets).';
    elseif (saas_slug_taken($slug, (string) $req['id'])) $err['slug'] = 'Cette adresse est déjà prise : choisissez-en une autre.';
    if ($first === '') $err['first_name'] = 'Indiquez votre prénom.';
    $generated = false;
    $social = !empty($req['social']);   // inscrit avec Google / Facebook… : le mot de passe est facultatif (la connexion se fait par le réseau social)
    if ($social && $password === '' && (string) ($in['password2'] ?? '') === '') { $password = bin2hex(random_bytes(16)); $generated = true; }
    if (mb_strlen($password) < 8) $err['password'] = 'Choisissez un mot de passe d\'au moins 8 caractères.';
    elseif (!$generated && $password !== (string) ($in['password2'] ?? '')) $err['password2'] = 'Les deux mots de passe ne sont pas identiques.';
    if ($gallery !== '') {
        $g = gallery_load($gallery);
        if (!$g || !$g['published']) $gallery = '';
    }
    if ($err) return ['ok' => false, 'errors' => $err];

    $req = array_merge($req, ['shop_name' => $shopName, 'slug' => $slug, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_set' => !$generated, 'about' => $about, 'gallery' => $gallery, 'template' => $template, 'details_at' => time()]);
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
 * l'ajoute à la galerie demandée, marque la demande « approved » et écrit au commerçant. Lève une exception si l'adresse est prise
 * ou si la création échoue. Retourne ['slug', 'login', 'shop_url', 'admin_url', 'gallery_name', 'mailed'].
 */
function saas_generate_shop(array $req, string $origin, string $galleryOverride = ''): array
{
    $slug = (string) $req['slug'];
    if (is_dir(dirname(__DIR__) . "/tenants/$slug") || file_exists(dirname(__DIR__) . '/' . $slug) || in_array($slug, SAAS_RESERVED_SLUGS, true)) {
        throw new InvalidArgumentException("L'adresse « $slug » est déjà prise.");
    }
    $login = saas_admin_login($req);
    $tpl = shop_template_valid($req['template'] ?? null) ? (string) $req['template'] : SHOP_TEMPLATE_DEFAULT;
    create_tenant_from_form([
        'name' => $req['shop_name'], 'slug' => $slug, 'url' => saas_is_local_host() ? '' : $origin . '/' . $slug, 'template' => $tpl,
        'cat' => ['Nos articles'], 'cat_icon' => ['ic-vase'],
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
    ], null);
    if (!saas_set_admin_hash($slug, $login, (string) $req['password_hash'])) {
        throw new RuntimeException("La boutique est créée mais le compte du commerçant n'a pas pu être installé : utilisez « Nouvel accès » dans le portail.");
    }
    $galleryName = '';
    $gallerySlug = strtolower(trim($galleryOverride !== '' ? $galleryOverride : (string) ($req['gallery'] ?? '')));
    if ($gallerySlug !== '' && ($g = gallery_load($gallerySlug))) {
        $g['members'][] = ['type' => 'local', 'slug' => $slug, 'featured' => false];
        gallery_save($g);
        $galleryName = $g['name'];
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
    return ['slug' => $slug, 'login' => $login, 'shop_url' => $shopUrl, 'admin_url' => $adminUrl, 'gallery_name' => $galleryName, 'gallery' => $gallerySlug, 'mailed' => $mailed];
}

// ── Paiement des formules (Stripe) ──────────────────────────────────────────
// Le paiement des formules passe par le compte Stripe de la PLATEFORME (clé dans .secrets/stripe_plateforme.json, réglée depuis le
// portail → Offres et annuaire) ; il n'a aucun rapport avec les clés Stripe des commerces, qui encaissent leurs propres clients.
// Appels REST sans SDK (comme includes/functions.php).

function saas_stripe_file(): string
{
    return dirname(__DIR__) . '/.secrets/stripe_plateforme.json';
}

/** ['secret_key' => sk_…, 'webhook_secret' => whsec_… | '', 'api_base' => …]. */
function saas_stripe_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = saas_stripe_file();
    $d = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $base = 'https://api.stripe.com/v1';
    // Adresse de l'API de substitution (faux Stripe de test) : acceptée seulement sur la machine de développement.
    if (is_file(dirname(__DIR__) . '/config.local.php') && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/v1$#', (string) ($d['api_base'] ?? ''))) $base = (string) $d['api_base'];
    return $cfg = [
        'secret_key' => preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', (string) ($d['secret_key'] ?? '')) ? (string) $d['secret_key'] : '',
        'webhook_secret' => preg_match('/^whsec_[A-Za-z0-9]+$/', (string) ($d['webhook_secret'] ?? '')) ? (string) $d['webhook_secret'] : '',
        'api_base' => $base,
    ];
}

function saas_stripe_configured(): bool
{
    return saas_stripe_config()['secret_key'] !== '';
}

function saas_stripe_live(): bool
{
    return str_starts_with(saas_stripe_config()['secret_key'], 'sk_live_');
}

function saas_stripe_save(string $secret, string $webhook): bool
{
    $dir = dirname(saas_stripe_file());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $old = is_file(saas_stripe_file()) ? (json_decode((string) file_get_contents(saas_stripe_file()), true) ?: []) : [];
    $data = ['secret_key' => $secret !== '' ? $secret : ($old['secret_key'] ?? ''), 'webhook_secret' => $webhook !== '' ? $webhook : ($old['webhook_secret'] ?? '')];
    if (!empty($old['api_base'])) $data['api_base'] = $old['api_base'];
    if (@file_put_contents(saas_stripe_file(), json_encode($data)) === false) return false;
    @chmod(saas_stripe_file(), 0600);
    return true;
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

/** La formule de la demande se paie-t-elle en ligne ? (prix > 0 ET Stripe configuré) */
function saas_plan_payable(array $plan): bool
{
    return saas_stripe_configured() && (saas_plan_cents($plan) ?? 0) > 0;
}

/** Crée la session Stripe Checkout (abonnement mensuel) d'une demande ; retourne la session (id, url). */
function saas_stripe_checkout(array $req, array $plan, string $origin): array
{
    $cfg = saas_config();
    return saas_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'subscription', 'locale' => 'fr', 'customer_email' => $req['email'], 'client_reference_id' => $req['id'],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur', 'unit_amount' => saas_plan_cents($plan), 'recurring' => ['interval' => 'month'],
                'product_data' => ['name' => $cfg['name'] . ' — formule ' . $plan['name'], 'description' => 'Abonnement mensuel à votre boutique en ligne'],
            ],
        ]],
        'metadata' => ['request_id' => $req['id'], 'plan' => $plan['key']],
        'subscription_data' => ['metadata' => ['request_id' => $req['id']]],
        'success_url' => $origin . '/inscription/merci.php?r=' . $req['id'] . '&s={CHECKOUT_SESSION_ID}',
        'cancel_url' => $origin . '/inscription/?annule=' . $req['id'],
    ]);
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

        $req['status'] = 'paid';
        $req['payment'] = array_merge($req['payment'] ?? [], [
            'state' => 'paid', 'paid_at' => time(), 'customer' => (string) ($session['customer'] ?? ''),
            'subscription' => (string) ($session['subscription'] ?? ''), 'amount' => (int) ($session['amount_total'] ?? 0),
        ]);
        saas_request_save($req);
        $cfg = saas_config();
        $planName = array_column($cfg['plans'], 'name', 'key')[$req['plan']] ?? $req['plan'];
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

/** Message e-mail simple (UTF-8). Silencieux en cas d'échec : l'inscription ne dépend pas de l'envoi. */
function saas_mail(string $to, string $subject, string $body): bool
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $from = 'noreply@' . (str_contains($host, '.') && !str_ends_with($host, '.test') ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    $headers = "From: " . saas_config()['name'] . " <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    return function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/saas.css">
<link rel="stylesheet" href="/assets/oauth.css">
<style>:root { --accent: <?= saas_e($accent) ?>; --accent-ink: <?= saas_e($ink) ?>; }</style>
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>
<header class="site-head"><div class="wrap bar">
  <a class="brand" href="/"><span class="mark" aria-hidden="true"><?= saas_e(mb_strtoupper(mb_substr($cfg['name'], 0, 1))) ?></span><?= saas_e($cfg['name']) ?></a>
  <nav aria-label="Navigation principale">
    <?php foreach ($nav as $k => [$href, $label]): ?><a href="<?= $href ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?>
    <a class="cta" href="/inscription/"<?= $active === 'inscription' ? ' aria-current="page"' : '' ?>>Créer ma boutique</a>
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
  <nav aria-label="Pied de page"><a href="/">Accueil</a><a href="/galerie/">Galeries</a><a href="/annuaire/">Annuaire</a><a href="/inscription/">Créer ma boutique</a><?php if ($cfg['contact'] !== ''): ?><a href="mailto:<?= saas_e($cfg['contact']) ?>">Contact</a><?php endif; ?></nav>
</div></footer>
</body>
</html>
<?php
}
