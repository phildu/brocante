<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$slides = hero_slides_list();
require_once __DIR__ . '/../includes/autofeed.php';
$mediaPhotos = get_media_items('photo');
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diaporama du hero — <?= h($content['site_name']) ?></title>
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

  .slide-add-head { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
  .slide-counter { display: inline-flex; align-items: stretch; border: 1px solid var(--line); background: var(--bg); }
  .slide-counter button {
    width: 38px; border: none; background: var(--surface); color: var(--ink);
    font-size: 1.1rem; cursor: pointer;
  }
  .slide-counter button:hover { background: var(--surface-2); }
  .slide-counter input {
    width: 58px; border: none; border-left: 1px solid var(--line); border-right: 1px solid var(--line);
    background: var(--bg); color: var(--ink); text-align: center;
    font-family: var(--font-body); font-size: 0.95rem; padding: 8px 0; -moz-appearance: textfield;
  }
  .slide-counter input::-webkit-outer-spin-button, .slide-counter input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .slide-file-list { display: grid; gap: 10px; margin-top: 16px; max-width: 720px; }
  .slide-file-row { display: grid; grid-template-columns: 110px 1fr; gap: 10px; align-items: center; font-size: 0.85rem; color: var(--ink-soft); }
  .slide-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 14px; }
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
            <form method="post" action="/admin/regenerate-image.php" style="display:flex;gap:8px;align-items:center;margin-top:6px;" onsubmit="return confirm('Générer une nouvelle image pour cette photo ? Elle la remplace et sera décomptée de votre crédit IA (≈ <?= h(ai_format_eur(autofeed_regen_cost_eur())) ?>).');">
              <input type="hidden" name="target" value="slide"><input type="hidden" name="back" value="hero">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="text" name="hint" maxlength="200" placeholder="Précisez (facultatif)">
              <button type="submit" class="btn-small">✨ Régénérer</button>
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

    <div class="admin-block" style="margin-top:24px;" id="slides-ajout">
      <h2 style="font-size:1.1rem;">Ajouter des slides</h2>
      <p class="hint" style="margin-top:10px;">Choisissez le nombre de slides à ajouter, puis envoyez des photos ou générez-les avec l'IA. Chaque image est aussi conservée dans la <a href="/admin/media.php">médiathèque</a>.</p>
      <form method="post" action="/admin/hero-action.php" enctype="multipart/form-data" id="slide-add-form"
        data-ai-cost="<?= h((string) autofeed_regen_cost_eur()) ?>">
        <input type="hidden" name="action" value="add_multi">
        <input type="hidden" name="back" value="hero">
        <div class="slide-add-head">
          <label for="slide-count" style="font-weight:600;">Nombre de slides</label>
          <div class="slide-counter">
            <button type="button" data-count-step="-1" aria-label="Diminuer le nombre de slides">−</button>
            <input type="number" id="slide-count" name="count" value="1" min="1" max="10" inputmode="numeric" aria-label="Nombre de slides">
            <button type="button" data-count-step="1" aria-label="Augmenter le nombre de slides">+</button>
          </div>
          <span class="hint" style="margin:0;">de 1 à 10</span>
        </div>
        <div class="slide-file-list" id="slide-file-list"></div>
        <div class="field-row" style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;">
          <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--ink-soft);">Légende des photos envoyées (optionnelle)
            <input type="text" name="caption" maxlength="120" placeholder="ex : Arrivage de céramiques" style="max-width:280px;">
          </label>
          <?php if (autofeed_api_key() !== ''): ?>
          <label style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;color:var(--ink-soft);">Description pour l'IA (optionnelle)
            <input type="text" name="hint" maxlength="200" placeholder="ex : ambiance lumineuse, vases anciens" style="max-width:320px;">
          </label>
          <?php endif; ?>
        </div>
        <div class="slide-actions">
          <button type="submit" class="btn btn-primary" id="slide-upload-btn">Envoyer les photos</button>
          <?php if (autofeed_api_key() !== ''): ?>
          <button type="submit" class="btn" id="slide-ai-btn" formaction="/admin/regenerate-image.php" formnovalidate name="target" value="hero_slides">✨ Générer <span id="slide-ai-count">1</span> <span id="slide-ai-word">slide</span> avec l'IA</button>
          <?php endif; ?>
          <button type="button" class="btn-small" id="open-media-pick">Choisir dans la médiathèque</button>
        </div>
        <?php if (autofeed_api_key() !== ''): ?>
        <p class="hint" id="slide-ai-cost" style="margin-top:10px;">Coût estimé : <?= h(ai_format_eur(autofeed_regen_cost_eur())) ?> pour 1 image, décompté de votre crédit IA. Chaque image est ajoutée au diaporama et à la médiathèque.</p>
        <?php endif; ?>
      </form>
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
<script>
(function () {
  var form = document.getElementById('slide-add-form');
  if (!form) return;
  var countInput = document.getElementById('slide-count');
  var list = document.getElementById('slide-file-list');
  var aiBtn = document.getElementById('slide-ai-btn');
  var uploadBtn = document.getElementById('slide-upload-btn');
  var aiCount = document.getElementById('slide-ai-count');
  var aiWord = document.getElementById('slide-ai-word');
  var costEl = document.getElementById('slide-ai-cost');
  var unit = parseFloat(form.dataset.aiCost || '0');

  function clamp(n) { return Math.max(1, Math.min(10, n || 1)); }
  function costLabel(n) {
    var v = unit * n;
    if (v > 0 && v < 0.005) return '< 0,01 €';
    return v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  function render() {
    var n = clamp(parseInt(countInput.value, 10));
    countInput.value = n;
    list.innerHTML = '';
    for (var i = 0; i < n; i++) {
      var row = document.createElement('label');
      row.className = 'slide-file-row';
      var caption = document.createElement('span');
      caption.textContent = 'Photo ' + (i + 1);
      var input = document.createElement('input');
      input.type = 'file';
      input.name = 'photo_' + i;
      input.accept = 'image/*';
      input.setAttribute('data-check-resolution', '');
      row.appendChild(caption);
      row.appendChild(input);
      list.appendChild(row);
    }
    if (aiCount) aiCount.textContent = String(n);
    if (aiWord) aiWord.textContent = n > 1 ? 'slides' : 'slide';
    if (costEl) {
      costEl.textContent = 'Coût estimé : ' + costLabel(n) + ' pour ' + n + ' image' + (n > 1 ? 's' : '')
        + ', décompté de votre crédit IA. Chaque image est ajoutée au diaporama et à la médiathèque.';
    }
  }
  countInput.addEventListener('input', render);
  document.querySelectorAll('[data-count-step]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      countInput.value = clamp((parseInt(countInput.value, 10) || 1) + parseInt(btn.dataset.countStep, 10));
      render();
    });
  });
  if (aiBtn) {
    form.addEventListener('submit', function (e) {
      var submitter = e.submitter || document.activeElement;
      if (submitter !== aiBtn) return;
      var n = clamp(parseInt(countInput.value, 10));
      if (!confirm('Générer ' + n + ' slide' + (n > 1 ? 's' : '') + ' avec l’IA ? Coût estimé : ' + costLabel(n) + ' (décompté de votre crédit IA).')) {
        e.preventDefault();
        return;
      }
      aiBtn.disabled = true;
      if (uploadBtn) uploadBtn.disabled = true;
      aiBtn.textContent = 'Génération en cours…';
      if (costEl) costEl.textContent = 'Génération de ' + n + ' image' + (n > 1 ? 's' : '') + ' en cours : cela peut durer quelques minutes. Ne fermez pas la page.';
    });
  }
  render();
})();
</script>
<script src="/assets/admin-upload-check.js"></script>
<script src="/assets/lightbox.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/lightbox.js') ?>" defer></script>
</body>
</html>
