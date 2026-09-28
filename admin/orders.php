<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$orders = db()->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();
$content = get_content();
$flash = flash_get();
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
  .order-fulfillment select, .order-fulfillment input[type="text"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 6px 8px; font-family: var(--font-body); font-size: 0.82rem;
  }
  .order-fulfillment input[type="text"] { width: 160px; }
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

    <?php if (!stripe_configured()): ?>
      <p class="publish-status" data-kind="error" style="margin-bottom:24px;">Clé Stripe absente — aucune commande ne pourra être encaissée tant que config.php n'est pas complété.</p>
    <?php endif; ?>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if (!$orders): ?>
      <p class="empty-state">Aucune commande pour l'instant.</p>
    <?php else: ?>
      <div class="admin-block">
        <div class="product-admin-list">
          <?php foreach ($orders as $o): ?>
            <div class="product-admin-row" style="grid-template-columns:1fr auto;">
              <div>
                <p class="ref"><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?> — <?= h($o['email'] ?: 'e-mail non fourni') ?></p>
                <p style="margin:6px 0;">
                  <?php foreach (json_decode($o['items'], true) ?: [] as $item): ?>
                    <?= h($item['qty']) ?> × <?= h($item['name']) ?><br>
                  <?php endforeach; ?>
                </p>
                <span class="badge" style="color:<?= $o['status'] === 'paid' ? 'var(--sage)' : 'var(--accent)' ?>;border-color:currentColor;"><?= h($o['status'] === 'paid' ? 'Payée' : 'En attente') ?></span>
                <?php if ($o['status'] === 'paid'): ?>
                  <span class="badge" style="margin-left:6px;"><?= h(fulfillment_label($o['fulfillment_status'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($o['shipping_address'])): ?>
                  <p style="margin-top:8px;font-size:0.82rem;color:var(--ink-soft);">📦 <?= h($o['shipping_address']) ?></p>
                <?php elseif ((int) $o['shipping_cents'] === 0 && $o['status'] === 'paid'): ?>
                  <p style="margin-top:8px;font-size:0.82rem;color:var(--ink-soft);">Retrait à l'atelier</p>
                <?php endif; ?>
                <?php if (!empty($o['tracking_number'])): ?>
                  <p style="margin-top:4px;font-size:0.82rem;color:var(--ink-soft);">N° de suivi : <?= h($o['tracking_number']) ?></p>
                <?php endif; ?>

                <?php if ($o['status'] === 'paid'): ?>
                  <form method="post" action="/admin/update-order.php" class="order-fulfillment">
                    <input type="hidden" name="action" value="fulfillment">
                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <select name="fulfillment_status">
                      <?php foreach (['a_preparer', 'expediee', 'livree'] as $fs): ?>
                        <option value="<?= $fs ?>"<?= $o['fulfillment_status'] === $fs ? ' selected' : '' ?>><?= h(fulfillment_label($fs)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="text" name="tracking_number" value="<?= h($o['tracking_number']) ?>" placeholder="N° de suivi (optionnel)">
                    <button type="submit" class="btn-small">Mettre à jour</button>
                  </form>
                <?php elseif (stripe_configured()): ?>
                  <form method="post" action="/admin/update-order.php" class="order-fulfillment">
                    <input type="hidden" name="action" value="refresh_stripe">
                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn-small">Vérifier auprès de Stripe</button>
                  </form>
                <?php endif; ?>

                <p style="margin-top:8px;">
                  <a href="https://dashboard.stripe.com/test/checkout/sessions/<?= h($o['stripe_session_id']) ?>" target="_blank" rel="noopener" style="font-size:0.78rem;color:var(--accent);">Voir dans Stripe →</a>
                </p>
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
