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
  var kind = 'objet';   // objet, vetement (recto / verso seulement) ou media (livre, CD, vinyle, DVD : scan)
  var scan = null;      // code lu par le scanner : {code, name, cover, label}
  var video = null;     // vidéo de la pièce (File), envoyée après les photos
  var laterMode = false; // « Shooting d'abord » : la pièce est enregistrée sans traitement (Studio)
  var aiReady = root.dataset.ai === '1';
  var VIDEO_MAX = 200 * 1048576;
  var measure = null;   // taille mesurée sur une photo : {text, blob (photo de mesure, si nouvelle)}

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

  // ── Type de pièce : vêtement = recto et verso seulement ; média = couverture et dos, plus le scan ──
  var KINDS = {
    objet: { note: '' },
    vetement: { max: 4, labels: ['Recto', 'Verso', 'Étiquette', 'Texture (gros plan)'], tips: ['Le vêtement entier, à plat ou sur cintre, de face', 'Le même vêtement vu de dos', 'L\'étiquette : marque, taille, composition, entretien', 'Gros plan sur la matière, le tissage ou une couture'], first: 'du recto',
      note: 'Vêtement : recto, verso, puis l\'étiquette et la texture en gros plan. L\'IA détoure le recto et le verso, fabrique les visuels recto / verso et la photo portée ; l\'étiquette et la texture lui servent à lire la taille, la marque et la matière.' },
    media: { max: 2, labels: ['Couverture', 'Dos / tranche'], tips: ['La pochette ou la couverture, de face', 'Le dos, la tranche ou le disque'], first: 'de la couverture',
      note: 'Livre, CD, vinyle, DVD : scannez le code-barres (ou le QR) pour récupérer titre, auteur, éditeur et pochette automatiquement.' },
  };
  var originals = [];   // libellés d'origine des emplacements (objet)
  shots.querySelectorAll('.qa-shot').forEach(function (s) {
    originals.push({ label: s.dataset.label, tip: s.querySelector('.qa-shot-take > small').textContent });
  });
  function applyKind(next) {
    var cfg = KINDS[next];
    var over = Object.keys(photos).filter(function (i) { return cfg.max && +i >= cfg.max && !slot(+i).dataset.detail; });
    if (over.length && !confirm('Les photos des autres angles (' + over.length + ') seront retirées. Continuer ?')) return false;
    over.forEach(function (i) { removePhoto(+i); });
    kind = next;
    document.querySelectorAll('#qa-kinds [data-kind]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.kind === kind)); });
    $('qa-kind-note').textContent = cfg.note;
    $('qa-kind-note').hidden = !cfg.note;
    $('qa-scan').hidden = kind !== 'media';
    shots.querySelectorAll('.qa-shot').forEach(function (s) {
      var i = +s.dataset.index;
      if (s.dataset.detail) return;   // les emplacements « Détail » ajoutés restent, quel que soit le type
      s.hidden = !!(cfg.max && i >= cfg.max);
      if (s.hidden) return;
      var label = cfg.labels ? cfg.labels[i] : (originals[i] ? originals[i].label : s.dataset.label);
      var tip = cfg.tips ? cfg.tips[i] : (originals[i] ? originals[i].tip : s.querySelector('.qa-shot-take > small').textContent);
      s.dataset.label = label;
      s.querySelector('.qa-shot-take b').innerHTML = '';
      s.querySelector('.qa-shot-take b').appendChild(document.createTextNode(label));
      if (i > 0) { var f = document.createElement('small'); f.textContent = cfg.labels ? ' (conseillé)' : ' (facultatif)'; s.querySelector('.qa-shot-take b').appendChild(f); }
      s.querySelector('.qa-shot-take > small').textContent = tip;
      s.querySelector('.qa-shot-bar > span').textContent = label;
    });
    $('add-shot').hidden = !!cfg.max;
    $('qa-shots-note').textContent = cfg.max
      ? 'Touchez un cadre pour ouvrir l\'appareil photo. « Principale » choisit la photo détourée et mise en situation.'
      : 'Touchez un cadre pour ouvrir l\'appareil photo. La photo de face est obligatoire ; les autres angles aident l\'IA à décrire la pièce. « Principale » choisit la photo détourée et mise en situation.';
    if (photos[mainIndex] === undefined) mainIndex = +(Object.keys(photos)[0] || 0);
    refresh();
    return true;
  }
  document.querySelectorAll('#qa-kinds [data-kind]').forEach(function (b) { b.addEventListener('click', function () { if (b.dataset.kind !== kind) applyKind(b.dataset.kind); }); });

  // ── Scan d'un média (ISBN, EAN, QR) : titre, auteur, pochette… récupérés au catalogue ──
  function renderScan(res, box) {
    box.textContent = '';
    scan = null;
    if (res.code || res.ok) scan = { code: res.code || '', name: res.ok ? res.name : '', cover: res.ok ? (res.cover || '') : '', label: res.ok ? (res.label || 'Média') : '' };
    var msg = document.createElement('p'); msg.className = 'scan-msg';
    if (!res.ok) {
      msg.className += ' is-warn'; msg.textContent = res.error || 'Recherche impossible.'; box.appendChild(msg);
      if (res.code) { var keep = document.createElement('p'); keep.className = 'scan-msg'; keep.textContent = 'Code ' + res.code + ' gardé : prenez la pochette en photo.'; box.appendChild(keep); }
      refresh(); return;
    }
    var card = document.createElement('div'); card.className = 'scan-card';
    if (res.cover) { var img = document.createElement('img'); img.className = 'scan-cover'; img.alt = ''; img.src = res.cover; img.referrerPolicy = 'no-referrer'; img.onerror = function () { img.remove(); }; card.appendChild(img); }
    var body = document.createElement('div'); body.className = 'scan-body';
    var title = document.createElement('p'); title.className = 'scan-title'; var kindTag = document.createElement('span'); kindTag.className = 'scan-kind'; kindTag.textContent = res.label || 'Média'; title.appendChild(kindTag); title.appendChild(document.createTextNode(' ' + res.name)); body.appendChild(title);
    var src = document.createElement('p'); src.className = 'scan-src'; src.textContent = 'Source : ' + (res.sources || []).join(', ') + (res.code ? ' · code ' + res.code : ''); body.appendChild(src);
    if (res.existing) { var w = document.createElement('p'); w.className = 'scan-msg is-warn'; w.textContent = 'Déjà au catalogue : ' + res.existing.name + ' (réf. ' + res.existing.ref + ').'; body.appendChild(w); }
    var ok = document.createElement('p'); ok.className = 'scan-msg is-ok'; ok.textContent = res.cover ? 'La pochette servira de photo si vous n\'en prenez pas.' : 'Pas de pochette trouvée : prenez la couverture en photo.'; body.appendChild(ok);
    var drop = document.createElement('button'); drop.type = 'button'; drop.className = 'btn-small'; drop.textContent = 'Retirer ce scan';
    drop.addEventListener('click', function () { scan = null; box.textContent = ''; refresh(); });
    body.appendChild(drop);
    card.appendChild(body); box.appendChild(card);
    refresh();
  }
  if (window.barcodeScanWidget) window.barcodeScanWidget($('qa-scan'), renderScan, 'Scannez le code-barres ou le QR du livre, CD, vinyle ou DVD : la fiche (titre, auteur, éditeur, pochette) est récupérée.');

  function refresh() {
    var count = Object.keys(photos).length;
    shots.querySelectorAll('.qa-shot').forEach(function (s) {
      var i = +s.dataset.index;
      s.classList.toggle('is-main', i === mainIndex);
      s.querySelector('[data-act="main"]').setAttribute('aria-pressed', String(i === mainIndex));
    });
    var coverOnly = kind === 'media' && scan && scan.cover;
    mainBtn.disabled = !photos[mainIndex] && !count && !coverOnly;
    var first = (KINDS[kind] && KINDS[kind].first) || 'de face';
    if (!count) mainBtn.textContent = coverOnly ? (laterMode ? 'Enregistrer (pochette du scan)' : 'Générer la fiche (pochette du scan)') : 'Prenez au moins la photo ' + first;
    else mainBtn.textContent = (laterMode ? 'Enregistrer, pièce suivante' : 'Générer la fiche') + ' (' + count + ' photo' + (count > 1 ? 's' : '') + ')';
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
      suggestObjects(i, blob);
    });
  }

  // ── Ensemble d'objets : l'IA repère les objets de la photo, on choisit celui à vendre ──
  var multi = { slot: null, blob: null, objects: null };
  function replacePhoto(i, blob) {
    var s = slot(i);
    photos[i] = blob;
    var img = s.querySelector('img'); if (img) img.src = URL.createObjectURL(blob);
    refresh();
  }
  function pickFor(i, objects) {
    if (!window.studioPickObject || !photos[i]) return;
    window.studioPickObject(photos[i], objects ? { objects: objects } : {}).then(function (b) {
      $('qa-multi').hidden = true;
      if (b) replacePhoto(i, b);
    });
  }
  function suggestObjects(i, blob) {
    // Seulement pour la photo principale, et si l'IA est disponible : un appel léger, sans bloquer la prise de vue.
    if (!aiReady || !window.studioDetectObjects || i !== mainIndex) return;
    window.studioDetectObjects(blob).then(function (objects) {
      if (!objects || objects.length < 2 || photos[i] !== blob) return;
      multi = { slot: i, blob: blob, objects: objects };
      $('qa-multi').hidden = false;
    });
  }
  $('qa-multi-pick').addEventListener('click', function () { if (multi.slot != null) pickFor(multi.slot, multi.objects); });
  $('qa-multi-no').addEventListener('click', function () { $('qa-multi').hidden = true; });

  function bindSlot(s) {
    var i = +s.dataset.index;
    s.querySelector('input[type=file]').addEventListener('change', function () {
      if (this.files[0]) setPhoto(i, this.files[0]);
      this.value = '';
    });
    s.querySelector('[data-act="main"]').addEventListener('click', function () { mainIndex = i; refresh(); });
    s.querySelector('[data-act="retake"]').addEventListener('click', function () { s.querySelector('input[type=file]').click(); });
    s.querySelector('[data-act="remove"]').addEventListener('click', function () { removePhoto(i); });
    s.querySelector('[data-act="pick"]').addEventListener('click', function () { if (photos[i]) pickFor(i); else s.querySelector('input[type=file]').click(); });
  }
  function removePhoto(i) {
    var s = slot(i);
    delete photos[i];
    var img = s.querySelector('img'); if (img) img.remove();
    s.classList.remove('has-photo');
    if (mainIndex === i) mainIndex = +(Object.keys(photos)[0] || 0);
    if (multi.slot === i) $('qa-multi').hidden = true;
    refresh();
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

  // Emplacement « Détail » supplémentaire (marque, défaut, signature, couture… autant que nécessaire), pour tous les types.
  var MAX_SLOTS = 10;   // le serveur accepte 10 photos par pièce
  function visibleSlots() { return Array.prototype.filter.call(shots.querySelectorAll('.qa-shot'), function (s) { return !s.hidden; }).length; }
  function addDetailSlot() {
    if (visibleSlots() >= MAX_SLOTS) { alert('10 photos au plus par pièce.'); return null; }
    var i = shots.children.length;
    var n = Array.prototype.filter.call(shots.querySelectorAll('.qa-shot'), function (s) { return /^Détail/.test(s.dataset.label) && !s.hidden; }).length + 1;
    var label = 'Détail ' + n;
    var s = slot(1).cloneNode(true);
    s.hidden = false; s.dataset.index = i; s.dataset.label = label; s.dataset.detail = '1';
    s.classList.remove('has-photo', 'is-main');
    var old = s.querySelector('img'); if (old) old.remove();
    s.querySelector('input').id = 'cam-' + i;
    s.querySelector('label').htmlFor = 'cam-' + i;
    s.querySelector('label b').textContent = label;
    s.querySelector('label > small').textContent = 'Un gros plan : marque, défaut, signature, couture…';
    s.querySelector('.qa-shot-bar > span').textContent = label;
    shots.appendChild(s);
    bindSlot(s);
    return s;
  }
  $('add-detail').addEventListener('click', function () { var s = addDetailSlot(); if (s) s.querySelector('input').click(); });

  // Galerie : remplit les emplacements vides dans l'ordre (ceux du type de pièce choisi).
  $('gallery').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files);
    shots.querySelectorAll('.qa-shot').forEach(function (s) {
      if (!files.length || s.hidden || photos[+s.dataset.index]) return;
      setPhoto(+s.dataset.index, files.shift());
    });
    while (files.length && visibleSlots() < MAX_SLOTS) {
      var extraSlot;
      if (KINDS[kind].max) extraSlot = addDetailSlot(); else { $('add-shot').click(); extraSlot = shots.lastElementChild; }
      if (!extraSlot) break;
      setPhoto(+extraSlot.dataset.index, files.shift());
    }
    this.value = '';
  });

  // ── Vidéo de la pièce (facultative) : filmée avec l'appareil du téléphone, envoyée par morceaux après les photos ──
  $('qa-video-input').addEventListener('change', function () {
    var f = this.files && this.files[0]; this.value = '';
    if (!f) return;
    if (f.size > VIDEO_MAX) { $('qa-video-state').textContent = 'Vidéo trop lourde (' + Math.round(f.size / 1048576) + ' Mo, 200 Mo au plus) : filmez plus court.'; return; }
    video = f;
    $('qa-video-state').textContent = '✓ Vidéo prête (' + (f.size < 1048576 ? Math.max(1, Math.round(f.size / 1024)) + ' Ko' : (f.size / 1048576).toFixed(1).replace('.', ',') + ' Mo') + ')';
    $('qa-video-remove').hidden = false;
  });
  $('qa-video-remove').addEventListener('click', function () { video = null; $('qa-video-state').textContent = ''; this.hidden = true; });

  /** Envoie la vidéo par morceaux (chunk octets) ; résout en {ok, error?}. Deux essais par morceau. */
  function uploadVideo(forRef, file, chunk) {
    var id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(8)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    var total = Math.max(1, Math.ceil(file.size / chunk));
    var name = file.name && /\.(mp4|mov|webm|m4v)$/i.test(file.name) ? file.name : (/webm/.test(file.type) ? 'video.webm' : (/quicktime/.test(file.type) ? 'video.mov' : 'video.mp4'));
    function sendChunk(i, attempt) {
      var fd = new FormData();
      fd.append('action', 'video'); fd.append('ref', forRef); fd.append('upload_id', id); fd.append('index', i); fd.append('total', total); fd.append('size', file.size); fd.append('name', name);
      fd.append('file', file.slice(i * chunk, Math.min(file.size, (i + 1) * chunk)), 'part');
      return post(fd).then(function (r) {
        if (r.ok) return r;
        if (attempt < 1 && !/format|trop|interrompu/i.test(r.error || '')) return sendChunk(i, attempt + 1);
        return r;
      });
    }
    return (function loop(i) {
      if (i >= total) return Promise.resolve({ ok: true });
      var li = document.querySelector('#progress li[data-step="video"] small');
      if (li && total > 1) li.textContent = 'Envoi de la vidéo : ' + Math.round(i / total * 100) + ' %';
      return sendChunk(i, 0).then(function (r) { return r.ok ? loop(i + 1) : r; });
    })(0);
  }

  // ── Mesure de la pièce : repère de taille connue (règle, mètre, pièce de monnaie) tracé au doigt sur une photo ──
  function showMeasure() {
    $('qa-measure-state').textContent = measure ? '✓ Taille : ' + measure.text : '';
    $('qa-measure-remove').hidden = !measure;
  }
  $('qa-measure-btn').addEventListener('click', function () {
    if (!window.studioMeasure) return;
    var imgs = Object.keys(photos).map(Number).sort(function (a, b) { return a - b; }).filter(function (i) { return !slot(i).hidden; })
      .map(function (i) { return { blob: photos[i], label: slot(i).dataset.label }; });
    window.studioMeasure({ images: imgs, ai: aiReady }).then(function (r) { if (r) { measure = { text: r.text, blob: r.newBlob || null }; showMeasure(); } });
  });
  $('qa-measure-remove').addEventListener('click', function () { measure = null; showMeasure(); });
  // Relecture : mesurer sur l'une des photos de la pièce (la taille remplit le champ « Taille »).
  $('f-measure').addEventListener('click', function () {
    if (!window.studioMeasure) return;
    window.studioMeasure({ images: visuals.map(function (v) { return { url: '/' + v.path, label: v.label }; }), ai: aiReady }).then(function (r) {
      if (!r) return;
      var f = $('f-size'); f.value = r.text; f.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  // ── Mode de travail (Studio) : tout de suite, ou shooting d'abord puis traitement par lot ──
  if (root.dataset.later === '1' && $('qa-mode')) {
    try { laterMode = localStorage.getItem('studio-mode') === 'later'; } catch (e) {}
    var setMode = function (m) {
      laterMode = m === 'later';
      try { localStorage.setItem('studio-mode', m); } catch (e) {}
      document.querySelectorAll('#qa-mode [data-mode]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.mode === m)); });
      $('qa-cost-note').hidden = laterMode;
      document.dispatchEvent(new CustomEvent('studio-mode', { detail: m }));
      refresh();
    };
    document.querySelectorAll('#qa-mode [data-mode]').forEach(function (b) { b.addEventListener('click', function () { setMode(b.dataset.mode); }); });
    setMode(laterMode ? 'later' : 'now');
  }

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
    fd.append('kind', kind);
    fd.append('source', 'single');
    fd.append('notes', $('notes').value);
    if (scan && scan.code) fd.append('barcode', scan.code);
    if (scan && scan.cover) fd.append('cover_url', scan.cover);
    if (measure) fd.append('size_text', measure.text);
    var keys = Object.keys(photos).map(Number).sort(function (a, b) { return a - b; });
    keys.forEach(function (i, n) {
      fd.append('photos[]', photos[i], 'photo-' + i + '.jpg');
      fd.append('labels[]', slot(i).dataset.label);
      if (i === mainIndex) fd.append('main', n);
    });
    // Photo de mesure (repère posé à côté de la pièce) : gardée pour mémoire, masquée dans la boutique.
    if (measure && measure.blob && keys.length < 10) { fd.append('photos[]', measure.blob, 'mesure.jpg'); fd.append('labels[]', 'Mesure (photo de référence)'); }
    // Shooting d'abord : seuls l'envoi (et la vidéo) sont affichés, le traitement se fera plus tard, par lot.
    if (laterMode) document.querySelectorAll('#progress li').forEach(function (li) { li.hidden = li.dataset.step !== 'create' && !(li.dataset.step === 'video' && video); });
    if (video) { var vli = document.querySelector('#progress li[data-step="video"]'); if (vli) vli.hidden = false; }
    step('create', 'run');
    post(fd).then(function (res) {
      if (!res.ok) { step('create', 'err', res.error); mainBtn.textContent = 'Réessayer'; mainBtn.disabled = false; mainBtn.onclick = generate; return; }
      ref = res.ref;
      step('create', 'ok', res.photos.length + ' photo(s) enregistrée(s) — Réf. N°' + ref);
      res.photos.forEach(function (p, n) { visuals.push({ path: p, label: n === 0 ? 'Photo principale' : 'Photo' }); });
      var sendVideo = video ? (step('video', 'run'), uploadVideo(ref, video, res.chunk || 4 * 1048576)) : Promise.resolve({ ok: true, none: true });
      sendVideo.then(function (v) {
        if (video) { if (v.ok) step('video', 'ok', 'Vidéo enregistrée'); else { step('video', 'err', v.error || 'Envoi impossible : ajoutez-la depuis la galerie de la pièce.'); failedSteps.push('video'); } }
        if (laterMode) {
          // La pièce attend son traitement (« Mes pièces ») : on repart sur une page neuve pour la suivante.
          try { sessionStorage.setItem('studio-queued', ref); } catch (e) {}
          location.reload();
          return;
        }
        afterCreate();
      });
    });
  }

  function afterCreate() {
    {
      // La fiche avant la mise en situation : la nature de la pièce (vêtement, meuble…) décide du décor. Avec 2 photos ou
      // plus : détourage des autres angles, puis recto et verso générés à partir de tous les détourages.
      var chain = ['detoure', 'sheet', 'ambiance', 'angles', 'recto', 'verso'];
      (function next() {
        var name = chain.shift();
        if (!name) { review(); return; }
        step(name, 'run');
        var skipIds = [];
        (function run() {
          var fd = stepData(name);
          if (name === 'angles' && skipIds.length) fd.append('skip', skipIds.join(','));
          post(fd).then(function (r) {
            if (r.ok && r.skipped) {
              var skipLi = document.querySelector('#progress li[data-step="' + name + '"]');
              if (skipLi) skipLi.hidden = true;
            } else if (r.ok) {
              step(name, 'ok', name === 'sheet' ? 'Fiche rédigée : « ' + r.sheet.name + ' »' : (name === 'angles' ? 'Tous les angles détourés' : 'Terminé'));
              if (r.path) visuals.unshift({ path: r.path, label: r.label });
              if (r.sheet) sheet = r.sheet;
              if (name === 'angles' && r.remaining > 0) { step(name, 'run', r.remaining + ' angle(s) restant(s)…'); run(); return; }
            } else {
              // Un angle impossible à détourer : on passe aux suivants (le recto / verso utilisera sa photo telle quelle).
              if (name === 'angles' && r.photo_id) {
                skipIds.push(r.photo_id);
                if (r.remaining > 0) { run(); return; }
              }
              step(name, 'err', r.error);
              failedSteps.push(name);
            }
            next();
          });
        })();
      })();
    }
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
      $('f-brand').value = sheet.brand || '';
      $('f-distributor').value = sheet.distributor || '';
      $('f-etat').value = sheet.etat || '';
      $('f-size').value = sheet.size_text || '';
      if (sheet.weight_grams) $('f-weight').value = sheet.weight_grams;
      $('f-nature').value = sheet.nature || '';
      $('f-nature').dispatchEvent(new Event('change'));
      $('f-sous').value = sheet.sous_categorie || '';
      $('f-price').value = sheet.price_hint;
      $('f-cat').value = sheet.category;
      if (sheet.barcode) $('f-barcode').value = sheet.barcode;
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
    fd.append('brand', $('f-brand').value);
    fd.append('distributor', $('f-distributor').value);
    fd.append('etat', $('f-etat').value);
    fd.append('size_text', $('f-size').value);
    fd.append('nature', $('f-nature').value);
    fd.append('sous_categorie', $('f-sous').value);
    fd.append('badge', $('f-badge').value);
    fd.append('barcode', $('f-barcode').value);
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
  applyKind('objet');
})();
