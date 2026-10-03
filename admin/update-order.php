<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/orders.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($action === 'fulfillment') {
    $status = (string) ($_POST['fulfillment_status'] ?? '');
    if (!in_array($status, ['a_preparer', 'expediee', 'livree'], true)) {
        flash_set('Statut de suivi invalide.', 'error');
        header('Location: /admin/orders.php');
        exit;
    }
    $tracking = trim((string) ($_POST['tracking_number'] ?? ''));
    $stmt = db()->prepare('UPDATE orders SET fulfillment_status = ?, tracking_number = ? WHERE id = ?');
    $stmt->execute([$status, $tracking !== '' ? $tracking : null, $id]);
    flash_set('Suivi de commande mis à jour.');
} elseif ($action === 'refresh_stripe') {
    // Rattrape une commande restée "en attente" côté site alors que le
    // paiement a bien abouti chez Stripe (le client a fermé l'onglet avant
    // la redirection de retour vers checkout-success.php — il n'y a pas de
    // webhook Stripe configuré, donc c'est le seul moyen de recaler l'état).
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash_set('Commande introuvable.', 'error');
        header('Location: /admin/orders.php');
        exit;
    }
    try {
        $session = stripe_request('GET', '/checkout/sessions/' . urlencode($order['stripe_session_id']));
        $paid = ($session['payment_status'] ?? '') === 'paid';
        if ($paid && $order['status'] !== 'paid') {
            $shippingCents = (int) ($session['shipping_cost']['amount_total'] ?? 0);
            $shippingAddr = $session['shipping_details']['address'] ?? $session['customer_details']['address'] ?? null;
            $addressText = $shippingAddr ? implode(', ', array_filter([
                $session['shipping_details']['name'] ?? null,
                $shippingAddr['line1'] ?? null,
                $shippingAddr['line2'] ?? null,
                trim(($shippingAddr['postal_code'] ?? '') . ' ' . ($shippingAddr['city'] ?? '')),
                $shippingAddr['country'] ?? null,
            ])) : null;
            $upd = db()->prepare('UPDATE orders SET status = ?, email = ?, amount_total = ?, shipping_cents = ?, shipping_address = ? WHERE id = ?');
            $upd->execute([
                'paid',
                $session['customer_details']['email'] ?? null,
                (int) ($session['amount_total'] ?? $order['amount_total']),
                $shippingCents,
                $addressText,
                $id,
            ]);
            decrement_stock_for_order(json_decode($order['items'], true) ?: []);
            flash_set('Paiement confirmé auprès de Stripe — commande mise à jour.');
        } elseif ($paid) {
            flash_set('Cette commande est déjà à jour (payée).');
        } else {
            flash_set('Stripe indique que cette commande n\'est toujours pas payée (statut : ' . h($session['payment_status'] ?? 'inconnu') . ').', 'error');
        }
    } catch (Throwable $e) {
        flash_set('Impossible de vérifier auprès de Stripe : ' . $e->getMessage(), 'error');
    }
}

header('Location: /admin/orders.php');
exit;
