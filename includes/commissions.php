<?php

// Commissions de la plateforme et des galeries (portail → Commissions). Trois commissions, réglables globalement puis galerie par galerie :
//   - plateforme sur les ventes des boutiques (taux « sales_platform_pct ») ;
//   - galerie sur les ventes de ses commerces membres (« sales_gallery_pct ») ;
//   - galerie sur les abonnements payés par les commerces inscrits via elle (« subs_gallery_pct »).
// Le GRAND LIVRE (data/saas/commissions.jsonl) garde une ligne par vente ou paiement d'abonnement. Une ligne est « encaissée » quand la
// plateforme détient l'argent (vente payée par Stripe Connect avec commission prélevée, ou abonnement payé à la plateforme) ; sinon elle est
// « à facturer » (suivi seulement : versement hors Stripe). Les versements aux galeries se font par virement Stripe (compte Connect de la
// galerie) pour les lignes encaissées, ou à la main (référence saisie) pour toutes. Aucune commission avant la date de départ réglée.
// Fichiers : data/saas/commissions{.json,.jsonl,-state.json,-payouts.json}, connect.json (comptes), connect-rates.json (lu par les boutiques).

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/saas-events.php';

const CM_RATE_KEYS = ['sales_platform_pct' => 'Plateforme — ventes des boutiques', 'sales_gallery_pct' => 'Galerie — ventes de ses commerces', 'subs_gallery_pct' => 'Galerie — abonnements des commerces recrutés'];
const CM_MAX_PCT = 50.0;

function cm_path(string $name): string
{
    return saas_data_dir() . '/saas/' . $name;
}

function cm_json(string $name, array $default = []): array
{
    $f = cm_path($name);
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : $default;
}

function cm_json_save(string $name, array $data): bool
{
    $dir = dirname(cm_path($name));
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = cm_path($name) . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    return @rename($tmp, cm_path($name));
}

// ── Réglages ────────────────────────────────────────────────────────────────

function cm_pct($v, ?float $default = 0.0): ?float
{
    if ($v === null || $v === '') return $default;
    $f = round((float) str_replace(',', '.', (string) $v), 2);
    return max(0.0, min(CM_MAX_PCT, $f));
}

function cm_config(): array
{
    $c = cm_json('commissions.json');
    $rates = [];
    foreach (CM_RATE_KEYS as $k => $_) $rates[$k] = cm_pct($c['rates'][$k] ?? 0);
    $galleries = [];
    foreach ((array) ($c['galleries'] ?? []) as $slug => $o) {
        if (!gallery_slug_valid((string) $slug) || !is_array($o)) continue;
        $row = [];
        foreach (['sales_gallery_pct', 'subs_gallery_pct'] as $k) if (isset($o[$k]) && $o[$k] !== '' && $o[$k] !== null) $row[$k] = cm_pct($o[$k]);
        if ($row) $galleries[$slug] = $row;
    }
    $shopGallery = [];
    foreach ((array) ($c['shop_gallery'] ?? []) as $shop => $g) if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string) $shop) && ($g === '' || gallery_slug_valid((string) $g))) $shopGallery[$shop] = (string) $g;
    return ['rates' => $rates, 'galleries' => $galleries, 'shop_gallery' => $shopGallery, 'payout_min_cents' => max(100, min(1000000, (int) ($c['payout_min_cents'] ?? 2000))),
        'connect' => !empty($c['connect']), 'since' => (int) ($c['since'] ?? 0)];
}

function cm_config_save(array $c): bool
{
    $old = cm_config();
    if ($c['since'] === 0 && array_sum($c['rates']) + array_sum(array_map(static fn ($g) => array_sum($g), $c['galleries'])) > 0) $c['since'] = $old['since'] ?: time();   // départ des commissions : première fois qu'un taux est enregistré
    $ok = cm_json_save('commissions.json', $c);
    cm_publish_rates();
    return $ok;
}

/** Taux d'une galerie (le sien, sinon le taux général). */
function cm_rate(string $gallery, string $key): float
{
    $c = cm_config();
    return (float) ($c['galleries'][$gallery][$key] ?? $c['rates'][$key] ?? 0.0);
}

