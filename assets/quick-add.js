(function () {
  var root = document.querySelector('.qa');
  if (!root) return;
  var ACTION_URL = root.dataset.action;
  var AGAIN_URL = root.dataset.again;
  var LIST_URL = root.dataset.list;
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
    return fetch(ACTION_URL, { method: 'POST', body: data, credentials: 'same-origin' })
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
            failedSteps.push(name);
          }
          next();
        });
      })();
    });
  }
  var sheet = null;
  var failedSteps = [];

  function review() {
    // La pièce passe « à relire » côté serveur (liste « Mes pièces » du Studio) ; sans effet pour l'administration.
    var done = new FormData();
    done.append('action', 'finish'); done.append('ref', ref); done.append('failed', failedSteps.join(','));
    post(done).then(function () { if (window.studioRunVariants) window.studioRunVariants(); });
    $('panel-review').dataset.aiRef = ref; // boutons « ↻ IA » de la relecture (assets/product-ai.js)
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
      $('f-materials').value = sheet.materials || '';
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
    fd.append('materials', $('f-materials').value);
    fd.append('badge', $('f-badge').value);
    if ($('f-publish').checked) fd.append('publish', '1');
    post(fd).then(function (res) {
      if (!res.ok) { mainBtn.disabled = false; mainBtn.textContent = 'Réessayer l\'enregistrement'; alert(res.error); return; }
      $('done-title').textContent = res.published ? 'Pièce publiée' : 'Fiche enregistrée (masquée)';
      $('done-text').textContent = 'Réf. N°' + ref + (res.published ? ' — visible dans la boutique.' : ' — à relire puis publier depuis le catalogue.');
      $('done-link').href = LIST_URL.replace('{ref}', encodeURIComponent(ref));
      show('panel-done', 4);
      document.querySelector('.qa-bar').hidden = true;
    });
  }

  mainBtn.onclick = generate;
  refresh();
})();
