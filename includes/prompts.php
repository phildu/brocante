<?php

// Prompts (« Mots-clés / précisions ») enregistrés pour être réutilisés : consignes données à l'IA
// pour une génération (mise en situation, autre angle, compléter l'objet) ou pour la fiche d'une pièce
// (« Indications pour l'IA »). Propres à chaque commerce ; partagés par toute son équipe.

/** Types de prompts : un par génération de la galerie, plus les indications de fiche. */
const SAVED_PROMPT_KINDS = ['ambiance', 'angle', 'complete', 'video_ai', 'notes'];
const SAVED_PROMPT_MAX_LENGTH = 300;
/** Prompts gardés au plus par type (au-delà, il faut en supprimer avant d'en ajouter). */
const SAVED_PROMPT_MAX_PER_KIND = 40;

function prompts_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'saved_prompts'")->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_prompts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      kind TEXT NOT NULL,
      text TEXT NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (kind, text)
    )");
}

/** Prompts enregistrés, les plus récents d'abord ; tous types, ou un seul. */
function saved_prompts_list(?string $kind = null): array
{
    $stmt = $kind === null
        ? db()->query('SELECT id, kind, text FROM saved_prompts ORDER BY id DESC')
        : db()->prepare('SELECT id, kind, text FROM saved_prompts WHERE kind = ? ORDER BY id DESC');
    if ($kind !== null) $stmt->execute([$kind]);
    return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'text' => $r['text']], $stmt->fetchAll());
}

/**
 * Enregistre un prompt ; retourne ['ok' => true, 'created' => bool] (created faux : déjà enregistré)
 * ou ['ok' => false, 'error' => …].
 */
function saved_prompt_add(string $kind, string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (!in_array($kind, SAVED_PROMPT_KINDS, true)) return ['ok' => false, 'error' => 'Type de prompt inconnu.'];
    if ($text === '') return ['ok' => false, 'error' => 'Saisissez d\'abord un prompt à enregistrer.'];
    if (mb_strlen($text) > SAVED_PROMPT_MAX_LENGTH) return ['ok' => false, 'error' => 'Prompt trop long (' . SAVED_PROMPT_MAX_LENGTH . ' caractères au plus).'];
    $exists = db()->prepare('SELECT 1 FROM saved_prompts WHERE kind = ? AND text = ?');
    $exists->execute([$kind, $text]);
    if ($exists->fetchColumn()) return ['ok' => true, 'created' => false];
    $count = db()->prepare('SELECT COUNT(*) FROM saved_prompts WHERE kind = ?');
    $count->execute([$kind]);
    if ((int) $count->fetchColumn() >= SAVED_PROMPT_MAX_PER_KIND) {
        return ['ok' => false, 'error' => 'Liste pleine (' . SAVED_PROMPT_MAX_PER_KIND . ' prompts) : supprimez-en un avant d\'en ajouter.'];
    }
    db()->prepare('INSERT INTO saved_prompts (kind, text) VALUES (?, ?)')->execute([$kind, $text]);
    return ['ok' => true, 'created' => true];
}

function saved_prompt_delete(int $id): void
{
    db()->prepare('DELETE FROM saved_prompts WHERE id = ?')->execute([$id]);
}

/**
 * VERSION FRANÇAISE des prompts de génération, pour l'aperçu sous le champ « Mots-clés / précisions » : le
 * modèle reçoit les prompts anglais de includes/functions.php (build_*_prompt, generated_framing_prompt,
 * owner_direction_prompt) ; ces fonctions en sont la traduction fidèle, phrase à phrase. À MODIFIER EN MÊME TEMPS
 * qu'eux, sinon l'aperçu ne dirait plus ce qui est envoyé.
 */
function owner_direction_prompt_fr(string $keywords): string
{
    return $keywords === '' ? '' : " CONSIGNE DU VENDEUR (impérative, elle prime sur tout réglage par défaut ci-dessus qui la contredit) : « "
        . $keywords . " ». Suis-la fidèlement — décor, lumière, cadrage (gros plan, plan large, angle), action, et toute personne qu'elle mentionne — en gardant l'objet lui-même identique.";
}

