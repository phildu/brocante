<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$type = (string) ($_GET['type'] ?? '');
$type = in_array($type, ['photo', 'video'], true) ? $type : null;
$q = trim((string) ($_GET['q'] ?? ''));
$items = get_media_items($type, $q !== '' ? $q : null);
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Médiathèque — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
  .media-item { background: var(--surface); border: 1px solid var(--line); padding: 12px; display: flex; flex-direction: column; gap: 8px; }
  .media-thumb { width: 100%; aspect-ratio: 4/3; background: var(--surface-2); overflow: hidden; position: relative; }
  .media-thumb img, .media-thumb video { width: 100%; height: 100%; object-fit: cover; }
  .media-dims {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    background: rgba(20,15,8,0.6); color: #fff; font-family: var(--font-mono); font-size: 0.85rem;
    letter-spacing: 0.02em; opacity: 0; transition: opacity 0.15s ease; pointer-events: none;
  }
  .media-thumb:hover .media-dims { opacity: 1; }
  .media-origin { font-family: var(--font-mono); font-size: 0.65rem; color: var(--accent); text-transform: uppercase; }
  .media-item input[type="text"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 6px 8px; font-family: var(--font-body); font-size: 0.82rem; width: 100%;
  }
  .media-filters { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
  .media-filters select, .media-filters input[type="search"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem;
  }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'media'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:2rem;margin:12px 0 20px;">Médiathèque (<?= count($items) ?>)</h1>
    <p class="hint">Toutes les ressources (photos, vidéos) réutilisables : imports réseaux sociaux, photos conservées après suppression d'une fiche, ajouts manuels.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <form method="get" action="/admin/media.php" class="media-filters">
      <select name="type" onchange="this.form.submit()">
        <option value="">Tous types</option>
        <option value="photo"<?= $type === 'photo' ? ' selected' : '' ?>>Photos</option>
        <option value="video"<?= $type === 'video' ? ' selected' : '' ?>>Vidéos</option>
      </select>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher (libellé, source, tags, article d'origine)...">
      <button type="submit" class="btn-small">Filtrer</button>
      <?php if ($type || $q !== ''): ?><a class="btn-small" href="/admin/media.php">Réinitialiser</a><?php endif; ?>
    </form>

    <div class="admin-block" style="margin-bottom:28px;">
      <h2 style="font-size:1.1rem;">Ajouter une ressource</h2>
      <p class="hint">Photo ou courte vidéo (mp4, mov, webm) récupérée d'un réseau social ou ajoutée manuellement.</p>
      <form method="post" action="/admin/media-action.php" enctype="multipart/form-data" style="margin-top:14px;" id="media-add-form">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="filename_slug" id="media-add-slug">
        <div class="field-row-3">
          <div class="field"><label>Fichier</label><input type="file" name="media" accept="image/*,video/mp4,video/quicktime,video/webm" data-check-resolution id="media-add-file" required></div>
          <div class="field"><label>Libellé</label><input type="text" name="label" id="media-add-label" placeholder="ex : Étagère de vases et pichets anciens"></div>
          <div class="field"><label>Source</label><input type="text" name="source" placeholder="ex : Instagram, Facebook, atelier..."></div>
        </div>
        <div class="field"><label>Tags (optionnel, séparés par des virgules)</label><input type="text" name="tags" id="media-add-tags" placeholder="ex : céramique, vitrine, reel"></div>
        <p class="hint" id="media-add-ai-status" style="margin:8px 0 0;min-height:1.2em;"></p>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Ajouter à la médiathèque</button>
      </form>
    </div>

    <?php if (!$items): ?>
      <p class="empty-state">Aucune ressource pour l'instant.</p>
    <?php else: ?>
      <div class="media-grid">
        <?php foreach ($items as $m): ?>
          <div class="media-item">
            <div class="media-thumb"<?= $m['type'] !== 'video' ? ' data-quick-preview="/' . h($m['path']) . '" data-quick-name="' . h($m['label']) . '"' : '' ?>>
              <?php if ($m['type'] === 'video'): ?>
                <video src="/<?= h($m['path']) ?>" preload="metadata" controls muted></video>
              <?php else: ?>
                <img src="/<?= h($m['path']) ?>" alt="<?= h($m['label']) ?>">
              <?php endif; ?>
              <?php $dims = media_item_dimensions($m); ?>
              <?php if ($dims): ?><span class="media-dims"><?= h($dims) ?></span><?php endif; ?>
            </div>
            <?php if ($m['origin_name']): ?>
              <?php $stillLive = $m['origin_ref'] && get_product($m['origin_ref']); ?>
              <span class="media-origin"><?= $stillLive ? 'Article' : 'Ex-article' ?> : <?= h($m['origin_name']) ?><?= $m['origin_ref'] ? ' (Réf ' . h($m['origin_ref']) . ')' : '' ?></span>
            <?php endif; ?>
            <form method="post" action="/admin/media-action.php" style="display:flex;flex-direction:column;gap:6px;">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="text" name="label" value="<?= h($m['label']) ?>" placeholder="Libellé">
              <input type="text" name="source" value="<?= h($m['source']) ?>" placeholder="Source">
              <input type="text" name="tags" value="<?= h($m['tags']) ?>" placeholder="Tags">
              <button type="submit" class="btn-small">Enregistrer</button>
            </form>
            <form method="post" action="/admin/media-action.php" onsubmit="return confirm('Retirer cette ressource de la médiathèque ?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button type="submit" class="admin-delete" style="width:100%;">Supprimer</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
</main>

<div class="quick-preview-overlay" id="quick-preview-overlay" aria-hidden="true">
  <img id="quick-preview-img" src="" alt="">
  <span id="quick-preview-name"></span>
</div>
<script>
(function () {
  // Aperçu plein format au survol — la grille force chaque vignette dans une
  // case 4:3 (object-fit:cover), donc un format déjà généré en story (9:16)
  // ou post (1:1) s'y affiche re-découpé : impossible d'y vérifier le vrai
  // cadrage sans voir l'image entière, non recadrée par la grille.
  if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

  var overlay = document.getElementById('quick-preview-overlay');
  var img = document.getElementById('quick-preview-img');
  var nameEl = document.getElementById('quick-preview-name');
  var showTimer = null;

  document.querySelectorAll('[data-quick-preview]').forEach(function (el) {
    el.addEventListener('mouseenter', function () {
      clearTimeout(showTimer);
      showTimer = setTimeout(function () {
        img.src = el.dataset.quickPreview;
        nameEl.textContent = el.dataset.quickName || '';
        overlay.classList.add('is-visible');
      }, 150);
    });
    el.addEventListener('mouseleave', function () {
      clearTimeout(showTimer);
      overlay.classList.remove('is-visible');
    });
  });
})();

(function () {
  // Si le libellé et les tags ne sont pas remplis à la main, propose une
  // suggestion IA (libellé, tags, nom de fichier lisible) dès qu'une image
  // est sélectionnée — l'admin garde la main pour corriger avant l'envoi.
  var fileInput = document.getElementById('media-add-file');
  var labelInput = document.getElementById('media-add-label');
  var tagsInput = document.getElementById('media-add-tags');
  var slugInput = document.getElementById('media-add-slug');
  var status = document.getElementById('media-add-ai-status');
  if (!fileInput) return;

  fileInput.addEventListener('change', function () {
    status.textContent = '';
    slugInput.value = '';

    var file = fileInput.files && fileInput.files[0];
    if (!file || file.type.indexOf('image/') !== 0) return;
    // On complète seulement les champs restés vides — pas de blocage global
    // si un seul des deux (ex : des tags déjà tapés) est renseigné.
    if (labelInput.value.trim() !== '' && tagsInput.value.trim() !== '') return;

    status.textContent = 'Suggestion IA en cours…';

    var fd = new FormData();
    fd.append('media', file);

    fetch('/admin/media-describe.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          status.textContent = data.error || 'Suggestion IA indisponible.';
          return;
        }
        if (labelInput.value.trim() === '') labelInput.value = data.label;
        if (tagsInput.value.trim() === '') tagsInput.value = data.tags;
        slugInput.value = data.slug || '';
        status.textContent = 'Suggestion IA appliquée — modifiable avant l’envoi.' + (data.resample ? ' ' + data.resample : '');
      })
      .catch(function (err) {
        status.textContent = 'Erreur réseau lors de la suggestion IA (' + err.message + ').';
      });
  });
})();
</script>
<script src="/assets/admin-upload-check.js"></script>
</body>
</html>
