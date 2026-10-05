<?php

// « Univers » d'un commerce : les rayons de sa boutique (filtres, accueil, classement des pièces). Ils viennent de
// tenants/<slug>/tenant.php, mais chaque commerce peut les adapter depuis l'administration (page Univers) : la liste
// enregistrée dans la table settings (clé « universes ») remplace alors celle du fichier, sans toucher au serveur.

/** Pictogrammes disponibles pour un univers (symboles de includes/icons.php). */
const UNIVERSE_ICONS = [
    'ic-vase' => 'Vase', 'ic-pitcher' => 'Pichet', 'ic-bowls' => 'Bols', 'ic-pot' => 'Pot',
    'ic-basket' => 'Panier', 'ic-stool' => 'Tabouret', 'ic-mirror' => 'Miroir', 'ic-plaid' => 'Plaid',
    'ic-cushion' => 'Coussin', 'ic-candle' => 'Bougie', 'ic-photophore' => 'Photophore', 'ic-pendant' => 'Suspension',
];
const UNIVERSE_MAX = 12;

/** Univers enregistrés pour ce commerce (liste de [key, label, icon]), ou null s'il utilise ceux de son fichier. */
function universes_saved(bool $refresh = false): ?array
{
    static $cache = false;
    static $busy = false;
    if ($refresh) $cache = false;
    if ($cache !== false) return $cache;
    if ($busy) return null; // pas de récursion pendant la création de la base
    $busy = true;
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'universes'");
        $stmt->execute();
        $list = json_decode((string) $stmt->fetchColumn(), true);
        $cache = is_array($list) && $list ? array_values($list) : null;
    } catch (Throwable $e) {
        $cache = null;
    }
    $busy = false;
    return $cache;
}

/** Remplace les univers du commerce par $rows (déjà normalisés). */
function universes_save(array $rows): void
{
    db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('universes', ?)")
        ->execute([json_encode(array_values($rows), JSON_UNESCAPED_UNICODE)]);
    universes_saved(true);
}

/** Revient aux univers du fichier du commerce. */
function universes_reset(): void
{
    db()->prepare("DELETE FROM settings WHERE name = 'universes'")->execute();
    universes_saved(true);
}

/**
 * Lignes saisies (key[], label[], icon[]) → univers propres : libellé de 40 caractères au plus, icône connue,
 * clé conservée pour une ligne existante (les pièces y restent rattachées) ou tirée du libellé, unique.
 * Lève InvalidArgumentException si la liste est vide ou trop longue.
 */
function universes_normalize(array $keys, array $labels, array $icons): array
{
    $out = [];
    $used = [];
    foreach ($labels as $i => $label) {
        $label = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $label)), 0, 40);
        if ($label === '') continue;
        $key = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($keys[$i] ?? '')));
        if ($key === '') $key = trim(slugify($label, 30), '-') ?: 'univers';
        $base = $key; $n = 2;
        while (isset($used[$key])) $key = $base . '-' . $n++;
        $used[$key] = true;
        $icon = (string) ($icons[$i] ?? '');
        $out[] = ['key' => $key, 'label' => $label, 'icon' => isset(UNIVERSE_ICONS[$icon]) ? $icon : 'ic-vase'];
    }
    if (!$out) throw new InvalidArgumentException('Gardez au moins un univers.');
    if (count($out) > UNIVERSE_MAX) throw new InvalidArgumentException('Douze univers au plus.');
    return $out;
}

/** Nombre de pièces par clé d'univers (toutes les fiches, masquées comprises). */
function universes_counts(): array
{
    $counts = [];
    foreach (db()->query('SELECT cat, COUNT(*) AS n FROM products GROUP BY cat')->fetchAll() as $row) $counts[$row['cat']] = (int) $row['n'];
    return $counts;
}

/** Références des pièces dont l'univers n'existe plus dans la liste courante. */
function universes_orphan_refs(): array
{
    $keys = array_column(category_list(), 'key');
    $refs = [];
    foreach (db()->query('SELECT ref, cat FROM products ORDER BY ref')->fetchAll() as $row) {
        if (!in_array($row['cat'], $keys, true)) $refs[] = $row['ref'];
    }
    return $refs;
}

/** Exemples d'univers donnés à l'IA : ceux enregistrés s'il y en a, sinon la description du fichier du commerce. */
function ai_shop_examples(): string
{
    $saved = universes_saved();
    return $saved ? implode(', ', array_map(static fn (array $c): string => mb_strtolower($c['label']), $saved)) : (string) tenant('ai.examples');
}

/**
 * Propose des univers à l'IA d'après l'identité de la boutique et son catalogue (noms des pièces, natures) :
 * liste de ['label' => …, 'icon' => …] (4 à 8), ou [] si l'IA ne répond pas.
 */
function universes_suggest(): array
{
    $content = get_content();
    $names = db()->query('SELECT name FROM products WHERE name != "" ORDER BY created_at DESC LIMIT 60')->fetchAll(PDO::FETCH_COLUMN);
    $natures = [];
    foreach (db()->query("SELECT nature, COUNT(*) AS n FROM products WHERE nature IS NOT NULL AND nature != '' GROUP BY nature ORDER BY n DESC")->fetchAll() as $r) {
        $natures[] = (product_nature_labels($r['nature'])[0] ?? $r['nature']) . ' (' . $r['n'] . ')';
    }
    $icons = implode(', ', array_map(static fn ($k, $l) => "$k = $l", array_keys(UNIVERSE_ICONS), UNIVERSE_ICONS));
    $prompt = 'Tu aides à organiser une boutique en ligne : ' . ($content['site_name'] ?: tenant('name')) . '. '
        . ($content['site_tagline'] ?? '' ? 'Accroche : ' . $content['site_tagline'] . '. ' : '')
        . 'Type de boutique : ' . tenant('ai.shop') . '. '
        . ($natures ? 'Natures des pièces en vente : ' . implode(', ', $natures) . '. ' : '')
        . ($names ? 'Exemples de pièces : ' . implode(' ; ', array_slice($names, 0, 60)) . '. ' : '')
        . "Propose entre 4 et 8 « univers » (rayons) pour classer ces produits sur le site : libellés courts (1 à 3 mots), au pluriel de préférence, "
        . "en français, qui couvrent l'ensemble du catalogue sans se chevaucher, adaptés à ce que vend VRAIMENT la boutique (ignore tout ce qui "
        . "ne correspond pas aux pièces citées). Pour chacun, choisis le pictogramme le plus proche parmi : $icons. "
        . 'Réponds UNIQUEMENT avec un tableau JSON, sans texte autour ni markdown, de la forme [{"label": "…", "icon": "ic-…"}].';
    $text = gemini_describe_image('', $prompt, 1);
    if (!$text) return [];
    $list = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $item) {
        $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 40);
        $icon = (string) ($item['icon'] ?? '');
        if ($label !== '') $out[] = ['label' => $label, 'icon' => isset(UNIVERSE_ICONS[$icon]) ? $icon : 'ic-vase'];
    }
    return array_slice($out, 0, 8);
}
