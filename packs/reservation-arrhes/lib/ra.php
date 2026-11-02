<?php
// Réservation avec arrhes : règles métier.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

const RA_MODULE = 'reservation-arrhes';
const RA_MAX_DAYS = 7;
const RA_PENDING_MINUTES = 35;     // une page de paiement non terminée libère la pièce après ce délai
const RA_MAX_PER_IP_HOUR = 4;
const RA_TICK_EVERY = 60;

function ra_defaults(): array
{
    return [
        'mode' => 'arrhes', 'percent' => '20', 'min_cents' => '200', 'days' => '3', 'enabled' => '1',
        'terms' => "Les arrhes versées ({arrhes}, soit {pourcent} % du prix) réservent la pièce « {piece} » jusqu'au {date}. Vous pouvez la retirer et régler le solde jusqu'à cette date. Passé ce délai, la réservation prend fin et les arrhes restent acquises à la boutique ; si la boutique renonce à la vente, elle vous rembourse le double des arrhes (art. L214-1 du Code de la consommation).",
        'terms_acompte' => "L'acompte versé ({acompte}, soit {pourcent} % du prix) réserve la pièce « {piece} » jusqu'au {date}. La vente est conclue : le solde est à régler au plus tard à cette date, et la boutique peut en exiger le paiement. Passé ce délai, la boutique peut mettre fin à la réservation et remettre la pièce en vente ; l'acompte reste alors imputé sur ce qui lui est dû. Le droit de rétractation de 14 jours des ventes à distance s'applique selon la loi.",
    ];
}

/** Vocabulaire selon le type de versement : « arrhes » (chacun peut renoncer) ou « acompte » (la vente est ferme). */
function ra_mode_of($m): string
{
    $mode = is_array($m) ? ($m['mode'] ?? 'arrhes') : (string) $m;
    return $mode === 'acompte' ? 'acompte' : 'arrhes';
}

function ra_word($m, string $form = 'nom'): string
{
    $a = ra_mode_of($m) === 'acompte';
    return match ($form) {
        'Nom' => $a ? 'Acompte' : 'Arrhes',
        'du' => $a ? "de l'acompte" : 'des arrhes',
        'le' => $a ? "l'acompte" : 'les arrhes',
        'verse' => $a ? 'Acompte versé' : 'Arrhes versées',
        default => $a ? 'acompte' : 'arrhes',
    };
}

/** Ajoute la colonne « mode » aux réservations déjà créées (une fois, sans passer par une mise à jour du module). */
function ra_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (db()->query('PRAGMA table_info(ra_reservations)')->fetchAll(PDO::FETCH_ASSOC) as $c) if ($c['name'] === 'mode') return;
    db()->exec("ALTER TABLE ra_reservations ADD COLUMN mode TEXT NOT NULL DEFAULT 'arrhes'");
}

function ra_settings(): array
{
    $out = ra_defaults();
    foreach (db()->query('SELECT key, value FROM ra_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    return $out;
}

function ra_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO ra_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, $v]);
}

function ra_settings_save(array $in): ?string
{
    if (isset($in['mode'])) ra_setting_set('mode', ra_mode_of((string) $in['mode']));
    if (isset($in['percent'])) {
        $p = (float) str_replace(',', '.', (string) $in['percent']);
        if ($p < 5 || $p > 50) return 'Le versement doit représenter entre 5 et 50 % du prix.';
        ra_setting_set('percent', (string) $p);
    }
    if (isset($in['min_cents'])) ra_setting_set('min_cents', (string) max(100, min(100000, (int) round((float) str_replace(',', '.', (string) $in['min_cents']) * 100))));
    if (isset($in['days'])) {
        $d = (int) $in['days'];
        if ($d < 1 || $d > RA_MAX_DAYS) return 'La durée doit être de 1 à ' . RA_MAX_DAYS . ' jours.';
        ra_setting_set('days', (string) $d);
    }
    if (isset($in['enabled'])) ra_setting_set('enabled', (string) $in['enabled'] === '1' ? '1' : '0');
    if (isset($in['terms'])) {
        $t = mb_substr(trim((string) $in['terms']), 0, 2000);
        if (mb_strlen($t) < 40) return 'Rédigez des conditions complètes (40 caractères minimum).';
        ra_setting_set('terms', $t);
    }
    if (isset($in['terms_acompte'])) {
        $t = mb_substr(trim((string) $in['terms_acompte']), 0, 2000);
        if (mb_strlen($t) < 40) return "Rédigez des conditions complètes pour l'acompte (40 caractères minimum).";
        ra_setting_set('terms_acompte', $t);
    }
    return null;
}

