<?php
/**
 * Caisse de la buvette : encaisser sur un téléphone, article par article, pendant l'événement actif.
 *
 * Une vente (bv_sales) garde le nom et le prix du jour de chaque ligne (bv_sale_lines) : changer la carte
 * ensuite ne modifie pas les ventes passées. Une vente PAYÉE alimente « quantités vendues » du bilan
 * (bv_event_items.sold_qty) ; une annulation les retire. Une vente annulée reste visible (jamais supprimée).
 *
 * Trois façons de payer (colonne method) :
 *   cash   — espèces, avec rendu de monnaie ;
 *   card   — carte sur le terminal de l'association : la caisse note seulement « carte », elle ne débite rien ;
 *   stripe — QR de paiement Stripe affiché sur le téléphone du barman : la vente reste « en attente » (status = pending)
 *            tant que Stripe n'a pas confirmé, puis passe à « payée ». L'argent va au compte Stripe Connect de la galerie
 *            (paiement par la plateforme, versé à la galerie : voir bv_card_account()).
 *
 * Les prix viennent toujours de la carte (jamais du téléphone). Montants en centimes.
 */

require_once __DIR__ . '/buvette.php';

const BV_SALE_MAX_LINES = 40;
const BV_SALE_MAX_QTY = 99;
const BV_SALE_VOID_MINUTES = 10;     // un bénévole peut annuler sa vente pendant ce délai ; le responsable, à tout moment
const BV_STRIPE_MIN_CENTS = 50;      // Stripe refuse un paiement par carte inférieur à 0,50 €
const BV_STRIPE_HOLD_MINUTES = 30;   // durée de vie d'un QR de paiement (minimum accepté par Stripe)

/* ====================================================================== */
/* Panier -> lignes                                                       */
/* ====================================================================== */

/** Valide un panier [['id', 'qty'], …] contre la carte. -> [lignes, total, erreur]. Les prix sont ceux de la carte. */
function bv_sale_lines_from(array $items): array
{
    $want = [];
    foreach ($items as $it) {
        $id = (int) ($it['id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($id <= 0 || $qty <= 0) continue;
        $want[$id] = ($want[$id] ?? 0) + $qty;
    }
    if (!$want) return [[], 0, 'Le panier est vide.'];
    if (count($want) > BV_SALE_MAX_LINES) return [[], 0, 'Trop d\'articles dans le panier.'];
    $lines = [];
    $total = 0;
    foreach ($want as $id => $qty) {
        $item = bv_item_get($id);
        if (!$item || !$item['is_active']) return [[], 0, 'Un article n\'est plus à la carte : actualisez la page.'];
        if ($qty > BV_SALE_MAX_QTY) return [[], 0, 'Quantité trop grande (' . BV_SALE_MAX_QTY . ' au plus par article).'];
        $lines[] = ['item_id' => $id, 'name' => (string) $item['name'], 'qty' => $qty, 'unit_cents' => (int) $item['price_cents']];
        $total += $qty * (int) $item['price_cents'];
    }
    return [$lines, $total, null];
}

/** Heure d'une opération saisie hors ligne (secondes, envoyée par le téléphone) : acceptée si elle est récente et pas dans le futur, sinon l'heure du serveur. */
function bv_client_ts($at): ?int
{
    $at = (int) $at;
    return ($at > time() - 3 * 86400 && $at <= time() + 60) ? $at : null;
}

/** Insère la vente et ses lignes ; si elle est payée, met à jour le bilan. -> id de la vente. */
function bv_sale_insert(int $eventId, array $lines, int $total, string $method, string $status, ?int $givenCents, string $by, string $sessionId = '', string $uid = '', ?int $ts = null, string $note = ''): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO bv_sales (event_id, method, status, stripe_session_id, total_cents, given_cents, by_name, client_uid, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $method, $status, $sessionId, $total, $givenCents, mb_substr(trim($by), 0, 60), $uid, $note, date('Y-m-d H:i:s', $ts ?? time())]);
        $saleId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO bv_sale_lines (sale_id, item_id, name, qty, unit_cents) VALUES (?, ?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$saleId, $l['item_id'], $l['name'], $l['qty'], $l['unit_cents']]);
            if ($status === 'paid') bv_sold_add($eventId, $l['item_id'], $l['qty']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $saleId;
}

