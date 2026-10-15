<?php

// Décor automatique de la photo d'ambiance (« mise en situation ») : selon la NATURE de la pièce (vêtement, meuble,
// livre, bijou…), l'IA reçoit une scène adaptée, tirée au hasard parmi quelques décors plausibles. Un vêtement de
// femme est ainsi porté par une femme dans un jardin, à la campagne, dans un bureau ou dans la rue ; un meuble est
// posé dans une pièce de la maison ; un livre dans un coin lecture. La consigne du vendeur (« Mots-clés / précisions »)
// prime toujours : ce décor ne sert que quand elle n'en décrit pas.
//
// Chaque scène existe en anglais (texte envoyé au modèle) et en français (aperçu du prompt dans l'administration).

/** Décors en extérieur / en ville où une personne porte l'article : [anglais, français]. */
const AMBIANCE_WORN_SETTINGS = [
    ['a flower-filled garden in soft afternoon light', 'un jardin fleuri, dans la douce lumière de l\'après-midi'],
    ['a sunlit countryside path with fields and hedgerows behind', 'un chemin de campagne ensoleillé, avec des champs et des haies derrière'],
    ['a bright modern office with large windows', 'un bureau moderne et lumineux aux grandes fenêtres'],
    ['a charming old-town street with natural daylight', 'une jolie rue de vieille ville, en lumière naturelle du jour'],
    ['the terrace of a café on a sunny morning', 'la terrasse d\'un café, un matin ensoleillé'],
    ['a public park with trees in warm golden-hour light', 'un parc arboré, dans la lumière dorée de fin de journée'],
    ['a farmers\' market with stalls softly blurred behind', 'un marché de producteurs, étals flous à l\'arrière-plan'],
];

