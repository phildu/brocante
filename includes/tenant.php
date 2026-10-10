<?php

// Marque blanche : tout ce qui est propre à un commerce (nom, logo, couleurs,
// catégories, textes, prompts IA, base, URL…) vit dans tenants/<slug>/tenant.php.
//
// Le commerce actif est, par ordre de priorité :
//   1. la variable d'environnement TENANT (session cloud, SetEnv Apache) ;
//   2. le sous-domaine, s'il porte le nom d'un commerce : naty.brocenstock.test
//      affiche tenants/naty (tests locaux avec Herd) ;
//   3. le fichier .tenant à la racine (écrit par le script de déploiement) ;
//   4. petit-chalet (comportement historique).

const TENANT_DEFAULT = 'petit-chalet';

/**
 * Commerce désigné par le sous-domaine de l'hôte (« naty » pour
 * naty.brocenstock.test), ou '' si le premier segment n'est pas un commerce.
 */
function tenant_from_host(?string $host = null): string
{
    $host = strtolower(preg_replace('/:\d+$/', '', $host ?? ($_SERVER['HTTP_HOST'] ?? '')));
    $labels = explode('.', $host);
    if (count($labels) < 3) {
        return '';
    }
    $candidate = $labels[0];
    return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $candidate) && is_file(tenant_dir($candidate) . '/tenant.php')
        ? $candidate
        : '';
}

/** Domaine sans le sous-domaine de commerce (brocenstock.test pour naty.brocenstock.test). */
function tenant_base_host(?string $host = null): string
{
    $host = strtolower($host ?? ($_SERVER['HTTP_HOST'] ?? ''));
    return tenant_from_host($host) !== '' ? substr($host, strpos($host, '.') + 1) : $host;
}

/**
 * Commerce désigné par le premier segment de l'adresse (« naty » pour
 * brocs.arrimage.com/naty/boutique.php), ou '' si ce segment n'est pas un
 * commerce. Un dossier ou fichier de même nom à la racine (admin/, assets/,
 * portail/…) a toujours priorité : il ne peut pas être masqué par un commerce.
 */
function tenant_from_path(?string $uri = null): string
{
    $path = (string) parse_url($uri ?? ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!preg_match('#^/([a-z0-9][a-z0-9_-]*)(?:/|$)#', $path, $m)) {
        return '';
    }
    return is_file(tenant_dir($m[1]) . '/tenant.php') && !file_exists(dirname(__DIR__) . '/' . $m[1])
        ? $m[1]
        : '';
}

/**
 * Commerce actif et préfixe d'adresse éventuel : ['slug' => …, 'base' => '' ou '/slug'].
 * Le préfixe n'existe que pour un commerce servi sous le domaine d'un autre
 * (brocs.arrimage.com/<slug>/) ; un commerce choisi par sous-domaine, par
 * variable d'environnement ou par .tenant garde des adresses sans préfixe.
 */
function tenant_resolve(): array
{
    static $resolved = null;
    if ($resolved === null) {
        $base = '';
        $slug = getenv('TENANT') ?: tenant_from_host();
        if ($slug === '') {
            $slug = tenant_from_path();
            $base = $slug !== '' ? '/' . $slug : '';
        }
        $file = __DIR__ . '/../.tenant';
        if ($slug === '' && is_file($file)) {
            $slug = trim((string) file_get_contents($file));
        }
        $slug = $slug ?: TENANT_DEFAULT;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new RuntimeException('Nom de commerce invalide : ' . $slug);
        }
        $resolved = ['slug' => $slug, 'base' => $base];
    }
    return $resolved;
}

function tenant_slug(): string
{
    return tenant_resolve()['slug'];
}

/** Préfixe des adresses du commerce actif : '' ou '/<slug>' (jamais de barre finale). */
function tenant_base_path(): string
{
    return tenant_resolve()['base'];
}

/**
 * Pages dynamiques de la boutique (racine du site, *.php de la racine,
 * admin/) : seules adresses à préfixer. assets/ et uploads/ sont partagés
 * entre commerces et restent à la racine du domaine.
 */
function tenant_dynamic_path_regex(): string
{
    static $regex = null;
    if ($regex === null) {
        $pages = [];
        foreach (glob(dirname(__DIR__) . '/*.php') ?: [] as $file) {
            $name = basename($file);
            if (!in_array($name, ['config.php', 'config.local.php', 'seed.php', 'router.php'], true)) {
                $pages[] = preg_quote($name, '~');
            }
        }
        $regex = 'admin/|oauth/|' . ($pages ? '(?:' . implode('|', $pages) . ')' : 'index\.php');
    }
    return $regex;
}

/** Retire le préfixe du commerce d'un chemin (/naty/admin/x → /admin/x) ; sans effet sans préfixe. */
function app_unprefix(string $path): string
{
    $base = tenant_base_path();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        return substr($path, strlen($base)) ?: '/';
    }
    return $path;
}

