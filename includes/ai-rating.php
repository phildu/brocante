<?php

// Notation des résultats de l'IA par les testeurs : une note de 1 à 5 par appel consigné
// (un testeur peut noter plusieurs fois le même résultat, seul son dernier avis compte).
//
// « Taux d'efficacité » = notes « Bon » ou « Excellent » (4 ou 5) ÷ résultats notés.
// Les notes sont saisies juste après une génération (widget « Résultat IA », présent dans
// l'administration) ou depuis « Consommation IA », qui les agrège par fournisseur et par type.
//
// La table vit dans la base de chaque boutique (à côté d'ai_usage) : le portail la lit à distance
// comme il lit déjà les coûts (portail/ia.php). Aucune note ne quitte le serveur de la boutique.

const AI_RATING_LABELS = [1 => 'Inutilisable', 2 => 'Faible', 3 => 'Passable', 4 => 'Bon', 5 => 'Excellent'];
const AI_RATING_EFFECTIVE_MIN = 4;
const AI_RATING_MAX_COMMENT = 500;
const AI_RATING_WIDGET_HOURS = 12;

function ai_rating_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_ratings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      usage_id INTEGER NOT NULL,
      stars INTEGER NOT NULL,
      comment TEXT NOT NULL DEFAULT \'\',
      tester_key TEXT NOT NULL,
      tester_name TEXT NOT NULL DEFAULT \'\',
      kind TEXT NOT NULL DEFAULT \'\',
      provider TEXT NOT NULL DEFAULT \'\',
      model TEXT NOT NULL DEFAULT \'\',
      ref TEXT,
      script TEXT NOT NULL DEFAULT \'\',
      UNIQUE (usage_id, tester_key)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_ratings_at ON ai_ratings(at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_ratings_usage ON ai_ratings(usage_id)');
}

/** Identité du testeur connecté : ['key', 'name', 'id'] (clé stable, indépendante du nom affiché). */
function ai_rating_tester(?array $session = null): array
{
    if ($session === null && function_exists('admin_session')) $session = admin_session();
    if (!$session) return ['key' => 'cli', 'name' => 'Test', 'id' => null];
    $id = isset($session['id']) && $session['id'] !== null ? (int) $session['id'] : null;
    $name = trim((string) ($session['name'] ?? ''));
    if ($name === '') $name = $id ? 'Compte ' . $id : 'Compte principal';
    return ['key' => $id ? 'acct:' . $id : 'main', 'name' => $name, 'id' => $id];
}

/** Enregistre (ou remplace) la note d'un testeur pour un appel IA. Null si l'appel ou la note est invalide. */
function ai_rating_save(PDO $pdo, int $usageId, int $stars, string $comment = '', ?array $tester = null): ?array
{
    if ($usageId <= 0 || $stars < 1 || $stars > 5) return null;
    $tester ??= ai_rating_tester();
    $stmt = $pdo->prepare('SELECT id, kind, provider, model, script, ref FROM ai_usage WHERE id = ?');
    $stmt->execute([$usageId]);
    $usage = $stmt->fetch();
    if (!$usage) return null;
    $comment = mb_substr(trim($comment), 0, AI_RATING_MAX_COMMENT);
    $provider = trim((string) ($usage['provider'] ?? '')) !== ''
        ? (string) $usage['provider']
        : ai_usage_provider((string) $usage['model'], (string) $usage['kind']);
    $pdo->prepare('INSERT INTO ai_ratings (usage_id, stars, comment, tester_key, tester_name, kind, provider, model, ref, script)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (usage_id, tester_key) DO UPDATE SET
              at = CURRENT_TIMESTAMP, stars = excluded.stars, comment = excluded.comment, tester_name = excluded.tester_name,
              kind = excluded.kind, provider = excluded.provider, model = excluded.model, ref = excluded.ref, script = excluded.script')
        ->execute([$usageId, $stars, $comment, (string) $tester['key'], (string) $tester['name'],
            (string) $usage['kind'], $provider, (string) $usage['model'], $usage['ref'], (string) $usage['script']]);
    $stmt = $pdo->prepare('SELECT * FROM ai_ratings WHERE usage_id = ? AND tester_key = ?');
    $stmt->execute([$usageId, (string) $tester['key']]);
    return $stmt->fetch() ?: null;
}

/**
 * Résumé des notes de plusieurs appels : id => ['n' => notes, 'avg' => moyenne, 'effective' => notes 4–5].
 * $usageIds est limité aux identifiants entiers pour rester paramétré.
 */
function ai_rating_usage_map(PDO $pdo, array $usageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $usageIds), static fn (int $id): bool => $id > 0)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT usage_id, COUNT(*) AS n, AVG(stars) AS avg, SUM(CASE WHEN stars >= ? THEN 1 ELSE 0 END) AS effective
        FROM ai_ratings WHERE usage_id IN ($marks) GROUP BY usage_id");
    $stmt->execute([AI_RATING_EFFECTIVE_MIN, ...$ids]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(int) $row['usage_id']] = ['n' => (int) $row['n'], 'avg' => (float) $row['avg'], 'effective' => (int) $row['effective']];
    return $out;
}

