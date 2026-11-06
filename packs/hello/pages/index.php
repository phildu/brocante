<?php
// Page de démonstration : prouve que le socle charge le plugin (manifeste, rôle, base de la boutique, API, CSRF, ressources).
defined('PLUGIN_RUNTIME') || exit;
?>
<link rel="stylesheet" href="<?= h($pluginAsset('hello.css')) ?>">
<p class="eyebrow">Plugin · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
<h1 style="font-size:1.8rem;margin:12px 0 12px;">Bonjour !</h1>
<p class="hint">
  Le socle des plugins fonctionne pour <strong><?= h($content['site_name']) ?></strong> : cette page vient de
  <code>packs/<?= h($plugin['id']) ?>/</code>, vous êtes connecté en tant que <strong><?= h(account_role_label($role)) ?></strong>,
  et les notes ci-dessous sont rangées dans la base de cette boutique seulement.
</p>

<div class="admin-block hello-box" id="hello" data-api-notes="<?= h($pluginApi('notes')) ?>" data-api-delete="<?= h($pluginApi('notes-delete')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <form class="hello-form" id="hello-form">
    <input type="text" name="text" maxlength="200" placeholder="Une note de test…" required aria-label="Note">
    <button type="submit" class="btn-small">Ajouter</button>
  </form>
  <p class="hello-status" id="hello-status" role="status"></p>
  <ul class="hello-notes" id="hello-notes"></ul>
</div>
<script src="<?= h($pluginAsset('hello.js')) ?>" defer></script>
