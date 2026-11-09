<?php

// Extension de galerie « Sponsors & partenaires » : l'organisateur d'une galerie (vide-grenier, marché, puces…) présente ceux qui le soutiennent
// (mécènes, commerçants du quartier, mairie, médias) sur la page publique de la galerie, avec leur logo et un lien.
//
// Clé « sponsors » de includes/gallery-modules.php ; page d'administration portail/galerie-sponsors.php ; affichage public dans galerie/index.php.
//
// Stockage : data/galeries/<identifiant>.sponsors.json (comme les autres fichiers annexes : .waitlist.json, .hours.json…), une liste de fiches :
//   id, name, tier (niveau), url, logo (uploads/galeries/…), description, visible, order, note (interne, jamais affichée), added.
// Les logos passent par gallery_image_store() (includes/galleries.php) et vivent sous uploads/galeries/.

require_once __DIR__ . '/galleries.php';

const GSP_MAX = 60;

/** Niveaux, du plus visible au plus discret : clé => [libellé d'une fiche, titre de la rubrique publique]. */
const GSP_TIERS = [
    'principal' => ['Sponsor principal', 'Notre sponsor principal'],
    'sponsor' => ['Sponsor', 'Nos sponsors'],
    'partenaire' => ['Partenaire', 'Nos partenaires'],
    'institutionnel' => ['Soutien institutionnel', 'Avec le soutien de'],
    'media' => ['Partenaire média', 'Nos partenaires médias'],
];

function gsp_file(string $slug): string
{
    return gallery_slug_valid($slug) ? galleries_dir() . '/' . $slug . '.sponsors.json' : '';
}

/** Adresse de logo acceptée : fichier de la galerie uploads/galeries/ (jamais une adresse externe, pour ne pas appeler un tiers à chaque visite). */
function gsp_logo(string $logo): string
{
    $logo = trim($logo);
    return preg_match('#^uploads/galeries/[a-z0-9][a-z0-9._-]*\.(?:jpg|png|webp)$#', $logo) ? $logo : '';
}

/** Site web : http(s) uniquement ; « exemple.fr » saisi sans schéma reçoit https://. Chaîne vide si inutilisable. */
function gsp_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) $url = 'https://' . $url;
    if (!preg_match('#^https?://[^\s"<>]+$#i', $url)) return '';
    $host = (string) parse_url($url, PHP_URL_HOST);
    return $host !== '' && str_contains($host, '.') ? mb_substr($url, 0, 300) : '';
}

/** Fiche normalisée ; null si inutilisable (pas de nom). */
function gsp_normalize($s): ?array
{
    if (!is_array($s)) return null;
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($s['name'] ?? ''))), 0, 80);
    if ($name === '') return null;
    $id = strtolower(trim((string) ($s['id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{8,16}$/', $id)) $id = bin2hex(random_bytes(4));
    $tier = (string) ($s['tier'] ?? 'partenaire');
    return [
        'id' => $id,
        'name' => $name,
        'tier' => isset(GSP_TIERS[$tier]) ? $tier : 'partenaire',
        'url' => gsp_url((string) ($s['url'] ?? '')),
        'logo' => gsp_logo((string) ($s['logo'] ?? '')),
        'description' => mb_substr(trim((string) ($s['description'] ?? '')), 0, 240),
        'visible' => !array_key_exists('visible', $s) || !empty($s['visible']),
        'order' => max(0, min(999, (int) ($s['order'] ?? 0))),
        'note' => mb_substr(trim((string) ($s['note'] ?? '')), 0, 500),
        'added' => (int) ($s['added'] ?? 0) ?: time(),
    ];
}

/** Liste normalisée : 60 fiches au plus, identifiants uniques, triée par niveau puis ordre puis nom. */
function gsp_normalize_list($list): array
{
    $out = [];
    foreach ((array) $list as $s) {
        $item = gsp_normalize($s);
        if (!$item || isset($out[$item['id']])) continue;
        $out[$item['id']] = $item;
        if (count($out) >= GSP_MAX) break;
    }
    $tiers = array_flip(array_keys(GSP_TIERS));
    $items = array_values($out);
    usort($items, static fn (array $a, array $b): int => [$tiers[$a['tier']], $a['order'], mb_strtolower($a['name'])] <=> [$tiers[$b['tier']], $b['order'], mb_strtolower($b['name'])]);
    return $items;
}

/** Toutes les fiches d'une galerie (visibles ou non). */
function gsp_load(string $slug): array
{
    $file = gsp_file($slug);
    if ($file === '' || !is_file($file)) return [];
    $data = json_decode((string) file_get_contents($file), true);
    return gsp_normalize_list(is_array($data) ? $data : []);
}

function gsp_save(string $slug, array $list): bool
{
    $file = gsp_file($slug);
    if ($file === '') return false;
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $json = json_encode(gsp_normalize_list($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return $json !== false && @file_put_contents($file, $json, LOCK_EX) !== false;
}

/** Fiches visibles sur la page publique, regroupées par niveau (les niveaux vides sont omis) : niveau => fiches. */
function gsp_public(string $slug): array
{
    $groups = [];
    foreach (gsp_load($slug) as $s) {
        if ($s['visible']) $groups[$s['tier']][] = $s;
    }
    $ordered = [];
    foreach (array_keys(GSP_TIERS) as $tier) {
        if (!empty($groups[$tier])) $ordered[$tier] = $groups[$tier];
    }
    return $ordered;
}

/** Compte par niveau, fiches masquées comprises : ['total' => n, 'visibles' => n, 'par_niveau' => [niveau => n]]. */
function gsp_stats(array $list): array
{
    $byTier = array_fill_keys(array_keys(GSP_TIERS), 0);
    $visible = 0;
    foreach ($list as $s) {
        $byTier[$s['tier']]++;
        if ($s['visible']) $visible++;
    }
    return ['total' => count($list), 'visibles' => $visible, 'par_niveau' => $byTier];
}

/** Supprime les fichiers de logo qui ne sont plus référencés (appelée après une suppression ou un remplacement). */
function gsp_forget_logo(string $logo): void
{
    if ($logo !== '') gallery_image_delete($logo);
}

/** Supprime les données et les logos d'une galerie (appelée par gallery_delete()). */
function gsp_delete_all(string $slug): void
{
    foreach (gsp_load($slug) as $s) gsp_forget_logo($s['logo']);
    $file = gsp_file($slug);
    if ($file !== '') @unlink($file);
}
