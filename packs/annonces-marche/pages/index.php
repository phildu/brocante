<?php
// Tableau de bord : abonnement (option payante), jeton, extension, historique.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

$ent = am_entitlement();
$listings = db()->query('SELECT l.*, p.name AS product_name FROM am_listings l
    LEFT JOIN products p ON p.ref = l.product_ref
    ORDER BY l.id DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
$apiBase = rtrim(shop_abs_url('/'), '/') . '/api/annonces';
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page" id="am-page"
     data-api-status="<?= h($pluginApi('status')) ?>"
     data-api-token="<?= $role === 'admin' ? h($pluginApi('token')) : '' ?>"
     data-api-selection="<?= h($pluginApi('selection')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>"
     data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>"
     data-has-token="<?= $ent['has_token'] ? '1' : '0' ?>"
     data-selection-modal-url="<?= h(plugin_page_url('annonces-marche', 'index') . '&modal=selection') ?>"
     data-api-modal-url="<?= $role === 'admin' ? h(plugin_page_url('annonces-marche', 'index') . '&modal=token') : '' ?>">

  <p class="eyebrow">Option · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <p><a href="<?= h(plugin_page_url('annonces-marche', 'mappings')) ?>">Correspondances des champs (état, catégorie, taille, couleur…) →</a></p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Annonces Leboncoin &amp; Vinted</h1>
  <p class="hint" style="max-width:62ch;">
    Remplissez les formulaires de dépôt à partir du catalogue de <strong><?= h($content['site_name']) ?></strong>.
    L’extension prépare l’annonce ; <strong>vous restez connecté sur le site et vous validez la publication</strong>
    (pas de robot qui publie à votre place).
  </p>

  <section class="am-card" id="am-billing">
    <?php if ($role === 'admin'): ?>
      <?= addon_panel_html('annonces-marche', $pluginApi('access'), $pluginCsrf) ?>
    <?php else: ?>
      <h2>Abonnement <span class="am-pill"><?= h($ent['label']) ?></span></h2>
    <?php endif; ?>
  </section>

  <section class="am-card<?= $ent['entitled'] ? '' : ' is-dim' ?>">
    <h2>Extension Chrome</h2>
    <ol class="am-steps">
      <li>Téléchargez l’archive, décompressez-la.</li>
      <li>Ouvrez <code>chrome://extensions</code>, activez le « mode développeur », « Charger l’extension non empaquetée », choisissez le dossier.</li>
      <li>Dans l’extension : adresse de la boutique <code><?= h(rtrim(shop_abs_url('/'), '/')) ?></code> (ou directement <code><?= h($apiBase) ?></code>) + jeton.</li>
      <li>Sur Leboncoin ou Vinted, ouvrez « Déposer une annonce », choisissez un article dans l’extension, puis validez à la main.</li>
    </ol>
    <div class="am-actions">
      <a class="btn-small" href="<?= h($pluginApi('extension-zip')) ?>">Télécharger l’extension (.zip)</a>
    </div>
  </section>

  <section class="am-card">
    <h2>Accès rapides</h2>
    <div class="am-actions">
      <button type="button" class="btn-small" data-open-modal="am-selection-dialog">Sélectionner les articles pour l’application</button>
      <?php if ($role === 'admin'): ?>
        <button type="button" class="btn-small" data-open-modal="am-api-dialog">Clé API et connexion Python</button>
      <?php endif; ?>
    </div>
  </section>

  <dialog class="am-modal" id="am-selection-dialog" aria-labelledby="am-selection-title">
    <div class="am-modal-header">
      <div>
        <p class="eyebrow">Application locale</p>
        <h2 id="am-selection-title">Sélection des articles</h2>
      </div>
      <button type="button" class="am-modal-close" data-close-modal aria-label="Fermer">×</button>
    </div>
    <p class="hint">Cochez les produits que l’application Python doit préparer. Les changements sont enregistrés automatiquement.</p>
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
    <p class="hint">L’application ouvre cette fenêtre dans le navigateur à l’adresse :</p>
    <p><code><?= h(shop_abs_url('/admin/plugin.php?p=annonces-marche&v=index&modal=selection')) ?></code></p>
    <p class="hint">Elle lit les données à l’adresse <code><?= h($apiBase) ?>/selection</code> avec l’en-tête <code>X-Annonces-Token</code>.</p>
  </dialog>

  <?php if ($role === 'admin'): ?>
    <dialog class="am-modal am-modal-narrow" id="am-api-dialog" aria-labelledby="am-api-title">
      <div class="am-modal-header">
        <div>
          <p class="eyebrow">Accès sécurisé</p>
          <h2 id="am-api-title">Clé API et connexion Python</h2>
        </div>
        <button type="button" class="am-modal-close" data-close-modal aria-label="Fermer">×</button>
      </div>
      <p class="hint">La clé autorise l’accès aux données de cette boutique. Elle est affichée une seule fois lors de sa création. Vous pourrez la copier tant que cette fenêtre reste ouverte. Ne la partagez pas.</p>
      <section class="am-card">
        <h3>Clé API</h3>
        <?php if ($ent['has_token']): ?>
          <p class="hint">Une clé existe déjà (préfixe <code><?= h($ent['token_prefix']) ?></code>). La régénérer invalidera immédiatement l’ancienne.</p>
        <?php else: ?>
          <p class="hint">Aucune clé n’a encore été créée pour cette boutique.</p>
        <?php endif; ?>
        <div class="am-actions">
          <button type="button" class="btn-small" id="am-token" <?= $ent['entitled'] ? '' : 'disabled' ?>><?= $ent['has_token'] ? 'Régénérer la clé API' : 'Créer la clé API' ?></button>
          <button type="button" class="btn-small" id="am-token-copy" hidden>Copier la clé</button>
        </div>
        <pre class="am-token" id="am-token-out" hidden></pre>
        <p class="am-status" id="am-token-status" role="status" aria-live="polite"></p>
      </section>
      <section class="am-card">
        <h3>Paramètres de l’application</h3>
        <dl class="am-connection">
          <div><dt>URL de la sélection</dt><dd><code><?= h($apiBase) ?>/selection</code></dd></div>
          <div><dt>Méthode</dt><dd><code>GET</code></dd></div>
          <div><dt>En-tête</dt><dd><code>X-Annonces-Token: votre_clé_api</code></dd></div>
        </dl>
        <p class="hint">L’application peut ouvrir cette fenêtre dans le navigateur à cette adresse :</p>
        <p><code><?= h(shop_abs_url('/admin/plugin.php?p=annonces-marche&v=index&modal=token')) ?></code></p>
      </section>
    </dialog>
  <?php endif; ?>

  <section class="am-card">
    <h2>Historique récent</h2>
    <?php if (!$listings): ?>
      <p class="hint">Aucune publication enregistrée pour le moment.</p>
    <?php else: ?>
      <table class="am-table">
        <thead><tr><th>Article</th><th>Canal</th><th>Statut</th><th>Lien</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($listings as $row): ?>
          <tr>
            <td><?= h($row['product_name'] ?: $row['product_ref']) ?> <small class="muted"><?= h($row['product_ref']) ?></small></td>
            <td><?= h($row['channel']) ?></td>
            <td><?= h($row['status']) ?></td>
            <td><?php if ($row['listing_url'] !== ''): ?><a href="<?= h($row['listing_url']) ?>" target="_blank" rel="noopener">voir</a><?php else: ?>—<?php endif; ?></td>
            <td><small class="muted"><?= h($row['published_at'] ?: $row['created_at']) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>
<script src="<?= h($pluginAsset('admin.js')) ?>" defer></script>
