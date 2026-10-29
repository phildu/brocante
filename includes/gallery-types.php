<?php

// Types de galerie : chaque type est un produit à part, avec son vocabulaire (commerces, exposants, marchands…), ses fonctionnalités et ses
// offres (prix, durée d'essai, nombre de membres). Quatre types d'origine, tous modifiables dans le portail (Types de galerie) :
//   marchande   Galerie marchande : des commerces regroupés sur une page commune (comportement historique) ;
//   puces       Marché aux puces : des marchands installés sur des emplacements numérotés, avec tarifs par zone ;
//   vide-grenier Vide-grenier : des exposants qui choisissent un emplacement sur le plan et le demandent en ligne ;
//   quartier    Quartier marchand : les commerçants d'une rue, d'un quartier ou d'un village réunis (nom proposé, modifiable).
// Réglages : data/saas/galeries-types.json (uniquement ce qui diffère des valeurs d'origine). Le type d'une galerie est la clé « type » de
// data/galeries/<id>.json ; une galerie sans type est une galerie marchande.

require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/gallery-offers.php';
require_once __DIR__ . '/gallery-modules.php';

const GT_DEFAULT = 'marchande';
const GT_FEATURES = [
    'plan' => 'Plan d\'implantation (carte, zones, emplacements)',
    'tarifs' => 'Tarifs par zone',
    'exposants' => 'Demandes d\'emplacement des exposants',
    'prospects' => 'Prospects et relances de commerçants',
    'tableau' => 'Tableau de bord de fréquentation',
    'abonnes' => 'Abonnés aux nouveautés',
    'fidelite' => 'Espace visiteur et fidélité de la galerie (web app, QR codes)',
];
const GT_VOCAB_KEYS = [
    'gallery' => 'La galerie (singulier)', 'galleries' => 'Les galeries (pluriel)', 'member' => 'Un membre (singulier)', 'members' => 'Les membres (pluriel)',
    'members_title' => 'Titre de la liste des membres', 'members_lede' => 'Phrase sous ce titre', 'plan_of' => 'Plan « … » (complément)', 'join_cta' => 'Bouton de rejoindre',
    'create_cta' => 'Bouton de création', 'create_title' => 'Titre de la page de création', 'create_intro' => 'Introduction de la page de création', 'empty' => 'Message galerie vide',
];

/** Offres d'un type : mêmes clés (test, essentiel, pro) que partout, valeurs propres au type. */
function gt_offers_for(array $over): array
{
    $out = [];
    foreach (go_defaults() as $d) $out[$d['key']] = array_merge($d, (array) ($over[$d['key']] ?? []));
    return $out;
}

