<?php

// Types de galerie : chaque type est un produit à part, avec son vocabulaire (commerces, exposants, marchands…), ses fonctionnalités et ses
// offres (prix, durée d'essai, nombre de membres). Six types d'origine, tous modifiables dans le portail (Types de galerie) :
//   marchande         Galerie marchande : des commerces regroupés sur une page commune (comportement historique) ;
//   puces             Marché aux puces : des marchands installés sur des emplacements numérotés, avec tarifs par zone ;
//   vide-grenier      Vide-grenier : des exposants qui choisissent un emplacement sur le plan et le demandent en ligne ;
//   quartier          Quartier marchand : les commerçants d'une rue, d'un quartier ou d'un village réunis (nom proposé, modifiable) ;
//   marche-de-noel    Marché de Noël : des chalets numérotés, des allées, une page publique éphémère et une identité saisonnière ;
//   marche-producteurs Marché des producteurs et créateurs : des producteurs locaux, artisans et créateurs sur des stands.
// Réglages : data/saas/galeries-types.json (uniquement ce qui diffère des valeurs d'origine). Le type d'une galerie est la clé « type » de
// data/galeries/<id>.json ; une galerie sans type est une galerie marchande.

require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/gallery-offers.php';
require_once __DIR__ . '/gallery-modules.php';

const GT_DEFAULT = 'marchande';
/** Page de présentation (landing) de chaque type d'origine : adresse, couleur et accroche. Une galerie personnalisée n'en a pas. */
const GT_LANDING_META = [
    'marchande' => ['/galerie-marchande/', '#6a4a86', 'Une seule adresse pour les commerces qui se recommandent entre eux.'],
    'puces' => ['/marche-aux-puces/', '#2f6b4f', 'Vos emplacements numérotés, vos tarifs par zone, vos marchands présentés.'],
    'vide-grenier' => ['/vide-greniers/', '#b5502e', 'Le vide-grenier se prépare en ligne, pas au téléphone.'],
    'quartier' => ['/quartier-marchand/', '#3b5fa8', 'La rue, le quartier ou le village : une adresse qui rassemble ses commerces.'],
    'marche-de-noel' => ['/marche-de-noel/', '#a3333d', 'Un marché de Noël qui se réserve en ligne, pas au téléphone.'],
    'marche-producteurs' => ['/marche-producteurs/', '#7a6a2f', 'Les producteurs et créateurs du territoire, réunis sur une même place.'],
];
const GT_FEATURES = [
    'plan' => 'Plan d\'implantation (carte, zones, emplacements)',
    'tarifs' => 'Tarifs par zone',
    'exposants' => 'Demandes d\'emplacement des exposants',
    'prospects' => 'Prospects et relances de commerçants',
    'tableau' => 'Tableau de bord de fréquentation',
    'abonnes' => 'Abonnés aux nouveautés',
    'horaires' => 'Horaires d\'ouverture, fermetures exceptionnelles et alerte aux visiteurs',
    'fidelite' => 'Espace visiteur et fidélité de la galerie (web app, QR codes)',
    'bourse' => 'Bourse d\'échanges (propositions de vente et recherches d\'achat)',
    'messages' => 'Messagerie interne (exposants et contacts)',
];
const GT_VOCAB_KEYS = [
    'gallery' => 'La galerie (singulier)', 'galleries' => 'Les galeries (pluriel)', 'member' => 'Un membre (singulier)', 'members' => 'Les membres (pluriel)',
    'place' => 'Un lieu de vente (singulier)', 'places' => 'Les lieux de vente (pluriel)',
    'members_title' => 'Titre de la liste des membres', 'members_lede' => 'Phrase sous ce titre', 'plan_of' => 'Plan « … » (complément)', 'join_cta' => 'Bouton de rejoindre',
    'create_cta' => 'Bouton de création', 'create_title' => 'Titre de la page de création', 'create_intro' => 'Introduction de la page de création', 'empty' => 'Message galerie vide',
];

/** Unités de tarification d'un lieu de vente (mêmes clés que le plan d'implantation). */
const GT_LOCATION_UNITS = ['slot' => 'par emplacement', 'ml' => 'par mètre linéaire', 'm2' => 'par m²'];

/** Rôle d'un plugin (module payant des boutiques) pour les vendeurs d'un type de galerie. */
const GT_VENDOR_PLUGIN_MODES = ['inherit' => 'Selon la plateforme', 'available' => 'Proposé aux vendeurs', 'included' => 'Inclus pour les vendeurs', 'unavailable' => 'Masqué aux vendeurs'];

