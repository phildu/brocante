<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

$orders = db()->query("SELECT * FROM orders WHERE status = 'paid' ORDER BY created_at DESC, id DESC LIMIT 300")->fetchAll();
$journal = vel_journal(60);
$flash = flash_get();

$columns = [
    'a_preparer' => ['À préparer', 'Colis payés qui attendent l\'emballage et le dépôt.'],
    'expediee' => ['Expédiées', 'Colis déposés chez le transporteur, en attente de livraison.'],
    'livree' => ['Livrées', 'Commandes remises au client.'],
];
$groups = ['a_preparer' => [], 'expediee' => [], 'livree' => []];
foreach ($orders as $o) {
    $groups[in_array($o['fulfillment_status'], array_keys($groups), true) ? $o['fulfillment_status'] : 'a_preparer'][] = $o;
}
$withTracking = count(array_filter($orders, static fn (array $o): bool => trim((string) ($o['tracking_number'] ?? '')) !== ''));
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Suivi des envois</h1>
  <p class="hint" style="max-width:75ch;">
    Les commandes payées, classées par étape. Faites avancer un colis d'un clic ; le numéro de suivi et chaque changement
    sont gardés dans le journal de la commande (visible aussi sur <a href="<?= h(vel_page_url($plugin, 'commandes')) ?>">Commandes</a>).
  </p>

  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <div class="vel-kpis">
    <div class="vel-kpi"><span>À préparer</span><strong><?= count($groups['a_preparer']) ?></strong></div>
    <div class="vel-kpi"><span>Expédiées</span><strong><?= count($groups['expediee']) ?></strong></div>
    <div class="vel-kpi"><span>Livrées</span><strong><?= count($groups['livree']) ?></strong></div>
    <div class="vel-kpi"><span>Avec n° de suivi</span><strong><?= $withTracking ?></strong></div>
  </div>

  <div class="vel-board">
    <?php foreach ($columns as $key => [$label, $hint]): ?>
      <section class="vel-col">
        <h2><?= h($label) ?> <small><?= count($groups[$key]) ?></small></h2>
        <p class="sub" style="margin:0 0 4px;"><?= h($hint) ?></p>
        <?php if (!$groups[$key]): ?><p class="sub" style="margin-top:10px;">Aucune commande à cette étape.</p><?php endif; ?>
        <?php foreach ($groups[$key] as $o): ?>
          <div class="vel-ticket">
            <div class="who"><?= h($o['email'] ?: 'e-mail non fourni') ?></div>
            <div class="meta">#<?= (int) $o['id'] ?> · <?= h(date('d/m/Y', strtotime($o['created_at']))) ?> · <?= h(format_cents((int) $o['amount_total'])) ?></div>
            <?php if (!empty($o['tracking_number'])): ?><div class="meta">Suivi : <?= h($o['tracking_number']) ?></div><?php endif; ?>
            <?php if (!empty($o['shipping_address'])): ?><div class="meta"><?= h(mb_strimwidth($o['shipping_address'], 0, 70, '…')) ?></div><?php elseif ((int) $o['shipping_cents'] === 0): ?><div class="meta">Retrait à l'atelier</div><?php endif; ?>

            <form class="vel-form" data-vel-form="order_fulfillment">
              <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <input type="hidden" name="fulfillment_status" value="<?= $key === 'a_preparer' ? 'expediee' : ($key === 'expediee' ? 'livree' : 'expediee') ?>">
              <input type="text" name="tracking_number" value="<?= h((string) ($o['tracking_number'] ?? '')) ?>" placeholder="N° de suivi" style="flex:1;min-width:100px;">
              <button type="submit" class="btn-small"><?= $key === 'a_preparer' ? 'Expédiée' : ($key === 'expediee' ? 'Livrée' : 'Repasser expédiée') ?></button>
            </form>
            <?php if ($key !== 'a_preparer'): ?>
              <form class="vel-form" data-vel-form="order_fulfillment" data-confirm="Repasser cette commande à l'étape « À préparer » ?">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <input type="hidden" name="fulfillment_status" value="a_preparer">
                <button type="submit" class="btn-small">À préparer</button>
              </form>
            <?php endif; ?>
            <details>
              <summary>Note</summary>
              <form class="vel-form" data-vel-form="order_note" data-vel-stay="1">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <label style="flex:1;"><textarea class="vel-note" name="message" rows="2" maxlength="500" placeholder="ex : remis en main propre"></textarea></label>
                <button type="submit" class="btn-small">Ajouter</button>
              </form>
            </details>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <section class="vel-card">
    <h2>Journal des dernières actions</h2>
    <?php if (!$journal): ?>
      <p class="empty-state">Aucun événement pour l'instant — la prochaine commande payée apparaîtra ici.</p>
    <?php else: ?>
      <div class="vel-journal">
        <table class="vel">
          <thead><tr><th>Date</th><th>Commande</th><th>Type</th><th>Détail</th></tr></thead>
          <tbody>
          <?php foreach ($journal as $e): ?>
            <tr>
              <td style="white-space:nowrap;"><?= h(date('d/m/Y H:i', strtotime($e['created_at']))) ?></td>
              <td><?= $e['order_id'] ? '#' . (int) $e['order_id'] : '—' ?><?= $e['email'] ? '<small>' . h($e['email']) . '</small>' : '' ?></td>
              <td><?= h(vel_event_label((string) $e['type'])) ?></td>
              <td><?= h($e['message']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