/**
 * Enregistre une vente payée tout de suite (espèces, ou carte sur le terminal). $items : [['id' => article, 'qty' => n], …].
 * Retourne [vente|null, erreur|null] ; la vente : id, total_cents, given_cents, change_cents, method.
 */
function bv_sale_create(int $eventId, array $items, string $method, ?int $givenCents, string $by = '', array $opts = []): array
{
    // $opts : uid (identifiant posé par le téléphone), at (heure de la saisie), offline (saisie hors ligne), client_total (total vu par le téléphone).
    $uid = mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['uid'] ?? '')) ?? '', 0, 64);
    if ($uid !== '') {   // envoi déjà reçu (synchronisation répétée) : on renvoie la vente existante, sans en créer une seconde
        $dup = db()->prepare('SELECT * FROM bv_sales WHERE client_uid = ? LIMIT 1');
        $dup->execute([$uid]);
        if ($row = $dup->fetch()) {
            return [['id' => (int) $row['id'], 'method' => $row['method'], 'total_cents' => (int) $row['total_cents'], 'given_cents' => $row['given_cents'] === null ? null : (int) $row['given_cents'],
                'change_cents' => $row['given_cents'] === null ? null : (int) $row['given_cents'] - (int) $row['total_cents'], 'duplicate' => true], null];
        }
    }
    if (!bv_event_get($eventId)) return [null, 'Aucun événement actif : le responsable doit en activer un.'];
    if (!in_array($method, ['cash', 'card'], true)) return [null, 'Mode de paiement inconnu.'];
    [$lines, $total, $err] = bv_sale_lines_from($items);
    if ($err !== null) return [null, $err];
    if ($method === 'cash' && $givenCents !== null && $givenCents < $total) {
        return [null, 'La somme remise (' . bv_money($givenCents) . ') est inférieure au total (' . bv_money($total) . ').'];
    }
    if ($method === 'card') $givenCents = null;
    $note = !empty($opts['offline']) ? 'saisi hors ligne' : '';
    if (isset($opts['client_total']) && (int) $opts['client_total'] !== $total) {
        $note = trim($note . ' · écart de prix : ' . bv_money((int) $opts['client_total']) . ' sur le téléphone, ' . bv_money($total) . ' à la carte', ' ·');
    }
    try {
        $saleId = bv_sale_insert($eventId, $lines, $total, $method, 'paid', $givenCents, $by, '', $uid, bv_client_ts($opts['at'] ?? 0), $note);
    } catch (Throwable $e) {
        return [null, 'Enregistrement impossible : réessayez.'];
    }
    return [[
        'id' => $saleId, 'method' => $method, 'total_cents' => $total, 'given_cents' => $givenCents,
        'change_cents' => $givenCents !== null ? $givenCents - $total : null, 'note' => $note,
    ], null];
}

