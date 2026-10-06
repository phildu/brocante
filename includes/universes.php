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
        . '{"sectors": ["clé", …], "profile": "ce que vend la boutique en une courte phrase, ex : friperie et mode vintage pour femme", '
        . '"universes": [{"label": "…", "icon": "ic-…"}]}. '
        . 'Dans « sectors », une à trois clés — les grands domaines de la boutique — parmi : ' . implode(' ; ', array_map(static fn ($k, $v) => $k . ' = ' . $v['label'], array_keys(shop_sectors()), shop_sectors())) . '. '
        . 'Entre 4 et 8 univers, libellés courts (1 à 3 mots), au pluriel de préférence, en français, qui couvrent l\'ensemble de ce que vend la boutique sans se chevaucher ; '
        . "pour chacun, le pictogramme le plus proche parmi : $icons — sans répéter le même pictogramme quand on peut l'éviter (ex. le plaid pour le textile et les vêtements, le miroir pour les bijoux et accessoires).";
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
    $out = [];
    foreach ((array) ($data['universes'] ?? []) as $item) {
        $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 40);
        $icon = (string) ($item['icon'] ?? '');
        if ($label !== '') $out[] = ['label' => $label, 'icon' => isset(UNIVERSE_ICONS[$icon]) ? $icon : 'ic-vase'];
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
    $sectors = array_slice($sectors, 0, 3);

    $profile = mb_substr(trim((string) ($data['profile'] ?? '')), 0, 120);
    if ($profile === '' && $sectors) $profile = mb_substr(implode(' ; ', array_map(static fn ($k) => shop_sectors()[$k]['profile'], $sectors)), 0, 120);
    if (!$out) $out = universes_for_sectors($sectors);
    if (!$out && !$sectors) return [];
    return [
        'profile' => $profile, 'universes' => array_slice($out, 0, 8), 'sectors' => $sectors, 'source' => $source,
        'seen' => ['photos' => count($smalls), 'names' => array_slice($names, 0, 4), 'natures' => array_slice($natures, 0, 4)],
    ];
}

// ── Secteurs : les grands domaines qu'une boutique peut vendre ──

/**
 * Secteurs d'activité proposés, chacun avec la phrase qui décrit la boutique (« profile »), les natures de produits qui y
 * mènent, des mots du nom ou de l'accroche qui le trahissent, et des univers (rayons) prêts à l'emploi. Une boutique peut
 * relever de plusieurs secteurs. Les pictogrammes sont ceux de UNIVERSE_ICONS.
 */