/** Libellés des anciens types de zone (plans enregistrés avant les lieux configurables). */
const GT_LEGACY_LOCATION_LABELS = ['rue' => 'Rue', 'hall' => 'Hall', 'allee' => 'Allée', 'cour' => 'Cour', 'parking' => 'Parking', 'quartier' => 'Quartier', 'autre' => 'Autre lieu'];

/** Offres d'un type : mêmes clés (test, essentiel, pro) que partout, valeurs propres au type. */
function gt_offers_for(array $over): array
{
    $out = [];
    foreach (go_defaults() as $d) $out[$d['key']] = array_merge($d, (array) ($over[$d['key']] ?? []));
    return $out;
}

/**
 * Lieux de vente d'origine : le type de lieu (boutique, stand, emplacement…) que l'on choisit pour une zone du plan, avec son unité de
 * tarification et un prix indicatif (0 = à fixer). Les clés historiques du plan (rue, hall, allée…) restent acceptées dans tous les types.
 */
function gt_default_locations(): array
{
    return [
        'marchande' => [
            ['key' => 'boutique', 'label' => 'Boutique', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'atelier', 'label' => 'Atelier', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'stand', 'label' => 'Stand', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'rue', 'label' => 'Rue', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0],
        ],
        'puces' => [
            ['key' => 'stand', 'label' => 'Stand', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'emplacement', 'label' => 'Emplacement', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'hall', 'label' => 'Hall', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'cour', 'label' => 'Cour', 'unit' => 'm2', 'price_cents' => 0],
            ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0],
        ],
        'vide-grenier' => [
            ['key' => 'emplacement', 'label' => 'Emplacement', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'stand', 'label' => 'Stand', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'rue', 'label' => 'Rue', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'allee', 'label' => 'Allée', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0],
        ],
        'quartier' => [
            ['key' => 'boutique', 'label' => 'Boutique', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'vitrine', 'label' => 'Vitrine', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'rue', 'label' => 'Rue', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0],
        ],
        'marche-de-noel' => [
            ['key' => 'chalet', 'label' => 'Chalet', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'stand', 'label' => 'Stand', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'allee', 'label' => 'Allée', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'esplanade', 'label' => 'Esplanade', 'unit' => 'm2', 'price_cents' => 0],
            ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0],
        ],
        'marche-producteurs' => [
            ['key' => 'stand', 'label' => 'Stand', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'etal', 'label' => 'Étal', 'unit' => 'ml', 'price_cents' => 0],
            ['key' => 'emplacement', 'label' => 'Emplacement', 'unit' => 'slot', 'price_cents' => 0],
            ['key' => 'halle', 'label' => 'Halle', 'unit' => 'm2', 'price_cents' => 0],
            ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0],
        ],
    ];
}

