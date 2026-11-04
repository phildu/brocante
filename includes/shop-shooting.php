<?php

// Shooting du lieu (admin/shop-shooting.php) : parcours guidé pour photographier
// la boutique, le stand ou l'atelier, puis publier les photos aux bons endroits
// du site — diaporama d'accueil, section « Notre histoire », diaporama boutique
// et bandeaux de page. Le contrôle qualité reprend les mesures locales de
// l'assistant de shooting (shooting_photo_metrics) et les complète par une
// critique IA facultative.
//
// Rien ici ne modifie le parcours des pièces : ce module ne sert qu'aux photos
// du lieu, et les photos publiées restent modifiables dans les écrans existants
// (admin/hero.php, admin/slideshow.php, admin/banners.php, Réglages → pages).

require_once __DIR__ . '/shooting-assistant.php';

/**
 * Défauts possibles d'une photo de lieu. Ce ne sont pas ceux d'une photo
 * d'objet : un décor chargé n'est pas un problème ici, mais des lignes
 * penchées, un lieu en désordre ou un client reconnaissable en sont.
 */
function shop_shooting_issue_labels(): array
{
    return [
        'flou' => 'Photo floue',
        'bouge' => 'Flou de bougé',
        'sombre' => 'Trop sombre',
        'surexpose' => 'Surexposée',
        'contraste' => 'Manque de contraste',
        'cadrage' => 'Mal cadrée',
        'penche' => 'Lignes penchées',
        'encombre' => 'Décor encombré',
        'reflet' => 'Reflets gênants',
        'personne' => 'Personne reconnaissable',
        'autre' => 'À vérifier',
    ];
}

/** Conseils valables pour les trois lieux, affichés avant de commencer. */
function shop_shooting_general_tips(): array
{
    return [
        ['icon' => '💡', 'text' => 'Allumez toutes les lumières et ouvrez les rideaux : éteignez le flash, qui aplatit les volumes.'],
        ['icon' => '🧹', 'text' => 'Rangez ce qui traîne (cartons, sacs, balai) : une photo de lieu se lit en une seconde.'],
        ['icon' => '📐', 'text' => "Tenez le téléphone à hauteur de poitrine, bien droit, en alignant les montants des meubles sur les bords de l'écran."],
        ['icon' => '🚶', 'text' => 'Photographiez sans client, ou de dos : un visage reconnaissable demande une autorisation.'],
    ];
}

/**
 * Lieux proposés avec leur programme. Chaque vue porte les endroits du site où
 * elle est habituellement publiée ('targets'), repris comme cases précochées.
 *   ratio : format conseillé, repris par le coaching et l'affichage.
 */
