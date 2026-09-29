<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$batch = $_SESSION['batch_import'] ?? null;
$flash = flash_get();

/** Taille en octets d'une directive php.ini (« 8M », « 2G »…). */
$iniBytes = static function (string $key): int {
    $v = trim((string) ini_get($key));
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
};
// Limites d'envoi de l'hébergement : les photos partent par paquets qui les respectent.
$uploadLimits = [
    'maxFiles' => max(1, min(20, (int) ini_get('max_file_uploads') ?: 20)),
    'maxPost' => $iniBytes('post_max_size') ?: 8 << 20,
    'maxFile' => $iniBytes('upload_max_filesize') ?: 2 << 20,
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import par lot — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .bi-group-card { background: var(--surface); border: 1px solid var(--line); padding: 18px 20px; margin-top: 20px; }
  .bi-group-card.is-done { opacity: 0.7; }
  .bi-group-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
  .bi-group-head h2 { font-size: 1.05rem; margin: 0; }
  .bi-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
  .bi-photo-item { position: relative; border: 2px solid var(--line); background: var(--bg); cursor: pointer; padding: 0; }
  .bi-photo-item.is-chosen { border-color: var(--accent); }
  .bi-photo-item img { width: 100%; aspect-ratio: 1; object-fit: contain; background: var(--surface-2); display: block; }
  .bi-photo-item .bi-score { position: absolute; top: 4px; right: 4px; background: var(--bg); border: 1px solid var(--line); font-family: var(--font-mono); font-size: 0.68rem; padding: 1px 5px; }
  .bi-photo-item .bi-group-select { width: 100%; margin-top: 4px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); font-size: 0.75rem; padding: 3px; }
  .bi-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
  .bi-drop { border: 2px dashed var(--line); background: var(--bg); padding: 22px; display: flex; flex-direction: column; gap: 12px; align-items: flex-start; margin-top: 14px; }
  .bi-drop.is-over { border-color: var(--accent); background: var(--surface-2); }
  .bi-drop-actions { display: flex; gap: 10px; flex-wrap: wrap; }
  .bi-drop-actions label { cursor: pointer; }
  .bi-picked { font-size: 0.9rem; color: var(--ink); margin: 0; }
  .bi-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); gap: 6px; width: 100%; max-height: 220px; overflow-y: auto; }
  .bi-thumbs img { width: 100%; aspect-ratio: 1; object-fit: contain; background: var(--surface-2); display: block; border: 1px solid var(--line); }
  .bi-progress { width: 100%; height: 8px; background: var(--surface-2); border: 1px solid var(--line); overflow: hidden; }
  .bi-progress span { display: block; height: 100%; width: 0; background: var(--accent); transition: width 0.2s; }
  .bi-or { font-family: var(--font-mono); font-size: 0.75rem; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.08em; margin: 18px 0 0; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'batch'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Catalogue</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Import par lot</h1>
    <p class="hint">
      Déposez des photos prises en vrac (plusieurs pièces mélangées) : une par une, un dossier entier ou un zip. Elles sont regroupées
      automatiquement par pièce selon l'heure de prise de vue, à corriger si besoin. Pour chaque
      groupe : l'IA suggère la photo la plus simple à détourer, la détoure, la met en situation, puis
      rédige une fiche produit — créée <strong>masquée</strong> pour relecture avant publication.
    </p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if (!$batch): ?>
      <div class="admin-block" style="margin-top:20px;">
        <h2 style="font-size:1.1rem;">Déposer des photos</h2>
        <div class="bi-drop" id="bi-drop" data-limits="<?= h(json_encode($uploadLimits)) ?>">
          <p class="hint" style="margin:0;">Choisissez les photos une par une (sélection multiple avec ⌘ ou Maj), ou tout un dossier, ou glissez-les ici. JPEG, PNG ou WEBP ; les photos HEIC d'iPhone sont à exporter en JPEG.</p>
          <div class="bi-drop-actions">
            <label class="btn btn-primary" for="bi-files">Choisir des photos</label>
            <label class="btn btn-ghost" for="bi-folder">Choisir un dossier</label>
          </div>
          <input type="file" id="bi-files" accept="image/jpeg,image/png,image/webp,.heic,.heif" multiple hidden>
          <input type="file" id="bi-folder" webkitdirectory directory multiple hidden>
          <p class="bi-picked" id="bi-picked" hidden></p>
          <div class="bi-thumbs" id="bi-thumbs" hidden></div>
          <div class="bi-progress" id="bi-progress" hidden><span></span></div>
          <div class="bi-drop-actions">
            <button type="button" class="btn btn-primary" id="bi-send" hidden>Envoyer et regrouper</button>
            <button type="button" class="btn btn-ghost" id="bi-clear" hidden>Vider la sélection</button>
          </div>
        </div>
        <p class="bi-or">ou</p>
        <h2 style="font-size:1.1rem;">Déposer un zip de photos</h2>
        <form method="post" action="/admin/batch-import-action.php" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
          <input type="hidden" name="action" value="upload">
          <input type="file" name="zip" accept=".zip" required>
          <button type="submit" class="btn btn-primary">Extraire et regrouper</button>
        </form>
      </div>

    <?php elseif (!$batch['confirmed']): ?>
      <?php if (!empty($batch['skipped_files'])): ?>
        <p class="hint" style="margin-top:16px;">Fichiers ignorés (format non pris en charge) : <?= h(implode(', ', $batch['skipped_files'])) ?></p>
      <?php endif; ?>

      <?php $aiRemaining = count($batch['sorted_paths'] ?? []) - ($batch['ai_processed_count'] ?? 0); ?>
      <?php if ($aiRemaining > 0): ?>
        <div class="admin-block" style="margin-top:16px;">
          <p class="hint" style="margin:0 0 10px;">
            <?= (int) ($batch['ai_processed_count'] ?? 0) ?> photo(s) sur <?= count($batch['sorted_paths'] ?? []) ?> analysées par IA —
            les <?= $aiRemaining ?> restantes sont groupées par heure de prise de vue en attendant. Chaque clic analyse un nouveau lot
            (limité pour rester rapide) ; recommencer maintenant écrase les corrections manuelles faites ci-dessous.
          </p>
          <form method="post" action="/admin/batch-import-action.php">
            <input type="hidden" name="action" value="ai_regroup_chunk">
            <?php $nextChunkSize = min($aiRemaining, BATCH_IMPORT_AI_CHUNK_SIZE); ?>
            <button type="submit" class="btn-small">Analyser le lot suivant par IA (<?= $nextChunkSize ?> photo<?= $nextChunkSize > 1 ? 's' : '' ?>)</button>
          </form>
        </div>
      <?php endif; ?>

      <form method="post" action="/admin/batch-import-action.php" id="regroup-form">
        <input type="hidden" name="action" value="regroup">
        <?php foreach ($batch['groups'] as $groupIndex => $group): ?>
          <div class="bi-group-card">
            <div class="bi-group-head"><h2>Groupe <?= $groupIndex + 1 ?> (<?= count($group['photos']) ?> photo<?= count($group['photos']) > 1 ? 's' : '' ?>)</h2></div>
            <div class="bi-photo-grid">
              <?php foreach ($group['photos'] as $photo): ?>
                <div class="bi-photo-item">
                  <img src="/<?= h($photo) ?>" alt="">
                  <select class="bi-group-select" name="group[<?= h($photo) ?>]">
                    <?php for ($n = 0; $n < count($batch['groups']) + 1; $n++): ?>
                      <option value="<?= $n ?>"<?= $n === $groupIndex ? ' selected' : '' ?>>Groupe <?= $n + 1 ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="bi-actions">
          <button type="submit" class="btn-small">Recalculer les groupes</button>
        </div>
      </form>

      <form method="post" action="/admin/batch-import-action.php" style="margin-top:20px;">
        <input type="hidden" name="action" value="confirm_grouping">
        <button type="submit" class="btn btn-primary">Valider le regroupement (<?= count($batch['groups']) ?> pièce<?= count($batch['groups']) > 1 ? 's' : '' ?>)</button>
      </form>

    <?php else: ?>
      <?php foreach ($batch['groups'] as $groupIndex => $group): ?>
        <div class="bi-group-card<?= $group['status'] !== 'pending' ? ' is-done' : '' ?>">
          <div class="bi-group-head">
            <h2>Pièce <?= $groupIndex + 1 ?></h2>
            <?php if ($group['status'] === 'done'): ?>
              <a class="btn-small" href="/admin/catalog.php#produit-<?= h($group['product_ref']) ?>" target="_blank">Fiche créée (Réf. N°<?= h($group['product_ref']) ?>) — relire →</a>
            <?php elseif ($group['status'] === 'skipped'): ?>
              <span class="publish-status" data-kind="">Ignoré</span>
            <?php endif; ?>
          </div>

          <?php if ($group['status'] === 'pending'): ?>
            <form method="post" action="/admin/batch-import-action.php">
              <input type="hidden" name="action" value="choose_photo">
              <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
              <div class="bi-photo-grid">
                <?php foreach ($group['photos'] as $photoIndex => $photo): ?>
                  <button type="submit" name="photo_index" value="<?= $photoIndex ?>" class="bi-photo-item<?= $photoIndex === $group['chosen_index'] ? ' is-chosen' : '' ?>" style="border:none;text-align:left;">
                    <img src="/<?= h($photo) ?>" alt="">
                    <?php if (isset($group['scores'][$photoIndex])): ?>
                      <span class="bi-score"><?= $group['scores'][$photoIndex] !== null ? (int) $group['scores'][$photoIndex] . '/10' : '?' ?></span>
                    <?php endif; ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </form>

            <div class="bi-actions">
              <form method="post" action="/admin/batch-import-action.php">
                <input type="hidden" name="action" value="suggest_best_photo">
                <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
                <button type="submit" class="btn-small">Suggérer la plus simple à détourer (IA)</button>
              </form>
              <form method="post" action="/admin/batch-import-action.php">
                <input type="hidden" name="action" value="process_group">
                <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
                <button type="submit" class="btn btn-primary">Traiter cette fiche →</button>
              </form>
              <form method="post" action="/admin/batch-import-action.php" onsubmit="return confirm('Ignorer ce groupe sans créer de fiche ?');">
                <input type="hidden" name="action" value="skip_group">
                <input type="hidden" name="group_index" value="<?= $groupIndex ?>">
                <button type="submit" class="admin-delete">Ignorer</button>
              </form>
            </div>
          <?php else: ?>
            <div class="bi-photo-grid">
              <?php foreach ($group['photos'] as $photo): ?>
                <div class="bi-photo-item"><img src="/<?= h($photo) ?>" alt=""></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <form method="post" action="/admin/batch-import-action.php" style="margin-top:24px;">
        <input type="hidden" name="action" value="finish">
        <button type="submit" class="btn btn-primary">Terminer l'import</button>
      </form>
    <?php endif; ?>
  </div>
</section>
</main>
<script>
(function () {
  var drop = document.getElementById('bi-drop');
  if (!drop) return;
  var limits = JSON.parse(drop.dataset.limits);
  var picked = [];   // fichiers retenus
  var ignored = [];  // noms écartés (format, taille)
  var $ = function (id) { return document.getElementById(id); };
  var OK = /\.(jpe?g|png|webp)$/i;

  function add(files) {
    Array.prototype.forEach.call(files, function (f) {
      if (f.name.charAt(0) === '.') return;                       // fichiers cachés (.DS_Store…)
      if (!OK.test(f.name)) { if (/\.(heic|heif)$/i.test(f.name) || /^image\//.test(f.type)) ignored.push(f.name); return; }
      if (f.size > limits.maxFile || f.size > limits.maxPost * 0.9) { ignored.push(f.name + ' (trop lourde)'); return; }
      if (picked.some(function (p) { return p.name === f.name && p.size === f.size && p.lastModified === f.lastModified; })) return;
      picked.push(f);
    });
    render();
  }

  function render() {
    var total = picked.reduce(function (n, f) { return n + f.size; }, 0);
    $('bi-picked').hidden = !(picked.length || ignored.length);
    $('bi-picked').textContent = picked.length + ' photo' + (picked.length > 1 ? 's' : '') + ' sélectionnée' + (picked.length > 1 ? 's' : '')
      + ' (' + (total / 1048576).toFixed(1) + ' Mo)'
      + (ignored.length ? ' — ' + ignored.length + ' fichier(s) ignoré(s) : ' + ignored.slice(0, 5).join(', ') + (ignored.length > 5 ? '…' : '') : '');
    var thumbs = $('bi-thumbs');
    thumbs.textContent = '';
    picked.slice(0, 60).forEach(function (f) {
      var img = document.createElement('img');
      img.alt = f.name; img.title = f.name; img.loading = 'lazy';
      img.src = URL.createObjectURL(f);
      thumbs.appendChild(img);
    });
    thumbs.hidden = !picked.length;
    $('bi-send').hidden = $('bi-clear').hidden = !picked.length;
    $('bi-send').textContent = 'Envoyer et regrouper (' + picked.length + ')';
  }

  $('bi-files').addEventListener('change', function () { add(this.files); this.value = ''; });
  $('bi-folder').addEventListener('change', function () { add(this.files); this.value = ''; });
  $('bi-clear').addEventListener('click', function () { picked = []; ignored = []; render(); });
  ['dragenter', 'dragover'].forEach(function (t) {
    drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (t) {
    drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
  });
  drop.addEventListener('drop', function (e) { add(e.dataTransfer.files); });

  function post(fd) {
    return fetch('/admin/batch-import-action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Envoi interrompu : vérifiez la connexion, puis recommencez.' }; });
  }
  function field(action) { var fd = new FormData(); fd.append('action', action); return fd; }

  // Paquets : au plus maxFiles fichiers et ~80 % de post_max_size par envoi.
  function chunks() {
    var out = [], cur = [], size = 0, budget = limits.maxPost * 0.8;
    picked.forEach(function (f) {
      if (cur.length && (cur.length >= limits.maxFiles || size + f.size > budget)) { out.push(cur); cur = []; size = 0; }
      cur.push(f); size += f.size;
    });
    if (cur.length) out.push(cur);
    return out;
  }

  $('bi-send').addEventListener('click', function () {
    var btn = this, list = chunks(), done = 0, bar = $('bi-progress');
    btn.disabled = true; $('bi-clear').hidden = true;
    bar.hidden = false;
    var total = picked.length;
    post(field('files_begin')).then(function (r) {
      if (!r.ok) throw r;
      return list.reduce(function (p, chunk) {
        return p.then(function () {
          var fd = field('files_chunk');
          chunk.forEach(function (f) { fd.append('photos[]', f, f.name); fd.append('mtimes[]', String(f.lastModified || 0)); });
          btn.textContent = 'Envoi… ' + done + ' / ' + total;
          return post(fd).then(function (res) {
            if (!res.ok) throw res;
            done += chunk.length;
            bar.firstElementChild.style.width = Math.round(done / total * 100) + '%';
          });
        });
      }, Promise.resolve());
    }).then(function () {
      btn.textContent = 'Regroupement des photos par pièce…';
      return post(field('files_done'));
    }).then(function (r) {
      if (!r.ok) throw r;
      location.href = '/admin/batch-import.php';
    }).catch(function (r) {
      btn.disabled = false;
      btn.textContent = 'Réessayer l\'envoi';
      $('bi-picked').textContent = (r && r.error) || 'L\'envoi a échoué, réessayez.';
    });
  });
})();
</script>
</body>
</html>