function ra_eur(int $c): string
{
    return number_format($c / 100, 2, ',', ' ') . ' €';
}

/** Versement dû (arrhes ou acompte) pour une pièce (centimes) ou null si elle ne se réserve pas (prix non fixe, arrhes trop faibles par rapport au prix). */
function ra_deposit(array $p, array $s): ?array
{
    $price = effective_price($p);
    if (!is_fixed_price($price) || preg_match('/\d\s*[-–—]\s*\d/u', (string) $price)) return null;
    $cents = price_to_cents($price);
    if (!$cents || $cents < 500) return null;
    $deposit = max((int) $s['min_cents'], (int) round($cents * (float) $s['percent'] / 100));
    return $deposit >= $cents ? null : ['price' => $cents, 'deposit' => $deposit];
}

function ra_terms_text(array $s, array $p, int $deposit, int $expires): string
{
    $tpl = ra_mode_of($s) === 'acompte' ? $s['terms_acompte'] : $s['terms'];
    return strtr($tpl, ['{arrhes}' => ra_eur($deposit), '{acompte}' => ra_eur($deposit), '{pourcent}' => rtrim(rtrim(number_format((float) $s['percent'], 1, ',', ''), '0'), ','), '{piece}' => $p['name'], '{date}' => date('d/m/Y', $expires)]);
}

/** Pièces retenues par les réservations (ref => quantité) : actives non échues, et paiements en cours. */
function ra_held(): array
{
    $held = [];
    $st = db()->prepare("SELECT product_ref FROM ra_reservations WHERE (status = 'active' AND expires_at > ?) OR (status = 'pending' AND created_at >= datetime('now', ?))");
    $st->execute([time(), '-' . RA_PENDING_MINUTES . ' minutes']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ref) $held[$ref] = ($held[$ref] ?? 0) + 1;
    return $held;
}

function ra_mail(string $to, string $subject, string $body): bool
{
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    return password_reset_mail($to, $subject, $body, 'reservation');
}