function shop_shooting_places(): array
{
    return [
        'boutique' => [
            'title' => 'La boutique',
            'icon' => '🏬',
            'intro' => "Sept vues racontent une boutique : la vitrine depuis la rue, la pièce en entier, un rayon garni, le comptoir, la cabine, un détail et vous. Comptez une dizaine de minutes, de préférence avant l'ouverture.",
            'before' => [
                'Ouvrez une demi-heure avant les clients : la lumière est plus douce et personne ne traverse le cadre.',
                "Redressez les portants, pliez ce qui dépasse et dégagez le sol au centre de la pièce.",
                'Éteignez les écrans et les néons froids qui donnent une teinte verte aux murs.',
            ],
            'shots' => [
                ['key' => 'vitrine', 'label' => 'La vitrine depuis la rue', 'required' => true, 'ratio' => 'paysage',
                    'tip' => "L'enseigne et la vitrine entières, sans reflet de voiture ni de soleil.",
                    'coach' => "Photo une : la vitrine. Placez-vous de l'autre côté de la rue, attendez un moment sans reflet, et cadrez l'enseigne entière.",
                    'targets' => ['hero', 'banner:accueil']],
                ['key' => 'ensemble', 'label' => 'La pièce en entier', 'required' => true, 'ratio' => 'paysage',
                    'tip' => "Depuis un angle : on doit voir le sol, les murs et la profondeur de la boutique.",
                    'coach' => "Photo deux : l'ensemble. Mettez-vous dans un coin de la pièce, téléphone à hauteur de poitrine, et prenez toute la largeur.",
                    'targets' => ['hero', 'story']],
                ['key' => 'rayon', 'label' => 'Un rayon bien garni', 'required' => true, 'ratio' => 'libre',
                    'tip' => 'De face, en remplissant le cadre : c’est la photo qui donne envie d’entrer.',
                    'coach' => "Photo trois : un rayon. Placez-vous bien en face, assez près pour que les articles remplissent le cadre.",
                    'targets' => ['slideshow', 'banner:boutique']],
                ['key' => 'comptoir', 'label' => 'Le comptoir', 'required' => true, 'ratio' => 'paysage',
                    'tip' => 'La caisse, les emballages, un mot manuscrit : ce qui montre qu’il y a quelqu’un derrière.',
                    'coach' => 'Photo quatre : le comptoir. Cadrez la caisse et ce qui l’entoure, de trois quarts.',
                    'targets' => ['slideshow']],
                ['key' => 'cabine', 'label' => "La cabine d'essayage ou le coin détente", 'required' => false, 'ratio' => 'portrait',
                    'tip' => 'Rideau ouvert, miroir propre, lumière allumée.',
                    'coach' => "Photo cinq, facultative : la cabine. Ouvrez le rideau et vérifiez que le miroir n'est pas sale.",
                    'targets' => ['slideshow']],
                ['key' => 'detail', 'label' => 'Un détail du décor', 'required' => false, 'ratio' => 'libre',
                    'tip' => 'Une matière, une étiquette, un luminaire, une pile de livres : un gros plan qui respire.',
                    'coach' => 'Photo six, facultative : un détail. Approchez-vous d’une matière ou d’un objet du décor et laissez la mise au point se faire.',
                    'targets' => ['hero', 'slideshow']],
                ['key' => 'vous', 'label' => 'Vous dans la boutique', 'required' => false, 'ratio' => 'paysage',
                    'tip' => 'Un visage rassure plus qu’un décor : faites-vous photographier devant votre rayon.',
                    'coach' => 'Dernière photo, facultative : vous. Demandez à quelqu’un de vous photographier devant votre rayon, ou posez le téléphone sur un meuble.',
                    'targets' => ['story']],
            ],
        ],
        'stand' => [
            'title' => 'Le stand',
            'icon' => '⛺',
            'intro' => "Six vues pour un stand de marché ou de brocante : l'enseigne, le stand entier, l'étal, les portants, un gros plan et l'ambiance autour. Photographiez dès la fin de l'installation, avant l'arrivée des visiteurs.",
            'before' => [
                "Installez tout avant de photographier : les photos prises à moitié montées ne resservent jamais.",
                'Tendez les nappes et alignez les portants : un stand rangé se lit de loin.',
                'Par temps couvert la lumière est la plus douce ; en plein soleil, cherchez l’ombre du parasol.',
            ],
            'shots' => [
                ['key' => 'enseigne', 'label' => "L'enseigne ou la pancarte", 'required' => true, 'ratio' => 'paysage',
                    'tip' => 'Le nom doit être lisible : c’est ce qui permet de vous retrouver sur un marché.',
                    'coach' => "Photo une : l'enseigne. Cadrez la pancarte entière, bien droite, en vous plaçant à sa hauteur.",
                    'targets' => ['hero', 'banner:accueil']],
                ['key' => 'ensemble', 'label' => 'Le stand entier, de face', 'required' => true, 'ratio' => 'paysage',
                    'tip' => 'Reculer assez pour avoir toute la largeur, sans couper les montants.',
                    'coach' => "Photo deux : le stand entier. Reculez de quelques mètres et prenez toute la largeur, du sol au haut du parasol.",
                    'targets' => ['hero', 'story']],
                ['key' => 'etal', 'label' => "L'étal ou la table de présentation", 'required' => true, 'ratio' => 'paysage',
                    'tip' => 'De trois quarts, pour montrer à la fois la surface et la profondeur.',
                    'coach' => "Photo trois : l'étal. Placez-vous de trois quarts pour montrer la surface de la table et ce qui est derrière.",
                    'targets' => ['slideshow', 'banner:boutique']],
                ['key' => 'portants', 'label' => 'Les portants ou les rayonnages', 'required' => true, 'ratio' => 'libre',
                    'tip' => 'De face, en laissant voir les cintres alignés.',
                    'coach' => 'Photo quatre : les portants. Mettez-vous bien en face et cadrez toute la hauteur.',
                    'targets' => ['slideshow']],
                ['key' => 'gros_plan', 'label' => 'Un gros plan sur une belle pièce', 'required' => false, 'ratio' => 'libre',
                    'tip' => 'Une pièce posée sur l’étal, seule, avec le reste du stand flou derrière.',
                    'coach' => 'Photo cinq, facultative : un gros plan. Posez une belle pièce seule et approchez-vous pour détacher le fond.',
                    'targets' => ['slideshow', 'hero']],
                ['key' => 'ambiance', 'label' => "L'ambiance autour du stand", 'required' => false, 'ratio' => 'paysage',
                    'tip' => 'Le marché, la rue, les allées : sans visage reconnaissable.',
                    'coach' => 'Dernière photo, facultative : l’ambiance. Prenez le marché ou l’allée autour du stand, de préférence de dos.',
                    'targets' => ['slideshow', 'banner:histoire']],
            ],
        ],
        'atelier' => [
            'title' => "L'atelier",
            'icon' => '🔨',
            'intro' => "Cinq vues pour un atelier : l'entrée, l'établi, les outils, les matières et les mains au travail. C'est ce qui montre qu'une pièce est réparée, pas seulement revendue.",
            'before' => [
                'Dégagez l’établi : gardez l’outil en cours et la pièce, rangez le reste.',
                'Une lampe d’appoint placée de face vaut mieux qu’un seul plafonnier au-dessus de vous.',
                'Sortez les poubelles et les chiffons usagés du cadre.',
            ],
            'shots' => [
                ['key' => 'entree', 'label' => "L'entrée de l'atelier", 'required' => true, 'ratio' => 'paysage',
                    'tip' => 'Depuis la porte : on doit comprendre où l’on entre.',
                    'coach' => "Photo une : l'entrée. Placez-vous dans l'encadrement de la porte et cadrez la profondeur de l'atelier.",
                    'targets' => ['hero']],
                ['key' => 'etabli', 'label' => "L'établi ou le poste de travail", 'required' => true, 'ratio' => 'paysage',
                    'tip' => 'Avec une pièce en cours dessus : un établi vide ne raconte rien.',
                    'coach' => "Photo deux : l'établi. Laissez une pièce en cours dessus et cadrez de trois quarts.",
                    'targets' => ['hero', 'story', 'banner:boutique']],
                ['key' => 'outils', 'label' => 'Les outils', 'required' => true, 'ratio' => 'libre',
                    'tip' => 'Alignés sur un mur ou posés sur l’établi, bien nets.',
                    'coach' => 'Photo trois : les outils. Alignez-les sur un mur ou sur l’établi et rapprochez-vous.',
                    'targets' => ['slideshow']],
                ['key' => 'matieres', 'label' => 'Le stock de matières ou de pièces', 'required' => false, 'ratio' => 'libre',
                    'tip' => 'Une étagère, un bac, une pile : ce qui montre le volume de l’atelier.',
                    'coach' => 'Photo quatre, facultative : le stock. Cadrez une étagère ou un bac bien rempli.',
                    'targets' => ['slideshow']],
                ['key' => 'mains', 'label' => 'Les mains au travail', 'required' => false, 'ratio' => 'libre',
                    'tip' => 'Le geste vaut mille mots : photographiez les mains, pas le visage.',
                    'coach' => 'Dernière photo, facultative : les mains au travail. Faites-vous photographier en train de réparer, en cadrant les mains.',
                    'targets' => ['hero', 'story', 'slideshow']],
            ],
        ],
    ];
}

