<?php

// Adresses publiques choisies pour une galerie ou une boutique, à la place de /galerie/<identifiant>/ ou bricbrok.com/<commerce>/.
//
// - Galerie : la page est servie à l'adresse choisie (domaine + chemin), ex. aux-puces.com/de-villeurbanne.
// - Boutique : elle est servie sur le domaine de l'adresse choisie (petit-chalet.aux-puces.com, comme un sous-domaine de commerce) et
//   son accueil au chemin choisi (/de-villeurbanne) ; ses autres pages restent à la racine de ce domaine (/produit.php…).
// - Les anciennes adresses (page de la galerie, accueil de la boutique) redirigent vers les nouvelles, mais seulement sur les domaines
//   de production (ADRESSES_HOTES_PROD) : la préprod et le local restent consultables sous leurs adresses habituelles.
//
// Ajouter une adresse : l'ajouter ici ET ajouter son chemin à la règle « Adresses publiques » du .htaccess, de LocalValetDriver.php
// et de router.php. Le domaine doit arriver sur ce serveur (Cloudflare : pas de règle de redirection sur ces adresses ; un sous-domaine
// passe par la réécriture Host + X-Orig-Host décrite dans includes/edge-proxy.php).

/** Identifiant de galerie => adresse publique (https://domaine/chemin, sans barre finale). */
const ADRESSES_GALERIES = [
    'lespuces' => 'https://aux-puces.com/de-villeurbanne',
];

/** Identifiant de commerce => adresse publique de son accueil (https://domaine/chemin, sans barre finale). */
const ADRESSES_BOUTIQUES = [
    'petit-chalet' => 'https://petit-chalet.aux-puces.com/de-villeurbanne',
];

/** Domaines sur lesquels les anciennes adresses redirigent vers les nouvelles (sans « www. »). */
const ADRESSES_HOTES_PROD = ['bricbrok.com', 'aux-puces.com'];

/** Hôte d'une requête ou d'une adresse : minuscules, sans port ni « www. ». */
function adresse_host_normalize(string $host): string
{
    $host = strtolower(trim($host));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    return (string) preg_replace('/^www\./', '', $host);
}

/** Chemin d'une adresse : sans barre finale, '' pour la racine. */
function adresse_path_normalize(string $path): string
{
    return rtrim($path, '/');
}

/**
 * Ce que sert l'adresse demandée, s'il s'agit d'une adresse publique choisie :
 * ['type' => 'galerie'|'boutique', 'slug' => …], sinon null.
 *
 * @param array<string,string>|null $galeries  @param array<string,string>|null $boutiques  (tests)
 */
function adresse_publique_cible(string $host, string $path, ?array $galeries = null, ?array $boutiques = null): ?array
{
    $host = adresse_host_normalize($host);
    $path = adresse_path_normalize($path);
    if ($host === '' || $path === '') return null;
    foreach (['galerie' => $galeries ?? ADRESSES_GALERIES, 'boutique' => $boutiques ?? ADRESSES_BOUTIQUES] as $type => $map) {
        foreach ($map as $slug => $url) {
            if (adresse_host_normalize((string) parse_url($url, PHP_URL_HOST)) === $host
                && adresse_path_normalize((string) parse_url($url, PHP_URL_PATH)) === $path) {
                return ['type' => $type, 'slug' => (string) $slug];
            }
        }
    }
    return null;
}

/** Chemins de toutes les adresses choisies (« /de-villeurbanne »…), sans doublon. */
function adresse_chemins(): array
{
    $paths = [];
    foreach (array_merge(array_values(ADRESSES_GALERIES), array_values(ADRESSES_BOUTIQUES)) as $url) {
        $p = adresse_path_normalize((string) parse_url($url, PHP_URL_PATH));
        if ($p !== '') $paths[$p] = true;
    }
    return array_keys($paths);
}

/** Adresse publique choisie pour une galerie, ou '' (adresse habituelle). */
function adresse_galerie(string $slug): string
{
    return ADRESSES_GALERIES[$slug] ?? '';
}

/** Origine (https://domaine) d'une boutique qui a une adresse publique choisie, ou ''. */
function adresse_boutique_origine(string $slug): string
{
    $url = ADRESSES_BOUTIQUES[$slug] ?? '';
    if ($url === '') return '';
    return 'https://' . strtolower((string) parse_url($url, PHP_URL_HOST));
}

/** Vrai si l'hôte est un domaine de production (les redirections des anciennes adresses n'ont lieu que là). */
function adresse_hote_prod(string $host): bool
{
    return in_array(adresse_host_normalize($host), ADRESSES_HOTES_PROD, true);
}

/**
 * Nouvelle adresse vers laquelle rediriger une requête GET, ou '' :
 * - /galerie/<id>/ d'une galerie qui a une adresse choisie (domaines de production) ;
 * - /<commerce>/ (accueil d'une boutique sous le domaine du portail) d'une boutique qui en a une (domaines de production) ;
 * - la racine du domaine d'une boutique (petit-chalet.aux-puces.com/) vers son accueil choisi (partout).
 * La chaîne de requête est conservée.
 *
 * @param array<string,string>|null $galeries  @param array<string,string>|null $boutiques  (tests)
 */
function adresse_redirection(string $host, string $uri, ?array $galeries = null, ?array $boutiques = null): string
{
    $galeries ??= ADRESSES_GALERIES;
    $boutiques ??= ADRESSES_BOUTIQUES;
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    $suffix = $query !== '' ? '?' . $query : '';
    $hostN = adresse_host_normalize($host);

    if (adresse_hote_prod($hostN)) {
        if (preg_match('#^/galerie/([a-z0-9][a-z0-9-]*)/?$#', $path, $m) && isset($galeries[$m[1]])) {
            return $galeries[$m[1]] . $suffix;
        }
        if (preg_match('#^/([a-z0-9][a-z0-9_-]*)/?$#', $path, $m) && isset($boutiques[$m[1]])
            && adresse_host_normalize((string) parse_url($boutiques[$m[1]], PHP_URL_HOST)) !== $hostN) {
            return $boutiques[$m[1]] . $suffix;
        }
    }
    if ($path === '/' || $path === '') {
        foreach ($boutiques as $url) {
            $p = adresse_path_normalize((string) parse_url($url, PHP_URL_PATH));
            if ($p !== '' && adresse_host_normalize((string) parse_url($url, PHP_URL_HOST)) === $hostN) return $url . $suffix;
        }
    }
    return '';
}

/** Applique adresse_redirection() à la requête en cours (GET et HEAD seulement) : envoie la redirection 301 et termine. */
function adresse_rediriger_si_besoin(): void
{
    if (PHP_SAPI === 'cli') return;
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') return;
    $target = adresse_redirection((string) ($_SERVER['HTTP_HOST'] ?? ''), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
    if ($target === '') return;
    header('Location: ' . $target, true, 301);
    exit;
}
