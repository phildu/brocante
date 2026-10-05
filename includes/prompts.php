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