/** Intérieurs et extérieurs pour un objet posé, sans personne, par famille : [anglais, français]. */
const AMBIANCE_HOME_SETTINGS = [
    'living' => [
        ['a cozy French living room with warm wood tones and soft daylight', 'un salon français chaleureux aux tons de bois, en lumière douce du jour'],
        ['a bright Scandinavian-style living room with a linen sofa', 'un salon lumineux de style scandinave, avec un canapé en lin'],
        ['a sunny reading corner by a window, with a rug and plants', 'un coin lecture ensoleillé près d\'une fenêtre, avec un tapis et des plantes'],
        ['a rustic stone farmhouse room with exposed beams', 'une pièce de ferme en pierre avec poutres apparentes'],
        ['an entrance hall with a vintage console and a mirror', 'une entrée avec une console ancienne et un miroir'],
    ],
    'bedroom' => [
        ['a calm bedroom with white walls, wooden floor and soft morning light', 'une chambre calme aux murs blancs et au parquet, en lumière douce du matin'],
        ['a country-house bedroom with an iron bed and floral curtains', 'une chambre de maison de campagne avec lit en fer et rideaux fleuris'],
        ['a cozy attic bedroom with wooden beams', 'une chambre sous les toits aux poutres de bois'],
    ],
    'dining' => [
        ['a laid wooden dining table in a farmhouse kitchen, morning light', 'une table en bois dressée dans une cuisine de ferme, lumière du matin'],
        ['a sunny garden table under a tree, set for lunch', 'une table de jardin ensoleillée sous un arbre, dressée pour le déjeuner'],
        ['a French kitchen counter with herbs, bread and warm wood tones', 'un plan de travail de cuisine française avec herbes, pain et tons de bois'],
        ['a rustic dining room with a linen tablecloth and candles', 'une salle à manger rustique avec nappe en lin et bougies'],
    ],
    'reading' => [
        ['a reading nook with an armchair, a lamp and a cup of tea', 'un coin lecture avec fauteuil, lampe et tasse de thé'],
        ['a wooden desk by a window with a notebook and a pen', 'un bureau en bois près d\'une fenêtre, avec carnet et stylo'],
        ['a library shelf corner, warm light', 'un coin de bibliothèque, lumière chaude'],
        ['a café table with a coffee, by the window', 'une table de café avec un café, près de la fenêtre'],
        ['a garden bench in the shade of a tree', 'un banc de jardin à l\'ombre d\'un arbre'],
    ],
    'garden' => [
        ['a cottage garden with flowers and old stone, soft daylight', 'un jardin de campagne fleuri avec de la vieille pierre, lumière douce'],
        ['a rustic garden shed with wooden shelves and plants', 'un abri de jardin rustique avec étagères en bois et plantes'],
        ['a sunny terrace with terracotta pots and climbing plants', 'une terrasse ensoleillée avec pots en terre cuite et plantes grimpantes'],
        ['a vegetable garden with raised beds, golden light', 'un potager en carrés surélevés, lumière dorée'],
    ],
    'workshop' => [
        ['a tidy wooden workshop with a workbench and natural light', 'un atelier en bois bien rangé, avec établi et lumière naturelle'],
        ['a garden shed with tools hanging on the wall', 'un abri de jardin avec des outils accrochés au mur'],
        ['a barn with old planks and warm sunlight through the door', 'une grange aux vieilles planches, avec le soleil chaud qui entre par la porte'],
    ],
    'desk' => [
        ['a tidy modern home office with a wooden desk and a plant', 'un bureau moderne à la maison, avec table en bois et plante'],
        ['a bright workspace by a window with a notebook', 'un espace de travail lumineux près d\'une fenêtre, avec un carnet'],
        ['a cozy living room shelf unit with books and warm light', 'une étagère de salon avec des livres, en lumière chaude'],
    ],
    'music' => [
        ['a cozy living room with a record shelf and warm lamp light', 'un salon chaleureux avec une étagère à disques, à la lumière d\'une lampe'],
        ['a vintage-style loft with a wooden sideboard and plants', 'un loft au style vintage avec un buffet en bois et des plantes'],
        ['a teenager\'s retro bedroom with posters and string lights', 'une chambre rétro avec posters et guirlandes lumineuses'],
    ],
    'nursery' => [
        ['a soft, bright nursery with pastel tones and a wooden crib', 'une chambre de bébé douce et lumineuse, tons pastel et lit en bois'],
        ['a playroom with a wooden floor, a rug and shelves of toys', 'une salle de jeux avec parquet, tapis et étagères de jouets'],
    ],
    'pets' => [
        ['a cozy living room with a basket and a soft rug, daylight', 'un salon chaleureux avec un panier et un tapis doux, en lumière du jour'],
        ['a sunny garden lawn with flowers', 'une pelouse ensoleillée avec des fleurs'],
    ],
    'bathroom' => [
        ['a bright bathroom with white tiles and wood details', 'une salle de bain lumineuse aux carreaux blancs et détails en bois'],
        ['a dressing table in a calm bedroom, soft daylight', 'une coiffeuse dans une chambre calme, lumière douce du jour'],
    ],
    'table' => [
        ['a rustic wooden market table with a linen cloth, natural light', 'une table de marché en bois avec nappe en lin, lumière naturelle'],
        ['a picnic blanket on grass under a tree, sunny day', 'une couverture de pique-nique sur l\'herbe sous un arbre, par beau temps'],
        ['a farmhouse kitchen table with a checked cloth', 'une table de cuisine de ferme avec nappe à carreaux'],
        ['a bakery counter with wooden shelves and warm light', 'un comptoir de boulangerie, étagères en bois et lumière chaude'],
    ],
    'tech' => [
        ['a tidy modern desk with a plant and soft daylight', 'un bureau moderne bien rangé avec une plante, lumière douce du jour'],
        ['a bright living room with a wooden TV unit and shelves', 'un salon lumineux avec meuble TV en bois et étagères'],
        ['a minimalist workspace with a white wall and a lamp', 'un espace de travail minimaliste, mur blanc et lampe'],
    ],
    'auto' => [
        ['a tidy garage with warm light and wooden shelves', 'un garage bien rangé, lumière chaude et étagères en bois'],
        ['a country road with a vintage car blurred behind, golden light', 'une route de campagne avec une voiture ancienne floue derrière, lumière dorée'],
    ],
    'sport' => [
        ['a sunny park path in the morning', 'un chemin de parc, un matin ensoleillé'],
        ['a forest trail in soft light', 'un sentier de forêt en lumière douce'],
        ['a countryside road with fields, golden hour', 'une route de campagne bordée de champs, heure dorée'],
        ['a lakeside meadow in the morning mist', 'une prairie au bord d\'un lac, dans la brume du matin'],
    ],
];

