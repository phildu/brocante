<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}

$orderId = (int) ($_GET['o'] ?? 0);
$token = (string) ($_GET['t'] ?? '');
$expiresAt = (int) ($_GET['e'] ?? 0);
$order = $orderId > 0 ? order_get($orderId) : null;
$valid = $order && (string) ($order['availability_status'] ?? '') !== '' && oa_token_valid($order, 'buyer', $token, $expiresAt);

$flash = null;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!cart_csrf_valid()) {
        $flash = ['error', "La page avait expiré : réessayez, s'il vous plaît."];
    } else {
        $res = oa_cancel_by_buyer($orderId);
        $flash = [$res['ok'] ? 'ok' : 'error', $res['message']];
        $order = order_get($orderId);
    }
}

// Délai dépassé : la demande est fermée au passage (et le client prévenu par e-mail).
if ($valid && $order && oa_expire($order)) {
    $order = order_get($orderId);
}

$status = $valid ? (string) ($order['availability_status'] ?? '') : '';
$items = $valid ? (json_decode((string) ($order['items'] ?? '[]'), true) ?: []) : [];
$deadline = $valid && !empty($order['availability_deadline']) ? (int) strtotime((string) $order['availability_deadline']) : 0;

$activeNav = '';
$pageTitle = 'Ma demande';
include __DIR__ . '/includes/header.php';
?>
<section class="tight">
  <div class="wrap" style="max-width:640px;">
    <?php if (!$valid): ?>
      <p class="eyebrow">Demande</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Lien non valide</h1>
      <p class="lede">Ce lien de suivi est incomplet, expiré ou ne correspond pas à une demande. Si vous avez commandé, retrouvez le lien dans l'e-mail reçu à la validation de votre panier.</p>
      <a class="btn btn-ghost" href="/boutique.php" style="margin-top:20px;">← Retour à la boutique</a>
    <?php else: ?>
      <p class="eyebrow">Demande n° <?= (int) $order['id'] ?></p>
      <?php if ($status === 'pending'): ?>
        <h1 style="font-size:1.9rem;margin:12px 0 16px;">Nous vérifions la disponibilité</h1>
        <p class="lede">Votre demande est bien arrivée. Le vendeur vérifie que la pièce est toujours disponible — elle peut être vendue en boutique entre-temps.</p>
        <p class="lede">Aucun paiement n'a été effectué. Vous recevrez un lien de paiement à <?= h($order['email'] ?: 'votre adresse e-mail') ?> dès que la disponibilité est confirmée.</p>
        <?php if (!empty($order['availability_channel']) && order_event_seen((int) $order['id'], 'dispo_notification')): ?>
          <p style="font-size:0.85rem;color:var(--ink-soft);">Le vendeur a été prévenu par <?= h(oa_channel_label((string) $order['availability_channel'])) ?>.</p>
        <?php endif; ?>
      <?php elseif ($status === 'available' && $deadline > time()): ?>
        <h1 style="font-size:1.9rem;margin:12px 0 16px;">La pièce est disponible !</h1>
        <p class="lede">Le vendeur a confirmé la disponibilité. La pièce est mise de côté pour vous jusqu'au <strong><?= h(date('d/m/Y à H:i', $deadline)) ?></strong>.</p>
        <p class="lede">Aucun paiement n'a encore été effectué : réglez votre commande pour la réserver définitivement.</p>
        <p style="margin:24px 0;">
          <a class="btn" href="<?= h(oa_pay_url($order)) ?>">Payer ma commande — <?= h(format_cents((int) $order['amount_total'])) ?></a>
        </p>
        <p style="font-size:0.85rem;color:var(--ink-soft);">Passé ce délai, la demande sera annulée et la pièce remise en vente.</p>
      <?php elseif ($status === 'refused'): ?>
        <h1 style="font-size:1.9rem;margin:12px 0 16px;">La pièce a été vendue</h1>
        <p class="lede">Désolé, la pièce de votre demande a trouvé preneur entre-temps. Aucun paiement n'a été effectué et aucun montant ne vous sera prélevé.</p>
      <?php elseif ($status === 'expired'): ?>
        <h1 style="font-size:1.9rem;margin:12px 0 16px;">Demande expirée</h1>
        <p class="lede">Le délai est dépassé : la pièce a été remise en vente. Aucun montant ne vous a été prélevé.</p>
      <?php elseif ($status === 'cancelled'): ?>
        <h1 style="font-size:1.9rem;margin:12px 0 16px;">Demande annulée</h1>
        <p class="lede">Cette demande a été annulée. Aucun paiement n'a été effectué.</p>
      <?php elseif ($status === 'converted'): ?>
        <h1 style="font-size:1.9rem;margin:12px 0 16px;">Commande payée</h1>
        <p class="lede">Cette demande a été remplacée par une commande payée <?= !empty($order['availability_order_id']) ? 'n° ' . (int) $order['availability_order_id'] : '' ?>. Merci !</p>
      <?php endif; ?>

      <div class="admin-block" style="margin-top:24px;">
        <span class="eyebrow" style="font-size:0.9rem;">Votre sélection</span>
        <ul style="list-style:none;padding:0;margin:12px 0 0;">
          <?php foreach ($items as $item): ?>
            <li style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--line);">
              <span><?= (int) ($item['qty'] ?? 1) ?> × <?= h((string) ($item['name'] ?? '')) ?></span>
              <span class="price"><?= format_cents((int) ($item['unit_cents'] ?? 0) * (int) ($item['qty'] ?? 1)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ((int) $order['shipping_cents'] > 0): ?>
          <p style="font-size:0.85rem;color:var(--ink-soft);margin-top:10px;"><?= h((string) ($order['shipping_label'] ?: 'Livraison')) ?> : <?= format_cents((int) $order['shipping_cents']) ?></p>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:14px;border-top:1px solid var(--line);">
          <span class="eyebrow" style="font-size:0.9rem;">Total <?= (string) ($order['availability_status'] ?? '') === 'converted' ? 'payé' : 'prévu' ?></span>
          <span class="price" style="font-size:1.2rem;"><?= format_cents((int) $order['amount_total']) ?></span>
        </div>
        <p style="font-size:0.8rem;color:var(--ink-soft);margin-top:12px;"><?= order_is_pickup($order) ? 'Retrait sur place' : 'Expédition — ' . h((string) ($order['shipping_label'] ?: 'livraison')) ?></p>
      </div>

      <?php if ($flash): ?>
        <p class="publish-status" data-kind="<?= h($flash[0] === 'ok' ? 'ok' : 'error') ?>" style="margin-top:20px;"><?= h($flash[1]) ?></p>
      <?php endif; ?>

      <?php if (in_array($status, ['pending', 'available'], true)): ?>
        <form method="post" action="?o=<?= (int) $order['id'] ?>&amp;t=<?= h(rawurlencode($token)) ?>&amp;e=<?= (int) $expiresAt ?>" style="margin-top:24px;" onsubmit="return confirm('Annuler cette demande ? Aucun paiement n\'a été effectué.');">
          <?= cart_csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn-small" style="background:none;border:1px solid var(--line);color:var(--ink-soft);">Annuler ma demande</button>
        </form>
      <?php endif; ?>

      <a class="btn btn-ghost" href="/boutique.php" style="margin-top:24px;">← Retour à la boutique</a>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
