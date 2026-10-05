<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$type = (string) ($_GET['type'] ?? '');
$type = in_array($type, ['photo', 'video'], true) ? $type : null;
$q = trim((string) ($_GET['q'] ?? ''));
// Médiathèque = ressources propres + photos des fiches produit (lues en place), classées par produit par défaut.
$product = (string) ($_GET['produit'] ?? '');
$by = in_array($_GET['classer'] ?? '', ['produit', 'source', 'type', 'date'], true) ? (string) $_GET['classer'] : 'produit';
$items = get_media_overview($type, $q !== '' ? $q : null, $product);
$groups = group_media_overview($items, $by);
$productOptions = db()->query('SELECT ref, name FROM products ORDER BY ref DESC')->fetchAll();
$ownCount = count(array_filter($items, static fn (array $m): bool => $m['kind'] === 'media'));
$flash = flash_get();
$view = ($_GET['vue'] ?? '') === 'liste' ? 'liste' : 'grille';
$viewUrl = static fn (string $v): string => '/admin/media.php?' . http_build_query(array_filter(['type' => $type, 'q' => $q, 'produit' => $product !== '' ? $product : null, 'classer' => $by !== 'produit' ? $by : null, 'vue' => $v === 'liste' ? 'liste' : null]));
$hasFilter = $type || $q !== '' || $product !== '' || $by !== 'produit';
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
  .media-thumb img, .media-thumb video { width: 100%; height: 100%; object-fit: contain; }
  .media-origin { font-family: var(--font-mono); font-size: 0.65rem; color: var(--accent); text-transform: uppercase; }
  .media-item input[type="text"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 6px 8px; font-family: var(--font-body); font-size: 0.82rem; width: 100%;
  }
  .media-fold > summary { cursor: pointer; list-style: none; }
  .media-fold > summary::-webkit-details-marker { display: none; }
  .media-fold > summary h2 { display: inline; margin: 0; }
  .media-fold > summary::after { content: ' +'; color: var(--ink-soft); }
  .media-fold[open] > summary::after { content: ' −'; }
  .media-fold[open] > summary { margin-bottom: 10px; }
  .media-group { margin-top: 28px; }
  .media-group:first-of-type { margin-top: 8px; }
  .media-group-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid var(--line); padding-bottom: 6px; margin-bottom: 14px; }
  .media-group-head h2 { font-size: 1.05rem; margin: 0; }
  .media-group-head .muted { color: var(--ink-soft); font-size: 0.8rem; font-family: var(--font-mono); }
  .media-badges { display: flex; flex-wrap: wrap; gap: 4px; }
  .media-badge { font-family: var(--font-mono); font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.03em; padding: 1px 6px; border: 1px solid var(--line); color: var(--ink-soft); }
  .media-badge.is-ai { border-color: var(--accent); color: var(--accent); }
  .media-item.is-fiche { background: var(--bg); }
  .media-item .media-name { font-size: 0.86rem; word-break: break-word; }
  .media-list tr.media-group-row td { background: var(--surface); font-weight: 600; padding-top: 14px; }
  .media-filters { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
  .media-filters select, .media-filters input[type="search"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem;
  }
  .media-view-toggle { display: inline-flex; border: 1px solid var(--line); margin-left: auto; }
  .media-view-toggle a { padding: 7px 12px; font-size: 0.82rem; color: var(--ink-soft); text-decoration: none; }
  .media-view-toggle a[aria-current] { background: var(--ink); color: var(--bg); }
  .media-bulk-bar {
    position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center;
    background: var(--bg); border: 1px solid var(--line); padding: 10px 14px; margin-bottom: 10px; font-size: 0.88rem;
  }
  .media-bulk-bar label { display: inline-flex; gap: 6px; align-items: center; cursor: pointer; }
  .media-list { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
  .media-list th, .media-list td { padding: 6px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
  .media-list th { color: var(--ink-soft); font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; }
  .media-list tr.is-checked td { background: var(--surface); }
  .media-list td.col-check { width: 32px; }
  .media-list input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
  .media-list-thumb { width: 112px; height: 76px; background: var(--surface-2); display: flex; align-items: center; justify-content: center; }
  .media-list-thumb img, .media-list-thumb video { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
  .media-list .muted { color: var(--ink-soft); font-size: 0.78rem; }
  .media-list .tags { font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-soft); word-break: break-word; }
  @media (max-width: 780px) { .media-list .col-source, .media-list .col-date { display: none; } }
  #phone-box [hidden] { display: none !important; }
  .phone-live { display: grid; grid-template-columns: 220px 1fr; gap: 24px; margin-top: 16px; align-items: start; }
  .phone-qr { background: #fff; padding: 10px; border: 1px solid var(--line); line-height: 0; }
  .phone-qr svg { width: 100%; height: auto; display: block; }
  .phone-status { font-weight: 600; margin: 0 0 6px; }
  .phone-status[data-state="active"]::before { content: "● "; color: #2e9e5b; }
  .phone-status[data-state="off"]::before { content: "● "; color: #b3261e; }
  .phone-received { display: grid; grid-template-columns: repeat(auto-fill, minmax(86px, 1fr)); gap: 8px; margin: 12px 0; }
  .phone-received img, .phone-received video { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; border: 1px solid var(--line); background: var(--surface); }
  .phone-link { word-break: break-all; font-size: 0.82rem; }
  @media (max-width: 640px) { .phone-live { grid-template-columns: 1fr; } .phone-qr { max-width: 220px; } }
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
    <p class="hint">Toutes les photos et vidéos : celles des <strong>fiches produit</strong> (originales, visuels IA, détourages, versions 9:16 — à gérer dans la galerie de chaque pièce) et les ressources propres (imports réseaux sociaux, photos prises avec le téléphone, photos conservées après suppression d'une fiche, ajouts manuels). Classées par produit par défaut : changez le classement ou filtrez sur un produit ci-dessous.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <form method="get" action="/admin/media.php" class="media-filters">
      <select name="type" onchange="this.form.submit()">
        <option value="">Tous types</option>
        <option value="photo"<?= $type === 'photo' ? ' selected' : '' ?>>Photos</option>
        <option value="video"<?= $type === 'video' ? ' selected' : '' ?>>Vidéos</option>
      </select>
      <select name="produit" onchange="this.form.submit()" aria-label="Produit">
        <option value="">Tous les produits</option>
        <option value="-"<?= $product === '-' ? ' selected' : '' ?>>Sans produit</option>
        <?php foreach ($productOptions as $po): ?>
          <option value="<?= h($po['ref']) ?>"<?= $product === $po['ref'] ? ' selected' : '' ?>>Réf. <?= h($po['ref']) ?> — <?= h(mb_strimwidth($po['name'], 0, 40, '…')) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="classer" onchange="this.form.submit()" aria-label="Classer par">
        <option value="produit"<?= $by === 'produit' ? ' selected' : '' ?>>Classer par produit</option>
        <option value="source"<?= $by === 'source' ? ' selected' : '' ?>>Classer par source</option>
        <option value="type"<?= $by === 'type' ? ' selected' : '' ?>>Classer par type</option>
        <option value="date"<?= $by === 'date' ? ' selected' : '' ?>>Classer par mois</option>
      </select>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher (libellé, source, tags, produit)...">
      <button type="submit" class="btn-small">Filtrer</button>
      <?php if ($hasFilter): ?><a class="btn-small" href="<?= h($view === 'liste' ? '/admin/media.php?vue=liste' : '/admin/media.php') ?>">Réinitialiser</a><?php endif; ?>
      <?php if ($view === 'liste'): ?><input type="hidden" name="vue" value="liste"><?php endif; ?>
      <span class="media-view-toggle" role="group" aria-label="Affichage">
        <a href="<?= h($viewUrl('grille')) ?>"<?= $view === 'grille' ? ' aria-current="page"' : '' ?>>▦ Grille</a>
        <a href="<?= h($viewUrl('liste')) ?>"<?= $view === 'liste' ? ' aria-current="page"' : '' ?>>☰ Liste</a>
      </span>
    </form>

    <details class="admin-block media-fold" id="phone-box" style="margin-bottom:14px;" data-csrf="<?= h(admin_csrf_token()) ?>">
      <summary><h2 style="font-size:1.1rem;">Utiliser mon téléphone</h2></summary>
      <p class="hint">Scannez un code avec l'appareil photo du téléphone : une page s'ouvre pour prendre des photos ou filmer de courtes vidéos. Elles arrivent ici et dans la médiathèque, sans rien saisir sur le téléphone.</p>
      <button type="button" class="btn btn-primary" id="phone-start">Afficher le code</button>
      <div class="phone-live" id="phone-live" hidden>
        <div class="phone-qr" id="phone-qr" role="img" aria-label="Code QR à scanner avec le téléphone"></div>
        <div>
          <p class="phone-status" id="phone-status" data-state="active" aria-live="polite">En attente du téléphone…</p>
          <p class="hint phone-link" style="margin:0 0 6px;">Ou ouvrez ce lien sur le téléphone : <a id="phone-link" href="#" target="_blank" rel="noopener"></a></p>
          <p class="hint" id="phone-limits" style="margin:0 0 6px;"></p>
          <p class="publish-status" data-kind="error" id="phone-warn" hidden style="margin:8px 0;"></p>
          <div class="phone-received" id="phone-received"></div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" id="phone-done">Terminer</button>
            <button type="button" class="btn" id="phone-renew" hidden>Nouveau code</button>
          </div>
        </div>
      </div>
    </details>

    <details class="admin-block media-fold" style="margin-bottom:28px;">
      <summary><h2 style="font-size:1.1rem;">Ajouter une ressource</h2></summary>
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
    </details>

    <?php
    $badgesHtml = static function (array $m): string {
        if (!$m['badges']) return '';
        return '<span class="media-badges">' . implode('', array_map(static fn (string $b): string => '<span class="media-badge' . (in_array($b, ['Visuel IA', 'Détourage'], true) ? ' is-ai' : '') . '">' . h($b) . '</span>', $m['badges'])) . '</span>';
    };
    $galleryUrl = static fn (string $ref): string => '/admin/gallery.php?ref=' . urlencode($ref);
    ?>
    <?php if (!$items): ?>
      <p class="empty-state"><?= $hasFilter ? 'Aucune ressource ne correspond à ces filtres.' : "Aucune ressource pour l'instant." ?></p>
    <?php elseif ($view === 'liste'): ?>
      <form method="post" action="/admin/media-action.php" id="media-bulk-form">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="back" value="<?= h($viewUrl('liste')) ?>">
        <div class="media-bulk-bar">
          <label><input type="checkbox" id="media-check-all"> Tout cocher</label>
          <span id="media-check-count" class="muted">Aucune ressource cochée</span>
          <button type="submit" class="admin-delete" id="media-bulk-delete" disabled>Supprimer la sélection</button>
          <span class="muted">Astuce : Maj + clic coche toute une plage. Les photos des fiches se gèrent dans la galerie de la pièce.</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="media-list">
            <thead><tr><th></th><th>Aperçu</th><th>Libellé</th><th class="col-source">Source / tags</th><th>Article d'origine</th><th class="col-date">Ajout</th></tr></thead>
            <tbody>
              <?php foreach ($groups as [$groupTitle, $groupRef, $groupItems]): ?>
                <tr class="media-group-row"><td colspan="6"><?= h($groupTitle) ?> <span class="muted">· <?= count($groupItems) ?></span><?php if ($groupRef): ?> <a class="muted" href="<?= h($galleryUrl($groupRef)) ?>">Gérer les photos →</a><?php endif; ?></td></tr>
                <?php foreach ($groupItems as $m): ?>
                  <tr>
                    <td class="col-check"><?php if ($m['kind'] === 'media'): ?><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" class="media-check" aria-label="Cocher <?= h($m['label']) ?>"><?php endif; ?></td>
                    <td>
                      <div class="media-list-thumb"<?= $m['type'] !== 'video' ? ' data-quick-preview="/' . h($m['path']) . '" data-quick-name="' . h($m['label']) . '"' : '' ?>>
                        <?php if ($m['type'] === 'video'): ?>
                          <video src="/<?= h($m['path']) ?>" preload="metadata" muted></video>
                        <?php else: ?>
                          <img src="/<?= h($m['path']) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                      </div>
                    </td>
                    <td><?= h($m['label']) ?: '<span class="muted">(sans libellé)</span>' ?><?= $m['type'] === 'video' ? ' <span class="muted">· vidéo</span>' : '' ?><?= $badgesHtml($m) ?></td>
                    <td class="col-source"><?= h($m['source']) ?><?php if ($m['tags'] !== ''): ?><br><span class="tags"><?= h($m['tags']) ?></span><?php endif; ?></td>
                    <td><?php if ($m['origin_name']): ?><?= h($m['origin_name']) ?><?= $m['origin_ref'] ? ' <span class="muted">(Réf ' . h($m['origin_ref']) . ')</span>' : '' ?><?php if ($m['kind'] === 'fiche'): ?> <a class="muted" href="<?= h($galleryUrl($m['origin_ref'])) ?>">galerie →</a><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td class="col-date muted"><?= h(date('d/m/Y', strtotime($m['created_at']) ?: time())) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </form>
      <script>
      (function () {
        var form = document.getElementById('media-bulk-form');
        var boxes = Array.prototype.slice.call(form.querySelectorAll('.media-check'));
        var all = document.getElementById('media-check-all');
        var count = document.getElementById('media-check-count');
        var del = document.getElementById('media-bulk-delete');
        var last = null;

        function refresh() {
          var n = boxes.filter(function (b) { return b.checked; }).length;
          boxes.forEach(function (b) { b.closest('tr').classList.toggle('is-checked', b.checked); });
          all.checked = boxes.length > 0 && n === boxes.length;
          all.indeterminate = n > 0 && n < boxes.length;
          count.textContent = n ? n + ' ressource' + (n > 1 ? 's' : '') + ' cochée' + (n > 1 ? 's' : '') : 'Aucune ressource cochée';
          del.disabled = n === 0;
          del.textContent = n ? 'Supprimer la sélection (' + n + ')' : 'Supprimer la sélection';
        }
        boxes.forEach(function (b, i) {
          b.addEventListener('click', function (e) {
            // Maj + clic : applique l'état à toute la plage depuis la dernière case cliquée.
            if (e.shiftKey && last !== null) {
              var from = Math.min(last, i), to = Math.max(last, i);
              for (var k = from; k <= to; k++) boxes[k].checked = b.checked;
            }
            last = i;
            refresh();
          });
        });
        // Clic sur la ligne (hors aperçu et liens) = cocher, quand la ligne a une case.
        form.querySelectorAll('tbody tr').forEach(function (tr) {
          var box = tr.querySelector('.media-check');
          if (!box) return;
          tr.addEventListener('click', function (e) {
            if (e.target.closest('input, a, video, .media-list-thumb')) return;
            box.click();
          });
        });
        all.addEventListener('change', function () {
          boxes.forEach(function (b) { b.checked = all.checked; });
          refresh();
        });
        form.addEventListener('submit', function (e) {
          var n = boxes.filter(function (b) { return b.checked; }).length;
          if (!n || !confirm('Retirer ' + n + ' ressource' + (n > 1 ? 's' : '') + ' de la médiathèque ?')) e.preventDefault();
        });
        refresh();
      })();
      </script>
    <?php else: ?>
      <?php foreach ($groups as [$groupTitle, $groupRef, $groupItems]): ?>
        <div class="media-group">
          <div class="media-group-head">
            <h2><?= h($groupTitle) ?></h2>
            <span class="muted"><?= count($groupItems) ?> ressource<?= count($groupItems) > 1 ? 's' : '' ?></span>
            <?php if ($groupRef): ?><a class="btn-small" href="<?= h($galleryUrl($groupRef)) ?>">Gérer les photos →</a><?php endif; ?>
          </div>
          <div class="media-grid">
            <?php foreach ($groupItems as $m): ?>
              <div class="media-item<?= $m['kind'] === 'fiche' ? ' is-fiche' : '' ?>">
                <div class="media-thumb"<?= $m['type'] !== 'video' ? ' data-quick-preview="/' . h($m['path']) . '" data-quick-name="' . h($m['label']) . '"' : '' ?>>
                  <?php if ($m['type'] === 'video'): ?>
                    <video src="/<?= h($m['path']) ?>" preload="metadata" controls muted></video>
                  <?php else: ?>
                    <img src="/<?= h($m['path']) ?>" alt="<?= h($m['label']) ?>" loading="lazy">
                  <?php endif; ?>
                </div>
                <?php if ($m['kind'] === 'fiche'): ?>
                  <span class="media-name"><?= h($m['label']) ?></span>
                  <?= $badgesHtml($m) ?>
                  <a class="btn-small" style="text-align:center;text-decoration:none;" href="<?= h($galleryUrl($m['origin_ref'])) ?>">Gérer dans la galerie →</a>
                <?php else: ?>
                  <?php if ($m['origin_name']): ?>
                    <span class="media-origin"><?= $m['live'] ? 'Article' : 'Ex-article' ?> : <?= h($m['origin_name']) ?><?= $m['origin_ref'] ? ' (Réf ' . h($m['origin_ref']) . ')' : '' ?></span>
                  <?php endif; ?>
                  <form method="post" action="/admin/media-action.php" style="display:flex;flex-direction:column;gap:6px;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <input type="hidden" name="back" value="<?= h($viewUrl('grille')) ?>">
                    <input type="text" name="label" value="<?= h($m['label']) ?>" placeholder="Libellé">
                    <input type="text" name="source" value="<?= h($m['source']) ?>" placeholder="Source">
                    <input type="text" name="tags" value="<?= h($m['tags']) ?>" placeholder="Tags">
                    <button type="submit" class="btn-small">Enregistrer</button>
                  </form>
                  <form method="post" action="/admin/media-action.php" onsubmit="return confirm('Retirer cette ressource de la médiathèque ?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <input type="hidden" name="back" value="<?= h($viewUrl('grille')) ?>">
                    <button type="submit" class="admin-delete" style="width:100%;">Supprimer</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
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
<script src="/assets/vendor/qrcode-generator.js"></script>
<script>
(function () {
  var box = document.getElementById('phone-box');
  var live = document.getElementById('phone-live');
  var startBtn = document.getElementById('phone-start');
  var renewBtn = document.getElementById('phone-renew');
  var doneBtn = document.getElementById('phone-done');
  var statusEl = document.getElementById('phone-status');
  var received = document.getElementById('phone-received');
  var csrf = box.dataset.csrf;
  var session = null;   // { id, after, total }
  var timer = null;

  function stopPolling() { clearInterval(timer); timer = null; }
  function setStatus(text, state) { statusEl.textContent = text; statusEl.dataset.state = state; }

  function api(fields) {
    var fd = new FormData();
    fd.append('csrf', csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch('/admin/capture-session.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function addThumb(u) {
    var el;
    if (u.type === 'video') {
      el = document.createElement('video');
      el.src = '/' + u.path + '#t=0.1';
      el.muted = true; el.preload = 'metadata'; el.playsInline = true;
      el.setAttribute('controls', '');
    } else {
      el = document.createElement('img');
      el.src = '/' + u.path; el.alt = u.label; el.loading = 'lazy';
    }
    el.title = u.label;
    received.insertBefore(el, received.firstChild);
  }

  function poll() {
    if (!session) return;
    fetch('/admin/capture-session.php?action=status&id=' + session.id + '&after=' + session.after, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        d.uploads.forEach(function (u) { addThumb(u); session.after = u.id; });
        session.total = d.total;
        if (!d.active) {
          stopPolling();
          setStatus(d.total + ' fichier' + (d.total > 1 ? 's' : '') + ' reçu' + (d.total > 1 ? 's' : '') + ' — code expiré ou fermé.', 'off');
          renewBtn.hidden = false;
          return;
        }
        var minutes = Math.max(1, Math.round(d.expires_in / 60));
        setStatus(d.total === 0 ? 'En attente du téléphone… (code valable encore ' + minutes + ' min)'
                                : d.total + ' fichier' + (d.total > 1 ? 's' : '') + ' reçu' + (d.total > 1 ? 's' : '') + ' — ' + minutes + ' min restantes', 'active');
      })
      .catch(function () {});
  }

  function start() {
    startBtn.disabled = true;
    renewBtn.hidden = true;
    api({ action: 'create' }).then(function (d) {
      startBtn.disabled = false;
      if (!d.ok) { alert(d.error || 'Impossible de créer le code.'); return; }
      session = { id: d.id, after: 0, total: 0 };
      var qr = qrcode(0, 'M');
      qr.addData(d.url);
      qr.make();
      document.getElementById('phone-qr').innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
      var link = document.getElementById('phone-link');
      link.href = d.url; link.textContent = d.url;
      document.getElementById('phone-limits').textContent =
        'Photos réduites automatiquement. Vidéos : ' + d.max_video_seconds + ' secondes et ' + d.max_mb + ' Mo maximum chacune.';
      var warn = document.getElementById('phone-warn');
      if (!d.reachable) {
        warn.hidden = false;
        warn.textContent = "Ce code pointe vers une adresse locale (" + new URL(d.url).host + ") que le téléphone ne peut pas joindre. En ligne, ça fonctionne ; en local, ouvrez l'administration avec l'adresse IP de votre ordinateur, sur le même Wi-Fi.";
      } else { warn.hidden = true; }
      received.innerHTML = '';
      setStatus('En attente du téléphone…', 'active');
      live.hidden = false;
      startBtn.hidden = true;
      stopPolling();
      timer = setInterval(poll, 2000);
    }).catch(function () { startBtn.disabled = false; alert('Impossible de créer le code (connexion).'); });
  }

  startBtn.addEventListener('click', start);
  renewBtn.addEventListener('click', start);
  doneBtn.addEventListener('click', function () {
    stopPolling();
    var receivedCount = session ? session.total : 0;
    var finish = function () { if (receivedCount > 0) { location.reload(); } else { live.hidden = true; startBtn.hidden = false; } };
    if (session) { api({ action: 'close', id: session.id }).then(finish, finish); } else { finish(); }
    session = null;
  });
})();
</script>
<script src="/assets/admin-upload-check.js"></script>
</body>
</html>
