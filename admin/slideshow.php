<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$slides = slideshow_slides_list();
$mediaAll = get_media_items();
$products = get_products(null, true);
$flash = flash_get();

$kindLabels = ['video' => 'Vidéo', 'ambiance' => "Image d'ambiance", 'product' => 'Fiche produit'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diaporama boutique — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<style>
  .gm-list { display: flex; flex-direction: column; gap: 16px; }
  .gm-item {
    display: grid; grid-template-columns: 120px 1fr auto; gap: 16px; align-items: center;
    padding: 16px; background: var(--surface); border: 1px solid var(--line);
  }
  .gm-thumb { width: 120px; height: 90px; background: var(--surface-2); overflow: hidden; display:flex; align-items:center; justify-content:center; }
  .gm-thumb img, .gm-thumb video { width: 100%; height: 100%; object-fit: cover; }
  .gm-thumb-empty { font-family: var(--font-mono); font-size: 0.68rem; color: var(--ink-soft); text-transform: uppercase; }
  .gm-kind-label { font-family: var(--font-mono); font-size: 0.68rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ink-soft); margin: 0 0 8px; }
  .gm-fields input[type="text"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem; max-width: 220px;
  }
  .gm-fields input[type="number"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem;
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
  .media-pick-item img, .media-pick-item video { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; }
  .media-pick-item span { display: block; font-size: 0.68rem; padding: 4px 6px; color: var(--ink-soft); }
  .media-pick-item:hover { outline: 2px solid var(--accent); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'slideshow'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Écran boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Diaporama boutique</h1>
    <p class="hint">Composez un diaporama à diffuser sur un écran ou une tablette en magasin : vidéos, images d'ambiance, fiches produits. Chaque diapositive a sa propre durée d'affichage.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Réglages d'affichage</h2>
      <form method="post" action="/admin/slideshow-action.php" style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin-top:14px;">
        <input type="hidden" name="action" value="set_orientation">
        <label style="display:flex;gap:6px;align-items:center;">
          <input type="radio" name="orientation" value="horizontal" <?= $content['slideshow_orientation'] === 'horizontal' ? 'checked' : '' ?>>
          Horizontal (écran large / TV)
        </label>
        <label style="display:flex;gap:6px;align-items:center;">
          <input type="radio" name="orientation" value="vertical" <?= $content['slideshow_orientation'] === 'vertical' ? 'checked' : '' ?>>
          Vertical (écran ou tablette en portrait)
        </label>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </form>
      <a class="btn btn-primary" href="/slideshow.php" target="_blank" style="margin-top:16px;display:inline-flex;">Lancer le diaporama</a>
      <p class="hint" style="margin-top:14px;">
        À ouvrir en plein écran sur l'appareil connecté à l'écran boutique : <strong><?= h(SITE_URL) ?>/slideshow.php</strong>
        — <a href="/slideshow.php?orientation=horizontal" target="_blank">aperçu horizontal</a> ·
        <a href="/slideshow.php?orientation=vertical" target="_blank">aperçu vertical</a>
      </p>
    </div>

    <div class="gm-list" style="margin-top:24px;">
      <?php foreach ($slides as $i => $s):
        $p = $s['kind'] === 'product' ? get_product((string) $s['product_ref']) : null;
      ?>
        <div class="gm-item">
          <div class="gm-thumb">
            <?php if ($s['kind'] === 'product'): ?>
              <?php if ($p && !empty($p['photo'])): ?><img src="/<?= h($p['photo']) ?>" alt=""><?php else: ?><span class="gm-thumb-empty">Produit</span><?php endif; ?>
            <?php elseif ($s['kind'] === 'video'): ?>
              <video src="/<?= h($s['path']) ?>" muted preload="metadata"></video>
            <?php else: ?>
              <img src="/<?= h($s['path']) ?>" alt="">
            <?php endif; ?>
          </div>
          <div class="gm-fields">
            <p class="gm-kind-label"><?= h($kindLabels[$s['kind']] ?? $s['kind']) ?><?php if ($s['kind'] === 'product'): ?> — <?= h($p ? $p['name'] : 'produit introuvable') ?><?php endif; ?></p>
            <form method="post" action="/admin/slideshow-action.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <?php if ($s['kind'] !== 'product'): ?>
                <input type="text" name="caption" value="<?= h($s['caption']) ?>" placeholder="Légende (optionnelle)">
              <?php endif; ?>
              <label style="display:flex;gap:6px;align-items:center;font-size:0.82rem;color:var(--ink-soft);">
                Durée
                <input type="number" name="duration_seconds" value="<?= (int) $s['duration_seconds'] ?>" min="0" max="120">
                s<?= $s['kind'] === 'video' ? ' (0 = durée de la vidéo)' : '' ?>
              </label>
              <button type="submit" class="btn-small">Mettre à jour</button>
            </form>
          </div>
          <div class="gm-actions">
            <div class="gm-order">
              <form method="post" action="/admin/slideshow-action.php"><input type="hidden" name="action" value="move_up"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button type="submit" class="btn-small"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
              <form method="post" action="/admin/slideshow-action.php"><input type="hidden" name="action" value="move_down"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button type="submit" class="btn-small"<?= $i === count($slides) - 1 ? ' disabled' : '' ?>>↓</button></form>
            </div>
            <form method="post" action="/admin/slideshow-action.php" onsubmit="return confirm('Retirer cette diapositive ?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="admin-delete" style="width:100%;">Supprimer</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$slides): ?>
        <p class="empty-state">Aucune diapositive pour l'instant.</p>
      <?php endif; ?>
    </div>

    <div class="admin-block" style="margin-top:24px;">
      <h2 style="font-size:1.1rem;">Ajouter une vidéo ou une image d'ambiance</h2>
      <div class="field-row" style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
        <form method="post" action="/admin/slideshow-action.php" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="action" value="add_media">
          <input type="text" name="caption" placeholder="Légende (optionnelle)" style="max-width:200px;">
          <label style="display:flex;gap:6px;align-items:center;font-size:0.82rem;color:var(--ink-soft);">
            Durée <input type="number" name="duration_seconds" min="0" max="120" value="8" style="width:64px;">s
          </label>
          <input type="file" name="media" accept="image/*,video/mp4,video/quicktime,video/webm" data-check-resolution required>
          <button type="submit" class="btn btn-primary">Envoyer</button>
        </form>
        <button type="button" class="btn-small" id="open-media-pick">Choisir dans la médiathèque</button>
      </div>
      <p class="hint" style="margin-top:10px;">Pour une vidéo, une durée de 0 laisse le diaporama attendre la fin de la lecture avant de passer à la suite.</p>
    </div>

    <div class="admin-block" style="margin-top:24px;">
      <h2 style="font-size:1.1rem;">Ajouter une fiche produit</h2>
      <form method="post" action="/admin/slideshow-action.php" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
        <input type="hidden" name="action" value="add_product">
        <select name="ref" required style="max-width:320px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:9px 10px;font-family:var(--font-body);">
          <option value="">— Choisir un produit —</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= h($p['ref']) ?>">
              <?= h($p['name']) ?> (Réf. <?= h($p['ref']) ?>)<?= $p['is_hidden'] ? ' — masqué' : '' ?><?= (int) $p['stock'] <= 0 ? ' — épuisé' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <label style="display:flex;gap:6px;align-items:center;font-size:0.82rem;color:var(--ink-soft);">
          Durée <input type="number" name="duration_seconds" min="1" max="120" value="8" style="width:64px;background:var(--bg);border:1px solid var(--line);color:var(--ink);padding:8px 10px;">s
        </label>
        <button type="submit" class="btn btn-primary">Ajouter</button>
      </form>
      <p class="hint" style="margin-top:10px;">Les fiches masquées ou en rupture n'apparaissent pas dans le diaporama tant qu'elles ne redeviennent pas disponibles.</p>
    </div>
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
          <button type="button" class="media-pick-item" data-media-path="<?= h($m['path']) ?>" data-media-type="<?= h($m['type']) ?>" data-media-label="<?= h($m['label']) ?>">
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

<form method="post" action="/admin/slideshow-action.php" id="media-pick-form">
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