/** Mots indiquant le public visé d'un vêtement (nom, description, rayon). */
function ambiance_gender(string $haystack, string $sub): string
{
    $women = '/\b(femme|femmes|dame|dames|fille|filles|robe|jupe|chemisier|blouse|escarpins?|talons?|ballerines?|soutien|tailleur|f[ée]minin\w*|woman|women|lady|ladies)\b/u';
    $men = '/\b(homme|hommes|monsieur|messieurs|gar[cç]on|cravate|derbys?|mocassins?|masculin\w*|man|men)\b/u';
    $isW = (bool) preg_match($women, $haystack);
    $isM = (bool) preg_match($men, $haystack);
    if ($isW && !$isM) return 'woman';
    if ($isM && !$isW) return 'man';
    if (in_array($sub, ['robes', 'escarpins', 'ballerines'], true)) return 'woman';
    return 'any';
}

/** Famille de décor d'une pièce : nature, sous-catégorie, puis mots du nom / de la description. Chaîne vide si inconnue. */
function ambiance_family(array $product): string
{
    $nature = (string) ($product['nature'] ?? '');
    $sub = (string) ($product['sous_categorie'] ?? '');
    $text = mb_strtolower(implode(' ', [
        (string) ($product['name'] ?? ''), (string) ($product['description'] ?? ''), (string) ($product['materials'] ?? ''),
        category_label((string) ($product['cat'] ?? '')), (string) ($product['size_text'] ?? ''),
    ]));
    $has = static fn (string $re): bool => (bool) preg_match('/\b(?:' . $re . ')\b/u', $text);

    // Linge, lingerie, maillots et vêtements d'enfant : jamais portés par une personne sur l'image.
    if ($nature === 'vetements' && in_array($sub, ['lingerie', 'maillots'], true)) return 'bedroom';
    if ($nature === 'vetements' && $sub === 'enfant' || $nature === 'chaussures' && $sub === 'chaussures_enfant') return 'nursery';

    switch ($nature) {
        case 'vetements': case 'chaussures': case 'accessoires': case 'bijoux': return 'worn';
        case 'linge': return in_array($sub, ['draps', 'plaids', 'coussins'], true) ? 'bedroom' : (in_array($sub, ['nappes', 'torchons'], true) ? 'dining' : 'living');
        case 'decoration': return $sub === 'plantes' ? 'garden' : 'living';
        case 'arts_table': return 'dining';
        case 'mobilier': return $sub === 'mobilier_exterieur' ? 'garden' : ($sub === 'lits' ? 'bedroom' : ($sub === 'mobilier_enfant' ? 'nursery' : ($sub === 'meubles_toilette' ? 'bathroom' : 'living')));
        case 'luminaires': return 'living';
        case 'livres_medias': return in_array($sub, ['vinyles', 'cassettes'], true) ? 'music' : 'reading';
        case 'jeux': return 'nursery';
        case 'collection': return $sub === 'photographie' ? 'desk' : 'living';
        case 'musique_electro': return 'music';
        case 'outils_jardin': return in_array($sub, ['jardinage', 'cuisine_campagne'], true) ? 'garden' : ($sub === 'velos' ? 'sport' : 'workshop');
        case 'sport': return $sub === 'supporters' ? 'worn' : 'sport';
        case 'alimentaire': return 'table';
        case 'tv_hifi': case 'informatique': case 'telephonie': return 'tech';
        case 'electromenager': return 'dining';
        case 'beaute': return 'bathroom';
        case 'bebe': return 'nursery';
        case 'animaux': return 'pets';
        case 'auto_moto': return 'auto';
    }
    // Nature absente : repli sur les mots de la fiche.
    if ($has('robes?|jupes?|pantalons?|vestes?|manteaux?|pulls?|chemises?|chemisiers?|blousons?|v[êe]tements?|chaussures?|bottes?|sacs?|foulards?|[ée]charpes?|chapeaux?|bijoux?|colliers?|bracelets?|bagues?|montres?|hoodies?|sweats?|sweat-shirts?|t-shirts?|tee-shirts?|jeans?|shorts?|polos?|doudounes?|parkas?|bonnets?|casquettes?|sneakers?|baskets?|gilets?|cardigans?|maillots?|tuniques?|costumes?')) return 'worn';
    if ($has('livres?|romans?|bd|magazines?|cahiers?|carnets?')) return 'reading';
    if ($has('disques?|vinyles?|platines?|radios?|guitares?|instruments?')) return 'music';
    if ($has('lits?|draps?|couvertures?|plaids?')) return 'bedroom';
    if ($has('assiettes?|verres?|tasses?|bols?|plats?|casseroles?|couverts?|nappes?|torchons?|vaisselle|service')) return 'dining';
    if ($has('jardin|pots?|arrosoirs?|b[êe]ches?|outils?')) return 'garden';
    if ($has('pains?|g[âa]teaux?|tartes?|confitures?|miels?|vins?|fromages?')) return 'table';
    if ($has('poup[ée]es?|jouets?|jeux?|puzzles?')) return 'nursery';
    return '';
}

