<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

// Virements SEPA non reçus au-delà du délai réglé : annulés et pièces remises en vente.
try {
    order_expire_pending_transfers();
} catch (Throwable $e) {
    error_log('expiration virements : ' . $e->getMessage());
}
// Demandes de disponibilité dont le délai de réponse ou de paiement est passé.
try {
    oa_sweep();
} catch (Throwable $e) {
    error_log('expiration disponibilité : ' . $e->getMessage());
}

$orders = db()->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();
$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$statusLabels = ['paid' => 'Payée', 'pending' => 'En attente', 'awaiting' => 'À valider', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée'];
$eventLabels = ['paiement' => 'Paiement', 'paiement_echec' => 'Paiement non abouti', 'remboursement' => 'Remboursement', 'remboursement_echec' => 'Remboursement refusé',
    'remboursement_virement' => 'Virement de remboursement', 'stock_reserve' => 'Stock réservé',
    'litige' => 'Litige', 'annulee' => 'Annulation', 'pret_retrait' => 'Prêt au retrait', 'retiree' => 'Retrait',
    'expediee' => 'Expédition', 'livree' => 'Livraison', 'suivi' => 'Suivi', 'stock_restored' => 'Stock',
    'dispo_notification' => 'Vendeur prévenu', 'dispo_notification_echec' => 'Vendeur non prévenu',
    'dispo_confirmee' => 'Disponibilité confirmée', 'dispo_refusee' => 'Pièce vendue',
    'dispo_annulee_client' => 'Annulée par le client', 'dispo_expiree' => 'Délai dépassé',
    'dispo_convertie' => 'Paiement effectué'];
$awaitingOrders = array_values(array_filter($orders, static fn ($o) => (string) $o['status'] === 'awaiting' && (string) ($o['availability_status'] ?? '') === 'pending'));

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Commandes — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .order-fulfillment { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
  .order-fulfillment select, .order-fulfillment input[type="text"], .order-fulfillment input[type="number"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 6px 8px; font-family: var(--font-body); font-size: 0.82rem;
  }
  .order-fulfillment input[type="text"] { width: 160px; }
  .order-fulfillment input[name="refund_amount"] { width: 100px; }
  .order-mode { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border: 1px solid var(--line); border-radius: 999px; font-size: 0.78rem; margin: 8px 4px 0 0; }
  .order-mode[data-mode="pickup"] { border-color: var(--sage); color: var(--sage); }
  .order-mode[data-mode="shipping"] { border-color: var(--accent); color: var(--accent); }
  .order-alert { padding: 8px 10px; border-left: 3px solid #b3261e; background: rgba(179, 38, 30, 0.06); font-size: 0.82rem; margin-top: 8px; }
  .order-journal { margin: 8px 0 0; padding-left: 18px; font-size: 0.78rem; color: var(--ink-soft); }
  .order-journal li { margin: 2px 0; }
  .order-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'commandes'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:2rem;margin:12px 0 30px;">Commandes (<?= count($orders) ?>)</h1>

    <?php if (!shop_checkout_ready()): ?>
      <p class="publish-status" data-kind="error" style="margin-bottom:24px;">Aucun moyen de paiement configuré — aucune commande ne pourra être encaissée tant que Stripe, Monetico, PayPal, Revolut ou un IBAN de virement SEPA n’est pas réglé.</p>
    <?php endif; ?>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if ($awaitingOrders): ?>
      <p class="publish-status" data-kind="error" style="margin-bottom:24px;">
        <?= count($awaitingOrders) ?> demande<?= count($awaitingOrders) > 1 ? 's' : '' ?> de disponibilité en attente : le client ne peut pas payer tant que vous n'avez pas répondu.
      </p>
    <?php endif; ?>

    <?php if (!$orders): ?>
      <p class="empty-state">Aucune commande pour l'instant.</p>
    <?php else: ?>
      <div class="admin-block">
        <div class="product-admin-list">
          <?php foreach ($orders as $o):
            $pickup = order_is_pickup($o);
            $modeLabel = $pickup ? 'Retrait sur place' : 'Expédition';
            if (!$pickup && !empty($o['shipping_label'])) $modeLabel .= ' — ' . $o['shipping_label'];
            $remaining = order_remaining_refund($o);
            $events = order_events_for((int) $o['id'], 8);
            $provider = payment_provider_of($o);
            $providerLabel = $o['status'] === 'awaiting' ? 'Paiement après validation' : payment_provider_label($provider);
            $availabilityStatus = (string) ($o['availability_status'] ?? '');
          ?>
            <div class="product-admin-row" style="grid-template-columns:1fr auto;">
              <div>
                <p class="ref">#<?= (int) $o['id'] ?> — <?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?> — <?= h($o['email'] ?: 'e-mail non fourni') ?></p>
                <?php if (!empty($o['ship_name'])): ?><p style="margin:2px 0 0;font-size:0.85rem;">🎁 Livrer à : <strong><?= h($o['ship_name']) ?></strong><?= !empty($o['ship_phone']) ? ' — <a href="tel:' . h($o['ship_phone']) . '">' . h(function_exists('customer_phone_display') ? customer_phone_display((string) $o['ship_phone']) : $o['ship_phone']) . '</a>' : '' ?> <span style="color:var(--ink-soft);">(différent du client)</span></p><?php endif; ?>
                <?php if (!empty($o['phone'])): ?><p style="margin:2px 0 0;font-size:0.85rem;">📞 <a href="tel:<?= h($o['phone']) ?>"><?= h(function_exists('customer_phone_display') ? customer_phone_display((string) $o['phone']) : $o['phone']) ?></a><?= !empty($o['terms_at']) ? ' <span style="color:var(--ink-soft);">· conditions acceptées le ' . h(date('d/m/Y H:i', strtotime($o['terms_at']))) . '</span>' : '' ?></p><?php endif; ?>
                <?php if (!empty($o['buyer_address']) && ($pickup || !empty($o['ship_name']))): ?><p style="margin:2px 0 0;font-size:0.85rem;">👤 Adresse de l'acheteur : <?= h($o['buyer_address']) ?></p><?php endif; ?>
                <p style="margin:6px 0;">
                  <?php foreach (json_decode($o['items'], true) ?: [] as $item): ?>
                    <?= h($item['qty']) ?> × <?= h($item['name']) ?><br>
                  <?php endforeach; ?>
                </p>
                <?php if (!empty($o['gift_wrap'])): ?>
                  <p style="margin:2px 0;font-size:0.85rem;">🎁 Emballage cadeau<?= (int) ($o['gift_wrap_cents'] ?? 0) > 0 ? ' — ' . format_cents((int) $o['gift_wrap_cents']) : '' ?></p>
                  <?php if (trim((string) ($o['gift_message'] ?? '')) !== ''): ?><p style="margin:2px 0;font-size:0.82rem;color:var(--ink-soft);">Petit mot : « <?= h($o['gift_message']) ?> »</p><?php endif; ?>
                <?php endif; ?>
                <span class="badge" style="color:<?= in_array($o['status'], ['paid', 'refunded'], true) ? 'var(--sage)' : (in_array($o['status'], ['pending', 'awaiting'], true) ? 'var(--accent)' : 'var(--ink-soft)') ?>;border-color:currentColor;"><?= h($statusLabels[$o['status']] ?? $o['status']) ?></span>
                <?php if (in_array($o['status'], ['paid', 'refunded'], true)): ?>
                  <span class="badge" style="margin-left:6px;"><?= h(fulfillment_label((string) $o['fulfillment_status'])) ?></span>
                <?php endif; ?>
                <span class="badge" style="margin-left:6px;"><?= h($providerLabel) ?></span>
                <?php if (!empty($o['payment_detail'])): ?><span style="font-size:0.78rem;color:var(--ink-soft);margin-left:6px;"><?= h($o['payment_detail']) ?></span><?php endif; ?>
                <span class="order-mode" data-mode="<?= $pickup ? 'pickup' : 'shipping' ?>"><?= $pickup ? '🏬' : '🚚' ?> <?= h($modeLabel) ?></span>

                <?php if ($pickup && !empty($o['pickup_code'])): ?>
                  <p style="margin:8px 0 0;font-size:0.85rem;">Code de retrait : <strong style="letter-spacing:0.12em;"><?= h($o['pickup_code']) ?></strong>
                    <?php if (!empty($o['pickup_deadline'])): ?><span style="color:var(--ink-soft);"> · à retirer avant le <?= h(date('d/m/Y', strtotime($o['pickup_deadline']))) ?></span><?php endif; ?>
                    <?php if (!empty($o['picked_up_at'])): ?><span style="color:var(--sage);"> · retirée le <?= h(date('d/m/Y H:i', strtotime($o['picked_up_at']))) ?><?= !empty($o['picked_up_by']) ? ' par ' . h($o['picked_up_by']) : '' ?></span><?php endif; ?>
                  </p>
                <?php endif; ?>

                <?php if (!empty($o['shipping_address']) && !$pickup): ?>
                  <p style="margin-top:8px;font-size:0.82rem;color:var(--ink-soft);">📦 <?= h($o['shipping_address']) ?></p>
                <?php elseif (!empty($o['declared_address']) && $o['status'] === 'awaiting'): ?>
                  <p style="margin-top:8px;font-size:0.82rem;color:var(--ink-soft);">📦 <?= h($o['declared_address']) ?></p>
                <?php endif; ?>
                <?php if (!empty($o['tracking_number']) && !$pickup): ?>
                  <p style="margin-top:4px;font-size:0.82rem;color:var(--ink-soft);">N° de suivi : <?= h($o['tracking_number']) ?></p>
                <?php endif; ?>
                <?php if ((int) ($o['refunded_cents'] ?? 0) > 0): ?>
                  <p style="margin-top:6px;font-size:0.82rem;color:var(--accent);">Remboursé : <?= format_cents((int) $o['refunded_cents']) ?> sur <?= format_cents((int) $o['amount_total']) ?><?= !empty($o['refunded_at']) ? ' le ' . h(date('d/m/Y', strtotime($o['refunded_at']))) : '' ?></p>
                <?php endif; ?>
                <?php if (!empty($o['dispute_status'])): ?>
                  <p class="order-alert">⚠️ Litige Stripe : <strong><?= h($o['dispute_status']) ?></strong><?= !empty($o['dispute_id']) ? ' (' . h($o['dispute_id']) . ')' : '' ?>. Répondez depuis le tableau de bord Stripe.</p>
                <?php endif; ?>

                <?php if ($availabilityStatus !== ''): ?>
                  <p style="margin:8px 0 0;font-size:0.85rem;">
                    <strong>Disponibilité :</strong> <?= h(oa_status_label($availabilityStatus)) ?>
                    <?php if (!empty($o['availability_channel'])): ?><span style="color:var(--ink-soft);"> · vendeur prévenu par <?= h(oa_channel_label((string) $o['availability_channel'])) ?></span><?php endif; ?>
                    <?php if (!empty($o['availability_deadline']) && in_array($availabilityStatus, ['available', 'expired'], true)): ?><span style="color:var(--ink-soft);"> · paiement avant le <?= h(date('d/m/Y H:i', strtotime((string) $o['availability_deadline']))) ?></span><?php endif; ?>
                    <?php if (!empty($o['availability_order_id']) && $availabilityStatus === 'converted'): ?><span style="color:var(--sage);"> · commande n° <?= (int) $o['availability_order_id'] ?></span><?php endif; ?>
                  </p>
                  <?php if ($o['status'] === 'awaiting' && in_array($availabilityStatus, ['pending', 'available'], true)): ?>
                    <div class="order-actions">
                      <?php if ($availabilityStatus === 'pending'): ?>
                        <form method="post" action="/admin/update-order.php" style="margin:0;">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="availability_confirm">
                          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                          <button type="submit" class="btn-small">La pièce est disponible</button>
                        </form>
                      <?php else: ?>
                        <form method="post" action="/admin/update-order.php" style="margin:0;">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="availability_resend_buyer">
                          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                          <button type="submit" class="btn-small">Renvoyer le lien de paiement</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="/admin/update-order.php" style="margin:0;" onsubmit="return confirm('Indiquer que la pièce a été vendue ? Le client sera prévenu et rien ne sera prélevé.');">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="availability_refuse">
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn-small">Pièce déjà vendue</button>
                      </form>
                      <form method="post" action="/admin/update-order.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="availability_notify_seller">
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn-small">Me renvoyer l'alerte</button>
                      </form>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>

                <?php if ($o['status'] === 'paid'): ?>
                  <form method="post" action="/admin/update-order.php" class="order-fulfillment">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="fulfillment">
                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <select name="fulfillment_status">
                      <?php foreach (order_fulfillment_steps($o) as $fs => $fsLabel): ?>
                        <option value="<?= h($fs) ?>"<?= $o['fulfillment_status'] === $fs ? ' selected' : '' ?>><?= h($fsLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (!$pickup): ?>
                      <input type="text" name="tracking_number" value="<?= h($o['tracking_number']) ?>" placeholder="N° de suivi (optionnel)">
                    <?php endif; ?>
                    <button type="submit" class="btn-small">Mettre à jour</button>
                  </form>

                  <?php if ($pickup && in_array($o['fulfillment_status'], ['pret_retrait', 'a_confirmer', 'a_preparer'], true) && empty($o['picked_up_at'])): ?>
                    <form method="post" action="/admin/update-order.php" class="order-fulfillment">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="fulfillment">
                      <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                      <input type="hidden" name="fulfillment_status" value="retiree">
                      <input type="text" name="pickup_code" placeholder="Code du client" required style="width:130px;text-transform:uppercase;">
                      <input type="text" name="picked_up_by" placeholder="Remis à (optionnel)" style="width:150px;">
                      <button type="submit" class="btn-small">Valider le retrait</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($remaining > 0): ?>
                    <form method="post" action="/admin/update-order.php" class="order-fulfillment" onsubmit="return confirm('<?= $provider === 'sepa' ? 'Enregistrer ce remboursement ? Le virement de retour est à effectuer depuis votre banque.' : 'Confirmer le remboursement via ' . h($providerLabel) . ' ?' ?>');">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="refund">
                      <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                      <input type="number" name="refund_amount" min="0.01" max="<?= number_format($remaining / 100, 2, '.', '') ?>" step="0.01" value="<?= number_format($remaining / 100, 2, '.', '') ?>" aria-label="Montant à rembourser">
                      <input type="text" name="refund_reason" placeholder="Motif (facultatif)">
                      <label style="font-size:0.8rem;display:inline-flex;align-items:center;gap:4px;"><input type="checkbox" name="restock" value="1"> Remettre en stock</label>
                      <button type="submit" class="btn-small"><?= $provider === 'sepa' ? 'Enregistrer le remboursement' : 'Rembourser' ?></button>
                    </form>
                    <?php if ($provider === 'sepa'): ?>
                      <p style="margin:4px 0 0;font-size:0.78rem;color:var(--ink-soft);">Virement SEPA : le montant n’est pas prélevé automatiquement. Le remboursement est noté ici ; effectuez le virement de retour depuis votre banque, puis conservez la référence affichée dans le journal.</p>
                    <?php endif; ?>
                  <?php endif; ?>

                  <div class="order-actions">
                    <form method="post" action="/admin/update-order.php" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="resend_confirmation">
                      <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                      <button type="submit" class="btn-small">Renvoyer le récapitulatif</button>
                    </form>
                    <?php if ($provider === 'stripe'): ?>
                      <?php if (!empty($o['stripe_payment_intent'])): ?>
                        <a href="https://dashboard.stripe.com/<?= ($o['payment_via'] ?? '') === 'connect' ? '' : 'test/' ?>payments/<?= h($o['stripe_payment_intent']) ?>" target="_blank" rel="noopener" style="font-size:0.78rem;color:var(--accent);">Voir le paiement dans Stripe →</a>
                      <?php else: ?>
                        <a href="https://dashboard.stripe.com/test/checkout/sessions/<?= h($o['stripe_session_id']) ?>" target="_blank" rel="noopener" style="font-size:0.78rem;color:var(--accent);">Voir dans Stripe →</a>
                      <?php endif; ?>
                    <?php elseif ($provider === 'paypal' && !empty($o['provider_payment_id'])): ?>
                      <span style="font-size:0.78rem;color:var(--ink-soft);">Capture PayPal : <?= h($o['provider_payment_id']) ?></span>
                    <?php elseif ($provider === 'monetico'): ?>
                      <span style="font-size:0.78rem;color:var(--ink-soft);">Référence Monetico : <?= h((string) ($o['payment_reference'] ?: $o['provider_checkout_id'])) ?><?= !empty($o['payment_authorization']) ? ' · autorisation ' . h($o['payment_authorization']) : '' ?></span>
                    <?php elseif ($provider === 'revolut' && !empty($o['provider_payment_id'])): ?>
                      <span style="font-size:0.78rem;color:var(--ink-soft);">Paiement Revolut : <?= h($o['provider_payment_id']) ?></span>
                    <?php elseif ($provider === 'sepa'): ?>
                      <span style="font-size:0.78rem;color:var(--ink-soft);">Virement SEPA — référence <?= h((string) ($o['payment_reference'] ?: 'B' . (int) $o['id'])) ?><?= !empty($o['payment_date']) ? ' · reçu le ' . h(date('d/m/Y', strtotime($o['payment_date']))) : '' ?></span>
                    <?php endif; ?>
                  </div>
                <?php elseif ($o['status'] === 'pending'): ?>
                  <div class="order-actions">
                    <?php if ($provider === 'stripe' && shop_checkout_ready()): ?>
                      <form method="post" action="/admin/update-order.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="refresh_stripe">
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn-small">Vérifier auprès de Stripe</button>
                      </form>
                    <?php elseif ($provider === 'paypal'): ?>
                      <form method="post" action="/admin/update-order.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="refresh_paypal">
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn-small">Vérifier auprès de PayPal</button>
                      </form>
                    <?php elseif ($provider === 'monetico'): ?>
                      <span style="font-size:0.78rem;color:var(--ink-soft);">Monetico confirme le paiement via son URL de confirmation ; vérifiez aussi le back-office de la banque.</span>
                    <?php elseif ($provider === 'revolut'): ?>
                      <form method="post" action="/admin/update-order.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="refresh_revolut">
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn-small">Vérifier auprès de Revolut</button>
                      </form>
                    <?php elseif ($provider === 'sepa'): ?>
                      <form method="post" action="/admin/update-order.php" style="margin:0;" onsubmit="return confirm('Confirmer que le virement de <?= h(format_cents((int) $o['amount_total'])) ?> (référence <?= h((string) ($o['payment_reference'] ?: 'B' . (int) $o['id'])) ?>) a bien été reçu sur votre compte ?');">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="mark_sepa_paid">
                        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                        <button type="submit" class="btn-small">Marquer le virement reçu</button>
                      </form>
                      <span style="font-size:0.78rem;color:var(--ink-soft);">À virer : <?= format_cents((int) $o['amount_total']) ?> — référence <?= h((string) ($o['payment_reference'] ?: 'B' . (int) $o['id'])) ?><?= !empty($o['payment_deadline']) ? ' · avant le ' . h(date('d/m/Y', strtotime($o['payment_deadline']))) : '' ?>.</span>
                    <?php endif; ?>
                    <form method="post" action="/admin/update-order.php" style="margin:0;" onsubmit="return confirm('Annuler cette commande en attente ?');">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                      <input type="hidden" name="cancel_reason" value="Annulée depuis l'administration">
                      <button type="submit" class="btn-small">Annuler</button>
                    </form>
                  </div>
                <?php endif; ?>

                <?php if ($events): ?>
                  <details style="margin-top:10px;">
                    <summary style="font-size:0.78rem;color:var(--ink-soft);cursor:pointer;">Journal (<?= count($events) ?>)</summary>
                    <ul class="order-journal">
                      <?php foreach ($events as $e): ?>
                        <li><?= h(date('d/m/Y H:i', strtotime($e['created_at']))) ?> — <strong><?= h($eventLabels[$e['type']] ?? (str_starts_with((string) $e['type'], 'email_') ? 'E-mail' : $e['type'])) ?></strong><?= $e['detail'] !== '' ? ' : ' . h($e['detail']) : '' ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                <?php endif; ?>
              </div>
              <div style="text-align:right;">
                <span class="price"><?= format_cents((int) $o['amount_total']) ?></span>
                <?php if ((int) $o['shipping_cents'] > 0): ?>
                  <p style="font-size:0.78rem;color:var(--ink-soft);margin-top:4px;">dont <?= format_cents((int) $o['shipping_cents']) ?> de port</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