/** Lieu retenu ; une clé inconnue retombe sur la boutique. */
function shop_shooting_place(?string $key): array
{
    $places = shop_shooting_places();
    $place = $places[(string) $key] ?? $places['boutique'];
    $place['key'] = array_key_exists((string) $key, $places) ? (string) $key : 'boutique';
    return $place;
}

/**
 * Lieu proposé à l'ouverture de la page : une boutique déclarée uniquement en
 * ligne n'a le plus souvent pas de local à photographier, on part de l'atelier.
 */
function shop_shooting_default_place(): string
{
    $mode = function_exists('shop_presence_mode') ? shop_presence_mode() : 'online';
    return $mode === 'online' ? 'atelier' : 'boutique';
}

/**
 * Endroits du site où une photo du lieu peut être publiée. Les bandeaux
 * reprennent les pages déjà déclarées par page_banner_targets().
 *   single : la cible ne porte qu'une photo, la nouvelle remplace l'ancienne.
 */
function shop_shooting_targets(): array
{
    $targets = [
        'hero' => [
            'label' => "Diaporama d'accueil",
            'where' => "Accueil — photo d'ambiance du hero",
            'admin' => '/admin/hero.php',
            'ratio' => 'paysage',
        ],
        'story' => [
            'label' => 'Photo « Notre histoire »',
            'where' => 'Accueil et page Notre histoire (une seule photo, la nouvelle remplace la précédente)',
            'admin' => '/admin/index.php?s=pages',
            'ratio' => 'paysage',
            'single' => true,
        ],
        'slideshow' => [
            'label' => 'Diaporama boutique',
            'where' => 'Écran en magasin (/slideshow.php)',
            'admin' => '/admin/slideshow.php',
            'ratio' => 'libre',
        ],
    ];
    foreach (page_banner_targets() as $pageKey => $label) {
        $targets['banner:' . $pageKey] = [
            'label' => 'Bandeau — ' . $label,
            'where' => 'Fond du titre de la page, sous un voile sombre',
            'admin' => '/admin/banners.php',
            'ratio' => 'paysage',
            'single' => true,
        ];
    }
    return $targets;
}

function shop_shooting_target(string $key): ?array
{
    $targets = shop_shooting_targets();
    return $targets[$key] ?? null;
}

/**
 * Critique d'une photo de lieu : les mesures locales (GD, gratuites) bornent la
 * note de l'IA, qui seule juge le cadrage et le décor. Sans IA, le verdict
 * repose uniquement sur la netteté, la lumière et le contraste.
 *
 * @return array{quality:array{sharpness:int,framing:int,lighting:int,issues:string[],advice:string[]},ready:bool,summary:string}
 */
function shop_shooting_review(array $metrics, ?array $ai = null): array
{
    $quality = is_array($ai['quality'] ?? null) ? $ai['quality'] : [];
    $clamp = static fn ($v, int $fallback): int => is_numeric($v) ? max(0, min(100, (int) round((float) $v))) : $fallback;
    $sharpness = $clamp($quality['sharpness'] ?? null, (int) ($metrics['sharpness'] ?? 0));
    $lighting = $clamp($quality['lighting'] ?? null, (int) ($metrics['lighting'] ?? 0));
    $framing = $clamp($quality['framing'] ?? null, 70);

    $labels = shop_shooting_issue_labels();
    $issues = [];
    foreach ((array) ($quality['issues'] ?? []) as $issue) {
        $issue = strtolower(trim((string) $issue));
        if (isset($labels[$issue]) && !in_array($issue, $issues, true)) $issues[] = $issue;
    }
    $advice = [];
    foreach ((array) ($quality['advice'] ?? []) as $line) {
        $line = trim((string) $line);
        if ($line !== '' && mb_strlen($line) <= 220 && count($advice) < 3) $advice[] = $line;
    }

    // Garde-fous objectifs : une IA trop optimiste est ramenée aux mesures réelles.
    if (($metrics['sharpness'] ?? 0) > 0 && $metrics['sharpness'] < 38) {
        $sharpness = min($sharpness, 45);
        if (!in_array('flou', $issues, true)) $issues[] = 'flou';
    }
    if (($metrics['brightness'] ?? 50) < 23) {
        $lighting = min($lighting, 40);
        if (!in_array('sombre', $issues, true)) $issues[] = 'sombre';
    }
    if (($metrics['brightness'] ?? 50) > 83 || ($metrics['over'] ?? 0) > 0.12) {
        $lighting = min($lighting, 45);
        if (!in_array('surexpose', $issues, true)) $issues[] = 'surexpose';
    }
    if (($metrics['contrast'] ?? 100) < 12 && !in_array('contraste', $issues, true)) $issues[] = 'contraste';

    // Conseils par défaut quand l'IA n'a rien proposé pour ce défaut.
    $defaults = [
        'flou' => 'Tenez le téléphone à deux mains ou posez-le sur un meuble, puis attendez la mise au point.',
        'sombre' => 'Allumez les lumières, ouvrez les rideaux et évitez le flash, qui aplatit les volumes.',
        'surexpose' => 'Éloignez-vous de la vitrine ou de la fenêtre en plein soleil : la lumière directe brûle les détails.',
        'contraste' => 'Cadrez un mur clair avec un meuble foncé : la photo gagnera tout de suite en relief.',
        'penche' => "Alignez les montants des meubles sur les bords de l'écran avant de déclencher.",
        'encombre' => 'Retirez du cadre ce qui ne se vend pas : cartons, sacs, balai, vêtements de tous les jours.',
        'reflet' => 'Décalez-vous de quelques pas sur le côté : le reflet vient presque toujours de face.',
        'personne' => 'Reprenez la photo sans client, ou de dos : un visage reconnaissable demande une autorisation.',
    ];
    foreach ($issues as $issue) {
        if (count($advice) >= 3) break;
        if (isset($defaults[$issue]) && !in_array($defaults[$issue], $advice, true)) $advice[] = $defaults[$issue];
    }

    $blocking = array_intersect($issues, ['flou', 'sombre', 'surexpose', 'personne']);
    $ready = $sharpness >= 55 && $lighting >= 45 && !$blocking;
    if (!empty($ai)) $ready = $ready && !empty($ai['ready']) && $framing >= 45;

    $summary = trim((string) ($ai['summary'] ?? ''));
    // Une IA contredite par les mesures (elle annonce une photo prête alors que la
    // lumière ou la netteté ne suivent pas, ou l'inverse) : on garde le verdict local.
    if ($summary !== '' && !empty($ai['ready']) !== $ready) $summary = '';
    if ($summary === '') {
        $summary = $ready
            ? 'La photo est exploitable : vous pouvez la publier.'
            : 'Photo à reprendre : ' . implode(', ', array_map(static fn (string $i): string => mb_strtolower($labels[$i] ?? $i), $issues ?: ['lumière ou netteté insuffisante'])) . '.';
    }

    return [
        'quality' => [
            'sharpness' => $sharpness,
            'framing' => $framing,
            'lighting' => $lighting,
            'issues' => $issues,
            'advice' => array_slice($advice, 0, 3),
        ],
        'ready' => (bool) $ready,
        'summary' => mb_substr($summary, 0, 300),
    ];
}

