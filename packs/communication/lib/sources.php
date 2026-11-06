<?php
// Sources de l'éditeur visuel propres à la boutique (« Source des données » d'electroboy80, plugin/sources.js) : les pièces en
// vente et les promos, sous la forme lue par sources.js (liste « items », champs décrits dans eb80-shim.js, EB80SourceConfig).
// Lecture seule, base de la boutique courante uniquement ; seules les informations publiques d'une pièce sortent (jamais le prix
// plancher, les estimations internes ni le code-barres). Route : GET /shop/pieces et /shop/promos (api/eb80.php).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/share.php';
require_once dirname(__DIR__, 3) . '/includes/promo.php';

/** Adresse d'un fichier du site pour le cadre de l'éditeur (même origine : l'affiche peut dessiner l'image). */
function com_source_media(string $path): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^https?://#i', $path)) return $path;
    return app_prefix('/' . ltrim($path, '/'));
}

/** Photos visibles d'une pièce, couverture d'abord, sans doublon. */
function com_source_photos(array $p): array
{
    $photos = [(string) ($p['photo'] ?? '')];
    foreach (product_photos_list((string) $p['ref']) as $ph) {
        if ((int) $ph['is_hidden'] === 0 && ($ph['type'] ?? 'photo') === 'photo') $photos[] = (string) $ph['path'];
    }
    return array_values(array_unique(array_filter(array_map('com_source_media', $photos))));
}

/**
 * Une pièce sous forme publique. Chaque champ peut être choisi dans les options de la source (configurateur de sources.js) : la
 * liste et les libellés proposés sont dans eb80-shim.js (champsDisponibles), à tenir en accord avec ces clés.
 */
function com_source_piece(array $p): array
{
    $promo = has_promo_price($p);
    $t = static fn (string $k): string => trim((string) ($p[$k] ?? ''));
    $condition = product_condition($t('etat'));
    return [
        'id' => (string) $p['ref'],
        'ref' => (string) $p['ref'],
        'name' => (string) $p['name'],
        'category' => category_label((string) $p['cat']),
        'nature' => product_nature_text($t('nature'), $t('sous_categorie')),
        'description' => $t('description'),
        'price' => (string) effective_price($p),
        'old_price' => $promo ? $t('price') : '',
        'old_price_text' => $promo ? 'Au lieu de ' . $t('price') : '',
        'new_price_text' => (int) ($p['new_show'] ?? 0) === 1 && $t('new_price') !== '' ? 'Prix du neuf : ' . $t('new_price') : '',
        'badge' => $t('badge'),
        'etat' => $condition ? (string) $condition[0] : $t('etat'),
        'brand' => $t('brand'),
        'materials' => $t('materials'),
        'colors' => $t('colors'),
        'size' => $t('size_text'),
        'weight' => $t('weight_text'),
        'negotiable' => (int) ($p['negotiable'] ?? 0) === 1 ? 'Prix à débattre' : '',
        'photos' => com_source_photos($p),
        'url' => shop_abs_url('/produit.php?ref=' . rawurlencode((string) $p['ref'])),
    ];
}

/** Pièces en vente (ni masquées, ni vendues), dans l'ordre du catalogue. */
function com_source_pieces(): array
{
    return array_map('com_source_piece', get_products());
}

/** Promos en cours : pièces au badge « Promo » encore en vente, avec leur visuel promo à jour en première image s'il existe. */
function com_source_promos(): array
{
    $items = [];
    foreach (promo_products() as $p) {
        $item = com_source_piece($p);
        $visual = promo_visual_get((string) $p['ref']);
        if (promo_visual_fresh($p, $visual)) {
            array_unshift($item['photos'], com_source_media((string) $visual['path']));
            $item['headline'] = (string) $visual['headline'];
            $item['cta'] = (string) $visual['cta'];
        } else {
            $item['headline'] = '';
            $item['cta'] = '';
        }
        $items[] = $item;
    }
    return $items;
}
