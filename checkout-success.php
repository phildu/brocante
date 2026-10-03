<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

$sessionId = (string) ($_GET['session_id'] ?? '');
$order = null;
$error = null;

if ($sessionId === '') {
    $error = "Aucune commande à afficher.";
} else {
    try {
        $session = stripe_request('GET', '/checkout/sessions/' . urlencode($sessionId));
        $paid = ($session['payment_status'] ?? '') === 'paid';

        $stmt = db()->prepare('SELECT * FROM orders WHERE stripe_session_id = ?');
        $stmt->execute([$sessionId]);
        $order = $stmt->fetch();

        if ($paid && $order && $order['status'] !== 'paid') {
            $shippingCents = (int) ($session['shipping_cost']['amount_total'] ?? 0);
            $shippingAddr = $session['shipping_details']['address'] ?? $session['customer_details']['address'] ?? null;
            $addressText = $shippingAddr ? implode(', ', array_filter([
                $session['shipping_details']['name'] ?? null,
                $shippingAddr['line1'] ?? null,
                $shippingAddr['line2'] ?? null,
                trim(($shippingAddr['postal_code'] ?? '') . ' ' . ($shippingAddr['city'] ?? '')),
                $shippingAddr['country'] ?? null,
            ])) : null;

            $upd = db()->prepare('UPDATE orders SET status = ?, email = ?, amount_total = ?, shipping_cents = ?, shipping_address = ? WHERE stripe_session_id = ?');
            $upd->execute([
                'paid',
                $session['customer_details']['email'] ?? null,
                (int) ($session['amount_total'] ?? $order['amount_total']),
                $shippingCents,
                $addressText,
                $sessionId,
            ]);
            // Le client rejoint la liste des comptes (Administration → Comptes) ; un prospect inscrit à la newsletter passe en client.
            account_upsert_contact(
                (string) ($session['customer_details']['email'] ?? ''),
                (string) ($session['customer_details']['name'] ?? $session['shipping_details']['name'] ?? ''),
                'client',
                'commande'
            );
            $order['status'] = 'paid';
            $order['email'] = $session['customer_details']['email'] ?? null;
            $order['amount_total'] = (int) ($session['amount_total'] ?? $order['amount_total']);
            $order['shipping_cents'] = $shippingCents;
            $order['shipping_address'] = $addressText;
            decrement_stock_for_order(json_decode($order['items'], true) ?: []);
            cart_clear();
        } elseif (!$paid) {
            $error = "Le paiement n'a pas été confirmé pour cette commande.";
        }
    } catch (Throwable $e) {
        $error = "Impossible de vérifier cette commande : " . $e->getMessage();
    }
}

$activeNav = '';
$pageTitle = 'Commande';
include __DIR__ . '/includes/header.php';
?>

<section class="tight">
  <div class="wrap" style="max-width:640px;">
    <?php if ($order && $order['status'] === 'paid'): ?>
      <p class="eyebrow">Merci !</p>
      <h1 style="font-size:1.9rem;margin:12px 0 16px;">Votre commande est confirmée</h1>
      <p class="lede">Un récapitulatif a été envoyé à <?= h($order['email'] ?? 'votre adresse e-mail') ?>. Nous préparons votre paquet avec soin.</p>
      <div class="admin-block" style="margin-top:28px;">
        <?php foreach (json_decode($order['items'], true) as $item): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
            <span><?= h($item['qty']) ?> × <?= h($item['name']) ?></span>
            <span class="price"><?= format_cents($item['unit_cents'] * $item['qty']) ?></span>
          </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);">
          <span>Livraison</span>
          <span class="price"><?= $order['shipping_cents'] > 0 ? format_cents((int) $order['shipping_cents']) : 'Offerte' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding-top:16px;font-weight:600;">
          <span>Total</span>
          <span class="price"><?= format_cents((int) $order['amount_total']) ?></span>
        </div>
        <?php if (!empty($order['shipping_address'])): ?>
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