function shop_sectors(): array
{
    return [
        'mode' => ['label' => 'Mode et vêtements', 'profile' => 'mode et vêtements, neufs ou de seconde main',
            'natures' => ['vetements', 'chaussures', 'accessoires'], 'words' => ['fripe', 'friperie', 'mode', 'vetement', 'tissu', 'couture', 'habit', 'textile', 'boutique de vetements', 'prêt-à-porter', 'pret-a-porter', 'dressing'],
            'universes' => [['Hauts et t-shirts', 'ic-plaid'], ['Pulls et sweats', 'ic-cushion'], ['Pantalons et jeans', 'ic-plaid'], ['Robes et jupes', 'ic-pendant'], ['Vestes et manteaux', 'ic-stool'], ['Chaussures', 'ic-basket'], ['Sacs et accessoires', 'ic-mirror']]],
        'sport' => ['label' => 'Sport et loisirs', 'profile' => 'articles de sport et de loisirs',
            'natures' => ['sport'], 'words' => ['sport', 'velo', 'fitness', 'running', 'randonnee', 'outdoor', 'ski', 'football', 'tennis'],
            'universes' => [['Vêtements de sport', 'ic-plaid'], ['Chaussures de sport', 'ic-basket'], ['Vélos et glisse', 'ic-stool'], ['Fitness et musculation', 'ic-pot'], ['Sports d\'équipe', 'ic-bowls'], ['Plein air et camping', 'ic-candle']]],
        'deco' => ['label' => 'Déco et maison', 'profile' => 'décoration et objets pour la maison',
            'natures' => ['decoration', 'linge', 'arts_table', 'mobilier', 'luminaires'], 'words' => ['deco', 'decoration', 'maison', 'interieur', 'mobilier', 'meuble', 'luminaire', 'home'],
            'universes' => [['Vases et objets déco', 'ic-vase'], ['Cadres et miroirs', 'ic-mirror'], ['Luminaires', 'ic-pendant'], ['Linge et textiles', 'ic-plaid'], ['Arts de la table', 'ic-bowls'], ['Mobilier', 'ic-stool']]],
        'alimentaire' => ['label' => 'Alimentaire', 'profile' => 'produits alimentaires et boissons',
            'natures' => ['alimentaire'], 'words' => ['pain', 'boulang', 'epicerie', 'alimentaire', 'gourmand', 'cave', 'vin', 'traiteur', 'fromage', 'chocolat', 'patisserie'],
            'universes' => [['Épicerie salée', 'ic-pot'], ['Épicerie sucrée', 'ic-candle'], ['Boissons', 'ic-pitcher'], ['Pains et pâtisseries', 'ic-basket'], ['Produits du terroir', 'ic-bowls'], ['Coffrets cadeaux', 'ic-photophore']]],
        'tv_hifi' => ['label' => 'TV, hifi et audiovisuel', 'profile' => 'télévision, hifi et matériel audiovisuel',
            'natures' => ['tv_hifi', 'musique_electro'], 'words' => ['hifi', 'hi-fi', 'audio', 'tv', 'television', 'son', 'cinema', 'vinyle', 'platine'],
            'universes' => [['Télévisions', 'ic-mirror'], ['Hi-fi et enceintes', 'ic-pot'], ['Casques et écouteurs', 'ic-bowls'], ['Platines et radios', 'ic-pitcher'], ['Home cinéma', 'ic-pendant'], ['Câbles et accessoires', 'ic-basket']]],
        'informatique' => ['label' => 'Informatique', 'profile' => 'matériel informatique et accessoires',
            'natures' => ['informatique'], 'words' => ['informatique', 'ordinateur', 'ordi', 'pc', 'computer', 'tech', 'numerique', 'gaming'],
            'universes' => [['Ordinateurs portables', 'ic-mirror'], ['Ordinateurs fixes', 'ic-stool'], ['Écrans', 'ic-mirror'], ['Composants', 'ic-pot'], ['Périphériques', 'ic-bowls'], ['Réseau et stockage', 'ic-basket']]],
        'telephonie' => ['label' => 'Téléphonie et objets connectés', 'profile' => 'téléphonie et objets connectés',
            'natures' => ['telephonie'], 'words' => ['telephone', 'smartphone', 'mobile', 'connecte', 'gsm'],
            'universes' => [['Smartphones', 'ic-mirror'], ['Coques et protections', 'ic-cushion'], ['Chargeurs et câbles', 'ic-basket'], ['Montres connectées', 'ic-bowls'], ['Domotique', 'ic-pendant']]],
        'electromenager' => ['label' => 'Électroménager', 'profile' => 'électroménager et équipement de la maison',
            'natures' => ['electromenager'], 'words' => ['electromenager', 'cuisine electrique', 'aspirateur'],
            'universes' => [['Gros électroménager', 'ic-stool'], ['Petit électroménager', 'ic-pot'], ['Entretien et ménage', 'ic-basket'], ['Chauffage et ventilation', 'ic-candle']]],
        'bijoux_beaute' => ['label' => 'Bijoux, montres et beauté', 'profile' => 'bijoux, montres et produits de beauté',
            'natures' => ['bijoux', 'beaute'], 'words' => ['bijou', 'montre', 'beaute', 'parfum', 'cosmetique', 'maquillage'],
            'universes' => [['Colliers et pendentifs', 'ic-pendant'], ['Bracelets et bagues', 'ic-bowls'], ['Montres', 'ic-mirror'], ['Parfums', 'ic-pitcher'], ['Soins et maquillage', 'ic-photophore']]],
        'brocante' => ['label' => 'Brocante, vintage et collection', 'profile' => 'brocante, objets vintage et de collection',
            'natures' => ['collection'], 'words' => ['brocante', 'vintage', 'antiquite', 'chine', 'collection', 'ancien', 'curiosite'],
            'universes' => [['Céramique', 'ic-pitcher'], ['Bois et mobilier', 'ic-stool'], ['Textile', 'ic-plaid'], ['Lumière', 'ic-pendant'], ['Jardin', 'ic-pot'], ['Curiosités', 'ic-vase']]],
        'livres_medias' => ['label' => 'Livres, musique et films', 'profile' => 'livres, disques, films et papeterie',
            'natures' => ['livres_medias'], 'words' => ['livre', 'librairie', 'disque', 'cd', 'dvd', 'bd', 'papeterie'],
            'universes' => [['Romans et littérature', 'ic-candle'], ['BD et mangas', 'ic-photophore'], ['Livres jeunesse', 'ic-cushion'], ['Vinyles et CD', 'ic-bowls'], ['Papeterie', 'ic-basket']]],
        'jeux_jouets' => ['label' => 'Jeux et jouets', 'profile' => 'jeux, jouets et jeux vidéo',
            'natures' => ['jeux'], 'words' => ['jouet', 'jeu', 'jeux', 'puzzle', 'peluche', 'console'],
            'universes' => [['Jouets', 'ic-stool'], ['Poupées et peluches', 'ic-cushion'], ['Jeux de société', 'ic-bowls'], ['Jeux vidéo', 'ic-mirror'], ['Maquettes', 'ic-pot']]],
        'bricolage_jardin' => ['label' => 'Bricolage et jardin', 'profile' => 'outillage, bricolage et jardin',
            'natures' => ['outils_jardin'], 'words' => ['bricolage', 'outil', 'jardin', 'quincaillerie', 'jardinage'],
            'universes' => [['Outils à main', 'ic-stool'], ['Outillage électrique', 'ic-pot'], ['Quincaillerie', 'ic-basket'], ['Jardinage', 'ic-vase'], ['Rangement et malles', 'ic-bowls']]],
        'auto_moto' => ['label' => 'Auto et moto', 'profile' => 'pièces, accessoires et équipement auto et moto',
            'natures' => ['auto_moto'], 'words' => ['auto', 'moto', 'voiture', 'garage', 'scooter', 'pneu'],
            'universes' => [['Pièces auto', 'ic-pot'], ['Accessoires auto', 'ic-basket'], ['Moto et scooter', 'ic-stool'], ['Équipement du motard', 'ic-cushion'], ['Outillage garage', 'ic-bowls']]],
        'bebe_enfant' => ['label' => 'Bébé et enfant', 'profile' => 'articles pour bébés et enfants',
            'natures' => ['bebe'], 'words' => ['bebe', 'enfant', 'puericulture', 'naissance', 'kids'],
            'universes' => [['Vêtements bébé et enfant', 'ic-plaid'], ['Poussettes et sièges', 'ic-stool'], ['Jouets d\'éveil', 'ic-cushion'], ['Chambre et déco', 'ic-candle'], ['Repas et soins', 'ic-bowls']]],
        'animaux' => ['label' => 'Animaux', 'profile' => 'accessoires et équipement pour animaux',
            'natures' => ['animaux'], 'words' => ['animal', 'animaux', 'chien', 'chat', 'aquarium'],
            'universes' => [['Chiens', 'ic-basket'], ['Chats', 'ic-cushion'], ['Aquariophilie', 'ic-vase'], ['Rongeurs et oiseaux', 'ic-bowls']]],
    ];
}

