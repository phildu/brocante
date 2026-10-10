<?php
// Retour de Stripe Checkout après le paiement d'une formule : on INTERROGE Stripe (jamais la seule adresse de retour) pour confirmer que la
// session est payée ; la demande passe « paid » et le client est envoyé à l'étape 3 (creer.php) pour créer sa boutique. Le webhook
// (webhook.php) enregistre le paiement et envoie le lien par e-mail si la page de retour est fermée.
require_once __DIR__ . '/../includes/saas.php';

$cfg = saas_config();
$origin = saas_origin();
$reqId = (string) ($_GET['r'] ?? '');
$sessionId = (string) ($_GET['s'] ?? '');
$result = ['state' => 'error', 'message' => 'Lien de retour invalide.'];

if (preg_match('/^[a-f0-9]{16}$/', $reqId) && preg_match('/^cs_[A-Za-z0-9_]{6,200}$/', $sessionId) && ($req = saas_request_load($reqId))) {
    if (($req['payment']['session'] ?? '') !== $sessionId) {
        $result = ['state' => 'error', 'message' => 'Cette session de paiement ne correspond pas à la demande.'];
    } elseif (in_array($req['status'] ?? '', ['paid', 'pending', 'approved'], true)) {
        $result = ['state' => 'already', 'request' => $req];
    } else {
        try {
            $result = saas_mark_paid($reqId, saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId)), $origin);
        } catch (RuntimeException $e) {
            error_log('merci.php: ' . $e->getMessage());
            $result = ['state' => 'error', 'message' => "Impossible de confirmer le paiement auprès de Stripe pour le moment : actualisez cette page dans un instant."];
        }
    }
}

// Payé : direction l'étape 3.
if (in_array($result['state'], ['paid', 'already'], true) && !empty($result['request'])) {
    header('Location: ' . saas_creation_url($result['request'], $origin), true, 303);
    exit;
}

saas_page_start('Paiement — ' . $cfg['name'], 'Confirmation de votre paiement.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Paiement</p><h1>Confirmation du paiement</h1></div>
  <div class="form-card">
    <?php if ($result['state'] === 'unpaid'): ?>
      <div class="notice" role="status"><strong>Paiement en cours de confirmation.</strong> Stripe n'a pas encore confirmé le règlement : actualisez cette page dans un instant. Vous recevrez aussi par e-mail le lien pour créer votre boutique dès la confirmation.</div>
      <p><a class="btn" href="">Actualiser</a></p>
    <?php else: ?>
      <div class="notice error" role="alert"><?= saas_e($result['message'] ?? 'Une erreur est survenue.') ?></div>
      <p><a class="btn ghost" href="/inscription/">Retour</a></p>
    <?php endif; ?>
  </div>
</div>
<?php saas_page_end(); ?>
