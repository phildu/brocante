<?php
// Reçus & journal des ventes : accès au module, réglages du vendeur, reçus, exports comptables.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rc.php';

$ent = addon_entitlement(RC_MODULE);
$s = rc_settings();
$fields = [
    'seller_name' => ['Nom du vendeur', 'text'], 'seller_address' => ['Adresse', 'text'], 'seller_siret' => ['SIRET', 'text'],
    'seller_vat' => ['N° de TVA (si assujetti)', 'text'], 'footer' => ['Mention libre en bas du reçu', 'text'],
];
$accounts = ['journal' => 'Code journal', 'acc_bank' => 'Compte banque', 'acc_cash' => 'Compte caisse (espèces)', 'acc_sales' => 'Compte ventes', 'acc_shipping' => 'Compte port', 'acc_vat' => 'Compte TVA collectée'];
?>
<div class="rc-page" id="rc-page" style="display:grid;gap:16px;max-width:900px;"
     data-api-settings="<?= h($pluginApi('settings')) ?>" data-api-receipts="<?= h($pluginApi('receipts')) ?>"
     data-api-export="<?= h($pluginApi('export')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Reçus &amp; journal des ventes</h1>
  <?= addon_panel_html(RC_MODULE, $pluginApi('access'), $pluginCsrf) ?>

  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(RC_MODULE) ?>
  <?php else: ?>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Vendeur et régime de TVA</h2>
    <form id="rc-settings" style="display:grid;gap:8px;">
      <?php foreach ($fields as $k => [$label, $type]): ?>
        <label><?= h($label) ?><br><input type="<?= $type ?>" name="<?= h($k) ?>" value="<?= h($s[$k]) ?>" style="width:100%;max-width:520px;"></label>
      <?php endforeach; ?>
      <label>Régime de TVA<br><select name="regime">
        <?php foreach (rc_regimes() as $k => $label): ?><option value="<?= h($k) ?>"<?= $s['regime'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select></label>
      <label>Taux de TVA (régime « TVA normale »), %<br><input type="text" name="vat_rate" value="<?= h($s['vat_rate']) ?>" style="width:90px;"></label>
      <label><input type="checkbox" name="email_auto" value="1"<?= $s['email_auto'] === '1' ? ' checked' : '' ?>> Envoyer automatiquement le reçu par e-mail à chaque vente</label>
      <details><summary>Comptes comptables (journal des ventes, FEC)</summary><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
        <?php foreach ($accounts as $k => $label): ?><label><?= h($label) ?><br><input type="text" name="<?= h($k) ?>" value="<?= h($s[$k]) ?>" style="width:130px;"></label><?php endforeach; ?>
      </div></details>
      <div><button type="submit" class="btn-small">Enregistrer</button> <span class="hint" id="rc-settings-msg" role="status"></span></div>
    </form>
    <p class="hint">Le reçu ne remplace pas une facture : il prouve l'achat. Faites valider la mention de TVA par votre expert-comptable.</p>
  </section>

  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Exports comptables</h2>
    <form id="rc-export" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
      <label>Du<br><input type="date" name="from" value="<?= h(date('Y-m-01')) ?>" required></label>
      <label>Au<br><input type="date" name="to" value="<?= h(date('Y-m-d')) ?>" required></label>
      <button type="button" class="btn-small" data-fmt="csv">Journal des ventes (CSV / Excel)</button>
      <button type="button" class="btn-small" data-fmt="fec">Fichier des écritures (FEC)</button>
    </form>
    <p class="hint" id="rc-export-msg" role="status"></p>
  </section>

  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Commandes payées</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="rc-orders">
      <thead><tr><th align="left">Date</th><th align="left">Commande</th><th align="left">Acheteur</th><th align="right">Total</th><th align="left">Reçu</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <p class="hint" id="rc-orders-msg"></p>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('recus.js')) ?>" defer></script>