/** Clés de secteurs valides parmi $keys (tableau ou liste séparée par des virgules), dans l'ordre de shop_sectors(). */
function shop_sectors_clean($keys): array
{
    $keys = is_array($keys) ? $keys : explode(',', (string) $keys);
    return array_values(array_intersect(array_keys(shop_sectors()), array_map('trim', $keys)));
}

/** Secteurs enregistrés pour ce commerce. */
function shop_sectors_saved(): array
{
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'shop_sectors'");
        $stmt->execute();
        return shop_sectors_clean((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return [];
    }
}

function shop_sectors_save(array $keys): void
{
    $keys = shop_sectors_clean($keys);
    if (!$keys) db()->prepare("DELETE FROM settings WHERE name = 'shop_sectors'")->execute();
    else db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('shop_sectors', ?)")->execute([implode(',', $keys)]);
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

/** Univers prêts à l'emploi des secteurs choisis : [['label' => …, 'icon' => …], …], sans doublon, UNIVERSE_MAX au plus. */
function universes_for_sectors(array $keys): array
{
    $sectors = shop_sectors();
    $out = [];
    $seen = [];
    foreach (shop_sectors_clean($keys) as $key) {
        foreach ($sectors[$key]['universes'] as [$label, $icon]) {
            if (isset($seen[mb_strtolower($label)])) continue;
            $seen[mb_strtolower($label)] = true;
            $out[] = ['label' => $label, 'icon' => $icon];
        }
    }
    return array_slice($out, 0, UNIVERSE_MAX);
}