/**
 * Décor automatique de la photo d'ambiance d'une pièce, tiré au hasard : ['en' => scène pour le modèle,
 * 'fr' => même scène pour l'aperçu, 'family' => …, 'person' => bool] ; null si la pièce est inconnue
 * (l'IA choisit alors elle-même un décor selon ce qu'elle voit).
 */
function ambiance_scene(?array $product): ?array
{
    if (!$product) return null;
    $family = ambiance_family($product);
    if ($family === 'worn') {
        $nature = (string) ($product['nature'] ?? '');
        $sub = (string) ($product['sous_categorie'] ?? '');
        $text = mb_strtolower(implode(' ', [(string) ($product['name'] ?? ''), (string) ($product['description'] ?? ''), category_label((string) ($product['cat'] ?? ''))]));
        $gender = ambiance_gender($text, $sub);
        if ($gender === 'any' && in_array($nature, ['bijoux', 'accessoires'], true) && !in_array($sub, ['ceintures', 'montres'], true)) $gender = 'woman';
        [$en, $fr] = AMBIANCE_WORN_SETTINGS[array_rand(AMBIANCE_WORN_SETTINGS)];
        $who = ['woman' => ['a woman', 'une femme'], 'man' => ['a man', 'un homme'], 'any' => ['a person (a woman or a man, according to who this item is designed for)', 'une personne (femme ou homme, selon le public de l\'article)']][$gender];
        // Cadrage selon l'article : pieds pour des chaussures, buste pour un bijou, plan américain sinon.
        $frame = match ($nature) {
            'chaussures' => ['framed on the feet and legs while walking, the shoes sharp and clearly visible', 'cadrée sur les pieds et les jambes en marchant, les chaussures nettes et bien visibles'],
            'bijoux' => ['in a close portrait framing so that the jewel is sharp and clearly visible', 'en portrait rapproché pour que le bijou soit net et bien visible'],
            'accessoires' => ['in a medium shot, the accessory worn or carried and clearly visible', 'en plan moyen, l\'accessoire porté ou tenu et bien visible'],
            default => ['in a natural, relaxed pose, framed so that the whole garment is visible and in focus', 'dans une pose naturelle et détendue, cadrée pour que tout le vêtement soit visible et net'],
        };
        return [
            'family' => 'worn', 'person' => true,
            'en' => "$who[0] wearing exactly this item, photographed in $en, $frame[0], candid editorial fashion photography",
            'fr' => "$who[1] portant exactement cet article, photographiée dans $fr, $frame[1], photographie de mode naturelle",
        ];
    }
    if ($family === '') {
        return [
            'family' => '', 'person' => false,
            'en' => 'a setting chosen to suit the nature of this object. If it is something worn (clothing, shoe, accessory, jewel), show it worn by a person '
                . '(a woman or a man, according to who it is designed for); otherwise place it where such an object naturally belongs. '
                . 'For variety, pick the setting at random among a garden, the countryside, an office, a street or a home interior, whichever fits the object',
            'fr' => 'un décor choisi selon la nature de l\'objet. S\'il se porte (vêtement, chaussure, accessoire, bijou), il est porté par une personne '
                . '(femme ou homme, selon le public visé) ; sinon il est posé là où un tel objet a sa place. '
                . 'Pour varier, le décor est tiré au hasard parmi un jardin, la campagne, un bureau, une rue ou un intérieur, selon ce qui convient',
        ];
    }
    $options = AMBIANCE_HOME_SETTINGS[$family] ?? AMBIANCE_HOME_SETTINGS['living'];
    [$en, $fr] = $options[array_rand($options)];
    return ['family' => $family, 'person' => false, 'en' => "$en, no people", 'fr' => "$fr, sans personne"];
}

