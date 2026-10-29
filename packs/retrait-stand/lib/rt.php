<?php
// Retrait sur stand : réservation en ligne, billet QR, encaissement au scan.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

const RT_MODULE = 'retrait-stand';
const RT_MAX_DAYS = 6;             // blocage d'une carte : 7 jours au plus chez Stripe, on garde une marge
const RT_PENDING_MINUTES = 35;
const RT_MAX_PER_IP_HOUR = 4;
const RT_TICK_EVERY = 60;

function rt_defaults(): array
{
    return [
        'enabled' => '1', 'days' => '3', 'place' => '',
        'terms' => "Le montant de « {piece} » ({prix}) est seulement bloqué sur votre carte, pas débité. Présentez le billet QR sur le stand avant le {date} : le paiement est alors encaissé. Passé ce délai, le blocage est levé et aucune somme n'est prélevée.",
    ];
}

function rt_settings(): array
{
    $out = rt_defaults();
    foreach (db()->query('SELECT key, value FROM rt_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    return $out;
}

function rt_settings_save(array $in): ?string
{
    $st = db()->prepare('INSERT INTO rt_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    if (isset($in['days'])) {
        $d = (int) $in['days'];
        if ($d < 1 || $d > RT_MAX_DAYS) return 'La durée doit être de 1 à ' . RT_MAX_DAYS . ' jours (Stripe bloque une carte 7 jours au plus).';
        $st->execute(['days', (string) $d]);
    }
    if (isset($in['enabled'])) $st->execute(['enabled', (string) $in['enabled'] === '1' ? '1' : '0']);
    if (isset($in['place'])) $st->execute(['place', mb_substr(trim((string) $in['place']), 0, 300)]);
    if (isset($in['terms'])) {
        $t = mb_substr(trim((string) $in['terms']), 0, 2000);
        if (mb_strlen($t) < 40) return 'Rédigez des conditions complètes (40 caractères minimum).';
        $st->execute(['terms', $t]);
    }
    return null;
}

function rt_eur(int $c): string
{
    return number_format($c / 100, 2, ',', ' ') . ' €';
}

function rt_mail(string $to, string $subject, string $body): bool
{
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    return password_reset_mail($to, $subject, $body);
}

function rt_ticket_url(array $r): string
{
    return shop_abs_url('/api/retrait/billet/' . $r['token']);
}

function rt_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 20; $i++) {
        $c = '';
        for ($j = 0; $j < 6; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $q = db()->prepare('SELECT 1 FROM rt_pickups WHERE code = ?');
        $q->execute([$c]);
        if (!$q->fetchColumn()) return $c;
    }
    throw new RuntimeException('Code de retrait indisponible.');
}

/** Pièce éligible : prix fixe (pas de fourchette) d'au moins 5 €. -> prix en centimes ou null. */
function rt_price(array $p): ?int
{
    $price = effective_price($p);
    if (!is_fixed_price($price) || preg_match('/\d\s*[-–—]\s*\d/u', (string) $price)) return null;
    $c = price_to_cents($price);
    return $c && $c >= 500 ? $c : null;
}

/** Pièces retenues : paiements en cours et billets valides (ref => quantité). */
function rt_held(): array
{
    $held = [];
    $st = db()->prepare("SELECT product_ref FROM rt_pickups WHERE (status IN ('authorized', 'collecting') AND expires_at > ?) OR (status = 'pending' AND created_at >= datetime('now', ?))");
    $st->execute([time(), '-' . RT_PENDING_MINUTES . ' minutes']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ref) $held[$ref] = ($held[$ref] ?? 0) + 1;
    return $held;
}

/** Démarre un retrait : enregistrement en attente, puis page de paiement Stripe avec blocage de la somme. -> [url, erreur]. */
function rt_start(string $ref, string $name, string $email, string $phone, bool $accept, string $ip): array
{
    $s = rt_settings();
    if ($s['enabled'] !== '1') return [null, 'Le retrait sur stand est suspendu pour le moment.'];
    if (!stripe_configured()) return [null, "Le paiement en ligne n'est pas encore configuré sur cette boutique."];
    $email = mb_strtolower(trim($email));
    $name = mb_substr(trim($name), 0, 80);
    $phone = mb_substr(preg_replace('/[^0-9+ ().-]/', '', $phone), 0, 30);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    if (mb_strlen($name) < 2) return [null, 'Indiquez votre nom.'];
    if (!$accept) return [null, 'Acceptez les conditions de retrait pour continuer.'];
    $hash = hash('sha256', $ip . '|rt|' . tenant_slug());
    db()->prepare('DELETE FROM rt_hits WHERE at < ?')->execute([time() - 3600]);
    $n = db()->prepare('SELECT COUNT(*) FROM rt_hits WHERE ip_hash = ? AND at > ?');
    $n->execute([$hash, time() - 3600]);
    if ((int) $n->fetchColumn() >= RT_MAX_PER_IP_HOUR) return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    db()->prepare('INSERT INTO rt_hits (ip_hash, at) VALUES (?, ?)')->execute([$hash, time()]);

    db()->exec('BEGIN IMMEDIATE');
    try {
        $ps = db()->prepare('SELECT * FROM products WHERE ref = ? AND is_hidden = 0');
        $ps->execute([preg_replace('/[^A-Za-z0-9_-]/', '', $ref)]);
        $p = $ps->fetch(PDO::FETCH_ASSOC);
        $cents = $p ? rt_price($p) : null;
        if (!$p || !$cents || !in_stock($p)) { db()->exec('ROLLBACK'); return [null, 'Cette pièce ne peut pas être retirée sur stand.']; }
        if (product_free_qty($p) < 1) { db()->exec('ROLLBACK'); return [null, 'Cette pièce est déjà réservée ou en cours de paiement par un autre client.']; }
        $expires = time() + (int) $s['days'] * 86400;
        $token = bin2hex(random_bytes(16));
        $terms = strtr($s['terms'], ['{piece}' => $p['name'], '{prix}' => rt_eur($cents), '{date}' => date('d/m/Y', $expires)]);
        db()->prepare("INSERT INTO rt_pickups (product_ref, email, name, phone, token, code, status, amount_cents, terms, expires_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)")
            ->execute([$p['ref'], $email, $name, $phone, $token, rt_code(), $cents, $terms, $expires]);
        $id = (int) db()->lastInsertId();
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    try {
        $meta = ['type' => 'pickup', 'pickup' => $token, 'ref' => $p['ref']];
        $session = stripe_request('POST', '/checkout/sessions', [
            'mode' => 'payment', 'locale' => 'fr', 'customer_email' => $email,
            'payment_intent_data' => ['capture_method' => 'manual', 'metadata' => $meta],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => $cents, 'product_data' => ['name' => mb_substr($p['name'], 0, 100)]]]],
            'metadata' => $meta,
            'success_url' => shop_abs_url('/api/retrait/retour') . '?token=' . $token . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => shop_abs_url('/produit.php?ref=' . rawurlencode($p['ref'])),
            'expires_at' => time() + RT_PENDING_MINUTES * 60,
        ]);
        db()->prepare('UPDATE rt_pickups SET stripe_session_id = ? WHERE id = ?')->execute([$session['id'], $id]);
        return [$session['url'], null];
    } catch (Throwable $e) {
        db()->prepare("UPDATE rt_pickups SET status = 'canceled', note = ? WHERE id = ?")->execute(['Paiement impossible : ' . mb_substr($e->getMessage(), 0, 150), $id]);
        return [null, "Impossible d'ouvrir le paiement pour le moment."];
    }
}