/** Ajoute le préfixe du commerce à un chemin de page dynamique (/admin/x → /naty/admin/x). */
function app_prefix(string $path): string
{
    $base = tenant_base_path();
    if ($base === '' || !str_starts_with($path, '/')) {
        return $path;
    }
    $isRoot = $path === '/' || preg_match('#^/[?\#]#', $path);
    return $isRoot || preg_match('#^/(?:' . tenant_dynamic_path_regex() . ')#', $path) ? $base . $path : $path;
}

/**
 * Réécrit la page produite quand le commerce est servi sous un préfixe :
 * liens, formulaires et appels JS vers les pages dynamiques reçoivent le
 * préfixe, window.APP_BASE le donne aux scripts statiques.
 */
function tenant_rewrite_output(string $html): string
{
    $base = tenant_base_path();
    if ($base === '' || $html === '') {
        return $html;
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
            return $html;
        }
    }
    $dynamic = tenant_dynamic_path_regex();
    // Chaînes entre guillemets qui commencent par une page dynamique (attributs
    // HTML comme JS en ligne), puis la racine « / » des liens et formulaires.
    // Une expression qui échoue rend null : on garde alors la page telle quelle.
    $out = preg_replace('~(["\'])/(?=(?:' . $dynamic . '))~', '$1' . $base . '/', $html) ?? $html;
    $out = preg_replace('~(\b(?:href|action|formaction)\s*=\s*["\'])/(?=["\'?\#])~', '$1' . $base . '/', $out) ?? $out;
    $script = '<script>window.APP_BASE=' . json_encode($base) . ';</script>';
    return preg_replace('~<head(\s[^>]*)?>~i', '$0' . $script, $out, 1) ?? $out;
}

/**
 * Active les réécritures de préfixe (page produite et redirections) pour un
 * commerce servi sous /<slug>/. Idempotent : appelée par session.php comme par
 * config.php, selon le premier des deux que charge la page.
 */
function tenant_enable_base_path(): void
{
    static $done = false;
    if ($done || PHP_SAPI === 'cli' || tenant_base_path() === '') {
        return;
    }
    $done = true;
    ob_start('tenant_rewrite_output');
    header_register_callback('tenant_rewrite_location');
}

/** Préfixe l'en-tête Location d'une redirection vers une page dynamique (appelé juste avant l'envoi des en-têtes). */
function tenant_rewrite_location(): void
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') !== 0) {
            continue;
        }
        $target = trim(substr($header, 9));
        $prefixed = app_prefix($target);
        if ($prefixed !== $target) {
            $code = http_response_code() ?: 302;
            header('Location: ' . $prefixed, true, $code);
        }
    }
}

function tenant_dir(?string $slug = null): string
{
    return dirname(__DIR__) . '/tenants/' . ($slug ?? tenant_slug());
}

/**
 * Configuration du commerce actif, valeurs par défaut incluses.
 * tenant() rend tout le tableau, tenant('texts.follow_title') une valeur.
 */