function gt_defaults(): array
{
    $all = static function (array $on): array { $f = []; foreach (array_keys(GT_FEATURES) as $k) $f[$k] = in_array($k, $on, true); return $f; };
    return [
        'marchande' => [
            'name' => 'Galerie marchande', 'emoji' => '🏬', 'active' => true, 'order' => 1,
            'tagline' => 'Des commerces réunis sur une même page : leurs pièces, leurs boutiques, leur plan.',
            'vocab' => ['gallery' => 'galerie commerciale', 'galleries' => 'galeries commerciales', 'member' => 'commerce', 'members' => 'commerces', 'members_title' => 'Les commerces',
                'members_lede' => 'Chaque commerce a sa boutique, son panier et son paiement : une pièce se règle chez son commerçant.', 'plan_of' => 'des commerces', 'join_cta' => 'Votre boutique ici',
                'create_cta' => 'Créer ma galerie', 'create_title' => 'Créez votre galerie commerciale', 'create_intro' => 'Réunissez plusieurs commerces sur une page commune, avec un annuaire, des filtres, vos couleurs et un tableau de bord.', 'empty' => "Cette galerie n'a pas encore de commerce."],
            'features' => $all(['plan', 'prospects', 'tableau', 'abonnes', 'fidelite']),
            'offers' => gt_offers_for([]),
        ],
        'puces' => [
            'name' => 'Marché aux puces', 'emoji' => '🧺', 'active' => true, 'order' => 2,
            'tagline' => 'Des marchands sur des emplacements numérotés, avec un plan et des tarifs par zone.',
            'vocab' => ['gallery' => 'marché aux puces', 'galleries' => 'marchés aux puces', 'member' => 'marchand', 'members' => 'marchands', 'members_title' => 'Les marchands',
                'members_lede' => 'Retrouvez chaque marchand et ses trouvailles : une pièce se règle chez son marchand.', 'plan_of' => 'du marché', 'join_cta' => 'Devenir marchand',
                'create_cta' => 'Créer mon marché aux puces', 'create_title' => 'Créez votre marché aux puces', 'create_intro' => 'Dessinez le plan de votre marché, numérotez les emplacements, fixez les tarifs par zone et présentez vos marchands.', 'empty' => "Ce marché n'a pas encore de marchand."],
            'features' => $all(['plan', 'tarifs', 'prospects', 'tableau', 'abonnes', 'fidelite']),
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour essayer votre marché sans engagement', 'max_members' => 5],
                'essentiel' => ['tagline' => 'Pour un marché de quartier ou de village', 'month_cents' => 3900, 'year_cents' => 39000, 'max_members' => 25],
                'pro' => ['tagline' => 'Pour un grand marché ou une halle', 'month_cents' => 9900, 'year_cents' => 99000, 'max_members' => 80],
            ]),
        ],
        'vide-grenier' => [
            'name' => 'Vide-grenier', 'emoji' => '🪑', 'active' => true, 'order' => 3,
            'tagline' => 'Les exposants choisissent leur emplacement sur le plan et le demandent en ligne.',
            'vocab' => ['gallery' => 'vide-grenier', 'galleries' => 'vides-greniers', 'member' => 'exposant', 'members' => 'exposants', 'members_title' => 'Les exposants',
                'members_lede' => 'Retrouvez les exposants du vide-grenier et leurs boutiques en ligne.', 'plan_of' => 'du vide-grenier', 'join_cta' => 'Exposer ici',
                'create_cta' => 'Organiser mon vide-grenier', 'create_title' => 'Organisez votre vide-grenier', 'create_intro' => 'Un plan en ligne, des tarifs par zone (rue, hall…), et des exposants qui demandent leur emplacement : vous validez en un clic.', 'empty' => "Ce vide-grenier n'a pas encore d'exposant."],
            'features' => $all(['plan', 'tarifs', 'exposants', 'tableau', 'abonnes', 'fidelite']),
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour préparer votre prochain vide-grenier', 'max_members' => 10],
                'essentiel' => ['tagline' => 'Pour un vide-grenier de village ou de quartier', 'month_cents' => 1900, 'year_cents' => 19000, 'max_members' => 40],
                'pro' => ['tagline' => 'Pour une grande braderie ou plusieurs événements', 'month_cents' => 4900, 'year_cents' => 49000, 'max_members' => 150],
            ]),
        ],
        'quartier' => [
            'name' => 'Quartier marchand', 'emoji' => '🏘️', 'active' => true, 'order' => 4,
            'tagline' => 'Les commerçants d\'une rue, d\'un quartier ou d\'un village réunis sous une même enseigne.',
            'vocab' => ['gallery' => 'quartier marchand', 'galleries' => 'quartiers marchands', 'member' => 'commerçant', 'members' => 'commerçants', 'members_title' => 'Les commerçants du quartier',
                'members_lede' => 'Les boutiques de la rue, du quartier ou du village : chacune garde son panier et son paiement.', 'plan_of' => 'du quartier', 'join_cta' => 'Rejoindre le quartier',
                'create_cta' => 'Créer mon quartier marchand', 'create_title' => 'Créez votre quartier marchand', 'create_intro' => 'Réunissez les commerçants d\'une rue, d\'un quartier ou d\'un village : annuaire, plan des boutiques, promotions communes et lettre d\'information.', 'empty' => "Ce quartier n'a pas encore de commerçant."],
            'features' => $all(['plan', 'prospects', 'tableau', 'abonnes', 'fidelite']),
            'offers' => gt_offers_for([
                'test' => ['tagline' => 'Pour réunir vos premiers commerçants', 'max_members' => 5],
                'essentiel' => ['tagline' => 'Pour une rue ou un petit village', 'month_cents' => 2500, 'year_cents' => 25000, 'max_members' => 15],
                'pro' => ['tagline' => 'Pour un quartier ou une grande commune', 'month_cents' => 6900, 'year_cents' => 69000, 'max_members' => 50],
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
    return ['key' => $key, 'name' => $txt($saved['name'] ?? null, 40, (string) $base['name']), 'emoji' => $txt($saved['emoji'] ?? null, 8, (string) ($base['emoji'] ?? '🏬')),
        'tagline' => $txt($saved['tagline'] ?? null, 160, (string) ($base['tagline'] ?? '')), 'active' => array_key_exists('active', $saved) ? !empty($saved['active']) : !empty($base['active']),
        'order' => (int) ($saved['order'] ?? $base['order'] ?? 50), 'vocab' => $vocab, 'features' => $features, 'offers' => $offers,
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
