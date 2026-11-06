<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ac.php';
$ent = addon_entitlement(AC_MODULE);
?>
<div id="ac-page" style="display:grid;gap:16px;max-width:900px;" data-api="<?= h($pluginApi('alerts')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Alerte chineur</h1>
  <?= addon_panel_html(AC_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(AC_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:66ch;">Le formulaire « Ne ratez plus la pièce rare » s'affiche en bas de la boutique. Chaque visiteur confirme son adresse par e-mail ; les alertes partent dans les 5 minutes qui suivent une mise en ligne (déclenchées par les visites du site). Seules les pièces mises en ligne <em>après</em> l'inscription sont signalées.</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><h2 style="margin:0;font-size:1.05rem;">Alertes enregistrées</h2><button type="button" class="btn-small" id="ac-scan">Vérifier maintenant</button><span class="hint" id="ac-msg" role="status"></span></div>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="ac-table">
      <thead><tr><th align="left">E-mail</th><th align="left">Recherche</th><th align="right">Prix max.</th><th align="left">État</th><th align="right">Envoyées</th><th></th></tr></thead><tbody></tbody>
    </table></div>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('alertes.js')) ?>" defer></script>
