<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/qs.php';
$ent = addon_entitlement(QS_MODULE);
?>
<div id="qs-page" style="display:grid;gap:16px;max-width:1000px;" data-api="<?= h($pluginApi('stories')) ?>" data-print="<?= h($pluginApi('print')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">QR Code Story &amp; certificats</h1>
  <?= addon_panel_html(QS_MODULE, $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(QS_MODULE) ?>
  <?php else: ?>
  <p class="hint" style="max-width:70ch;">Racontez la pièce (époque, histoire, expertise) et cochez « Publier » : une page est créée, accessible par QR Code. Imprimez les étiquettes à coller sur les objets, ou un certificat d'authenticité (« Imprimer » puis « Enregistrer en PDF »). Le certificat engage le vendeur sur ce qu'il sait de la pièce : n'y mettez que ce que vous pouvez établir.</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
      <label>Chercher une pièce<br><input type="search" id="qs-q" placeholder="nom ou référence" style="min-width:220px;"></label>
      <label>Signataire du certificat<br><input type="text" id="qs-signatory" placeholder="Nom du vendeur ou de la boutique" style="min-width:240px;"></label>
      <button type="button" class="btn-small" id="qs-save-settings">Enregistrer</button>
      <button type="button" class="btn-small" id="qs-print-labels">Étiquettes QR (cochées)</button>
      <button type="button" class="btn-small" id="qs-print-certs">Certificats (cochées)</button>
    </div>
    <p class="hint" id="qs-msg" role="status"></p>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;" id="qs-table">
      <thead><tr><th></th><th align="left">Pièce</th><th align="left">Histoire</th><th align="left">Page</th><th></th></tr></thead><tbody></tbody>
    </table></div>
  </section>
  <section id="qs-edit" style="border:1px solid var(--line);padding:14px 16px;display:none;gap:8px;">
    <h2 style="margin:0;font-size:1.05rem;" id="qs-edit-title"></h2>
    <form id="qs-form" style="display:grid;gap:8px;max-width:640px;">
      <label>Époque<br><input type="text" name="epoch" maxlength="120" placeholder="ex. Années 1950, France" style="width:100%;"></label>
      <label>Son histoire<br><textarea name="origin" rows="5" maxlength="3000" style="width:100%;"></textarea></label>
      <label>Expertise<br><textarea name="expertise" rows="3" maxlength="3000" style="width:100%;"></textarea></label>
      <label>Expert (facultatif)<br><input type="text" name="expert_name" maxlength="120" style="width:100%;"></label>
      <label><input type="checkbox" name="published" value="1"> Publier la page (visible par QR Code)</label>
      <div><button type="submit" class="btn-small">Enregistrer</button></div>
    </form>
  </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('story.js')) ?>" defer></script>
