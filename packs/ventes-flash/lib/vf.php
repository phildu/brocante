<?php
// Ventes flash : baisses de prix programmées, appliquées par « tick » (visites du site) et rétablies à la fin.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const VF_MODULE = 'ventes-flash';
const VF_TICK_EVERY = 20;   // secondes minimum entre deux vérifications déclenchées par les visites

/** Pièce concernée : prix fixe (pas de fourchette ni « à partir de »), visible, en stock. */
function vf_eligible(array $p): bool
{
    if ((int) $p['is_hidden'] === 1 || (int) $p['stock'] <= 0) return false;
    $price = (string) $p['price'];
    return is_fixed_price($price) && !preg_match('/\d\s*[-–—]\s*\d/u', $price) && (price_to_cents($price) ?? 0) > 0;
}

function vf_scope(array $sale): array
{
    $refs = array_values(array_filter(array_map('trim', explode(',', (string) $sale['refs']))));
    $all = db()->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($all, static fn ($p) => ($refs === [] || in_array($p['ref'], $refs, true)) && vf_eligible($p)));
}

/** Remise (%) due à l'instant $now pour cette vente. */
function vf_percent(array $sale, int $now): float
{
    if ($now < (int) $sale['starts_at']) return 0.0;
    $steps = intdiv($now - (int) $sale['starts_at'], max(1, (int) $sale['step_minutes']) * 60);
    return min((float) $sale['max_percent'], round($steps * (float) $sale['step_percent'], 2));
}

function vf_promo_for(string $price, float $percent): string
{
    $cents = (int) round((price_to_cents($price) ?? 0) * (1 - $percent / 100) / 5) * 5;   // arrondi aux 5 centimes
    return price_research_format(max(0.05, $cents / 100));
}

function vf_restore(array $sale): void
{
    $items = db()->prepare('SELECT * FROM vf_items WHERE sale_id = ?');
    $items->execute([$sale['id']]);
    $upd = db()->prepare('UPDATE products SET promo_price = ?, badge = ? WHERE ref = ?');
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $upd->execute([$i['orig_promo'] !== '' ? $i['orig_promo'] : null, $i['orig_badge'], $i['product_ref']]);
    }
    db()->prepare('DELETE FROM vf_items WHERE sale_id = ?')->execute([$sale['id']]);
}

/** Fait avancer les ventes flash (démarrage, baisses, fin). -> nombre de prix modifiés. */
function vf_tick(bool $force = false): int
{
    if (!addon_is_entitled(VF_MODULE)) {
        // Essai expiré ou abonnement résilié : plus rien ne s'applique, et les prix d'origine sont rétablis.
        $changed = 0;
        foreach (db()->query("SELECT * FROM vf_sales WHERE status = 'running'")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            vf_restore($s);
            db()->prepare("UPDATE vf_sales SET status = 'ended' WHERE id = ?")->execute([$s['id']]);
            $changed++;
        }
        return $changed;
    }
    static $last = 0;
    if (!$force && time() - $last < VF_TICK_EVERY) return 0;
    $last = time();
    $now = time();
    $changed = 0;
    foreach (db()->query("SELECT * FROM vf_sales WHERE status IN ('scheduled', 'running') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $sale) {
        if ($now < (int) $sale['starts_at']) continue;
        db()->exec('BEGIN IMMEDIATE');
        try {
            if ($now >= (int) $sale['ends_at']) {
                vf_restore($sale);
                db()->prepare("UPDATE vf_sales SET status = 'ended' WHERE id = ?")->execute([$sale['id']]);
                $changed++;
            } else {
                if ($sale['status'] === 'scheduled') {
                    $ins = db()->prepare('INSERT OR IGNORE INTO vf_items (sale_id, product_ref, orig_badge, orig_promo) VALUES (?, ?, ?, ?)');
                    foreach (vf_scope($sale) as $p) $ins->execute([$sale['id'], $p['ref'], (string) $p['badge'], (string) ($p['promo_price'] ?? '')]);
                    db()->prepare("UPDATE vf_sales SET status = 'running' WHERE id = ?")->execute([$sale['id']]);
                }
                $pct = vf_percent($sale, $now);
                if ($pct > 0) {
                    $rows = db()->prepare('SELECT i.product_ref, i.applied_percent, i.orig_promo, i.orig_badge, p.price FROM vf_items i JOIN products p ON p.ref = i.product_ref WHERE i.sale_id = ?');
                    $rows->execute([$sale['id']]);
                    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if ((float) $r['applied_percent'] >= $pct) continue;
                        $promo = vf_promo_for((string) $r['price'], $pct);
                        // Une promo déjà plus basse que le prix flash n'est jamais relevée.
                        if ($r['orig_badge'] === 'Promo' && $r['orig_promo'] !== '' && (price_to_cents($r['orig_promo']) ?? PHP_INT_MAX) <= (price_to_cents($promo) ?? 0)) {
                            db()->prepare('UPDATE vf_items SET applied_percent = ? WHERE sale_id = ? AND product_ref = ?')->execute([$pct, $sale['id'], $r['product_ref']]);
                            continue;
                        }
                        db()->prepare("UPDATE products SET promo_price = ?, badge = 'Promo' WHERE ref = ?")->execute([$promo, $r['product_ref']]);
                        db()->prepare('UPDATE vf_items SET applied_percent = ? WHERE sale_id = ? AND product_ref = ?')->execute([$pct, $sale['id'], $r['product_ref']]);
                        $changed++;
                    }
                }
            }
            db()->exec('COMMIT');
        } catch (Throwable $e) {
            db()->exec('ROLLBACK');
            error_log('Ventes flash : ' . $e->getMessage());
        }
    }
    return $changed;
}

/** Annule une vente : prix d'origine rétablis. */
function vf_cancel(int $id): bool
{
    $st = db()->prepare("SELECT * FROM vf_sales WHERE id = ? AND status IN ('scheduled', 'running')");
    $st->execute([$id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) return false;
    vf_restore($sale);
    db()->prepare("UPDATE vf_sales SET status = 'canceled' WHERE id = ?")->execute([$id]);
    return true;
}
