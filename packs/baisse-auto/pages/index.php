<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/bp.php';
$ent = addon_entitlement(BP_MODULE);
$s = bp_settings();
?>
<div id="bp-page" style="display:grid;gap:16px;max-width:980px;" data-api="<?= h($pluginApi('drops')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Baisse de prix automatique</h1>
  <?= addon_panel_html(BP_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(BP_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:72ch;">Une pièce qui reste en ligne baisse par paliers, à partir de son prix de départ, sans jamais passer sous son <strong>prix de vente minimum</strong> (fiche produit) ni sous le plafond de remise ci-dessous. Une pièce dont vous changez le prix à la main repart de ce prix. Les pièces en promotion, à prix en fourchette ou vendues sont ignorées. Le bot Leboncoin voit la baisse comme un « prix modifié » (republication selon ses réglages).</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Règles</h2>
    <form id="bp-settings" style="display:grid;gap:8px;max-width:680px;">
      <label>Paliers « jours:remise cumulée en % »<br><input type="text" name="steps" value="<?= h($s['steps']) ?>" style="width:100%;"></label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <label>Remise maximale (%)<br><input type="text" name="max_percent" value="<?= h($s['max_percent']) ?>" style="width:90px;"></label>
        <label>Mode<br><select name="mode"><option value="propose"<?= $s['mode'] === 'propose' ? ' selected' : '' ?>>Je valide chaque baisse</option><option value="auto"<?= $s['mode'] === 'auto' ? ' selected' : '' ?>>Automatique</option></select></label>
        <label>Ancienneté<br><select name="from_activation"><option value="1"<?= $s['from_activation'] === '1' ? ' selected' : '' ?>>Compter depuis l'activation du module</option><option value="0"<?= $s['from_activation'] === '0' ? ' selected' : '' ?>>Compter depuis la mise en ligne</option></select></label>
      </div>
      <div><button type="submit" class="btn-small">Enregistrer</button> <span class="hint" id="bp-settings-msg" role="status"></span></div>
    </form>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><h2 style="margin:0;font-size:1.05rem;">Baisses à faire</h2><button type="button" class="btn-small" id="bp-apply-all">Appliquer les cochées</button></div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="bp-due">
      <thead><tr><th></th><th align="left">Pièce</th><th align="right">Ancienneté</th><th align="right">Prix</th><th align="right">Nouveau prix</th><th align="right">Remise</th></tr></thead><tbody></tbody>
    </table></div>
    <p class="hint" id="bp-msg" role="status"></p>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Historique</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="bp-log">
      <thead><tr><th align="left">Date</th><th align="left">Pièce</th><th align="right">Avant</th><th align="right">Après</th><th align="left">Origine</th><th></th></tr></thead><tbody></tbody>
    </table></div>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('baisse.js')) ?>" defer></script>
