<?php
// Baisse de prix automatique par ancienneté.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const BP_MODULE = 'baisse-auto';
const BP_TICK_EVERY = 3600;
const BP_MAX_TOTAL = 60;     // remise cumulée maximale acceptée par les réglages

function bp_defaults(): array
{
    return ['mode' => 'propose', 'steps' => '30:10, 60:20, 90:30', 'max_percent' => '40', 'from_activation' => '1'];
}

function bp_settings(): array
{
    $out = bp_defaults();
    foreach (db()->query('SELECT key, value FROM bp_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    return $out;
}

function bp_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO bp_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, $v]);
}

/** « 30:10, 60:20 » → [[30, 10.0], [60, 20.0]] (jours, remise cumulée en %), croissant ; null si invalide. */
function bp_parse_steps(string $s): ?array
{
    $steps = [];
    foreach (array_filter(array_map('trim', explode(',', $s))) as $part) {
        if (!preg_match('/^(\d{1,3})\s*:\s*(\d{1,2}(?:[.,]\d)?)$/', $part, $m)) return null;
        $steps[] = [(int) $m[1], (float) str_replace(',', '.', $m[2])];
    }
    if (!$steps) return null;
    usort($steps, static fn ($a, $b) => $a[0] <=> $b[0]);
    $prevPct = 0;
    foreach ($steps as [$d, $p]) {
        if ($d < 1 || $p <= $prevPct || $p > BP_MAX_TOTAL) return null;   // remises cumulées strictement croissantes
        $prevPct = $p;
    }
    return $steps;
}

function bp_settings_save(array $in): ?string
{
    if (isset($in['steps'])) {
        if (bp_parse_steps((string) $in['steps']) === null) return 'Paliers invalides : écrivez « jours:remise » séparés par des virgules, remises cumulées croissantes (ex. 30:10, 60:20, 90:30, ' . BP_MAX_TOTAL . ' % au plus).';
        bp_setting_set('steps', trim((string) $in['steps']));
    }
    if (isset($in['max_percent'])) {
        $m = (float) str_replace(',', '.', (string) $in['max_percent']);
        if ($m < 5 || $m > BP_MAX_TOTAL) return 'Le plafond de remise doit être compris entre 5 et ' . BP_MAX_TOTAL . ' %.';
        bp_setting_set('max_percent', (string) $m);
    }
    if (isset($in['mode'])) bp_setting_set('mode', (string) $in['mode'] === 'auto' ? 'auto' : 'propose');
    if (isset($in['from_activation'])) bp_setting_set('from_activation', (string) $in['from_activation'] === '1' ? '1' : '0');
    return null;
}

/** Prix en texte, au format du catalogue (« 27 € », « 27,5 € »). */
function bp_format(int $cents): string
{
    return price_research_format($cents / 100);
}

/** Pièce concernée : visible, en stock, prix fixe (ni fourchette ni « à partir de »), pas de promo en cours. */
function bp_eligible(array $p): bool
{
    if ((int) $p['is_hidden'] === 1 || (int) $p['stock'] <= 0) return false;
    $price = (string) $p['price'];
    if (!is_fixed_price($price) || preg_match('/\d\s*[-–—]\s*\d/u', $price) || (price_to_cents($price) ?? 0) < 300) return false;
    if (($p['badge'] ?? '') === 'Promo' && !empty($p['promo_price'])) return false;
    return true;
}

/** Prix arrondi aux 50 centimes, pour des prix lisibles. */
function bp_round(float $cents): int
{
    return (int) (round($cents / 50) * 50);
}

/** Début du comptage d'ancienneté d'une pièce. */
function bp_start(array $p, array $s): int
{
    $t = 0;
    foreach (['created_at', 'restocked_at'] as $k) {
        if (!empty($p[$k])) $t = max($t, (int) strtotime((string) $p[$k] . ' UTC'));   // dates de la base en UTC (une date vide ne doit pas compter pour « maintenant »)
    }
    if ($s['from_activation'] === '1') $t = max($t, (int) bp_setting('since', '0'));
    return $t ?: time();
}

