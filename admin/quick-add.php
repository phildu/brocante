<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$aiReady = (bool) GEMINI_API_KEY;
// Angles proposés : [clé, titre, conseil de prise de vue, obligatoire].
$angles = [
    ['face', 'Face', "L'objet entier, de face, sur un fond simple", true],
    ['profil', 'Profil', 'De côté, pour montrer la forme et l’épaisseur', false],
    ['dos', 'Dos', 'L’arrière de la pièce', false],
    ['detail', 'Détail', 'Marque, signature, poinçon ou défaut', false],
    ['dessous', 'Dessous', 'Le dessous ou l’intérieur', false],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4eee1">
<title>Nouvelle pièce — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .qa { max-width: 560px; margin: 0 auto; padding: 16px 16px calc(96px + env(safe-area-inset-bottom, 0px)); display: flex; flex-direction: column; gap: 18px; }
  .qa h1 { font-size: 1.5rem; margin: 4px 0 0; }
  .qa-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; list-style: none; margin: 0; padding: 0; counter-reset: step; }
  .qa-steps li { font-size: 0.75rem; color: var(--ink-soft); border-top: 3px solid var(--line); padding-top: 6px; counter-increment: step; }
  .qa-steps li::before { content: counter(step) ". "; font-family: var(--font-mono); }
  .qa-steps li[aria-current="step"] { color: var(--ink); border-top-color: var(--accent); font-weight: 600; }
  .qa-steps li.is-done { border-top-color: var(--accent-2); }
  .qa-panel[hidden] { display: none !important; }
  .qa-panel { display: flex; flex-direction: column; gap: 14px; }

  /* Étape 1 : un emplacement par angle. */
  .qa-shots { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .qa-shot { position: relative; border: 2px dashed var(--line); background: var(--surface); min-height: 150px; display: flex; flex-direction: column; justify-content: flex-end; overflow: hidden; }
  .qa-shot:first-child { grid-column: 1 / -1; min-height: 220px; }
  .qa-shot.has-photo { border-style: solid; border-color: var(--line); }
  .qa-shot.is-main { border-color: var(--accent); }
  .qa-shot img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .qa-shot-take { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; padding: 12px; text-align: center; background: none; border: 0; cursor: pointer; color: var(--ink); font-family: var(--font-body); }
  .qa-shot-take svg { width: 34px; height: 34px; color: var(--accent); }
  .qa-shot-take b { font-size: 1rem; }
  .qa-shot-take small { font-size: 0.75rem; color: var(--ink-soft); line-height: 1.35; }
  .qa-shot.has-photo .qa-shot-take { display: none; }
  .qa-shot-bar { position: relative; display: none; justify-content: space-between; align-items: center; gap: 6px; padding: 6px; background: linear-gradient(transparent, rgba(0,0,0,0.55)); }
  .qa-shot.has-photo .qa-shot-bar { display: flex; }
  .qa-shot-bar > span:first-child { color: #fff; font-size: 0.8rem; font-weight: 600; text-shadow: 0 1px 2px rgba(0,0,0,0.5); }
  .qa-mini { display: flex; gap: 4px; }
  .qa-mini button { background: rgba(255,255,255,0.92); color: #1d1a16; border: 0; padding: 6px 9px; font-size: 0.75rem; font-weight: 600; cursor: pointer; font-family: var(--font-body); }
  .qa-mini button[aria-pressed="true"] { background: var(--accent); color: var(--accent-ink); }
  .qa-shot:not(:first-child) .qa-mini .txt { display: none; }
  .qa-shot:not(:first-child) .qa-mini button { min-width: 34px; min-height: 34px; font-size: 0.95rem; }
  .qa-shot-bar > span:first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .qa-mini { flex: none; }
  .qa-add { border: 1px dashed var(--line); background: none; padding: 12px; font-family: var(--font-body); color: var(--ink); cursor: pointer; }
  .qa-gallery { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 0.85rem; color: var(--ink-soft); }
  .qa-gallery label { text-decoration: underline; cursor: pointer; color: var(--ink); }
  .qa textarea, .qa input[type="text"], .qa input[type="number"], .qa select {
    width: 100%; background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 12px; font-family: var(--font-body); font-size: 16px; /* 16px : pas de zoom automatique sur iPhone */
  }
  .qa label.qa-field { display: flex; flex-direction: column; gap: 6px; font-size: 0.85rem; color: var(--ink-soft); }
  .qa-note { font-size: 0.85rem; color: var(--ink-soft); margin: 0; }
  .qa-warn { font-size: 0.85rem; margin: 0; padding: 10px 12px; border: 1px solid var(--accent); color: var(--ink); background: var(--surface); }

  /* Barre d'action fixée en bas de l'écran, à portée de pouce. */
  .qa-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; background: var(--bg); border-top: 1px solid var(--line); padding: 10px 16px calc(10px + env(safe-area-inset-bottom, 0px)); }
  html.has-admin-sidebar .qa-bar { left: var(--admin-sidebar-w); }
  @media (max-width: 860px) { html.has-admin-sidebar .qa-bar { left: 0; } }
  .qa-bar .btn { width: 100%; justify-content: center; padding: 15px; font-size: 1rem; }
  .qa-bar .btn:disabled { opacity: 0.5; }

  /* Étape 2 : progression. */
  .qa-progress { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .qa-progress li { display: grid; grid-template-columns: 28px 1fr; gap: 2px 10px; align-items: center; padding: 12px; background: var(--surface); border: 1px solid var(--line); }
  .qa-progress .dot { width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--line); grid-row: span 2; }
  .qa-progress li[data-state="run"] .dot { border-color: var(--accent); border-right-color: transparent; animation: qa-spin 0.9s linear infinite; }
  .qa-progress li[data-state="ok"] .dot { background: var(--accent-2); border-color: var(--accent-2); }
  .qa-progress li[data-state="err"] .dot { background: var(--line); border-color: var(--ink-soft); }
  .qa-progress small { color: var(--ink-soft); font-size: 0.78rem; }
  @keyframes qa-spin { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) { .qa-progress li[data-state="run"] .dot { animation: none; background: var(--accent); } }

  /* Étape 3 : visuels et fiche. */
  .qa-visuals { display: flex; gap: 8px; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 4px; }
  .qa-visuals figure { margin: 0; flex: 0 0 72%; scroll-snap-align: start; background: var(--surface); border: 1px solid var(--line); }
  .qa-visuals img { width: 100%; aspect-ratio: 1; object-fit: contain; display: block; background: var(--surface-2); }
  .qa-visuals figcaption { font-size: 0.75rem; color: var(--ink-soft); padding: 6px 8px; font-family: var(--font-mono); }
  .qa-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .qa-switch { display: flex; gap: 10px; align-items: flex-start; font-size: 0.95rem; padding: 12px; background: var(--surface); border: 1px solid var(--line); }
  .qa-switch input { width: 22px; height: 22px; flex: none; accent-color: var(--accent); }
  .qa-done { text-align: center; display: flex; flex-direction: column; gap: 12px; align-items: center; padding: 30px 0; }
  .qa-done .btn { width: 100%; justify-content: center; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'prise'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<div class="qa">
  <div>
    <p class="eyebrow" style="margin:0;">Catalogue</p>
    <h1>Nouvelle pièce</h1>
  </div>
  <ol class="qa-steps" aria-label="Étapes">
    <li id="st-1" aria-current="step">Photos</li>
    <li id="st-2">Génération</li>
    <li id="st-3">Vérification</li>
  </ol>

  <!-- Étape 1 : photos -->
  <section class="qa-panel" id="panel-photos">
    <p class="qa-note">Touchez un cadre pour ouvrir l'appareil photo. La photo de face est obligatoire ; les autres angles aident l'IA à décrire la pièce. « Principale » choisit la photo détourée et mise en situation.</p>
    <div class="qa-shots" id="shots">
      <?php foreach ($angles as $i => [$key, $title, $tip, $required]): ?>
        <div class="qa-shot<?= $i === 0 ? ' is-main' : '' ?>" data-index="<?= $i ?>" data-label="<?= h($title) ?>">
          <input type="file" accept="image/*" capture="environment" id="cam-<?= $i ?>" hidden>
          <label class="qa-shot-take" for="cam-<?= $i ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
            <b><?= h($title) ?><?= $required ? '' : ' <small>(facultatif)</small>' ?></b>
            <small><?= h($tip) ?></small>
          </label>
          <div class="qa-shot-bar">
            <span><?= h($title) ?></span>
            <span class="qa-mini">
              <button type="button" data-act="main" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Photo principale"><span aria-hidden="true">★</span><span class="txt"> Principale</span></button>
              <button type="button" data-act="retake" aria-label="Reprendre la photo"><span aria-hidden="true">↺</span><span class="txt"> Reprendre</span></button>
              <button type="button" data-act="remove" aria-label="Retirer la photo">✕</button>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="qa-add" id="add-shot">+ Autre angle</button>
    <div class="qa-gallery">
      Photos déjà prises ?
      <label for="gallery">Choisir dans la galerie</label>
      <input type="file" accept="image/*" multiple id="gallery" hidden>
    </div>
    <label class="qa-field">Indications pour l'IA (facultatif)
      <textarea id="notes" rows="2" maxlength="300" placeholder="Ex. : années 70, grès, petit éclat au pied, 32 cm de haut"></textarea>
    </label>
    <?php if (!$aiReady): ?>
      <p class="qa-warn">Clé Gemini non configurée (Réglages du site) : les photos seront enregistrées, mais le détourage, la mise en situation et la rédaction automatiques seront sautés.</p>
    <?php endif; ?>
  </section>

  <!-- Étape 2 : génération -->
  <section class="qa-panel" id="panel-progress" hidden>
    <ol class="qa-progress" id="progress">
      <li data-step="create"><span class="dot"></span><b>Envoi des photos</b><small>Enregistrement dans le catalogue</small></li>
      <li data-step="detoure"><span class="dot"></span><b>Détourage</b><small>Photo principale sur fond neutre</small></li>
      <li data-step="ambiance"><span class="dot"></span><b>Mise en situation</b><small>La pièce dans un intérieur</small></li>
      <li data-step="sheet"><span class="dot"></span><b>Rédaction de la fiche</b><small>Nom, description, catégorie, prix</small></li>
    </ol>
    <p class="qa-note">Comptez 30 secondes à 1 minute. Gardez cette page ouverte.</p>
  </section>

  <!-- Étape 3 : vérification -->
  <section class="qa-panel" id="panel-review" hidden>
    <div class="qa-visuals" id="visuals"></div>
    <label class="qa-field">Nom<input type="text" id="f-name" maxlength="120"></label>
    <div class="qa-two">
      <label class="qa-field">Prix<input type="text" id="f-price" maxlength="30" inputmode="decimal" placeholder="25 €"></label>
      <label class="qa-field">Poids (g)<input type="number" id="f-weight" min="0" step="10" inputmode="numeric" placeholder="500"></label>
    </div>
    <label class="qa-field">Catégorie
      <select id="f-cat">
        <?php foreach (category_list() as $c): ?>
          <option value="<?= h($c['key']) ?>"><?= h($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="qa-field">Description<textarea id="f-desc" rows="5" maxlength="1200"></textarea></label>
    <label class="qa-field">Étiquette<input type="text" id="f-badge" maxlength="30" placeholder="Chiné, Rare, Coup de cœur…"></label>
    <label class="qa-switch"><input type="checkbox" id="f-publish"> <span>Publier tout de suite<br><small class="qa-note">Sinon la fiche reste masquée, à relire dans le catalogue.</small></span></label>
  </section>

  <!-- Terminé -->
  <section class="qa-panel qa-done" id="panel-done" hidden>
    <h2 id="done-title" style="margin:0;font-size:1.3rem;"></h2>
    <p class="qa-note" id="done-text"></p>
    <a class="btn btn-primary" href="/admin/quick-add.php">Ajouter une autre pièce</a>
    <a class="btn btn-ghost" id="done-link" href="/admin/catalog.php">Voir dans le catalogue</a>
  </section>
</div>
</main>

<div class="qa-bar">
  <button type="button" class="btn btn-primary" id="main-btn" disabled>Prenez au moins la photo de face</button>
</div>

<script>
(function () {
  var shots = document.getElementById('shots');
  var mainBtn = document.getElementById('main-btn');
  var photos = {};      // index d'emplacement → Blob redimensionné
  var mainIndex = 0;
  var ref = null;

  function $(id) { return document.getElementById(id); }

  // Réduit la photo sur le téléphone (1600 px, JPEG) avant l'envoi : plus rapide en 4G.
  function shrink(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function slot(i) { return shots.querySelector('.qa-shot[data-index="' + i + '"]'); }

  function refresh() {
    var count = Object.keys(photos).length;
    shots.querySelectorAll('.qa-shot').forEach(function (s) {
      var i = +s.dataset.index;
      s.classList.toggle('is-main', i === mainIndex);
      s.querySelector('[data-act="main"]').setAttribute('aria-pressed', String(i === mainIndex));
    });
    mainBtn.disabled = !photos[mainIndex] && !count;
    if (!count) mainBtn.textContent = 'Prenez au moins la photo de face';
    else mainBtn.textContent = 'Générer la fiche (' + count + ' photo' + (count > 1 ? 's' : '') + ')';
  }

  function setPhoto(i, file) {
    var s = slot(i);
    shrink(file).then(function (blob) {
      photos[i] = blob;
      var old = s.querySelector('img'); if (old) old.remove();
      var img = document.createElement('img');
      img.alt = s.dataset.label;
      img.src = URL.createObjectURL(blob);
      s.insertBefore(img, s.firstChild);
      s.classList.add('has-photo');
      if (!photos[mainIndex]) mainIndex = i;
      refresh();
    });
  }

  function bindSlot(s) {
    var i = +s.dataset.index;
    s.querySelector('input[type=file]').addEventListener('change', function () {
      if (this.files[0]) setPhoto(i, this.files[0]);
      this.value = '';
    });
    s.querySelector('[data-act="main"]').addEventListener('click', function () { mainIndex = i; refresh(); });
    s.querySelector('[data-act="retake"]').addEventListener('click', function () { s.querySelector('input[type=file]').click(); });
    s.querySelector('[data-act="remove"]').addEventListener('click', function () {
      delete photos[i];
      var img = s.querySelector('img'); if (img) img.remove();
      s.classList.remove('has-photo');
      if (mainIndex === i) mainIndex = +(Object.keys(photos)[0] || 0);
      refresh();
    });
  }
  shots.querySelectorAll('.qa-shot').forEach(bindSlot);

  // Emplacement supplémentaire (« Autre angle »).
  var extra = 0;
  $('add-shot').addEventListener('click', function () {
    var i = shots.children.length;
    extra++;
    var s = slot(1).cloneNode(true);
    s.dataset.index = i;
    s.dataset.label = 'Autre angle ' + extra;
    s.classList.remove('has-photo', 'is-main');
    var old = s.querySelector('img'); if (old) old.remove();
    s.querySelector('input').id = 'cam-' + i;
    s.querySelector('label').htmlFor = 'cam-' + i;
    s.querySelector('label b').textContent = 'Autre angle ' + extra;
    s.querySelector('label > small').textContent = 'Un angle ou un détail de plus';
    s.querySelector('.qa-shot-bar > span').textContent = 'Autre angle ' + extra;
    shots.appendChild(s);
    bindSlot(s);
    s.querySelector('input').click();
  });

  // Galerie : remplit les emplacements vides dans l'ordre.
  $('gallery').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files);
    shots.querySelectorAll('.qa-shot').forEach(function (s) {
      if (!files.length || photos[+s.dataset.index]) return;
      setPhoto(+s.dataset.index, files.shift());
    });
    while (files.length) { $('add-shot').click(); setPhoto(shots.children.length - 1, files.shift()); }
    this.value = '';
  });

  // ── Génération : une requête par étape ──
  function post(data) {
    return fetch('/admin/quick-add-action.php', { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : vérifiez le réseau.' }; });
  }
  function step(name, state, text) {
    var li = document.querySelector('#progress li[data-step="' + name + '"]');
    li.dataset.state = state;
    if (text) li.querySelector('small').textContent = text;
  }
  function stepData(action, extraFields) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('ref', ref);
    fd.append('notes', $('notes').value);
    return fd;
  }
  function show(panel, stepIndex) {
    ['panel-photos', 'panel-progress', 'panel-review', 'panel-done'].forEach(function (id) { $(id).hidden = id !== panel; });
    [1, 2, 3].forEach(function (n) {
      var li = $('st-' + n);
      li.classList.toggle('is-done', n < stepIndex);
      if (n === stepIndex) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });
    window.scrollTo(0, 0);
  }
  var visuals = [];

  function generate() {
    show('panel-progress', 2);
    mainBtn.disabled = true;
    mainBtn.textContent = 'Génération en cours…';
    var fd = new FormData();
    fd.append('action', 'create');
    var keys = Object.keys(photos).map(Number).sort(function (a, b) { return a - b; });
    keys.forEach(function (i, n) {
      fd.append('photos[]', photos[i], 'photo-' + i + '.jpg');
      fd.append('labels[]', slot(i).dataset.label);
      if (i === mainIndex) fd.append('main', n);
    });
    step('create', 'run');
    post(fd).then(function (res) {
      if (!res.ok) { step('create', 'err', res.error); mainBtn.textContent = 'Réessayer'; mainBtn.disabled = false; mainBtn.onclick = generate; return; }
      ref = res.ref;
      step('create', 'ok', res.photos.length + ' photo(s) enregistrée(s) — Réf. N°' + ref);
      res.photos.forEach(function (p, n) { visuals.push({ path: p, label: n === 0 ? 'Photo principale' : 'Photo' }); });
      var chain = ['detoure', 'ambiance', 'sheet'];
      (function next() {
        var name = chain.shift();
        if (!name) { review(); return; }
        step(name, 'run');
        post(stepData(name)).then(function (r) {
          if (r.ok) {
            step(name, 'ok', name === 'sheet' ? 'Fiche rédigée : « ' + r.sheet.name + ' »' : 'Terminé');
            if (r.path) visuals.unshift({ path: r.path, label: r.label });
            if (r.sheet) sheet = r.sheet;
          } else {
            step(name, 'err', r.error);
          }
          next();
        });
      })();
    });
  }
  var sheet = null;

  function review() {
    var box = $('visuals'); box.textContent = '';
    visuals.forEach(function (v) {
      var fig = document.createElement('figure');
      var img = document.createElement('img'); img.src = '/' + v.path; img.alt = v.label; img.loading = 'lazy';
      var cap = document.createElement('figcaption'); cap.textContent = v.label;
      fig.appendChild(img); fig.appendChild(cap); box.appendChild(fig);
    });
    if (sheet) {
      $('f-name').value = sheet.name;
      $('f-desc').value = sheet.description;
      $('f-price').value = sheet.price_hint;
      $('f-cat').value = sheet.category;
    }
    $('f-badge').value = 'Chiné';
    show('panel-review', 3);
    mainBtn.disabled = false;
    mainBtn.textContent = 'Enregistrer la fiche';
    mainBtn.onclick = save;
  }

  function save() {
    mainBtn.disabled = true;
    mainBtn.textContent = 'Enregistrement…';
    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('ref', ref);
    fd.append('name', $('f-name').value);
    fd.append('price', $('f-price').value);
    fd.append('weight_grams', $('f-weight').value || '0');
    fd.append('cat', $('f-cat').value);
    fd.append('description', $('f-desc').value);
    fd.append('badge', $('f-badge').value);
    if ($('f-publish').checked) fd.append('publish', '1');
    post(fd).then(function (res) {
      if (!res.ok) { mainBtn.disabled = false; mainBtn.textContent = 'Réessayer l\'enregistrement'; alert(res.error); return; }
      $('done-title').textContent = res.published ? 'Pièce publiée' : 'Fiche enregistrée (masquée)';
      $('done-text').textContent = 'Réf. N°' + ref + (res.published ? ' — visible dans la boutique.' : ' — à relire puis publier depuis le catalogue.');
      $('done-link').href = '/admin/catalog.php#produit-' + encodeURIComponent(ref);
      show('panel-done', 4);
      document.querySelector('.qa-bar').hidden = true;
    });
  }

  mainBtn.onclick = generate;
  refresh();
})();
</script>
</body>
</html>
