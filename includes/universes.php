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
        $key = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($keys[$i] ?? '')));
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
 * Retourne ['profile' => « friperie et mode vintage »], 'sectors' => [clés], 'universes' => [univers correspondants], 'source' => ia|natures|texte] ou [].
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
    $hint = trim($hint);
    $prompt = 'Tu aides à organiser une boutique en ligne. Découvre ce qu\'elle vend VRAIMENT : ses UNIVERS sont les grands domaines de ses produits (mode, sport, déco, alimentaire…). '
        . ($hint !== '' ? "Indication du vendeur (prioritaire, à suivre) : « $hint ». " : '')
        . ($smalls ? 'Les ' . count($smalls) . ' photos jointes sont celles de pièces récemment mises en vente (' . implode(' ; ', array_slice($names, 0, 8)) . ') : '
            . "ce sont les indices les plus fiables. " : '')
        . ($natures ? 'Natures détectées des pièces, par nombre : ' . implode(', ', $natures) . '. ' : '')
        . 'Nom de la boutique : ' . ($content['site_name'] ?: tenant('name')) . ($content['site_tagline'] ? ' ; accroche : ' . $content['site_tagline'] : '') . '. '
        . "ATTENTION : les textes du site (accueil, « notre histoire »…) et la description technique de la boutique viennent parfois d'un modèle d'exemple "
        . "(par exemple une boulangerie) sans rapport avec ce qui est vendu : ignore-les dès qu'ils contredisent les photos ou les natures. "
        . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour ni markdown : "
        . '{"sectors": ["clé", …], "profile": "ce que vend la boutique en une courte phrase, ex : friperie et mode vintage pour femme"}. '
        . 'Dans « sectors », les clés des UNIVERS de la boutique — une à quatre, les grands domaines de ce qu\'elle vend vraiment — parmi : '
        . implode(' ; ', array_map(static fn ($k, $v) => $k . ' = ' . $v['label'], array_keys(shop_sectors()), shop_sectors())) . '.';
    $text = gemini_describe_image($smalls[0] ?? '', $prompt, 1, array_slice($smalls, 1));
    foreach ($smalls as $i => $small) {
        $abs = $root . '/' . ($rows[$i]['photo'] ?? '');
        if ($small !== $abs && str_starts_with($small, sys_get_temp_dir())) @unlink($small);
    }
    $data = [];
    if ($text) {
        $data = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
        // Le modèle ajoute parfois une phrase autour du JSON : on en extrait l'objet.
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $m)) $data = json_decode($m[0], true);
        if (!is_array($data)) $data = [];
    }
    // Secteurs : ce que l'IA a reconnu ; sinon ce que disent les natures des pièces ; sinon les mots du nom, de l'accroche et
    // de l'indication du vendeur. Ainsi la détection ne dépend plus d'une seule réponse de l'IA.
    $textHints = shop_sectors_from_text($hint . ' ' . ($content['site_name'] ?: tenant('name')) . ' ' . ($content['site_tagline'] ?? ''));
    $sectors = shop_sectors_clean($data['sectors'] ?? []);
    $source = $sectors ? 'ia' : '';
    if (!$sectors && ($sectors = shop_sectors_from_natures())) $source = 'natures';
    if (!$sectors && ($sectors = $textHints)) $source = 'texte';
    // Une indication du vendeur reconnue passe devant.
    $hintSectors = $hint !== '' ? shop_sectors_from_text($hint) : [];
    if ($hintSectors) $sectors = array_values(array_unique(array_merge($hintSectors, $sectors)));
    $sectors = array_slice($sectors, 0, 4);

    $profile = mb_substr(trim((string) ($data['profile'] ?? '')), 0, 120);
    if ($profile === '' && $sectors) $profile = mb_substr(implode(' ; ', array_map(static fn ($k) => shop_sectors()[$k]['profile'], $sectors)), 0, 120);
    if (!$sectors) return [];
    return [
        'profile' => $profile, 'universes' => universes_for_sectors($sectors), 'sectors' => $sectors, 'source' => $source,
        'seen' => ['photos' => count($smalls), 'names' => array_slice($names, 0, 4), 'natures' => array_slice($natures, 0, 4)],
    ];
}

// ── Secteurs : les grands domaines qu'une boutique peut vendre ──

/**
 * Les UNIVERS d'une boutique sont ses grands domaines : Mode, Sport, Déco, Alimentaire, TV-hifi, Informatique… Chaque entrée porte
 * son libellé court (« short », celui d'un univers), sa description pour l'IA (« profile »), les natures de produits qui y mènent,
 * des mots du nom ou de l'accroche qui le trahissent et son pictogramme. Une boutique peut en avoir plusieurs. Le détail d'un
 * produit (vêtement › pulls…) relève de sa nature et de sa sous-catégorie (product_nature_options()).
 */
