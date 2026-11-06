<?php
// Espace visiteur & fidélité : membres, points, paliers, récompenses et rubriques de la web app.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

const EV_MODULE = 'espace-visiteur';
const EV_MAX_TIERS = 5;
const EV_MAX_HITS_HOUR = 5;           // demandes de lien par visiteur et par heure
const EV_RESERVE_SECONDS = 3000;      // une récompense réservée à l'ouverture du paiement se libère après ce délai

function ev_defaults(): array
{
    return [
        'enabled' => '1', 'program_name' => 'Fidélité', 'points_per_euro' => '1', 'welcome_points' => '20',
        'tiers' => json_encode([['points' => 100, 'cents' => 500], ['points' => 250, 'cents' => 1500], ['points' => 500, 'cents' => 4000]]),
    ];
}

function ev_settings(): array
{
    $out = ev_defaults();
    foreach (db()->query('SELECT key, value FROM ev_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    return $out;
}

function ev_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO ev_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, $v]);
}

/** Paliers de récompense triés : [['points' => 100, 'cents' => 500], …]. */
function ev_tiers(?array $s = null): array
{
    $s ??= ev_settings();
    $t = json_decode((string) $s['tiers'], true);
    $out = [];
    foreach (is_array($t) ? $t : [] as $x) {
        $p = (int) ($x['points'] ?? 0);
        $c = (int) ($x['cents'] ?? 0);
        if ($p >= 1 && $c >= 100) $out[] = ['points' => $p, 'cents' => $c];
    }
    usort($out, static fn ($a, $b) => $a['points'] <=> $b['points']);
    return array_slice($out, 0, EV_MAX_TIERS);
}

function ev_settings_save(array $in): ?string
{
    if (isset($in['program_name'])) ev_setting_set('program_name', mb_substr(trim((string) $in['program_name']), 0, 40) ?: 'Fidélité');
    if (isset($in['enabled'])) ev_setting_set('enabled', (string) $in['enabled'] === '1' ? '1' : '0');
    if (isset($in['points_per_euro'])) {
        $v = (float) str_replace(',', '.', (string) $in['points_per_euro']);
        if ($v < 0.1 || $v > 100) return 'Les points par euro doivent être compris entre 0,1 et 100.';
        ev_setting_set('points_per_euro', (string) $v);
    }
    if (isset($in['welcome_points'])) {
        $v = (int) $in['welcome_points'];
        if ($v < 0 || $v > 1000) return 'Les points de bienvenue doivent être compris entre 0 et 1000.';
        ev_setting_set('welcome_points', (string) $v);
    }
    if (isset($in['tiers'])) {
        $raw = is_array($in['tiers']) ? $in['tiers'] : (json_decode((string) $in['tiers'], true) ?: []);
        $clean = [];
        foreach (array_slice($raw, 0, EV_MAX_TIERS) as $t) {
            $p = (int) ($t['points'] ?? 0);
            $c = (int) round((float) str_replace(',', '.', (string) ($t['euros'] ?? ($t['cents'] ?? 0) / 100)) * 100);
            if ($p === 0 && $c === 0) continue;
            if ($p < 10 || $p > 100000 || $c < 100 || $c > 50000) return 'Chaque palier : de 10 à 100 000 points pour une remise de 1 à 500 €.';
            $clean[] = ['points' => $p, 'cents' => $c];
        }
        usort($clean, static fn ($a, $b) => $a['points'] <=> $b['points']);
        ev_setting_set('tiers', json_encode($clean));
    }
    return null;
}

function ev_eur(int $c): string
{
    return number_format($c / 100, 2, ',', ' ') . ' €';
}

function ev_app_url(string $token = ''): string
{
    return shop_abs_url('/visiteur.php') . ($token !== '' ? '?t=' . $token : '');
}

function ev_url(string $path): string
{
    return shop_abs_url('/api/visiteur/' . ltrim($path, '/'));
}

function ev_mail(string $to, string $subject, string $body): bool
{
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    return password_reset_mail($to, $subject, $body, 'espace-visiteur');
}

// --- Membres ---

