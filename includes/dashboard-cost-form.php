<?php
/**
 * Formulaire d'ajout / modification d'une ligne de frais (admin/dashboard.php).
 * Attend $cost (ligne shop_costs ou valeurs par défaut) et la closure $return.
 */
$fid = 'cf' . (int) $cost['id'];
$amountVal = $cost['amount'] === '' ? '' : rtrim(rtrim(number_format((float) $cost['amount'], 3, ',', ''), '0'), ',');
?>
<form method="post" action="/admin/dashboard-action.php" class="cost-form">
  <?= $return('frais-gerer') ?>
  <input type="hidden" name="action" value="cost_save">
  <input type="hidden" name="id" value="<?= (int) $cost['id'] ?>">
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-label">Libellé</label><input type="text" id="<?= $fid ?>-label" name="label" value="<?= h($cost['label']) ?>" required maxlength="120"></div>
    <div class="field"><label for="<?= $fid ?>-cat">Catégorie</label>
      <select id="<?= $fid ?>-cat" name="category"><?php foreach (cost_categories() as $k => $lab): ?><option value="<?= h($k) ?>"<?= $cost['category'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-kind">Type de frais</label>
      <select id="<?= $fid ?>-kind" name="kind"><?php foreach (cost_kinds() as $k => $lab): ?><option value="<?= h($k) ?>"<?= $cost['kind'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="<?= $fid ?>-amount">Montant (€, ou % selon le type)</label><input type="text" id="<?= $fid ?>-amount" name="amount" inputmode="decimal" value="<?= h($amountVal) ?>" placeholder="ex : 3,59" required></div>
  </div>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-period">Si montant fixe : périodicité</label>
      <select id="<?= $fid ?>-period" name="period"><?php foreach (['monthly' => 'Par mois', 'yearly' => 'Par an', 'once' => 'Ponctuel'] as $k => $lab): ?><option value="<?= $k ?>"<?= $cost['period'] === $k ? ' selected' : '' ?>><?= $lab ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="<?= $fid ?>-unit">Si à l'usage : ce qui est compté</label>
      <select id="<?= $fid ?>-unit" name="unit"><option value="">—</option><?php foreach (cost_usage_units() as $k => $lab): ?><option value="<?= h($k) ?>"<?= ($cost['unit'] ?? '') === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-status">Statut</label>
      <select id="<?= $fid ?>-status" name="status">
        <option value="actif"<?= $cost['status'] === 'actif' ? ' selected' : '' ?>>Réel — je le paie vraiment</option>
        <option value="test"<?= $cost['status'] === 'test' ? ' selected' : '' ?>>Test / gratuit aujourd'hui — simulation seulement</option>
      </select></div>
    <div class="field"><label for="<?= $fid ?>-note">Note</label><input type="text" id="<?= $fid ?>-note" name="note" value="<?= h($cost['note']) ?>" maxlength="240"></div>
  </div>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-start">Début (facultatif)</label><input type="date" id="<?= $fid ?>-start" name="start_date" value="<?= h($cost['start_date']) ?>"></div>
    <div class="field"><label for="<?= $fid ?>-end">Fin (facultatif)</label><input type="date" id="<?= $fid ?>-end" name="end_date" value="<?= h($cost['end_date']) ?>"></div>
  </div>
  <button class="btn btn-primary" type="submit"><?= (int) $cost['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
</form>
