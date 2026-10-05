<?php

// Pièces SIMILAIRES déjà enregistrées : avant de demander à l'IA d'estimer un poids, un prix ou des dimensions,
// on regarde ce que le catalogue sait déjà d'objets comparables (même nature et sous-catégorie, noms voisins).
// Quand il y en a assez et qu'elles s'accordent, la valeur vient du catalogue sans appeler l'IA ; sinon elles sont
// données à l'IA comme référence prioritaire, ce qui la rend plus cohérente avec les pièces de la boutique.

const COMPARABLE_STOPWORDS = [
    'pour', 'avec', 'sans', 'dans', 'sous', 'entre', 'vintage', 'ancien', 'ancienne', 'anciens', 'anciennes', 'petit', 'petite', 'petits', 'petites',
    'grand', 'grande', 'grands', 'grandes', 'noir', 'noire', 'blanc', 'blanche', 'rouge', 'bleu', 'bleue', 'vert', 'verte', 'jaune', 'gris', 'grise',
    'beige', 'marron', 'rose', 'style', 'motif', 'motifs', 'decor', 'deco', 'piece', 'pieces', 'objet', 'article', 'lot', 'tres', 'bon', 'bien',
    'etat', 'neuf', 'neuve', 'belle', 'beau', 'joli', 'jolie', 'epoque', 'annees', 'taille', 'marque',
];

/** Mots significatifs d'un nom de pièce (minuscules, sans accents, sans pluriel simple, sans mots creux). */
function comparable_tokens(string $name): array
{
    $text = strtr(mb_strtolower($name), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => ' ']);
    $tokens = [];
    foreach (preg_split('/[^a-z0-9]+/', $text) ?: [] as $t) {
        if (strlen($t) < 4 || in_array($t, COMPARABLE_STOPWORDS, true)) continue;
        $tokens[] = rtrim($t, 's') ?: $t;
    }
    return array_values(array_unique($tokens));
}

/**
 * Pièces comparables à $ctx (name, nature, sous_categorie, cat — clés), du plus proche au moins proche, avec leur
 * « score » : +4 même sous-catégorie, +2 même nature, +1 même univers, +2 par mot significatif commun au nom.
 * Seuls les scores d'au moins 3 sont gardés ; $excludeRef écarte la pièce elle-même.
 */
function product_comparables(array $ctx, string $excludeRef = '', int $limit = 8): array
{
    $nature = (string) ($ctx['nature'] ?? '');
    $sub = (string) ($ctx['sous_categorie'] ?? '');
    $cat = (string) ($ctx['cat'] ?? '');
    $tokens = comparable_tokens((string) ($ctx['name'] ?? ''));
    $rows = [];
    foreach (db()->query('SELECT ref, name, price, weight_grams, weight_text, size_text, materials, etat, nature, sous_categorie, cat FROM products')->fetchAll() as $p) {
        if ($p['ref'] === $excludeRef) continue;
        $score = 0;
        if ($sub !== '' && $p['sous_categorie'] === $sub && $p['nature'] === $nature) $score += 4;
        if ($nature !== '' && $p['nature'] === $nature) $score += 2;
        if ($cat !== '' && $p['cat'] === $cat) $score += 1;
        if ($tokens) $score += 2 * count(array_intersect($tokens, comparable_tokens((string) $p['name'])));
        if ($score >= 3) { $p['score'] = $score; $rows[] = $p; }
    }
    usort($rows, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['ref'], $b['ref']));
    return array_slice($rows, 0, $limit);
}

/** Valeur au rang $q (0 à 1) d'une liste triée de nombres. */
function comparable_quantile(array $sorted, float $q): float
{
    $i = ($sorted ? count($sorted) - 1 : 0) * $q;
    $lo = (int) floor($i);
    $hi = (int) ceil($i);
    return $sorted ? $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($i - $lo) : 0.0;
}

/**
 * Poids que le catalogue donne pour cette pièce, sans IA : médiane du poids de pièces de MÊME sous-catégorie
 * (score ≥ 6), à condition d'en avoir au moins 3 et qu'elles s'accordent (écart interquartile ≤ 50 % de la médiane).
 * Retourne ['grams' => entier arrondi à 10 g, 'n' => nombre de pièces] ou null.
 */
function comparables_weight_estimate(array $comparables): ?array
{
    $weights = [];
    foreach ($comparables as $p) {
        if ((int) $p['weight_grams'] > 0 && $p['score'] >= 6) $weights[] = (int) $p['weight_grams'];
    }
    if (count($weights) < 3) return null;
    sort($weights);
    $median = comparable_quantile($weights, 0.5);
    if ($median <= 0 || (comparable_quantile($weights, 0.75) - comparable_quantile($weights, 0.25)) / $median > 0.5) return null;
    return ['grams' => max(10, (int) (round($median / 10) * 10)), 'n' => count($weights)];
}

/**
 * Texte donné à l'IA : les pièces similaires du catalogue, comme référence prioritaire pour estimer prix, poids et
 * dimensions (sans les recopier si l'objet diffère). Vide s'il n'y en a pas.
 */
function comparables_prompt_text(array $comparables): string
{
    if (!$comparables) return '';
    $lines = [];
    foreach (array_slice($comparables, 0, 8) as $p) {
        $bits = [];
        if (price_to_cents($p['price'])) $bits[] = 'prix ' . $p['price'];
        $weight = trim((string) $p['weight_text']) ?: product_weight_text((int) $p['weight_grams']);
        if ($weight !== '') $bits[] = 'poids ' . $weight;
        if (trim((string) $p['size_text']) !== '') $bits[] = 'taille ' . $p['size_text'];
        if (trim((string) $p['materials']) !== '') $bits[] = 'matières ' . $p['materials'];
        if ($cond = product_condition($p['etat'] ?? '')) $bits[] = 'état ' . mb_strtolower($cond[0]);
        $lines[] = '« ' . $p['name'] . ' »' . ($bits ? ' (' . implode(', ', $bits) . ')' : '');
    }
    return 'Pièces SIMILAIRES déjà enregistrées dans cette boutique, à prendre comme référence PRIORITAIRE pour estimer prix, poids et dimensions '
        . "(adapte-toi si l'objet diffère, sans les recopier aveuglément) : " . implode(' ; ', $lines) . '. ';
}

/**
 * Après la génération d'une fiche : si le catalogue connaît assez de pièces comparables (même nature et sous-catégorie),
 * leur poids médian remplace l'estimation de l'IA. Retourne true si le poids a été corrigé.
 */
function catalog_refine_weight(string $ref): bool
{
    $product = get_product($ref);
    if (!$product) return false;
    $estimate = comparables_weight_estimate(product_comparables([
        'name' => $product['name'], 'nature' => $product['nature'] ?? '', 'sous_categorie' => $product['sous_categorie'] ?? '', 'cat' => $product['cat'],
    ], $ref));
    if (!$estimate) return false;
    db()->prepare('UPDATE products SET weight_grams = ?, weight_text = ? WHERE ref = ?')
        ->execute([$estimate['grams'], product_weight_text($estimate['grams']), $ref]);
    return true;
}