function build_ambiance_prompt_fr(string $keywords): string
{
    if ($keywords === '') {
        return "Voici une vraie photo d'un objet d'occasion / vintage pour une boutique d'antiquités en ligne. "
            . "Génère une photo mise en scène montrant exactement le MÊME objet, placé naturellement dans un intérieur "
            . "français chaleureux (un salon ou une cuisine aux tons de bois chauds), comme pour une photo de produit "
            . "lifestyle, photoréaliste, lumière naturelle du jour, sans texte, sans filigrane, sans personne. "
            . "Réponds uniquement avec l'image générée, sans texte dans ta réponse.";
    }
    return "Voici une vraie photo d'un objet d'occasion / vintage pour une boutique d'antiquités en ligne. "
        . "Génère une photo lifestyle mise en scène, photoréaliste, montrant exactement le MÊME objet (forme, couleurs, "
        . "matières, motifs et détails identiques — ne jamais le redessiner), comme pour une photo de produit lifestyle, "
        . "lumière naturelle, sans texte, sans filigrane. Décor par défaut, à utiliser UNIQUEMENT si la consigne du vendeur "
        . "ci-dessous ne décrit ni décor, ni action, ni personne : un intérieur français chaleureux (salon ou cuisine aux "
        . "tons de bois chauds), sans personne. Si la consigne décrit une scène, une action ou une personne, cette scène "
        . "remplace le décor par défaut — pour un vêtement ou un accessoire, l'article peut alors être porté ou tenu, "
        . "en portant exactement cet article."
        . owner_direction_prompt_fr($keywords)
        . " Réponds uniquement avec l'image générée, sans texte dans ta réponse.";
}

function build_angle_prompt_fr(string $anglePreset, string $keywords): string
{
    $angles = [
        'auto' => 'sous un angle différent de la photo d\'origine',
        'dessus' => 'directement du dessus, en vue plongeante verticale',
        'dessous' => 'd\'en dessous, en contre-plongée',
        'trois-quarts' => 'de trois quarts',
        'face' => 'de face, face à l\'objet',
        'profil' => 'de côté, en vue de profil',
        'arriere' => 'de l\'arrière de l\'objet',
    ];
    return "Voici une vraie photo d'un objet d'occasion / vintage pour une boutique d'antiquités en ligne. "
        . "Génère une photo du MÊME objet exactement (ou des mêmes objets), photographié " . ($angles[$anglePreset] ?? $angles['auto']) . ", "
        . "sur un fond de studio gris clair neutre, simple et uni, avec un éclairage naturel doux, "
        . "photoréaliste, sans texte, sans filigrane, sans personne. "
        . "Réponds uniquement avec l'image générée, sans texte dans ta réponse."
        . owner_direction_prompt_fr($keywords);
}

function build_complete_prompt_fr(string $keywords): string
{
    return "Voici une vraie photo d'un objet d'occasion / vintage pour une boutique d'antiquités en ligne, mais "
        . "l'objet est coupé par le bord du cadre — une partie manque sur l'image. "
        . "Génère exactement le MÊME objet montré en entier, en prolongeant / complétant les parties coupées "
        . "de façon cohérente avec son style, ses matières, ses proportions et sa construction visibles, "
        . "sur un fond de studio gris clair neutre, simple et uni, avec un éclairage naturel doux, "
        . "photoréaliste, sans texte, sans filigrane, sans personne. "
        . "Réponds uniquement avec l'image générée, sans texte dans ta réponse."
        . owner_direction_prompt_fr($keywords);
}

function generated_framing_prompt_fr(string $aspectRatio, bool $directed = false): string
{
    $orientation = $aspectRatio === '9:16' ? 'vertical (portrait)' : 'horizontal (paysage)';
    $fill = $directed
        ? "Remplis tout l'espace restant en prolongeant naturellement la scène, de façon cohérente avec le décor demandé dans la "
            . "consigne du vendeur — toute zone unie ou floue autour de la photo est de la toile vide à remplacer."
        : "Remplis tout l'espace restant en prolongeant naturellement le décor (mur, sol, surface, pièce) — "
            . "toute zone unie ou floue autour de la photo est de la toile vide à remplacer par un décor cohérent.";
    $unless = $directed
        ? "Sauf si la consigne du vendeur demande explicitement un autre cadrage (gros plan, plan large, plongée ou contre-plongée, "
            . "composition décentrée), auquel cas suis-la, garde ce cadrage : "
        : "Garde ce cadrage : ";
    return "CADRAGE (impératif) : l'image produite est une image $aspectRatio $orientation. L'image d'entrée a déjà été posée "
        . "sur une toile de ce format exact, avec l'objet entièrement visible et une marge autour de lui. " . $unless
        . "l'objet ENTIER doit rester visible, jamais coupé par un bord du cadre, avec de l'espace vide de chaque côté "
        . "(au moins 8 % du cadre). Ne zoome pas, ne recadre pas, n'agrandis pas l'objet pour remplir le cadre. " . $fill;
}