/** Notes déjà données par un testeur sur ces appels : id => étoiles. */
function ai_rating_mine_map(PDO $pdo, array $usageIds, string $testerKey): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $usageIds), static fn (int $id): bool => $id > 0)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT usage_id, stars FROM ai_ratings WHERE tester_key = ? AND usage_id IN ($marks)");
    $stmt->execute([$testerKey, ...$ids]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(int) $row['usage_id']] = (int) $row['stars'];
    return $out;
}

/** Dernier appel IA non encore noté par ce testeur (dans les $hours dernières heures), ou null. */
function ai_rating_pending_usage(PDO $pdo, string $testerKey, int $hours = AI_RATING_WIDGET_HOURS): ?array
{
    $since = gmdate('Y-m-d H:i:s', time() - max(1, $hours) * 3600);
    $stmt = $pdo->prepare('SELECT u.* FROM ai_usage u LEFT JOIN ai_ratings r ON r.usage_id = u.id AND r.tester_key = ?
        WHERE u.at >= ? AND r.id IS NULL ORDER BY u.id DESC LIMIT 1');
    $stmt->execute([$testerKey, $since]);
    return $stmt->fetch() ?: null;
}

/**
 * Statistiques pour le tableau de bord. Sans $since : tout l'historique ; sinon les notes et appels depuis cet horodatage UTC.
 * 'rate' (taux d'efficacité) vaut null tant qu'aucune note n'existe.
 */
function ai_rating_stats(PDO $pdo, ?string $since = null): array
{
    $where = $since ? ' WHERE at >= ?' : '';
    $params = $since ? [$since] : [];
    $stmt = $pdo->prepare("SELECT COUNT(*) AS n, AVG(stars) AS avg, SUM(CASE WHEN stars >= ? THEN 1 ELSE 0 END) AS effective,
            COUNT(DISTINCT usage_id) AS rated FROM ai_ratings" . $where);
    $stmt->execute([AI_RATING_EFFECTIVE_MIN, ...$params]);
    $row = $stmt->fetch() ?: [];
    $n = (int) ($row['n'] ?? 0);
    $effective = (int) ($row['effective'] ?? 0);

    $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $stmt = $pdo->prepare('SELECT stars, COUNT(*) AS n FROM ai_ratings' . $where . ' GROUP BY stars');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) $distribution[(int) $r['stars']] = (int) $r['n'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ai_usage' . $where);
    $stmt->execute($params);
    $calls = (int) $stmt->fetchColumn();

    $group = static function (PDO $pdo, string $column, string $where, array $params): array {
        $stmt = $pdo->prepare("SELECT $column AS label, COUNT(*) AS n, AVG(stars) AS avg, SUM(CASE WHEN stars >= ? THEN 1 ELSE 0 END) AS effective
            FROM ai_ratings" . $where . " GROUP BY $column ORDER BY n DESC");
        $stmt->execute([AI_RATING_EFFECTIVE_MIN, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $count = (int) $r['n'];
            $out[(string) $r['label']] = ['n' => $count, 'avg' => (float) $r['avg'], 'effective' => (int) $r['effective'],
                'rate' => $count > 0 ? (int) $r['effective'] / $count : null];
        }
        return $out;
    };

    $stmt = $pdo->prepare("SELECT substr(at, 1, 7) AS m, COUNT(*) AS n, AVG(stars) AS avg, SUM(CASE WHEN stars >= ? THEN 1 ELSE 0 END) AS effective
        FROM ai_ratings" . ($where ? $where : '') . ' GROUP BY m ORDER BY m DESC LIMIT 6');
    $stmt->execute([AI_RATING_EFFECTIVE_MIN, ...$params]);
    $byMonth = [];
    foreach ($stmt->fetchAll() as $r) {
        $count = (int) $r['n'];
        $byMonth[(string) $r['m']] = ['n' => $count, 'avg' => (float) $r['avg'], 'effective' => (int) $r['effective'],
            'rate' => $count > 0 ? (int) $r['effective'] / $count : null];
    }

    return [
        'n' => $n,
        'avg' => $n > 0 ? (float) $row['avg'] : null,
        'effective' => $effective,
        'rate' => $n > 0 ? $effective / $n : null,
        'rated' => (int) ($row['rated'] ?? 0),
        'calls' => $calls,
        'coverage' => $calls > 0 ? (int) ($row['rated'] ?? 0) / $calls : null,
        'distribution' => $distribution,
        'by_provider' => $group($pdo, "COALESCE(NULLIF(provider, ''), 'Non précisé')", $where, $params),
        'by_kind' => $group($pdo, "COALESCE(NULLIF(kind, ''), 'Non précisé')", $where, $params),
        'by_month' => $byMonth,
    ];
}

/** Dernières notes reçues (toutes boutiques confondues côté portail, la boutique concernée côté admin). */
function ai_rating_recent(PDO $pdo, int $limit = 12, ?string $since = null): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare('SELECT * FROM ai_ratings' . ($since ? ' WHERE at >= ?' : '') . ' ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute($since ? [$since] : []);
    return $stmt->fetchAll();
}

/** Libellé court d'une note : « 4 / 5 — Bon ». */
function ai_rating_label(int $stars): string
{
    $stars = max(1, min(5, $stars));
    return $stars . ' / 5 — ' . (AI_RATING_LABELS[$stars] ?? '');
}

/** « 75 % » ou « — » quand aucune note n'existe. */
function ai_rating_rate_label(?float $rate): string
{
    return $rate === null ? '—' : (int) round($rate * 100) . ' %';
}

/**
 * Widget flottant proposé après une génération : note le dernier résultat IA non noté
 * par le testeur connecté. Retourne une chaîne vide si rien à noter (ou hors administration).
 */
function ai_rating_widget_html(): string
{
    if (PHP_SAPI === 'cli') return '';
    if (!function_exists('ai_shop_enabled') || !ai_shop_enabled()) return '';
    if (!function_exists('admin_session') || !admin_session()) return '';
    if (function_exists('admin_role_can_access') && !admin_role_can_access(admin_role(), '/admin/ai-usage.php')) return '';
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['ai-usage.php', 'login.php'], true)) return '';

    try {
        $tester = ai_rating_tester();
        $usage = ai_rating_pending_usage(db(), (string) $tester['key']);
    } catch (Throwable $e) {
        return '';
    }
    if (!$usage) return '';
    $usageId = (int) $usage['id'];
    if ((int) ($_SESSION['ai_rating_skip'] ?? 0) === $usageId) return '';

    $e = static fn ($s): string => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
    $kind = AI_KINDS[(string) $usage['kind']] ?? (string) $usage['kind'];
    $provider = trim((string) ($usage['provider'] ?? '')) !== ''
        ? (string) $usage['provider']
        : ai_usage_provider((string) $usage['model'], (string) $usage['kind']);
    $when = date('H:i', strtotime((string) $usage['at'] . ' UTC'));
    $back = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/ai-usage.php');
    if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || preg_match('/[\r\n]/', $back)) $back = '/admin/ai-usage.php';

    ob_start();
    ?>
<style>
  .airate { position: fixed; left: 18px; bottom: 18px; z-index: 65; width: min(330px, calc(100vw - 88px)); background: var(--surface, #fff); color: var(--ink, #1c1c1c);
    border: 1px solid var(--line, #d8d5ce); border-left: 4px solid var(--accent, #2f6f5e); box-shadow: 0 10px 30px rgba(0,0,0,.14); padding: 12px 14px; font-size: .86rem; }
  .airate-title { margin: 0 0 8px; font-weight: 700; }
  .airate-title span { display: block; font-weight: 400; font-size: .76rem; color: var(--ink-soft, #6b675f); margin-top: 2px; }
  .airate-stars { border: 0; padding: 0; margin: 0 0 8px; display: flex; gap: 2px; }
  .airate-stars legend { padding: 0; margin-bottom: 4px; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-soft, #6b675f); }
  .airate-stars label { cursor: pointer; line-height: 1; }
  .airate-stars input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  .airate-stars span[aria-hidden] { font-size: 1.7rem; color: var(--line, #c9c5bc); padding: 0 1px; }
  .airate-stars input:checked + span[aria-hidden] { color: #c98a00; }
  .airate-stars input:focus-visible + span[aria-hidden] { outline: 2px solid var(--accent, #2f6f5e); outline-offset: 2px; }
  .airate-comment { display: grid; gap: 3px; font-size: .76rem; color: var(--ink-soft, #6b675f); }
  .airate-comment input { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid var(--line, #d8d5ce); background: var(--bg, #fff); color: inherit; font: inherit; }
  .airate-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 9px; }
  .airate-actions a { font-size: .76rem; color: inherit; }
  .airate-ok { padding: 5px 12px; border: 1px solid var(--accent, #2f6f5e); background: var(--accent, #2f6f5e); color: #fff; font: inherit; cursor: pointer; }
  .airate-later { padding: 5px 8px; border: 0; background: none; color: var(--ink-soft, #6b675f); font: inherit; cursor: pointer; text-decoration: underline; }
  .airate-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  @media (max-width: 640px) { .airate { left: 12px; bottom: 12px; width: min(300px, calc(100vw - 84px)); } }
</style>
<aside class="airate" aria-label="Noter le dernier résultat de l'IA">
  <form method="post" action="/admin/ai-rating.php">
    <input type="hidden" name="csrf" value="<?= $e(function_exists('admin_csrf_token') ? admin_csrf_token() : '') ?>">
    <input type="hidden" name="usage_id" value="<?= $usageId ?>">
    <input type="hidden" name="back" value="<?= $e($back) ?>">
    <p class="airate-title">Résultat IA à noter<span><?= $e($kind) ?> · <?= $e($provider) ?> · <?= $e($when) ?></span></p>
    <fieldset class="airate-stars">
      <legend>Votre note</legend>
      <?php foreach ([1 => 'Inutilisable', 2 => 'Faible', 3 => 'Passable', 4 => 'Bon', 5 => 'Excellent'] as $stars => $label): ?>
        <label title="<?= $e($stars . ' sur 5 — ' . $label) ?>"><input type="radio" name="stars" value="<?= $stars ?>" required><span aria-hidden="true">★</span><span class="airate-sr"><?= $e($stars . ' sur 5 — ' . $label) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="airate-comment">Commentaire (facultatif)<input type="text" name="comment" maxlength="<?= AI_RATING_MAX_COMMENT ?>" placeholder="Ex. fond flou, pièce déformée…"></label>
    <div class="airate-actions">
      <button type="submit" class="airate-ok">Envoyer la note</button>
      <button type="submit" name="later" value="1" formnovalidate class="airate-later">Plus tard</button>
      <a href="/admin/ai-usage.php">Tableau de bord</a>
    </div>
  </form>
</aside>
    <?php
    return (string) ob_get_clean();
}
