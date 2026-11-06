<?php

// Applications installables (« web apps ») : nom affiché sous l'icône, nom court, identifiants stables
// et préparation d'un portage natif iOS / Android.
//
// Cinq applications, deux familles :
//   shop            — le site client du commerce                 (défaut : « Nom du site »)
//   article         — le Studio : photographier et créer les fiches (défaut : « Article Nom du site »)
//   vente           — la caisse : encaisser et valider les commandes (défaut : « Vente Nom du site »)
//   gallery_admin   — l'administration d'une galerie             (défaut : « Admin Nom de la galerie »)
//   gallery_visitor — l'espace client d'une galerie              (défaut : « Nom de la galerie »)
//   gallery_caisse  — la caisse de la buvette d'une galerie      (défaut : « Caisse Nom de la galerie »)
//
// Un nom personnalisé vide garde le nom par défaut : renommer ne casse jamais une installation.
// Les identifiants (manifeste et natif) ne dépendent PAS du nom : un portage iOS/Android pourra
// reprendre les mêmes valeurs sans perdre les installations existantes.

const APP_KEYS = ['shop', 'article', 'vente', 'gallery_admin', 'gallery_visitor', 'gallery_caisse'];

require_once __DIR__ . '/app-icons.php';   // icône de chaque application : pictogramme de base ou logo

/** Nom saisi : espaces normalisés, balises et caractères de contrôle retirés, longueur bornée. */
function app_name_clean(string $value, int $max = 40): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return mb_substr($value, 0, max(1, $max));
}

/** Nom par défaut d'une application, à partir du nom du commerce ou de la galerie. */
function app_default_name(string $app, string $ownerName): string
{
    $ownerName = app_name_clean($ownerName, 60);
    return match ($app) {
        'article' => 'Article ' . $ownerName,
        'vente' => 'Vente ' . $ownerName,
        'gallery_admin' => 'Admin ' . $ownerName,
        'gallery_caisse' => 'Caisse ' . $ownerName,
        default => $ownerName,   // boutique et espace client galerie : le nom de l'enseigne
    };
}

/** Nom affiché sous l'icône : le nom personnalisé s'il est renseigné, sinon le nom par défaut. */
function app_resolve_name(string $app, string $ownerName, string $custom = ''): string
{
    $custom = app_name_clean($custom);
    return $custom !== '' ? $custom : app_default_name($app, $ownerName);
}

/** Nom court des manifestes (Android, listes d'applications) : 15 caractères au plus, sans couper un mot. */
function app_short_name(string $name, int $max = 15): string
{
    $name = app_name_clean($name, 80);
    if (mb_strlen($name) <= $max) return $name;
    $short = mb_substr($name, 0, $max);
    $space = mb_strrpos($short, ' ');
    if ($space !== false && $space >= 6) $short = mb_substr($short, 0, $space);
    return rtrim($short);
}

/** Identifiant d'application natif (reverse DNS), stable quand le nom change : fr.brocs.<famille>.<enseigne>. */
function app_native_id(string $app, string $owner): string
{
    $family = match ($app) {
        'article' => 'article',
        'vente' => 'vente',
        'gallery_admin' => 'galerie.admin',
        'gallery_visitor' => 'galerie.visiteur',
        'gallery_caisse' => 'galerie.caisse',
        default => 'boutique',
    };
    $owner = mb_strtolower(trim($owner));
    if (class_exists('Normalizer')) {
        $owner = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($owner, Normalizer::FORM_D));
    }
    $owner = strtr($owner, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n']);
    $owner = substr((string) preg_replace('/[^a-z0-9]+/', '', $owner), 0, 40);
    if ($owner === '') $owner = 'app';
    if (ctype_digit($owner[0])) $owner = 's' . $owner;   // un segment de nom de paquet ne commence pas par un chiffre
    return 'fr.brocs.' . $family . '.' . $owner;
}

/** Identifiant du manifeste (même origine, stable) : il ne change pas quand le nom de l'application change. */
function app_manifest_id(string $app, string $base = '', string $ownerSlug = ''): string
{
    $path = match ($app) {
        'article' => '/studio.php?app=article',
        'vente' => '/vente.php?app=vente',
        'gallery_admin' => '/galerie/admin-manifest.php?app=admin&g=' . rawurlencode($ownerSlug),
        'gallery_visitor' => '/galerie/visiteur.php?app=visiteur&g=' . rawurlencode($ownerSlug),
        'gallery_caisse' => '/galerie/' . rawurlencode($ownerSlug) . '/buvette/caisse.php?app=caisse',
        default => '/?app=boutique',
    };
    return rtrim($base, '/') . $path;
}

/** Nom de l'application d'un commerce (« shop », « article », « vente »). */
function shop_app_name(string $app): string
{
    static $cache = [];
    if (isset($cache[$app])) return $cache[$app];
    $site = '';
    if (function_exists('get_content')) {
        try {
            $site = trim((string) (get_content()['site_name'] ?? ''));
        } catch (Throwable $e) {
            $site = '';
        }
    }
    if ($site === '' && function_exists('tenant')) {
        try {
            $site = trim((string) tenant('name'));
        } catch (Throwable $e) {
            $site = '';
        }
    }
    $custom = function_exists('shop_setting') ? (string) shop_setting('app_name_' . $app, '') : '';
    return $cache[$app] = app_resolve_name($app, $site, $custom);
}

/** Nom de l'application d'une galerie (« gallery_admin », « gallery_visitor »). */
function gallery_app_name(array $gallery, string $app): string
{
    $key = match ($app) {
        'gallery_admin' => 'admin',
        'gallery_visitor' => 'visitor',
        'gallery_caisse' => 'caisse',
        default => $app,
    };
    return app_resolve_name($app, (string) ($gallery['name'] ?? 'Galerie'), (string) ($gallery['apps'][$key] ?? ''));
}

/** Noms personnalisés stockés dans une galerie : une chaîne vide garde le nom par défaut. */
function gallery_apps_normalize(array $apps): array
{
    return [
        'admin' => app_name_clean((string) ($apps['admin'] ?? ''), 40),
        'visitor' => app_name_clean((string) ($apps['visitor'] ?? ''), 40),
        'caisse' => app_name_clean((string) ($apps['caisse'] ?? ''), 40),
    ];
}