/** Consigne de critique d'une photo de lieu envoyée à Gemini. */
function shop_shooting_review_prompt(string $placeLabel, string $shotLabel): string
{
    $issues = implode(', ', array_keys(shop_shooting_issue_labels()));
    return "Tu es photographe de commerce pour le site d'une boutique de seconde main (" . tenant('ai.shop') . "). "
        . "La photo doit montrer " . $placeLabel . " — ici : " . $shotLabel . " — et donner envie de venir sur place. "
        . "Évalue la netteté, la lumière, le cadrage (lignes verticales droites, lieu entier, rien de coupé) et le décor (rangé, lisible, sans déchet ni carton visible). "
        . "Signale les défauts avec UNIQUEMENT ces clés : " . $issues . ". "
        . "Une personne reconnaissable de face doit donner le défaut « personne ». "
        . "Donne au plus 3 conseils courts et concrets pour améliorer la prise de vue. "
        . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown, de cette forme exacte : "
        . '{"quality": {"sharpness": 0, "framing": 0, "lighting": 0, "issues": ["sombre"], "advice": ["conseil"]}, '
        . '"ready": true, "summary": "une phrase qui résume la photo et ce qu\'il faut éventuellement refaire"}. '
        . "Les notes vont de 0 (inutilisable) à 100 (parfaite). "
        . "Sois honnête : une photo floue, sombre ou encombrée doit avoir ready=false.";
}

/** Valide la réponse JSON de l'IA : nettoie le markdown, borne les notes, filtre les défauts inconnus. */
function shop_shooting_parse_review(string $text): ?array
{
    $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($data)) return null;

    $clamp = static fn ($v): int => is_numeric($v) ? max(0, min(100, (int) round((float) $v))) : 0;
    $quality = is_array($data['quality'] ?? null) ? $data['quality'] : [];
    $labels = shop_shooting_issue_labels();
    $issues = [];
    foreach ((array) ($quality['issues'] ?? []) as $issue) {
        $issue = strtolower(trim((string) $issue));
        if (isset($labels[$issue]) && !in_array($issue, $issues, true)) $issues[] = $issue;
    }
    $advice = [];
    foreach ((array) ($quality['advice'] ?? []) as $line) {
        $line = trim((string) $line);
        if ($line !== '' && mb_strlen($line) <= 220 && count($advice) < 3) $advice[] = $line;
    }

    return [
        'quality' => [
            'sharpness' => $clamp($quality['sharpness'] ?? 0),
            'framing' => $clamp($quality['framing'] ?? 0),
            'lighting' => $clamp($quality['lighting'] ?? 0),
            'issues' => $issues,
            'advice' => $advice,
        ],
        'ready' => !empty($data['ready']),
        'summary' => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 300),
    ];
}

/**
 * Retouche d'une photo de lieu : lumière, contraste et netteté seulement. Le
 * décor, le mobilier et les couleurs des articles restent inchangés — une photo
 * de boutique retouchée ne doit pas promettre autre chose que la réalité.
 */
function shop_shooting_enhance_prompt(): string
{
    return "Retouche cette photo d'un lieu de vente de seconde main pour son site internet. "
        . "Corrige UNIQUEMENT l'exposition, le contraste, la balance des blancs et la netteté. "
        . "Garde exactement le même cadrage, le même mobilier, les mêmes articles et les mêmes couleurs. "
        . "N'ajoute, ne déplace et ne retire aucun élément, et n'invente aucune marque ni aucun texte. "
        . "Rends une photo lumineuse, naturelle et nette, comme prise en lumière du jour.";
}

/** Consigne de légende : une phrase courte, sans invention. */
function shop_shooting_caption_prompt(string $placeLabel, string $shotLabel): string
{
    return "Rédige la légende d'une photo de " . $placeLabel . " (" . $shotLabel . ") pour le site d'une boutique de seconde main. "
        . "Une seule phrase de 6 à 12 mots, chaleureuse et concrète, en français. "
        . "Décris uniquement ce que montre la photo : n'invente ni adresse, ni nom de personne, ni date, ni promesse. "
        . "Réponds avec la phrase seule, sans guillemets et sans aucun texte autour.";
}