/** Ajoute (ou retire, si négatif) des portions vendues au bilan de l'événement. */
function bv_sold_add(int $eventId, int $itemId, int $delta): void
{
    db()->prepare('INSERT INTO bv_event_items (event_id, item_id, forecast_qty, sold_qty) VALUES (?, ?, 0, MAX(0, ?))
                   ON CONFLICT(event_id, item_id) DO UPDATE SET sold_qty = MAX(0, COALESCE(sold_qty, 0) + ?)')
        ->execute([$eventId, $itemId, $delta, $delta]);
}

/** Annule une vente payée. Retourne null si c'est fait, sinon le message d'erreur. */
function bv_sale_void(int $saleId, bool $isStaff): ?string
{
    $sale = bv_sale_get($saleId);
    if (!$sale) return 'Vente introuvable.';
    if ($sale['voided_at'] !== null || $sale['status'] === 'canceled') return 'Cette vente est déjà annulée.';
    if ($sale['status'] === 'pending') return 'Ce paiement est en attente : annulez-le depuis l\'écran de paiement.';
    if ($sale['method'] === 'stripe' && !$isStaff) return 'Une vente payée par QR a été encaissée par Stripe : demandez au responsable (remboursement).';
    if ($sale['method'] === 'stripe') {       // le client est remboursé par Stripe (l'argent est repris à la galerie)
        try {
            $session = bv_stripe('GET', '/checkout/sessions/' . rawurlencode((string) $sale['stripe_session_id']));
            if (empty($session['payment_intent'])) throw new RuntimeException('Paiement introuvable.');
            bv_stripe('POST', '/refunds', ['payment_intent' => (string) $session['payment_intent'], 'reverse_transfer' => 'true']);
        } catch (Throwable $e) {
            error_log('Caisse buvette, remboursement : ' . $e->getMessage());
            return 'Remboursement Stripe impossible : ' . mb_substr($e->getMessage(), 0, 120);
        }
    }
    if (!$isStaff && time() - (int) strtotime((string) $sale['created_at']) > BV_SALE_VOID_MINUTES * 60) {
        return 'Trop tard pour annuler (' . BV_SALE_VOID_MINUTES . ' minutes) : demandez au responsable.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE bv_sales SET voided_at = ? WHERE id = ? AND voided_at IS NULL')->execute([date('Y-m-d H:i:s'), $saleId]);
        $lines = $pdo->prepare('SELECT item_id, qty FROM bv_sale_lines WHERE sale_id = ?');
        $lines->execute([$saleId]);
        foreach ($lines->fetchAll() as $l) {
            if ($l['item_id'] !== null) bv_sold_add((int) $sale['event_id'], (int) $l['item_id'], -(int) $l['qty']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Annulation impossible : réessayez.';
    }
    return null;
}

function bv_sale_get(int $saleId): ?array
{
    $stmt = db()->prepare('SELECT * FROM bv_sales WHERE id = ?');
    $stmt->execute([$saleId]);
    return $stmt->fetch() ?: null;
}

/* ====================================================================== */
/* Paiement par QR Stripe                                                 */
/* ====================================================================== */

/**
 * Compte Stripe Connect (acct_…) de la galerie, s'il est prêt (Portail → Commissions → Comptes Stripe), sinon null :
 * sans lui, pas de QR de paiement, la carte reste celle du terminal.
 */
function bv_card_account(): ?string
{
    if (isset($GLOBALS['bv_card_account_stub'])) return $GLOBALS['bv_card_account_stub'] ?: null;   // tests
    $ctx = bv_gallery_context();
    if ($ctx === null) return null;
    try {
        require_once __DIR__ . '/commissions.php';
        $key = 'gallery:' . $ctx['slug'];
        if (!saas_stripe_configured() || !cm_connect_ready($key)) return null;
        return (string) (cm_connect()[$key]['account'] ?? '') ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Appel à l'API Stripe de la plateforme (remplaçable par un double dans les tests). */
function bv_stripe(string $method, string $path, array $params = []): array
{
    if (isset($GLOBALS['bv_stripe_stub']) && is_callable($GLOBALS['bv_stripe_stub'])) return ($GLOBALS['bv_stripe_stub'])($method, $path, $params);
    require_once __DIR__ . '/saas.php';
    return saas_stripe_request($method, $path, $params);
}

/**
 * Démarre un paiement par QR : crée la vente « en attente » et la page de paiement Stripe.
 * -> [vente|null, erreur|null] ; la vente : id, total_cents, url (à afficher en QR), method, status.
 */
function bv_sale_start_card(int $eventId, array $items, string $by, string $returnBase): array
{
    if (!bv_event_get($eventId)) return [null, 'Aucun événement actif : le responsable doit en activer un.'];
    $account = bv_card_account();
    if ($account === null) return [null, 'Le paiement par QR n\'est pas disponible : le compte Stripe de la galerie n\'est pas prêt.'];
    [$lines, $total, $err] = bv_sale_lines_from($items);
    if ($err !== null) return [null, $err];
    if ($total < BV_STRIPE_MIN_CENTS) return [null, 'Stripe n\'accepte pas un paiement par carte inférieur à 0,50 €.'];
    $ctx = bv_gallery_context();
    try {
        $saleId = bv_sale_insert($eventId, $lines, $total, 'stripe', 'pending', null, $by);
    } catch (Throwable $e) {
        return [null, 'Enregistrement impossible : réessayez.'];
    }
    $params = [
        'mode' => 'payment', 'locale' => 'fr',
        'success_url' => $returnBase . 'merci.php', 'cancel_url' => $returnBase . 'merci.php?annule=1',
        'expires_at' => time() + BV_STRIPE_HOLD_MINUTES * 60,
        'metadata' => ['type' => 'buvette_sale', 'gallery' => (string) ($ctx['slug'] ?? ''), 'sale' => (string) $saleId],
        'payment_intent_data' => ['transfer_data' => ['destination' => $account], 'metadata' => ['type' => 'buvette_sale', 'sale' => (string) $saleId]],
    ];
    foreach ($lines as $i => $l) {
        $params['line_items'][$i] = ['quantity' => $l['qty'], 'price_data' => ['currency' => 'eur', 'unit_amount' => $l['unit_cents'], 'product_data' => ['name' => mb_substr($l['name'], 0, 100)]]];
    }
    try {
        $session = bv_stripe('POST', '/checkout/sessions', $params);
        if (empty($session['id']) || empty($session['url'])) throw new RuntimeException('Réponse Stripe incomplète.');
    } catch (Throwable $e) {
        db()->prepare("UPDATE bv_sales SET status = 'canceled' WHERE id = ?")->execute([$saleId]);
        error_log('Caisse buvette : ' . $e->getMessage());
        return [null, 'Stripe est injoignable : réessayez, ou encaissez au terminal.'];
    }
    db()->prepare('UPDATE bv_sales SET stripe_session_id = ? WHERE id = ?')->execute([(string) $session['id'], $saleId]);
    return [['id' => $saleId, 'method' => 'stripe', 'status' => 'pending', 'total_cents' => $total, 'url' => (string) $session['url']], null];
}

/** Passe une vente en attente à « payée » (une seule fois) et met à jour le bilan. */
function bv_sale_mark_paid(int $saleId): bool
{
    $sale = bv_sale_get($saleId);
    if (!$sale || $sale['status'] !== 'pending') return false;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE bv_sales SET status = 'paid' WHERE id = ? AND status = 'pending'");
        $upd->execute([$saleId]);
        if ($upd->rowCount() !== 1) { $pdo->rollBack(); return false; }
        $lines = $pdo->prepare('SELECT item_id, qty FROM bv_sale_lines WHERE sale_id = ?');
        $lines->execute([$saleId]);
        foreach ($lines->fetchAll() as $l) {
            if ($l['item_id'] !== null) bv_sold_add((int) $sale['event_id'], (int) $l['item_id'], (int) $l['qty']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
    return true;
}

/**
 * Interroge Stripe sur une vente par QR et la met à jour : 'paid', 'pending', 'canceled' (expirée ou annulée) ou 'error'.
 * $cancel : l'annuler (le barman renonce) ; si le client a payé entre-temps, la vente reste payée.
 */
function bv_sale_card_status(int $saleId, bool $cancel = false): string
{
    $sale = bv_sale_get($saleId);
    if (!$sale || $sale['method'] !== 'stripe') return 'error';
    if ($sale['status'] === 'paid') return 'paid';
    if ($sale['status'] === 'canceled') return 'canceled';
    $sid = (string) $sale['stripe_session_id'];
    if ($sid === '') return 'error';
    if ($cancel) {
        try { bv_stripe('POST', '/checkout/sessions/' . rawurlencode($sid) . '/expire'); } catch (Throwable $e) { /* déjà payée ou expirée */ }
    }
    try {
        $session = bv_stripe('GET', '/checkout/sessions/' . rawurlencode($sid));
    } catch (Throwable $e) {
        return 'error';
    }
    if (($session['payment_status'] ?? '') === 'paid') {
        bv_sale_mark_paid($saleId);
        return 'paid';
    }
    if ($cancel || ($session['status'] ?? '') === 'expired') {
        db()->prepare("UPDATE bv_sales SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$saleId]);
        return 'canceled';
    }
    return 'pending';
}

/** Vérifie auprès de Stripe les paiements par QR encore en attente (client parti sans que le téléphone du barman ait suivi). */
function bv_sales_reconcile(int $eventId): void
{
    $stmt = db()->prepare("SELECT id FROM bv_sales WHERE event_id = ? AND status = 'pending' AND method = 'stripe' ORDER BY id DESC LIMIT 10");
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) bv_sale_card_status((int) $id);
}

/* ====================================================================== */
/* Lecture                                                                */
/* ====================================================================== */

/** Ventes d'un événement, la plus récente d'abord, chacune avec ses lignes. */
function bv_sales_list(int $eventId, int $limit = 50): array
{
    $stmt = db()->prepare('SELECT * FROM bv_sales WHERE event_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $stmt->execute([$eventId]);
    $sales = $stmt->fetchAll();
    $lineStmt = db()->prepare('SELECT name, qty, unit_cents FROM bv_sale_lines WHERE sale_id = ? ORDER BY id');
    foreach ($sales as &$s) {
        $lineStmt->execute([(int) $s['id']]);
        $s['lines'] = $lineStmt->fetchAll();
    }
    unset($s);
    return $sales;
}

/** Bilan des ventes payées et non annulées d'un événement : nombre, total, espèces, carte (terminal + QR), détail par article. */
function bv_sales_summary(int $eventId): array
{
    $ok = "event_id = ? AND status = 'paid' AND voided_at IS NULL";
    $stmt = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_cents), 0) total,
            COALESCE(SUM(CASE WHEN method = 'cash' THEN total_cents END), 0) cash,
            COALESCE(SUM(CASE WHEN method = 'card' THEN total_cents END), 0) card,
            COALESCE(SUM(CASE WHEN method = 'stripe' THEN total_cents END), 0) stripe
        FROM bv_sales WHERE $ok");
    $stmt->execute([$eventId]);
    $r = $stmt->fetch();
    $by = db()->prepare('SELECT l.name, SUM(l.qty) qty, SUM(l.qty * l.unit_cents) cents
        FROM bv_sale_lines l JOIN bv_sales s ON s.id = l.sale_id
        WHERE s.event_id = ? AND s.status = \'paid\' AND s.voided_at IS NULL GROUP BY l.name ORDER BY cents DESC, l.name');
    $by->execute([$eventId]);
    $extra = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN voided_at IS NOT NULL THEN 1 END), 0) voided,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 END), 0) pending
        FROM bv_sales WHERE event_id = ?");
    $extra->execute([$eventId]);
    $e = $extra->fetch();
    return [
        'count' => (int) $r['n'], 'total_cents' => (int) $r['total'], 'cash_cents' => (int) $r['cash'],
        'card_cents' => (int) $r['card'] + (int) $r['stripe'], 'terminal_cents' => (int) $r['card'], 'stripe_cents' => (int) $r['stripe'],
        'voided' => (int) $e['voided'], 'pending' => (int) $e['pending'],
        'items' => array_map(static fn (array $x): array => ['name' => $x['name'], 'qty' => (int) $x['qty'], 'cents' => (int) $x['cents']], $by->fetchAll()),
    ];
}

/** Libellé court d'une vente (« 2 × Café, 1 × Crêpe »), pour les listes. */
function bv_sale_label(array $sale): string
{
    return implode(', ', array_map(static fn (array $l): string => (int) $l['qty'] . ' × ' . $l['name'], $sale['lines'] ?? []));
}

/** Libellé du mode de paiement. */
function bv_method_label(string $method): string
{
    return ['cash' => 'Espèces', 'card' => 'Carte (terminal)', 'stripe' => 'Carte (QR)'][$method] ?? $method;
}

/**
 * Adresse encodée dans le QR d'un article sur la carte imprimée : la caisse l'ouvre (code bénévole) et ajoute l'article au panier.
 * Elle marche aussi avec l'appareil photo du téléphone ; le scanner de la caisse n'en lit que le paramètre « a ».
 */
function bv_item_scan_url(int $itemId): string
{
    return bv_volunteer_space_url() . 'caisse.php?a=' . $itemId;
}

/* ====================================================================== */
/* Gratuités des bénévoles (bar et restauration)                          */
/* ====================================================================== */
//
// Chaque bénévole actif a un badge QR personnel (bv_badge_url). Le serveur le scanne dans la caisse : elle montre ce qu'il lui reste
// (quotas par événement, réglables : boissons et repas), puis le serveur touche l'article offert. Un article offert n'entre ni dans la
// recette ni dans les « quantités vendues » du bilan : il est compté à part, avec sa valeur. Boissons = catégories « boissons » de la
// carte ; tout le reste = restauration.

const BV_GIFT_DEFAULT_QUOTAS = ['bar' => 2, 'food' => 1];
const BV_GIFT_MAX_QUOTA = 20;

/** Type d'un article pour les gratuités : 'bar' (boissons) ou 'food' (restauration). */
function bv_gift_kind(array $item): string
{
    return str_starts_with((string) ($item['category'] ?? ''), 'boissons') ? 'bar' : 'food';
}

function bv_gift_kind_label(string $kind): string
{
    return $kind === 'bar' ? 'Boissons' : 'Repas';
}

/** Quotas par bénévole et par événement : ['bar' => n, 'food' => n]. */
function bv_gift_quotas(): array
{
    $q = [];
    foreach (BV_GIFT_DEFAULT_QUOTAS as $kind => $default) {
        $raw = bv_setting('gift_quota_' . $kind, '');
        $q[$kind] = $raw === '' ? $default : max(0, min(BV_GIFT_MAX_QUOTA, (int) $raw));
    }
    return $q;
}

function bv_gift_set_quotas(int $bar, int $food): void
{
    bv_set_setting('gift_quota_bar', (string) max(0, min(BV_GIFT_MAX_QUOTA, $bar)));
    bv_set_setting('gift_quota_food', (string) max(0, min(BV_GIFT_MAX_QUOTA, $food)));
}

/** Jeton du badge d'un bénévole : secret propre à la galerie (créé au premier usage), impossible à deviner. */
function bv_badge_token(int $volunteerId): string
{
    $secret = bv_setting('badge_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(24));
        bv_set_setting('badge_secret', $secret);
    }
    return substr(hash_hmac('sha256', 'bvbadge:' . $volunteerId, $secret), 0, 16);
}

/** Code d'un badge (« 12.a1b2c3d4e5f60718 »), tel que lu dans le QR. */
function bv_badge_code(int $volunteerId): string
{
    return $volunteerId . '.' . bv_badge_token($volunteerId);
}

/** Adresse encodée dans le QR du badge : la caisse l'ouvre et charge le bénévole (appareil photo du téléphone ou scanner de la caisse). */
function bv_badge_url(int $volunteerId): string
{
    return bv_volunteer_space_url() . 'caisse.php?v=' . bv_badge_code($volunteerId);
}

/** Bénévole actif d'après un code de badge (ou d'une adresse contenant « v=… »), ou null. */
function bv_badge_volunteer(string $code): ?array
{
    if (preg_match('/[?&]v=([0-9]+\.[a-f0-9]{16})/', $code, $m)) $code = $m[1];
    if (!preg_match('/^([0-9]{1,9})\.([a-f0-9]{16})$/', trim($code), $m)) return null;
    if (!hash_equals(bv_badge_token((int) $m[1]), $m[2])) return null;
    $stmt = db()->prepare('SELECT * FROM bv_volunteers WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $m[1]]);
    return $stmt->fetch() ?: null;
}

/** Ce qu'un bénévole a déjà reçu et ce qu'il lui reste : ['bar' => ['used', 'quota', 'left'], 'food' => […]]. */
function bv_gift_status(int $eventId, int $volunteerId): array
{
    $quotas = bv_gift_quotas();
    $stmt = db()->prepare('SELECT kind, COUNT(*) FROM bv_gifts WHERE event_id = ? AND volunteer_id = ? AND voided_at IS NULL GROUP BY kind');
    $stmt->execute([$eventId, $volunteerId]);
    $used = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($quotas as $kind => $quota) {
        $u = (int) ($used[$kind] ?? 0);
        $out[$kind] = ['used' => $u, 'quota' => $quota, 'left' => max(0, $quota - $u)];
    }
    return $out;
}

/** Offre un article à un bénévole. -> [don|null, erreur|null, statut]. */
function bv_gift_give(int $eventId, int $volunteerId, int $itemId, string $by = '', array $opts = []): array
{
    // $opts : uid, at, offline (saisie hors ligne : déjà servie, donc enregistrée même au-delà du quota, avec une note).
    $uid = mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['uid'] ?? '')) ?? '', 0, 64);
    if ($uid !== '') {
        $dup = db()->prepare('SELECT * FROM bv_gifts WHERE client_uid = ? LIMIT 1');
        $dup->execute([$uid]);
        if ($row = $dup->fetch()) return [['id' => (int) $row['id'], 'kind' => $row['kind'], 'name' => $row['name'], 'duplicate' => true], null, bv_gift_status((int) $row['event_id'], (int) $row['volunteer_id'])];
    }
    $force = !empty($opts['offline']);
    if (!bv_event_get($eventId)) return [null, 'Aucun événement actif : le responsable doit en activer un.', []];
    $vol = db()->prepare('SELECT * FROM bv_volunteers WHERE id = ? AND is_active = 1');
    $vol->execute([$volunteerId]);
    if (!$vol->fetch()) return [null, 'Bénévole introuvable.', []];
    $item = bv_item_get($itemId);
    if (!$item || !$item['is_active']) return [null, 'Un article n\'est plus à la carte : actualisez la page.', []];
    $kind = bv_gift_kind($item);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');   // deux serveurs qui scannent en même temps ne dépassent pas le quota
    try {
        $status = bv_gift_status($eventId, $volunteerId);
        $note = $force ? 'saisi hors ligne' : '';
        if ($status[$kind]['left'] <= 0 && $force) $note .= ' · hors quota';
        if ($status[$kind]['left'] <= 0 && !$force) {
            $pdo->exec('ROLLBACK');
            return [null, 'Plus de ' . mb_strtolower(bv_gift_kind_label($kind)) . ' offert(e)s : ' . $status[$kind]['used'] . ' sur ' . $status[$kind]['quota'] . ' déjà pris.', $status];
        }
        $pdo->prepare('INSERT INTO bv_gifts (event_id, volunteer_id, kind, item_id, name, unit_cents, by_name, client_uid, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $volunteerId, $kind, $itemId, (string) $item['name'], (int) $item['price_cents'], mb_substr(trim($by), 0, 60), $uid, $note, date('Y-m-d H:i:s', bv_client_ts($opts['at'] ?? 0) ?? time())]);
        $giftId = (int) $pdo->lastInsertId();
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        return [null, 'Enregistrement impossible : réessayez.', []];
    }
    return [['id' => $giftId, 'kind' => $kind, 'name' => (string) $item['name']], null, bv_gift_status($eventId, $volunteerId)];
}

