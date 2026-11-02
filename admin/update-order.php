<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/orders.php');
    exit;
}
admin_csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$back = '/admin/orders.php';

if ($action === 'fulfillment') {
    $order = order_get($id);
    if (!$order) {
        flash_set('Commande introuvable.', 'error');
        header('Location: ' . $back);
        exit;
    }
    $status = (string) ($_POST['fulfillment_status'] ?? '');
    if (!array_key_exists($status, order_fulfillment_steps($order))) {
        flash_set('Statut de suivi invalide.', 'error');
        header('Location: ' . $back);
        exit;
    }
    $tracking = trim((string) ($_POST['tracking_number'] ?? ''));
    if ($status === 'pret_retrait' && order_mark_ready($id)) {
        flash_set('Commande prête : le client a reçu le code et la date limite de retrait.');
    } elseif ($status === 'retiree') {
        $res = order_confirm_pickup($id, (string) ($_POST['pickup_code'] ?? ''), trim((string) ($_POST['picked_up_by'] ?? '')));
        flash_set($res['message'], $res['ok'] ? 'ok' : 'error');
    } elseif ($status === 'expediee' && order_mark_shipped($id, $tracking)) {
        flash_set('Commande marquée expédiée.' . ($tracking !== '' ? ' N° de suivi enregistré.' : ''));
    } elseif ($status === 'livree' && order_mark_delivered($id)) {
        flash_set('Commande marquée livrée.');
    } else {
        db()->prepare('UPDATE orders SET fulfillment_status = ?, tracking_number = ? WHERE id = ?')
            ->execute([$status, $tracking !== '' ? $tracking : null, $id]);
        order_event($id, 'suivi', 'Statut : ' . fulfillment_label($status), 'admin');
        flash_set('Suivi de commande mis à jour.');
    }
} elseif ($action === 'refund') {
    $amount = price_to_cents(str_replace(',', '.', (string) ($_POST['refund_amount'] ?? '')));
    if ($amount === null || $amount <= 0) {
        flash_set('Indiquez un montant de remboursement valide (en euros).', 'error');
    } else {
        $res = order_refund($id, $amount, trim((string) ($_POST['refund_reason'] ?? '')), !empty($_POST['restock']));
        flash_set($res['message'], $res['ok'] ? 'ok' : 'error');
    }
} elseif ($action === 'cancel') {
    $res = order_cancel($id, trim((string) ($_POST['cancel_reason'] ?? '')));
    flash_set($res['message'], $res['ok'] ? 'ok' : 'error');
} elseif ($action === 'resend_confirmation') {
    $sent = order_send_confirmation($id, true);
    flash_set($sent ? 'Récapitulatif renvoyé au client.' : 'Impossible d\'envoyer l\'e-mail (adresse absente ?).', $sent ? 'ok' : 'error');
} elseif ($action === 'refresh_stripe') {
    // Rattrape une commande restée "en attente" côté site alors que le
    // paiement a bien abouti chez Stripe (client parti avant la redirection).
    $order = order_get($id);
    if (!$order) {
        flash_set('Commande introuvable.', 'error');
        header('Location: ' . $back);
        exit;
    }
    if (payment_provider_of($order) !== 'stripe') {
        flash_set('Cette commande n’a pas été payée avec Stripe.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $session = stripe_request('GET', '/checkout/sessions/' . urlencode($order['stripe_session_id']), [], ($order['payment_via'] ?? '') === 'connect');
        $paid = ($session['payment_status'] ?? '') === 'paid';
        if ($paid && $order['status'] !== 'paid' && order_mark_paid($order, $session)) {
            flash_set('Paiement confirmé auprès de Stripe — commande mise à jour.');
        } elseif ($paid) {
            flash_set('Cette commande est déjà à jour (payée).');
        } else {
            flash_set('Stripe indique que cette commande n\'est toujours pas payée (statut : ' . h($session['payment_status'] ?? 'inconnu') . ').', 'error');
        }
    } catch (Throwable $e) {
        flash_set('Impossible de vérifier auprès de Stripe : ' . $e->getMessage(), 'error');
    }
} elseif ($action === 'refresh_paypal') {
    // Même rattrapage pour PayPal : interroge la commande et capture si l'acheteur a approuvé sans revenir sur le site.
    $order = order_get($id);
    if (!$order) {
        flash_set('Commande introuvable.', 'error');
        header('Location: ' . $back);
        exit;
    }
    if (payment_provider_of($order) !== 'paypal') {
        flash_set('Cette commande n’a pas été payée avec PayPal.', 'error');
        header('Location: ' . $back);
        exit;
    }
    $config = paypal_config();
    $paypalOrderId = trim((string) ($order['provider_checkout_id'] ?? ''));
    if (!$config) {
        flash_set('PayPal n’est plus configuré pour cette boutique.', 'error');
    } elseif ($paypalOrderId === '') {
        flash_set('Aucune commande PayPal enregistrée pour cette commande.', 'error');
    } else {
        try {
            $data = paypal_get_order($config, $paypalOrderId);
            if (strtoupper((string) ($data['status'] ?? '')) === 'APPROVED') {
                $data = paypal_capture_order($config, $paypalOrderId, (string) ($order['payment_reference'] ?? ''));
            }
            $capture = paypal_capture_data($data);
            if (!paypal_is_paid($data)) {
                flash_set('PayPal indique que cette commande n’est pas encore payée (statut : ' . ($capture['order_status'] ?: 'inconnu') . ').', 'error');
            } elseif ($order['status'] === 'paid') {
                flash_set('Cette commande est déjà à jour (payée).');
            } elseif ($capture['amount_cents'] !== (int) $order['amount_total'] || $capture['currency'] !== 'EUR') {
                order_event($id, 'paiement_echec', 'Montant PayPal incohérent : ' . ($capture['amount_cents'] ?? '?') . ' centimes ' . $capture['currency'] . ' au lieu de ' . (int) $order['amount_total'] . ' EUR', 'admin');
                flash_set('PayPal a encaissé un montant différent de la commande — vérifiez avant d’expédier.', 'error');
            } else {
                order_finalize_external($order, 'paypal', $capture['capture_id'], '', trim('PayPal ' . $capture['email']));
                flash_set('Paiement confirmé auprès de PayPal — commande mise à jour.');
            }
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'ORDER_ALREADY_CAPTURED') || str_contains($e->getMessage(), 'ORDER_ALREADY_COMPLETED')) {
                try {
                    $existing = paypal_get_order($config, $paypalOrderId);
                    $capture = paypal_capture_data($existing);
                    if (paypal_is_paid($existing) && $order['status'] !== 'paid') {
                        order_finalize_external($order, 'paypal', $capture['capture_id'], '', trim('PayPal ' . $capture['email']));
                        flash_set('Paiement confirmé auprès de PayPal — commande mise à jour.');
                    } else {
                        flash_set('PayPal signale une commande déjà capturée mais non payée — vérifiez dans PayPal.', 'error');
                    }
                } catch (Throwable $retry) {
                    flash_set('Impossible de vérifier auprès de PayPal : ' . $retry->getMessage(), 'error');
                }
            } else {
                flash_set('Impossible de vérifier auprès de PayPal : ' . $e->getMessage(), 'error');
            }
        }
    }
}

header('Location: ' . $back);
exit;
