<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

$q = trim((string) ($_GET['q'] ?? ''));
$etat = in_array($_GET['etat'] ?? '', ['paid', 'pending'], true) ? (string) $_GET['etat'] : '';

$where = [];
$params = [];
if ($etat !== '') {
    $where[] = 'status = ?';
    $params[] = $etat;
}
if ($q !== '') {
    $where[] = '(email LIKE ? OR ship_name LIKE ? OR stripe_session_id LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$stmt = db()->prepare('SELECT * FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, id DESC LIMIT 300');
$stmt->execute($params);
$orders = $stmt->fetchAll();
$events = vel_order_events(array_column($orders, 'id'));
$flash = flash_get();

$paidCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
$paidSum = (int) db()->query("SELECT COALESCE(SUM(amount_total), 0) FROM orders WHERE status = 'paid'")->fetchColumn();
$pendingCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$prepCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND fulfillment_status = 'a_preparer'")->fetchColumn();
$sentCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND fulfillment_status = 'expediee'")->fetchColumn();
$deliveredCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND fulfillment_status = 'livree'")->fetchColumn();

$dashBase = str_starts_with(STRIPE_SECRET_KEY, 'sk_live') || str_starts_with(STRIPE_SECRET_KEY, 'rk_live')
    ? 'https://dashboard.stripe.com/checkout/sessions/'
    : 'https://dashboard.stripe.com/test/checkout/sessions/';
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Commandes</h1>
  <p class="hint" style="max-width:75ch;">
    Toutes les commandes payées en ligne, avec leur suivi. Le paiement est vérifié à la confirmation et par le bouton
    « Vérifier auprès de Stripe » (aucun webhook) ; le suivi se change ici ou dans <a href="<?= h(vel_page_url($plugin, 'suivi')) ?>">Suivi des envois</a>.
  </p>

  <?php if (!$flash && !shop_checkout_ready()): ?>
    <p class="publish-status" data-kind="error" style="margin:14px 0;">Aucune clé Stripe valide : aucune commande ne peut être encaissée. <a href="<?= h(vel_page_url($plugin, 'paiement')) ?>">Configurer le paiement</a>.</p>
  <?php endif; ?>
  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <div class="vel-kpis">
    <div class="vel-kpi"><span>CA payé</span><strong><?= h(format_cents($paidSum)) ?></strong></div>
    <div class="vel-kpi"><span>Commandes payées</span><strong><?= $paidCount ?></strong></div>
    <div class="vel-kpi"><span>À préparer</span><strong><?= $prepCount ?></strong></div>
    <div class="vel-kpi"><span>Expédiées</span><strong><?= $sentCount ?></strong></div>
    <div class="vel-kpi"><span>Livrées</span><strong><?= $deliveredCount ?></strong></div>
    <?php if ($pendingCount): ?><div class="vel-kpi"><span>En attente</span><strong><?= $pendingCount ?></strong></div><?php endif; ?>
  </div>

  <nav class="vel-tabs" aria-label="Filtrer les commandes">
    <a href="<?= h(vel_page_url($plugin, 'commandes', $q !== '' ? ['q' => $q] : [])) ?>"<?= $etat === '' ? ' aria-current="page"' : '' ?>>Toutes</a>
    <a href="<?= h(vel_page_url($plugin, 'commandes', ['etat' => 'paid'] + ($q !== '' ? ['q' => $q] : []))) ?>"<?= $etat === 'paid' ? ' aria-current="page"' : '' ?>>Payées<small><?= $paidCount ?></small></a>
    <a href="<?= h(vel_page_url($plugin, 'commandes', ['etat' => 'pending'] + ($q !== '' ? ['q' => $q] : []))) ?>"<?= $etat === 'pending' ? ' aria-current="page"' : '' ?>>En attente<small><?= $pendingCount ?></small></a>
  </nav>

  <form method="get" action="" class="vel-form" role="search" style="margin-top:0;">
    <input type="hidden" name="p" value="<?= h($plugin['id']) ?>">
    <input type="hidden" name="v" value="commandes">
    <?php if ($etat !== ''): ?><input type="hidden" name="etat" value="<?= h($etat) ?>"><?php endif; ?>
    <label>Rechercher <input type="search" name="q" value="<?= h($q) ?>" placeholder="e-mail, nom, session Stripe…" style="min-width:240px;"></label>
    <button type="submit" class="btn-small">Rechercher</button>
    <?php if ($q !== ''): ?><a class="btn-small" href="<?= h(vel_page_url($plugin, 'commandes', $etat !== '' ? ['etat' => $etat] : [])) ?>">Effacer</a><?php endif; ?>
  </form>

  <?php if (!$orders): ?>
    <p class="empty-state" style="margin-top:16px;"><?= $q !== '' ? 'Aucune commande ne correspond à cette recherche.' : 'Aucune commande pour l\'instant.' ?></p>
  <?php endif; ?>

  <?php foreach ($orders as $o): $orderEvents = $events[(int) $o['id']] ?? []; ?>
    <article class="vel-order">
      <div class="vel-order-head">
        <div>
          <span class="ref">#<?= (int) $o['id'] ?> · <?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></span>
          <p style="margin:2px 0 0;"><strong><?= h($o['email'] ?: 'e-mail non fourni') ?></strong></p>
          <?php if (!empty($o['ship_name'])): ?>
            <p class="sub" style="margin:2px 0 0;">Livrer à <strong><?= h($o['ship_name']) ?></strong><?= !empty($o['ship_phone']) ? ' — ' . h($o['ship_phone']) : '' ?> (différent du client)</p>
          <?php endif; ?>
          <?php if (!empty($o['phone'])): ?><p class="sub" style="margin:2px 0 0;"><?= h($o['phone']) ?><?= !empty($o['terms_at']) ? ' · conditions acceptées le ' . h(date('d/m/Y H:i', strtotime($o['terms_at']))) : '' ?></p><?php endif; ?>
        </div>
        <div class="r">
          <strong><?= h(format_cents((int) $o['amount_total'])) ?></strong>
          <?php if ((int) $o['shipping_cents'] > 0): ?><small>dont <?= h(format_cents((int) $o['shipping_cents'])) ?> de port</small><?php endif; ?>
        </div>
      </div>

      <p style="margin:8px 0 0;">
        <span class="badge" style="color:<?= $o['status'] === 'paid' ? 'var(--sage)' : 'var(--accent)' ?>;border-color:currentColor;"><?= $o['status'] === 'paid' ? 'Payée' : 'En attente' ?></span>
        <?php if ($o['status'] === 'paid'): ?><span class="badge"><?= h(fulfillment_label((string) $o['fulfillment_status'])) ?></span><?php endif; ?>
        <?php if (!empty($o['shipping_method'])): ?><span class="badge"><?= h($o['shipping_method']) ?></span><?php endif; ?>
      </p>

      <ul class="item-list">
        <?php foreach (json_decode((string) $o['items'], true) ?: [] as $item): ?>
          <li><?= (int) ($item['qty'] ?? 0) ?> × <?= h($item['name'] ?? '') ?></li>
        <?php endforeach; ?>
      </ul>

      <?php if (!empty($o['shipping_address'])): ?>
        <p class="sub">📦 <?= h($o['shipping_address']) ?></p>
      <?php elseif ((int) $o['shipping_cents'] === 0 && $o['status'] === 'paid'): ?>
        <p class="sub">Retrait à l'atelier</p>
      <?php endif; ?>
      <?php if (!empty($o['tracking_number'])): ?><p class="sub">N° de suivi : <?= h($o['tracking_number']) ?></p><?php endif; ?>

      <?php if ($o['status'] === 'paid'): ?>
        <form class="vel-form" data-vel-form="order_fulfillment">
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <label>Suivi
            <select name="fulfillment_status">
              <?php foreach (['a_preparer', 'expediee', 'livree'] as $fs): ?>
                <option value="<?= $fs ?>"<?= $o['fulfillment_status'] === $fs ? ' selected' : '' ?>><?= h(fulfillment_label($fs)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>N° de suivi <input type="text" name="tracking_number" value="<?= h((string) ($o['tracking_number'] ?? '')) ?>" placeholder="facultatif"></label>
          <button type="submit" class="btn-small">Mettre à jour</button>
        </form>
      <?php elseif (shop_checkout_ready()): ?>
        <form class="vel-form" data-vel-form="order_refresh_stripe">
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <button type="submit" class="btn-small">Vérifier auprès de Stripe</button>
        </form>
      <?php endif; ?>

      <details>
        <summary>Journal de suivi<?= $orderEvents ? ' (' . count($orderEvents) . ')' : '' ?></summary>
        <?php if ($orderEvents): ?>
          <ul>
            <?php foreach ($orderEvents as $event): ?>
              <li><?= h(date('d/m/Y H:i', strtotime($event['created_at']))) ?> · <strong><?= h(vel_event_label((string) $event['type'])) ?></strong> — <?= h($event['message']) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="sub" style="margin:6px 0 0;">Aucun événement pour l'instant.</p>
        <?php endif; ?>
        <form class="vel-form" data-vel-form="order_note" data-vel-stay="1">
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <label style="flex:1;min-width:220px;">Ajouter une note <textarea class="vel-note" name="message" rows="2" maxlength="500" placeholder="ex : client prévenu, colis déposé en point relais…"></textarea></label>
          <button type="submit" class="btn-small">Ajouter</button>
        </form>
      </details>

      <p style="margin:8px 0 0;"><a href="<?= h($dashBase . urlencode((string) $o['stripe_session_id'])) ?>" target="_blank" rel="noopener" style="font-size:.78rem;color:var(--accent);">Voir dans Stripe →</a></p>
    </article>
  <?php endforeach; ?>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
