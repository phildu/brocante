<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$carriers = shipping_carriers_list();
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Frais de port — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .carrier-card { background: var(--surface); border: 1px solid var(--line); padding: 18px 20px; margin-top: 20px; }
  .carrier-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .carrier-head h2 { font-size: 1.15rem; margin: 0; }
  .carrier-head-actions { display: flex; gap: 8px; align-items: center; }
  .carrier-inactive { opacity: 0.55; }
  .carrier-inactive::after { content: ' (désactivé)'; font-size: 0.75rem; color: var(--ink-soft); }
  table.rate-table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 0.9rem; }
  table.rate-table th, table.rate-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); }
  table.rate-table th { color: var(--ink-soft); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
  .rate-add-row input, .rate-add-row select { width: 100%; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 6px 8px; font-family: var(--font-body); font-size: 0.85rem; }
  .rate-add-row td { vertical-align: top; }
  .rate-zone-head td { padding: 10px 10px 4px; border-bottom: none; font-family: var(--font-mono); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--accent); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'shipping'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Livraison</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Frais de port</h1>
    <p class="hint">
      Ajoutez vos transporteurs et leurs paliers de tarif selon le poids <strong>et la destination</strong>
      (France métropolitaine, Corse, outre-mer, ou international par zone A/B/C — chacune coûte souvent
      bien plus cher à livrer que la métropole). Dans le panier, le client indique son pays et son adresse,
      ce qui détermine la zone ;
      les paliers qui correspondent au poids du panier et à cette zone sont alors proposés en options de
      livraison, en plus du retrait à l'atelier. Sans transporteur configuré pour la zone du client (ou si
      le panier dépasse tous les paliers), le tarif fixe des <a href="/admin/index.php#shipping-settings">réglages du site</a>
      (« <?= h($content['shipping_label']) ?> — <?= h($content['shipping_fee']) ?> ») reste utilisé comme repli.
      Le poids de chaque pièce se renseigne dans le <a href="/admin/catalog.php">catalogue</a>, champ « Poids (g) ».
    </p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php
      $carrierNames = array_column($carriers, 'name');
      $hasChronopost = in_array('Chronopost', $carrierNames, true);
      $hasColissimo = in_array('Colissimo', $carrierNames, true);
      $colissimoId = null;
      $colissimoHasIntl = false;
      foreach ($carriers as $c) {
          if ($c['name'] === 'Colissimo') {
              $colissimoId = (int) $c['id'];
          }
      }
      if ($colissimoId !== null) {
          $stmt = db()->prepare("SELECT COUNT(*) FROM shipping_rates WHERE carrier_id = ? AND zone LIKE 'intl_%'");
          $stmt->execute([$colissimoId]);
          $colissimoHasIntl = (int) $stmt->fetchColumn() > 0;
      }
    ?>
    <?php if (!$hasChronopost || !$hasColissimo || ($hasColissimo && !$colissimoHasIntl)): ?>
      <div class="admin-block" style="margin-top:20px;">
        <?php if (!$carriers): ?>
          <p class="empty-state" style="margin:0 0 14px;">Aucun transporteur configuré pour l'instant.</p>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <?php if (!$hasChronopost): ?>
            <form method="post" action="/admin/shipping-action.php" style="display:inline;">
              <input type="hidden" name="action" value="seed_chronopost">
              <button type="submit" class="btn btn-primary">Charger un exemple Chronopost (15 paliers, 3 zones)</button>
            </form>
          <?php endif; ?>
          <?php if (!$hasColissimo): ?>
            <form method="post" action="/admin/shipping-action.php" style="display:inline;">
              <input type="hidden" name="action" value="seed_colissimo">
              <button type="submit" class="btn btn-primary">Charger un exemple Colissimo (22 paliers, 3 zones)</button>
            </form>
          <?php endif; ?>
          <?php if ($hasColissimo && !$colissimoHasIntl): ?>
            <form method="post" action="/admin/shipping-action.php" style="display:inline;">
              <input type="hidden" name="action" value="seed_colissimo_international">
              <button type="submit" class="btn btn-primary">Ajouter les tarifs internationaux Colissimo (21 paliers, zones A/B/C)</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php $zoneLabels = shipping_zones(); $zoneOrder = array_flip(array_keys($zoneLabels)); ?>
    <?php foreach ($carriers as $carrier): ?>
      <?php
        $rates = shipping_rates_for_carrier((int) $carrier['id']);
        usort($rates, function ($a, $b) use ($zoneOrder) {
            $za = $zoneOrder[$a['zone']] ?? 99;
            $zb = $zoneOrder[$b['zone']] ?? 99;
            return $za <=> $zb ?: $a['weight_min_g'] <=> $b['weight_min_g'];
        });
      ?>
      <div class="carrier-card">
        <div class="carrier-head">
          <h2 class="<?= $carrier['is_active'] ? '' : 'carrier-inactive' ?>"><?= h($carrier['name']) ?></h2>
          <div class="carrier-head-actions">
            <form method="post" action="/admin/shipping-action.php">
              <input type="hidden" name="action" value="toggle_carrier">
              <input type="hidden" name="id" value="<?= (int) $carrier['id'] ?>">
              <button type="submit" class="btn-small"><?= $carrier['is_active'] ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <form method="post" action="/admin/shipping-action.php" onsubmit="return confirm('Supprimer ce transporteur et tous ses paliers de tarif ?');">
              <input type="hidden" name="action" value="delete_carrier">
              <input type="hidden" name="id" value="<?= (int) $carrier['id'] ?>">
              <button type="submit" class="admin-delete">Supprimer</button>
            </form>
          </div>
        </div>

        <table class="rate-table">
          <thead>
            <tr>
              <th>Service</th>
              <th>Zone</th>
              <th>Poids min (kg)</th>
              <th>Poids max (kg)</th>
              <th>Tarif</th>
              <th>Délai</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php $lastZone = null; ?>
            <?php foreach ($rates as $rate): ?>
              <?php if ($rate['zone'] !== $lastZone): $lastZone = $rate['zone']; ?>
                <tr class="rate-zone-head"><td colspan="7"><?= h($zoneLabels[$rate['zone']] ?? $rate['zone']) ?></td></tr>
              <?php endif; ?>
              <tr>
                <td><?= h($rate['service_name']) ?></td>
                <td><?= h($zoneLabels[$rate['zone']] ?? $rate['zone']) ?></td>
                <td><?= h(rtrim(rtrim(number_format($rate['weight_min_g'] / 1000, 3, ',', ''), '0'), ',')) ?></td>
                <td><?= h(rtrim(rtrim(number_format($rate['weight_max_g'] / 1000, 3, ',', ''), '0'), ',')) ?></td>
                <td><?= h(format_cents((int) $rate['price_cents'])) ?></td>
                <td><?= h($rate['delivery_delay']) ?></td>
                <td>
                  <form method="post" action="/admin/shipping-action.php" onsubmit="return confirm('Supprimer ce palier ?');">
                    <input type="hidden" name="action" value="delete_rate">
                    <input type="hidden" name="id" value="<?= (int) $rate['id'] ?>">
                    <button type="submit" class="admin-delete">Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rates): ?>
              <tr><td colspan="7" class="empty-state">Aucun palier — ajoutez-en un ci-dessous.</td></tr>
            <?php endif; ?>
            <tr class="rate-add-row">
              <form method="post" action="/admin/shipping-action.php" style="display:contents;">
                <input type="hidden" name="action" value="add_rate">
                <input type="hidden" name="carrier_id" value="<?= (int) $carrier['id'] ?>">
                <td><input type="text" name="service_name" placeholder="ex : Chrono 13" required></td>
                <td>
                  <select name="zone">
                    <?php foreach ($zoneLabels as $zoneKey => $zoneLabel): ?>
                      <option value="<?= h($zoneKey) ?>"><?= h($zoneLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="text" name="weight_min_kg" placeholder="0" required></td>
                <td><input type="text" name="weight_max_kg" placeholder="1" required></td>
                <td><input type="text" name="price" placeholder="ex : 23,40 €" required></td>
                <td><input type="text" name="delivery_delay" placeholder="ex : 13h le lendemain"></td>
                <td><button type="submit" class="btn-small">Ajouter</button></td>
              </form>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <div class="admin-block" style="margin-top:24px;">
      <h2 style="font-size:1.1rem;">Ajouter un transporteur</h2>
      <form method="post" action="/admin/shipping-action.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
        <input type="hidden" name="action" value="add_carrier">
        <input type="text" name="name" placeholder="ex : Colissimo" required style="max-width:260px;">
        <button type="submit" class="btn btn-primary">Ajouter</button>
      </form>
    </div>
  </div>
</section>
</main>
</body>
</html>
