<?php

// Consommation de l'IA : chaque appel payant (génération d'image, texte et vision, recherche web, détourage) est
// consigné avec son coût ESTIMÉ ; la page « Consommation IA » en fait le total et le solde d'un crédit prépayé.
// Les tarifs sont ceux des offres publiques à la date de cette version : modifiables dans la page, à vérifier chez
// Google (ai.google.dev/pricing) et fal.ai. Le solde réel d'un compte n'est lisible par aucune API : ce qui est affiché
// est une estimation, pas une facture.

/** Tarifs par défaut : image = $ par image générée ; text_in / text_out = $ par million de tokens ; search = $ par requête avec recherche web ; cutout = $ par détourage fal.ai ; veo_* = $ par seconde de vidéo Veo 3.1 (Lite, Fast, Standard ; 720p). */
const AI_PRICING_DEFAULTS = ['usd_eur' => 0.92, 'image' => 0.039, 'text_in' => 0.30, 'text_out' => 2.50, 'search' => 0.035, 'cutout' => 0.001, 'veo_lite' => 0.05, 'veo_fast' => 0.10, 'veo_std' => 0.40];
const AI_KINDS = ['image' => 'Images générées', 'text' => 'Texte et vision', 'search' => 'Recherches web', 'cutout' => 'Détourages (fal.ai)', 'video' => 'Vidéos IA (Veo)'];

function ai_usage_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ai_usage'")->fetchColumn();
    if ($exists) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      kind TEXT NOT NULL,
      model TEXT NOT NULL DEFAULT '',
      tokens_in INTEGER NOT NULL DEFAULT 0,
      tokens_out INTEGER NOT NULL DEFAULT 0,
      cost_usd REAL NOT NULL DEFAULT 0,
      script TEXT NOT NULL DEFAULT '',
      ref TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_usage_at ON ai_usage(at)');
}

/** Tarifs en vigueur : ceux enregistrés pour le commerce, complétés par les valeurs par défaut. */
function ai_pricing(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = [];
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'ai_pricing'");
        $stmt->execute();
        $saved = json_decode((string) $stmt->fetchColumn(), true) ?: [];
    } catch (Throwable $e) {
    }
    $cache = array_merge(AI_PRICING_DEFAULTS, array_intersect_key(array_map('floatval', $saved), AI_PRICING_DEFAULTS));
    return $cache;
}

function ai_pricing_save(array $values): void
{
    $clean = [];
    foreach (AI_PRICING_DEFAULTS as $key => $default) {
        $v = (float) str_replace(',', '.', (string) ($values[$key] ?? $default));
        $clean[$key] = $v >= 0 && $v < 1000 ? $v : $default;
    }
    db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('ai_pricing', ?)")->execute([json_encode($clean)]);
}

/** Pièce à laquelle rattacher les appels qui suivent (pour le classement « par pièce »). */
function ai_usage_context(?string $ref = null): ?string
{
    static $current = null;
    if (func_num_args() > 0) $current = $ref;
    return $current;
}

