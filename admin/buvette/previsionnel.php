<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

$event = bv_active_event();

if (bv_post_action() === 'save_forecast') {
    if (!$event) bv_redirect('/admin/buvette/previsionnel.php', 'Créez d\'abord un événement.', 'error');
    $eid = (int) $event['id'];
    $forecast = (array) ($_POST['forecast'] ?? []);
    $sold = (array) ($_POST['sold'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO bv_event_items (event_id, item_id, forecast_qty) VALUES (?, ?, 0)');
    $upd = $pdo->prepare('UPDATE bv_event_items SET forecast_qty = ?, sold_qty = ? WHERE event_id = ? AND item_id = ?');
    foreach (bv_items_list(false) as $it) {
        $id = (int) $it['id'];
        $f = max(0, (int) ($forecast[$id] ?? 0));
        $sRaw = trim((string) ($sold[$id] ?? ''));
        $s = $sRaw === '' ? null : max(0, (int) $sRaw);
        $ins->execute([$eid, $id]);
        $upd->execute([$f, $s, $eid, $id]);
    }
    $pdo->commit();
    bv_redirect('/admin/buvette/previsionnel.php', 'Prévisionnel enregistré. La liste de courses est à jour.');
}

bv_admin_header('Prévisionnel & bilan', 'previsionnel');

if (!$event): ?>
  <p class="bv-callout">Créez d'abord un événement depuis le <a href="/admin/buvette/index.php#nouvel-evenement">tableau de bord</a>.</p>
<?php
    bv_admin_footer();
    exit;
endif;

$eid = (int) $event['id'];
$items = bv_items_list(true);
$fc = bv_event_forecast($eid);
$costs = bv_item_costs();
$totals = bv_event_totals($eid);
$perVisitor = (int) $event['expected_visitors'] > 0 ? $totals['portions_forecast'] / (int) $event['expected_visitors'] : null;
?>
<p class="bv-hint">Combien de portions pensez-vous vendre de chaque article ? Ces chiffres servent à calculer la <a href="/admin/buvette/courses.php" style="color:var(--accent);">liste de courses</a>. Après l'événement, saisissez les quantités réellement vendues (colonne « Vendu ») : le bilan se calcule tout seul.</p>

<?php if (!$items): ?>
  <p class="bv-callout">La carte est vide : <a href="/admin/buvette/carte.php">ajoutez des articles</a> d'abord.</p>
<?php else: ?>
<?= bv_form_open('save_forecast') ?>
  <div class="bv-card">
    <div class="bv-table-wrap">
      <table class="bv-table" id="bv-forecast">
        <thead>
          <tr>
            <th>Article</th><th class="num">Prix</th><th class="num">Revient</th>
            <th class="num">Prévu</th><th class="num">CA prévu</th>
            <th class="num">Vendu</th><th class="num">CA réel</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it):
            $id = (int) $it['id'];
            $f = $fc[$id]['forecast'] ?? 0;
            $s = $fc[$id]['sold'] ?? null;
            $cost = $costs[$id] ?? 0.0;
            ?>
          <tr data-price="<?= (int) $it['price_cents'] ?>" data-cost="<?= h(round($cost, 4)) ?>">
            <td><?= h($it['name']) ?></td>
            <td class="num"><?= h(bv_money($it['price_cents'])) ?></td>
            <td class="num bv-muted"><?= isset($costs[$id]) ? h(bv_money($cost)) : '—' ?></td>
            <td class="num"><input class="bv-input js-fc" type="number" name="forecast[<?= $id ?>]" min="0" value="<?= $f ?>" aria-label="Prévu : <?= h($it['name']) ?>"></td>
            <td class="num js-ca-fc"><?= h(bv_money($f * $it['price_cents'])) ?></td>
            <td class="num"><input class="bv-input js-sold" type="number" name="sold[<?= $id ?>]" min="0" value="<?= $s === null ? '' : $s ?>" placeholder="—" aria-label="Vendu : <?= h($it['name']) ?>"></td>
            <td class="num js-ca-sold"><?= $s === null ? '—' : h(bv_money($s * $it['price_cents'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3">Total</td>
            <td class="num" id="t-fc-qty"><?= (int) $totals['portions_forecast'] ?></td>
            <td class="num" id="t-fc-ca"><?= h(bv_money($totals['revenue_forecast'])) ?></td>
            <td class="num" id="t-sold-qty"><?= $totals['has_actual'] ? (int) $totals['portions_sold'] : '—' ?></td>
            <td class="num" id="t-sold-ca"><?= $totals['has_actual'] ? h(bv_money($totals['revenue_actual'])) : '—' ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </div>
</form>

<div class="bv-stats">
  <div class="bv-stat">
    <div class="label">Prévu</div>
    <div class="value" id="s-fc-margin"><?= h(bv_money($totals['margin_forecast'])) ?></div>
    <div class="sub">de marge sur <span id="s-fc-ca"><?= h(bv_money($totals['revenue_forecast'])) ?></span> de ventes</div>
  </div>
  <div class="bv-stat">
    <div class="label">Budget courses</div>
    <div class="value"><?= h(bv_money($totals['shopping_cents'])) ?></div>
    <div class="sub">à avancer pour acheter ce qui manque</div>
  </div>
  <?php if ($perVisitor !== null): ?>
  <div class="bv-stat">
    <div class="label">Repère</div>
    <div class="value"><?= h(number_format($perVisitor, 2, ',', '')) ?></div>
    <div class="sub">portions par visiteur attendu (<?= (int) $event['expected_visitors'] ?>)</div>
  </div>
  <?php endif; ?>
  <div class="bv-stat <?= $totals['has_actual'] ? ($totals['margin_actual'] > 0 ? 'is-good' : 'is-bad') : '' ?>">
    <div class="label">Réel</div>
    <div class="value" id="s-sold-margin"><?= $totals['has_actual'] ? h(bv_money($totals['margin_actual'])) : '—' ?></div>
    <div class="sub"><?= $totals['has_actual'] ? 'de marge sur ' . h(bv_money($totals['revenue_actual'])) . ' encaissés' : 'saisissez les quantités vendues après l\'événement' ?></div>
  </div>
</div>
<p class="bv-hint">La marge = ventes − coût de revient des recettes (hors matériel, hors invendus déjà achetés). Le « budget courses » est la somme à sortir pour acheter les lots qui manquent : ce qui n'est pas consommé reste en stock pour la prochaine fois.</p>

<script>
(function () {
  var fmt = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  var rows = document.querySelectorAll('#bv-forecast tbody tr');
  function recompute() {
    var qf = 0, caf = 0, costf = 0, qs = 0, cas = 0, anySold = false;
    rows.forEach(function (tr) {
      var price = parseFloat(tr.dataset.price), cost = parseFloat(tr.dataset.cost);
      var f = parseInt(tr.querySelector('.js-fc').value, 10) || 0;
      var sv = tr.querySelector('.js-sold').value.trim();
      tr.querySelector('.js-ca-fc').textContent = fmt.format(f * price / 100);
      qf += f; caf += f * price; costf += f * cost;
      if (sv === '') { tr.querySelector('.js-ca-sold').textContent = '—'; }
      else {
        var s = parseInt(sv, 10) || 0; anySold = true; qs += s; cas += s * price;
        tr.querySelector('.js-ca-sold').textContent = fmt.format(s * price / 100);
      }
    });
    document.getElementById('t-fc-qty').textContent = qf;
    document.getElementById('t-fc-ca').textContent = fmt.format(caf / 100);
    document.getElementById('t-sold-qty').textContent = anySold ? qs : '—';
    document.getElementById('t-sold-ca').textContent = anySold ? fmt.format(cas / 100) : '—';
    document.getElementById('s-fc-ca').textContent = fmt.format(caf / 100);
    document.getElementById('s-fc-margin').textContent = fmt.format((caf - costf) / 100);
  }
  document.getElementById('bv-forecast').addEventListener('input', recompute);
})();
</script>
<?php endif;
bv_admin_footer();
