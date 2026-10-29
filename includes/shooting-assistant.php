<?php

// Assistant de shooting alternatif (admin/shooting-assistant.php) : analyse la
// première photo (nombre d'objets, qualité, nature), propose un programme de
// prise de vue adapté et un coaching audio. La génération de la fiche reste
// celle du parcours existant (admin/quick-add-action.php).
//
// Rien ici ne modifie le parcours « Nouvelle pièce » : ce fichier n'est chargé
// que par la page et l'endpoint de l'assistant.

/** Libellés des défauts de photo renvoyés par l'IA (clés stables, texte affiché). */
function shooting_issue_labels(): array
{
    return [
        'flou' => 'Photo floue',
        'bouge' => 'Flou de bougé',
        'sombre' => 'Trop sombre',
        'surexpose' => 'Surexposée',
        'cadrage' => 'Mal cadrée',
        'fond_charge' => 'Fond encombré',
        'reflet' => 'Reflets gênants',
        'objet_coupe' => 'Objet coupé',
        'trop_loin' => 'Objet trop petit',
        'plusieurs_objets' => 'Plusieurs objets',
        'contraste' => 'Manque de contraste',
        'autre' => 'À vérifier',
    ];
}

/**
 * Nature et sous-catégorie probables à partir d'un mot libre de l'IA :
 * accepte accents, singulier/pluriel et libellés courants (« vêtement »,
 * « chemise », « pull ») que Gemini renvoie parfois au lieu des clés exactes.
 */
function shooting_nature_resolve(?string $nature, ?string $sub = null): array
{
    $exact = product_nature_resolve($nature, $sub);
    if ($exact['nature'] !== '' && (trim((string) $sub) === '' || $exact['sous_categorie'] !== '')) return $exact;

    $aliases = [
        'clothing' => 'vetements', 'clothes' => 'vetements', 'garment' => 'vetements', 'apparel' => 'vetements',
        'shoe' => 'chaussures', 'shoes' => 'chaussures', 'footwear' => 'chaussures',
        'bag' => 'accessoires', 'handbag' => 'accessoires', 'purse' => 'accessoires', 'scarf' => 'accessoires', 'hat' => 'accessoires',
        'book' => 'livres_medias', 'books' => 'livres_medias', 'vinyl' => 'livres_medias', 'record' => 'livres_medias',
        'jewel' => 'bijoux', 'jewelry' => 'bijoux', 'jewellery' => 'bijoux', 'watch' => 'bijoux',
        'lamp' => 'luminaires', 'lighting' => 'luminaires',
        'furniture' => 'mobilier', 'chair' => 'mobilier', 'table' => 'mobilier',
        'toy' => 'jeux', 'toys' => 'jeux', 'game' => 'jeux',
        'tool' => 'outils_jardin', 'garden' => 'outils_jardin',
        'plate' => 'arts_table', 'dish' => 'arts_table', 'glass' => 'arts_table', 'cup' => 'arts_table',
        'painting' => 'decoration', 'vase' => 'decoration',
        'meuble' => 'mobilier', 'doudoune' => 'vetements', 'vaisselle' => 'arts_table', 'bibelot' => 'decoration', 'tableau' => 'decoration',
    ];

    $tokens = static function (?string $value): array {
        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $stop = ['de', 'du', 'des', 'et', 'a', 'au', 'aux', 'en', 'le', 'la', 'les', 'un', 'une', 'pour', 'avec', 'sur'];
        $out = [];
        foreach (preg_split('/\s+/', trim((string) $value)) ?: [] as $word) {
            if ($word === '' || in_array($word, $stop, true)) continue;
            $out[] = rtrim($word, 'sx') ?: $word;
        }
        return $out;
    };
    $contains = static fn (array $needle, array $haystack): bool => $needle !== [] && !array_diff($needle, $haystack);
    // Sous-catégorie : même mot (100), mot de l'IA contenu dans le libellé (70),
    // libellé contenu dans la réponse (60), mot de la clé (52–55).
    // En dessous de 55 : pas de match.
    $score = static function (array $wanted, string $key, string $label) use ($tokens, $contains): int {
        $keyTokens = $tokens(str_replace('_', ' ', $key));
        $labelTokens = $tokens($label);
        if ($wanted === $labelTokens || $wanted === $keyTokens) return 100;
        if ($contains($wanted, $labelTokens)) return 70 + count($wanted);
        if ($contains($labelTokens, $wanted)) return 60 + count($labelTokens);
        if ($contains($wanted, $keyTokens)) return 55 + count($wanted);
        if ($contains($keyTokens, $wanted)) return 55 + count($keyTokens);
        return 0;
    };

    $natures = product_nature_options();
    $wanted = $tokens($nature);
    $natureKey = $exact['nature'];
    $natureScore = $natureKey !== '' ? 100 : 0;
    if ($natureKey === '') {
        // Une nature ne se devine que sur son libellé ou sa clé (« vêtements de
        // sport » → vetements), jamais sur un mot isolé d'un libellé long
        // (« table » ne doit pas devenir « arts de la table »).
        foreach ($natures as $key => $n) {
            $keyTokens = $tokens(str_replace('_', ' ', $key));
            $labelTokens = $tokens($n['label']);
            $s = 0;
            if ($wanted === $labelTokens || $wanted === $keyTokens) $s = 100;
            elseif ($contains($keyTokens, $wanted)) $s = 55 + count($keyTokens);
            elseif (count($labelTokens) === 1 && $contains($labelTokens, $wanted)) $s = 60 + count($labelTokens);
            if ($s > $natureScore) { $natureScore = $s; $natureKey = $key; }
        }
        if ($natureScore < 55) $natureKey = '';
        if ($natureKey === '' && count($wanted) === 1 && isset($aliases[$wanted[0]])) $natureKey = $aliases[$wanted[0]];
    }

    $subKey = $exact['sous_categorie'];
    $subWanted = $tokens($sub);
    // Nature non reconnue : le mot de l'IA est souvent une sous-catégorie
    // (« chemise », « pull ») — on la retrouve pour en déduire la nature.
    if ($subWanted === [] && $natureKey === '') $subWanted = $wanted;
    $skipSubs = $subWanted === [] || ($natureKey !== '' && $subWanted === $tokens($natures[$natureKey]['label']));
    if (!$skipSubs && $subKey === '') {
        $subScore = 0;
        $subNature = '';
        foreach ($natures as $key => $n) {
            if ($natureKey !== '' && $key !== $natureKey) continue;
            foreach ($n['subs'] as $sk => $label) {
                $s = $score($subWanted, $sk, $label);
                if ($s > $subScore) { $subScore = $s; $subKey = $sk; $subNature = $key; }
            }
        }
        if ($subScore < 55) { $subKey = ''; $subNature = ''; }
        if ($natureKey === '') $natureKey = $subNature;
    }

    return ['nature' => $natureKey, 'sous_categorie' => $subKey];
}