/** La somme est-elle bloquée sur la carte ? -> true si le billet est (ou était déjà) valide. */
function rt_confirm(array $r): bool
{
    if (in_array($r['status'], ['authorized', 'collecting', 'collected'], true)) return true;
    if ($r['status'] !== 'pending' || $r['stripe_session_id'] === '') return false;
    $s = stripe_request('GET', '/checkout/sessions/' . urlencode($r['stripe_session_id']));
    $pi = (string) (is_array($s['payment_intent'] ?? null) ? ($s['payment_intent']['id'] ?? '') : ($s['payment_intent'] ?? ''));
    if ($pi === '' || ($s['metadata']['pickup'] ?? '') !== $r['token']) return false;
    $intent = stripe_request('GET', '/payment_intents/' . urlencode($pi));
    if (!in_array($intent['status'] ?? '', ['requires_capture', 'succeeded'], true)) return false;
    $u = db()->prepare("UPDATE rt_pickups SET status = 'authorized', payment_intent = ?, authorized_at = CURRENT_TIMESTAMP, expires_at = ? WHERE id = ? AND status = 'pending'");
    $settings = rt_settings();
    $expires = time() + (int) $settings['days'] * 86400;
    $u->execute([$pi, $expires, $r['id']]);
    if ($u->rowCount() > 0) {
        $p = db()->prepare('SELECT name FROM products WHERE ref = ?');
        $p->execute([$r['product_ref']]);
        $site = (string) (get_content()['site_name'] ?? tenant('name'));
        rt_mail($r['email'], 'Votre billet de retrait — ' . $site,
            "Bonjour {$r['name']},\n\n« " . $p->fetchColumn() . " » vous est réservée jusqu'au " . date('d/m/Y', $expires) . ".\nPrésentez ce billet sur le stand : " . rt_ticket_url($r) . "\nCode de secours : {$r['code']}\n"
            . ($settings['place'] !== '' ? "\nRetrait : {$settings['place']}\n" : '') . "\n" . $r['terms'] . "\n\n$site\n");
    }
    return true;
}

