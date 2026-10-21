<?php
// Retour Stripe après souscription à l'option.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/subscribe.php';
require_once dirname(__DIR__, 3) . '/includes/saas.php';

$id = (string) ($_GET['s'] ?? '');
$message = "Paiement non confirmé : s'il a bien été débité, l'option s'activera dès la confirmation de Stripe.";
$kind = 'error';

if (preg_match('/^cs_[A-Za-z0-9_]+$/', $id) && function_exists('saas_stripe_request')) {
    try {
        $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($id));
        if (($session['metadata']['shop'] ?? '') !== tenant_slug() || ($session['metadata']['type'] ?? '') !== 'marketplace_addon') {
            $message = 'Cette session de paiement ne correspond pas à votre boutique.';
        } else {
            $r = am_apply_checkout_session($session);
            if ($r['state'] === 'added') {
                $message = 'Merci ! L’option Annonces places de marché est active. Créez un jeton pour l’extension.';
                $kind = 'ok';
                if (function_exists('saas_event')) {
                    saas_event('config_changed', [
                        'slug' => tenant_slug(),
                        'note' => 'Option Annonces places de marché souscrite',
                        'amount' => (int) ($session['amount_total'] ?? 0),
                        'actor' => 'client',
                    ]);
                }
            } elseif ($r['state'] === 'already') {
                $message = 'Cet abonnement est déjà enregistré : l’option est active.';
                $kind = 'ok';
            } elseif ($r['state'] === 'unpaid') {
                $message = "Le paiement n'est pas encore confirmé par Stripe.";
            }
        }
    } catch (Throwable $e) {
        error_log('annonces-marche merci: ' . $e->getMessage());
        $message = 'Impossible de vérifier le paiement pour le moment.';
    }
}
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page">
  <p class="eyebrow">Option · Paiement</p>
  <h1 style="font-size:1.8rem;margin:12px 0 12px;">Confirmation</h1>
  <p class="am-flash" data-kind="<?= h($kind) ?>"><?= h($message) ?></p>
  <p><a class="btn-small" href="<?= h(plugin_page_url('annonces-marche', 'index')) ?>">Retour à Annonces LBC / Vinted</a></p>
</div>