function shop_sectors(): array
{
    return [
        'mode' => ['short' => 'Mode', 'label' => 'Mode et vêtements', 'icon' => 'ic-plaid', 'profile' => 'mode et vêtements, neufs ou de seconde main',
            'natures' => ['vetements', 'chaussures', 'accessoires'], 'words' => ['fripe', 'friperie', 'mode', 'vetement', 'tissu', 'couture', 'habit', 'textile', 'pret-a-porter', 'dressing']],
        'sport' => ['short' => 'Sport', 'label' => 'Sport et loisirs', 'icon' => 'ic-basket', 'profile' => 'articles de sport et de loisirs',
            'natures' => ['sport'], 'words' => ['sport', 'velo', 'fitness', 'running', 'randonnee', 'outdoor', 'ski', 'football', 'tennis']],
        'deco' => ['short' => 'Déco', 'label' => 'Déco et maison', 'icon' => 'ic-vase', 'profile' => 'décoration et objets pour la maison',
            'natures' => ['decoration', 'linge', 'arts_table', 'mobilier', 'luminaires'], 'words' => ['deco', 'decoration', 'maison', 'interieur', 'mobilier', 'meuble', 'luminaire', 'home']],
        'alimentaire' => ['short' => 'Alimentaire', 'label' => 'Alimentaire', 'icon' => 'ic-bowls', 'profile' => 'produits alimentaires et boissons',
            'natures' => ['alimentaire'], 'words' => ['pain', 'boulang', 'epicerie', 'alimentaire', 'gourmand', 'cave', 'vin', 'traiteur', 'fromage', 'chocolat', 'patisserie']],
        'tv_hifi' => ['short' => 'TV et hifi', 'label' => 'TV, hifi et audiovisuel', 'icon' => 'ic-pot', 'profile' => 'télévision, hifi et matériel audiovisuel',
            'natures' => ['tv_hifi', 'musique_electro'], 'words' => ['hifi', 'hi-fi', 'audio', 'tv', 'television', 'son', 'cinema', 'vinyle', 'platine']],
        'informatique' => ['short' => 'Informatique', 'label' => 'Informatique', 'icon' => 'ic-mirror', 'profile' => 'matériel informatique et accessoires',
            'natures' => ['informatique'], 'words' => ['informatique', 'ordinateur', 'ordi', 'pc', 'computer', 'tech', 'numerique', 'gaming']],
        'telephonie' => ['short' => 'Téléphonie', 'label' => 'Téléphonie et objets connectés', 'icon' => 'ic-mirror', 'profile' => 'téléphonie et objets connectés',
            'natures' => ['telephonie'], 'words' => ['telephone', 'smartphone', 'mobile', 'connecte', 'gsm']],
        'electromenager' => ['short' => 'Électroménager', 'label' => 'Électroménager', 'icon' => 'ic-stool', 'profile' => 'électroménager et équipement de la maison',
            'natures' => ['electromenager'], 'words' => ['electromenager', 'aspirateur']],
        'bijoux_beaute' => ['short' => 'Bijoux et beauté', 'label' => 'Bijoux, montres et beauté', 'icon' => 'ic-pendant', 'profile' => 'bijoux, montres et produits de beauté',
            'natures' => ['bijoux', 'beaute'], 'words' => ['bijou', 'montre', 'beaute', 'parfum', 'cosmetique', 'maquillage']],
        'brocante' => ['short' => 'Brocante', 'label' => 'Brocante, vintage et collection', 'icon' => 'ic-pitcher', 'profile' => 'brocante, objets vintage et de collection',
            'natures' => ['collection'], 'words' => ['brocante', 'vintage', 'antiquite', 'chine', 'collection', 'ancien', 'curiosite']],
        'livres_medias' => ['short' => 'Livres et médias', 'label' => 'Livres, musique et films', 'icon' => 'ic-candle', 'profile' => 'livres, disques, films et papeterie',
            'natures' => ['livres_medias'], 'words' => ['livre', 'librairie', 'disque', 'cd', 'dvd', 'bd', 'papeterie']],
        'jeux_jouets' => ['short' => 'Jeux et jouets', 'label' => 'Jeux et jouets', 'icon' => 'ic-cushion', 'profile' => 'jeux, jouets et jeux vidéo',
            'natures' => ['jeux'], 'words' => ['jouet', 'jeu', 'jeux', 'puzzle', 'peluche', 'console']],
        'bricolage_jardin' => ['short' => 'Bricolage et jardin', 'label' => 'Bricolage et jardin', 'icon' => 'ic-pot', 'profile' => 'outillage, bricolage et jardin',
            'natures' => ['outils_jardin'], 'words' => ['bricolage', 'outil', 'jardin', 'quincaillerie', 'jardinage']],
        'auto_moto' => ['short' => 'Auto et moto', 'label' => 'Auto et moto', 'icon' => 'ic-stool', 'profile' => 'pièces, accessoires et équipement auto et moto',
            'natures' => ['auto_moto'], 'words' => ['auto', 'moto', 'voiture', 'garage', 'scooter', 'pneu']],
        'bebe_enfant' => ['short' => 'Bébé et enfant', 'label' => 'Bébé et enfant', 'icon' => 'ic-cushion', 'profile' => 'articles pour bébés et enfants',
            'natures' => ['bebe'], 'words' => ['bebe', 'enfant', 'puericulture', 'naissance', 'kids']],
        'animaux' => ['short' => 'Animaux', 'label' => 'Animaux', 'icon' => 'ic-basket', 'profile' => 'accessoires et équipement pour animaux',
            'natures' => ['animaux'], 'words' => ['animal', 'animaux', 'chien', 'chat', 'aquarium']],
    ];
}

