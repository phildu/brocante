<?php

// Extension de galerie « Visite guidée » : un parcours pas à pas du marché aux puces (halls, bâtiments, marchands) pour les visiteurs,
// bâti tout seul à partir du plan d'implantation : une étape par secteur (nature « Sous-zone » du plan) avec les marchands qui s'y trouvent,
// ou une étape par marchand quand le plan n'a pas de secteur. L'organisateur ajoute seulement un mot d'accueil et, s'il le veut, un mot par étape.
//
// Clé « visite » de includes/gallery-modules.php (incluse pour les marchés aux puces, réglable par type) ; page d'administration
// portail/galerie-visite.php ; page publique galerie/visite.php?g=<galerie>&etape=<n> ; bouton « Visite guidée » dans galerie/index.php.
//
// Stockage : data/galeries/<identifiant>.visite.json (comme .hours.json et .sponsors.json) :
//   show (bool, vrai par défaut : le bouton est affiché), intro (mot d'accueil), notes (identifiant de secteur ou clé de marchand => mot de l'étape).

require_once __DIR__ . '/galleries.php';
require_once __DIR__ . '/gallery-map.php';

const GV_INTRO_MAX = 600;
const GV_NOTE_MAX = 400;
/** Sans secteur dans le plan, une étape par marchand : au-delà, le parcours devient une liste, on s'arrête là. */
const GV_MAX_SHOP_STEPS = 12;

function gv_file(string $slug): string
{
    return gallery_slug_valid($slug) ? galleries_dir() . '/' . $slug . '.visite.json' : '';
}

/** Réglages normalisés : ['show' => bool, 'intro' => string, 'notes' => [clé => texte]]. */
function gv_normalize(mixed $data): array
{
    $data = is_array($data) ? $data : [];
    $clean = static fn (mixed $v, int $max): string => mb_substr(trim((string) preg_replace('/[ \t]+/', ' ', str_replace("\r", '', is_scalar($v) ? (string) $v : ''))), 0, $max);
    $notes = [];
    foreach ((array) ($data['notes'] ?? []) as $key => $text) {
        $key = (string) $key;
        $text = $clean($text, GV_NOTE_MAX);
        if ($text !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $key)) $notes[$key] = $text;
    }
    return [
        'show' => !array_key_exists('show', $data) || !empty($data['show']),
        'intro' => $clean($data['intro'] ?? '', GV_INTRO_MAX),
        'notes' => $notes,
    ];
}

function gv_load(string $slug): array
{
    $file = gv_file($slug);
    if ($file === '' || !is_file($file)) return gv_normalize([]);
    return gv_normalize(json_decode((string) file_get_contents($file), true));
}

function gv_save(string $slug, array $settings): bool
{
    $file = gv_file($slug);
    if ($file === '') return false;
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $json = json_encode(gv_normalize($settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return $json !== false && @file_put_contents($file, $json, LOCK_EX) !== false;
}

/** Supprime les réglages d'une galerie (appelée par gallery_delete()). */
function gv_delete_all(string $slug): void
{
    $file = gv_file($slug);
    if ($file !== '') @unlink($file);
}

/** L'extension est-elle ouverte pour cette galerie (module actif et galerie publiée) ? Le bouton public dépend en plus de gv_visible(). */
function gv_enabled(array $gallery): bool
{
    if (!function_exists('gm_enabled')) require_once __DIR__ . '/gallery-modules.php';
    return !empty($gallery['published']) && gm_enabled($gallery, 'visite');
}

/**
 * Étapes du parcours. $shops : commerces de la galerie (clé => ['name', 'tagline', 'logo', 'url', 'accent', 'count'…], comme gallery_catalogue()).
 * Toujours : un accueil, des étapes de contenu, puis une conclusion ; sans commerce, aucune étape (pas de visite à proposer).
 * -> liste de ['key','kind' => accueil|secteur|marchand|ailleurs|fin,'title','text','color','shops' => clé => commerce,'sector' => secteur|null]
 */
function gv_steps(array $gallery, array $shops, array $settings): array
{
    if (!$shops) return [];
    $notes = $settings['notes'];
    $sectors = gallery_map_sectors($gallery['map'] ?? null);
    $content = [];
    $placed = [];
    foreach ($sectors as $sec) {
        $inside = [];
        foreach ($sec['shops'] as $key) {
            if (isset($shops[$key])) {
                $inside[$key] = $shops[$key];
                $placed[$key] = true;
            }
        }
        if (!$inside) continue;
        $content[] = ['key' => $sec['id'], 'kind' => 'secteur', 'title' => $sec['name'], 'text' => $notes[$sec['id']] ?? $sec['note'],
            'color' => $sec['color'], 'shops' => $inside, 'sector' => $sec];
    }
    if ($sectors) {
        $rest = array_diff_key($shops, $placed);
        if ($rest) $content[] = ['key' => 'ailleurs', 'kind' => 'ailleurs', 'title' => $content ? 'Ailleurs sur le site' : 'Les marchands', 'text' => $notes['ailleurs'] ?? '',
            'color' => (string) ($gallery['accent'] ?? '#2f6b4f'), 'shops' => $rest, 'sector' => null];
    } else {
        foreach (array_slice($shops, 0, GV_MAX_SHOP_STEPS, true) as $key => $shop) {
            $content[] = ['key' => (string) $key, 'kind' => 'marchand', 'title' => (string) $shop['name'], 'text' => $notes[(string) $key] ?? (string) ($shop['tagline'] ?? ''),
                'color' => (string) (($shop['accent'] ?? '') !== '' ? $shop['accent'] : ($gallery['accent'] ?? '#2f6b4f')), 'shops' => [$key => $shop], 'sector' => null];
        }
    }
    $accent = (string) ($gallery['accent'] ?? '#2f6b4f');
    $intro = $settings['intro'] !== '' ? $settings['intro']
        : 'Suivez le guide : ' . (count($content) > 1 ? count($content) . ' étapes' : 'une étape') . ' pour découvrir ' . (string) ($gallery['name'] ?? 'le marché') . ', ' . ($sectors ? 'hall après hall' : 'marchand après marchand') . '.';
    return array_merge(
        [['key' => 'accueil', 'kind' => 'accueil', 'title' => (string) ($gallery['name'] ?? ''), 'text' => $intro, 'color' => $accent, 'shops' => [], 'sector' => null]],
        $content,
        [['key' => 'fin', 'kind' => 'fin', 'title' => 'Bonne visite !', 'text' => '', 'color' => $accent, 'shops' => [], 'sector' => null]]
    );
}

/** Bouton public affiché ? Extension ouverte, case « afficher le bouton » cochée et au moins un marchand à montrer. */
function gv_visible(array $gallery, array $shops): bool
{
    return gv_enabled($gallery) && gv_load((string) $gallery['slug'])['show'] && $shops !== [];
}
