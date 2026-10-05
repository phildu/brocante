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

/** Type de boutique en une phrase (« friperie et mode vintage »), détecté par l'IA ou saisi : il guide l'IA dans tous ses prompts. */
function shop_profile(): string
{
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'shop_profile'");
        $stmt->execute();
        return trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function shop_profile_save(string $profile): void
{
    $profile = mb_substr(trim(preg_replace('/\s+/u', ' ', $profile)), 0, 120);
    if ($profile === '') db()->prepare("DELETE FROM settings WHERE name = 'shop_profile'")->execute();
    else db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('shop_profile', ?)")->execute([$profile]);
}

/**
 * Ce que vend la boutique, tel que l'IA doit le comprendre dans ses prompts : le type de boutique enregistré s'il y en a un,
 * sinon les univers enregistrés, sinon la description du fichier du commerce (souvent un texte d'exemple du modèle).
 */
function ai_shop_examples(): string
{
    if ($profile = shop_profile()) return $profile;
    $saved = universes_saved();
    return $saved ? implode(', ', array_map(static fn (array $c): string => mb_strtolower($c['label']), $saved)) : (string) tenant('ai.examples');
}

/**
 * Découvre ce que vend la boutique et propose ses univers. Sources, de la plus fiable à la moins fiable : l'indication du
 * vendeur ($hint), les PHOTOS des pièces en vente et leurs natures, le nom et l'accroche de la boutique ; les textes du site
 * et la description du fichier du commerce peuvent être des exemples du modèle (« Le bon pain, comme au fournil ») : l'IA est
 * prévenue de les ignorer s'ils contredisent les photos.
 * Retourne ['profile' => « friperie et mode vintage », 'universes' => [['label' => …, 'icon' => …], …]] ou [].
 */
function universes_suggest(string $hint = ''): array
{
    $content = get_content();
    $rows = db()->query("SELECT ref, name, photo FROM products WHERE photo IS NOT NULL AND photo != '' ORDER BY created_at DESC, rowid DESC LIMIT 8")->fetchAll();
    $root = realpath(__DIR__ . '/..');
    $smalls = [];
    $names = [];
    foreach ($rows as $r) {
        $abs = $root . '/' . $r['photo'];
        if (!is_file($abs)) continue;
        $smalls[] = downscale_for_ai($abs, 512, 70) ?? $abs;
        $names[] = $r['name'];
    }
    $natures = [];
    foreach (db()->query("SELECT nature, COUNT(*) AS n FROM products WHERE nature IS NOT NULL AND nature != '' GROUP BY nature ORDER BY n DESC")->fetchAll() as $r) {
        $natures[] = (product_nature_labels($r['nature'])[0] ?? $r['nature']) . ' (' . $r['n'] . ')';
    }
    $icons = implode(', ', array_map(static fn ($k, $l) => "$k = $l", array_keys(UNIVERSE_ICONS), UNIVERSE_ICONS));
    $hint = trim($hint);
    $prompt = 'Tu aides à organiser une boutique en ligne. Découvre ce qu\'elle vend VRAIMENT, puis propose ses univers (rayons). '
        . ($hint !== '' ? "Indication du vendeur (prioritaire, à suivre) : « $hint ». " : '')
        . ($smalls ? 'Les ' . count($smalls) . ' photos jointes sont celles de pièces récemment mises en vente (' . implode(' ; ', array_slice($names, 0, 8)) . ') : '
            . "ce sont les indices les plus fiables. " : '')
        . ($natures ? 'Natures détectées des pièces, par nombre : ' . implode(', ', $natures) . '. ' : '')
        . 'Nom de la boutique : ' . ($content['site_name'] ?: tenant('name')) . ($content['site_tagline'] ? ' ; accroche : ' . $content['site_tagline'] : '') . '. '
        . "ATTENTION : les textes du site (accueil, « notre histoire »…) et la description technique de la boutique viennent parfois d'un modèle d'exemple "
        . "(par exemple une boulangerie) sans rapport avec ce qui est vendu : ignore-les dès qu'ils contredisent les photos ou les natures. "
        . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour ni markdown : "
        . '{"profile": "ce que vend la boutique en une courte phrase, ex : friperie et mode vintage pour femme", '
        . '"universes": [{"label": "…", "icon": "ic-…"}]}. '
        . 'Entre 4 et 8 univers, libellés courts (1 à 3 mots), au pluriel de préférence, en français, qui couvrent l\'ensemble de ce que vend la boutique sans se chevaucher ; '
        . "pour chacun, le pictogramme le plus proche parmi : $icons — sans répéter le même pictogramme quand on peut l'éviter (ex. le plaid pour le textile et les vêtements, le miroir pour les bijoux et accessoires).";
    $text = gemini_describe_image($smalls[0] ?? '', $prompt, 1, array_slice($smalls, 1));
    foreach ($smalls as $i => $small) {
        $abs = $root . '/' . ($rows[$i]['photo'] ?? '');
        if ($small !== $abs && str_starts_with($small, sys_get_temp_dir())) @unlink($small);
    }
    if (!$text) return [];
    $data = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
    // Le modèle ajoute parfois une phrase autour du JSON : on en extrait l'objet.
    if (!is_array($data) && preg_match('/\{.*\}/s', $text, $m)) $data = json_decode($m[0], true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ((array) ($data['universes'] ?? []) as $item) {
        $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 40);
        $icon = (string) ($item['icon'] ?? '');
        if ($label !== '') $out[] = ['label' => $label, 'icon' => isset(UNIVERSE_ICONS[$icon]) ? $icon : 'ic-vase'];
    }
    return $out ? ['profile' => mb_substr(trim((string) ($data['profile'] ?? '')), 0, 120), 'universes' => array_slice($out, 0, 8), 'seen' => ['photos' => count($smalls), 'names' => array_slice($names, 0, 4), 'natures' => array_slice($natures, 0, 4)]] : [];
}