/** Clés de secteurs valides parmi $keys (tableau ou liste séparée par des virgules), dans l'ordre de shop_sectors(). */
function shop_sectors_clean($keys): array
{
    $keys = is_array($keys) ? $keys : explode(',', (string) $keys);
    return array_values(array_intersect(array_keys(shop_sectors()), array_map('trim', $keys)));
}

/** Secteur(s) qui se détachent du nom et de l'indication du vendeur : mots-clés reconnus (sans accents, sans casse). */
function shop_sectors_from_text(string $text): array
{
    $t = strtr(mb_strtolower($text), ['à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', '’' => ' ']);
    $tokens = preg_split('/[^a-z0-9-]+/', $t) ?: [];
    $found = [];
    foreach (shop_sectors() as $key => $sector) {
        foreach ($sector['words'] as $w) {
            $w = strtr(mb_strtolower($w), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'ç' => 'c', 'à' => 'a']);
            // Mot court (tv, pc, cd, bd, ski, auto…) : mot entier ; mot long : début de mot (« friperie » trouve « fripe »).
            $hit = strlen($w) <= 4 ? in_array($w, $tokens, true) : str_contains($t, $w);
            if ($hit) { $found[$key] = true; break; }
        }
    }
    return array_keys($found);
}

/** Secteurs dominants d'après la nature des pièces en vente : ceux qui regroupent au moins 25 % des pièces avec nature, trois au plus. */
function shop_sectors_from_natures(): array
{
    $byNature = [];
    foreach (db()->query("SELECT nature, COUNT(*) AS n FROM products WHERE nature IS NOT NULL AND nature != '' GROUP BY nature")->fetchAll() as $r) $byNature[$r['nature']] = (int) $r['n'];
    $total = array_sum($byNature);
    if (!$total) return [];
    $scores = [];
    foreach (shop_sectors() as $key => $sector) {
        foreach ($sector['natures'] as $nature) $scores[$key] = ($scores[$key] ?? 0) + ($byNature[$nature] ?? 0);
    }
    arsort($scores);
    $out = [];
    foreach ($scores as $key => $n) {
        if ($n > 0 && $n / $total >= 0.25 && count($out) < 3) $out[] = $key;
    }
    return $out;
}

/** Univers correspondant aux secteurs choisis : un par secteur, [['key' => …, 'label' => libellé court, 'icon' => …], …]. */
function universes_for_sectors(array $keys): array
{
    $sectors = shop_sectors();
    $out = [];
    foreach (shop_sectors_clean($keys) as $key) $out[] = ['key' => $key, 'label' => $sectors[$key]['short'], 'icon' => $sectors[$key]['icon']];
    return array_slice($out, 0, UNIVERSE_MAX);
}

/** Secteurs (clés) des univers actuels de la boutique : ceux dont la clé est celle d'un secteur. */
function shop_sectors_current(): array
{
    return shop_sectors_clean(array_column(category_list(), 'key'));
}

/** Liste des univers donnée à l'IA pour classer une pièce : « clé = libellé (ce que contient l'univers) ». */
function universes_prompt_list(): string
{
    $sectors = shop_sectors();
    return implode(' ; ', array_map(static fn (array $c): string => $c['key'] . ' = ' . $c['label'] . (isset($sectors[$c['key']]) ? ' (' . $sectors[$c['key']]['profile'] . ')' : ''), category_list()));
}

/** Clé d'univers de la boutique à partir d'une clé ou d'un libellé (insensible à la casse), ou chaîne vide. */
function universe_key_resolve(?string $value): string
{
    $wanted = mb_strtolower(trim((string) $value));
    if ($wanted === '') return '';
    foreach (category_list() as $c) {
        if ($wanted === mb_strtolower($c['key']) || $wanted === mb_strtolower($c['label'])) return $c['key'];
    }
    return '';
}

/** Univers de la boutique qui correspond à la nature d'un produit (vêtements → Mode), ou chaîne vide s'il n'y en a pas. */
function universe_key_for_nature(?string $nature): string
{
    $keys = array_column(category_list(), 'key');
    foreach (shop_sectors() as $key => $sector) {
        if (in_array((string) $nature, $sector['natures'], true) && in_array($key, $keys, true)) return $key;
    }
    return '';
}