/** Annule une gratuité (erreur d'article). Bénévole : 10 minutes ; responsable : à tout moment. */
function bv_gift_void(int $giftId, bool $isStaff): ?string
{
    $stmt = db()->prepare('SELECT * FROM bv_gifts WHERE id = ?');
    $stmt->execute([$giftId]);
    $g = $stmt->fetch();
    if (!$g) return 'Gratuité introuvable.';
    if ($g['voided_at'] !== null) return 'Cette gratuité est déjà annulée.';
    if (!$isStaff && time() - (int) strtotime((string) $g['created_at']) > BV_SALE_VOID_MINUTES * 60) {
        return 'Trop tard pour annuler (' . BV_SALE_VOID_MINUTES . ' minutes) : demandez au responsable.';
    }
    db()->prepare('UPDATE bv_gifts SET voided_at = ? WHERE id = ? AND voided_at IS NULL')->execute([date('Y-m-d H:i:s'), $giftId]);
    return null;
}

/** Gratuités d'un événement (récentes d'abord) avec le nom du bénévole. */
function bv_gifts_list(int $eventId, int $limit = 200): array
{
    $stmt = db()->prepare('SELECT g.*, v.name AS volunteer FROM bv_gifts g LEFT JOIN bv_volunteers v ON v.id = g.volunteer_id WHERE g.event_id = ? ORDER BY g.id DESC LIMIT ' . max(1, $limit));
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** Bilan des gratuités valables : nombre, valeur offerte, par type. */
function bv_gifts_summary(int $eventId): array
{
    $stmt = db()->prepare("SELECT kind, COUNT(*) n, COALESCE(SUM(unit_cents), 0) cents FROM bv_gifts WHERE event_id = ? AND voided_at IS NULL GROUP BY kind");
    $stmt->execute([$eventId]);
    $out = ['count' => 0, 'value_cents' => 0, 'bar' => 0, 'food' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $out['count'] += (int) $r['n'];
        $out['value_cents'] += (int) $r['cents'];
        $out[$r['kind']] = (int) $r['n'];
    }
    return $out;
}

/* ====================================================================== */
/* Mode dégradé : synchronisation des opérations saisies sans réseau       */
/* ====================================================================== */

/** Bénévoles actifs avec leur badge et ce qu'il leur reste : de quoi offrir une gratuité sans réseau (copié sur le téléphone). */
function bv_roster(int $eventId): array
{
    $out = [];
    foreach (bv_volunteers_list(true) as $v) {
        $out[] = ['id' => (int) $v['id'], 'name' => (string) $v['name'], 'code' => bv_badge_code((int) $v['id']), 'status' => bv_gift_status($eventId, (int) $v['id'])];
    }
    return $out;
}

/**
 * Rejoue les opérations mises en file d'attente par le téléphone. $ops : [['uid', 'type' => sale|gift, 'at', …], …].
 * -> une réponse par opération : ['uid', 'ok', 'error', 'duplicate']. Rejouer deux fois la même file ne crée rien en double.
 */
function bv_sync_ops(int $eventId, array $ops, string $by): array
{
    $results = [];
    foreach (array_slice($ops, 0, 100) as $op) {
        $uid = (string) ($op['uid'] ?? '');
        $type = (string) ($op['type'] ?? '');
        $res = ['uid' => $uid, 'ok' => false, 'error' => 'Opération inconnue.', 'duplicate' => false];
        if ($uid === '') {
            $results[] = $res;
            continue;
        }
        if ($type === 'sale') {
            [$sale, $err] = bv_sale_create($eventId, (array) ($op['items'] ?? []), (string) ($op['method'] ?? ''), isset($op['given_cents']) ? (int) $op['given_cents'] : null, $by,
                ['uid' => $uid, 'at' => $op['at'] ?? 0, 'offline' => true, 'client_total' => $op['total_cents'] ?? null]);
            $res = ['uid' => $uid, 'ok' => $err === null, 'error' => $err, 'duplicate' => !empty($sale['duplicate'])];
        } elseif ($type === 'gift') {
            $vol = bv_badge_volunteer((string) ($op['code'] ?? ''));
            if (!$vol) {
                $res = ['uid' => $uid, 'ok' => false, 'error' => 'Badge inconnu ou bénévole désactivé.', 'duplicate' => false];
            } else {
                [$gift, $err] = bv_gift_give($eventId, (int) $vol['id'], (int) ($op['item_id'] ?? 0), $by, ['uid' => $uid, 'at' => $op['at'] ?? 0, 'offline' => true]);
                $res = ['uid' => $uid, 'ok' => $err === null, 'error' => $err, 'duplicate' => !empty($gift['duplicate'])];
            }
        }
        $results[] = $res;
    }
    return $results;
}