/** Conseils généraux affichés dans le programme (audio et texte). */
function shooting_general_tips(): array
{
    return [
        ['icon' => '☀️', 'text' => "Lumière du jour, près d'une fenêtre, sans flash."],
        ['icon' => '🧺', 'text' => 'Un fond simple et neutre : un mur clair, un drap, une table débarrassée.'],
        ['icon' => '📐', 'text' => "Tenez le téléphone à la hauteur de la pièce, bien droit, à deux mains."],
        ['icon' => '🔍', 'text' => "Laissez une petite marge autour de l'objet : il doit être entier sur chaque photo."],
    ];
}

/**
 * Mesures objectives d'une photo, sans IA (GD) : luminosité, contraste, netteté
 * (variance du laplacien), zones brûlées ou bouchées. Sert de garde-fou quand
 * l'IA n'est pas disponible, et corrige une analyse trop optimiste.
 *
 * @return array{width:int,height:int,aspect:float,brightness:int,contrast:int,sharpness:int,lighting:int,over:float,under:float,issues:string[]}
 */
function shooting_photo_metrics(string $absPath): array
{
    $empty = ['width' => 0, 'height' => 0, 'aspect' => 0.0, 'brightness' => 0, 'contrast' => 0, 'sharpness' => 0, 'lighting' => 0, 'over' => 0.0, 'under' => 0.0, 'issues' => []];
    $info = @getimagesize($absPath);
    $raw = @file_get_contents($absPath);
    if (!$info || $raw === false) {
        return $empty;
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return $empty;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $max = 640;
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $lum = [];
    $sum = 0.0;
    $sum2 = 0.0;
    $over = 0;
    $under = 0;
    $n = $nw * $nh;
    for ($y = 0; $y < $nh; $y++) {
        $row = [];
        for ($x = 0; $x < $nw; $x++) {
            $rgb = imagecolorat($dst, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $l = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            $row[] = $l;
            $sum += $l;
            $sum2 += $l * $l;
            if ($l > 245) $over++;
            if ($l < 12) $under++;
        }
        $lum[] = $row;
    }
    imagedestroy($dst);

    $mean = $sum / max(1, $n);
    $variance = max(0.0, $sum2 / max(1, $n) - $mean * $mean);
    $contrast = (int) round(min(100, sqrt($variance) / 1.28)); // écart-type 0-255 → 0-100

    // Variance du laplacien : élevée sur une photo nette, faible sur une photo floue.
    $lapSum = 0.0;
    $lapSum2 = 0.0;
    $lapN = 0;
    for ($y = 1; $y < $nh - 1; $y++) {
        for ($x = 1; $x < $nw - 1; $x++) {
            $v = 4 * $lum[$y][$x] - $lum[$y - 1][$x] - $lum[$y + 1][$x] - $lum[$y][$x - 1] - $lum[$y][$x + 1];
            $lapSum += $v;
            $lapSum2 += $v * $v;
            $lapN++;
        }
    }
    $lapMean = $lapN ? $lapSum / $lapN : 0.0;
    $lapVar = $lapN ? max(0.0, $lapSum2 / $lapN - $lapMean * $lapMean) : 0.0;
    $sharpness = (int) round(100 * min(1, sqrt($lapVar / 1200)));

    $lighting = 100.0;
    if ($mean < 70) $lighting -= (70 - $mean) * 1.2;
    if ($mean > 200) $lighting -= ($mean - 200) * 1.5;
    $clipped = ($over + $under) / max(1, $n);
    $lighting -= min(55, $clipped * 260);
    $lighting = (int) round(max(0, min(100, $lighting)));

    $issues = [];
    if ($sharpness < 38) $issues[] = 'flou';
    if ($mean < 58) $issues[] = 'sombre';
    if ($mean > 205 || $over / max(1, $n) > 0.12) $issues[] = 'surexpose';
    if ($contrast < 12) $issues[] = 'contraste';

    return [
        'width' => $w,
        'height' => $h,
        'aspect' => $h > 0 ? round($w / $h, 3) : 0.0,
        'brightness' => (int) round($mean / 255 * 100),
        'contrast' => $contrast,
        'sharpness' => $sharpness,
        'lighting' => $lighting,
        'over' => round($over / max(1, $n), 3),
        'under' => round($under / max(1, $n), 3),
        'issues' => $issues,
    ];
}

/** Consigne unique d'analyse envoyée à Gemini (objets, qualité, nature). */
function shooting_analysis_prompt(): string
{
    $natures = product_nature_prompt_list();
    return "Tu es photographe de produit pour une boutique de seconde main (" . tenant('ai.shop') . "). "
        . "Analyse cette photo destinée à la vente en ligne d'" . tenant('ai.item') . ". "
        . "1) Compte les objets distincts que l'on pourrait vendre séparément (un service ou un lot indissociable compte pour un seul objet). "
        . "Donne pour chacun un libellé très court et un cadre box_2d [ymin, xmin, ymax, xmax] en entiers de 0 à 1000 relatifs à l'image. "
        . "2) Reconnais la nature de l'objet principal et sa sous-catégorie, avec les clés EXACTES de cette liste : " . $natures . ". "
        . "3) Évalue la qualité de la photo pour la vente : netteté (flou), cadrage (objet entier, centré, fond propre), lumière (ni trop sombre, ni brûlée). "
        . "Signale les défauts avec UNIQUEMENT ces clés : flou, bouge, sombre, surexpose, cadrage, fond_charge, reflet, objet_coupe, trop_loin, plusieurs_objets, autre. "
        . "Donne au plus 3 conseils courts et concrets pour améliorer la prise de vue. "
        . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown, de cette forme exacte : "
        . '{"count": 1, "objects": [{"label": "nom court", "box_2d": [0, 0, 1000, 1000]}], '
        . '"nature": "clé de nature", "sous_categorie": "clé de sous-catégorie", "subject_label": "nom court de l\'objet principal", '
        . '"quality": {"sharpness": 0, "framing": 0, "lighting": 0, "issues": ["flou"], "advice": ["conseil"]}, '
        . '"ready": true, "summary": "une phrase qui résume la photo et ce qu\'il faut éventuellement refaire"}. '
        . "Les notes sharpness, framing et lighting vont de 0 (inutilisable) à 100 (parfaite). "
        . "Sois honnête : une photo floue, sombre ou mal cadrée doit avoir ready=false.";
}

/**
 * Valide la réponse JSON de l'IA : nettoie les clôtures Markdown, borne les
 * notes, résout la nature et filtre les défauts inconnus.
 */
function shooting_parse_analysis(string $text): ?array
{
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($data)) return null;

    $clamp = static fn ($v, int $min = 0, int $max = 100): int => is_numeric($v) ? max($min, min($max, (int) round((float) $v))) : 0;
    $quality = is_array($data['quality'] ?? null) ? $data['quality'] : [];
    $validIssues = array_keys(shooting_issue_labels());
    $issues = [];
    foreach ((array) ($quality['issues'] ?? []) as $issue) {
        $issue = strtolower(trim((string) $issue));
        if (in_array($issue, $validIssues, true) && !in_array($issue, $issues, true)) $issues[] = $issue;
    }
    $advice = [];
    foreach ((array) ($quality['advice'] ?? []) as $line) {
        $line = trim((string) $line);
        if ($line !== '' && mb_strlen($line) <= 220 && count($advice) < 3) $advice[] = $line;
    }

    $objects = [];
    foreach ((array) ($data['objects'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $box = $item['box_2d'] ?? null;
        if (!is_array($box) || count($box) !== 4) continue;
        [$y0, $x0, $y1, $x1] = array_map(static fn ($v): float => max(0.0, min(1000.0, (float) $v)) / 1000, array_values($box));
        $objects[] = [
            'label' => mb_substr(trim((string) ($item['label'] ?? 'Objet')) ?: 'Objet', 0, 40),
            'box' => [round($x0, 4), round($y0, 4), round($x1 - $x0, 4), round($y1 - $y0, 4)],
        ];
        if (count($objects) >= 8) break;
    }

    $count = max(1, min(8, (int) ($data['count'] ?? count($objects) ?: 1)));
    if (count($objects) > 1) $count = max($count, count($objects));
    if (count($objects) === 1) $count = 1;

    $pair = shooting_nature_resolve((string) ($data['nature'] ?? ''), (string) ($data['sous_categorie'] ?? ''));

    return [
        'count' => $count,
        'objects' => $objects,
        'quality' => [
            'sharpness' => $clamp($quality['sharpness'] ?? 0),
            'framing' => $clamp($quality['framing'] ?? 0),
            'lighting' => $clamp($quality['lighting'] ?? 0),
            'issues' => $issues,
            'advice' => $advice,
        ],
        'ready' => !empty($data['ready']),
        'summary' => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 300),
        'nature' => $pair['nature'],
        'sous_categorie' => $pair['sous_categorie'],
        'nature_raw' => mb_substr(trim((string) ($data['nature'] ?? '')), 0, 60),
        'sub_raw' => mb_substr(trim((string) ($data['sous_categorie'] ?? '')), 0, 60),
        'subject_label' => mb_substr(trim((string) ($data['subject_label'] ?? '')), 0, 60),
    ];
}

/**
 * Fusionne l'analyse IA et les mesures locales : les mesures objectives
 * corrigent une IA trop optimiste (photo réellement floue ou bouchée).
 */
function shooting_merge_analysis(?array $ai, array $metrics, string $manual = 'auto'): array
{
    $ai = $ai ?? [];
    $quality = is_array($ai['quality'] ?? null) ? $ai['quality'] : [];
    $sharpness = is_numeric($quality['sharpness'] ?? null) ? (int) $quality['sharpness'] : (int) ($metrics['sharpness'] ?? 0);
    $framing = is_numeric($quality['framing'] ?? null) ? (int) $quality['framing'] : 70;
    $lighting = is_numeric($quality['lighting'] ?? null) ? (int) $quality['lighting'] : (int) ($metrics['lighting'] ?? 0);

    $issues = array_values(array_unique(array_filter((array) ($quality['issues'] ?? []), static fn ($i): bool => is_string($i) && array_key_exists($i, shooting_issue_labels()))));
    $advice = array_values(array_unique(array_filter((array) ($quality['advice'] ?? []), static fn ($a): bool => is_string($a) && trim($a) !== '')));

    // Garde-fous objectifs, même quand l'IA n'a pas répondu.
    if (($metrics['sharpness'] ?? 0) > 0 && $metrics['sharpness'] < 38) {
        $sharpness = min($sharpness, 45);
        if (!in_array('flou', $issues, true)) $issues[] = 'flou';
    } elseif (($metrics['sharpness'] ?? 0) > 0 && $metrics['sharpness'] < 50) {
        $sharpness = min($sharpness, 65);
    }
    if (($metrics['brightness'] ?? 50) < 23) {
        $lighting = min($lighting, 40);
        if (!in_array('sombre', $issues, true) && !in_array('surexpose', $issues, true)) $issues[] = 'sombre';
    }
    if (($metrics['brightness'] ?? 50) > 83 || ($metrics['over'] ?? 0) > 0.12) {
        $lighting = min($lighting, 45);
        if (!in_array('surexpose', $issues, true)) $issues[] = 'surexpose';
    }
    if (($metrics['contrast'] ?? 100) < 12 && !in_array('contraste', $issues, true)) $issues[] = 'contraste';

    $count = max(1, (int) ($ai['count'] ?? 1));
    if ($count > 1 && !in_array('plusieurs_objets', $issues, true)) $issues[] = 'plusieurs_objets';

    if (in_array('flou', $issues, true) && count($advice) < 3) {
        $advice[] = 'Posez le téléphone sur un support ou tenez-le à deux mains, puis attendez que la mise au point se fasse.';
    }
    if (in_array('sombre', $issues, true) && count($advice) < 3) {
        $advice[] = "Rapprochez-vous d'une fenêtre ou allumez une lampe douce : évitez le flash, qui aplatit la matière.";
    }
    if (in_array('surexpose', $issues, true) && count($advice) < 3) {
        $advice[] = 'Évitez le soleil direct et le flash : mettez la pièce à l\'ombre claire, près d\'une fenêtre.';
    }
    if (in_array('cadrage', $issues, true) && count($advice) < 3) {
        $advice[] = "Reculez un peu pour garder l'objet entier, avec une marge régulière autour.";
    }
    if (in_array('plusieurs_objets', $issues, true) && count($advice) < 3) {
        $advice[] = 'Plusieurs objets sont visibles : choisissez celui que vous vendez, ou photographiez-le seul.';
    }

    $nature = (string) ($ai['nature'] ?? '');
    $sub = (string) ($ai['sous_categorie'] ?? '');
    $kind = $nature === 'vetements' ? 'vetement' : ($nature === 'livres_medias' ? 'media' : 'objet');
    $labels = product_nature_labels($nature, $sub);
    if ($labels === null && $manual !== 'auto') {
        $kind = $manual === 'vetement' ? 'vetement' : ($manual === 'media' ? 'media' : 'objet');
    }

    $blocking = array_intersect($issues, ['flou', 'sombre', 'surexpose', 'objet_coupe', 'cadrage']);
    $ready = !empty($ai['ready']) && $count <= 1 && $sharpness >= 55 && $framing >= 50 && $lighting >= 45 && !$blocking;
    if (empty($ai)) $ready = $count <= 1 && $sharpness >= 55 && $lighting >= 45 && !$blocking;

    $summary = trim((string) ($ai['summary'] ?? ''));
    if ($summary === '') {
        $summary = $count > 1
            ? $count . ' objets distincts sur la photo : choisissez celui que vous vendez.'
            : ($ready ? 'La photo est exploitable : vous pouvez lancer le programme.' : 'La photo mérite une reprise pour une fiche nette et lumineuse.');
    }

    return [
        'count' => $count,
        'objects' => (array) ($ai['objects'] ?? []),
        'quality' => [
            'sharpness' => max(0, min(100, $sharpness)),
            'framing' => max(0, min(100, $framing)),
            'lighting' => max(0, min(100, $lighting)),
            'issues' => $issues,
            'advice' => array_slice($advice, 0, 4),
        ],
        'ready' => $ready,
        'summary' => mb_substr($summary, 0, 300),
        'subject' => [
            'kind' => $kind,
            'nature' => $nature,
            'sous_categorie' => $sub,
            'nature_raw' => (string) ($ai['nature_raw'] ?? ''),
            'sub_raw' => (string) ($ai['sub_raw'] ?? ''),
            'label' => mb_substr(trim((string) ($ai['subject_label'] ?? '')), 0, 60),
            'nature_label' => $labels[0] ?? '',
            'sub_label' => $labels[1] ?? '',
        ],
    ];
}

/** Programmes de prise de vue : chaque vue a un conseil parlé pour le coaching audio. */
function shooting_programs(): array
{
    return [
        'objet' => [
            'title' => 'Les vues essentielles de la pièce',
            'icon' => '📦',
            'kind' => 'objet',
            'intro' => "Cinq vues suffisent pour une pièce bien décrite : face, profil, dos, un détail de marque et, si besoin, le défaut.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => "L'objet entier, de face, centré, sur un fond simple.",
                    'coach' => "Photo une : la face. Posez la pièce au centre, laissez un peu d'espace autour, et placez le téléphone à sa hauteur."],
                ['key' => 'profil', 'label' => 'Profil', 'required' => true,
                    'tip' => "De côté, pour montrer l'épaisseur et la silhouette.",
                    'coach' => "Photo deux : le profil. Tournez la pièce d'un quart de tour pour montrer son épaisseur et sa forme."],
                ['key' => 'dos', 'label' => 'Dos', 'required' => true,
                    'tip' => "L'arrière, avec les marques ou étiquettes visibles.",
                    'coach' => "Photo trois : le dos. Montrez l'arrière de la pièce, en gardant les étiquettes bien lisibles."],
                ['key' => 'detail', 'label' => 'Détail / marque', 'required' => true,
                    'tip' => 'Gros plan sur la signature, le poinçon, la marque ou la matière.',
                    'coach' => "Photo quatre : un détail. Rapprochez-vous de la signature, du poinçon ou de la matière, et laissez la mise au point se faire."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => "S'il y a un défaut, montrez-le franchement, de près.",
                    'coach' => "Dernière photo, facultative : le défaut. Si la pièce en a un, montrez-le de près et sans le cacher. Sinon, passez cette vue."],
            ],
        ],
        'vetement' => [
            'title' => 'Vêtement : recto, verso et étiquette',
            'icon' => '👕',
            'kind' => 'vetement',
            'intro' => "Quatre photos racontent un vêtement : le recto, le verso, l'étiquette et la matière. À plat ou sur cintre, sans plis inutiles.",
            'shots' => [
                ['key' => 'recto', 'label' => 'Recto', 'required' => true,
                    'tip' => 'Le vêtement entier, à plat ou sur cintre, de face.',
                    'coach' => "Photo une : le recto. Installez le vêtement bien à plat, ou sur un cintre, et cadrez-le entier."],
                ['key' => 'verso', 'label' => 'Verso', 'required' => true,
                    'tip' => 'Le même vêtement vu de dos, sans le plier.',
                    'coach' => "Photo deux : le verso. Retournez le vêtement et photographiez le dos, sans le plier."],
                ['key' => 'etiquette', 'label' => 'Étiquette', 'required' => true,
                    'tip' => 'Marque, taille, composition : le texte doit être lisible.',
                    'coach' => "Photo trois : l'étiquette. Approchez-vous pour que la marque, la taille et la composition soient parfaitement lisibles."],
                ['key' => 'matiere', 'label' => 'Matière', 'required' => true,
                    'tip' => 'Gros plan sur le tissu, une couture ou un motif.',
                    'coach' => "Photo quatre : la matière. Faites un gros plan sur le tissu, une couture ou un motif, pour montrer la qualité."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => "Tache, accroc, usure : montrez-le de près.",
                    'coach' => "Dernière photo, facultative : le défaut. S'il y a une tache ou un accroc, montrez-le de près, sans le cacher."],
            ],
        ],
        'chaussures' => [
            'title' => 'Chaussures : la paire sous tous les angles',
            'icon' => '👟',
            'kind' => 'objet',
            'intro' => "Pour des chaussures, montrez la paire de profil, le dessus, la semelle et l'intérieur : l'acheteur veut voir l'usure réelle.",
            'shots' => [
                ['key' => 'profil', 'label' => 'Paire de profil', 'required' => true,
                    'tip' => 'Les deux chaussures côte à côte, de profil.',
                    'coach' => "Photo une : la paire de profil. Placez les deux chaussures côte à côte, talons alignés, et photographiez-les de côté."],
                ['key' => 'dessus', 'label' => 'Dessus', 'required' => true,
                    'tip' => 'Le dessus et les lacets, vus de trois quarts.',
                    'coach' => "Photo deux : le dessus. Prenez les chaussures de trois quarts pour montrer le dessus et les lacets."],
                ['key' => 'semelle', 'label' => 'Semelle', 'required' => true,
                    'tip' => "La semelle, pour montrer l'usure.",
                    'coach' => "Photo trois : la semelle. Retournez la paire et montrez l'usure de la semelle."],
                ['key' => 'interieur', 'label' => 'Intérieur / marque', 'required' => true,
                    'tip' => "L'intérieur, la pointure et la marque.",
                    'coach' => "Photo quatre : l'intérieur. Montrez la pointure et la marque, bien lisibles au fond de la chaussure."],
            ],
        ],
        'accessoires' => [
            'title' => 'Accessoire : forme, détail et intérieur',
            'icon' => '👜',
            'kind' => 'objet',
            'intro' => "Un sac, une ceinture ou un chapeau se vend sur la forme, la matière et les détails de finition.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => "L'accessoire entier, de face.",
                    'coach' => "Photo une : la face. Montrez l'accessoire entier, bien à plat ou posé, sur un fond simple."],
                ['key' => 'profil', 'label' => 'Profil', 'required' => true,
                    'tip' => "De côté, pour l'épaisseur et la ligne.",
                    'coach' => "Photo deux : le profil. Tournez l'accessoire pour montrer son épaisseur et sa silhouette."],
                ['key' => 'interieur', 'label' => 'Intérieur', 'required' => true,
                    'tip' => "L'intérieur, les poches ou la doublure.",
                    'coach' => "Photo trois : l'intérieur. Ouvrez-le pour montrer les poches, la doublure et l'état général."],
                ['key' => 'detail', 'label' => 'Détail / marque', 'required' => true,
                    'tip' => 'Fermeture, logo, couture ou boucle.',
                    'coach' => "Photo quatre : un détail. Rapprochez-vous de la fermeture, du logo, de la couture ou de la boucle."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => 'Usure, rayure ou accroc, de près.',
                    'coach' => "Dernière photo, facultative : le défaut. Montrez de près toute usure ou rayure, sans la cacher."],
            ],
        ],
        'bijoux' => [
            'title' => 'Bijou : ensemble, échelle et poinçon',
            'icon' => '💍',
            'kind' => 'objet',
            'intro' => "Un bijou se juge sur sa vue d'ensemble, son échelle et ses poinçons. Une lumière douce évite les reflets.",
            'shots' => [
                ['key' => 'ensemble', 'label' => "Vue d'ensemble", 'required' => true,
                    'tip' => 'Le bijou entier, posé sur un fond uni.',
                    'coach' => "Photo une : la vue d'ensemble. Posez le bijou sur un fond uni, et évitez les reflets en vous plaçant à côté de la fenêtre."],
                ['key' => 'echelle', 'label' => 'Échelle', 'required' => true,
                    'tip' => 'Le bijou porté, ou à côté d\'une pièce de monnaie.',
                    'coach' => "Photo deux : l'échelle. Montrez le bijou porté, ou posez une pièce de monnaie à côté pour donner la taille."],
                ['key' => 'poincon', 'label' => 'Poinçon / fermoir', 'required' => true,
                    'tip' => 'Le poinçon, la marque ou le fermoir, de très près.',
                    'coach' => "Photo trois : le poinçon. Approchez-vous très près du poinçon, de la marque ou du fermoir, et stabilisez le téléphone."],
                ['key' => 'detail', 'label' => 'Détail', 'required' => true,
                    'tip' => 'Une pierre, une gravure ou un motif.',
                    'coach' => "Photo quatre : un détail. Montrez une pierre, une gravure ou un motif qui donne envie."],
            ],
        ],
        'media' => [
            'title' => 'Livre ou média : couverture, dos et intérieur',
            'icon' => '📚',
            'kind' => 'media',
            'intro' => "Pour un livre, un disque ou un DVD, la couverture, le dos et l'intérieur disent tout : édition, état, code-barres.",
            'shots' => [
                ['key' => 'couverture', 'label' => 'Couverture', 'required' => true,
                    'tip' => 'La couverture ou la pochette, de face, bien à plat.',
                    'coach' => "Photo une : la couverture. Posez le livre ou la pochette bien à plat, et cadrez-la entièrement, sans reflet."],
                ['key' => 'dos', 'label' => 'Dos / tranche', 'required' => true,
                    'tip' => 'Le dos, la tranche ou la liste des titres.',
                    'coach' => "Photo deux : le dos. Montrez le dos, la tranche ou la liste des titres, selon le support."],
                ['key' => 'pages', 'label' => 'Page de titre / code', 'required' => true,
                    'tip' => 'La page de titre, le code-barres ou l\'ISBN.',
                    'coach' => "Photo trois : l'identification. Ouvrez à la page de titre, ou montrez le code-barres et l'ISBN, bien lisibles."],
                ['key' => 'interieur', 'label' => 'Intérieur', 'required' => true,
                    'tip' => "Quelques pages, le disque ou l'état intérieur.",
                    'coach' => "Photo quatre : l'intérieur. Montrez quelques pages, le disque ou l'état intérieur, pour rassurer sur l'usure."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => 'Corner plié, rayure, annotation : de près.',
                    'coach' => "Dernière photo, facultative : le défaut. Montrez de près un coin plié, une rayure ou une annotation."],
            ],
        ],
        'vaisselle' => [
            'title' => 'Vaisselle : face, dessous et intérieur',
            'icon' => '🍽️',
            'kind' => 'objet',
            'intro' => "Une assiette, un vase ou une théière se vend sur la forme, le dessous signé et l'intérieur.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => 'La pièce entière, de face, en lumière douce.',
                    'coach' => "Photo une : la face. Placez la pièce au centre, sur un fond simple, et évitez que la lumière fasse un reflet sur l'émail."],
                ['key' => 'profil', 'label' => 'Profil', 'required' => true,
                    'tip' => 'De côté, pour la forme et les proportions.',
                    'coach' => "Photo deux : le profil. Tournez la pièce pour montrer sa forme et ses proportions."],
                ['key' => 'dessous', 'label' => 'Dessous / signature', 'required' => true,
                    'tip' => 'Le dessous : poinçon, signature ou fabrication.',
                    'coach' => "Photo trois : le dessous. Retournez la pièce : le poinçon, la signature ou la fabrication sont souvent là."],
                ['key' => 'interieur', 'label' => 'Intérieur / détail', 'required' => true,
                    'tip' => "L'intérieur, le décor ou un détail de l'émail.",
                    'coach' => "Photo quatre : l'intérieur. Montrez l'intérieur, le décor ou un détail de l'émail, de près."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => 'Éclat, fêle ou usure, de près.',
                    'coach' => "Dernière photo, facultative : le défaut. Montrez de près tout éclat, fêle ou trace d'usure."],
            ],
        ],
        'deco' => [
            'title' => 'Décoration : face, profil et signature',
            'icon' => '🖼️',
            'kind' => 'objet',
            'intro' => "Un objet décoratif se raconte en trois temps : la vue d'ensemble, le profil et le détail qui signe son époque.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => "L'objet entier, de face, sur un fond neutre.",
                    'coach' => "Photo une : la face. Posez l'objet au centre, sur un fond neutre, et cadrez-le entier."],
                ['key' => 'profil', 'label' => 'Profil', 'required' => true,
                    'tip' => 'De côté, pour le volume et la profondeur.',
                    'coach' => "Photo deux : le profil. Tournez l'objet pour montrer son volume et sa profondeur."],
                ['key' => 'signature', 'label' => 'Signature / poinçon', 'required' => true,
                    'tip' => 'La signature, le poinçon ou l\'étiquette sous l\'objet.',
                    'coach' => "Photo trois : la signature. Regardez dessous ou derrière : montrez de près la signature, le poinçon ou l'étiquette."],
                ['key' => 'detail', 'label' => 'Détail', 'required' => true,
                    'tip' => 'Une matière, un motif ou un défaut caractéristique.',
                    'coach' => "Photo quatre : un détail. Rapprochez-vous d'une matière, d'un motif ou d'un défaut caractéristique."],
            ],
        ],
        'mobilier' => [
            'title' => 'Mobilier : ensemble, profil et détails',
            'icon' => '🪑',
            'kind' => 'objet',
            'intro' => "Pour un meuble, l'ensemble donne l'allure, le profil donne les proportions, et les détails donnent la qualité.",
            'shots' => [
                ['key' => 'ensemble', 'label' => "Vue d'ensemble", 'required' => true,
                    'tip' => 'Le meuble entier, reculé, sur un fond dégagé.',
                    'coach' => "Photo une : le meuble entier. Reculez assez pour le cadrer en entier, avec de l'espace autour."],
                ['key' => 'profil', 'label' => 'Profil', 'required' => true,
                    'tip' => 'De côté, pour la profondeur et les proportions.',
                    'coach' => "Photo deux : le profil. Placez-vous de côté pour montrer la profondeur et les proportions."],
                ['key' => 'detail', 'label' => 'Détail / matériau', 'required' => true,
                    'tip' => 'Bois, poignée, assemblage ou finition.',
                    'coach' => "Photo trois : le matériau. Rapprochez-vous du bois, d'une poignée, d'un assemblage ou d'une finition."],
                ['key' => 'dessous', 'label' => 'Marque / dessous', 'required' => true,
                    'tip' => "L'étiquette, la marque ou le dessous d'un tiroir.",
                    'coach' => "Photo quatre : la marque. Cherchez une étiquette ou une marque sous le meuble ou dans un tiroir."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => 'Rayure, éclat ou usure, de près.',
                    'coach' => "Dernière photo, facultative : le défaut. Montrez de près une rayure, un éclat ou une usure."],
            ],
        ],
        'luminaire' => [
            'title' => 'Luminaire : allumé, éteint et détails',
            'icon' => '💡',
            'kind' => 'objet',
            'intro' => "Un luminaire se vend allumé et éteint : la lumière montre l'ambiance, l'extérieur montre la matière.",
            'shots' => [
                ['key' => 'allume', 'label' => 'Allumé', 'required' => true,
                    'tip' => "Le luminaire allumé, dans une pièce un peu sombre.",
                    'coach' => "Photo une : allumé. Baissez un peu la lumière de la pièce, allumez le luminaire et montrez la lumière qu'il diffuse."],
                ['key' => 'eteint', 'label' => 'Éteint', 'required' => true,
                    'tip' => 'Le luminaire éteint, entier, sur un fond simple.',
                    'coach' => "Photo deux : éteint. Montrez le luminaire entier, éteint, sur un fond simple."],
                ['key' => 'detail', 'label' => 'Matériau / détail', 'required' => true,
                    'tip' => 'Le pied, l\'abat-jour, la douille ou le câble.',
                    'coach' => "Photo trois : un détail. Montrez le pied, l'abat-jour, la douille ou le câble, de près."],
                ['key' => 'etiquette', 'label' => 'Étiquette', 'required' => false,
                    'tip' => 'La marque ou l\'étiquette sous le pied.',
                    'coach' => "Dernière photo, facultative : l'étiquette. Regardez sous le pied : montrez la marque ou l'étiquette si elle est lisible."],
            ],
        ],
        'textile' => [
            'title' => 'Linge de maison : matière et motif',
            'icon' => '🧵',
            'kind' => 'objet',
            'intro' => "Pour un drap, une nappe ou un plaid, la matière et le motif comptent autant que la pièce entière.",
            'shots' => [
                ['key' => 'face', 'label' => 'Pièce entière', 'required' => true,
                    'tip' => 'Le textile déplié, entier, sans plis.',
                    'coach' => "Photo une : la pièce entière. Dépliez le textile, lissez-le du plat de la main et cadrez-le en entier."],
                ['key' => 'motif', 'label' => 'Motif / bordure', 'required' => true,
                    'tip' => 'Le motif, la bordure ou la broderie.',
                    'coach' => "Photo deux : le motif. Rapprochez-vous d'une bordure, d'une broderie ou du motif principal."],
                ['key' => 'matiere', 'label' => 'Matière', 'required' => true,
                    'tip' => 'Gros plan sur le tissage et la texture.',
                    'coach' => "Photo trois : la matière. Faites un gros plan sur le tissage, pour montrer la texture et la qualité."],
                ['key' => 'etiquette', 'label' => 'Étiquette', 'required' => false,
                    'tip' => 'Composition, taille ou marque, si présente.',
                    'coach' => "Dernière photo, facultative : l'étiquette. Montrez la composition ou la marque si elle est encore là."],
            ],
        ],
        'collection' => [
            'title' => 'Objet de collection : face, dos et signature',
            'icon' => '🏺',
            'kind' => 'objet',
            'intro' => "Un objet de collection se prouve : face, dos, signature et échelle. Les collectionneurs regardent les détails.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => "L'objet entier, de face, en lumière douce.",
                    'coach' => "Photo une : la face. Montrez l'objet entier, de face, avec une lumière douce et sans reflet."],
                ['key' => 'dos', 'label' => 'Dos', 'required' => true,
                    'tip' => "L'arrière ou le dessous, avec les marques.",
                    'coach' => "Photo deux : le dos. Montrez l'arrière ou le dessous, là où se trouvent les marques."],
                ['key' => 'signature', 'label' => 'Signature / poinçon', 'required' => true,
                    'tip' => 'La signature, le poinçon ou le cachet, de très près.',
                    'coach' => "Photo trois : la signature. Approchez-vous très près de la signature, du poinçon ou du cachet."],
                ['key' => 'echelle', 'label' => 'Échelle', 'required' => true,
                    'tip' => "L'objet à côté d'un repère (règle, pièce, main).",
                    'coach' => "Photo quatre : l'échelle. Posez une règle, une pièce ou votre main à côté pour donner la taille réelle."],
            ],
        ],
        'electronique' => [
            'title' => 'Appareil : face, connectique et accessoires',
            'icon' => '📻',
            'kind' => 'objet',
            'intro' => "Un appareil se vend sur son état visible, ses connectiques, sa référence et ses accessoires.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => "L'appareil entier, de face, éteint puis allumé si possible.",
                    'coach' => "Photo une : la face. Montrez l'appareil entier de face ; s'il s'allume, une photo allumée rassure."],
                ['key' => 'arriere', 'label' => 'Arrière / connectique', 'required' => true,
                    'tip' => 'Les connecteurs, branchements et étiquettes.',
                    'coach' => "Photo deux : l'arrière. Montrez les connecteurs et les étiquettes, sans les cacher."],
                ['key' => 'reference', 'label' => 'Marque / référence', 'required' => true,
                    'tip' => 'La plaque ou l\'étiquette avec le modèle exact.',
                    'coach' => "Photo trois : la référence. Approchez-vous de la plaque ou de l'étiquette pour lire le modèle exact."],
                ['key' => 'accessoires', 'label' => 'Accessoires', 'required' => true,
                    'tip' => 'Télécommande, câbles, boîte ou notice fournis.',
                    'coach' => "Photo quatre : les accessoires. Rassemblez la télécommande, les câbles, la boîte ou la notice fournis."],
                ['key' => 'defaut', 'label' => 'Défaut', 'required' => false,
                    'tip' => 'Rayure, écran marqué ou pièce manquante.',
                    'coach' => "Dernière photo, facultative : le défaut. Montrez de près une rayure, un écran marqué ou une pièce manquante."],
            ],
        ],
        'jeux' => [
            'title' => 'Jeu ou jouet : ensemble et contenu',
            'icon' => '🧩',
            'kind' => 'objet',
            'intro' => "Pour un jeu ou un jouet, l'acheteur veut voir l'ensemble, le contenu complet et l'état de la boîte.",
            'shots' => [
                ['key' => 'ensemble', 'label' => "Vue d'ensemble", 'required' => true,
                    'tip' => "L'ensemble, boîte comprise, sur un fond simple.",
                    'coach' => "Photo une : l'ensemble. Montrez le jeu ou le jouet avec sa boîte, sur un fond simple."],
                ['key' => 'contenu', 'label' => 'Contenu', 'required' => true,
                    'tip' => 'Toutes les pièces étalées, pour prouver qu\'il est complet.',
                    'coach' => "Photo deux : le contenu. Étalez toutes les pièces pour montrer que le jeu est complet."],
                ['key' => 'boite', 'label' => 'Boîte / notice', 'required' => true,
                    'tip' => 'La boîte, la notice ou les règles.',
                    'coach' => "Photo trois : la boîte. Montrez la boîte, la notice ou les règles, avec les coins et les bords visibles."],
                ['key' => 'detail', 'label' => 'Détail', 'required' => false,
                    'tip' => 'Une pièce rare, une marque ou un défaut.',
                    'coach' => "Dernière photo, facultative : un détail. Montrez une pièce rare, une marque ou un défaut."],
            ],
        ],
        'beaute' => [
            'title' => 'Beauté : produit, ouverture et texture',
            'icon' => '🧴',
            'kind' => 'objet',
            'intro' => "Pour un produit de beauté, montrez le contenant, l'ouverture, la texture et le niveau restant.",
            'shots' => [
                ['key' => 'face', 'label' => 'Face', 'required' => true,
                    'tip' => 'Le produit entier, étiquette lisible.',
                    'coach' => "Photo une : la face. Montrez le produit entier, avec l'étiquette bien lisible."],
                ['key' => 'ouverture', 'label' => 'Ouverture / niveau', 'required' => true,
                    'tip' => 'Le bouchon ouvert, pour montrer le niveau restant.',
                    'coach' => "Photo deux : l'ouverture. Ouvrez le produit pour montrer le niveau restant, sans flasher."],
                ['key' => 'texture', 'label' => 'Texture', 'required' => false,
                    'tip' => 'Une touche de produit sur la main ou un support propre.',
                    'coach' => "Photo trois, facultative : la texture. Montrez une touche de produit sur votre main ou sur un support propre."],
                ['key' => 'lot', 'label' => 'Lot / date', 'required' => false,
                    'tip' => 'Le numéro de lot ou la date, si visible.',
                    'coach' => "Dernière photo, facultative : le lot. Montrez le numéro de lot ou la date s'ils sont visibles."],
            ],
        ],
    ];
}

