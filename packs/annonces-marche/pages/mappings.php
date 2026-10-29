<?php
// Correspondances : champs de la boutique ↔ champs Leboncoin / Vinted.
defined('PLUGIN_RUNTIME') || exit;
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page am-map-page" id="am-map" style="max-width:980px;"
     data-api="<?= h($pluginApi('mappings')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Option · <?= h($plugin['name']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Correspondances Leboncoin &amp; Vinted</h1>
  <p class="hint" style="max-width:68ch;">
    Dites quelle valeur du site correspond à une valeur de la boutique (état, catégorie, taille, couleur, marque, matière).
    Le bot l'utilise en priorité ; sans correspondance, il se rabat sur ses règles intégrées.
    Les champs et valeurs qu'il n'a pas su remplir arrivent dans « À renseigner ».
  </p>
  <p><a href="<?= h(plugin_page_url('annonces-marche', 'index')) ?>">← Retour aux annonces</a></p>

  <section class="am-card" id="am-map-unknown-card">
    <h2>À renseigner <span class="am-pill" id="am-map-unknown-count">0</span></h2>
    <p class="hint">Remontés par le bot lors des dernières publications. Choisissez la valeur du site à utiliser, puis « Enregistrer ».</p>
    <div id="am-map-unknown"></div>
  </section>

  <section class="am-card">
    <div class="am-actions">
      <label>Place de marché
        <select id="am-map-channel"></select>
      </label>
      <label>Champ
        <select id="am-map-field"></select>
      </label>
    </div>
    <p class="hint" id="am-map-help"></p>
    <div class="am-table-scroll"><table class="am-table" id="am-map-table">
      <thead><tr><th id="am-map-th-source">Valeur boutique</th><th>Valeur sur le site</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <form class="am-actions" id="am-map-form" autocomplete="off">
      <input type="hidden" name="field-name" id="am-map-fieldname">
      <label id="am-map-source-wrap">Valeur boutique
        <input type="text" id="am-map-source" list="am-map-sug" placeholder="ex. kaki" style="min-width:200px;">
        <select id="am-map-source-etat" hidden></select>
      </label>
      <label id="am-map-autre-wrap" hidden>Nom du champ sur le site
        <input type="text" id="am-map-autrename" placeholder="ex. Genre" style="min-width:160px;">
      </label>
      <label>Valeur sur le site
        <input type="text" id="am-map-target" placeholder="ex. Vert" style="min-width:200px;">
      </label>
      <button type="submit" class="btn-small">Ajouter / modifier</button>
    </form>
    <datalist id="am-map-sug"></datalist>
    <p class="am-status" id="am-map-status" role="status" aria-live="polite"></p>
  </section>
</div>
<script src="<?= h($pluginAsset('mappings.js')) ?>" defer></script>