/** Aperçu du prompt : la nature détectée et les décors possibles, sans tirage (le vrai tirage se fait à la génération). */
function ambiance_scene_preview(?array $product): ?array
{
    if (!$product) return null;
    $family = ambiance_family($product);
    if ($family === 'worn') {
        $fr = implode(' · ', array_column(AMBIANCE_WORN_SETTINGS, 1));
        $en = implode('; ', array_column(AMBIANCE_WORN_SETTINGS, 0));
        return ['en' => "Automatic setting (random draw at each generation): the item worn by a person in one of: $en",
            'fr' => "Une personne porte l'article, dans un décor tiré au hasard à chaque génération : $fr"];
    }
    if ($family === '') return null;
    $options = AMBIANCE_HOME_SETTINGS[$family] ?? AMBIANCE_HOME_SETTINGS['living'];
    return ['en' => 'Automatic setting (random draw at each generation), one of: ' . implode('; ', array_column($options, 0)),
        'fr' => 'Décor tiré au hasard à chaque génération parmi : ' . implode(' · ', array_column($options, 1))];
}

/** Réglage « Taille de l'objet dans l'image » : [libellé, facteur appliqué à la place qu'il prend sur la toile de départ]. */
const AMBIANCE_SIZE_PRESETS = [
    'auto' => ['Selon la pièce (automatique)', 1.0],
    'reduit' => ['Plus petit (−20 %)', 0.8],
    'tres_reduit' => ['Beaucoup plus petit (−40 %)', 0.6],
];

/**
 * Part de la toile qu'occupe la photo de départ envoyée à l'IA. Un vêtement porté, sur une toile 3:2, prenait 82 % de la
 * hauteur : l'IA habillait alors un mannequin à la taille du vêtement, qui paraissait trop grand. Il est donc posé plus petit
 * (le 9:16 se règle sur la largeur, il n'en avait pas besoin) ; $sizePreset permet de le réduire encore à la demande.
 */
function ambiance_subject_fill(?array $scene, string $sizePreset, string $aspect): float
{
    $base = GENERATED_IMAGE_SUBJECT_FILL;
    if ($scene && !empty($scene['person']) && aspect_ratio_value($aspect) >= 1) $base = 0.55;
    $factor = AMBIANCE_SIZE_PRESETS[$sizePreset][1] ?? 1.0;
    return max(0.25, round($base * $factor, 2));
}
