<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

// Page ouverte depuis le SMS, WhatsApp ou l'e-mail du vendeur : elle ne demande
// pas de connexion, le lien étant signé et valable une semaine. Le vendeur y
// confirme que la pièce est toujours disponible, ou indique qu'elle a été vendue
// entre-temps — auquel cas le client est prévenu et rien n'est prélevé.
$orderId = (int) ($_GET['o'] ?? 0);
$token = (string) ($_GET['t'] ?? '');
$expiresAt = (int) ($_GET['e'] ?? 0);
$order = $orderId > 0 ? order_get($orderId) : null;
$valid = $order && (string) ($order['availability_status'] ?? '') !== '' && oa_token_valid($order, 'seller', $token, $expiresAt);

$flash = null;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cart_csrf_valid()) {
        $flash = ['error', "La page avait expiré : recommencez, s'il vous plaît."];
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'confirm') {
            $res = oa_confirm($orderId, 'vendeur (lien)');
            $flash = [$res['ok'] ? 'ok' : 'error', $res['message']];
        } elseif ($action === 'refuse') {
            $res = oa_refuse($orderId, 'vendeur (lien)');
            $flash = [$res['ok'] ? 'ok' : 'error', $res['message']];
        }
        $order = order_get($orderId);
    }
}
if ($valid && $order && oa_expire($order)) $order = order_get($orderId);

$status = $valid ? (string) ($order['availability_status'] ?? '') : '';
$items = $valid ? (json_decode((string) ($order['items'] ?? '[]'), true) ?: []) : [];
$deadline = $valid && !empty($order['availability_deadline']) ? (int) strtotime((string) $order['availability_deadline']) : 0;
$open = $valid && ($status === 'pending' || $status === 'available');

$activeNav = '';
$pageTitle = 'Validation d\'une commande';
include __DIR__ . '/includes/header.php';
?>
<section class="tight">
  <div class="wrap" style="max-width:680px;">
    <?php if (!$valid): ?>
      <p class="eyebrow">Validation</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Lien non valide ou expiré</h1>
      <p class="lede">Ce lien de validation n'est plus utilisable. Retrouvez la demande dans Administration → Commandes, rubrique « À valider ».</p>
    <?php else: ?>
      <p class="eyebrow">Commande à valider — n° <?= (int) $order['id'] ?></p>
      <h1 style="font-size:1.9rem;margin:12px 0 8px;">La pièce est-elle toujours disponible ?</h1>
      <p class="lede">Aucun paiement n'a été encaissé. Le client attend votre réponse : confirmez pour lui envoyer le lien de paiement, ou signalez que la pièce a été vendue.</p>

      <?php if ($flash): ?>
        <p class="publish-status" data-kind="<?= h($flash[0] === 'ok' ? 'ok' : 'error') ?>" style="margin:16px 0;"><?= h($flash[1]) ?></p>
      <?php endif; ?>

      <div class="admin-block" style="margin-top:20px;">
        <span class="eyebrow" style="font-size:0.9rem;">Demande</span>
        <ul style="list-style:none;padding:0;margin:12px 0 0;">
          <?php foreach ($items as $item): ?>
            <li style="display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--line);">
              <span><strong><?= (int) ($item['qty'] ?? 1) ?> × <?= h((string) ($item['name'] ?? '')) ?></strong></span>
              <span class="price"><?= format_cents((int) ($item['unit_cents'] ?? 0) * (int) ($item['qty'] ?? 1)) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:14px;border-top:1px solid var(--line);">
          <span class="eyebrow" style="font-size:0.9rem;">Total prévu</span>
          <span class="price" style="font-size:1.2rem;"><?= format_cents((int) $order['amount_total']) ?></span>
        </div>
        <ul style="list-style:none;padding:0;margin:14px 0 0;font-size:0.88rem;color:var(--ink-soft);">
          <li>Client : <?= h((string) ($order['email'] ?? '')) ?><?= !empty($order['phone']) ? ' — ' . h((string) $order['phone']) : '' ?></li>
          <li>Mode : <?= order_is_pickup($order) ? 'retrait sur place' : 'expédition — ' . h((string) ($order['shipping_label'] ?: 'livraison')) ?></li>
          <?php if (!empty($order['declared_address'])): ?><li>Livraison : <?= h((string) $order['declared_address']) ?></li><?php endif; ?>
          <li>Demande reçue le <?= h(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?></li>
        </ul>
      </div>

      <?php if ($status === 'available'): ?>
        <p class="publish-status" data-kind="ok" style="margin-top:18px;">Disponibilité confirmée — le client a reçu son lien de paiement. Réponse attendue avant le <?= $deadline > 0 ? h(date('d/m/Y à H:i', $deadline)) : '' ?>.</p>
      <?php elseif ($status === 'refused'): ?>
        <p class="publish-status" data-kind="error" style="margin-top:18px;">Pièce signalée comme vendue. Le client est prévenu ; aucun paiement n'a été effectué.</p>
      <?php elseif ($status === 'expired'): ?>
        <p class="publish-status" data-kind="error" style="margin-top:18px;">Le délai de réponse est dépassé : la demande a été annulée.</p>
      <?php elseif ($status === 'cancelled'): ?>
        <p class="publish-status" style="margin-top:18px;">Le client a annulé sa demande.</p>
      <?php elseif ($status === 'converted'): ?>
        <p class="publish-status" data-kind="ok" style="margin-top:18px;">Le client a payé sa commande<?= !empty($order['availability_order_id']) ? ' (n° ' . (int) $order['availability_order_id'] . ')' : '' ?>.</p>
      <?php endif; ?>

      <?php if ($open): ?>
        <form method="post" action="?o=<?= (int) $order['id'] ?>&amp;t=<?= h(rawurlencode($token)) ?>&amp;e=<?= (int) $expiresAt ?>" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;">
          <?= cart_csrf_field() ?>
          <button type="submit" name="action" value="confirm" class="btn">✓ La pièce est disponible</button>
          <button type="submit" name="action" value="refuse" class="btn-small" style="background:none;border:1px solid var(--line);color:var(--accent);" onclick="return confirm('Indiquer que la pièce a été vendue ? Le client sera prévenu et rien ne sera prélevé.');">La pièce a été vendue</button>
        </form>
        <?php if ($status === 'available'): ?>
          <p style="font-size:0.82rem;color:var(--ink-soft);margin-top:10px;">Vous pouvez encore signaler une vente sur place tant que le client n'a pas payé.</p>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