/** Démarre une réservation : crée l'enregistrement en attente puis la page de paiement Stripe. -> [url, erreur]. */
function ra_start(string $ref, string $name, string $email, string $phone, bool $accept, string $ip): array
{
    ra_schema();
    $s = ra_settings();
    $mode = ra_mode_of($s);
    if ($s['enabled'] !== '1') return [null, 'Les réservations sont suspendues pour le moment.'];
    if (!stripe_configured()) return [null, "Le paiement en ligne n'est pas encore configuré sur cette boutique."];
    $email = mb_strtolower(trim($email));
    $name = mb_substr(trim($name), 0, 80);
    $phone = mb_substr(preg_replace('/[^0-9+ ().-]/', '', $phone), 0, 30);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    if (mb_strlen($name) < 2) return [null, 'Indiquez votre nom.'];
    if (!$accept) return [null, 'Acceptez les conditions de réservation pour continuer.'];
    $hash = hash('sha256', $ip . '|ra|' . tenant_slug());
    db()->prepare('DELETE FROM ra_hits WHERE at < ?')->execute([time() - 3600]);
    $n = db()->prepare('SELECT COUNT(*) FROM ra_hits WHERE ip_hash = ? AND at > ?');
    $n->execute([$hash, time() - 3600]);
    if ((int) $n->fetchColumn() >= RA_MAX_PER_IP_HOUR) return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    db()->prepare('INSERT INTO ra_hits (ip_hash, at) VALUES (?, ?)')->execute([$hash, time()]);

    db()->exec('BEGIN IMMEDIATE');
    try {
        $ps = db()->prepare('SELECT * FROM products WHERE ref = ? AND is_hidden = 0');
        $ps->execute([preg_replace('/[^A-Za-z0-9_-]/', '', $ref)]);
        $p = $ps->fetch(PDO::FETCH_ASSOC);
        $dep = $p ? ra_deposit($p, $s) : null;
        if (!$p || !$dep) { db()->exec('ROLLBACK'); return [null, "Cette pièce ne peut pas être réservée."]; }
        if (product_free_qty($p) < 1) { db()->exec('ROLLBACK'); return [null, 'Cette pièce est déjà réservée ou en cours de paiement par un autre client.']; }
        $expires = time() + (int) $s['days'] * 86400;
        $token = bin2hex(random_bytes(16));
        $terms = ra_terms_text($s, $p, $dep['deposit'], $expires);
        db()->prepare("INSERT INTO ra_reservations (product_ref, email, name, phone, token, status, price_cents, deposit_cents, days, terms, terms_accepted_at, expires_at, mode)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)")->execute([$p['ref'], $email, $name, $phone, $token, $dep['price'], $dep['deposit'], (int) $s['days'], $terms, $expires, $mode]);
        $id = (int) db()->lastInsertId();
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    try {
        $session = stripe_request('POST', '/checkout/sessions', [
            'mode' => 'payment', 'locale' => 'fr', 'customer_email' => $email,
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $dep['deposit'],
                'product_data' => ['name' => ra_word($mode, 'Nom') . ' — ' . mb_substr($p['name'], 0, 80), 'description' => 'Réservation jusqu\'au ' . date('d/m/Y', $expires)]]]],
            'metadata' => ['type' => 'reservation', 'reservation' => $token, 'ref' => $p['ref'], 'mode' => $mode],
            'success_url' => shop_abs_url('/api/reserver/retour') . '?token=' . $token . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => shop_abs_url('/produit.php?ref=' . rawurlencode($p['ref'])),
            'expires_at' => time() + RA_PENDING_MINUTES * 60,
        ]);
        db()->prepare('UPDATE ra_reservations SET stripe_session_id = ? WHERE id = ?')->execute([$session['id'], $id]);
        return [$session['url'], null];
    } catch (Throwable $e) {
        db()->prepare("UPDATE ra_reservations SET status = 'canceled', note = ? WHERE id = ?")->execute(['Paiement impossible : ' . mb_substr($e->getMessage(), 0, 150), $id]);
        return [null, "Impossible d'ouvrir le paiement pour le moment."];
    }
}

/** Active une réservation dont la page de paiement Stripe est payée. -> true si elle est (ou était déjà) active. */
function ra_confirm(array $r): bool
{
    if ($r['status'] === 'active') return true;
    if ($r['status'] !== 'pending' || $r['stripe_session_id'] === '') return false;
    $s = stripe_request('GET', '/checkout/sessions/' . urlencode($r['stripe_session_id']));
    if (($s['payment_status'] ?? '') !== 'paid' || ($s['metadata']['reservation'] ?? '') !== $r['token']) return false;
    $upd = db()->prepare("UPDATE ra_reservations SET status = 'active', paid_at = CURRENT_TIMESTAMP, payment_intent = ?, expires_at = ? WHERE id = ? AND status = 'pending'");
    $expires = time() + (int) $r['days'] * 86400;
    $upd->execute([(string) ($s['payment_intent'] ?? ''), $expires, $r['id']]);
    if ($upd->rowCount() > 0) {
        $p = db()->prepare('SELECT name FROM products WHERE ref = ?');
        $p->execute([$r['product_ref']]);
        $site = (string) (get_content()['site_name'] ?? tenant('name'));
        ra_mail($r['email'], 'Réservation confirmée — ' . $site,
            "Bonjour {$r['name']},\n\nVotre réservation est confirmée : « " . $p->fetchColumn() . " » vous est réservée jusqu'au " . date('d/m/Y', $expires) . ".\n" . ra_word($r, 'verse') . " : " . ra_eur((int) $r['deposit_cents']) . " — solde à régler : " . ra_eur((int) $r['price_cents'] - (int) $r['deposit_cents']) . ".\n\nConditions acceptées :\n" . $r['terms'] . "\n\n$site\n");
    }
    return true;
}

