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
$zipUrl = $pluginApi('extension-zip');
?>
<link rel="stylesheet" href="<?= h($pluginAsset('admin.css')) ?>">
<div class="am-page" id="am-page"
     data-api-status="<?= h($pluginApi('status')) ?>"
     data-api-token="<?= h($pluginApi('token')) ?>"
     data-api-subscribe="<?= h($pluginApi('subscribe')) ?>"
     data-api-trial="<?= h($pluginApi('trial')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>"
     data-entitled="<?= $ent['entitled'] ? '1' : '0' ?>">

  <p class="eyebrow">Option · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 8px;">Annonces Leboncoin &amp; Vinted</h1>
  <p class="hint" style="max-width:62ch;">
    Remplissez les formulaires de dépôt à partir du catalogue de <strong><?= h($content['site_name']) ?></strong>.
    L’extension prépare l’annonce ; <strong>vous restez connecté sur le site et vous validez la publication</strong>
    (pas de robot qui publie à votre place).
  </p>

  <section class="am-card" id="am-billing">
    <h2>Abonnement <span class="am-pill" data-am-status><?= h($ent['label']) ?></span></h2>
    <p class="hint"><?= h($ent['addon_name']) ?> — <?= h($ent['monthly_label']) ?>.</p>
    <?php if ($ent['trial_until']): ?>
      <p class="hint">Essai jusqu’au <?= h($ent['trial_until']) ?>.</p>
    <?php endif; ?>
    <div class="am-actions" id="am-billing-actions">
      <?php if (!$ent['entitled']): ?>
        <?php if (!$ent['trial_used']): ?>
          <button type="button" class="btn-small" id="am-trial">Essai gratuit <?= (int) AM_TRIAL_DAYS ?> jours</button>
        <?php endif; ?>
        <button type="button" class="btn-small" id="am-subscribe" style="background:var(--accent);color:var(--accent-ink);border-color:var(--accent);">Souscrire — <?= h($ent['monthly_label']) ?></button>
      <?php else: ?>
        <p class="hint am-ok">Option active : vous pouvez générer un jeton et utiliser l’extension.</p>
      <?php endif; ?>
    </div>
    <p class="am-status" id="am-billing-msg" role="status"></p>
  </section>

  <section class="am-card<?= $ent['entitled'] ? '' : ' is-dim' ?>">
    <h2>1. Jeton pour l’extension</h2>
    <p class="hint">Collez l’adresse de la boutique et ce jeton dans l’extension Chrome. Le jeton complet n’est montré qu’une fois.</p>
    <?php if ($ent['has_token']): ?>
      <p class="hint">Jeton actuel : <code><?= h($ent['token_prefix']) ?></code></p>
    <?php endif; ?>
    <div class="am-actions">
      <button type="button" class="btn-small" id="am-token" <?= $ent['entitled'] ? '' : 'disabled' ?>><?= $ent['has_token'] ? 'Régénérer le jeton' : 'Créer un jeton' ?></button>
    </div>
    <pre class="am-token" id="am-token-out" hidden></pre>
  </section>

  <section class="am-card<?= $ent['entitled'] ? '' : ' is-dim' ?>">
    <h2>2. Extension Chrome</h2>
    <ol class="am-steps">
      <li>Téléchargez l’archive, décompressez-la.</li>
      <li>Ouvrez <code>chrome://extensions</code>, activez le « mode développeur », « Charger l’extension non empaquetée », choisissez le dossier.</li>
      <li>Dans l’extension : adresse de la boutique <code><?= h(rtrim(shop_abs_url('/'), '/')) ?></code> (ou directement <code><?= h($apiBase) ?></code>) + jeton.</li>
      <li>Sur Leboncoin ou Vinted, ouvrez « Déposer une annonce », choisissez un article dans l’extension, puis validez à la main.</li>
    </ol>
    <div class="am-actions">
      <a class="btn-small" href="<?= h($zipUrl) ?>">Télécharger l’extension (.zip)</a>
    </div>
  </section>

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