function ev_member_by_token(string $t): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) return null;
    $st = db()->prepare('SELECT * FROM ev_members WHERE token = ?');
    $st->execute([$t]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ev_member_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM ev_members WHERE email = ?');
    $st->execute([mb_strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ev_balance(int $memberId): int
{
    $st = db()->prepare('SELECT COALESCE(SUM(points), 0) FROM ev_ledger WHERE member_id = ?');
    $st->execute([$memberId]);
    return (int) $st->fetchColumn();
}

function ev_ledger_add(int $memberId, string $kind, int $points, ?int $orderId = null, string $note = ''): bool
{
    try {
        db()->prepare('INSERT INTO ev_ledger (member_id, kind, points, order_id, note) VALUES (?, ?, ?, ?, ?)')->execute([$memberId, $kind, $points, $orderId, mb_substr($note, 0, 160)]);
        return true;
    } catch (PDOException $e) {
        return false;     // même commande déjà créditée
    }
}

/**
 * Demande de lien personnel : envoyé à l'adresse indiquée. Un nouveau visiteur doit cocher l'accord (l'inscription n'est créée qu'à ce moment).
 * La réponse est la même, que l'adresse soit connue ou non (on ne révèle pas qui est inscrit). -> [message, erreur].
 */
function ev_request_link(string $email, string $name, bool $consent, string $ip): array
{
    $s = ev_settings();
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    $hash = hash('sha256', $ip . '|ev|' . tenant_slug());
    db()->prepare('DELETE FROM ev_hits WHERE at < ?')->execute([time() - 3600]);
    $n = db()->prepare('SELECT COUNT(*) FROM ev_hits WHERE ip_hash = ? AND at > ?');
    $n->execute([$hash, time() - 3600]);
    if ((int) $n->fetchColumn() >= EV_MAX_HITS_HOUR) return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    db()->prepare('INSERT INTO ev_hits (ip_hash, at) VALUES (?, ?)')->execute([$hash, time()]);

    $member = ev_member_by_email($email);
    if (!$member) {
        if (!$consent) return [null, "Cochez la case pour créer votre espace : c'est votre accord à l'enregistrement de votre adresse."];
        db()->prepare('INSERT INTO ev_members (email, name, token) VALUES (?, ?, ?)')->execute([$email, mb_substr(trim($name), 0, 60), bin2hex(random_bytes(16))]);
        $member = ev_member_by_email($email);
    }
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    ev_mail($email, 'Votre espace visiteur — ' . $site,
        "Bonjour,\n\nVoici votre lien personnel vers votre espace visiteur (" . $s['program_name'] . ", alertes, réservations) :\n" . ev_app_url($member['token']) . "\n\nGardez-le pour vous : il donne accès à vos points. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\n$site\n");
    return ['Si cette adresse peut avoir un espace, un lien personnel vient de lui être envoyé. Ouvrez votre boîte e-mail.', null];
}

/** Premier passage par le lien personnel : adresse vérifiée, les points de bienvenue sont crédités (une seule fois). */
function ev_touch(array $member): void
{
    db()->prepare("UPDATE ev_members SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$member['id']]);
    if ((int) $member['welcomed'] === 0) {
        $w = (int) ev_settings()['welcome_points'];
        if ($w > 0) ev_ledger_add((int) $member['id'], 'welcome', $w, null, 'Bienvenue');
        db()->prepare('UPDATE ev_members SET welcomed = 1 WHERE id = ?')->execute([$member['id']]);
    }
}

/** Efface le membre, son journal et ses récompenses non utilisées (droit à l'effacement). */
function ev_member_delete(int $id): void
{
    foreach (['ev_ledger', 'ev_rewards'] as $t) db()->prepare("DELETE FROM $t WHERE member_id = ?")->execute([$id]);
    db()->prepare('DELETE FROM ev_members WHERE id = ?')->execute([$id]);
}

// --- Points et récompenses ---

function ev_points_for(int $goodsCents, array $s): int
{
    return max(0, (int) floor($goodsCents / 100 * (float) $s['points_per_euro']));
}

/** Commande payée : récompense soldée, points crédités au membre portant cette adresse (s'il existe). */
function ev_order_paid(int $orderId): void
{
    $o = db()->prepare('SELECT id, email, amount_total, shipping_cents FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch(PDO::FETCH_ASSOC);
    if (!$order) return;
    db()->prepare("UPDATE ev_rewards SET status = 'used', used_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status = 'active'")->execute([$orderId]);
    $s = ev_settings();
    if ($s['enabled'] !== '1' || empty($order['email'])) return;
    $member = ev_member_by_email((string) $order['email']);
    if (!$member || (int) $member['welcomed'] === 0) return;      // adresse non vérifiée : pas de points
    $pts = ev_points_for(max(0, (int) $order['amount_total'] - (int) $order['shipping_cents']), $s);
    if ($pts > 0) ev_ledger_add((int) $member['id'], 'earn', $pts, $orderId, 'Achat (commande n° ' . $orderId . ')');
}

function ev_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return 'FID-' . $c;
}

/** Échange des points contre une récompense (code de remise). -> [récompense, erreur]. */
function ev_reward_create(array $member, int $tierIndex): array
{
    $s = ev_settings();
    $tiers = ev_tiers($s);
    if ($s['enabled'] !== '1' || !isset($tiers[$tierIndex])) return [null, "Cette récompense n'existe pas."];
    $t = $tiers[$tierIndex];
    db()->exec('BEGIN IMMEDIATE');
    try {
        if (ev_balance((int) $member['id']) < $t['points']) { db()->exec('ROLLBACK'); return [null, 'Pas assez de points pour cette récompense.']; }
        $code = ev_code();
        db()->prepare('INSERT INTO ev_rewards (member_id, code, cents, points) VALUES (?, ?, ?, ?)')->execute([$member['id'], $code, $t['cents'], $t['points']]);
        ev_ledger_add((int) $member['id'], 'redeem', -$t['points'], null, 'Récompense ' . ev_eur($t['cents']) . ' (' . $code . ')');
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    return [['code' => $code, 'cents' => $t['cents'], 'points' => $t['points']], null];
}

/** Récompense utilisable pour ce code (active, non réservée par une autre commande en cours) ou null. */
function ev_reward_find(string $code): ?array
{
    $st = db()->prepare("SELECT * FROM ev_rewards WHERE code = ? AND status = 'active'");
    $st->execute([strtoupper(trim($code))]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if ((int) $r['order_id'] > 0 && (int) $r['reserved_until'] > time()) {      // réservée par un paiement en cours : seul son propre visiteur la retrouve (retour depuis la page de paiement)
        $own = function_exists('checkout_own_orders') ? checkout_own_orders() : [];
        if (!in_array((int) $r['order_id'], $own, true)) return null;
    }
    return $r;
}

/** Remise (centimes) apportée par la récompense sur un panier : le client règle toujours au moins 1 €. */
function ev_reward_discount(string $code, int $subtotalCents, int $alreadyCents): int
{
    $r = ev_reward_find($code);
    if (!$r) return 0;
    return max(0, min((int) $r['cents'], $subtotalCents - $alreadyCents - 100));
}

function ev_reward_reserve(string $code, int $orderId): void
{
    db()->prepare("UPDATE ev_rewards SET order_id = ?, reserved_until = ? WHERE code = ? AND status = 'active'")->execute([$orderId, time() + EV_RESERVE_SECONDS, strtoupper(trim($code))]);
}

// --- Web app visiteur ---

function ev_table_exists(string $t): bool
{
    $st = db()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $st->execute([$t]);
    return (bool) $st->fetchColumn();
}

/** Rubriques des autres modules disponibles dans cette boutique : actives et sous licence. */
function ev_sections(): array
{
    $on = static function (string $id): bool {
        if (!function_exists('plugin_shop_status') || plugin_shop_status($id) !== 'ready') return false;
        return addon_is_entitled($id);
    };
    return ['alertes' => $on('alerte-chineur'), 'billets' => $on('retrait-stand') || $on('reservation-arrhes'), 'retrait' => $on('retrait-stand'),
        'reservations' => $on('reservation-arrhes'), 'scan' => $on('qr-story')];
}

/** Contenu de l'espace d'un membre : points, paliers, historique, récompenses, billets et réservations. */
function ev_member_view(array $member): array
{
    $s = ev_settings();
    $sec = ev_sections();
    $bal = ev_balance((int) $member['id']);
    $hist = db()->prepare('SELECT kind, points, note, created_at FROM ev_ledger WHERE member_id = ? ORDER BY id DESC LIMIT 25');
    $hist->execute([$member['id']]);
    $rw = db()->prepare("SELECT code, cents, status, created_at FROM ev_rewards WHERE member_id = ? ORDER BY id DESC LIMIT 20");
    $rw->execute([$member['id']]);
    $tiers = [];
    foreach (ev_tiers($s) as $i => $t) $tiers[] = $t + ['index' => $i, 'reachable' => $bal >= $t['points'], 'missing' => max(0, $t['points'] - $bal)];
    $out = ['email' => $member['email'], 'program' => $s['program_name'], 'enabled' => $s['enabled'] === '1', 'points' => $bal, 'tiers' => $tiers,
        'history' => $hist->fetchAll(PDO::FETCH_ASSOC), 'rewards' => $rw->fetchAll(PDO::FETCH_ASSOC), 'sections' => $sec, 'pickups' => [], 'reservations' => []];
    if ($sec['retrait'] && ev_table_exists('rt_pickups')) {
        $st = db()->prepare("SELECT p.token, p.status, p.expires_at, p.amount_cents, pr.name AS product FROM rt_pickups p LEFT JOIN products pr ON pr.ref = p.product_ref WHERE p.email = ? AND p.status IN ('authorized', 'collecting') ORDER BY p.id DESC LIMIT 10");
        $st->execute([$member['email']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $out['pickups'][] = ['product' => (string) $p['product'], 'status' => $p['status'], 'expires_at' => (int) $p['expires_at'], 'ticket' => shop_abs_url('/api/retrait/billet/' . $p['token'])];
    }
    if ($sec['reservations'] && ev_table_exists('ra_reservations')) {
        $st = db()->prepare("SELECT r.status, r.expires_at, r.deposit_cents, r.price_cents, pr.name AS product FROM ra_reservations r LEFT JOIN products pr ON pr.ref = r.product_ref WHERE r.email = ? AND r.status = 'active' ORDER BY r.id DESC LIMIT 10");
        $st->execute([$member['email']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['reservations'][] = ['product' => (string) $r['product'], 'expires_at' => (int) $r['expires_at'], 'deposit' => (int) $r['deposit_cents'], 'price' => (int) $r['price_cents']];
    }
    return $out;
}

// --- Administration ---

function ev_members_list(int $limit = 300): array
{
    $rows = db()->query("SELECT m.id, m.email, m.name, m.created_at, m.last_seen_at, m.welcomed,
        COALESCE((SELECT SUM(points) FROM ev_ledger l WHERE l.member_id = m.id), 0) AS points,
        COALESCE((SELECT SUM(points) FROM ev_ledger l WHERE l.member_id = m.id AND l.kind = 'earn'), 0) AS earned
        FROM ev_members m ORDER BY m.id DESC LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

function ev_adjust(int $memberId, int $points, string $note): ?string
{
    if ($points === 0 || abs($points) > 100000) return 'Indiquez un nombre de points (positif ou négatif).';
    $st = db()->prepare('SELECT id FROM ev_members WHERE id = ?');
    $st->execute([$memberId]);
    if (!$st->fetchColumn()) return 'Membre introuvable.';
    ev_ledger_add($memberId, 'adjust', $points, null, $note !== '' ? $note : 'Ajustement');
    return null;
}

function ev_stats(): array
{
    $q = static fn (string $sql) => (int) db()->query($sql)->fetchColumn();
    return ['members' => $q('SELECT COUNT(*) FROM ev_members'), 'points_out' => $q("SELECT COALESCE(SUM(points), 0) FROM ev_ledger"),
        'rewards_active' => $q("SELECT COUNT(*) FROM ev_rewards WHERE status = 'active'"), 'rewards_used' => $q("SELECT COUNT(*) FROM ev_rewards WHERE status = 'used'"),
        'rewards_cents' => $q("SELECT COALESCE(SUM(cents), 0) FROM ev_rewards WHERE status = 'used'")];
}