/** Consigne un appel. Ne casse jamais l'appel de l'IA : toute erreur d'écriture est ignorée. */
function ai_usage_record(string $kind, float $costUsd, string $model = '', int $tokensIn = 0, int $tokensOut = 0): void
{
    try {
        db()->prepare('INSERT INTO ai_usage (kind, model, tokens_in, tokens_out, cost_usd, script, ref) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$kind, $model, $tokensIn, $tokensOut, round($costUsd, 6), basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'cli')), ai_usage_context()]);
    } catch (Throwable $e) {
    }
}

/** Coût en dollars d'un appel de texte d'après usageMetadata : entrée, puis sortie y compris la réflexion du modèle. */
function ai_usage_text_cost(array $usage): array
{
    $in = (int) ($usage['promptTokenCount'] ?? 0);
    $out = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
    $p = ai_pricing();
    return [$in * $p['text_in'] / 1e6 + $out * $p['text_out'] / 1e6, $in, $out];
}

function ai_usage_log_text(array $usage, string $model = 'gemini-2.5-flash'): void
{
    [$cost, $in, $out] = ai_usage_text_cost($usage);
    ai_usage_record('text', $cost, $model, $in, $out);
}

function ai_usage_log_image(array $usage = []): void
{
    ai_usage_record('image', ai_pricing()['image'], 'gemini-2.5-flash-image', (int) ($usage['promptTokenCount'] ?? 0), (int) ($usage['candidatesTokenCount'] ?? 0));
}

/** Recherche web : le tarif d'une requête avec recherche, plus les tokens. */
function ai_usage_log_search(array $usage, bool $grounded): void
{
    [$cost, $in, $out] = ai_usage_text_cost($usage);
    ai_usage_record('search', $cost + ($grounded ? ai_pricing()['search'] : 0), 'gemini-2.5-flash + Google Search', $in, $out);
}

/** Vidéo Veo terminée (Google ne facture que les vidéos réussies). */
function ai_usage_log_video(string $modelKey, int $seconds): void
{
    $model = VEO_MODELS[$modelKey] ?? VEO_MODELS['fast'];
    ai_usage_record('video', veo_cost_usd($modelKey, $seconds), $model['id'] . " ({$seconds} s)");
}

function ai_usage_log_cutout(): void
{
    ai_usage_record('cutout', ai_pricing()['cutout'], 'fal-ai/imageutils/rembg');
}

// ── Estimations affichées avant de générer ──

/** Coût moyen d'un appel de texte : la moyenne des derniers appels consignés, sinon une valeur par défaut. */
function ai_text_call_usd(): float
{
    try {
        $rows = db()->query("SELECT cost_usd FROM ai_usage WHERE kind = 'text' ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) >= 5) return array_sum($rows) / count($rows);
    } catch (Throwable $e) {
    }
    return 0.003;
}

/** Coût estimé en dollars de $counts = ['image' => n, 'text' => n, 'search' => n, 'cutout' => n, 'usd' => montant déjà calculé]. */
function ai_estimate_usd(array $counts): float
{
    $p = ai_pricing();
    return ($counts['image'] ?? 0) * $p['image'] + ($counts['text'] ?? 0) * ai_text_call_usd()
        + ($counts['search'] ?? 0) * ($p['search'] + ai_text_call_usd() * 2) + ($counts['cutout'] ?? 0) * $p['cutout'] + ($counts['usd'] ?? 0);
}

function ai_eur(float $usd): float
{
    return $usd * ai_pricing()['usd_eur'];
}

/** « 3,42 € », « < 0,01 € » : un montant en euros pour l'affichage. */
function ai_format_eur(float $eur): string
{
    if ($eur > 0 && $eur < 0.005) return '< 0,01 €';
    return number_format($eur, 2, ',', ' ') . ' €';
}

/** « ≈ 0,08 € » pour $counts. */
function ai_estimate_label(array $counts): string
{
    $label = ai_format_eur(ai_eur(ai_estimate_usd($counts)));
    return str_starts_with($label, '<') ? $label : '≈ ' . $label;
}

/** Détourage d'une pièce : fal.ai s'il est configuré, sinon le repli par image générée. */
function ai_cutout_counts(): array
{
    return FAL_API_KEY ? ['cutout' => 1] : ['image' => 1];
}

/** Traitement complet d'une pièce (Studio, lot) : détourage, mise en situation 3:2 et 9:16, fiche. */
function ai_estimate_piece_counts(): array
{
    $c = ai_cutout_counts();
    $c['image'] = ($c['image'] ?? 0) + 2;
    $c['text'] = 1;
    return $c;
}

// ── Totaux ──

/** Dépense estimée en euros depuis $since (horodatage UTC « Y-m-d H:i:s »), ou toute la période. */
function ai_usage_spent(?string $since = null): float
{
    try {
        $stmt = db()->prepare('SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage' . ($since ? ' WHERE at >= ?' : ''));
        $stmt->execute($since ? [$since] : []);
        return ai_eur((float) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Crédit prépayé déclaré : ['amount' => euros, 'since' => horodatage UTC] ou null. */
function ai_budget(): ?array
{
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'ai_budget'");
        $stmt->execute();
        $b = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($b) && isset($b['amount'], $b['since']) ? ['amount' => (float) $b['amount'], 'since' => (string) $b['since']] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Déclare un crédit : le solde repart de ce montant, les dépenses comptées à partir de maintenant. */
function ai_budget_save(float $amountEur): void
{
    db()->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES ('ai_budget', ?)")
        ->execute([json_encode(['amount' => max(0, round($amountEur, 2)), 'since' => gmdate('Y-m-d H:i:s')])]);
}

/** Solde estimé : crédit moins dépenses depuis sa déclaration (peut devenir négatif). */
function ai_balance(): ?array
{
    $b = ai_budget();
    if (!$b) return null;
    $spent = ai_usage_spent($b['since']);
    return ['amount' => $b['amount'], 'since' => $b['since'], 'spent' => $spent, 'remaining' => $b['amount'] - $spent];
}
