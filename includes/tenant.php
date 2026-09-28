<?php

// Marque blanche : tout ce qui est propre à un commerce (nom, logo, couleurs,
// catégories, textes, prompts IA, base, URL…) vit dans tenants/<slug>/tenant.php.
//
// Le commerce actif est, par ordre de priorité :
//   1. la variable d'environnement TENANT (session cloud, SetEnv Apache) ;
//   2. le fichier .tenant à la racine (écrit par le script de déploiement) ;
//   3. petit-chalet (comportement historique).

const TENANT_DEFAULT = 'petit-chalet';

function tenant_slug(): string
{
    static $slug = null;
    if ($slug === null) {
        $slug = getenv('TENANT') ?: '';
        $file = __DIR__ . '/../.tenant';
        if ($slug === '' && is_file($file)) {
            $slug = trim((string) file_get_contents($file));
        }
        $slug = $slug ?: TENANT_DEFAULT;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new RuntimeException('Nom de commerce invalide : ' . $slug);
        }
    }
    return $slug;
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

/** Chemin absolu d'un fichier déclaré relativement à la racine du projet. */
function tenant_path(string $key): string
{
    return tenant_file(tenant(), $key);
}

function tenant_text(string $key): string
{
    return (string) tenant('texts.' . $key, '');
}

/** Balises <head> propres au commerce : polices et couleurs. */
function tenant_head_html(): string
{
    $html = '<link href="' . h(tenant('fonts_url')) . '" rel="stylesheet">';
    $vars = static function (array $colors): string {
        $css = '';
        foreach ($colors as $name => $value) {
            if (preg_match('/^[a-z0-9-]+$/', $name) && preg_match('/^[#(),.%\w\s-]+$/', $value)) {
                $css .= "--$name:$value;";
            }
        }
        return $css;
    };
    $light = $vars(tenant('colors', []));
    $dark = $vars(tenant('colors_dark', []));
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
    return $html . "\n";
}
