<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autofeed.php';
require_admin();

$content = get_content();
$targets = page_banner_targets();
$banners = get_page_banners();
$mediaAll = get_media_items();
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bandeaux de page — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .banner-list { display: flex; flex-direction: column; gap: 16px; }
  .banner-item {
    display: grid; grid-template-columns: 160px 1fr auto; gap: 16px; align-items: center;
    padding: 16px; background: var(--surface); border: 1px solid var(--line);
  }
  .banner-thumb { width: 160px; height: 90px; background: var(--surface-2); overflow: hidden; display: flex; align-items: center; justify-content: center; }
  .banner-thumb img, .banner-thumb video { width: 100%; height: 100%; object-fit: contain; }
  .banner-thumb-empty { font-family: var(--font-mono); font-size: 0.66rem; color: var(--ink-soft); text-transform: uppercase; text-align: center; padding: 0 8px; }
  .banner-page-label { font-weight: 600; margin: 0 0 10px; }
  .banner-actions { display: flex; flex-direction: column; gap: 6px; align-items: stretch; min-width: 120px; }

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
  .media-pick-item img, .media-pick-item video { width: 100%; aspect-ratio: 4/3; object-fit: contain; background: var(--surface-2); display: block; }
  .media-pick-item span { display: block; font-size: 0.68rem; padding: 4px 6px; color: var(--ink-soft); }
  .media-pick-item:hover { outline: 2px solid var(--accent); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'banners'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Habillage du site</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Bandeaux de page</h1>
    <p class="hint">Une photo ou une courte vidéo en fond, avec un voile sombre optionnel, derrière le titre en haut de chaque page choisie. Sans média, la page garde son apparence actuelle.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="banner-list" style="margin-top:20px;">
      <?php foreach ($targets as $key => $label): $b = $banners[$key] ?? null; ?>
        <div class="banner-item">
          <div class="banner-thumb">
            <?php if ($b && $b['path']): ?>
              <?php if ($b['kind'] === 'video'): ?>
                <video src="/<?= h($b['path']) ?>" muted preload="metadata"></video>
              <?php else: ?>
                <img src="/<?= h($b['path']) ?>" alt="">
              <?php endif; ?>
            <?php else: ?>
              <span class="banner-thumb-empty">Aucun bandeau</span>
            <?php endif; ?>
          </div>
          <div>
            <p class="banner-page-label"><?= h($label) ?></p>
            <?php $overlayVal = $b ? (int) $b['overlay'] : 60; ?>
            <form method="post" action="/admin/banners-action.php" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
              <input type="hidden" name="action" value="set">
              <input type="hidden" name="page_key" value="<?= h($key) ?>">
              <input type="file" name="media" accept="image/*,video/mp4,video/quicktime,video/webm" data-check-resolution>
              <label style="display:flex;gap:8px;align-items:center;font-size:0.82rem;color:var(--ink-soft);">
                Voile sombre
                <input type="range" name="overlay" min="0" max="100" step="5" value="<?= $overlayVal ?>" class="banner-overlay-range"
                  oninput="this.nextElementSibling.textContent = this.value + ' %'">
                <span><?= $overlayVal ?> %</span>
              </label>
              <button type="submit" class="btn-small">Enregistrer</button>
              <button type="button" class="btn-small" data-open-media-pick data-page-key="<?= h($key) ?>">Choisir dans la médiathèque</button>
            </form>
          </div>
          <?php if (autofeed_api_key() !== ''): ?>
          <details style="grid-column:1/-1;order:5;">
            <summary style="cursor:pointer;font-weight:600;font-size:.88rem;">✨ Générer ce bandeau avec l'IA</summary>
            <form method="post" action="/admin/regenerate-image.php" class="ai-banner-form" style="display:grid;gap:8px;margin-top:8px;max-width:720px;"
              data-cost="<?= h(ai_format_eur(autofeed_regen_cost_eur())) ?>">
              <input type="hidden" name="target" value="banner"><input type="hidden" name="back" value="banners">
              <input type="hidden" name="page_key" value="<?= h($key) ?>">
              <label style="font-size:.82rem;color:var(--ink-soft);">Description de l'image (modifiable)
                <textarea name="prompt" rows="3" maxlength="700" style="width:100%;box-sizing:border-box;"><?= h(autofeed_banner_prompt($key)) ?></textarea></label>
              <div><button type="submit" class="btn-small">Générer le bandeau</button>
                <span class="hint">Environ <?= h(ai_format_eur(autofeed_regen_cost_eur())) ?>, décomptés de votre crédit IA. Remplace le bandeau actuel.</span></div>
            </form>
          </details>
          <?php endif; ?>
          <div class="banner-actions">
            <?php if ($b && $b['path']): ?>
              <form method="post" action="/admin/banners-action.php" onsubmit="return confirm('Retirer ce bandeau ?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="page_key" value="<?= h($key) ?>">
                <button type="submit" class="admin-delete" style="width:100%;">Supprimer</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (autofeed_api_key() !== ''): $sharedBanners = banner_library_list(); ?>
    <details style="margin-top:28px;border:1px solid var(--line);background:var(--surface);padding:16px;">
      <summary style="cursor:pointer;font-weight:600;">♻️ Réutiliser un bandeau déjà généré — médiathèque commune</summary>
      <p class="hint" style="margin:10px 0 14px;">Chaque bandeau produit par l'IA est conservé ici, pour toutes les boutiques. « Appliquer » réutilise l'image telle quelle, sans crédit IA : elle reste disponible pour les autres. « Réserver » rend l'illustration exclusive à votre boutique pour <?= h(ai_format_eur(autofeed_regen_cost_eur())) ?> : elle disparaît alors de la médiathèque des autres. Le titre de la page reste en texte par-dessus l'image.</p>
      <?php if ($sharedBanners): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;">
        <?php foreach (array_slice($sharedBanners, 0, 24) as $item): $mine = !empty($item['exclusive']) && (string) ($item['exclusive_by'] ?? '') === tenant_slug(); ?>
        <form method="post" action="/admin/banners-action.php" style="margin:0;border:1px solid var(--line);background:var(--bg);padding:8px;display:grid;gap:8px;">
          <input type="hidden" name="file" value="<?= h((string) $item['file']) ?>">
          <img src="/uploads/banner-library/<?= h((string) $item['file']) ?>" alt="" loading="lazy" title="<?= h((string) ($item['prompt'] ?? '')) ?>" style="width:100%;aspect-ratio:16/9;object-fit:cover;display:block;">
          <?php if ($mine): ?><span class="hint" style="font-size:.72rem;">🔒 Réservée à votre boutique</span><?php endif; ?>
          <label style="font-size:.76rem;color:var(--ink-soft);">Page
            <select name="page_key" style="width:100%;">
              <?php foreach ($targets as $k => $l): ?><option value="<?= h($k) ?>"<?= ($item['page'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label style="font-size:.76rem;color:var(--ink-soft);display:flex;gap:8px;align-items:center;">
            Voile
            <input type="range" name="overlay" min="0" max="100" step="5" value="60" style="flex:1;" oninput="this.nextElementSibling.textContent = this.value + ' %'">
            <span>60 %</span>
          </label>
          <button type="submit" name="action" value="set_from_library" class="btn-small">Appliquer</button>
          <?php if (!$mine): ?>
          <button type="submit" name="action" value="reserve_from_library" class="btn-small"
            onclick="return confirm('Réserver cette illustration à votre boutique ? <?= h(ai_format_eur(autofeed_regen_cost_eur())) ?> seront décomptés de votre crédit IA ; elle ne sera plus proposée aux autres boutiques.');">Réserver à ma boutique (<?= h(ai_format_eur(autofeed_regen_cost_eur())) ?>)</button>
          <?php endif; ?>
        </form>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="hint">Aucun bandeau partagé pour l'instant : la première génération alimentera la médiathèque commune.</p>
      <?php endif; ?>
    </details>
    <?php endif; ?>
  </div>
</section>
</main>

<div class="media-pick-backdrop" id="media-pick-backdrop">
  <div class="media-pick-box">
    <p class="eyebrow" style="margin:0;">Choisir dans la médiathèque</p>
    <?php if (!$mediaAll): ?>
      <p class="empty-state">La médiathèque est vide pour l'instant. <a href="/admin/media.php">Ajouter des médias →</a></p>
    <?php else: ?>
      <div class="media-pick-grid">
        <?php foreach ($mediaAll as $m): ?>
          <button type="button" class="media-pick-item" data-media-path="<?= h($m['path']) ?>" data-media-type="<?= h($m['type']) ?>">
            <?php if ($m['type'] === 'video'): ?>
              <video src="/<?= h($m['path']) ?>" muted></video>
            <?php else: ?>
              <img src="/<?= h($m['path']) ?>" alt="<?= h($m['label']) ?>">
            <?php endif; ?>
            <span><?= h($m['label']) ?><?= $m['type'] === 'video' ? ' (vidéo)' : '' ?></span>
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

<form method="post" action="/admin/banners-action.php" id="media-pick-form">
  <input type="hidden" name="action" value="set_from_media">
  <input type="hidden" name="page_key" id="media-pick-page-key">
  <input type="hidden" name="path" id="media-pick-path">
  <input type="hidden" name="overlay" id="media-pick-overlay">
</form>

<script>
(function () {
  var backdrop = document.getElementById('media-pick-backdrop');
  var targetKey = null;

  document.querySelectorAll('[data-open-media-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      targetKey = btn.dataset.pageKey;
      backdrop.classList.add('is-open');
    });
  });
  document.getElementById('media-pick-cancel').addEventListener('click', function () {
    backdrop.classList.remove('is-open');
  });
  document.querySelectorAll('[data-media-path]').forEach(function (item) {
    item.addEventListener('click', function () {
      if (!targetKey) return;
      var row = document.querySelector('[data-open-media-pick][data-page-key="' + targetKey + '"]').closest('form');
      var overlay = row.querySelector('.banner-overlay-range').value;
      document.getElementById('media-pick-page-key').value = targetKey;
      document.getElementById('media-pick-path').value = item.dataset.mediaPath;
      document.getElementById('media-pick-overlay').value = overlay;
      document.getElementById('media-pick-form').submit();
    });
  });
})();
</script>

<script src="/assets/admin-upload-check.js"></script>
<script src="/assets/lightbox.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/lightbox.js') ?>" defer></script>
<script>
document.querySelectorAll('.ai-banner-form').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (!confirm('Générer ce bandeau avec l\u2019IA ? Coût estimé : ' + f.dataset.cost + ' (décompté de votre crédit IA).')) { e.preventDefault(); return; }
    var b = f.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Génération en cours (~30 s)…';
  });
});
</script>
</body>
</html>