/** Galerie de rattachement d'un commerce : choix de l'exploitant, sinon la plus ancienne galerie dont il est membre, sinon aucune. */
function cm_shop_gallery(string $shop, ?array $config = null): string
{
    $config ??= cm_config();
    if (array_key_exists($shop, $config['shop_gallery'])) return $config['shop_gallery'][$shop];
    $best = null;
    foreach (gallery_list() as $g) {
        foreach ($g['members'] as $m) if ($m['type'] === 'local' && $m['slug'] === $shop && (!$best || $g['created'] < $best['created'])) $best = $g;
    }
    return $best['slug'] ?? '';
}

// ── Comptes Stripe Connect ──────────────────────────────────────────────────

function cm_connect(): array
{
    return cm_json('connect.json');
}

function cm_connect_ready(string $key): bool
{
    $a = cm_connect()[$key] ?? null;
    return is_array($a) && ($a['status'] ?? '') === 'ready' && !empty($a['account']);
}

/** Écrit pour les boutiques (includes/connect.php) les commerces dont la commission est prélevée par Stripe Connect. */
function cm_publish_rates(): void
{
    $c = cm_config();
    $out = [];
    if ($c['connect'] && saas_stripe_configured()) {
        foreach (cm_connect() as $key => $a) {
            if (!str_starts_with($key, 'shop:') || !cm_connect_ready($key)) continue;
            $shop = substr($key, 5);
            $gallery = cm_shop_gallery($shop, $c);
            $platform = $c['rates']['sales_platform_pct'];
            $galleryPct = $gallery !== '' ? cm_rate($gallery, 'sales_gallery_pct') : 0.0;
            if ($platform + $galleryPct <= 0) continue;
            $out[$shop] = ['account' => $a['account'], 'platform_pct' => $platform, 'gallery_pct' => $galleryPct, 'pct' => round($platform + $galleryPct, 2), 'gallery' => $gallery];
        }
    }
    cm_json_save('connect-rates.json', $out);
}

/** Crée le compte Connect (Express) d'un commerce ou d'une galerie. -> message d'erreur ou null. */
function cm_account_create(string $kind, string $slug, string $email): ?string
{
    if (!in_array($kind, ['shop', 'gallery'], true)) return 'Type inconnu.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Adresse e-mail du titulaire invalide.';
    if (!saas_stripe_configured()) return "Stripe n'est pas configuré pour la plateforme (Portail → Clés / Stripe).";
    $all = cm_connect();
    $key = $kind . ':' . $slug;
    if (!empty($all[$key]['account'])) return 'Ce compte existe déjà.';
    $caps = $kind === 'shop' ? ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']] : ['transfers' => ['requested' => 'true']];
    try {
        $a = saas_stripe_request('POST', '/accounts', ['type' => 'express', 'country' => 'FR', 'email' => $email, 'capabilities' => $caps, 'metadata' => ['kind' => $kind, 'slug' => $slug]]);
    } catch (Throwable $e) {
        return 'Stripe : ' . $e->getMessage();
    }
    $all[$key] = ['account' => (string) $a['id'], 'status' => 'pending', 'email' => $email, 'created' => time(), 'updated' => time()];
    return cm_json_save('connect.json', $all) ? null : "Impossible d'écrire data/saas/connect.json.";
}

/** Lien d'inscription (KYC) Stripe du titulaire. -> [url, erreur]. */
function cm_account_link(string $key, string $returnUrl): array
{
    $a = cm_connect()[$key] ?? null;
    if (!$a) return [null, 'Compte inconnu.'];
    try {
        $l = saas_stripe_request('POST', '/account_links', ['account' => $a['account'], 'type' => 'account_onboarding', 'refresh_url' => $returnUrl, 'return_url' => $returnUrl]);
    } catch (Throwable $e) {
        return [null, 'Stripe : ' . $e->getMessage()];
    }
    return [(string) $l['url'], null];
}