function bp_setting(string $k, string $d = ''): string
{
    $st = db()->prepare('SELECT value FROM bp_settings WHERE key = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : (string) $v;
}

/**
 * Baisses dues maintenant : [[ref, name, current_cents, new_cents, percent, step, age_days]].
 * Une pièce dont le prix a été changé à la main depuis la dernière baisse repart de zéro avec ce nouveau prix.
 */
function bp_due(?int $now = null): array
{
    $now ??= time();
    $s = bp_settings();
    $steps = bp_parse_steps($s['steps']) ?? [];
    $max = (float) $s['max_percent'];
    if (bp_setting('since') === '') bp_setting_set('since', (string) $now);
    $due = [];
    $state = db()->prepare('SELECT * FROM bp_state WHERE product_ref = ?');
    foreach (db()->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (!bp_eligible($p)) continue;
        $cur = (int) price_to_cents((string) $p['price']);
        $state->execute([$p['ref']]);
        $st = $state->fetch(PDO::FETCH_ASSOC);
        if (!$st || (int) $st['last_cents'] !== $cur) {      // première vue, ou prix changé à la main : nouveau cycle
            db()->prepare('INSERT INTO bp_state (product_ref, base_cents, step, last_cents, started_at) VALUES (?, ?, 0, ?, ?)
                ON CONFLICT(product_ref) DO UPDATE SET base_cents = excluded.base_cents, step = 0, last_cents = excluded.last_cents, started_at = excluded.started_at')
                ->execute([$p['ref'], $cur, $cur, $st ? $now : bp_start($p, $s)]);
            $st = ['base_cents' => $cur, 'step' => 0, 'last_cents' => $cur, 'started_at' => $st ? $now : bp_start($p, $s)];
        }
        $age = intdiv($now - (int) $st['started_at'], 86400);
        $reached = 0;
        $pct = 0.0;
        foreach ($steps as $i => [$days, $percent]) {
            if ($age >= $days) { $reached = $i + 1; $pct = min($percent, $max); }
        }
        if ($reached <= (int) $st['step'] || $pct <= 0) continue;
        $new = bp_round((int) $st['base_cents'] * (1 - $pct / 100));
        $floor = !empty($p['price_floor']) ? price_to_cents((string) $p['price_floor']) : null;
        if ($floor !== null && $new < $floor) $new = $floor;
        if ($new >= $cur) {                                   // plancher atteint ou arrondi sans effet : le palier est consommé sans changement
            db()->prepare('UPDATE bp_state SET step = ? WHERE product_ref = ?')->execute([$reached, $p['ref']]);
            continue;
        }
        $due[] = ['ref' => $p['ref'], 'name' => $p['name'], 'current_cents' => $cur, 'new_cents' => $new, 'percent' => $pct, 'step' => $reached, 'age_days' => $age, 'floor_cents' => $floor];
    }
    return $due;
}

/** Applique une baisse. -> message d'erreur ou null. */
function bp_apply(array $d, string $mode): ?string
{
    $st = db()->prepare('SELECT price, stock FROM products WHERE ref = ?');
    $st->execute([$d['ref']]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || (int) $p['stock'] <= 0) return 'Pièce introuvable ou vendue.';
    if ((int) price_to_cents((string) $p['price']) !== (int) $d['current_cents']) return 'Le prix a changé entre-temps : rechargez la liste.';
    $newText = bp_format((int) $d['new_cents']);
    db()->exec('BEGIN IMMEDIATE');
    try {
        db()->prepare('UPDATE products SET price = ? WHERE ref = ?')->execute([$newText, $d['ref']]);
        db()->prepare('UPDATE bp_state SET step = ?, last_cents = ? WHERE product_ref = ?')->execute([(int) $d['step'], (int) $d['new_cents'], $d['ref']]);
        db()->prepare('INSERT INTO bp_log (product_ref, old_price, new_price, percent, step, mode) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$d['ref'], (string) $p['price'], $newText, (float) $d['percent'], (int) $d['step'], $mode]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    return null;
}

/** Annule une baisse (la dernière de la pièce) : l'ancien prix revient, le palier est rejoué plus tard. */
function bp_undo(int $logId): ?string
{
    $st = db()->prepare('SELECT * FROM bp_log WHERE id = ? AND undone = 0');
    $st->execute([$logId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return 'Baisse introuvable ou déjà annulée.';
    $last = db()->prepare('SELECT MAX(id) FROM bp_log WHERE product_ref = ? AND undone = 0');
    $last->execute([$l['product_ref']]);
    if ((int) $last->fetchColumn() !== (int) $l['id']) return "Seule la dernière baisse d'une pièce peut être annulée.";
    $p = db()->prepare('SELECT price FROM products WHERE ref = ?');
    $p->execute([$l['product_ref']]);
    if ((string) $p->fetchColumn() !== (string) $l['new_price']) return 'Le prix a été modifié depuis : rien à annuler.';
    db()->exec('BEGIN IMMEDIATE');
    try {
        db()->prepare('UPDATE products SET price = ? WHERE ref = ?')->execute([$l['old_price'], $l['product_ref']]);
        db()->prepare('UPDATE bp_log SET undone = 1 WHERE id = ?')->execute([$logId]);
        // On repart de ce prix avec un nouveau cycle : pas de nouvelle baisse avant le prochain palier complet.
        $cents = (int) price_to_cents((string) $l['old_price']);
        db()->prepare('UPDATE bp_state SET base_cents = ?, step = 0, last_cents = ?, started_at = ? WHERE product_ref = ?')->execute([$cents, $cents, time(), $l['product_ref']]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    return null;
}

/** En mode automatique, applique les baisses dues (au plus une fois par heure, déclenché par les visites). */
function bp_tick(bool $force = false): int
{
    if (!addon_is_entitled(BP_MODULE)) return 0;
    if (!$force && time() - (int) bp_setting('last_tick', '0') < BP_TICK_EVERY) return 0;
    bp_setting_set('last_tick', (string) time());
    if (bp_settings()['mode'] !== 'auto') { bp_due(); return 0; }
    $n = 0;
    foreach (bp_due() as $d) if (bp_apply($d, 'auto') === null) $n++;
    return $n;
}
