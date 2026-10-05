<?php

// Prompts (« Mots-clés / précisions ») enregistrés pour être réutilisés : consignes données à l'IA
// pour une génération (mise en situation, autre angle, compléter l'objet) ou pour la fiche d'une pièce
// (« Indications pour l'IA »). Propres à chaque commerce ; partagés par toute son équipe.

/** Types de prompts : un par génération de la galerie, plus les indications de fiche. */
const SAVED_PROMPT_KINDS = ['ambiance', 'angle', 'complete', 'notes'];
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
 * Prompt réellement envoyé à l'IA pour une génération, en blocs [label, text] : celui que construit le serveur
 * au moment de générer (mêmes fonctions), plus le cadrage ajouté automatiquement. Sert à l'aperçu affiché sous
 * le champ « Mots-clés / précisions ». $kind : ambiance, angle, complete ou notes (indications de fiche : la
 * mise en situation ET la rédaction de la fiche).
 */
function prompt_preview_parts(string $kind, string $text, string $anglePreset = 'auto'): ?array
{
    $text = trim($text);
    $directed = $text !== '';
    $framing = static fn (bool $d): array => ['label' => 'Cadrage ajouté automatiquement (format 3:2)', 'text' => trim(generated_framing_prompt(GENERATED_IMAGE_FORMATS['desktop'], $d))];
    switch ($kind) {
        case 'ambiance':
            return [['label' => "Prompt envoyé à l'IA", 'text' => build_ambiance_prompt($text)], $framing($directed)];
        case 'angle':
            return [['label' => "Prompt envoyé à l'IA", 'text' => build_angle_prompt($anglePreset, $text)], $framing(false)];
        case 'complete':
            return [['label' => "Prompt envoyé à l'IA", 'text' => build_complete_prompt($text)], $framing(false)];
        case 'notes':
            return [
                ['label' => 'Mise en situation', 'text' => build_ambiance_prompt($text)],
                $framing($directed),
                ['label' => 'Fiche (nom, description, catégorie, prix)', 'text' => build_product_sheet_prompt(1, $text)],
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
        default => "de mises en situation : décors, situations et ambiances de lumière variés",
    };
    $prompt = "Tu regardes la photo d'" . tenant('ai.item') . ' pour ' . tenant('ai.shop') . '. '
        . "Propose 8 idées courtes (3 à 8 mots chacune, en français) $what, adaptées à CET objet précis, variées entre elles. "
        . ($kind === 'angle' || $kind === 'complete' ? '' : "Si c'est un vêtement ou un accessoire, inclus des idées où il est porté, en mouvement ou non. ")
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
