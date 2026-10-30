<?php
// Actions commerciales : braderie, foire ou soldes. Une opération applique une remise aux pièces choisies,
// les prix d'origine sont rétablis à la fin (ou à l'arrêt), et les ventes de la période sont chiffrées.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const ACO_MODULE = 'actions-commerciales';
const ACO_TICK_EVERY = 20;          // secondes minimum entre deux vérifications déclenchées par les visites
const ACO_MAX_PERCENT = 90;
const ACO_MAX_AMOUNT_CENTS = 1000000;

function aco_kind_labels(): array
{
    return ['braderie' => 'Braderie', 'foire' => 'Foire / marché', 'soldes' => 'Soldes'];
}

function aco_kind_label(string $kind): string
{
    return aco_kind_labels()[$kind] ?? 'Opération';
}

/** Le module est-il actif (essai, abonnement ou accès offert) ? */
function aco_enabled(): bool
{
    try {
        return addon_is_entitled(ACO_MODULE);
    } catch (Throwable $e) {
        return false;
    }
}

/** Une opération par identifiant. */
function aco_op(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM aco_operations WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Toutes les opérations, la plus récente d'abord. */
function aco_ops(): array
{
    return db()->query('SELECT * FROM aco_operations ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/** Prix en texte au format du catalogue (« 27 € », « 24,50 € »). */
function aco_format(int $cents): string
{
    return price_research_format(max(0, $cents) / 100);
}

/** Remise en texte : « −30 % », « −5 € », avec l'arrondi éventuel. */
function aco_discount_text(array $op): string
{
    if ($op['discount_type'] === 'amount') {
        $label = '−' . rtrim(rtrim(number_format((float) $op['discount_value'], 2, ',', ' '), '0'), ',') . ' €';
    } else {
        $label = '−' . rtrim(rtrim(number_format((float) $op['discount_value'], 2, ',', ''), '0'), ',') . ' %';
    }
    if ((int) $op['round_to'] > 0) $label .= ' · arrondi ' . rtrim(rtrim(number_format((int) $op['round_to'] / 100, 2, ',', ''), '0'), ',') . ' €';
    return $label;
}

function aco_period_label(array $op): string
{
    return date('d/m/Y H:i', (int) $op['starts_at']) . ' → ' . date('d/m/Y H:i', (int) $op['ends_at']);
}

function aco_period_short(array $op): string
{
    $a = (int) $op['starts_at'];
    $b = (int) $op['ends_at'];
    return date('d/m/Y', $a) === date('d/m/Y', $b)
        ? 'le ' . date('d/m/Y', $a)
        : 'du ' . date('d/m/Y', $a) . ' au ' . date('d/m/Y', $b);
}

/** « 30 » ou « 12,5 » → centimes ; null si invalide. */
function aco_money_cents(mixed $value): ?int
{
    $value = trim(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], (string) $value));
    if ($value === '' || !is_numeric($value)) return null;
    $cents = (int) round((float) $value * 100);
    return $cents >= 0 && $cents <= 10000000 ? $cents : null;
}

function aco_csv(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
}

/** Pièce éligible : visible, en stock, à prix fixe (ni fourchette ni « à partir de »). */
function aco_eligible(array $p): bool
{
    if ((int) $p['is_hidden'] === 1 || (int) $p['stock'] <= 0) return false;
    $price = (string) $p['price'];
    if (!is_fixed_price($price) || preg_match('/\d\s*[-–—]\s*\d/u', $price)) return false;
    return (price_to_cents($price) ?? 0) > 0;
}

/** Prix actuellement facturé : promo en cours si elle existe, sinon prix de base. */
function aco_current_cents(array $p): int
{
    $price = has_promo_price($p) ? (string) $p['promo_price'] : (string) $p['price'];
    return (int) (price_to_cents($price) ?? 0);
}

function aco_age_days(array $p, int $now): int
{
    $created = trim((string) ($p['created_at'] ?? ''));
    $t = $created !== '' ? (int) strtotime($created . ' UTC') : 0;
    return $t > 0 ? max(0, intdiv($now - $t, 86400)) : 0;
}

/** Pièces concernées par une opération (avant calcul du nouveau prix). */
function aco_scope(array $op): array
{
    $refs = aco_csv((string) $op['refs']);
    $cats = aco_csv((string) $op['cats']);
    $min = (int) $op['min_cents'];
    $max = (int) $op['max_cents'];
    $minAge = (int) $op['min_age_days'];
    $now = time();
    $out = [];
    foreach (db()->query('SELECT * FROM products ORDER BY sort_order, ref')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ($refs !== [] ? !in_array($p['ref'], $refs, true) : ($cats !== [] && !in_array($p['cat'], $cats, true))) continue;
        if (!aco_eligible($p)) continue;
        $cur = aco_current_cents($p);
        if ($min > 0 && $cur < $min) continue;
        if ($max > 0 && $cur > $max) continue;
        if ($minAge > 0 && aco_age_days($p, $now) < $minAge) continue;
        $out[] = $p;
    }
    return $out;
}

/**
 * Nouveau prix en centimes, ou null si la pièce ne peut pas baisser (plancher atteint, arrondi sans effet).
 * L'arrondi se fait vers le bas ; le prix de vente minimum (interne) est toujours respecté.
 */
function aco_new_cents(array $p, array $op): ?int
{
    $cur = aco_current_cents($p);
    if ($cur <= 0) return null;
    if ($op['discount_type'] === 'amount') {
        $cut = (int) round((float) $op['discount_value'] * 100);
        if ($cut >= $cur) return null;
        $new = $cur - $cut;
    } else {
        $pct = max(0.0, min((float) ACO_MAX_PERCENT, (float) $op['discount_value']));
        $new = (int) round($cur * (100 - $pct) / 100);
    }
    $round = (int) $op['round_to'];
    if ($round >= 5) $new = intdiv($new, $round) * $round;
    if (!empty($op['respect_floor']) && trim((string) ($p['price_floor'] ?? '')) !== '') {
        $floor = price_to_cents((string) $p['price_floor']);
        if ($floor !== null && $floor > 0) $new = max($new, (int) $floor);
    }
    $new = max(5, $new);
    return $new < $cur ? $new : null;
}

/** Aperçu : nombre de pièces, valeur avant / après, échantillon. */
function aco_analyze(array $op): array
{
    $items = aco_scope($op);
    $before = 0;
    $after = 0;
    $discounted = 0;
    $sample = [];
    foreach ($items as $p) {
        $old = aco_current_cents($p);
        $new = aco_new_cents($p, $op);
        if ($new === null) continue;
        $before += $old;
        $after += $new;
        $discounted++;
        if (count($sample) < 8) {
            $sample[] = ['ref' => (string) $p['ref'], 'name' => (string) $p['name'], 'old_cents' => $old, 'new_cents' => $new,
                'old' => aco_format($old), 'new' => aco_format($new)];
        }
    }
    return ['eligible' => count($items), 'discounted' => $discounted, 'skipped' => count($items) - $discounted,
        'before_cents' => $before, 'after_cents' => $after, 'sample' => $sample];
}

/** Applique l'opération : photographie les prix d'origine et pose les prix réduits. -> nombre de pièces modifiées. */
function aco_apply(array $op): int
{
    $id = (int) $op['id'];
    $changed = 0;
    db()->exec('BEGIN IMMEDIATE');
    try {
        $owned = db()->prepare("SELECT 1 FROM aco_items i JOIN aco_operations o ON o.id = i.operation_id
            WHERE i.product_ref = ? AND i.active = 1 AND o.id <> ? AND o.status = 'running' LIMIT 1");
        $snap = db()->prepare('INSERT OR REPLACE INTO aco_items (operation_id, product_ref, orig_badge, orig_promo, old_cents, new_cents, active)
            VALUES (?, ?, ?, ?, ?, ?, 0)');
        $mark = db()->prepare('UPDATE aco_items SET active = 1 WHERE operation_id = ? AND product_ref = ?');
        $upd = db()->prepare("UPDATE products SET promo_price = ?, badge = 'Promo' WHERE ref = ?");
        foreach (aco_scope($op) as $p) {
            $new = aco_new_cents($p, $op);
            if ($new === null) continue;
            $owned->execute([$p['ref'], $id]);
            if ($owned->fetchColumn()) continue;   // déjà réduite par une autre opération en cours : on ne l'écrase pas
            $snap->execute([$id, $p['ref'], (string) ($p['badge'] ?? ''), (string) ($p['promo_price'] ?? ''), aco_current_cents($p), $new]);
            $upd->execute([aco_format($new), $p['ref']]);
            $mark->execute([$id, $p['ref']]);
            $changed++;
        }
        db()->prepare("UPDATE aco_operations SET status = 'running' WHERE id = ?")->execute([$id]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        error_log('Actions commerciales (application) : ' . $e->getMessage());
        return 0;
    }
    return $changed;
}

/** Rétablit les prix d'origine des pièces encore au prix de l'opération. */
function aco_restore(array $op): int
{
    $restored = 0;
    $rows = db()->prepare('SELECT * FROM aco_items WHERE operation_id = ? AND active = 1');
    $rows->execute([(int) $op['id']]);
    $get = db()->prepare('SELECT promo_price FROM products WHERE ref = ?');
    $upd = db()->prepare('UPDATE products SET promo_price = ?, badge = ? WHERE ref = ?');
    db()->exec('BEGIN IMMEDIATE');
    try {
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $get->execute([$i['product_ref']]);
            $cur = $get->fetch(PDO::FETCH_ASSOC);
            $expected = aco_format((int) $i['new_cents']);
            // Prix changé à la main entre-temps : on ne l'écrase pas.
            if ($cur && (string) $cur['promo_price'] === $expected) {
                $upd->execute([$i['orig_promo'] !== '' ? $i['orig_promo'] : null, $i['orig_badge'], $i['product_ref']]);
                $restored++;
            }
        }
        db()->prepare('UPDATE aco_items SET active = 0 WHERE operation_id = ?')->execute([(int) $op['id']]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        error_log('Actions commerciales (rétablissement) : ' . $e->getMessage());
        return 0;
    }
    return $restored;
}

/** Fait avancer les opérations (démarrage, fin). Sans droit d'usage : tout est rétabli et arrêté. */
function aco_tick(bool $force = false): void
{
    if (!aco_enabled()) {
        foreach (db()->query("SELECT * FROM aco_operations WHERE status = 'running'")->fetchAll(PDO::FETCH_ASSOC) as $op) {
            aco_restore($op);
            db()->prepare("UPDATE aco_operations SET status = 'canceled', stopped_at = ? WHERE id = ?")->execute([time(), (int) $op['id']]);
        }
        return;
    }
    static $last = 0;
    if (!$force && time() - $last < ACO_TICK_EVERY) return;
    $last = time();
    $now = time();
    foreach (db()->query("SELECT * FROM aco_operations WHERE status IN ('scheduled', 'running') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $op) {
        if ($now >= (int) $op['ends_at']) {
            aco_restore($op);
            db()->prepare("UPDATE aco_operations SET status = 'ended', stopped_at = ? WHERE id = ?")->execute([(int) $op['ends_at'], (int) $op['id']]);
        } elseif ($now >= (int) $op['starts_at'] && $op['status'] === 'scheduled') {
            aco_apply($op);
        }
    }
}

/** Arrête une opération (ou annule une opération programmée) : prix d'origine rétablis. */
function aco_stop(int $id): bool
{
    $op = aco_op($id);
    if (!$op || !in_array($op['status'], ['scheduled', 'running'], true)) return false;
    aco_restore($op);
    db()->prepare("UPDATE aco_operations SET status = 'canceled', stopped_at = ? WHERE id = ?")->execute([time(), $id]);
    return true;
}

/** Démarre tout de suite une opération programmée (la fin reste celle prévue). */
function aco_start_now(int $id): ?string
{
    $op = aco_op($id);
    if (!$op) return 'Opération introuvable.';
    if ($op['status'] === 'running') return 'Cette opération est déjà en cours.';
    if ($op['status'] !== 'scheduled') return 'Seule une opération programmée peut être démarrée.';
    if ((int) $op['ends_at'] <= time()) return 'La fin de l\'opération est déjà passée.';
    db()->prepare('UPDATE aco_operations SET starts_at = ? WHERE id = ?')->execute([min(time(), (int) $op['starts_at']), $id]);
    $op = aco_op($id);
    $n = aco_apply($op);
    return $n > 0 ? null : 'Aucune pièce ne peut être réduite avec ces critères.';
}

/** Supprime une opération terminée ou annulée (et sa photographie de prix). */
function aco_delete(int $id): bool
{
    $op = aco_op($id);
    if (!$op || !in_array($op['status'], ['ended', 'canceled'], true)) return false;
    db()->prepare('DELETE FROM aco_items WHERE operation_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM aco_operations WHERE id = ?')->execute([$id]);
    return true;
}

/** Totaux d'une opération : pièces, valeur d'origine, valeur réduite. */
function aco_totals(array $op): array
{
    $st = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(old_cents), 0) AS old_cents, COALESCE(SUM(new_cents), 0) AS new_cents FROM aco_items WHERE operation_id = ?');
    $st->execute([(int) $op['id']]);
    $t = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'old_cents' => 0, 'new_cents' => 0];
    return ['count' => (int) $t['n'], 'old_cents' => (int) $t['old_cents'], 'new_cents' => (int) $t['new_cents']];
}

/** Opération à afficher en boutique : en cours, ou programmée dans les sept jours. */
function aco_front_operation(): ?array
{
    $now = time();
    $st = db()->prepare("SELECT * FROM aco_operations WHERE status IN ('running', 'scheduled') AND ends_at > ? AND starts_at <= ?
        ORDER BY CASE status WHEN 'running' THEN 0 ELSE 1 END, starts_at ASC LIMIT 1");
    $st->execute([$now, $now + 7 * 86400]);
    $op = $st->fetch(PDO::FETCH_ASSOC);
    return $op ?: null;
}

/** Opération en cours qui a effectivement réduit cette pièce (pour la fiche produit). */
function aco_operation_for_ref(string $ref): ?array
{
    $st = db()->prepare("SELECT o.* FROM aco_operations o JOIN aco_items i ON i.operation_id = o.id
        WHERE i.product_ref = ? AND i.active = 1 AND o.status = 'running' LIMIT 1");
    $st->execute([$ref]);
    $op = $st->fetch(PDO::FETCH_ASSOC);
    return $op ?: null;
}

/**
 * Ventes payées pendant l'opération : commandes, lignes, chiffre d'affaires total et part concernée par l'opération.
 * Les commandes de la caisse (« Vente en boutique ») sont comprises.
 */
function aco_sales_report(array $op): array
{
    $refs = [];
    $st = db()->prepare('SELECT product_ref FROM aco_items WHERE operation_id = ?');
    $st->execute([(int) $op['id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) $refs[(string) $r] = true;
    if (!$refs && $op['status'] === 'scheduled') {
        foreach (aco_scope($op) as $p) $refs[(string) $p['ref']] = true;
    }
    $cats = [];
    foreach (db()->query('SELECT ref, cat FROM products')->fetchAll(PDO::FETCH_ASSOC) as $p) $cats[(string) $p['ref']] = (string) $p['cat'];

    $orders = [];
    $byRef = [];
    $byCat = [];
    $revenue = 0;
    $opRevenue = 0;
    $opUnits = 0;
    $end = (int) $op['ends_at'];
    if ((int) ($op['stopped_at'] ?? 0) > 0) $end = min($end, (int) $op['stopped_at']);
    $stmt = db()->prepare("SELECT * FROM orders WHERE status = 'paid' AND created_at >= ? AND created_at <= ? ORDER BY created_at");
    $stmt->execute([gmdate('Y-m-d H:i:s', (int) $op['starts_at']), gmdate('Y-m-d H:i:s', $end)]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $total = (int) $o['amount_total'];
        $revenue += $total;
        $lines = [];
        foreach (json_decode((string) $o['items'], true) ?: [] as $l) {
            if (!is_array($l)) continue;
            $ref = (string) ($l['ref'] ?? '');
            $qty = max(1, (int) ($l['qty'] ?? 1));
            $unit = (int) ($l['unit_cents'] ?? 0);
            $inOp = isset($refs[$ref]);
            $lines[] = ['ref' => $ref, 'name' => (string) ($l['name'] ?? ''), 'qty' => $qty, 'unit_cents' => $unit, 'total_cents' => $unit * $qty, 'in_op' => $inOp];
            $b = &$byRef[$ref];
            $b ??= ['name' => (string) ($l['name'] ?? ''), 'qty' => 0, 'total_cents' => 0, 'in_op' => $inOp];
            $b['qty'] += $qty;
            $b['total_cents'] += $unit * $qty;
            unset($b);
            if ($inOp) {
                $opRevenue += $unit * $qty;
                $opUnits += $qty;
                $label = category_label($cats[$ref] ?? '');
                $c = &$byCat[$label];
                $c ??= ['qty' => 0, 'total_cents' => 0];
                $c['qty'] += $qty;
                $c['total_cents'] += $unit * $qty;
                unset($c);
            }
        }
        $orders[] = ['id' => (int) $o['id'], 'at' => (string) $o['created_at'], 'total_cents' => $total,
            'method' => (string) ($o['shipping_method'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'lines' => $lines];
    }
    uasort($byRef, static fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);
    uasort($byCat, static fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);
    return ['orders' => array_reverse($orders), 'orders_count' => count($orders), 'revenue_cents' => $revenue,
        'op_revenue_cents' => $opRevenue, 'op_units' => $opUnits, 'by_ref' => $byRef, 'by_cat' => $byCat];
}

// — Annonces aux visiteurs : trois listes opt-in de la boutique (web app, alertes chineur, newsletter), dédoublonnées,
//   avec un lien de désinscription par adresse et un journal par opération. Les annonces ne partent que sur action explicite.

const ACO_NOTICE_BATCH = 40;   // adresses par envoi : au-delà, on relance pour ne pas saturer la boîte de l'hébergeur

function aco_notice_sources(): array
{
    return ['visiteur' => 'membres de la web app', 'chineur' => 'abonnés aux alertes', 'prospect' => 'prospects newsletter'];
}

/** Table d'un autre module présente dans cette boutique (ces modules ne sont pas toujours installés). */
function aco_table_exists(string $table): bool
{
    try {
        $st = db()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Adresses à prévenir, avec leurs sources : membres de l'espace visiteur (accord explicite), abonnés confirmés
 * aux alertes chineur, prospects inscrits à la newsletter. Les désinscrits et les adresses déjà prévenues
 * pour l'opération sont comptés à part ; seules les adresses en attente sont renvoyées.
 */
function aco_notice_audience(?array $op = null): array
{
    $emails = [];
    $add = static function (string $email, string $source) use (&$emails): void {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        $emails[$email][$source] = true;
    };
    if (aco_table_exists('ev_members')) {
        foreach (db()->query('SELECT email FROM ev_members')->fetchAll(PDO::FETCH_COLUMN) as $e) $add((string) $e, 'visiteur');
    }
    if (aco_table_exists('ac_alerts')) {
        foreach (db()->query("SELECT email FROM ac_alerts WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $e) $add((string) $e, 'chineur');
    }
    if (aco_table_exists('accounts')) {
        foreach (db()->query("SELECT email FROM accounts WHERE source = 'newsletter' AND is_active = 1 AND role IN ('prospect', 'client')")->fetchAll(PDO::FETCH_COLUMN) as $e) $add((string) $e, 'prospect');
    }
    $stopped = [];
    if (aco_table_exists('aco_contacts')) {
        foreach (db()->query("SELECT email FROM aco_contacts WHERE state = 'stopped'")->fetchAll(PDO::FETCH_COLUMN) as $e) $stopped[mb_strtolower((string) $e)] = true;
    }
    $sent = [];
    if ($op && aco_table_exists('aco_notices')) {
        $st = db()->prepare("SELECT email FROM aco_notices WHERE operation_id = ? AND status = 'sent'");
        $st->execute([(int) $op['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $sent[mb_strtolower((string) $e)] = true;
    }
    $bySource = array_fill_keys(array_keys(aco_notice_sources()), 0);
    $pending = [];
    $blocked = 0;
    $already = 0;
    foreach ($emails as $email => $sources) {
        foreach (array_keys($sources) as $s) $bySource[$s]++;
        if (isset($stopped[$email])) { $blocked++; continue; }
        if (isset($sent[$email])) { $already++; continue; }
        $pending[$email] = array_keys($sources);
    }
    return ['total' => count($emails), 'by_source' => $bySource, 'pending' => $pending,
        'pending_count' => count($pending), 'stopped' => $blocked, 'already' => $already];
}

/** Fiche d'envoi d'une adresse (jeton de désinscription) : créée au premier envoi, jamais modifiée ensuite. */
function aco_notice_contact(string $email): ?array
{
    $email = mb_strtolower(trim($email));
    $st = db()->prepare('SELECT * FROM aco_contacts WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    try {
        db()->prepare("INSERT INTO aco_contacts (email, token, state) VALUES (?, ?, 'active')")->execute([$email, bin2hex(random_bytes(16))]);
    } catch (Throwable $e) {
        // adresse ajoutée entre-temps par un autre envoi : on relit la fiche existante
    }
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Objet et texte de l'annonce, avec l'adresse de la boutique et le lien de désinscription. */
function aco_notice_text(array $op, string $shopUrl, string $stopUrl): array
{
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $label = trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']);
    $place = trim((string) $op['place']);
    $banner = trim((string) $op['banner_text']);
    $subject = $label . ' ' . aco_period_short($op) . ' — ' . $site;
    $lines = [
        'Bonjour,',
        '',
        $site . ' organise ' . $label . ' ' . aco_period_short($op) . ($place !== '' ? ' — ' . $place : '') . '.',
        aco_discount_text($op) . ' sur une sélection de pièces.',
    ];
    if ($banner !== '') {
        $lines[] = '';
        $lines[] = $banner;
    }
    $lines[] = '';
    $lines[] = 'Voir la boutique : ' . $shopUrl;
    $lines[] = '';
    $lines[] = 'À bientôt,';
    $lines[] = $site;
    $lines[] = '';
    $lines[] = 'Vous recevez ce message parce que vous suivez cette boutique. Pour ne plus recevoir ses annonces : ' . $stopUrl;
    return [$subject, implode("\n", $lines)];
}

/**
 * Envoie l'annonce de l'opération aux adresses en attente, par lots de ACO_NOTICE_BATCH.
 * Un échec est journalisé et pourra être retenté ; une adresse prévenue ne reçoit jamais deux fois la même opération.
 */
function aco_notice_send(array $op): array
{
    if (!in_array((string) $op['status'], ['scheduled', 'running'], true)) {
        return ['error' => 'Seule une opération programmée ou en cours peut être annoncée.'];
    }
    $aud = aco_notice_audience($op);
    if ($aud['pending_count'] === 0) {
        return ['ok' => true, 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'message' => 'Aucune nouvelle adresse à prévenir.'];
    }
    $shopUrl = shop_abs_url('/boutique.php');
    $journal = db()->prepare('INSERT OR REPLACE INTO aco_notices (operation_id, email, source, status) VALUES (?, ?, ?, ?)');
    $sent = 0;
    $failed = 0;
    foreach ($aud['pending'] as $email => $sources) {
        if ($sent + $failed >= ACO_NOTICE_BATCH) break;
        $contact = aco_notice_contact($email);
        if (!$contact || $contact['state'] !== 'active') continue;
        [$subject, $body] = aco_notice_text($op, $shopUrl, shop_abs_url('/api/braderie/stop/' . $contact['token']));
        $ok = aco_mail($email, $subject, $body);
        $journal->execute([(int) $op['id'], $email, implode(',', $sources), $ok ? 'sent' : 'failed']);
        $ok ? $sent++ : $failed++;
    }
    $remaining = aco_notice_audience($op)['pending_count'];
    return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining,
        'message' => $sent . ' annonce(s) envoyée(s)' . ($failed > 0 ? ', ' . $failed . ' échec(s) à retenter' : '')
            . ($remaining > 0 ? ' — ' . $remaining . ' adresse(s) restante(s) : relancez pour continuer.' : '.')];
}

function aco_mail(string $to, string $subject, string $body): bool
{
    try {
        return password_reset_mail($to, $subject, $body);
    } catch (Throwable $e) {
        error_log('Actions commerciales (annonce) : ' . $e->getMessage());
        return false;
    }
}

/** Chiffres affichés dans l'administration : audience totale, déjà prévenus, en attente, désinscrits. */
function aco_notice_stats(array $op): array
{
    $aud = aco_notice_audience($op);
    return ['audience' => $aud['total'], 'sent' => $aud['already'], 'pending' => $aud['pending_count'],
        'stopped' => $aud['stopped'], 'by_source' => $aud['by_source']];
}

/** Lignes du bilan au format CSV. */
function aco_csv_rows(array $op, array $report): array
{
    $rows = [['Date', 'Commande', 'Canal', 'Référence', 'Pièce', 'Quantité', 'Prix unitaire', 'Total', 'Dans l\'opération']];
    foreach ($report['orders'] as $o) {
        foreach ($o['lines'] as $l) {
            $rows[] = [date('d/m/Y H:i', strtotime($o['at'] . ' UTC')), '#' . $o['id'], $o['method'], $l['ref'], $l['name'],
                (string) $l['qty'], number_format($l['unit_cents'] / 100, 2, ',', ''), number_format($l['total_cents'] / 100, 2, ',', ''),
                $l['in_op'] ? 'oui' : 'non'];
        }
    }
    return $rows;
}
