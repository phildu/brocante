<?php
// Retour de Stripe après le rachat d'un crédit SMS : la session est relue chez
// Stripe (jamais crue sur parole), vérifiée (payée, de type crédit SMS, pour CETTE
// boutique), puis le crédit est ajouté ; idempotent (le webhook a pu passer avant,
// ou la page être rechargée).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/sms.php';
require_admin();

$id = (string) ($_GET['s'] ?? '');
$message = "Paiement non confirmé : si vous avez été débité, le crédit s'ajoutera dès la confirmation de Stripe.";
$kind = 'error';
if (preg_match('/^cs_[A-Za-z0-9_]+$/', $id)) {
    try {
        $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($id));
        if (($session['metadata']['shop'] ?? '') !== tenant_slug()) {
            $message = 'Cette session de paiement ne correspond pas à votre boutique.';
        } else {
            $r = sms_credit_apply_session($session);
            if ($r['state'] === 'added') {
                $message = 'Merci ! ' . number_format((int) $r['sms'], 0, ',', ' ') . ' SMS ajoutés à votre crédit (' . sms_credit_account(tenant_slug())['left'] . ' restants).';
                $kind = 'ok';
                if (function_exists('saas_event')) saas_event('sms_credit_purchased', ['slug' => tenant_slug(), 'amount' => $r['amount'] ?? 0, 'note' => 'Pack ' . ($r['pack'] ?? '') . ' : ' . (int) $r['sms'] . ' SMS', 'actor' => 'client']);
            } elseif ($r['state'] === 'already') {
                $message = 'Ce paiement est déjà crédité : votre crédit SMS est à jour.';
                $kind = 'ok';
            } elseif ($r['state'] === 'unpaid') {
                $message = "Le paiement n'est pas encore confirmé par Stripe : le crédit s'ajoutera dès qu'il le sera.";
            }
        }
    } catch (Throwable $e) {
        error_log('sms-credit-merci: ' . $e->getMessage());
        $message = 'Impossible de vérifier le paiement pour le moment : réessayez dans un instant.';
    }
}
flash_set($message, $kind);
header('Location: /admin/sms-credits.php');
exit;