function gt_defaults(): array
{
    $all = static function (array $on): array { $f = []; foreach (array_keys(GT_FEATURES) as $k) $f[$k] = in_array($k, $on, true); return $f; };
    $loc = gt_default_locations();
    return [
        'marchande' => [
            'name' => 'Galerie marchande', 'emoji' => '🏬', 'active' => true, 'order' => 1,
            'tagline' => 'Des commerces réunis sur une même page : leurs pièces, leurs boutiques, leur plan.',
            'vocab' => ['gallery' => 'galerie commerciale', 'galleries' => 'galeries commerciales', 'member' => 'commerce', 'members' => 'commerces', 'place' => 'lieu de vente', 'places' => 'lieux de vente', 'members_title' => 'Les commerces',
                'members_lede' => 'Chaque commerce a sa boutique, son panier et son paiement : une pièce se règle chez son commerçant.', 'plan_of' => 'des commerces', 'join_cta' => 'Votre boutique ici',
                'create_cta' => 'Créer ma galerie', 'create_title' => 'Créez votre galerie commerciale', 'create_intro' => 'Réunissez plusieurs commerces sur une page commune, avec un annuaire, des filtres, vos couleurs et un tableau de bord.', 'empty' => "Cette galerie n'a pas encore de commerce."],
            'features' => $all(['plan', 'prospects', 'tableau', 'abonnes', 'horaires', 'fidelite', 'bourse', 'messages']),
            'locations' => $loc['marchande'],
            'offers' => gt_offers_for([]),
        ],
        'puces' => [
            'name' => 'Marché aux puces', 'emoji' => '🧺', 'active' => true, 'order' => 2,
            'tagline' => 'Des marchands sur des emplacements numérotés, avec un plan et des tarifs par zone.',
            'vocab' => ['gallery' => 'marché aux puces', 'galleries' => 'marchés aux puces', 'member' => 'marchand', 'members' => 'marchands', 'place' => 'emplacement', 'places' => 'emplacements', 'members_title' => 'Les marchands',
                'members_lede' => 'Retrouvez chaque marchand et ses trouvailles : une pièce se règle chez son marchand.', 'plan_of' => 'du marché', 'join_cta' => 'Devenir marchand',
                'create_cta' => 'Créer mon marché aux puces', 'create_title' => 'Créez votre marché aux puces', 'create_intro' => 'Dessinez le plan de votre marché, numérotez les emplacements, fixez les tarifs par zone et présentez vos marchands.', 'empty' => "Ce marché n'a pas encore de marchand."],
            'features' => $all(['plan', 'tarifs', 'prospects', 'tableau', 'abonnes', 'horaires', 'fidelite', 'bourse', 'messages']),
            'locations' => $loc['puces'],
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour essayer votre marché sans engagement', 'max_members' => 5],
                'essentiel' => ['tagline' => 'Pour un marché de quartier ou de village', 'month_cents' => 3900, 'year_cents' => 39000, 'max_members' => 25],
                'pro' => ['tagline' => 'Pour un grand marché ou une halle', 'month_cents' => 9900, 'year_cents' => 99000, 'max_members' => 80],
            ]),
        ],
        'vide-grenier' => [
            'name' => 'Vide-grenier', 'emoji' => '🪑', 'active' => true, 'order' => 3,
            'tagline' => 'Les exposants choisissent leur emplacement sur le plan et le demandent en ligne.',
            'vocab' => ['gallery' => 'vide-grenier', 'galleries' => 'vides-greniers', 'member' => 'exposant', 'members' => 'exposants', 'place' => 'emplacement', 'places' => 'emplacements', 'members_title' => 'Les exposants',
                'members_lede' => 'Retrouvez les exposants du vide-grenier et leurs boutiques en ligne.', 'plan_of' => 'du vide-grenier', 'join_cta' => 'Exposer ici',
                'create_cta' => 'Organiser mon vide-grenier', 'create_title' => 'Organisez votre vide-grenier', 'create_intro' => 'Un plan en ligne, des tarifs par zone (rue, hall…), et des exposants qui demandent leur emplacement : vous validez en un clic.', 'empty' => "Ce vide-grenier n'a pas encore d'exposant."],
            'features' => $all(['plan', 'tarifs', 'exposants', 'tableau', 'abonnes', 'horaires', 'fidelite', 'bourse', 'messages']),
            'locations' => $loc['vide-grenier'],
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour préparer votre prochain vide-grenier', 'max_members' => 10],
                'essentiel' => ['tagline' => 'Pour un vide-grenier de village ou de quartier', 'month_cents' => 1900, 'year_cents' => 19000, 'max_members' => 40],
                'pro' => ['tagline' => 'Pour une grande braderie ou plusieurs événements', 'month_cents' => 4900, 'year_cents' => 49000, 'max_members' => 150],
            ]),
        ],
        'quartier' => [
            'name' => 'Quartier marchand', 'emoji' => '🏘️', 'active' => true, 'order' => 4,
            'tagline' => 'Les commerçants d\'une rue, d\'un quartier ou d\'un village réunis sous une même enseigne.',
            'vocab' => ['gallery' => 'quartier marchand', 'galleries' => 'quartiers marchands', 'member' => 'commerçant', 'members' => 'commerçants', 'place' => 'boutique', 'places' => 'boutiques', 'members_title' => 'Les commerçants du quartier',
                'members_lede' => 'Les boutiques de la rue, du quartier ou du village : chacune garde son panier et son paiement.', 'plan_of' => 'du quartier', 'join_cta' => 'Rejoindre le quartier',
                'create_cta' => 'Créer mon quartier marchand', 'create_title' => 'Créez votre quartier marchand', 'create_intro' => 'Réunissez les commerçants d\'une rue, d\'un quartier ou d\'un village : annuaire, plan des boutiques, promotions communes et lettre d\'information.', 'empty' => "Ce quartier n'a pas encore de commerçant."],
            'features' => $all(['plan', 'prospects', 'tableau', 'abonnes', 'horaires', 'fidelite', 'bourse', 'messages']),
            'locations' => $loc['quartier'],
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour réunir vos premiers commerçants', 'max_members' => 5],
                'essentiel' => ['tagline' => 'Pour une rue ou un petit village', 'month_cents' => 2500, 'year_cents' => 25000, 'max_members' => 15],
                'pro' => ['tagline' => 'Pour un quartier ou une grande commune', 'month_cents' => 6900, 'year_cents' => 69000, 'max_members' => 50],
            ]),
        ],
        'marche-de-noel' => [
            'name' => 'Marché de Noël', 'emoji' => '🎄', 'active' => true, 'order' => 5,
            'tagline' => 'Des chalets numérotés et des exposants qui réservent leur place en ligne.',
            'vocab' => ['gallery' => 'marché de Noël', 'galleries' => 'marchés de Noël', 'member' => 'exposant', 'members' => 'exposants', 'place' => 'chalet', 'places' => 'chalets', 'members_title' => 'Les exposants',
                'members_lede' => 'Retrouvez les exposants du marché de Noël et leurs boutiques en ligne.', 'plan_of' => 'du marché de Noël', 'join_cta' => 'Réserver un chalet',
                'create_cta' => 'Créer mon marché de Noël', 'create_title' => 'Créez votre marché de Noël', 'create_intro' => 'Dessinez les allées, numérotez les chalets, fixez les tarifs par zone et ouvrez les réservations en ligne pour votre marché de Noël.', 'empty' => "Ce marché de Noël n'a pas encore d'exposant."],
            'features' => $all(['plan', 'tarifs', 'exposants', 'tableau', 'abonnes', 'horaires', 'fidelite', 'bourse', 'messages']),
            'locations' => $loc['marche-de-noel'],
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour préparer votre marché de Noël', 'max_members' => 5],
                'essentiel' => ['tagline' => 'Pour un marché de Noël de village ou de quartier', 'month_cents' => 2900, 'year_cents' => 29000, 'max_members' => 35],
                'pro' => ['tagline' => 'Pour un grand marché de Noël ou une place centrale', 'month_cents' => 7900, 'year_cents' => 79000, 'max_members' => 120],
            ]),
        ],
        'marche-producteurs' => [
            'name' => 'Marché des producteurs et créateurs', 'emoji' => '🌾', 'active' => true, 'order' => 6,
            'tagline' => 'Producteurs locaux, artisans et créateurs réunis sur un marché de proximité.',
            'vocab' => ['gallery' => 'marché des producteurs et créateurs', 'galleries' => 'marchés des producteurs et créateurs', 'member' => 'producteur ou créateur', 'members' => 'producteurs et créateurs', 'place' => 'stand', 'places' => 'stands', 'members_title' => 'Les producteurs et créateurs',
                'members_lede' => 'Chaque stand présente ses produits ou ses créations ; une pièce se règle chez son exposant.', 'plan_of' => 'du marché', 'join_cta' => 'Réserver un stand',
                'create_cta' => 'Créer mon marché', 'create_title' => 'Créez votre marché des producteurs et créateurs', 'create_intro' => 'Réunissez les producteurs locaux, artisans et créateurs : plan des stands, tarifs par zone et réservations en ligne.', 'empty' => "Ce marché n'a pas encore de producteur ou de créateur."],
            'features' => $all(['plan', 'tarifs', 'exposants', 'tableau', 'abonnes', 'horaires', 'fidelite', 'bourse', 'messages']),
            'locations' => $loc['marche-producteurs'],
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour réunir vos premiers producteurs', 'max_members' => 5],
                'essentiel' => ['tagline' => 'Pour un marché de village ou de quartier', 'month_cents' => 2500, 'year_cents' => 25000, 'max_members' => 30],
                'pro' => ['tagline' => 'Pour un marché hebdomadaire ou une halle', 'month_cents' => 6900, 'year_cents' => 69000, 'max_members' => 90],
            ]),
        ],
    ];
}

