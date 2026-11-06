<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/td.php';
$ent = addon_entitlement(TD_MODULE);
?>
<div id="td-page" style="display:grid;gap:16px;max-width:1000px;" data-api="<?= h($pluginApi('stats')) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Tableau de bord</h1>
  <?= addon_panel_html(TD_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(TD_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:70ch;">Mesure sans cookie : chaque visiteur n'est qu'une empreinte qui change tous les jours, l'adresse IP et le navigateur ne sont jamais conservés, et vos propres visites (connecté à l'administration) ne comptent pas. Les chiffres démarrent à l'activation du module. À faire valider selon votre situation (la dispense de consentement de la CNIL suppose une mesure strictement anonyme et limitée à l'audience).</p>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <label>Période <select id="td-days"><option value="7">7 jours</option><option value="30" selected>30 jours</option><option value="90">90 jours</option><option value="365">12 mois</option></select></label>
    <span class="hint" id="td-msg" role="status"></span>
  </div>
  <div id="td-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;"></div>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:8px;"><h2 style="margin:0;font-size:1.05rem;">Visites par jour</h2><div id="td-chart"></div></section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:8px;"><h2 style="margin:0;font-size:1.05rem;">Pièces les plus vues</h2><div style="overflow-x:auto;"><table id="td-products" style="width:100%;border-collapse:collapse;font-size:.9rem;"></table></div></section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:8px;"><h2 style="margin:0;font-size:1.05rem;">D'où viennent les visiteurs</h2><div style="overflow-x:auto;"><table id="td-sources" style="width:100%;border-collapse:collapse;font-size:.9rem;"></table></div></section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:8px;"><h2 style="margin:0;font-size:1.05rem;">Campagnes (liens suivis)</h2><div style="overflow-x:auto;"><table id="td-campaigns" style="width:100%;border-collapse:collapse;font-size:.9rem;"></table></div></section>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Créer un lien de campagne</h2>
    <p class="hint" style="margin:0;">Partagez ce lien sur votre réseau social : les visites, ajouts au panier et pièces vues qui en viennent sont comptés à part.</p>
    <form id="td-link" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
      <label>Page<br><select name="page" id="td-link-page"><option value="">Toute la boutique</option></select></label>
      <label>Réseau<br><select name="source"><option>instagram</option><option>facebook</option><option>tiktok</option><option>pinterest</option><option>whatsapp</option><option>newsletter</option><option>leboncoin</option><option>vinted</option></select></label>
      <label>Nom de la campagne<br><input type="text" name="campaign" value="<?= h(date('Y-m')) ?>" maxlength="40" style="width:160px;"></label>
      <button type="submit" class="btn-small">Créer le lien</button>
    </form>
    <input type="text" id="td-link-out" readonly style="width:100%;" placeholder="Le lien apparaît ici">
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('dashboard.js')) ?>" defer></script>
