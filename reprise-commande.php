<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}

// Lien « Payer ma commande » reçu après validation du vendeur : le panier et
// les coordonnées de la demande sont remis dans la session, puis le client
// repasse normalement à la caisse (aucun moyen de paiement n'est imposé ici).
$orderId = (int) ($_GET['o'] ?? 0);
$token = (string) ($_GET['t'] ?? '');
$expiresAt = (int) ($_GET['e'] ?? 0);

$res = $orderId > 0 ? oa_resume($orderId, $token, $expiresAt) : ['ok' => false, 'message' => 'Ce lien de paiement est incomplet.', 'order' => null];
if ($res['ok']) {
    flash_set('Votre panier est prêt : vérifiez-le puis réglez votre commande.', 'ok');
    header('Location: /cart.php');
    exit;
}

$activeNav = '';
$pageTitle = 'Paiement de ma demande';
include __DIR__ . '/includes/header.php';
?>
<section class="tight">
  <div class="wrap" style="max-width:620px;">
    <p class="eyebrow">Demande n° <?= (int) $orderId ?></p>
    <h1 style="font-size:1.9rem;margin:12px 0 16px;">Ce lien ne permet plus de payer</h1>
    <p class="lede"><?= h((string) $res['message']) ?></p>
    <?php if (!empty($res['order'])): ?>
      <p style="font-size:0.9rem;color:var(--ink-soft);">État de la demande : <?= h(oa_status_label((string) ($res['order']['availability_status'] ?? ''))) ?>.</p>
    <?php endif; ?>
    <a class="btn" href="/boutique.php" style="margin-top:20px;">Voir la boutique</a>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