function gt_file(): string
{
    return saas_data_dir() . '/saas/galeries-types.json';
}

function gt_clean_key(string $k): string
{
    return preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $k) ? $k : '';
}

/** Liste de lieux de vente normalisée : clés uniques, libellés courts, unité et prix sûrs ; la liste d'origine sert de repli si tout est vide. */
function gt_locations_normalize(array $list, array $fallback = []): array
{
    $out = [];
    foreach ($list as $loc) {
        if (!is_array($loc)) continue;
        $key = strtolower(trim((string) ($loc['key'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/', $key) || isset($out[$key])) continue;
        $out[$key] = [
            'key' => $key,
            'label' => mb_substr(trim((string) ($loc['label'] ?? '')), 0, 40) ?: ucfirst($key),
            'unit' => isset(GT_LOCATION_UNITS[(string) ($loc['unit'] ?? '')]) ? (string) $loc['unit'] : 'slot',
            'price_cents' => max(0, min(1000000, (int) ($loc['price_cents'] ?? 0))),
            'price_pro_cents' => max(0, min(1000000, (int) ($loc['price_pro_cents'] ?? 0))),
        ];
        if (count($out) >= 20) break;
    }
    if ($out) {
        if (!isset($out['autre'])) $out['autre'] = ['key' => 'autre', 'label' => 'Autre lieu', 'unit' => 'slot', 'price_cents' => 0, 'price_pro_cents' => 0];
        return array_values($out);
    }
    $clean = [];
    foreach ($fallback as $loc) {
        if (!is_array($loc) || !isset($loc['key'])) continue;
        $key = (string) $loc['key'];
        $clean[$key] = ['key' => $key, 'label' => (string) ($loc['label'] ?? ucfirst($key)),
            'unit' => isset(GT_LOCATION_UNITS[(string) ($loc['unit'] ?? '')]) ? (string) $loc['unit'] : 'slot',
            'price_cents' => max(0, (int) ($loc['price_cents'] ?? 0)), 'price_pro_cents' => max(0, (int) ($loc['price_pro_cents'] ?? 0))];
    }
    return array_values($clean);
}

/** Rôles de plugins vendeurs enregistrés (seuls les rôles explicites sont gardés). */
function gt_vendor_plugins_normalize(array $map): array
{
    $known = function_exists('addon_defaults') ? array_keys(addon_defaults()) : null;
    $out = [];
    foreach ($map as $id => $mode) {
        $id = strtolower(trim((string) $id));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $id) || ($known !== null && !in_array($id, $known, true))
            || !in_array($mode, array_keys(GT_VENDOR_PLUGIN_MODES), true) || $mode === 'inherit') continue;
        $out[$id] = (string) $mode;
    }
    return $out;
}

/** Un type normalisé : valeurs enregistrées par-dessus les valeurs d'origine ($base). */
function gt_normalize(string $key, array $saved, array $base): array
{
    $txt = static fn ($v, int $max, string $d): string => mb_substr(trim((string) ($v ?? '')), 0, $max) ?: $d;
    $vocab = [];
    foreach (GT_VOCAB_KEYS as $vk => $_) $vocab[$vk] = $txt(($saved['vocab'] ?? [])[$vk] ?? null, $vk === 'members_lede' || $vk === 'create_intro' ? 300 : 80, (string) ($base['vocab'][$vk] ?? ''));
    $features = [];
    foreach (GT_FEATURES as $fk => $_) $features[$fk] = array_key_exists($fk, (array) ($saved['features'] ?? [])) ? !empty($saved['features'][$fk]) : !empty($base['features'][$fk]);
    $offers = [];
    foreach (go_defaults() as $d) {
        $o = array_merge($d, (array) ($base['offers'][$d['key']] ?? []));
        $offers[$d['key']] = go_normalize((array) (($saved['offers'] ?? [])[$d['key']] ?? []), $o);
    }
    $modules = [];
    foreach (gm_modules() as $mk => $_) {
        $mode = (string) (($saved['modules'] ?? [])[$mk] ?? '');
        $modules[$mk] = in_array($mode, GM_MODES, true) ? $mode : gm_default_type_mode($key, $mk);
    }
    $baseLocations = (array) ($base['locations'] ?? (gt_default_locations()[$key] ?? []));
    $locations = array_key_exists('locations', $saved) ? gt_locations_normalize((array) $saved['locations'], $baseLocations) : gt_locations_normalize($baseLocations);
    $vendorPlugins = gt_vendor_plugins_normalize((array) ($saved['vendor_plugins'] ?? ($base['vendor_plugins'] ?? [])));
    return ['key' => $key, 'name' => $txt($saved['name'] ?? null, 40, (string) $base['name']), 'emoji' => $txt($saved['emoji'] ?? null, 8, (string) ($base['emoji'] ?? '🏬')),
        'tagline' => $txt($saved['tagline'] ?? null, 160, (string) ($base['tagline'] ?? '')), 'active' => array_key_exists('active', $saved) ? !empty($saved['active']) : !empty($base['active']),
        'order' => (int) ($saved['order'] ?? $base['order'] ?? 50), 'vocab' => $vocab, 'features' => $features, 'offers' => $offers,
        'locations' => $locations, 'vendor_plugins' => $vendorPlugins,
        'modules' => $modules, 'custom' => !empty($base['custom'])];
}

/** Tous les types (d'origine et personnalisés), triés. cle => type. */
function gt_types(): array
{
    $saved = is_file(gt_file()) ? (json_decode((string) file_get_contents(gt_file()), true) ?: []) : [];
    $defaults = gt_defaults();
    // Historique : les offres réglées avant les types appartiennent à la galerie marchande.
    $legacy = is_file(go_file()) ? (json_decode((string) file_get_contents(go_file()), true) ?: []) : [];
    if ($legacy && empty($saved['marchande']['offers'])) $saved['marchande']['offers'] = $legacy;
    $out = [];
    foreach ($defaults as $k => $base) $out[$k] = gt_normalize($k, (array) ($saved[$k] ?? []), $base);
    foreach ($saved as $k => $s) {
        if (isset($defaults[$k]) || !gt_clean_key((string) $k) || !is_array($s)) continue;
        $out[$k] = gt_normalize((string) $k, $s, ['name' => (string) ($s['name'] ?? $k), 'emoji' => '🏬', 'active' => true, 'order' => 50, 'custom' => true] + $defaults[GT_DEFAULT]);
    }
    uasort($out, static fn ($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
    return $out;
}

function gt_type(?string $key): array
{
    $t = gt_types();
    return $t[(string) $key] ?? $t[GT_DEFAULT];
}

/** Type d'une galerie (marchande si elle n'en a pas). */
function gt_of(array $gallery): array
{
    return gt_type((string) ($gallery['type'] ?? GT_DEFAULT));
}

/** Types proposés à la vente (actifs). */
function gt_active(): array
{
    return array_filter(gt_types(), static fn ($t) => $t['active']);
}

/** Adresse de la page de présentation d'un type ('' si le type n'en a pas : galerie personnalisée ou page déplacée). */
function gt_landing_path(string $typeKey): string
{
    return (string) (GT_LANDING_META[$typeKey][0] ?? '');
}

/** Couleur et accroche de la page de présentation d'un type : ['path' => …, 'accent' => …, 'headline' => …]. */
function gt_landing_meta(string $typeKey): array
{
    $meta = GT_LANDING_META[$typeKey] ?? null;
    return $meta ? ['path' => $meta[0], 'accent' => $meta[1], 'headline' => $meta[2]] : [];
}

/**
 * Aperçu de la page de présentation d'un type : bouton « Aperçu » et panneau (vignette, accroche, description, lien).
 * À placer dans un conteneur .lp-hover ; les pages appellent gt_landing_preview_assets() une fois. '' si le type n'a pas de page.
 */
function gt_landing_preview_html(string $typeKey, string $description = '', string $label = 'Aperçu de la page'): string
{
    $meta = gt_landing_meta($typeKey);
    if (!$meta) return '';
    $type = gt_type($typeKey);
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $desc = trim($description) !== '' ? $description : (string) $type['tagline'];
    return '<button class="lp-peek" type="button" aria-expanded="false">' . $e($label) . '</button>'
        . '<div class="lp-pop" role="tooltip">'
        . '<div class="lp-pop-mock" style="--lp:' . $e($meta['accent']) . '">'
        . '<span class="lp-pop-band"></span><strong>' . $e($type['name']) . '</strong><i></i><i class="short"></i>'
        . '<span class="lp-pop-cards"><b></b><b></b><b></b></span>'
        . '<span class="lp-pop-cta">' . $e($type['vocab']['create_cta']) . '</span></div>'
        . '<p class="lp-pop-head">' . $e($meta['headline']) . '</p>'
        . '<p class="lp-pop-desc">' . $e(mb_strimwidth($desc, 0, 180, '…')) . '</p>'
        . '<a class="lp-pop-link" href="' . $e($meta['path']) . '">Visiter la page de présentation ↗</a>'
        . '</div>';
}

/** Styles et script de l'aperçu : une fois par page, avant </body>. */
function gt_landing_preview_assets(): string
{
    return '<style>' . gt_landing_preview_css() . '</style>' . "\n" . '<script>' . gt_landing_preview_js() . '</script>';
}

function gt_landing_preview_css(): string
{
    return <<<'CSS'
.lp-hover { position: relative; }
.lp-hover:hover, .lp-hover:focus-within, .lp-hover.is-open { z-index: 20; }
.lp-peek { position: absolute; top: 8px; right: 8px; z-index: 14; padding: 5px 11px; border: 1px solid var(--line); border-radius: 999px; background: var(--surface); color: var(--ink-soft); font: inherit; font-size: .74rem; font-weight: 700; line-height: 1.2; cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,.10); }
.lp-peek:hover, .lp-peek:focus-visible, .lp-hover.is-open .lp-peek { border-color: var(--accent); color: var(--accent); }
.lp-pop { position: absolute; top: calc(100% + 8px); left: 0; right: 0; z-index: 15; display: grid; gap: 8px; padding: 12px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: 0 22px 44px -20px rgba(0,0,0,.5); opacity: 0; visibility: hidden; transform: translateY(-4px); transition: opacity .16s, transform .16s, visibility .16s; pointer-events: none; text-align: left; }
.lp-hover:hover .lp-pop, .lp-hover.is-open .lp-pop, .lp-hover:has(> a:focus-visible) .lp-pop, .lp-hover:has(.lp-peek:focus-visible) .lp-pop, .lp-hover:has(.lp-pop a:focus-visible) .lp-pop { opacity: 1; visibility: visible; transform: none; pointer-events: auto; }
.lp-pop-mock { display: grid; gap: 6px; padding: 10px; border-radius: 8px; background: color-mix(in srgb, var(--lp) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--lp) 30%, var(--surface)); }
.lp-pop-band { display: block; width: 30px; height: 6px; border-radius: 3px; background: var(--lp); }
.lp-pop-mock strong { font-family: var(--display); font-size: 1.05rem; line-height: 1.1; color: var(--lp); }
.lp-pop-mock i { display: block; width: 76%; height: 4px; border-radius: 2px; background: rgba(0,0,0,.16); }
.lp-pop-mock i.short { width: 46%; }
.lp-pop-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; margin-top: 2px; }
.lp-pop-cards b { display: block; height: 24px; border-radius: 4px; background: var(--surface); border: 1px solid rgba(0,0,0,.12); }
.lp-pop-cta { justify-self: start; margin-top: 2px; padding: 3px 9px; border-radius: 999px; background: var(--lp); color: #fff; font-size: .68rem; font-weight: 700; }
.lp-pop-head { margin: 0; font-size: .86rem; font-weight: 700; line-height: 1.3; }
.lp-pop-desc { margin: 0; font-size: .82rem; line-height: 1.45; color: var(--ink-soft); }
.lp-pop-link { font-size: .84rem; font-weight: 700; color: var(--accent); text-decoration: none; }
.lp-pop-link:hover { text-decoration: underline; }
@media (hover: none) { .lp-hover:not(.is-open) .lp-pop { opacity: 0; visibility: hidden; transform: translateY(-4px); pointer-events: none; } }
@media (prefers-reduced-motion: reduce) { .lp-pop { transition: none; } }
CSS;
}

function gt_landing_preview_js(): string
{
    return <<<'JS'
(function () {
  function close(w) { w.classList.remove('is-open'); var b = w.querySelector('.lp-peek'); if (b) b.setAttribute('aria-expanded', 'false'); }
  document.querySelectorAll('.lp-peek').forEach(function (btn) {
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var w = btn.closest('.lp-hover');
      if (!w) return;
      var open = !w.classList.contains('is-open');
      document.querySelectorAll('.lp-hover.is-open').forEach(function (o) { if (o !== w) close(o); });
      w.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('.lp-hover.is-open').forEach(function (w) { if (!w.contains(ev.target)) close(w); });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') document.querySelectorAll('.lp-hover.is-open').forEach(close);
  });
})();
JS;
}

/** Mot du vocabulaire d'une galerie ou d'un type : gt_word($gallery, 'members'). */
function gt_word(array $galleryOrType, string $key): string
{
    $t = isset($galleryOrType['vocab']) ? $galleryOrType : gt_of($galleryOrType);
    return (string) ($t['vocab'][$key] ?? '');
}

function gt_feature(array $gallery, string $key): bool
{
    return !empty(gt_of($gallery)['features'][$key]);
}

/** Lieux de vente d'une galerie (ou d'un type) : liste de ['key','label','unit','price_cents','price_pro_cents']. */
function gt_locations(array $galleryOrType): array
{
    $t = isset($galleryOrType['locations']) ? $galleryOrType : gt_of($galleryOrType);
    return is_array($t['locations'] ?? null) ? $t['locations'] : gt_locations_normalize(gt_default_locations()[(string) ($t['key'] ?? '')] ?? []);
}

/** Lieux de vente indexés par clé. */
function gt_location_map(array $galleryOrType): array
{
    $out = [];
    foreach (gt_locations($galleryOrType) as $loc) $out[(string) $loc['key']] = $loc;
    return $out;
}

/** Libellé d'un lieu (« Boutique », « Stand »…), ou la clé brute pour un ancien type de zone. */
function gt_location_label(array $galleryOrType, string $key): string
{
    return (string) (gt_location_map($galleryOrType)[$key]['label'] ?? GT_LEGACY_LOCATION_LABELS[$key] ?? ucfirst($key));
}

/** Rôle d'un plugin vendeur pour ce type : inherit, available, included ou unavailable. */
function gt_vendor_plugin_mode(string $typeKey, string $module): string
{
    $mode = (string) (gt_type($typeKey)['vendor_plugins'][$module] ?? 'inherit');
    return in_array($mode, array_keys(GT_VENDOR_PLUGIN_MODES), true) ? $mode : 'inherit';
}

/** Enregistre les réglages d'un type (formulaire du portail). -> message d'erreur ou null. */
function gt_save(string $key, array $post, bool $create = false): ?string
{
    $key = gt_clean_key($key);
    if ($key === '') return 'Identifiant de type invalide (2 à 24 lettres minuscules, chiffres ou tirets).';
    $types = gt_types();
    if ($create && isset($types[$key])) return 'Ce type existe déjà.';
    if (!$create && !isset($types[$key])) return 'Type inconnu.';
    $saved = is_file(gt_file()) ? (json_decode((string) file_get_contents(gt_file()), true) ?: []) : [];
    $entry = ['name' => trim((string) ($post['name'] ?? '')), 'emoji' => trim((string) ($post['emoji'] ?? '')), 'tagline' => trim((string) ($post['tagline'] ?? '')),
        'active' => !empty($post['active']), 'order' => (int) ($post['order'] ?? 50), 'vocab' => (array) ($post['vocab'] ?? []), 'features' => [], 'modules' => []];
    foreach (GT_FEATURES as $fk => $_) $entry['features'][$fk] = !empty($post['features'][$fk]);
    foreach (gm_modules() as $mk => $_) {
        $mode = (string) (($post['modules'] ?? [])[$mk] ?? '');
        $entry['modules'][$mk] = in_array($mode, GM_MODES, true) ? $mode : gm_default_type_mode($key, $mk);
    }
    $locations = [];
    foreach ((array) ($post['locations'] ?? []) as $loc) {
        if (!is_array($loc) || trim((string) ($loc['key'] ?? '')) === '') continue;
        foreach (['price_cents', 'price_pro_cents'] as $k) {
            if (isset($loc[$k . '_eur'])) $loc[$k] = (int) round((float) str_replace(',', '.', (string) $loc[$k . '_eur']) * 100);
        }
        $locations[] = $loc;
    }
    $entry['locations'] = $locations;
    $entry['vendor_plugins'] = gt_vendor_plugins_normalize((array) ($post['vendor_plugins'] ?? []));
    $offers = [];
    foreach (go_defaults() as $d) {
        $p = (array) ($post['offers'][$d['key']] ?? []);
        foreach (['month_cents', 'year_cents'] as $k) if (isset($p[$k . '_eur'])) $p[$k] = (int) round((float) str_replace(',', '.', (string) $p[$k . '_eur']) * 100);
        $p['active'] = !empty($p['active']);
        $offers[$d['key']] = $p;
    }
    $entry['offers'] = $offers;
    if ($entry['name'] === '') return 'Donnez un nom au type.';
    $saved[$key] = $entry;
    // le type est stocké tel que saisi ; il est normalisé à la lecture
    $dir = dirname(gt_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return "Impossible d'écrire dans le dossier des réglages.";
    return @file_put_contents(gt_file(), json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false ? "Impossible d'enregistrer les types." : null;
}
