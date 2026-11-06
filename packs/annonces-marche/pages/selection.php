<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

$ent = am_entitlement();
$apiBase = rtrim(shop_abs_url('/'), '/') . '/api/annonces';
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page" id="am-page"
     data-api-selection="<?= h($pluginApi('selection')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>"
     data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">
  <p class="eyebrow"><a href="<?= h(plugin_page_url('annonces-marche', 'index')) ?>">← Annonces Leboncoin &amp; Vinted</a></p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Sélection pour l’application locale</h1>
  <p class="hint" style="max-width:68ch">Cochez les produits que votre application Python doit préparer. La sélection est enregistrée automatiquement et l’application peut la consulter via l’API sécurisée.</p>

  <section class="am-card<?= $ent['entitled'] ? '' : ' is-dim' ?>" id="am-export-selection">
    <div class="am-selection-toolbar">
      <label class="am-search-label" for="am-product-search">Rechercher un article</label>
      <input type="search" id="am-product-search" placeholder="Nom, référence ou marque…" <?= $ent['entitled'] ? '' : 'disabled' ?>>
      <div class="am-actions">
        <button type="button" class="btn-small" id="am-selection-search" <?= $ent['entitled'] ? '' : 'disabled' ?>>Rechercher</button>
        <button type="button" class="btn-small" id="am-selection-view" <?= $ent['entitled'] ? '' : 'disabled' ?>>Voir la sélection</button>
        <button type="button" class="btn-small" id="am-selection-all" <?= $ent['entitled'] ? '' : 'disabled' ?>>Tous les produits</button>
        <button type="button" class="btn-small" id="am-selection-clear" <?= $ent['entitled'] ? '' : 'disabled' ?>>Vider la sélection</button>
      </div>
    </div>
    <p class="am-status" id="am-selection-status" role="status"></p>
    <div class="am-table-scroll">
      <table class="am-table">
        <thead><tr><th>Choisir</th><th>Article</th><th>Référence</th><th>Marque</th><th>Prix</th></tr></thead>
        <tbody id="am-selection-items"><tr><td colspan="5">Chargement…</td></tr></tbody>
      </table>
    </div>
    <div class="am-actions">
      <button type="button" class="btn-small" id="am-selection-prev" disabled>Précédent</button>
      <span class="am-selection-page" id="am-selection-page"></span>
      <button type="button" class="btn-small" id="am-selection-next" disabled>Suivant</button>
    </div>
  </section>

  <section class="am-card">
    <h2>Connexion de l’application</h2>
    <p class="hint">URL de lecture de la sélection :</p>
    <p><code><?= h($apiBase) ?>/selection</code></p>
    <p class="hint">La clé API se configure sur la page <a href="<?= h(plugin_page_url('annonces-marche', 'api')) ?>">Clé API et connexion Python</a>. L’application doit envoyer l’en-tête <code>X-Annonces-Token</code>.</p>
  </section>
</div>
<script src="<?= h($pluginAsset('admin.js')) ?>" defer></script>