/** Relit l'état du compte chez Stripe (prêt = informations validées et virements possibles). */
function cm_account_refresh(string $key): ?string
{
    $all = cm_connect();
    if (empty($all[$key]['account'])) return 'Compte inconnu.';
    try {
        $a = saas_stripe_request('GET', '/accounts/' . rawurlencode($all[$key]['account']));
    } catch (Throwable $e) {
        return 'Stripe : ' . $e->getMessage();
    }
    $isShop = str_starts_with($key, 'shop:');
    $ready = !empty($a['details_submitted']) && !empty($a['payouts_enabled']) && (!$isShop || !empty($a['charges_enabled']));
    $all[$key]['status'] = $ready ? 'ready' : 'pending';
    $all[$key]['updated'] = time();
    cm_json_save('connect.json', $all);
    cm_publish_rates();
    return null;
}

// ── Grand livre ─────────────────────────────────────────────────────────────

function cm_lines(): array
{
    $f = cm_path('commissions.jsonl');
    $out = [];
    if (!is_file($f)) return $out;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['id'])) $out[] = $r;
    }
    return $out;
}

function cm_ledger_add(array $rows): int
{
    if (!$rows) return 0;
    $dir = dirname(cm_path('commissions.jsonl'));
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 0;
    $known = array_flip(array_column(cm_lines(), 'id'));
    $n = 0;
    $fh = @fopen(cm_path('commissions.jsonl'), 'ab');
    if (!$fh) return 0;
    flock($fh, LOCK_EX);
    foreach ($rows as $r) {
        if (isset($known[$r['id']])) continue;
        fwrite($fh, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $known[$r['id']] = true;
        $n++;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $n;
}

/** Commandes payées d'un commerce de ce serveur depuis la date de départ : [[id, created_at (ts), amount, shipping, payment_via, fee, gallery_fee, shipping_method]]. */
function cm_shop_orders(string $shop, int $afterId, int $sinceTs): array
{
    if (!is_file(tenant_dir($shop) . '/tenant.php')) return [];
    try {
        $file = tenant_file(tenant_load($shop), 'db_file');
        if (!is_file($file)) return [];
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only = 1');
        $cols = $pdo->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $sel = 'id, strftime(\'%s\', created_at) AS ts, amount_total, shipping_cents, ' . (in_array('payment_via', $cols, true) ? 'payment_via, connect_fee_cents, connect_gallery_cents' : "NULL AS payment_via, NULL AS connect_fee_cents, NULL AS connect_gallery_cents") . ', shipping_method';
        $st = $pdo->prepare("SELECT $sel FROM orders WHERE status = 'paid' AND id > ? AND CAST(strftime('%s', created_at) AS INTEGER) >= ? ORDER BY id");
        $st->execute([$afterId, $sinceTs]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("cm_shop_orders($shop): " . $e->getMessage());
        return [];
    }
}

/** Met le grand livre à jour (ventes des boutiques de ce serveur, paiements d'abonnement). Sans effet avant la date de départ. -> nombre de lignes ajoutées. */
function cm_sync(): int
{
    $c = cm_config();
    if ($c['since'] <= 0) return 0;
    $stateFile = cm_path('commissions-state.json');
    $state = cm_json('commissions-state.json', ['orders' => [], 'events_ts' => 0]);
    $rows = [];
    foreach (array_keys(tenant_list()) as $shop) {
        $last = (int) ($state['orders'][$shop] ?? 0);
        $gallery = cm_shop_gallery($shop, $c);
        foreach (cm_shop_orders($shop, $last, $c['since']) as $o) {
            $base = max(0, (int) $o['amount_total'] - (int) $o['shipping_cents']);
            $state['orders'][$shop] = max((int) ($state['orders'][$shop] ?? 0), (int) $o['id']);
            $connect = ($o['payment_via'] ?? '') === 'connect' && (int) $o['connect_fee_cents'] > 0;
            if ($connect) {
                $galleryCents = min((int) $o['connect_gallery_cents'], (int) $o['connect_fee_cents']);
                $platformCents = (int) $o['connect_fee_cents'] - $galleryCents;
            } else {
                $platformCents = (int) round($base * $c['rates']['sales_platform_pct'] / 100);
                $galleryCents = $gallery !== '' ? (int) round($base * cm_rate($gallery, 'sales_gallery_pct') / 100) : 0;
            }
            if ($platformCents + $galleryCents === 0) continue;
            $rows[] = ['id' => 'o:' . $shop . ':' . $o['id'], 'ts' => (int) $o['ts'], 'kind' => 'sale', 'shop' => $shop, 'gallery' => $gallery, 'ref' => (string) $o['id'], 'base' => $base,
                'platform' => $platformCents, 'gallery_cents' => $galleryCents, 'collected' => $connect];
        }
    }
    $maxTs = (int) $state['events_ts'];
    foreach (saas_events_logged() as $e) {
        if ((int) $e['ts'] <= (int) $state['events_ts'] || (int) $e['ts'] < $c['since']) continue;
        $maxTs = max($maxTs, (int) $e['ts']);
        if (!in_array($e['type'], ['payment_confirmed', 'payment_renewed', 'payment_refunded'], true) || empty($e['ref']) || empty($e['amount'])) continue;
        $req = saas_request_load((string) $e['ref']);
        $gallery = strtolower((string) ($req['gallery'] ?? ''));
        if ($gallery === '' || !gallery_load($gallery)) continue;
        $pct = cm_rate($gallery, 'subs_gallery_pct');
        $cents = (int) round((int) $e['amount'] * $pct / 100) * ($e['type'] === 'payment_refunded' ? -1 : 1);
        if ($cents === 0) continue;
        $rows[] = ['id' => 'e:' . $e['ts'] . ':' . $e['type'] . ':' . $e['ref'], 'ts' => (int) $e['ts'], 'kind' => $e['type'] === 'payment_refunded' ? 'refund' : 'subscription', 'shop' => (string) ($req['slug'] ?? ''),
            'gallery' => $gallery, 'ref' => (string) $e['ref'], 'base' => abs((int) $e['amount']), 'platform' => 0, 'gallery_cents' => $cents, 'collected' => true];
    }
    $state['events_ts'] = $maxTs;
    $n = cm_ledger_add($rows);
    cm_json_save('commissions-state.json', $state);
    return $n;
}

// ── Versements ──────────────────────────────────────────────────────────────

function cm_payouts(): array
{
    return cm_json('commissions-payouts.json', ['payouts' => []])['payouts'] ?? [];
}

function cm_paid_ids(): array
{
    $ids = [];
    foreach (cm_payouts() as $p) foreach ($p['lines'] as $id) $ids[$id] = $p['id'];
    return $ids;
}

/** Synthèse sur [$from, $to] (timestamps) : plateforme, galeries (avec solde dû toutes périodes confondues), commerces. */
function cm_summary(int $from, int $to): array
{
    $paid = cm_paid_ids();
    $platform = ['collected' => 0, 'to_invoice' => 0];
    $galleries = [];
    $shops = [];
    foreach (cm_lines() as $l) {
        $in = $l['ts'] >= $from && $l['ts'] <= $to;
        if ($in) {
            if ($l['kind'] === 'sale') {
                $platform[$l['collected'] ? 'collected' : 'to_invoice'] += $l['platform'];
                $s = $shops[$l['shop']] ?? ['count' => 0, 'base' => 0, 'platform' => 0, 'gallery' => 0, 'collected' => 0];
                $s['count']++; $s['base'] += $l['base']; $s['platform'] += $l['platform']; $s['gallery'] += $l['gallery_cents']; $s['collected'] += $l['collected'] ? 1 : 0;
                $shops[$l['shop']] = $s;
            }
        }
        $g = $l['gallery'];
        if ($g === '') continue;
        $galleries[$g] ??= ['sales' => 0, 'subs' => 0, 'period' => 0, 'due' => 0, 'due_collected' => 0, 'due_tracked' => 0, 'paid' => 0, 'lines' => 0];
        if ($in) { $galleries[$g][$l['kind'] === 'sale' ? 'sales' : 'subs'] += $l['gallery_cents']; $galleries[$g]['period'] += $l['gallery_cents']; $galleries[$g]['lines']++; }
        if (isset($paid[$l['id']])) { $galleries[$g]['paid'] += $l['gallery_cents']; continue; }
        $galleries[$g]['due'] += $l['gallery_cents'];
        $galleries[$g][$l['collected'] ? 'due_collected' : 'due_tracked'] += $l['gallery_cents'];
    }
    ksort($galleries);
    return ['platform' => $platform, 'galleries' => $galleries, 'shops' => $shops];
}

/** Verse à une galerie ses commissions impayées jusqu'à $until (timestamp). $method : stripe (lignes encaissées, compte Connect prêt) ou manual (toutes, référence libre). -> [montant, erreur]. */
function cm_payout(string $gallery, string $method, string $note, int $until): array
{
    $c = cm_config();
    $paid = cm_paid_ids();
    $lines = array_values(array_filter(cm_lines(), static fn ($l) => $l['gallery'] === $gallery && $l['gallery_cents'] !== 0 && $l['ts'] <= $until && !isset($paid[$l['id']])));
    if ($method === 'stripe') $lines = array_values(array_filter($lines, static fn ($l) => $l['collected']));
    $amount = array_sum(array_column($lines, 'gallery_cents'));
    if (!$lines || $amount <= 0) return [0, 'Rien à verser (solde nul ou négatif).'];
    if ($method === 'stripe' && $amount < $c['payout_min_cents']) return [0, 'Solde inférieur au minimum de versement (' . number_format($c['payout_min_cents'] / 100, 2, ',', ' ') . ' €).'];
    $ref = trim($note);
    if ($method === 'stripe') {
        if (!cm_connect_ready('gallery:' . $gallery)) return [0, "Le compte Stripe de cette galerie n'est pas prêt (Comptes Stripe)."];
        $acct = cm_connect()['gallery:' . $gallery]['account'];
        try {
            $t = saas_stripe_request('POST', '/transfers', ['amount' => $amount, 'currency' => 'eur', 'destination' => $acct, 'transfer_group' => 'commission-' . $gallery . '-' . gmdate('Ym'),
                'description' => 'Commissions galerie ' . $gallery, 'metadata' => ['gallery' => $gallery, 'lines' => (string) count($lines)]]);
        } catch (Throwable $e) {
            return [0, 'Stripe : ' . $e->getMessage()];
        }
        $ref = (string) $t['id'];
    } elseif ($ref === '') {
        return [0, 'Indiquez une référence (n° de virement, date…).'];
    }
    $data = cm_json('commissions-payouts.json', ['payouts' => []]);
    $data['payouts'][] = ['id' => 'p' . bin2hex(random_bytes(4)), 'ts' => time(), 'gallery' => $gallery, 'amount' => $amount, 'method' => $method, 'ref' => mb_substr($ref, 0, 120), 'lines' => array_column($lines, 'id')];
    if (!cm_json_save('commissions-payouts.json', $data)) return [0, "Versement effectué mais non enregistré (droits de data/saas) : notez-le : $amount centimes, $ref."];
    saas_event('commission_payout', ['slug' => $gallery, 'amount' => $amount, 'note' => $method . ' ' . $ref]);
    return [$amount, null];
}

/** Relevé CSV d'une période : une ligne par vente ou abonnement (UTF-8 avec BOM, séparateur « ; »). */
function cm_csv(int $from, int $to, string $gallery = ''): string
{
    $paid = cm_paid_ids();
    $names = [];
    $q = static function (string $v): string { if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
    $n = static fn (string $v): string => '"' . $v . '"';                                       // montants : jamais préfixés (un montant négatif reste un nombre)
    $eur = static fn (int $c): string => number_format($c / 100, 2, ',', '');
    $out = "\xEF\xBB\xBF" . implode(';', array_map($q, ['Date', 'Type', 'Commerce', 'Galerie', 'Référence', 'Base (€)', 'Plateforme (€)', 'Galerie (€)', 'Encaissée', 'Versée', 'Versement'])) . "\r\n";
    foreach (cm_lines() as $l) {
        if ($l['ts'] < $from || $l['ts'] > $to || ($gallery !== '' && $l['gallery'] !== $gallery)) continue;
        $out .= implode(';', [$q(date('d/m/Y H:i', $l['ts'])), $q(['sale' => 'Vente', 'subscription' => 'Abonnement', 'refund' => 'Remboursement'][$l['kind']] ?? $l['kind']), $q($l['shop']), $q($l['gallery']), $q($l['ref']),
            $n($eur($l['base'])), $n($eur($l['platform'])), $n($eur($l['gallery_cents'])), $q($l['collected'] ? 'oui' : 'non (à facturer)'), $q(isset($paid[$l['id']]) ? 'oui' : 'non'), $q($paid[$l['id']] ?? '')]) . "\r\n";
    }
    return $out;
}
