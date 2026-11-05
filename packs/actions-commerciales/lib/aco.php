<?php
// Actions commerciales : braderie, vente flash, foire ou soldes. Une opération applique une remise aux pièces choisies,
// les prix d'origine sont rétablis à la fin (ou à l'arrêt), et les ventes de la période sont chiffrées.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const ACO_MODULE = 'actions-commerciales';
const ACO_TICK_EVERY = 20;          // secondes minimum entre deux vérifications déclenchées par les visites
const ACO_MAX_PERCENT = 90;
const ACO_MAX_AMOUNT_CENTS = 1000000;

function aco_kind_labels(): array
{
    return ['braderie' => 'Braderie', 'flash' => 'Vente flash', 'foire' => 'Foire / marché', 'soldes' => 'Soldes'];
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
    aco_campaign_run();
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
    if (aco_table_exists('aco_campaign_steps')) {
        db()->prepare('DELETE FROM aco_campaign_sends WHERE step_id IN (SELECT id FROM aco_campaign_steps WHERE operation_id = ?)')->execute([$id]);
        db()->prepare('DELETE FROM aco_campaign_steps WHERE operation_id = ?')->execute([$id]);
    }
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

// — Annonces aux visiteurs : e-mail, annonce dans l'app, SMS et WhatsApp. Trois listes opt-in de la boutique,
//   dédoublonnées, un accord par canal, un lien de désinscription et un journal par opération. Rien ne part sans action explicite.

const ACO_NOTICE_BATCH = 40;   // adresses par canal et par envoi : au-delà, on relance pour ne pas saturer les envois
const ACO_CHANNELS = ['mail', 'inapp', 'sms', 'whatsapp'];

function aco_notice_sources(): array
{
    return ['visiteur' => 'membres de la web app', 'chineur' => 'abonnés aux alertes', 'prospect' => 'prospects newsletter'];
}

function aco_channel_labels(): array
{
    return ['mail' => 'E-mail', 'inapp' => 'Dans l\'app', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
}

/** Canal prêt à envoyer : e-mail et in-app toujours ; SMS via OVH ou Twilio (includes/sms.php) ; WhatsApp via l'API Meta. */
function aco_channel_ready(string $channel): bool
{
    if ($channel === 'mail' || $channel === 'inapp') return true;
    if ($channel === 'sms') {
        require_once dirname(__DIR__, 3) . '/includes/login-otp.php';
        return function_exists('login_otp_sms_available') && login_otp_sms_available();
    }
    if ($channel === 'whatsapp') {
        $c = aco_whatsapp_config();
        return $c['phone_id'] !== '' && $c['token'] !== '';
    }
    return false;
}

/** Identifiants WhatsApp Cloud API (Meta) : réglages de la boutique, sinon variables d'environnement. */
function aco_whatsapp_config(): array
{
    $read = static function (string $setting, string $env): string {
        $v = trim((string) getenv($env));
        if ($v !== '') return $v;
        try {
            return trim(shop_setting($setting, ''));
        } catch (Throwable $e) {
            return '';
        }
    };
    return ['phone_id' => $read('meta_wa_phone_id', 'WHATSAPP_PHONE_ID'),
        'token' => $read('meta_wa_token', 'WHATSAPP_TOKEN'),
        'template' => $read('meta_wa_template', 'WHATSAPP_TEMPLATE')];
}

/** Numéro international : « 06 12 34 56 78 » → « +33612345678 » ; null si invalide. */
function aco_phone_e164(string $raw): ?string
{
    $p = preg_replace('/[\s.\-()\/]/', '', trim($raw));
    if ($p === '' || !preg_match('/^\+?\d+$/', $p)) return null;
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if ($p[0] !== '+' && preg_match('/^0[1-9]\d{8}$/', $p)) $p = '+33' . substr($p, 1);
    $digits = strlen(ltrim($p, '+'));
    return $digits >= 8 && $digits <= 15 ? $p : null;
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

/** Jeton de version des caches d'audience : aco_cache_flush() les périme après une écriture. */
function aco_cache_gen(int $bump = 0): int
{
    static $gen = 0;
    if ($bump > 0) $gen++;
    return $gen;
}

function aco_cache_flush(): void
{
    aco_cache_gen(1);
}

/**
 * Toutes les adresses joignables, avec leurs listes d'origine. « idok » : la personne peut être reconnue sur le site
 * (compte client ou lien personnel de la web app), donc voir une annonce dans l'app.
 */
function aco_notice_base(): array
{
    static $base = null, $gen = -1;
    if ($base !== null && $gen === aco_cache_gen()) return $base;
    $gen = aco_cache_gen();
    $base = [];
    $add = static function (string $email, string $source, bool $idok = false, string $name = '', string $phone = '') use (&$base): void {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        if (!isset($base[$email])) $base[$email] = ['sources' => [], 'idok' => false, 'name' => '', 'phone' => ''];
        if ($source !== '') $base[$email]['sources'][$source] = true;
        $base[$email]['idok'] = $base[$email]['idok'] || $idok;
        if ($base[$email]['name'] === '' && $name !== '') $base[$email]['name'] = $name;
        if ($base[$email]['phone'] === '' && $phone !== '') $base[$email]['phone'] = $phone;
    };
    if (aco_table_exists('ev_members')) {
        foreach (db()->query('SELECT email, name FROM ev_members')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add((string) $r['email'], 'visiteur', true, (string) $r['name']);
        }
    }
    if (aco_table_exists('ac_alerts')) {
        foreach (db()->query("SELECT email FROM ac_alerts WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $e) $add((string) $e, 'chineur');
    }
    if (aco_table_exists('accounts')) {
        foreach (db()->query("SELECT email, name, phone FROM accounts WHERE source = 'newsletter' AND is_active = 1 AND role IN ('prospect', 'client')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add((string) $r['email'], 'prospect', true, (string) $r['name'], (string) $r['phone']);
        }
    }
    return $base;
}

/** Fiches de consentement (numéro, accords par canal), indexées par adresse. */
function aco_contacts_map(): array
{
    static $map = null, $gen = -1;
    if ($map !== null && $gen === aco_cache_gen()) return $map;
    $gen = aco_cache_gen();
    $map = [];
    if (aco_table_exists('aco_contacts')) {
        foreach (db()->query('SELECT * FROM aco_contacts')->fetchAll(PDO::FETCH_ASSOC) as $r) $map[mb_strtolower((string) $r['email'])] = $r;
    }
    return $map;
}

/** La personne accepte-t-elle ce canal (et a-t-elle le moyen nécessaire : compte reconnu, numéro valide) ? */
function aco_channel_allowed(string $channel, ?array $contact, array $info): bool
{
    if ($channel === 'mail') return true;
    if ($channel === 'inapp') return $info['idok'] && (!$contact || (int) ($contact['consent_inapp'] ?? 1) === 1);
    if ($channel === 'sms' || $channel === 'whatsapp') {
        if (!$contact || aco_phone_e164((string) ($contact['phone'] ?? '')) === null) return false;
        return (int) ($contact[$channel === 'sms' ? 'consent_sms' : 'consent_wa'] ?? 0) === 1;
    }
    return false;
}

/**
 * Adresses à prévenir pour un canal : membres de l'espace visiteur (accord explicite), abonnés confirmés
 * aux alertes chineur, prospects inscrits à la newsletter. Les désinscrits, les adresses déjà prévenues
 * pour l'opération et celles sans accord pour ce canal sont comptés à part ; seules les adresses en attente sont renvoyées.
 */
function aco_notice_audience(?array $op = null, string $channel = 'mail', ?int $stepId = null): array
{
    $base = aco_notice_base();
    $contacts = aco_contacts_map();
    $sent = [];
    if ($stepId !== null && aco_table_exists('aco_campaign_sends')) {
        // Étape de campagne : une adresse déjà traitée (envoyée ou refusée) n'est pas reprise automatiquement.
        $st = db()->prepare('SELECT email FROM aco_campaign_sends WHERE step_id = ? AND channel = ?');
        $st->execute([$stepId, $channel]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $sent[mb_strtolower((string) $e)] = true;
    } elseif ($op && aco_table_exists('aco_messages')) {
        $st = db()->prepare("SELECT email FROM aco_messages WHERE operation_id = ? AND channel = ? AND status = 'sent'");
        $st->execute([(int) $op['id'], $channel]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $sent[mb_strtolower((string) $e)] = true;
    }
    $bySource = array_fill_keys(array_keys(aco_notice_sources()), 0);
    $pending = [];
    $blocked = 0;
    $already = 0;
    $skipped = 0;
    foreach ($base as $email => $info) {
        foreach (array_keys($info['sources']) as $s) $bySource[$s]++;
        $contact = $contacts[$email] ?? null;
        if ($contact && (string) $contact['state'] === 'stopped') { $blocked++; continue; }
        if (isset($sent[$email])) { $already++; continue; }
        if (!aco_channel_allowed($channel, $contact, $info)) { $skipped++; continue; }
        $pending[$email] = array_keys($info['sources']);
    }
    return ['total' => count($base), 'by_source' => $bySource, 'pending' => $pending,
        'pending_count' => count($pending), 'stopped' => $blocked, 'already' => $already, 'skipped' => $skipped];
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

/** Texte court pour le SMS et WhatsApp, avec la boutique et le lien de désinscription. */
function aco_notice_short(array $op, string $stopUrl): string
{
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $label = trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']);
    return mb_substr($site . ' : ' . $label . ' ' . aco_period_short($op) . ' — ' . aco_discount_text($op)
        . ' sur une sélection. ' . shop_abs_url('/boutique.php') . ' Désinscription : ' . $stopUrl, 0, 480);
}

/** Livraison d'un message sur un canal externe. L'in-app se contente du journal (la personne le lit connectée). */
function aco_channel_deliver(string $channel, string $email, string $phone, string $subject, string $body, array $op, string $stopUrl, string $short = '', string $mailContext = ''): bool
{
    try {
        $short = $short !== '' ? $short : aco_notice_short($op, $stopUrl);
        if ($channel === 'mail') return aco_mail($email, $subject, $body, $mailContext !== '' ? $mailContext : 'aco:' . (int) $op['id']);
        if ($channel === 'sms') {
            require_once dirname(__DIR__, 3) . '/includes/login-otp.php';
            return login_otp_send_sms($phone, $short);
        }
        if ($channel === 'whatsapp') return aco_whatsapp_send($phone, $short);
    } catch (Throwable $e) {
        error_log('Actions commerciales (' . $channel . ') : ' . $e->getMessage());
    }
    return false;
}

/** Message WhatsApp Cloud API : modèle approuvé si « meta_wa_template » est renseigné, sinon texte simple. */
function aco_whatsapp_send(string $phone, string $text): bool
{
    $c = aco_whatsapp_config();
    if ($c['phone_id'] === '' || $c['token'] === '') return false;
    $to = preg_replace('/\D/', '', $phone);
    $payload = $c['template'] !== ''
        ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
            'template' => ['name' => $c['template'], 'language' => ['code' => 'fr'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]]]]
        : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text]];
    $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($c['phone_id']) . '/messages';
    $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $c['token'] . "\r\n",
        'content' => $json,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

/**
 * Envoie l'annonce de l'opération sur les canaux demandés, par lots de ACO_NOTICE_BATCH et par canal.
 * Un échec est journalisé et pourra être retenté ; une adresse prévenue ne reçoit jamais deux fois la même
 * opération sur le même canal.
 */
function aco_notice_send(array $op, array $channels = ['mail'], ?array $step = null): array
{
    if (!in_array((string) $op['status'], ['scheduled', 'running'], true)) {
        return ['error' => 'Seule une opération programmée ou en cours peut être annoncée.'];
    }
    $channels = array_values(array_intersect(ACO_CHANNELS, array_map('strval', $channels)));
    if (!$channels) $channels = ['mail'];
    $ready = array_values(array_filter($channels, 'aco_channel_ready'));
    $labels = aco_channel_labels();
    if (!$ready) {
        return ['error' => 'Aucun canal disponible (' . implode(', ', array_map(static fn ($c) => $labels[$c] ?? $c, $channels))
            . ') : configurez l\'envoi SMS (OVH ou Twilio) ou WhatsApp (Meta) dans les réglages de la boutique.'];
    }
    $shopUrl = shop_abs_url('/boutique.php');
    $stepId = $step ? (int) $step['id'] : null;
    $journal = db()->prepare('INSERT OR REPLACE INTO aco_messages (operation_id, email, channel, status, detail) VALUES (?, ?, ?, ?, ?)');
    // Étape de campagne : journal propre à l'étape ; le journal de l'opération garde la première annonce reçue par chaque adresse.
    $stepJournal = $step ? db()->prepare('INSERT OR REPLACE INTO aco_campaign_sends (step_id, email, channel, status) VALUES (?, ?, ?, ?)') : null;
    $opJournal = $step ? db()->prepare('INSERT OR IGNORE INTO aco_messages (operation_id, email, channel, status, detail) VALUES (?, ?, ?, ?, ?)') : null;
    $out = ['ok' => true, 'sent' => 0, 'failed' => 0, 'remaining' => 0, 'channels' => []];
    $parts = [];
    foreach ($ready as $channel) {
        $aud = aco_notice_audience($op, $channel, $stepId);
        $sent = 0;
        $failed = 0;
        foreach ($aud['pending'] as $email => $sources) {
            if ($sent + $failed >= ACO_NOTICE_BATCH) break;
            $contact = aco_notice_contact($email);
            if (!$contact || (string) $contact['state'] !== 'active') continue;
            $stopUrl = shop_abs_url('/api/braderie/stop/' . $contact['token']);
            if ($step) {
                [$subject, $body] = aco_step_texts($op, $step, $shopUrl, $stopUrl);
                $short = aco_step_short($op, $step, $stopUrl);
            } else {
                [$subject, $body] = aco_notice_text($op, $shopUrl, $stopUrl);
                $short = '';
            }
            $ok = $channel === 'inapp' || aco_channel_deliver($channel, $email, (string) $contact['phone'], $subject, $body, $op, $stopUrl, $short,
                $step ? 'aco:' . (int) $op['id'] . ':s' . $stepId : '');
            if ($step) {
                $stepJournal->execute([$stepId, $email, $channel, $ok ? 'sent' : 'failed']);
                if ($ok) $opJournal->execute([(int) $op['id'], $email, $channel, 'sent', 'campagne : ' . (string) $step['title']]);
            } else {
                $journal->execute([(int) $op['id'], $email, $channel, $ok ? 'sent' : 'failed', $ok ? '' : 'envoi refusé']);
            }
            $ok ? $sent++ : $failed++;
        }
        $remaining = aco_notice_audience($op, $channel, $stepId)['pending_count'];
        $out['channels'][$channel] = ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
        $out['sent'] += $sent;
        $out['failed'] += $failed;
        $out['remaining'] += $remaining;
        if ($sent + $failed > 0) {
            $parts[] = ($labels[$channel] ?? $channel) . ' : ' . $sent . ' envoyé(s)' . ($failed > 0 ? ', ' . $failed . ' échec(s)' : '');
        }
    }
    $skipped = array_values(array_diff($channels, $ready));
    if ($skipped) $parts[] = 'non configuré : ' . implode(', ', array_map(static fn ($c) => $labels[$c] ?? $c, $skipped));
    $out['message'] = $parts
        ? implode(' · ', $parts) . ($out['remaining'] > 0 ? '. ' . $out['remaining'] . ' en attente : relancez pour continuer.' : '.')
        : 'Aucune adresse en attente sur ces canaux.';
    return $out;
}

function aco_mail(string $to, string $subject, string $body, string $context = ''): bool
{
    try {
        return password_reset_mail($to, $subject, $body, 'action-commerciale', $context);
    } catch (Throwable $e) {
        error_log('Actions commerciales (annonce) : ' . $e->getMessage());
        return false;
    }
}

/** Chiffres affichés dans l'administration : audience, en attente et déjà prévenus par canal. */
function aco_notice_stats(array $op): array
{
    $channels = [];
    $audience = 0;
    $bySource = [];
    $stopped = 0;
    foreach (ACO_CHANNELS as $channel) {
        $a = aco_notice_audience($op, $channel);
        $channels[$channel] = ['ready' => aco_channel_ready($channel), 'eligible' => $a['pending_count'],
            'already' => $a['already'], 'skipped' => $a['skipped']];
        if ($channel === 'mail') {
            $audience = $a['total'];
            $bySource = $a['by_source'];
            $stopped = $a['stopped'];
        }
    }
    $opens = ['sent' => 0, 'opened' => 0, 'opens' => 0, 'last_open' => 0];
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail-tracking.php';
        $opens = mail_open_context_summary('aco:' . (int) $op['id']);
    } catch (Throwable $e) {
    }
    return ['audience' => $audience, 'sent' => $channels['mail']['already'], 'pending' => $channels['mail']['eligible'],
        'stopped' => $stopped, 'by_source' => $bySource, 'channels' => $channels,
        'opened' => (int) $opens['opened'], 'opens' => (int) $opens['opens'], 'last_open' => (int) $opens['last_open']];
}

/** Contacts avec listes, numéro et accords par canal (fiche de consentement créée au premier enregistrement). */
function aco_contacts_list(): array
{
    $base = aco_notice_base();
    $map = aco_contacts_map();
    $labels = aco_notice_sources();
    $out = [];
    foreach (array_keys($base + $map) as $email) {
        $info = $base[$email] ?? ['sources' => [], 'idok' => false, 'name' => '', 'phone' => ''];
        $c = $map[$email] ?? null;
        $sources = [];
        foreach (array_keys($info['sources']) as $s) $sources[] = $labels[$s] ?? $s;
        $out[] = [
            'email' => (string) $email,
            'name' => (string) $info['name'],
            'sources' => $sources,
            'phone' => (string) ($c['phone'] ?? '') !== '' ? (string) $c['phone'] : (string) $info['phone'],
            'identifiable' => (bool) $info['idok'],
            'inapp' => $c ? (int) ($c['consent_inapp'] ?? 1) === 1 : (bool) $info['idok'],
            'sms' => $c ? (int) ($c['consent_sms'] ?? 0) === 1 : false,
            'wa' => $c ? (int) ($c['consent_wa'] ?? 0) === 1 : false,
            'stopped' => $c ? (string) $c['state'] === 'stopped' : false,
        ];
    }
    usort($out, static fn (array $a, array $b): int => strcmp($a['email'], $b['email']));
    return $out;
}

/** Enregistre le numéro et les accords d'un contact. Renvoie le message d'erreur, ou null. */
function aco_contact_save(string $email, string $phone, bool $inapp, bool $sms, bool $wa): ?string
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Adresse invalide.';
    $raw = trim($phone);
    $normalized = $raw === '' ? '' : (aco_phone_e164($raw) ?? '');
    if ($raw !== '' && $normalized === '') return 'Numéro invalide (ex. 06 12 34 56 78).';
    if (($sms || $wa) && $normalized === '') return 'Renseignez un numéro avant d\'autoriser le SMS ou WhatsApp.';
    $base = aco_notice_base();
    if ($inapp && empty($base[$email]['idok'])) {
        return 'Cette personne n\'a pas de compte sur le site : elle ne peut pas voir d\'annonce dans l\'app.';
    }
    if (!aco_notice_contact($email)) return 'Contact introuvable.';
    db()->prepare('UPDATE aco_contacts SET phone = ?, consent_inapp = ?, consent_sms = ?, consent_wa = ?, consent_at = CURRENT_TIMESTAMP WHERE email = ?')
        ->execute([$normalized, $inapp ? 1 : 0, $sms ? 1 : 0, $wa ? 1 : 0, $email]);
    aco_cache_flush();
    return null;
}

/** Personne reconnue sur ce site : client connecté, ou membre de la web app arrivé par son lien personnel. */
function aco_current_email(): string
{
    try {
        if (function_exists('customer_session')) {
            $c = customer_session();
            if (is_array($c) && !empty($c['email']) && filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
                return mb_strtolower((string) $c['email']);
            }
        }
    } catch (Throwable $e) {
    }
    $token = trim((string) ($_GET['t'] ?? ''));
    if ($token !== '' && preg_match('/^[a-f0-9]{16,64}$/', $token) && aco_table_exists('ev_members')) {
        $st = db()->prepare('SELECT email FROM ev_members WHERE token = ?');
        $st->execute([$token]);
        $email = $st->fetchColumn();
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) return mb_strtolower((string) $email);
    }
    return '';
}

/** Annonces dans l'app non encore fermées par cette personne, pour les opérations en cours ou à venir. */
function aco_inapp_unseen(string $email): array
{
    if (!aco_table_exists('aco_messages')) return [];
    $st = db()->prepare("SELECT o.* FROM aco_messages m JOIN aco_operations o ON o.id = m.operation_id
        WHERE m.email = ? AND m.channel = 'inapp' AND m.status = 'sent' AND o.status IN ('scheduled', 'running') AND o.ends_at > ?
        ORDER BY o.starts_at ASC LIMIT 3");
    $st->execute([mb_strtolower(trim($email)), time()]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Références des pièces effectivement réduites par l'opération (pour les étiquettes en surimpression). */
function aco_front_refs(array $op): array
{
    $refs = [];
    if ((string) $op['status'] === 'running') {
        $st = db()->prepare('SELECT product_ref FROM aco_items WHERE operation_id = ? AND active = 1');
        $st->execute([(int) $op['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) $refs[(string) $r] = true;
    } else {
        foreach (aco_scope($op) as $p) $refs[(string) $p['ref']] = true;
    }
    return array_keys($refs);
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

// Campagnes de communication : plan d'envois programmés par opération.
require_once __DIR__ . '/campagnes.php';
