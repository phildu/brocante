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
            'where' => 'Accueil — section Notre histoire (une seule photo, la nouvelle remplace la précédente)',
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
            return 'Publiée dans la section « Notre histoire ».';
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

/** Tables du parcours : envois en attente et liens de partage. */
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

