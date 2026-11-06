<?php
// Caisse de la buvette : réponses JSON {ok: true, ...} ou {ok: false, error}. Accès : code bénévole (ou responsable), CSRF dans le corps.
//   état  (action=state) → carte, bilan et dernières ventes de l'événement actif
//   vente (action=sale)  → items [{id, qty}], method cash|card|stripe, given_cents (espèces remises, facultatif)
//       stripe : renvoie l'adresse de la page de paiement (à afficher en QR) ; la vente reste « en attente »
//   suivi (action=card_status) / abandon (action=card_cancel) → id d'une vente par QR en attente
//   gratuités bénévoles : gift_lookup (code du badge) → nom et reste ; gift_give (code, item_id) → offre l'article ; gift_void (id)
//   synchro (action=sync) → ops [{uid, type: sale|gift, at, …}] saisies sans réseau : rejouées sans doublon (identifiant uid)
//   annulation (action=void) → id de la vente (bénévole : 10 minutes ; responsable : à tout moment, remboursement Stripe compris)
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/buvette-caisse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bvc_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

bv_public_enter();   // galerie d'après l'adresse, extension active (sinon page introuvable)
bv_boot();
if (!bv_is_volunteer()) bvc_reply(['ok' => false, 'error' => 'Session expirée : rechargez la page et entrez le code.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bvc_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!isset($_SESSION['csrf_token'], $in['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $in['csrf_token'])) {
    bvc_reply(['ok' => false, 'error' => 'Page périmée : rechargez-la.'], 403);
}

$event = bv_active_event();
if (!$event) bvc_reply(['ok' => false, 'error' => 'Aucun événement actif : le responsable doit en activer un.'], 409);
$eid = (int) $event['id'];

/** État renvoyé après chaque action : de quoi redessiner l'écran sans recharger. */
function bvc_state(int $eid): array
{
    $sum = bv_sales_summary($eid);
    $recent = [];
    foreach (bv_sales_list($eid, 15) as $s) {
        $dead = $s['voided_at'] !== null || $s['status'] === 'canceled';
        $young = time() - (int) strtotime((string) $s['created_at']) <= BV_SALE_VOID_MINUTES * 60;
        $recent[] = [
            'id' => (int) $s['id'], 'time' => date('H:i', (int) strtotime((string) $s['created_at'])), 'method' => $s['method'], 'method_label' => bv_method_label($s['method']),
            'total_cents' => (int) $s['total_cents'], 'label' => bv_sale_label($s), 'voided' => $dead, 'pending' => $s['status'] === 'pending',
            'can_void' => !$dead && $s['status'] === 'paid' && (bv_is_staff() || ($young && $s['method'] !== 'stripe')),
        ];
    }
    $items = [];
    foreach (bv_items_list(true) as $it) $items[] = ['id' => (int) $it['id'], 'name' => (string) $it['name'], 'cents' => (int) $it['price_cents'], 'cat' => (string) $it['category']];
    // La carte et les badges sont recopiés sur le téléphone : de quoi encaisser et offrir une gratuité sans réseau.
    return ['summary' => $sum, 'recent' => $recent, 'card_qr' => bv_card_account() !== null, 'items' => $items, 'roster' => bv_roster($eid), 'generated' => time()];
}

switch ((string) ($in['action'] ?? '')) {
    case 'state':
        bv_sales_reconcile($eid);   // paiements par QR réglés alors que le téléphone ne suivait plus
        bvc_reply(['ok' => true] + bvc_state($eid));

    case 'sale':
        $given = isset($in['given_cents']) && $in['given_cents'] !== null && $in['given_cents'] !== '' ? (int) $in['given_cents'] : null;
        $me = bv_me();
        if ((string) ($in['method'] ?? '') === 'stripe') {
            $origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            [$sale, $err] = bv_sale_start_card($eid, (array) ($in['items'] ?? []), (string) ($me['name'] ?? ''), $origin . '/galerie/' . bv_gallery_context()['slug'] . '/buvette/');
            if ($err !== null) bvc_reply(['ok' => false, 'error' => $err], 422);
            bvc_reply(['ok' => true, 'sale' => $sale] + bvc_state($eid));
        }
        [$sale, $err] = bv_sale_create($eid, (array) ($in['items'] ?? []), (string) ($in['method'] ?? ''), $given, (string) ($me['name'] ?? ''), ['uid' => $in['uid'] ?? '']);
        if ($err !== null) bvc_reply(['ok' => false, 'error' => $err], 422);
        bvc_reply(['ok' => true, 'sale' => $sale] + bvc_state($eid));

    case 'card_status':
    case 'card_cancel':
        $status = bv_sale_card_status((int) ($in['id'] ?? 0), $in['action'] === 'card_cancel');
        bvc_reply(['ok' => $status !== 'error', 'status' => $status, 'error' => $status === 'error' ? 'Vérification impossible : réessayez.' : null] + bvc_state($eid));

    case 'gift_lookup':
    case 'gift_give':
        $vol = bv_badge_volunteer((string) ($in['code'] ?? ''));
        if (!$vol) bvc_reply(['ok' => false, 'error' => 'Badge inconnu ou bénévole désactivé.'], 422);
        $vid = (int) $vol['id'];
        if ($in['action'] === 'gift_lookup') {
            bvc_reply(['ok' => true, 'volunteer' => ['id' => $vid, 'name' => (string) $vol['name']], 'status' => bv_gift_status($eid, $vid)]);
        }
        $me = bv_me();
        [$gift, $err, $status] = bv_gift_give($eid, $vid, (int) ($in['item_id'] ?? 0), (string) ($me['name'] ?? ''), ['uid' => $in['uid'] ?? '']);
        if ($err !== null) bvc_reply(['ok' => false, 'error' => $err, 'status' => $status ?: null], 422);
        bvc_reply(['ok' => true, 'gift' => $gift, 'volunteer' => ['id' => $vid, 'name' => (string) $vol['name']], 'status' => $status]);

    case 'sync':
        $me = bv_me();
        $results = bv_sync_ops($eid, (array) ($in['ops'] ?? []), (string) ($me['name'] ?? ''));
        bvc_reply(['ok' => true, 'results' => $results] + bvc_state($eid));

    case 'gift_void':
        $err = bv_gift_void((int) ($in['id'] ?? 0), bv_is_staff());
        if ($err !== null) bvc_reply(['ok' => false, 'error' => $err], 422);
        bvc_reply(['ok' => true]);

    case 'void':
        $err = bv_sale_void((int) ($in['id'] ?? 0), bv_is_staff());
        if ($err !== null) bvc_reply(['ok' => false, 'error' => $err], 422);
        bvc_reply(['ok' => true] + bvc_state($eid));
}
bvc_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