/** Légende IA nettoyée : une ligne, sans guillemets ni tiret d'introduction, bornée à 120 signes. */
function shop_shooting_clean_caption(?string $text): string
{
    $caption = trim((string) $text);
    if ($caption === '') return '';
    // Une réponse encadrée de ``` (markdown) : les clôtures sautent avant le choix de la ligne.
    $caption = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $caption));
    $lines = preg_split('/\r\n|\r|\n/', $caption) ?: [];
    foreach ($lines as $line) {
        if (trim((string) $line) !== '') { $caption = trim((string) $line); break; }
    }
    $caption = trim(preg_replace('/\s+/u', ' ', $caption));
    $caption = trim((string) preg_replace('/^\s*(?:[-*•]|légende|caption)\s*[:\-]?\s*/iu', '', $caption));
    $caption = trim((string) $caption, "\"'«»“”\t\n\r\0\x0B ");
    return mb_substr(trim((string) $caption), 0, 120);
}

/** Publie une photo déjà stockée sur une cible. Message lisible, ou null si la cible est inconnue. */
function shop_shooting_publish_to(string $target, string $path, string $caption, array $options = []): ?string
{
    switch ($target) {
        case 'hero':
            add_hero_slide($path, $caption);
            return "Ajoutée au diaporama d'accueil.";

        case 'story': {
            // Une légende vide laisse la précédente : le texte de la section est
            // écrit à la main dans Réglages → Contenu des pages.
            if ($caption !== '') {
                db()->prepare('UPDATE content SET story_photo = ?, story_photo_caption = ? WHERE id = 1')->execute([$path, $caption]);
            } else {
                db()->prepare('UPDATE content SET story_photo = ? WHERE id = 1')->execute([$path]);
            }
            return 'Publiée sur la page et la section « Notre histoire ».';
        }

        case 'slideshow':
            $duration = max(4, min(30, (int) ($options['duration'] ?? 8)));
            add_slideshow_slide('ambiance', $path, null, $caption, $duration);
            return 'Ajoutée au diaporama boutique.';

        default: {
            if (!str_starts_with($target, 'banner:')) return null;
            $pageKey = substr($target, 7);
            $pages = page_banner_targets();
            if (!isset($pages[$pageKey])) return null;
            $overlay = max(0, min(100, (int) ($options['overlay'] ?? 60)));
            set_page_banner($pageKey, 'photo', $path, $overlay);
            return 'Appliquée au bandeau « ' . $pages[$pageKey] . ' ».';
        }
    }
}

/** Source commune des photos publiées par ce parcours, pour les retrouver dans la médiathèque. */
function shop_shooting_media_source(): string
{
    return 'Shooting lieu';
}

/** Photos déjà publiées par ce parcours, de la plus récente à la plus ancienne. */
function shop_shooting_published(int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT path, label, tags, created_at FROM media_library WHERE source = ? ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([shop_shooting_media_source()]);
    return $stmt->fetchAll();
}

/** Tags d'une ressource de médiathèque, en minuscules (ils sont séparés par des virgules). */
function shop_shooting_tag_list(string $tags): array
{
    return array_values(array_filter(array_map(
        static fn ($tag): string => mb_strtolower(trim((string) $tag)),
        explode(',', $tags)
    ), static fn (string $tag): bool => $tag !== ''));
}

/**
 * Photos du lieu validées, groupées par lieu, pour la visite virtuelle publique.
 * Chaque photo publiée par le reportage est inscrite dans la médiathèque : cette
 * liste ne montre donc que ce qui est déjà en ligne, jamais un envoi en attente.
 *
 * @return array<int, array{key:string,title:string,icon:string,intro:string,order:int,photos:array}>
 */
