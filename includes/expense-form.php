<?php
/**
 * Formulaire d'une ligne du journal (admin/depenses.php).
 * Attend : $exp (ligne ou valeurs par défaut), $kinds (types proposés), $reports (notes ouvertes, pour une ligne de note de frais), $return (closure).
 */
$GLOBALS['expense_form_seq'] = ($GLOBALS['expense_form_seq'] ?? 0) + 1;   // un seul formulaire peut apparaître plusieurs fois par page
$fid = 'ef' . $GLOBALS['expense_form_seq'];
$amountVal = (int) $exp['amount_cents'] > 0 ? number_format($exp['amount_cents'] / 100, 2, ',', '') : '';
$isInvoice = $exp['kind'] === 'facture';
$isReport = $exp['kind'] === 'frais';
?>
<form method="post" action="/admin/depenses-action.php" enctype="multipart/form-data" class="cost-form">
  <?= $return((string) ($anchor ?? '')) ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int) $exp['id'] ?>">
  <?php if (count($kinds) === 1): ?>
    <input type="hidden" name="kind" value="<?= h(array_key_first($kinds)) ?>">
  <?php else: ?>
    <div class="field"><label for="<?= $fid ?>-kind">Type</label>
      <select id="<?= $fid ?>-kind" name="kind"><?php foreach ($kinds as $k => $lab): ?><option value="<?= h($k) ?>"<?= $exp['kind'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($isReport): ?><input type="hidden" name="report_id" value="<?= (int) $exp['report_id'] ?>"><?php endif; ?>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-label">Libellé</label><input type="text" id="<?= $fid ?>-label" name="label" value="<?= h($exp['label']) ?>" required maxlength="160" placeholder="ex : Cartons d'expédition"></div>
    <div class="field"><label for="<?= $fid ?>-supplier">Fournisseur</label><input type="text" id="<?= $fid ?>-supplier" name="supplier" value="<?= h($exp['supplier']) ?>" maxlength="120"></div>
  </div>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-date"><?= $isInvoice ? 'Date de la facture' : 'Date' ?></label><input type="date" id="<?= $fid ?>-date" name="spent_on" value="<?= h($exp['spent_on']) ?>" required></div>
    <div class="field"><label for="<?= $fid ?>-cat">Catégorie</label>
      <select id="<?= $fid ?>-cat" name="category"><?php foreach (expense_categories() as $k => $lab): ?><option value="<?= h($k) ?>"<?= $exp['category'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-amount">Montant TTC (€)</label><input type="text" id="<?= $fid ?>-amount" name="amount" inputmode="decimal" value="<?= h($amountVal) ?>" placeholder="ex : 24,00" required></div>
    <div class="field"><label for="<?= $fid ?>-vat">TVA contenue</label>
      <select id="<?= $fid ?>-vat" name="vat_rate"><?php foreach (expense_vat_rates() as $k => $lab): ?><option value="<?= h((string) $k) ?>"<?= abs((float) $exp['vat_rate'] - (float) $k) < 0.001 ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  </div>
  <?php if ($isInvoice || count($kinds) > 1): ?>
  <div class="field-row-2" data-only="facture">
    <div class="field"><label for="<?= $fid ?>-no">N° de facture <small>(factures)</small></label><input type="text" id="<?= $fid ?>-no" name="invoice_no" value="<?= h($exp['invoice_no']) ?>" maxlength="60"></div>
    <div class="field"><label for="<?= $fid ?>-due">Échéance <small>(factures)</small></label><input type="date" id="<?= $fid ?>-due" name="due_on" value="<?= h((string) $exp['due_on']) ?>"></div>
  </div>
  <div class="field-row-2" data-only="facture">
    <div class="field"><label for="<?= $fid ?>-paid">Payée le <small>(laisser vide si à payer)</small></label><input type="date" id="<?= $fid ?>-paid" name="paid_on" value="<?= h((string) $exp['paid_on']) ?>"></div>
    <div class="field"><label for="<?= $fid ?>-method">Règlement</label>
      <select id="<?= $fid ?>-method" name="payment_method"><?php foreach (expense_methods() as $k => $lab): ?><option value="<?= h($k) ?>"<?= $exp['payment_method'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  </div>
  <?php else: ?>
  <div class="field"><label for="<?= $fid ?>-method">Règlement</label>
    <select id="<?= $fid ?>-method" name="payment_method"><?php foreach (expense_methods() as $k => $lab): ?><option value="<?= h($k) ?>"<?= ($exp['payment_method'] === '' && $isReport ? 'perso' : $exp['payment_method']) === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="field-row-2">
    <div class="field"><label for="<?= $fid ?>-receipt">Justificatif <small>(photo ou PDF, 8 Mo max)</small></label><input type="file" id="<?= $fid ?>-receipt" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
    <div class="field"><label for="<?= $fid ?>-note">Note</label><input type="text" id="<?= $fid ?>-note" name="note" value="<?= h($exp['note']) ?>" maxlength="400"></div>
  </div>
  <?php if ((int) $exp['id'] && $exp['receipt'] !== ''): ?>
    <p class="hint"><a href="/admin/depense-fichier.php?id=<?= (int) $exp['id'] ?>" target="_blank" rel="noopener">Voir le justificatif actuel</a> · <label><input type="checkbox" name="remove_receipt" value="1"> le retirer</label> (un nouveau fichier le remplace)</p>
  <?php endif; ?>
  <button class="btn btn-primary" type="submit"><?= (int) $exp['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
</form>
