<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}

$orderId = (int) ($_GET['order'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');
$order = order_get($orderId);
$error = null;
$info = null;

if (!$order) {
    $error = 'Commande introuvable.';
} elseif (payment_provider_of($order) !== 'sepa') {
    $error = "Ce lien de commande n'est pas valide.";
} elseif (!payment_return_token_valid($orderId, 'sepa', $sig)) {
    $error = "Ce lien de commande n'est pas valide.";
} else {
    // Délai dépassé : la commande est annulée à la première consultation du lien.
    if ($order['status'] === 'pending' && time() > sepa_deadline($order)->getTimestamp()) {
        order_cancel($orderId, 'Virement SEPA non reçu dans le délai');
        try {
            order_send_transfer_expired($orderId);
        } catch (Throwable $e) {
            error_log('e-mail virement annulé ' . $orderId . ' : ' . $e->getMessage());
        }
        $order = order_get($orderId);
    }
    if ($order && $order['status'] === 'pending') $info = sepa_instructions($order);
}

$activeNav = '';
$pageTitle = 'Virement SEPA';
include __DIR__ . '/includes/header.php';
?>

<section class="tight">
  <div class="wrap" style="max-width:680px;">
    <?php if ($error !== null): ?>
      <p class="eyebrow">Commande</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Lien de commande invalide</h1>
      <p class="lede"><?= h($error) ?></p>
      <a class="btn btn-ghost" href="/boutique.php" style="margin-top:20px;">← Retour à la boutique</a>

    <?php elseif ($order['status'] === 'cancelled'): ?>
      <p class="eyebrow">Commande n° <?= (int) $order['id'] ?></p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Commande annulée</h1>
      <p class="lede">Nous n’avons pas reçu le virement dans le délai indiqué. Les pièces ont été remises en vente. Si vous avez viré le montant entre-temps, répondez à l’e-mail de votre commande avec la date du virement : nous le retrouverons.</p>
      <a class="btn btn-ghost" href="/boutique.php" style="margin-top:20px;">← Retour à la boutique</a>

    <?php elseif (in_array($order['status'], ['paid', 'refunded'], true)): ?>
      <p class="eyebrow">Merci !</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Virement reçu</h1>
      <p class="lede">Votre commande n° <?= (int) $order['id'] ?> est confirmée. La référence <strong><?= h(sepa_reference($order)) ?></strong> a été rattachée au paiement.</p>
      <div class="admin-block" style="margin-top:24px;">
        <?php foreach (json_decode((string) $order['items'], true) ?: [] as $item): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
            <span><?= h($item['qty']) ?> × <?= h($item['name']) ?></span>
            <span class="price"><?= format_cents((int) $item['unit_cents'] * (int) $item['qty']) ?></span>
          </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;padding-top:16px;font-weight:600;">
          <span>Total reçu</span>
          <span class="price"><?= format_cents((int) $order['amount_total']) ?></span>
        </div>
      </div>
      <a class="btn btn-primary" href="/checkout-success.php?order_id=<?= (int) $order['id'] ?>&amp;sig=<?= h(rawurlencode($sig)) ?>" style="margin-top:24px;">Voir le récapitulatif</a>

    <?php elseif ($info !== null): ?>
      <p class="eyebrow">Commande n° <?= (int) $order['id'] ?> — réservée</p>
      <h1 style="font-size:1.9rem;margin:12px 0 12px;">Votre commande est réservée</h1>
      <p class="lede">Pour la confirmer, effectuez un virement SEPA de <strong><?= format_cents($info['amount_cents']) ?></strong> en indiquant la référence <strong><?= h($info['reference']) ?></strong>. Les pièces sont gardées jusqu’au <?= h($info['deadline']->format('d/m/Y')) ?>.</p>

      <div class="admin-block" style="margin-top:26px;">
        <h2 style="font-size:1.05rem;margin:0 0 12px;">Coordonnées bancaires</h2>
        <div style="display:grid;gap:10px;font-size:0.95rem;">
          <div><span style="color:var(--ink-soft);">Titulaire :</span> <strong><?= h($info['holder']) ?></strong></div>
          <div>
            <span style="color:var(--ink-soft);">IBAN :</span>
            <strong id="sepa-iban" style="letter-spacing:0.04em;"><?= h($info['iban']) ?></strong>
            <button type="button" class="btn-small" data-copy="sepa-iban" style="margin-left:8px;">Copier</button>
          </div>
          <?php if ($info['bic'] !== ''): ?><div><span style="color:var(--ink-soft);">BIC :</span> <strong><?= h($info['bic']) ?></strong></div><?php endif; ?>
          <?php if ($info['bank'] !== ''): ?><div><span style="color:var(--ink-soft);">Banque :</span> <?= h($info['bank']) ?></div><?php endif; ?>
          <div>
            <span style="color:var(--ink-soft);">Montant exact :</span> <strong><?= format_cents($info['amount_cents']) ?></strong>
          </div>
          <div>
            <span style="color:var(--ink-soft);">Référence à indiquer :</span>
            <strong id="sepa-reference" style="letter-spacing:0.08em;"><?= h($info['reference']) ?></strong>
            <button type="button" class="btn-small" data-copy="sepa-reference" style="margin-left:8px;">Copier</button>
          </div>
        </div>
        <?php if ($info['note'] !== ''): ?>
          <p style="margin:16px 0 0;font-size:0.88rem;color:var(--ink-soft);"><?= nl2br(h($info['note'])) ?></p>
        <?php endif; ?>
        <p style="margin:16px 0 0;font-size:0.88rem;">La commande sera préparée dès réception du virement. Sans virement avant le <?= h($info['deadline']->format('d/m/Y')) ?>, elle sera annulée et les pièces remises en vente.</p>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:22px;">
        <button type="button" class="btn btn-ghost" onclick="window.print()">Imprimer ces coordonnées</button>
        <a class="btn btn-ghost" href="/boutique.php">← Retour à la boutique</a>
      </div>

      <div class="admin-block" style="margin-top:26px;">
        <h2 style="font-size:1.05rem;margin:0 0 12px;">Récapitulatif</h2>
        <?php foreach (json_decode((string) $order['items'], true) ?: [] as $item): ?>
          <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);">
            <span><?= h($item['qty']) ?> × <?= h($item['name']) ?></span>
            <span class="price"><?= format_cents((int) $item['unit_cents'] * (int) $item['qty']) ?></span>
          </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
          <span><?= order_is_pickup($order) ? 'Retrait sur place' : 'Livraison' ?></span>
          <span class="price"><?= (int) $order['shipping_cents'] > 0 ? format_cents((int) $order['shipping_cents']) : 'Gratuit' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding-top:14px;font-weight:600;">
          <span>Total à virer</span>
          <span class="price"><?= format_cents((int) $order['amount_total']) ?></span>
        </div>
      </div>

    <?php else: ?>
      <p class="eyebrow">Commande</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Paiement non confirmé</h1>
      <p class="lede">Cette commande ne peut pas être affichée pour le moment.</p>
      <a class="btn btn-ghost" href="/cart.php" style="margin-top:20px;">← Retour au panier</a>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy'));
      if (!target) return;
      var text = target.textContent.trim();
      var done = function () {
        var old = button.textContent;
        button.textContent = 'Copié';
        setTimeout(function () { button.textContent = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {});
      } else {
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(area);
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