/** Fait avancer les réservations : paiements validés, paiements abandonnés, réservations échues. */
function ra_tick(bool $force = false): void
{
    if (!addon_is_entitled(RA_MODULE)) return;
    static $last = 0;
    if (!$force && time() - $last < RA_TICK_EVERY) return;
    $last = time();
    foreach (db()->query("SELECT * FROM ra_reservations WHERE status = 'pending' AND created_at <= datetime('now', '-3 minutes')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try {
            if (ra_confirm($r)) continue;            // payé alors que le client n'est pas revenu sur le site
        } catch (Throwable $e) {
            error_log('Réservations : ' . $e->getMessage());
        }
        if (strtotime($r['created_at'] . ' UTC') < time() - RA_PENDING_MINUTES * 60) {
            db()->prepare("UPDATE ra_reservations SET status = 'canceled', note = 'Paiement non terminé' WHERE id = ? AND status = 'pending'")->execute([$r['id']]);
        }
    }
    $due = db()->prepare("SELECT * FROM ra_reservations WHERE status = 'active' AND expires_at <= ?");
    $due->execute([time()]);
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = db()->prepare("UPDATE ra_reservations SET status = 'expired' WHERE id = ? AND status = 'active'");
        $u->execute([$r['id']]);
        if ($u->rowCount() > 0) {
            $site = (string) (get_content()['site_name'] ?? tenant('name'));
            ra_mail($r['email'], 'Votre réservation est arrivée à échéance — ' . $site, "Bonjour {$r['name']},\n\nVotre réservation a pris fin le " . date('d/m/Y', (int) $r['expires_at']) . " : la pièce est de nouveau en vente. Conformément aux conditions acceptées, " . (ra_mode_of($r) === 'acompte' ? "l'acompte reste acquis à la boutique et le solde lui reste dû." : 'les arrhes restent acquises à la boutique.') . "\n\n$site\n");
        }
    }
}

/** Le solde est réglé (en boutique ou autrement) : la pièce est vendue. -> message d'erreur ou null. */
function ra_honor(int $id): ?string
{
    $st = db()->prepare("SELECT * FROM ra_reservations WHERE id = ? AND status IN ('active', 'expired')");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'Réservation introuvable ou déjà clôturée.';
    $stock = db()->prepare('SELECT stock FROM products WHERE ref = ?');
    $stock->execute([$r['product_ref']]);
    if ((int) $stock->fetchColumn() < 1) return 'La pièce n\'est plus en stock (déjà vendue ?).';
    db()->exec('BEGIN IMMEDIATE');
    try {
        decrement_stock_for_order([['ref' => $r['product_ref'], 'qty' => 1]]);
        db()->prepare("UPDATE products SET sold_channel = 'reservation', sold_at = CURRENT_TIMESTAMP WHERE ref = ? AND stock <= 0")->execute([$r['product_ref']]);
        db()->prepare("UPDATE ra_reservations SET status = 'honored', note = 'Solde réglé : pièce vendue' WHERE id = ?")->execute([$id]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    return null;
}

/** Abandon : la pièce est libérée, le versement (arrhes ou acompte) reste acquis. */
function ra_forfeit(int $id): ?string
{
    ra_schema();
    $m = db()->prepare("SELECT mode FROM ra_reservations WHERE id = ?");
    $m->execute([$id]);
    $note = ra_mode_of((string) $m->fetchColumn()) === 'acompte' ? 'Client absent : acompte conservé, solde dû' : 'Client absent : arrhes conservées';
    $u = db()->prepare("UPDATE ra_reservations SET status = 'forfeited', note = ? WHERE id = ? AND status IN ('active', 'expired')");
    $u->execute([$note, $id]);
    return $u->rowCount() ? null : 'Réservation introuvable ou déjà clôturée.';
}

/** Remboursement du versement (Stripe) : la pièce est libérée. -> message d'erreur ou null. */
function ra_refund(int $id): ?string
{
    $st = db()->prepare("SELECT * FROM ra_reservations WHERE id = ? AND status IN ('active', 'expired', 'forfeited')");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'Réservation introuvable ou non remboursable.';
    if ($r['payment_intent'] === '') return 'Paiement Stripe introuvable : remboursez depuis le tableau de bord Stripe.';
    try {
        stripe_request('POST', '/refunds', ['payment_intent' => $r['payment_intent']]);
    } catch (Throwable $e) {
        return 'Remboursement refusé par Stripe : ' . mb_substr($e->getMessage(), 0, 160);
    }
    db()->prepare("UPDATE ra_reservations SET status = 'refunded', note = ? WHERE id = ?")->execute([ra_word($r, 'Nom') . ' remboursé' . (ra_mode_of($r) === 'acompte' ? '' : 'es'), $id]);
    return null;
}
