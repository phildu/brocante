<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

$ent = am_entitlement();
$apiBase = rtrim(shop_abs_url('/'), '/') . '/api/annonces';
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page" id="am-page"
     data-api-token="<?= h($pluginApi('token')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>"
     data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>"
     data-has-token="<?= $ent['has_token'] ? '1' : '0' ?>">
  <p class="eyebrow"><a href="<?= h(plugin_page_url('annonces-marche', 'index')) ?>">← Annonces Leboncoin &amp; Vinted</a></p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Clé API et connexion Python</h1>
  <p class="hint" style="max-width:68ch">Cette clé autorise l’accès aux données de cette boutique. Elle est affichée une seule fois lors de sa création. Ne la partagez pas et ne l’intégrez pas dans un dépôt public.</p>

  <section class="am-card<?= $ent['entitled'] ? '' : ' is-dim' ?>">
    <h2>Clé API</h2>
    <?php if ($ent['has_token']): ?>
      <p class="hint">Une clé existe déjà (préfixe <code><?= h($ent['token_prefix']) ?></code>). Pour la remplacer, régénérez-la : l’ancienne sera immédiatement invalidée.</p>
    <?php else: ?>
      <p class="hint">Aucune clé n’a encore été créée pour cette boutique.</p>
    <?php endif; ?>
    <div class="am-actions">
      <button type="button" class="btn-small" id="am-token" <?= $ent['entitled'] ? '' : 'disabled' ?>><?= $ent['has_token'] ? 'Régénérer la clé API' : 'Créer la clé API' ?></button>
    </div>
    <pre class="am-token" id="am-token-out" hidden></pre>
    <p class="am-status" id="am-api-msg" role="status"></p>
  </section>

  <section class="am-card">
    <h2>Paramètres à fournir à l’application</h2>
    <dl class="am-connection">
      <div><dt>URL de la sélection</dt><dd><code><?= h($apiBase) ?>/selection</code></dd></div>
      <div><dt>Méthode</dt><dd><code>GET</code></dd></div>
      <div><dt>En-tête d’authentification</dt><dd><code>X-Annonces-Token: votre_clé_api</code></dd></div>
    </dl>
    <p class="hint">La clé est propre à cette boutique. Créez-en une distincte pour chaque boutique utilisée par l’application.</p>
  </section>
</div>
<script src="<?= h($pluginAsset('admin.js')) ?>" defer></script>