/** Billet cherché par code court, jeton ou adresse du billet. */
function rt_find(string $q): ?array
{
    $q = trim($q);
    if (preg_match('/([a-f0-9]{32})/', $q, $m)) { $col = 'token'; $val = $m[1]; }
    else { $col = 'code'; $val = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q)); }
    if ($val === '') return null;
    $st = db()->prepare("SELECT r.*, p.name AS product_name FROM rt_pickups r LEFT JOIN products p ON p.ref = r.product_ref WHERE r.$col = ?");
    $st->execute([$val]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Encaisse le billet scanné : somme prélevée, commande créée (reçu, registre, statistiques), pièce vendue. -> message d'erreur ou null. */
function rt_collect(int $id): ?string
{
    $claim = db()->prepare("UPDATE rt_pickups SET status = 'collecting' WHERE id = ? AND status = 'authorized' AND expires_at > ?");
    $claim->execute([$id, time()]);
    if ($claim->rowCount() === 0) return 'Billet introuvable, déjà encaissé ou périmé.';
    $st = db()->prepare('SELECT * FROM rt_pickups WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    try {
        $intent = stripe_request('GET', '/payment_intents/' . urlencode($r['payment_intent']));
        if (($intent['status'] ?? '') === 'requires_capture') stripe_request('POST', '/payment_intents/' . urlencode($r['payment_intent']) . '/capture');
        elseif (($intent['status'] ?? '') !== 'succeeded') throw new RuntimeException('État du paiement inattendu : ' . ($intent['status'] ?? '?'));
    } catch (Throwable $e) {
        db()->prepare("UPDATE rt_pickups SET status = 'authorized' WHERE id = ?")->execute([$id]);
        return "Encaissement refusé par Stripe : " . mb_substr($e->getMessage(), 0, 160);
    }
    // Le paiement est encaissé : même si la suite échoue, le billet ne doit plus pouvoir être utilisé.
    db()->prepare("UPDATE rt_pickups SET status = 'collected', collected_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
    try {
        $p = db()->prepare('SELECT * FROM products WHERE ref = ?');
        $p->execute([$r['product_ref']]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);
        $items = [['ref' => $r['product_ref'], 'name' => (string) ($prod['name'] ?? $r['product_ref']), 'qty' => 1, 'unit_cents' => (int) $r['amount_cents']]];
        db()->prepare("INSERT INTO orders (stripe_session_id, amount_total, status, items) VALUES (?, ?, 'pending', ?)")
            ->execute([$r['stripe_session_id'], (int) $r['amount_cents'], json_encode($items, JSON_UNESCAPED_UNICODE)]);
        $orderId = (int) db()->lastInsertId();
        $order = db()->query('SELECT * FROM orders WHERE id = ' . $orderId)->fetch(PDO::FETCH_ASSOC);
        order_mark_paid($order, ['customer_details' => ['email' => $r['email'], 'name' => $r['name']], 'amount_total' => (int) $r['amount_cents'], 'shipping_cost' => ['amount_total' => 0]]);
        db()->prepare("UPDATE products SET sold_channel = 'stand', sold_at = CURRENT_TIMESTAMP WHERE ref = ? AND stock <= 0")->execute([$r['product_ref']]);
        db()->prepare('UPDATE rt_pickups SET order_id = ? WHERE id = ?')->execute([$orderId, $id]);
    } catch (Throwable $e) {
        error_log('Retrait sur stand : commande non créée pour le billet ' . $id . ' : ' . $e->getMessage());
        db()->prepare("UPDATE rt_pickups SET note = 'Encaissé, mais commande à créer à la main (voir le journal des erreurs)' WHERE id = ?")->execute([$id]);
        return 'Paiement encaissé, mais la commande n\'a pas pu être enregistrée : notez-le et saisissez la vente à la main.';
    }
    return null;
}

/** Annule un billet : le blocage sur la carte est levé, la pièce est libérée. -> message d'erreur ou null. */
function rt_release(int $id, string $status = 'released', string $note = 'Blocage levé'): ?string
{
    $claim = db()->prepare("UPDATE rt_pickups SET status = 'collecting' WHERE id = ? AND status = 'authorized'");
    $claim->execute([$id]);
    if ($claim->rowCount() === 0) return 'Billet introuvable ou déjà clôturé.';
    $st = db()->prepare('SELECT * FROM rt_pickups WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    try {
        stripe_request('POST', '/payment_intents/' . urlencode($r['payment_intent']) . '/cancel');
    } catch (Throwable $e) {
        db()->prepare("UPDATE rt_pickups SET status = 'authorized' WHERE id = ?")->execute([$id]);
        return 'Annulation refusée par Stripe : ' . mb_substr($e->getMessage(), 0, 160);
    }
    db()->prepare('UPDATE rt_pickups SET status = ?, note = ? WHERE id = ?')->execute([$status, $note, $id]);
    return null;
}

/** Fait avancer les retraits : paiements validés, abandonnés, billets échus. */
function rt_tick(bool $force = false): void
{
    if (!addon_is_entitled(RT_MODULE)) return;
    static $last = 0;
    if (!$force && time() - $last < RT_TICK_EVERY) return;
    $last = time();
    foreach (db()->query("SELECT * FROM rt_pickups WHERE status = 'pending' AND created_at <= datetime('now', '-3 minutes')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try {
            if (rt_confirm($r)) continue;
        } catch (Throwable $e) {
            error_log('Retrait sur stand : ' . $e->getMessage());
        }
        if (strtotime($r['created_at'] . ' UTC') < time() - RT_PENDING_MINUTES * 60) {
            db()->prepare("UPDATE rt_pickups SET status = 'canceled', note = 'Paiement non terminé' WHERE id = ? AND status = 'pending'")->execute([$r['id']]);
        }
    }
    $due = db()->prepare("SELECT * FROM rt_pickups WHERE status = 'authorized' AND expires_at <= ?");
    $due->execute([time()]);
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (rt_release((int) $r['id'], 'expired', 'Billet non retiré : blocage levé') === null) {
            $site = (string) (get_content()['site_name'] ?? tenant('name'));
            rt_mail($r['email'], 'Votre réservation est arrivée à échéance — ' . $site, "Bonjour {$r['name']},\n\nLe billet de retrait a expiré le " . date('d/m/Y', (int) $r['expires_at']) . " : la pièce est de nouveau en vente et le blocage sur votre carte est levé. Aucune somme n'a été prélevée.\n\n$site\n");
        }
    }
}