function tenant(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = tenant_load(tenant_slug());
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Configuration complète d'un commerce quelconque (valeurs par défaut incluses). */
function tenant_load(string $slug): array
{
    $file = tenant_dir($slug) . '/tenant.php';
    if (!is_file($file)) {
        throw new RuntimeException("Commerce introuvable : tenants/$slug/tenant.php");
    }
    $config = array_replace_recursive(tenant_defaults($slug), require $file);
    $config['slug'] = $slug;
    return $config;
}

/** Commerces présents dans tenants/ (hors modèle), par slug. */
function tenant_list(): array
{
    $list = [];
    foreach (glob(dirname(__DIR__) . '/tenants/*/tenant.php') as $file) {
        $slug = basename(dirname($file));
        if ($slug[0] !== '_') {
            $list[$slug] = tenant_load($slug);
        }
    }
    ksort($list);
    return $list;
}

/** Chemin absolu d'un fichier d'un commerce donné (clé relative à la racine du projet). */
function tenant_file(array $config, string $key): string
{
    $path = (string) $config[$key];
    return str_starts_with($path, '/') ? $path : dirname(__DIR__) . '/' . $path;
}

function tenant_defaults(string $slug): array
{
    return [
        'name' => 'Ma boutique',
        'tagline' => '',
        'site_url' => 'http://localhost:8000',
        // Compte de l'administration (mot de passe en clair ou haché avec password_hash).
        'admin_user' => 'admin',
        'admin_password' => '',
        // Chemins relatifs à la racine du projet.
        'db_file' => "data/$slug.db",
        'secrets_dir' => ".secrets/$slug",
        'seed_file' => "tenants/$slug/seed-data.json",
        // Chemins relatifs à la racine web.
        'logo' => "assets/tenants/$slug/logo.png",
        'logo_macaron' => "assets/tenants/$slug/logo.png",
        // Surcharges des variables CSS de assets/style.css (ex : 'accent' => '#b5502e').
        'colors' => [],
        'colors_dark' => [],
        'fonts_url' => 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap',
        'categories' => [
            ['key' => 'divers', 'label' => 'Divers', 'icon' => 'ic-vase'],
        ],
        'texts' => [
            'categories_eyebrow' => 'Explorer par catégorie',
            'categories_title' => 'Nos catégories',
            'item_singular' => 'article',
            'item_plural' => 'articles',
            'follow_eyebrow' => 'En images',
            'follow_title' => 'Suivez-nous au quotidien',
            'newsletter_title' => 'Les nouveautés, avant tout le monde',
            'newsletter_text' => 'Un e-mail de temps en temps, quand il y a du nouveau. Pas plus.',
            'empty_category' => 'Aucun article dans cette catégorie pour le moment — repassez bientôt.',
        ],
        // Contexte donné à l'IA (Gemini) pour décrire les photos et rédiger les fiches.
        'ai' => [
            'shop' => 'une boutique en ligne',
            'item' => 'un article',
            'examples' => '',
        ],
        // Envoyer le dossier uploads/ local lors du déploiement FTP.
        'deploy_uploads' => false,
    ];
}

require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/templates.php';

/** Chemin absolu d'un fichier déclaré relativement à la racine du projet. */
function tenant_path(string $key): string
{
    return tenant_file(tenant(), $key);
}

function tenant_text(string $key): string
{
    return (string) tenant('texts.' . $key, '');
}

/**
 * Balises <head> propres au commerce : polices et couleurs. L'apparence
 * réglée dans l'administration (includes/appearance.php) prime sur les
 * couleurs et polices du tenant.php.
 */
function tenant_head_html(): string
{
    $lightColors = tenant('colors', []);
    $darkColors = tenant('colors_dark', []);
    $fontsUrl = tenant('fonts_url');
    $fontVars = [];

    $saved = function_exists('appearance_saved') ? appearance_saved() : null;
    // Modèle de design : l'aperçu demandé (?modele=<clé>), sinon celui réglé dans l'administration, sinon celui du tenant.php.
    // Un aperçu prend aussi sa palette et ses polices.
    $tplPreview = function_exists('shop_template_preview') ? shop_template_preview() : '';
    $tplKey = function_exists('shop_template_active') ? shop_template_active() : (string) tenant('template', '');
    if ($tplPreview !== '') $saved = ['colors' => SHOP_TEMPLATES[$tplPreview]['colors'], 'fonts' => SHOP_TEMPLATES[$tplPreview]['fonts']];
    if ($saved) {
        $palette = appearance_derive($saved['colors'] ?? []);
        $lightColors = $palette['light'];
        $darkColors = $palette['dark'];
    }
    $fonts = $saved['fonts'] ?? tenant('fonts', []);
    if (!empty($fonts['display']) || !empty($fonts['body'])) {
        $display = $fonts['display'] ?? APPEARANCE_BASE_FONTS['display'];
        $body = $fonts['body'] ?? APPEARANCE_BASE_FONTS['body'];
        $fontsUrl = appearance_fonts_url($display, $body);
        $fontVars = [
            'font-display' => appearance_font_stack('display', $display),
            'font-body' => appearance_font_stack('body', $body),
        ];
    }

    $html = (function_exists('logo_favicon_html') ? logo_favicon_html() : '')
        . '<link href="' . h($fontsUrl) . '" rel="stylesheet">';
    $vars = static function (array $colors): string {
        $css = '';
        foreach ($colors as $name => $value) {
            if (preg_match('/^[a-z0-9-]+$/', $name) && preg_match('/^[#(),.%\w\s"\'-]+$/', (string) $value) && $value !== '') {
                $css .= "--$name:$value;";
            }
        }
        return $css;
    };
    $light = $vars($lightColors + $fontVars);
    $dark = $vars($darkColors);
    $css = '';
    // Sélecteurs « html:root » : plus prioritaires que ceux de style.css,
    // chargé après ces balises.
    if ($light !== '') {
        $css .= "html:root{{$light}}";
    }
    if ($dark !== '') {
        $css .= "@media (prefers-color-scheme: dark){html:root:not([data-theme=\"light\"]){{$dark}}}html:root[data-theme=\"dark\"]{{$dark}}";
    }
    if ($css !== '') {
        $html .= "\n<style>$css</style>";
    }
    $templateCss = function_exists('shop_template_css') ? shop_template_css($tplKey) : '';
    if ($templateCss !== '') {
        $html .= "\n<style id=\"template-" . h($tplKey) . "\">$templateCss</style>";
    }
    $templateLink = function_exists('shop_template_link') ? shop_template_link($tplKey) : '';
    if ($templateLink !== '') $html .= "\n" . $templateLink;
    return $html . "\n";
}
