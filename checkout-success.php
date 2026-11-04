<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/recommendations.php';

if (!shop_sales_enabled()) {
    header('Location: /boutique.php', true, 302);
    exit;
}

$sessionId = (string) ($_GET['session_id'] ?? '');
$orderId = (int) ($_GET['order_id'] ?? 0);
$sig = (string) ($_GET['sig'] ?? '');
$order = null;
$error = null;

if ($sessionId === '' && $orderId > 0) {
    // Commande payée chez Monetico ou PayPal : le lien signé remplace la
    // session (Monetico revient par un POST sans cookie SameSite=Lax).
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $candidate = $stmt->fetch();
    if (!$candidate) {
        $error = 'Commande introuvable.';
    } else {
        $provider = payment_provider_of($candidate);
        if ($provider === 'stripe' || !payment_return_token_valid($orderId, $provider, $sig)) {
            $error = "Ce lien de commande n'est pas valide.";
        } elseif ($candidate['status'] !== 'paid') {
            $error = "Le paiement n'a pas encore été confirmé par " . payment_provider_label($provider) . ". Si vous venez de payer, patientez un instant puis rechargez cette page.";
        } else {
            $order = $candidate;
            if (in_array($orderId, checkout_own_orders(), true)) cart_clear();
        }
    }
} elseif ($sessionId === '') {
    $error = "Aucune commande à afficher.";
} else {
    try {
        $stmt = db()->prepare('SELECT * FROM orders WHERE stripe_session_id = ?');
        $stmt->execute([$sessionId]);
        $order = $stmt->fetch();
        $session = stripe_request('GET', '/checkout/sessions/' . urlencode($sessionId), [], ($order['payment_via'] ?? '') === 'connect');
        $paid = ($session['payment_status'] ?? '') === 'paid';

        if ($paid && $order && $order['status'] !== 'paid') {
            if (order_mark_paid($order, $session)) cart_clear();
            $stmt->execute([$sessionId]);
            $order = $stmt->fetch();
        } elseif (!$paid) {
            $error = "Le paiement n'a pas été confirmé pour cette commande.";
        }
    } catch (Throwable $e) {
        $error = "Impossible de vérifier cette commande : " . $e->getMessage();
    }
}

$activeNav = '';
$pageTitle = 'Commande';
// Vente réglée dans la boutique (QR de la fiche ou caisse) : la pièce est remise sur place.
$inShop = $order && str_starts_with((string) ($order['shipping_method'] ?? ''), 'Vente en boutique');
$shopQrRef = '';
if ($inShop) {
    $firstItem = json_decode((string) $order['items'], true)[0] ?? [];
    $shopQrRef = (string) ($firstItem['ref'] ?? '');
}
if ($order && $order['status'] === 'paid') rec_track_order($order);
include __DIR__ . '/includes/header.php';
?>

<section class="tight">
  <div class="wrap" style="max-width:640px;">
    <?php if ($order && $order['status'] === 'paid'): ?>
      <p class="eyebrow"><?= $inShop ? 'Paiement en boutique' : 'Merci !' ?></p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;"><?= $inShop ? 'Paiement reçu' : 'Votre commande est confirmée' ?></h1>
      <?php if ($inShop): ?>
        <p class="lede">Merci ! Votre règlement est confirmé. Montrez cette page au vendeur : il vous remet la pièce sur place.<?php if (!empty($order['email'])): ?> Un reçu a été envoyé à <?= h($order['email']) ?>.<?php endif; ?></p>
      <?php else: ?>
        <p class="lede">Un récapitulatif a été envoyé à <?= h($order['email'] ?? 'votre adresse e-mail') ?>. Nous préparons votre paquet avec soin.</p>
      <?php endif; ?>
      <div class="admin-block" style="margin-top:28px;">
        <?php foreach (json_decode($order['items'], true) as $item): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
            <span><?= h($item['qty']) ?> × <?= h($item['name']) ?></span>
            <span class="price"><?= format_cents($item['unit_cents'] * $item['qty']) ?></span>
          </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
          <span><?= $inShop ? 'Paiement en boutique' : (order_is_pickup($order) ? 'Retrait sur place' : 'Livraison') ?></span>
          <span class="price"><?= $inShop ? 'Réglé' : ($order['shipping_cents'] > 0 ? format_cents((int) $order['shipping_cents']) : 'Gratuit') ?></span>
        </div>
        <?php if (!$inShop && !empty($order['gift_wrap'])): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
            <span>Emballage cadeau</span>
            <span class="price"><?= (int) ($order['gift_wrap_cents'] ?? 0) > 0 ? format_cents((int) $order['gift_wrap_cents']) : 'Offert' ?></span>
          </div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;padding-top:16px;font-weight:600;">
          <span>Total</span>
          <span class="price"><?= format_cents((int) $order['amount_total']) ?></span>
        </div>
        <?php if (!$inShop && !empty($order['gift_wrap']) && trim((string) ($order['gift_message'] ?? '')) !== ''): ?>
          <p style="margin:10px 0 0;font-size:0.85rem;color:var(--ink-soft);">Petit mot : « <?= h($order['gift_message']) ?> »</p>
        <?php endif; ?>
        <?php if ($inShop): ?>
          <div style="margin-top:18px;padding:12px 14px;border:1px solid var(--line);">
            <p style="margin:0;font-size:0.95rem;"><strong>Paiement reçu.</strong> La pièce est marquée « Vendue » et vous est remise sur place.</p>
            <p style="margin:6px 0 0;font-size:0.82rem;color:var(--ink-soft);">Le vendeur peut vérifier ce paiement dans son administration.</p>
          </div>
        <?php elseif (order_is_pickup($order)): ?>
          <div style="margin-top:18px;padding:12px 14px;border:1px solid var(--line);">
            <p style="margin:0;font-size:0.85rem;">Retrait sur place<?= !empty($order['shipping_label']) ? ' — ' . h($order['shipping_label']) : '' ?></p>
            <?php if (!empty($order['pickup_code'])): ?>
              <p style="margin:6px 0 0;font-size:0.95rem;">Code de retrait : <strong style="letter-spacing:0.14em;"><?= h($order['pickup_code']) ?></strong></p>
            <?php endif; ?>
            <p style="margin:6px 0 0;font-size:0.82rem;color:var(--ink-soft);">Nous vous préviendrons dès que la commande sera prête. Présentez ce code au vendeur.</p>
          </div>
        <?php elseif (!empty($order['shipping_address'])): ?>
          <p style="margin-top:18px;font-size:0.85rem;color:var(--ink-soft);">Expédition à : <?= h($order['shipping_address']) ?></p>
        <?php endif; ?>
      </div>
      <a class="btn btn-ghost" href="/boutique.php" style="margin-top:28px;">← Retour à la boutique</a>
    <?php else: ?>
      <p class="eyebrow">Commande</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Paiement non confirmé</h1>
      <p class="lede"><?= h($error ?? "Cette commande n'a pas pu être vérifiée.") ?></p>
      <a class="btn btn-ghost" href="/cart.php" style="margin-top:20px;">← Retour au panier</a>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
