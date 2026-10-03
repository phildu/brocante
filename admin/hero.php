<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$slides = hero_slides_list();
$mediaPhotos = get_media_items('photo');
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diaporama du hero — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .gm-list { display: flex; flex-direction: column; gap: 16px; }
  .gm-item {
    display: grid; grid-template-columns: 120px 1fr auto; gap: 16px; align-items: center;
    padding: 16px; background: var(--surface); border: 1px solid var(--line);
  }
  .gm-thumb { width: 120px; height: 90px; background: var(--surface-2); overflow: hidden; }
  .gm-thumb img { width: 100%; height: 100%; object-fit: contain; }
  .gm-fields input[type="text"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem; max-width: 280px;
  }
  .gm-actions { display: flex; flex-direction: column; gap: 6px; align-items: stretch; min-width: 130px; }
  .gm-actions .btn-small { width: 100%; }
  .gm-order { display: flex; gap: 6px; }
  .gm-order button { flex: 1; }

  .media-pick-backdrop {
    position: fixed; inset: 0; background: rgba(20,15,8,0.7);
    display: none; align-items: center; justify-content: center; z-index: 100;
  }
  .media-pick-backdrop.is-open { display: flex; }
  .media-pick-box {
    background: var(--bg); border: 1px solid var(--line); padding: 20px;
    max-width: 92vw; max-height: 85vh; width: 720px; display: flex; flex-direction: column; gap: 14px;
  }
  .media-pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; overflow-y: auto; max-height: 60vh; }
  .media-pick-item { border: 1px solid var(--line); background: var(--surface); cursor: pointer; padding: 0; }
  .media-pick-item img { width: 100%; aspect-ratio: 4/3; object-fit: contain; background: var(--surface-2); display: block; }
  .media-pick-item span { display: block; font-size: 0.68rem; padding: 4px 6px; color: var(--ink-soft); }
  .media-pick-item:hover { outline: 2px solid var(--accent); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'hero'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Page d'accueil</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Diaporama du hero</h1>
    <p class="hint">Ces photos s'affichent en pile de polaroïds en quinconce sur la page d'accueil, mélangées automatiquement avec les pièces actuellement en promo. Sans photo ici, l'ancienne photo de couverture unique reste affichée.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="gm-list" style="margin-top:20px;">
      <?php foreach ($slides as $i => $s): ?>
        <div class="gm-item">
          <div class="gm-thumb"><img src="/<?= h($s['path']) ?>" alt=""></div>
          <div class="gm-fields">
            <form method="post" action="/admin/hero-action.php" style="display:flex;gap:8px;align-items:center;">
              <input type="hidden" name="action" value="caption">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="text" name="caption" value="<?= h($s['caption']) ?>" placeholder="Légende (optionnelle)">
              <button type="submit" class="btn-small">Renommer</button>
            </form>
          </div>
          <div class="gm-actions">
            <div class="gm-order">
              <form method="post" action="/admin/hero-action.php"><input type="hidden" name="action" value="move_up"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button type="submit" class="btn-small"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
              <form method="post" action="/admin/hero-action.php"><input type="hidden" name="action" value="move_down"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button type="submit" class="btn-small"<?= $i === count($slides) - 1 ? ' disabled' : '' ?>>↓</button></form>
            </div>
            <form method="post" action="/admin/hero-action.php" onsubmit="return confirm('Retirer cette photo du diaporama ?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="admin-delete" style="width:100%;">Supprimer</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$slides): ?>
        <p class="empty-state">Aucune photo pour l'instant.</p>
      <?php endif; ?>
    </div>

    <div class="admin-block" style="margin-top:24px;">
      <h2 style="font-size:1.1rem;">Ajouter une photo</h2>
      <div class="field-row" style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;">
        <form method="post" action="/admin/hero-action.php" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="action" value="add">
          <input type="text" name="caption" placeholder="Légende (optionnelle)" style="max-width:220px;">
          <input type="file" name="photo" accept="image/*" data-check-resolution required>
          <button type="submit" class="btn btn-primary">Envoyer</button>
        </form>
        <button type="button" class="btn-small" id="open-media-pick">Choisir dans la médiathèque</button>
      </div>
    </div>
  </div>
</section>
</main>

<div class="media-pick-backdrop" id="media-pick-backdrop">
  <div class="media-pick-box">
    <p class="eyebrow" style="margin:0;">Choisir une photo dans la médiathèque</p>
    <?php if (!$mediaPhotos): ?>
      <p class="empty-state">La médiathèque est vide pour l'instant. <a href="/admin/media.php">Ajouter des photos →</a></p>
    <?php else: ?>
      <div class="media-pick-grid">
        <?php foreach ($mediaPhotos as $m): ?>
          <button type="button" class="media-pick-item" data-media-path="<?= h($m['path']) ?>" data-media-label="<?= h($m['label']) ?>">
            <img src="/<?= h($m['path']) ?>" alt="<?= h($m['label']) ?>">
            <span><?= h($m['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <a class="btn-small" href="/admin/media.php" target="_blank">Gérer la médiathèque →</a>
      <button type="button" class="btn-small" id="media-pick-cancel">Annuler</button>
    </div>
  </div>
</div>

<form method="post" action="/admin/hero-action.php" id="media-pick-form">
  <input type="hidden" name="action" value="add_from_media">
  <input type="hidden" name="path" id="media-pick-path">
  <input type="hidden" name="caption" id="media-pick-caption">
</form>

<script>
(function () {
  var backdrop = document.getElementById('media-pick-backdrop');
  document.getElementById('open-media-pick').addEventListener('click', function () {
    backdrop.classList.add('is-open');
  });
  document.getElementById('media-pick-cancel').addEventListener('click', function () {
    backdrop.classList.remove('is-open');
  });
  document.querySelectorAll('[data-media-path]').forEach(function (item) {
    item.addEventListener('click', function () {
      document.getElementById('media-pick-path').value = item.dataset.mediaPath;
      document.getElementById('media-pick-caption').value = item.dataset.mediaLabel || '';
      document.getElementById('media-pick-form').submit();
    });
  });
})();
</script>
<script src="/assets/admin-upload-check.js"></script>
</body>
</html>
