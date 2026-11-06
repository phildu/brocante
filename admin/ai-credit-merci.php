<?php
// Retour de Stripe après le rachat d'un crédit IA : la session est relue chez Stripe (jamais crue sur parole), vérifiée (payée, de type crédit IA, pour CETTE boutique),
// puis le crédit est ajouté ; idempotent (le webhook a pu passer avant, ou la page être rechargée).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saas.php';
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
            $r = saas_ai_credit_apply_session($session);
            if ($r['state'] === 'added') {
                $message = 'Merci ! ' . number_format($r['credit_eur'], 2, ',', ' ') . ' € de crédit IA ajoutés à votre boutique (' . saas_ai_estimate_text($r['credit_eur']) . ').';
                $kind = 'ok';
                if (function_exists('saas_event')) saas_event('ai_credit_purchased', ['slug' => tenant_slug(), 'amount' => $r['amount'] ?? 0, 'note' => 'Pack ' . ($r['pack'] ?? '') . ' : ' . number_format($r['credit_eur'], 2, ',', '') . ' € de crédit IA', 'actor' => 'client']);
            } elseif ($r['state'] === 'already') {
                $message = 'Ce paiement est déjà crédité : votre crédit IA est à jour.';
                $kind = 'ok';
            } elseif ($r['state'] === 'unpaid') {
                $message = "Le paiement n'est pas encore confirmé par Stripe : le crédit s'ajoutera dès qu'il le sera.";
            }
        }
    } catch (Throwable $e) {
        error_log('ai-credit-merci: ' . $e->getMessage());
        $message = 'Impossible de vérifier le paiement pour le moment : réessayez dans un instant.';
    }
}
flash_set($message, $kind);
header('Location: /admin/ai-usage.php');
exit;