/**
 * Prompt réellement envoyé à l'IA pour une génération, en blocs [label, text, text_fr] : `text` est celui que
 * construit le serveur au moment de générer (mêmes fonctions), `text_fr` sa traduction fidèle, plus le cadrage ajouté
 * automatiquement. Sert à l'aperçu affiché sous le champ « Mots-clés / précisions ». $kind : ambiance, angle,
 * complete ou notes (indications de fiche : la mise en situation ET la rédaction de la fiche).
 */
function prompt_preview_parts(string $kind, string $text, string $anglePreset = 'auto'): ?array
{
    $text = trim($text);
    $directed = $text !== '';
    $label = "Prompt envoyé à l'IA";
    $framing = static fn (bool $d): array => [
        'label' => 'Cadrage ajouté automatiquement (format 3:2)',
        'text' => trim(generated_framing_prompt(GENERATED_IMAGE_FORMATS['desktop'], $d)),
        'text_fr' => generated_framing_prompt_fr(GENERATED_IMAGE_FORMATS['desktop'], $d),
    ];
    switch ($kind) {
        case 'ambiance':
            return [['label' => $label, 'text' => build_ambiance_prompt($text), 'text_fr' => build_ambiance_prompt_fr($text)], $framing($directed)];
        case 'angle':
            return [['label' => $label, 'text' => build_angle_prompt($anglePreset, $text), 'text_fr' => build_angle_prompt_fr($anglePreset, $text)], $framing(false)];
        case 'complete':
            return [['label' => $label, 'text' => build_complete_prompt($text), 'text_fr' => build_complete_prompt_fr($text)], $framing(false)];
        case 'video_ai':
            return [['label' => "Prompt envoyé à Veo (mouvement : au choix de l'IA)", 'text' => build_veo_prompt('auto', $text), 'text_fr' => build_veo_prompt_fr('auto', $text)]];
        case 'notes':
            // Le prompt de la fiche est déjà rédigé en français.
            $sheet = build_product_sheet_prompt(1, $text);
            return [
                ['label' => 'Mise en situation', 'text' => build_ambiance_prompt($text), 'text_fr' => build_ambiance_prompt_fr($text)],
                $framing($directed),
                ['label' => 'Fiche (nom, description, catégorie, prix)', 'text' => $sheet, 'text_fr' => $sheet],
            ];
    }
    return null;
}

/**
 * Idées de décors / situations pour une pièce, d'après sa photo (aide à la rédaction du prompt) : liste de
 * phrases courtes en français, ou [] si l'IA ne répond pas.
 */
function prompt_suggestions_from_photo(string $photoAbsPath, string $kind): array
{
    $what = match ($kind) {
        'angle' => "de précisions de prise de vue (angle, cadrage, détail à montrer, ce qu'il faut enlever du cadre)",
        'complete' => "de précisions pour compléter la partie coupée de l'objet (motifs, symétrie, matière)",
        'video_ai' => "d'animations pour une courte vidéo de cette photo (mouvement de caméra lent, petit mouvement dans la scène : tissu qui bouge, lumière qui change, vapeur, reflets)",
        default => "de mises en situation : décors, situations et ambiances de lumière variés",
    };
    $prompt = "Tu regardes la photo d'" . tenant('ai.item') . ' pour ' . tenant('ai.shop') . '. '
        . "Propose 8 idées courtes (3 à 8 mots chacune, en français) $what, adaptées à CET objet précis, variées entre elles. "
        . ($kind === 'angle' || $kind === 'complete' || $kind === 'video_ai' ? '' : "Si c'est un vêtement ou un accessoire, inclus des idées où il est porté, en mouvement ou non. ")
        . 'Réponds UNIQUEMENT avec un tableau JSON de 8 chaînes, sans texte autour, sans markdown.';
    $small = downscale_for_ai($photoAbsPath, 800) ?? $photoAbsPath;
    $text = gemini_describe_image($small, $prompt, 1);
    if ($small !== $photoAbsPath) @unlink($small);
    if (!$text) return [];
    $list = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $item) {
        $item = rtrim(trim((string) $item), ' .');
        if ($item !== '' && mb_strlen($item) <= 90 && !in_array($item, $out, true)) $out[] = $item;
    }
    return array_slice($out, 0, 8);
}