/** Nature (products.nature) → clé de programme. */
function shooting_nature_program_map(): array
{
    return [
        'vetements' => 'vetement',
        'chaussures' => 'chaussures',
        'accessoires' => 'accessoires',
        'bijoux' => 'bijoux',
        'linge' => 'textile',
        'decoration' => 'deco',
        'arts_table' => 'vaisselle',
        'mobilier' => 'mobilier',
        'luminaires' => 'luminaire',
        'livres_medias' => 'media',
        'jeux' => 'jeux',
        'collection' => 'collection',
        'musique_electro' => 'electronique',
        'tv_hifi' => 'electronique',
        'informatique' => 'electronique',
        'telephonie' => 'electronique',
        'electromenager' => 'electronique',
        'beaute' => 'beaute',
    ];
}

/** Choix manuel de l'utilisateur (sans IA) → clé de programme. */
function shooting_manual_program_map(): array
{
    return [
        'objet' => 'objet',
        'vetement' => 'vetement',
        'media' => 'media',
        'chaussures' => 'chaussures',
        'bijou' => 'bijoux',
        'mobilier' => 'mobilier',
    ];
}

/** Programme retenu : choix manuel d'abord, sinon nature détectée, sinon objet. */
function shooting_program_for(?string $nature, string $manual = 'auto'): array
{
    $manualMap = shooting_manual_program_map();
    $natureMap = shooting_nature_program_map();
    $key = $manual !== 'auto' ? ($manualMap[$manual] ?? 'objet') : ($natureMap[(string) $nature] ?? 'objet');
    $programs = shooting_programs();
    $program = $programs[$key] ?? $programs['objet'];
    $program['key'] = $key;
    return $program;
}
