<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ml.php';

$ent = addon_entitlement(ML_MODULE);
?>
<div id="ml-page" data-api="<?= h($pluginApi('languages')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>" data-preview="<?= h(app_prefix('/index.php')) ?>" style="display:grid;gap:16px;max-width:920px;">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Version multilingue</h1>
  <?= addon_panel_html(ML_MODULE, $pluginApi('access'), $pluginCsrf) ?>

  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html(ML_MODULE) ?>
    <p class="hint" style="max-width:70ch;">Le module traduit une fois, par IA, tout le texte public de la boutique (accueil, boutique, fiches, mentions légales), puis l'écrit en dur dans un fichier propre à la langue. Le site sert ensuite cette copie figée avec un sélecteur de langue : aucune traduction n'est demandée à l'IA pendant la visite.</p>
  <?php else: ?>
    <p class="hint" style="max-width:74ch;margin:0;">La traduction est <strong>figée</strong> : elle est générée par l'IA puis écrite dans <code>tenants/<?= h(tenant_slug()) ?>/lang/&lt;langue&gt;.json</code>. Les visiteurs voient cette version sans nouvel appel d'IA. Après un changement de catalogue, relancez la génération : seuls les textes nouveaux sont traduits. La consommation IA est débitée de votre crédit habituel.</p>

    <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
      <h2 style="margin:0;font-size:1.05rem;">Générer une langue</h2>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <label>Langue<br><select id="ml-lang" style="min-width:200px;"></select></label>
        <button type="button" class="btn-small" id="ml-run">Générer la version</button>
        <button type="button" class="btn-small" id="ml-stop" hidden>Arrêter</button>
      </div>
      <p class="hint" id="ml-estimate" style="margin:0;"></p>
      <progress id="ml-progress" max="1" value="0" style="width:100%;height:10px;"></progress>
      <p class="hint" id="ml-status" role="status" style="margin:0;font-weight:600;"></p>
      <pre id="ml-log" style="margin:0;max-height:220px;overflow:auto;background:rgba(0,0,0,.04);padding:10px;border-radius:6px;font-size:.78rem;white-space:pre-wrap;"></pre>
    </section>

    <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
      <h2 style="margin:0;font-size:1.05rem;">Versions</h2>
      <div style="overflow-x:auto;">
        <table id="ml-table" style="width:100%;border-collapse:collapse;font-size:.9rem;">
          <thead><tr><th align="left">Langue</th><th align="left">État</th><th align="right">Textes</th><th align="right">Coût IA</th><th align="left">Générée le</th><th></th></tr></thead>
          <tbody><tr><td colspan="6" class="hint">Chargement…</td></tr></tbody>
        </table>
      </div>
      <p class="hint" style="margin:0;">Une version doit être <strong>publiée</strong> pour apparaître sur le site. Le sélecteur de langue (FR + langues publiées) est ajouté automatiquement aux pages, avec les liens <code>hreflang</code> pour les moteurs. Le français reste la version de référence : « FR » dans le sélecteur y ramène.</p>
    </section>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('multilingue.js')) ?>" defer></script>
