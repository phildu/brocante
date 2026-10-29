<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vf.php';
$ent = addon_entitlement(VF_MODULE);
$in2h = date('Y-m-d\TH:i', time() + 600);
$end = date('Y-m-d\TH:i', time() + 4 * 3600);
?>
<div id="vf-page" style="display:grid;gap:16px;max-width:900px;" data-api="<?= h($pluginApi('sales')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Ventes flash</h1>
  <?= addon_panel_html(VF_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(VF_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:66ch;">Les prix baissent d'eux-mêmes à chaque pas (calculé à chaque visite du site), jusqu'au plafond, puis reviennent à leur valeur d'origine à la fin. Seules les pièces à prix fixe, visibles et en stock sont concernées. Si l'essai ou l'abonnement s'arrête pendant une vente, les prix d'origine sont rétablis.</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Nouvelle vente flash</h2>
    <form id="vf-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
      <label>Nom<br><input type="text" name="name" required maxlength="80" placeholder="Braderie du dimanche"></label>
      <label>Début<br><input type="datetime-local" name="starts_at" value="<?= h($in2h) ?>" required></label>
      <label>Fin<br><input type="datetime-local" name="ends_at" value="<?= h($end) ?>" required></label>
      <label>Toutes les (min)<br><input type="number" name="step_minutes" value="60" min="1" max="1440" style="width:90px;"></label>
      <label>Baisse par pas (%)<br><input type="text" name="step_percent" value="5" style="width:80px;"></label>
      <label>Plafond (%)<br><input type="text" name="max_percent" value="40" style="width:80px;"></label>
      <label>Références (vide = toutes)<br><input type="text" name="refs" placeholder="ex. 014, 062" style="width:200px;"></label>
      <button type="submit" class="btn-small">Programmer</button>
    </form>
    <p class="hint" id="vf-msg" role="status"></p>
  </section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Ventes</h2>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="vf-table">
      <thead><tr><th align="left">Nom</th><th align="left">Période</th><th align="left">Pas</th><th align="left">État</th><th align="right">Pièces</th><th></th></tr></thead><tbody></tbody>
    </table></div>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('ventes.js')) ?>" defer></script>