function shop_shooting_visit_groups(int $limit = 160): array
{
    $limit = max(1, min(200, $limit));
    try {
        $stmt = db()->prepare('SELECT path, label, tags, created_at FROM media_library WHERE source = ? AND type = ? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([shop_shooting_media_source(), 'photo']);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    if (!$rows) return [];

    $places = shop_shooting_places();
    $placeOrder = array_flip(array_keys($places));
    // Présentation publique du lieu : courte, elle ne reprend pas les consignes
    // de prise de vue du programme (durée, lumière, préparation du local).
    $visitIntros = [
        'boutique' => 'La vitrine, la pièce, les rayons et les détails qui font la boutique.',
        'stand' => 'L’enseigne, l’étal et l’ambiance autour du stand.',
        'atelier' => 'L’entrée, l’établi et les outils de l’atelier.',
    ];
    $groups = [];
    $seen = [];

    foreach ($rows as $row) {
        $path = (string) ($row['path'] ?? '');
        if ($path === '' || isset($seen[$path]) || !preg_match('/\.(?:jpe?g|png|webp|gif)$/i', $path)) continue;
        // Une photo retirée du disque ne doit pas laisser une vignette cassée.
        if (!is_file(dirname(__DIR__) . '/' . $path)) continue;
        $seen[$path] = true;

        $tags = shop_shooting_tag_list((string) ($row['tags'] ?? ''));
        $placeKey = '';
        foreach ($tags as $tag) {
            if (isset($places[$tag])) { $placeKey = $tag; break; }
        }
        if ($placeKey === '') $placeKey = 'autres';

        $shot = null;
        $shotIndex = 99;
        if ($placeKey !== 'autres') {
            foreach ($places[$placeKey]['shots'] as $i => $candidate) {
                if (in_array($candidate['key'], $tags, true)) { $shot = $candidate; $shotIndex = $i; break; }
            }
        }
        $shotLabel = $shot !== null ? (string) $shot['label'] : 'Vue du lieu';

        $placeTitle = $placeKey !== 'autres' ? (string) $places[$placeKey]['title'] : 'Autres vues';
        $label = trim((string) ($row['label'] ?? ''));
        // Une légende vide a été enregistrée sous la forme « Lieu — Vue » : on
        // ne la répète pas sous la photo.
        $caption = ($label !== '' && $label !== $shotLabel && $label !== $placeTitle . ' — ' . $shotLabel) ? $label : '';

        $groups[$placeKey] ??= [
            'key' => $placeKey,
            'title' => $placeKey !== 'autres' ? $places[$placeKey]['title'] : 'Autres vues du lieu',
            'icon' => $placeKey !== 'autres' ? (string) $places[$placeKey]['icon'] : '📷',
            'intro' => $placeKey !== 'autres' ? (string) ($visitIntros[$placeKey] ?? '') : '',
            'order' => $placeKey !== 'autres' ? (int) $placeOrder[$placeKey] : 99,
            'photos' => [],
        ];
        $groups[$placeKey]['photos'][] = [
            'path' => $path,
            'url' => '/' . ltrim($path, '/'),
            'shot' => $shotLabel,
            'shot_order' => $shotIndex,
            'caption' => mb_substr($caption, 0, 160),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    foreach ($groups as &$group) {
        usort($group['photos'], static fn (array $a, array $b): int => $a['shot_order'] <=> $b['shot_order'] ?: strcmp($b['created_at'], $a['created_at']));
    }
    unset($group);

    $groups = array_values($groups);
    usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order'] ?: strcmp($a['title'], $b['title']));
    return $groups;
}

/** Ce qui est déjà en place sur le site, cible par cible. */
function shop_shooting_state(): array
{
    $content = get_content();
    $ambiance = 0;
    foreach (slideshow_slides_list() as $slide) {
        if (($slide['kind'] ?? '') === 'ambiance' && !empty($slide['path'])) $ambiance++;
    }
    $banners = [];
    foreach (get_page_banners() as $pageKey => $row) {
        $banners[(string) $pageKey] = !empty($row['path']);
    }
    return [
        'hero' => count(hero_slides_list()),
        'story' => !empty($content['story_photo']),
        'slideshow' => $ambiance,
        'banners' => $banners,
        'published' => shop_shooting_published(),
    ];
}

// ── Photographe : préparer sans publier ────────────────────────────────────
// Un photographe (rôle inférieur au community manager) peut cadrer, contrôler
// et envoyer ses photos ; seul un administrateur les met en ligne. Ses envois
// attendent donc dans shooting_requests jusqu'à validation.

/** Ce compte peut-il publier directement sur le site ? (administrateur seul) */
function shop_shooting_can_publish_live(): bool
{
    return admin_role() === 'admin';
}

/** Tables du parcours : envois en attente, vidéos enregistrées. */
function shop_shooting_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS shooting_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER,
  author_name TEXT NOT NULL DEFAULT '',
  place TEXT NOT NULL DEFAULT '',
  shot TEXT NOT NULL DEFAULT '',
  shot_label TEXT NOT NULL DEFAULT '',
  path TEXT NOT NULL,
  caption TEXT NOT NULL DEFAULT '',
  targets TEXT NOT NULL DEFAULT '[]',
  overlay INTEGER NOT NULL DEFAULT 60,
  duration INTEGER NOT NULL DEFAULT 8,
  session_key TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending', -- pending | published | rejected
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified_at TEXT,
  decided_at TEXT,
  decided_by TEXT
)
SQL);
    db()->exec('CREATE INDEX IF NOT EXISTS idx_shooting_requests_status ON shooting_requests(status)');
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS shooting_videos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  program TEXT NOT NULL DEFAULT 'presentation', -- presentation | testimonial
  path TEXT NOT NULL,
  caption TEXT NOT NULL DEFAULT '',
  script TEXT NOT NULL DEFAULT '',
  author_name TEXT NOT NULL DEFAULT '',
  account_id INTEGER,
  consent INTEGER NOT NULL DEFAULT 0,
  duration INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
}

/**
 * Enregistre une photo à valider. Les cibles sont déjà bornées par l'appelant ;
 * elles sont revérifiées ici pour qu'aucune adresse inconnue n'entre en base.
 */
function shop_shooting_request_create(array $data): int
{
    shop_shooting_ensure_schema();
    $targets = [];
    foreach ((array) ($data['targets'] ?? []) as $target) {
        $target = (string) $target;
        if (shop_shooting_target($target) !== null && !in_array($target, $targets, true)) $targets[] = $target;
    }
    $stmt = db()->prepare('INSERT INTO shooting_requests
        (account_id, author_name, place, shot, shot_label, path, caption, targets, overlay, duration, session_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['account_id'] ?? null,
        mb_substr((string) ($data['author_name'] ?? ''), 0, 80),
        (string) ($data['place'] ?? ''),
        (string) ($data['shot'] ?? ''),
        mb_substr((string) ($data['shot_label'] ?? ''), 0, 80),
        (string) ($data['path'] ?? ''),
        mb_substr(trim((string) ($data['caption'] ?? '')), 0, 120),
        json_encode($targets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        max(0, min(100, (int) ($data['overlay'] ?? 60))),
        max(4, min(30, (int) ($data['duration'] ?? 8))),
        mb_substr((string) ($data['session_key'] ?? ''), 0, 40),
    ]);
    return (int) db()->lastInsertId();
}

/** Envois à valider (les plus anciens d'abord : on traite dans l'ordre d'arrivée). */
function shop_shooting_requests_pending(int $limit = 24): array
{
    shop_shooting_ensure_schema();
    $stmt = db()->prepare("SELECT * FROM shooting_requests WHERE status = 'pending' ORDER BY id ASC LIMIT " . max(1, min(100, $limit)));
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Envois d'un photographe, du plus récent au plus ancien. */
function shop_shooting_requests_mine(int $accountId, int $limit = 12): array
{
    shop_shooting_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM shooting_requests WHERE account_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute([$accountId]);
    return $stmt->fetchAll();
}

function shop_shooting_pending_count(): int
{
    try {
        shop_shooting_ensure_schema();
        return (int) db()->query("SELECT COUNT(*) FROM shooting_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Décision de l'administrateur sur un envoi : « publish » applique la photo aux
 * cibles choisies par le photographe, « reject » la laisse hors du site (la photo
 * reste dans uploads/, elle n'est pas effacée).
 *
 * @return array{ok:bool,error?:string,messages?:array,path?:string}
 */
function shop_shooting_request_decide(int $id, string $decision, string $decidedBy): array
{
    shop_shooting_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM shooting_requests WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'error' => 'Envoi introuvable.'];
    if (($row['status'] ?? '') !== 'pending') return ['ok' => false, 'error' => 'Cet envoi a déjà été traité.'];

    $status = $decision === 'reject' ? 'rejected' : 'published';
    $messages = [];
    if ($status === 'published') {
        $targets = json_decode((string) $row['targets'], true);
        $targets = is_array($targets) ? $targets : [];
        $options = ['overlay' => (int) $row['overlay'], 'duration' => (int) $row['duration']];
        foreach ($targets as $target) {
            $message = shop_shooting_publish_to((string) $target, (string) $row['path'], (string) $row['caption'], $options);
            if ($message !== null) $messages[] = ['target' => (string) $target, 'message' => $message];
        }
        // La photo validée rejoint la médiathèque : elle est réutilisable ailleurs.
        $tags = 'lieu,' . $row['place'] . ',' . $row['shot'];
        foreach ($targets as $target) $tags .= ',' . str_replace(':', '-', (string) $target);
        media_library_register(
            (string) $row['path'],
            $row['caption'] !== '' ? (string) $row['caption'] : $row['shot_label'],
            shop_shooting_media_source(),
            $tags
        );
    }
    $upd = db()->prepare('UPDATE shooting_requests SET status = ?, decided_at = CURRENT_TIMESTAMP, decided_by = ? WHERE id = ?');
    $upd->execute([$status, mb_substr($decidedBy, 0, 80), $id]);
    return ['ok' => true, 'messages' => $messages, 'path' => (string) $row['path']];
}

/** Adresses à prévenir : contact du commerce puis administrateurs actifs. */
function shop_shooting_admin_emails(): array
{
    $emails = [];
    $main = trim((string) tenant('admin_email', ''));
    if ($main !== '' && filter_var($main, FILTER_VALIDATE_EMAIL)) $emails[] = $main;
    try {
        foreach (db()->query("SELECT email FROM accounts WHERE role = 'admin' AND is_active = 1 AND email != ''") as $row) {
            $email = trim((string) $row['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[] = $email;
        }
    } catch (Throwable $e) {
    }
    return array_values(array_unique(array_map('mb_strtolower', $emails)));
}

/** Envoi d'un message simple ; jamais d'e-mail depuis un poste local (.test, localhost). */
function shop_shooting_mail(string $to, string $subject, string $body): bool
{
    // L'hôte réellement servi prime sur l'adresse publique du commerce : une
    // boutique testée sur brocante.test ne doit jamais envoyer d'e-mail réel.
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') $host = (string) parse_url(tenant_site_url(), PHP_URL_HOST);
    $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test') || str_ends_with($host, '.local')) return false;
    if (!function_exists('mail')) return false;
    $headers = 'From: ' . tenant('name') . ' <noreply@' . $host . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/**
 * Prévient les administrateurs qu'une séance est terminée et que des photos
 * attendent leur validation. Les lignes déjà annoncées ne sont pas répétées :
 * un nouvel envoi dans la même séance produit un nouveau message.
 *
 * @return int nombre d'envois annoncés (0 si personne n'a pu être joint)
 */
function shop_shooting_notify_admins(array $rows): int
{
    $rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === 'pending' && empty($row['notified_at'])));
    if (!$rows) return 0;
    $recipients = shop_shooting_admin_emails();
    if (!$recipients) return 0;

    $place = shop_shooting_place((string) $rows[0]['place']);
    $author = trim((string) $rows[0]['author_name']);
    $lines = [];
    foreach ($rows as $row) {
        $targets = json_decode((string) $row['targets'], true);
        $targets = is_array($targets) ? $targets : [];
        $labels = [];
        foreach ($targets as $target) {
            $info = shop_shooting_target((string) $target);
            if ($info !== null) $labels[] = $info['label'];
        }
        $lines[] = '- ' . $row['shot_label']
            . ($row['caption'] !== '' ? ' — « ' . $row['caption'] . ' »' : '')
            . ' → ' . ($labels ? implode(', ', $labels) : 'aucun support choisi');
    }
    $subject = 'Shooting terminé : ' . count($rows) . ' photo' . (count($rows) > 1 ? 's' : '') . ' à valider';
    $body = ($author !== '' ? $author : 'Un photographe') . ' a terminé la séance « ' . $place['title'] . ' ».'
        . "\r\n\r\n" . implode("\r\n", $lines)
        . "\r\n\r\nRien n'est encore en ligne. Validez ou refusez les photos :"
        . "\r\n" . tenant_site_url() . '/admin/shop-shooting.php';

    $sent = false;
    foreach ($recipients as $to) $sent = shop_shooting_mail($to, $subject, $body) || $sent;
    if (!$sent) return 0;

    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $mark = db()->prepare('UPDATE shooting_requests SET notified_at = CURRENT_TIMESTAMP WHERE id = ?');
    foreach ($ids as $id) $mark->execute([$id]);
    return count($ids);
}


// ── Vidéos du commerce : présentation et témoignages ───────────────────────
// Deux tournages courts depuis le téléphone : le commerce se présente (texte
// généré par l'IA, relu et modifié, puis affiché dans un prompteur) ou un
// client témoigne (trame de questions + accord du client). La vidéo reste
// dans la médiathèque, rien n'est publié automatiquement sur le site.

/** Programmes vidéo proposés par le shooting, dans l'ordre d'affichage. */
function shop_shooting_video_programs(): array
{
    return [
        'presentation' => [
            'title' => 'Le commerce se présente',
            'icon' => '🎬',
            'lead' => '40 à 60 secondes face caméra : qui vous êtes, ce qu’on trouve ici, pourquoi venir.',
            'tips' => [
                'Filmez à la verticale, téléphone à hauteur du visage, près de la vitrine ou du comptoir.',
                'Lumière de face (vitrine, plafonnier) : jamais de contre-jour ni de fenêtre derrière vous.',
                'Parlez lentement, souriez, gardez les coudes appuyés pour stabiliser.',
            ],
            'text_label' => 'Texte à dire (modifiable)',
            'text_hint' => 'L’IA écrit un premier jet ; gardez vos mots, vos horaires et vos noms réels.',
            'placeholder' => 'Ex. : Bonjour, nous sommes… Voici ce que vous trouvez chez nous…',
            'consent' => false,
            'capture' => 'user',
        ],
        'testimonial' => [
            'title' => 'Un client témoigne',
            'icon' => '💬',
            'lead' => 'Une à deux minutes : un client raconte ce qu’il a trouvé, l’accueil, pourquoi il revient.',
            'tips' => [
                'Demandez l’accord du client avant d’appuyer sur Enregistrer.',
                'Posez les questions une par une, laissez les silences : ne soufflez pas les réponses.',
                'Cadrez le client à hauteur du visage, poitrine comprise, avec un fond simple.',
            ],
            'text_label' => 'Questions à poser au client',
            'text_hint' => 'L’IA propose une phrase d’introduction et des questions ; adaptez-les au client.',
            'placeholder' => 'Ex. : Qu’est-ce que vous cherchiez en entrant ? Qu’avez-vous trouvé ?…',
            'consent' => true,
            'capture' => 'environment',
        ],
    ];
}

function shop_shooting_video_program(string $key): ?array
{
    return shop_shooting_video_programs()[$key] ?? null;
}

/** Consigne envoyée au modèle pour préparer le texte du prompteur. */
function shop_shooting_video_prompt(string $program, string $siteName, string $placeLabel): string
{
    if ($program === 'testimonial') {
        return "Prépare une courte trame pour filmer le témoignage d’un client de « {$siteName} » ({$placeLabel}), en français. "
            . "Réponds uniquement par une phrase d’introduction à dire par le commerçant (15 mots au plus), puis 4 questions courtes "
            . "à poser au client, une par ligne, chacune précédée de « – ». "
            . "Questions ouvertes et concrètes : ce qu’il cherchait, ce qu’il a trouvé, l’accueil, ce qu’il dirait à un ami. "
            . "Aucune question qui suggère la réponse, aucun commentaire, aucun titre, aucun guillemet autour du texte.";
    }
    return "Écris le texte qu’un commerçant dira face caméra pour présenter « {$siteName} » ({$placeLabel}), en français, "
        . "à la première personne du pluriel. Durée visée : 40 à 50 secondes, soit 110 à 130 mots. "
        . "Structure en paragraphes très courts : une accroche concrète, ce que l’on trouve dans la boutique, "
        . "ce qui la distingue, une invitation à venir. "
        . "Ton chaleureux et simple, phrases courtes, aucune exagération, aucun chiffre, prix, récompense ni histoire inventés. "
        . "N’ajoute ni titre, ni didascalie, ni guillemets, ni commentaire : seulement le texte à lire.";
}

/** Nettoie la réponse du modèle : sans markdown ni clôture, 1 200 caractères au plus. */
function shop_shooting_clean_script(?string $text): string
{
    $text = trim((string) $text);
    if ($text === '') return '';
    $text = preg_replace('/^```[a-z]*\s*\n?|\n?```$/i', '', $text) ?? $text;
    $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
    $text = str_replace(['**', '__'], '', $text);
    $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return mb_substr(trim($text), 0, 1200);
}

/** Enregistre une vidéo prise ou importée. Les champs sont déjà bornés par l'appelant. */
function shop_shooting_video_create(array $data): int
{
    shop_shooting_ensure_schema();
    $program = shop_shooting_video_program((string) ($data['program'] ?? '')) !== null
        ? (string) $data['program']
        : 'presentation';
    $stmt = db()->prepare('INSERT INTO shooting_videos
        (program, path, caption, script, author_name, account_id, consent, duration)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $program,
        (string) ($data['path'] ?? ''),
        mb_substr(trim((string) ($data['caption'] ?? '')), 0, 120),
        mb_substr(trim((string) ($data['script'] ?? '')), 0, 1200),
        mb_substr(trim((string) ($data['author_name'] ?? '')), 0, 80),
        $data['account_id'] ?? null,
        !empty($data['consent']) ? 1 : 0,
        max(0, min(180, (int) ($data['duration'] ?? 0))),
    ]);
    return (int) db()->lastInsertId();
}

/** Vidéos de la boutique, de la plus récente à la plus ancienne. */
function shop_shooting_videos(int $limit = 24): array
{
    try {
        shop_shooting_ensure_schema();
        $stmt = db()->query('SELECT * FROM shooting_videos ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function shop_shooting_video_find(int $id): ?array
{
    shop_shooting_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM shooting_videos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Supprime une vidéo : fichier, ligne de médiathèque et fiche en base. */
function shop_shooting_video_delete(int $id): bool
{
    $row = shop_shooting_video_find($id);
    if (!$row) return false;
    $path = (string) $row['path'];
    if (preg_match('#^uploads/videos/shop-video-[A-Za-z0-9._-]+$#', $path)) {
        $abs = dirname(__DIR__) . '/' . $path;
        if (is_file($abs)) @unlink($abs);
    }
    try {
        db()->prepare('DELETE FROM media_library WHERE path = ?')->execute([$path]);
    } catch (Throwable $e) {
    }
    db()->prepare('DELETE FROM shooting_videos WHERE id = ?')->execute([$id]);
    return true;
}
