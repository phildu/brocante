<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

$carriers = shipping_carriers_list();
$zoneLabels = shipping_zones();
$zoneOrder = array_flip(array_keys($zoneLabels));
$flash = flash_get();

$names = array_column($carriers, 'name');
$hasChronopost = in_array('Chronopost', $names, true);
$hasColissimo = in_array('Colissimo', $names, true);
$hasMondialRelay = in_array('Mondial Relay', $names, true);
$colissimoId = 0;
foreach ($carriers as $c) {
    if ($c['name'] === 'Colissimo') $colissimoId = (int) $c['id'];
}
$colissimoHasIntl = false;
if ($colissimoId) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM shipping_rates WHERE carrier_id = ? AND zone LIKE 'intl_%'");
    $stmt->execute([$colissimoId]);
    $colissimoHasIntl = (int) $stmt->fetchColumn() > 0;
}
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Frais de port</h1>
  <p class="hint" style="max-width:78ch;">
    Transporteurs et paliers selon le poids <strong>et la destination</strong> (métropole, Corse, outre-mer, international A/B/C).
    Au panier, le pays et l'adresse du client déterminent la zone ; les paliers correspondants sont proposés en plus du retrait
    à l'atelier. Sans transporteur pour la zone (ou panier au-delà du dernier palier), le tarif fixe de repli défini dans
    <a href="<?= h(vel_page_url($plugin, 'paiement')) ?>">Paiement &amp; livraison</a> s'applique. Le poids de chaque pièce se
    renseigne dans le <a href="/admin/catalog.php">catalogue</a>.
  </p>

  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <?php if (!$hasChronopost || !$hasColissimo || !$hasMondialRelay || ($hasColissimo && !$colissimoHasIntl)): ?>
    <div class="vel-card">
      <h2>Charger un jeu de tarifs d'exemple</h2>
      <?php if (!$carriers): ?><p class="empty-state" style="margin:0 0 12px;">Aucun transporteur configuré pour l'instant.</p><?php endif; ?>
      <div class="vel-actions">
        <?php if (!$hasChronopost): ?>
          <form data-vel-form="seed"><input type="hidden" name="carrier" value="chronopost"><button type="submit" class="btn-small">Chronopost (15 paliers, 3 zones)</button></form>
        <?php endif; ?>
        <?php if (!$hasColissimo): ?>
          <form data-vel-form="seed"><input type="hidden" name="carrier" value="colissimo"><button type="submit" class="btn-small">Colissimo (22 paliers, 3 zones)</button></form>
        <?php endif; ?>
        <?php if (!$hasMondialRelay): ?>
          <form data-vel-form="seed"><input type="hidden" name="carrier" value="mondial_relay"><button type="submit" class="btn-small">Mondial Relay (10 paliers Point Relais)</button></form>
        <?php endif; ?>
        <?php if ($hasColissimo && !$colissimoHasIntl): ?>
          <form data-vel-form="seed"><input type="hidden" name="carrier" value="colissimo_intl"><button type="submit" class="btn-small">Colissimo international (21 paliers, zones A/B/C)</button></form>
        <?php endif; ?>
      </div>
      <p class="hint" style="margin:10px 0 0;">Tarifs grand public indicatifs : corrigez-les ensuite selon vos contrats (le supplément Corse et l'outre-mer sont des estimations).</p>
    </div>
  <?php endif; ?>

  <?php foreach ($carriers as $carrier): ?>
    <?php
      $rates = shipping_rates_for_carrier((int) $carrier['id']);
      usort($rates, static function (array $a, array $b) use ($zoneOrder): int {
          $za = $zoneOrder[$a['zone']] ?? 99;
          $zb = $zoneOrder[$b['zone']] ?? 99;
          return $za <=> $zb ?: $a['weight_min_g'] <=> $b['weight_min_g'];
      });
    ?>
    <section class="vel-card<?= $carrier['is_active'] ? '' : ' is-off' ?>">
      <div class="vel-head">
        <h2><?= h($carrier['name']) ?></h2>
        <div class="vel-actions">
          <form data-vel-form="carrier_toggle"><input type="hidden" name="id" value="<?= (int) $carrier['id'] ?>"><button type="submit" class="btn-small"><?= $carrier['is_active'] ? 'Désactiver' : 'Activer' ?></button></form>
          <form data-vel-form="carrier_delete" data-confirm="Supprimer ce transporteur et tous ses paliers de tarif ?"><input type="hidden" name="id" value="<?= (int) $carrier['id'] ?>"><button type="submit" class="admin-delete">Supprimer</button></form>
        </div>
      </div>

      <table class="vel">
        <thead><tr><th>Service</th><th class="hide-sm">Zone</th><th>Poids min</th><th>Poids max</th><th>Tarif</th><th class="hide-sm">Délai</th><th></th></tr></thead>
        <tbody>
          <?php $lastZone = null; ?>
          <?php foreach ($rates as $rate): ?>
            <?php if ($rate['zone'] !== $lastZone): $lastZone = $rate['zone']; ?>
              <tr class="zone-head"><td colspan="7"><?= h($zoneLabels[$rate['zone']] ?? $rate['zone']) ?></td></tr>
            <?php endif; ?>
            <tr>
              <td><?= h($rate['service_name']) ?></td>
              <td class="hide-sm"><?= h($zoneLabels[$rate['zone']] ?? $rate['zone']) ?></td>
              <td><?= h(vel_weight((int) $rate['weight_min_g'])) ?> kg</td>
              <td><?= h(vel_weight((int) $rate['weight_max_g'])) ?> kg</td>
              <td><?= h(format_cents((int) $rate['price_cents'])) ?></td>
              <td class="hide-sm"><?= h($rate['delivery_delay']) ?></td>
              <td><form data-vel-form="rate_delete" data-confirm="Supprimer ce palier ?"><input type="hidden" name="id" value="<?= (int) $rate['id'] ?>"><button type="submit" class="admin-delete">Supprimer</button></form></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rates): ?><tr><td colspan="7" class="empty-state">Aucun palier — ajoutez-en un ci-dessous.</td></tr><?php endif; ?>
        </tbody>
      </table>

      <form class="vel-form" data-vel-form="rate_add">
        <input type="hidden" name="carrier_id" value="<?= (int) $carrier['id'] ?>">
        <label>Service <input type="text" name="service_name" placeholder="ex : Chrono 13" required></label>
        <label>Zone
          <select name="zone">
            <?php foreach ($zoneLabels as $zoneKey => $zoneLabel): ?><option value="<?= h($zoneKey) ?>"><?= h($zoneLabel) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Poids min (kg) <input type="text" name="weight_min_kg" placeholder="0" required></label>
        <label>Poids max (kg) <input type="text" name="weight_max_kg" placeholder="1" required></label>
        <label>Tarif € <input type="number" name="price" min="0.01" step="0.01" inputmode="decimal" placeholder="23,40" required></label>
        <label>Délai <input type="text" name="delivery_delay" placeholder="ex : 13h le lendemain"></label>
        <button type="submit" class="btn-small">Ajouter le palier</button>
      </form>
    </section>
  <?php endforeach; ?>

  <section class="vel-card">
    <h2>Ajouter un transporteur</h2>
    <form class="vel-form" data-vel-form="carrier_add">
      <label>Nom <input type="text" name="name" placeholder="ex : Colissimo" maxlength="80" required></label>
      <button type="submit" class="btn-small">Ajouter</button>
    </form>
  </section>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
