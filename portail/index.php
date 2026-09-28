<?php
require __DIR__ . '/_bootstrap.php';

$shops = tenant_list();
$flash = portail_flash();
$confirmReset = $_GET['reinitialiser'] ?? '';
$editAccess = $_GET['acces'] ?? '';

// Chaque commerce a sa propre adresse : <slug>.brocenstock.test.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = tenant_base_host();
$viewing = (string) ($_GET['voir'] ?? active_slug());
if (!isset($shops[$viewing])) {
    $viewing = array_key_first($shops);
}
// Base de démonstration créée au premier affichage d'un commerce.
if (!is_file(tenant_file($shops[$viewing], 'db_file'))) {
    seed_tenant($shops[$viewing]);
}
$shopUrl = static fn (string $slug): string => "$scheme://$slug.$base";
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand">
      <h1>Portail des commerces</h1>
      <p>Une adresse par commerce, un formulaire pour en créer un nouveau</p>
    </div>
    <a class="btn btn-primary" href="/portail/nouveau.php">Nouveau commerce</a>
  </header>

  <?php if ($flash): ?>
    <p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
  <?php endif; ?>

  <div class="layout">
    <aside class="side">
      <div class="shop-list">
        <?php foreach ($shops as $slug => $shop): $isActive = $slug === $viewing; $look = portail_appearance($shop); ?>
          <div class="shop<?= $isActive ? ' is-active' : '' ?>">
            <span class="swatch" style="background:<?= e($look['colors']['accent']) ?>"></span>
            <h3><?= e($shop['name']) ?><?php if ($isActive): ?> <span class="pill done">À l'écran</span><?php endif; ?></h3>
            <span class="look" title="Apparence<?= $look['custom'] ? ' réglée dans l\'administration du commerce' : ' de départ' ?>">
              <span class="look-colors" aria-hidden="true">
                <?php foreach (['bg', 'accent', 'accent-2', 'ink'] as $key): ?><span style="background:<?= e($look['colors'][$key]) ?>"></span><?php endforeach; ?>
              </span>
              <span class="meta">Titres <?= e($look['fonts']['display']) ?> · textes <?= e($look['fonts']['body']) ?></span>
            </span>
            <span class="meta">admin : <?= e($shop['admin_user']) ?></span>
            <a class="meta" href="<?= e($shopUrl($slug)) ?>/" target="_blank" rel="noopener"><?= e("$slug.$base") ?> ↗</a>
            <div class="actions">
              <?php if (!$isActive): ?>
                <a class="btn small btn-primary" href="/portail/?voir=<?= e($slug) ?>">Voir</a>
              <?php endif; ?>
              <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>&amp;acces=<?= e($slug) ?>">Changer l'accès admin</a>
              <?php if ($slug !== TENANT_DEFAULT): ?>
                <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>&amp;reinitialiser=<?= e($slug) ?>">Réinitialiser les données</a>
              <?php endif; ?>
            </div>
            <?php if ($editAccess === $slug): ?>
              <form class="confirm access" method="post" action="/portail/action.php" autocomplete="off">
                <span>Nouvel accès à l'administration de <strong><?= e($shop['name']) ?></strong> (<?= e("$slug.$base") ?>/admin/) :</span>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="acces">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <label class="field">Identifiant<input name="admin_user" required pattern="[A-Za-z0-9._@\-]{3,40}" maxlength="40" autocapitalize="none" value="<?= e($shop['admin_user']) ?>"></label>
                <label class="field">Nouveau mot de passe
                  <span class="password-field">
                    <input type="password" name="admin_password" id="pw-<?= e($slug) ?>" required minlength="6" maxlength="60" autocomplete="new-password" placeholder="6 caractères minimum">
                    <button type="button" class="password-toggle" data-toggle="pw-<?= e($slug) ?>" aria-pressed="false">Afficher</button>
                  </span>
                </label>
                <span class="actions" style="margin:0;">
                  <button class="btn small btn-primary" type="submit">Enregistrer</button>
                  <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>">Annuler</a>
                </span>
              </form>
            <?php endif; ?>
            <?php if ($confirmReset === $slug): ?>
              <form class="confirm" method="post" action="/portail/action.php">
                <span>Remettre le contenu et les produits de <strong><?= e($shop['name']) ?></strong> à leur état de départ (seed-data.json) ? Les modifications faites dans l'administration seront perdues.</span>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reinitialiser">
                <input type="hidden" name="slug" value="<?= e($slug) ?>">
                <span class="actions" style="margin:0;">
                  <button class="btn small btn-primary" type="submit">Réinitialiser</button>
                  <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>">Annuler</a>
                </span>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="hint">Chaque commerce s'ouvre à l'adresse <code>&lt;identifiant&gt;.<?= e($base) ?></code>. Sans sous-domaine, <code><?= e($base) ?></code> affiche <?= e($shops[active_slug()]['name'] ?? '') ?>. Le Petit Chalet utilise toujours sa base <code>brocante.db</code>, jamais réinitialisée depuis ce portail.</p>
    </aside>

    <section class="main-stage">
      <div class="toolbar">
        <div class="seg" id="pages" role="group" aria-label="Page">
          <button type="button" data-src="/" aria-pressed="true">Accueil</button>
          <button type="button" data-src="/boutique.php" aria-pressed="false">Boutique</button>
          <button type="button" data-src="/cart.php" aria-pressed="false">Panier</button>
          <button type="button" data-src="/admin/" aria-pressed="false">Administration</button>
        </div>
        <div class="seg" id="device" role="group" aria-label="Format">
          <button type="button" data-device="desktop" aria-pressed="true">Ordinateur</button>
          <button type="button" data-device="mobile" aria-pressed="false">Mobile</button>
        </div>
        <a class="btn" href="<?= e($shopUrl($viewing)) ?>/" target="_blank" rel="noopener">Ouvrir <?= e("$viewing.$base") ?> ↗</a>
      </div>
      <div class="stage" id="stage">
        <iframe id="frame" src="<?= e($shopUrl($viewing)) ?>/" data-origin="<?= e($shopUrl($viewing)) ?>" title="Boutique <?= e($shops[$viewing]['name']) ?>"></iframe>
      </div>
    </section>
  </div>
</div>
<script>
(function () {
  var frame = document.getElementById('frame');
  function press(group, btn) {
    group.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
  }
  document.getElementById('pages').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    press(this, b); frame.src = frame.dataset.origin + b.dataset.src;
  });
  document.getElementById('device').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    press(this, b); document.getElementById('stage').classList.toggle('mobile', b.dataset.device === 'mobile');
  });
  // Afficher / masquer le mot de passe saisi.
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.toggle);
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Masquer' : 'Afficher';
      btn.setAttribute('aria-pressed', String(show));
      input.focus();
    });
  });
})();
</script>
</body>
</html>
