// Application smartphone Studio (studio.php) : onglets, shooting en lot avec viseur,
// file de traitement des pièces et relecture. Le parcours « Une pièce » est dans quick-add.js.
// Tous les appels passent par admin/quick-add-action.php (réponses JSON {ok, ...}).
(function () {
  'use strict';
  var script = document.currentScript;
  var ACTION = script.dataset.action;
  var VARIANTS = script.dataset.variants;
  var MAX_PER_PIECE = 10;         // photos par pièce (le serveur en accepte 10)
  var MAX_REQUEST_BYTES = 9 * 1048576; // OVH coupe les requêtes plus lourdes (~16 Mo mesurés)

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function sizeText(n) { return n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' Ko' : (n / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }
  function plural(n, one, many) { return n + ' ' + (n > 1 ? many : one); }

  // ── Appels serveur ────────────────────────────────────────────────────────
  function api(data) {
    var fd = data instanceof FormData ? data : (function () { var f = new FormData(); Object.keys(data).forEach(function (k) { f.append(k, data[k]); }); return f; })();
    return fetch(ACTION, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().then(function (j) { j.status = r.status; return j; })
          .catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').', status: r.status }; });
      })
      .catch(function () { return { ok: false, network: true, error: 'Connexion perdue : vérifiez le réseau.' }; });
  }
  // Une session expirée ou une coupure arrête les traitements en cours (reprise possible plus tard).
  function must(res) {
    if (res.network) throw new Error('network');
    if (res.status === 401) throw new Error('auth');
    return res;
  }

  var toastTimer = null;
  function toast(msg) {
    var t = $('st-toast');
    if (!t) { t = el('div', 'st-toast'); t.id = 'st-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('is-on'); }, 2600);
  }

  // Écran allumé pendant les traitements longs.
  var wakeLock = null;
  function keepAwake(on) {
    try {
      if (on && navigator.wakeLock) navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }).catch(function () {});
      else if (!on && wakeLock) { wakeLock.release(); wakeLock = null; }
    } catch (e) {}
  }

  // Photo réduite à 1600 px (JPEG) avant envoi : rapide en 4G et sous la limite d'envoi.
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

  // ── Onglets ───────────────────────────────────────────────────────────────
  var TABS = { single: 'tab-single', batch: 'tab-batch', pieces: 'tab-list' };
  var currentTab = 'single';
  function go(name) {
    if (!TABS[name]) name = 'single';
    currentTab = name;
    Object.keys(TABS).forEach(function (k) { $(TABS[k]).hidden = k !== name; });
    document.querySelectorAll('.st-tabs [data-go]').forEach(function (b) {
      if (b.dataset.go === name) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
    });
    if (name !== 'batch') stopCamera(); else resumeCameraIfShooting();
    if (name === 'pieces') { loadList(); if (window.studioRunVariants) window.studioRunVariants(); }
    if (location.hash.replace('#', '') !== (name === 'single' ? '' : name)) history.replaceState(null, '', name === 'single' ? location.pathname + location.search : '#' + name);
    window.scrollTo(0, 0);
  }
  document.querySelectorAll('.st-tabs [data-go]').forEach(function (b) { b.addEventListener('click', function () { go(b.dataset.go); }); });
  window.addEventListener('hashchange', function () { go(location.hash.replace('#', '') || 'single'); });

  // ── Brouillon du lot (conservé si le téléphone ferme la page) ─────────────
  var idb = null;
  function idbOpen() {
    return new Promise(function (resolve) {
      try {
        var r = indexedDB.open('studio-draft', 1);
        r.onupgradeneeded = function () { r.result.createObjectStore('blobs'); r.result.createObjectStore('meta'); };
        r.onsuccess = function () { resolve(r.result); };
        r.onerror = function () { resolve(null); };
      } catch (e) { resolve(null); }
    });
  }
  function idbDo(store, mode, fn) {
    return new Promise(function (resolve) {
      if (!idb) return resolve(undefined);
      try {
        var tx = idb.transaction(store, mode);
        var req = fn(tx.objectStore(store));
        tx.oncomplete = function () { resolve(req && req.result); };
        tx.onerror = tx.onabort = function () { resolve(undefined); };
      } catch (e) { resolve(undefined); }
    });
  }

  // ── Lot : modèle ──────────────────────────────────────────────────────────
  var pieces = [[]];   // pièces → photos {id, blob, url} ; la dernière est la pièce en cours
  var nextId = 1;
  var running = false;

  // Type de pièce (objet, vêtement : recto et verso seulement, média : scan), code scanné et vidéo, portés par la pièce (tableau de photos).
  // labels : les vues guidées, dans l'ordre ; au-delà (et à tout moment avec « Gros plan »), les photos sont des détails.
  var KIND_SHOTS = { objet: { max: MAX_PER_PIECE, labels: ['Face', 'Profil droit', 'Profil gauche', 'Dos', 'Dessus', 'Dessous'] }, vetement: { max: MAX_PER_PIECE, labels: ['Recto', 'Verso', 'Étiquette', 'Texture (gros plan)'] }, media: { max: MAX_PER_PIECE, labels: ['Couverture', 'Dos / tranche'] } };
  /** Photos de la pièce qui sont des vues (pas des gros plans), et libellé de chaque photo envoyée au serveur. */
  function viewCount(p) { return p.filter(function (x) { return !x.detail; }).length; }
  function shotLabels(piece) {
    var guided = KIND_SHOTS[meta(piece).kind].labels, v = 0, d = 0;
    return piece.map(function (x) {
      if (x.measure) return 'Mesure (photo de référence)';
      if (x.detail) return 'Détail ' + (++d);
      v++;
      return guided[v - 1] || 'Vue ' + v;
    });
  }
  function mainIndexOf(piece) { var i = piece.findIndex(function (x) { return !x.detail; }); return i < 0 ? 0 : i; }
  function meta(p) { return p.meta || (p.meta = { kind: 'objet', scan: null, video: null, detail: false, size: '' }); }
  function pieceMax(p) { return KIND_SHOTS[meta(p).kind].max; }
  /** Une pièce compte si elle a des photos, ou si c'est un média scanné avec sa pochette (sans photo). */
  function pieceValid(p) { var m = meta(p); return p.length > 0 || (m.kind === 'media' && !!(m.scan && m.scan.cover)); }
  function total() { return pieces.reduce(function (n, p) { return n + p.length; }, 0); }
  function validCount() { return pieces.filter(pieceValid).length; }
  function saveLayout() {
    return idbDo('meta', 'readwrite', function (s) {
      return s.put(pieces.map(function (p) { var m = meta(p); return { ids: p.map(function (x) { return x.id; }), details: p.filter(function (x) { return x.detail; }).map(function (x) { return x.id; }), measures: p.filter(function (x) { return x.measure; }).map(function (x) { return x.id; }), size: m.size || '', kind: m.kind, scan: m.scan, video: m.video ? m.video.id : null }; }), 'layout');
    });
  }
  function addShot(blob, flags) {
    var piece = pieces[pieces.length - 1];
    if (piece.length >= pieceMax(piece)) { toast('Déjà ' + MAX_PER_PIECE + ' photos : passez à la pièce suivante.'); return false; }
    var shot = { id: nextId++, blob: blob, url: URL.createObjectURL(blob), detail: !!meta(piece).detail };
    if (flags) { shot.detail = !!flags.detail; shot.measure = !!flags.measure; }
    piece.push(shot);
    idbDo('blobs', 'readwrite', function (s) { return s.put(blob, shot.id); }).then(saveLayout);
    renderShoot();
    return true;
  }
  function dropShot(shot) {
    idbDo('blobs', 'readwrite', function (s) { return s.delete(shot.id); });
    URL.revokeObjectURL(shot.url);
  }
  function dropVideo(p) {
    var m = meta(p);
    if (m.video) { idbDo('blobs', 'readwrite', function (s) { return s.delete(m.video.id); }); URL.revokeObjectURL(m.video.url); m.video = null; }
  }
  function tidy() {
    // Plus de pièce vide, sauf la dernière (celle en cours de prise de vue).
    pieces = pieces.filter(function (p, i) { return pieceValid(p) || i === pieces.length - 1; });
    if (!pieces.length) pieces = [[]];
  }
  function nextPiece() {
    var last = pieces[pieces.length - 1];
    if (!pieceValid(last)) return;
    // La pièce suivante garde le type de la précédente (une série de vêtements, de disques…) ; le scan est à refaire.
    var np = []; meta(np).kind = meta(last).kind;
    pieces.push(np);
    saveLayout(); renderShoot();
    toast('Pièce ' + pieces.length + ' : prenez sa première photo.');
  }
  function clearDraft() {
    pieces.forEach(function (p) { p.forEach(function (s) { URL.revokeObjectURL(s.url); }); if (meta(p).video) URL.revokeObjectURL(meta(p).video.url); });
    pieces = [[]];
    idbDo('blobs', 'readwrite', function (s) { return s.clear(); });
    idbDo('meta', 'readwrite', function (s) { return s.clear(); });
  }

  // ── Lot : prise de vue ────────────────────────────────────────────────────
  var stream = null;
  var video = $('sb-video');
  var screens = ['sb-shoot', 'sb-review', 'sb-run'];
  function showScreen(id) {
    screens.forEach(function (s) { $(s).hidden = s !== id; });
    if (id !== 'sb-shoot') stopCamera();
    window.scrollTo(0, 0);
  }

  function startCamera() {
    if (stream) return Promise.resolve();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject(new Error('unsupported'));
    return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1440 } }, audio: false })
      .then(function (s) { stream = s; video.srcObject = s; return video.play(); });
  }
  function stopCamera() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    video.srcObject = null;
  }
  function resumeCameraIfShooting() {
    if (!$('sb-camera').hidden && !$('sb-shoot').hidden) startCamera().catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopCamera(); return; }
    if (currentTab === 'batch') resumeCameraIfShooting();
    if (running) keepAwake(true);
  });

  function showCameraUI(on) {
    $('sb-intro').hidden = on;
    $('sb-camera').hidden = !on;
    renderShoot();
  }

  function renderShoot() {
    var piece = pieces[pieces.length - 1], m = meta(piece), cfg = KIND_SHOTS[m.kind];
    $('sb-piece-label').textContent = 'Pièce ' + pieces.length;
    $('sb-total-label').textContent = plural(piece.length, 'photo', 'photos') + ' · ' + plural(total(), 'au total', 'au total');
    $('sb-next').disabled = !pieceValid(piece);
    $('sb-done').disabled = !validCount();
    $('sb-done').textContent = validCount() ? 'Terminer (' + plural(validCount(), 'pièce', 'pièces') + ')' : 'Terminer le shooting';
    document.querySelectorAll('#sb-kinds [data-kind]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.kind === m.kind)); });
    // Consigne de la prochaine photo selon le type de pièce.
    var guide = $('sb-guide'), views = viewCount(piece), guided = cfg.labels;
    if (m.detail) guide.textContent = 'Gros plans : chaque photo est un détail (marque, défaut, couture, signature…). Touchez « Gros plan » pour revenir aux vues.';
    else if (views < guided.length) guide.textContent = ({ objet: 'Objet : ', vetement: 'Vêtement : ', media: 'Média : ' })[m.kind] + 'prochaine photo — ' + guided[views] + (views ? '' : (m.kind === 'media' ? ' (après le scan du code)' : '')) + '.';
    else guide.textContent = 'Vues prises : ajoutez autant de gros plans que vous voulez (bouton « Gros plan »), filmez la pièce, ou « Pièce suivante ».' + (m.kind === 'objet' ? ' Un ensemble ? Isolez l\'objet à la relecture.' : '');
    $('sb-detail').classList.toggle('is-on', !!m.detail);
    $('sb-detail').setAttribute('aria-pressed', String(!!m.detail));
    $('sb-next').classList.toggle('is-ready', views >= guided.length);
    $('sb-scan').hidden = m.kind !== 'media';
    var chip = $('sb-chips'); chip.textContent = '';
    if (m.scan) {
      var sc = el('span', 'sb-chip', '📚 ' + (m.scan.name || ('Code ' + m.scan.code)));
      var x = el('button', 'sb-chip-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Retirer le scan');
      x.addEventListener('click', function () { m.scan = null; saveLayout(); renderShoot(); });
      sc.appendChild(x); chip.appendChild(sc);
    }
    if (m.size) {
      var zc = el('span', 'sb-chip', '📏 ' + m.size);
      var zx = el('button', 'sb-chip-x', '×'); zx.type = 'button'; zx.setAttribute('aria-label', 'Retirer la taille');
      zx.addEventListener('click', function () { m.size = ''; saveLayout(); renderShoot(); });
      zc.appendChild(zx); chip.appendChild(zc);
    }
    if (m.video) {
      var vc = el('span', 'sb-chip', '🎬 Vidéo ' + (m.video.seconds ? m.video.seconds + ' s' : '') + ' (' + sizeText(m.video.blob.size) + ')');
      var vx = el('button', 'sb-chip-x', '×'); vx.type = 'button'; vx.setAttribute('aria-label', 'Retirer la vidéo');
      vx.addEventListener('click', function () { dropVideo(piece); saveLayout(); renderShoot(); });
      vc.appendChild(vx); chip.appendChild(vc);
    }
    var strip = $('sb-strip');
    strip.textContent = '';
    piece.forEach(function (shot, i) {
      var b = el('button', 'sb-thumb'); b.type = 'button';
      b.setAttribute('aria-label', 'Retirer la photo ' + (i + 1));
      var img = el('img'); img.src = shot.url; img.alt = '';
      b.appendChild(img); b.appendChild(el('span', '', '×'));
      b.addEventListener('click', function () { piece.splice(i, 1); dropShot(shot); saveLayout(); renderShoot(); });
      strip.appendChild(b);
    });
    if (piece.length) strip.scrollLeft = strip.scrollWidth;
  }

  // Type de la pièce en cours : le vêtement n'a que recto et verso ; le média se scanne.
  document.querySelectorAll('#sb-kinds [data-kind]').forEach(function (b) {
    b.addEventListener('click', function () {
      var piece = pieces[pieces.length - 1], m = meta(piece), k = b.dataset.kind;
      if (m.kind === k) return;
      m.kind = k; if (k !== 'media') m.scan = null;
      saveLayout(); renderShoot();
    });
  });

  // « Gros plan » : les photos suivantes sont des détails (étiquette, défaut, signature, texture…), autant qu'on veut.
  $('sb-detail').addEventListener('click', function () { var m = meta(pieces[pieces.length - 1]); m.detail = !m.detail; renderShoot(); });

  // Mesure : repère de taille connue (règle, mètre, pièce de monnaie) tracé au doigt sur une photo de la pièce.
  $('sb-measure').addEventListener('click', function () {
    if (!window.studioMeasure) return;
    var piece = pieces[pieces.length - 1];
    var imgs = piece.filter(function (x) { return !x.measure; }).map(function (x, i) { return { blob: x.blob, label: 'Photo ' + (i + 1) }; });
    var qa = document.querySelector('.qa');
    stopCamera();
    window.studioMeasure({ images: imgs, ai: !!(qa && qa.dataset.ai === '1') }).then(function (r) {
      resumeCameraIfShooting();
      if (!r) return;
      meta(piece).size = r.text;
      // Photo prise pour l'occasion : gardée avec la pièce (masquée dans la boutique), jamais comptée comme une vue.
      if (r.newBlob && piece.length < pieceMax(piece)) addShot(r.newBlob, { detail: true, measure: true });
      saveLayout(); renderShoot(); toast('Taille notée : ' + r.text);
    });
  });

  // Scan d'un code-barres / QR (livre, CD, vinyle, DVD) : la fiche et la pochette sont cherchées au catalogue.
  function applyScan(piece, code) {
    toast('Recherche du média…');
    var lookup = window.barcodeScanLookup ? window.barcodeScanLookup(code) : Promise.resolve({ ok: false, code: '' });
    return lookup.then(function (res) {
      var m = meta(piece);
      m.scan = { code: res.code || '', name: res.ok ? res.name : '', cover: res.ok ? (res.cover || '') : '', label: res.ok ? res.label : '' };
      if (!res.ok && !res.code) { m.scan = null; toast(res.error || 'Code non reconnu.'); return; }
      toast(res.ok ? 'Trouvé : ' + res.name : 'Code gardé (' + res.code + ') : photographiez la couverture.');
      saveLayout(); renderShoot();
    }).catch(function () { toast('Recherche impossible : vérifiez la connexion.'); });
  }
  $('sb-scan').addEventListener('click', function () {
    var piece = pieces[pieces.length - 1];
    if (!window.barcodeScanCamera || !window.isSecureContext || !navigator.mediaDevices) {
      var typed = prompt('Saisissez le code-barres, l\'ISBN ou l\'adresse du QR :');
      if (typed) applyScan(piece, typed);
      return;
    }
    stopCamera();   // le scanner ouvre sa propre caméra
    window.barcodeScanCamera(function (code) { applyScan(piece, code); }, function () { toast('Caméra du scanner inaccessible : saisissez le code.'); var t = prompt('Saisissez le code-barres, l\'ISBN ou l\'adresse du QR :'); if (t) applyScan(piece, t); }, function () { resumeCameraIfShooting(); });
  });

  // Vidéo de la pièce : enregistrée par le viseur (MediaRecorder), sinon par l'appareil du téléphone.
  var recorder = null, recChunks = [], recTimer = null, recStart = 0;
  var REC_MAX = 20; // secondes
  function recMime() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    return ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].filter(function (t) { return MediaRecorder.isTypeSupported(t); })[0] || '';
  }
  function setPieceVideo(piece, blob, seconds) {
    dropVideo(piece);
    var m = meta(piece), id = nextId++;
    m.video = { id: id, blob: blob, url: URL.createObjectURL(blob), seconds: seconds || 0 };
    idbDo('blobs', 'readwrite', function (st) { return st.put(blob, id); }).then(saveLayout);
    renderShoot();
    toast('Vidéo enregistrée.');
  }
  function stopRecording() {
    if (recorder && recorder.state !== 'inactive') recorder.stop();
  }
  $('sb-rec').addEventListener('click', function () {
    var piece = pieces[pieces.length - 1], btn = this;
    if (recorder && recorder.state === 'recording') { stopRecording(); return; }
    var mime = recMime();
    if (!stream || !mime) { $('sb-video-file').click(); return; }
    recChunks = [];
    try { recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 2500000 }); } catch (e) { $('sb-video-file').click(); return; }
    recorder.ondataavailable = function (e) { if (e.data && e.data.size) recChunks.push(e.data); };
    recorder.onstop = function () {
      clearInterval(recTimer); btn.classList.remove('is-rec'); btn.querySelector('span').textContent = 'Vidéo';
      var secs = Math.round((Date.now() - recStart) / 1000);
      var blob = new Blob(recChunks, { type: mime.split(';')[0] });
      recorder = null;
      if (blob.size && secs >= 1) setPieceVideo(piece, blob, secs);
    };
    recorder.start(1000); recStart = Date.now();
    btn.classList.add('is-rec');
    recTimer = setInterval(function () {
      var secs = Math.round((Date.now() - recStart) / 1000);
      btn.querySelector('span').textContent = '■ ' + secs + ' s';
      if (secs >= REC_MAX) stopRecording();
    }, 500);
  });
  $('sb-video-file').addEventListener('change', function () {
    var f = this.files && this.files[0]; this.value = '';
    if (!f) return;
    if (f.size > 200 * 1048576) { toast('Vidéo trop lourde (200 Mo au plus) : filmez plus court.'); return; }
    setPieceVideo(pieces[pieces.length - 1], f, 0);
  });

  $('sb-start').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    startCamera().then(function () { showCameraUI(true); })
      .catch(function () {
        // Pas d'accès à la caméra (refusé, page non sécurisée) : on retombe sur l'appareil photo du téléphone.
        toast('Caméra indisponible : utilisez la galerie ou l\'appareil photo du téléphone.');
        $('sb-gallery').setAttribute('capture', 'environment');
        showCameraUI(true);
        $('sb-camera').classList.add('no-live');
      })
      .then(function () { btn.disabled = false; });
  });

  $('sb-shutter').addEventListener('click', function () {
    if (!stream || !video.videoWidth) { $('sb-gallery').click(); return; }
    var w = video.videoWidth, h = video.videoHeight, scale = Math.min(1, 1600 / Math.max(w, h));
    var canvas = document.createElement('canvas');
    canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    var flash = $('sb-flash'); flash.classList.remove('is-on'); void flash.offsetWidth; flash.classList.add('is-on');
    if (navigator.vibrate) navigator.vibrate(12);
    canvas.toBlob(function (blob) { if (blob) addShot(blob); }, 'image/jpeg', 0.85);
  });

  $('sb-gallery').addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files).filter(function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|webp|heic)$/i.test(f.name); });
    this.value = '';
    if (!files.length) return;
    if ($('sb-camera').hidden) showCameraUI(true);
    // Les photos sont ajoutées dans l'ordre ; le découpage en pièces se fait à la relecture.
    files.reduce(function (p, f) {
      return p.then(function () { return shrink(f).then(function (blob) { var last = pieces[pieces.length - 1]; if (last.length >= pieceMax(last)) { var np = []; meta(np).kind = meta(last).kind === 'objet' ? 'objet' : meta(last).kind; pieces.push(np); } addShot(blob); }); });
    }, Promise.resolve());
  });

  $('sb-next').addEventListener('click', nextPiece);
  $('sb-done').addEventListener('click', function () { tidy(); renderReview(); showScreen('sb-review'); });

  // ── Lot : relecture avant envoi ───────────────────────────────────────────
  var SVG_STAR = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>';
  var SVG_SPLIT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 3v18M5 8l-2 4 2 4M19 8l2 4-2 4"/></svg>';
  var SVG_PICK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><circle cx="12" cy="12" r="2.5"/></svg>';
  var SVG_X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

  function iconBtn(svg, label, onClick, pressed) {
    var b = el('button', 'sb-ib'); b.type = 'button'; b.innerHTML = svg; b.setAttribute('aria-label', label);
    if (pressed != null) b.setAttribute('aria-pressed', String(pressed));
    b.addEventListener('click', onClick);
    return b;
  }

  function renderReview() {
    var list = $('sb-pieces'); list.textContent = '';
    var count = validCount();
    $('sb-summary').textContent = plural(count, 'pièce', 'pièces') + ' · ' + plural(total(), 'photo', 'photos') + '. La première photo de chaque pièce est la principale (détourée et mise en situation). Corrigez si besoin.';
    $('sb-generate').disabled = $('sb-send-only').disabled = !count;
    // Coût estimé de la génération complète du lot (détourage, 2 visuels, fiche par pièce), tarifs de la page Consommation IA.
    var perPiece = parseFloat($('sb-cost').dataset.pieceEur) || 0;
    $('sb-cost').textContent = count && perPiece ? 'Génération estimée à ' + (count * perPiece < 0.005 ? '< 0,01' : (count * perPiece).toFixed(2).replace('.', ',')) + ' € pour ' + plural(count, 'pièce', 'pièces') + ' (' + perPiece.toFixed(2).replace('.', ',') + ' € la pièce : détourage, deux visuels, fiche).' : '';
    pieces.forEach(function (piece, pi) {
      if (!pieceValid(piece)) return;
      var pm = meta(piece);
      var card = el('section', 'sb-piece');
      var head = el('div', 'sb-piece-head');
      head.appendChild(el('b', '', 'Pièce ' + (pi + 1) + ' · ' + { objet: 'objet', vetement: 'vêtement', media: 'média' }[pm.kind] + ' · ' + plural(piece.length, 'photo', 'photos') + (pm.video ? ' · 🎬 vidéo' : '') + (pm.size ? ' · 📏 ' + pm.size : '')));
      var tools = el('span', 'sb-piece-tools');
      if (pi > 0 && pieces[pi - 1].length) {
        var merge = el('button', 'sb-link', 'Fusionner avec la précédente'); merge.type = 'button';
        merge.addEventListener('click', function () { var prev = pieces[pi - 1], merged = prev.concat(piece); merged.meta = meta(prev); pieces[pi - 1] = merged; if (!meta(prev).video) meta(prev).video = pm.video; else dropVideo(piece); pieces.splice(pi, 1); saveLayout(); renderReview(); });
        tools.appendChild(merge);
      }
      var del = el('button', 'sb-link sb-danger', 'Retirer la pièce'); del.type = 'button';
      del.addEventListener('click', function () {
        if (!confirm('Retirer cette pièce et ses ' + plural(piece.length, 'photo', 'photos') + ' ?')) return;
        piece.forEach(dropShot); dropVideo(piece); pieces.splice(pi, 1); tidy(); saveLayout(); renderReview();
      });
      tools.appendChild(del);
      head.appendChild(tools);
      card.appendChild(head);

      if (pm.scan) card.appendChild(el('p', 'qa-note sb-scanline', '📚 ' + (pm.scan.name ? pm.scan.name + ' · ' : '') + 'code ' + pm.scan.code + (pm.scan.cover ? ' · la pochette servira de photo si besoin' : '')));
      var row = el('div', 'sb-photos');
      piece.forEach(function (shot, i) {
        var cell = el('div', 'sb-photo' + (i === mainIndexOf(piece) ? ' is-main' : '') + (shot.detail ? ' is-detail' : ''));
        var img = el('img'); img.src = shot.url; img.alt = 'Pièce ' + (pi + 1) + ', photo ' + (i + 1);
        cell.appendChild(img);
        var bar = el('div', 'sb-photo-bar');
        bar.appendChild(iconBtn(SVG_STAR, 'Photo principale', function () { piece.splice(i, 1); shot.detail = false; piece.unshift(shot); saveLayout(); renderReview(); }, i === mainIndexOf(piece)));
        if (i > 0) bar.appendChild(iconBtn(SVG_SPLIT, 'Nouvelle pièce à partir d\'ici', function () { var tail = piece.splice(i); tail.meta = { kind: pm.kind === 'media' ? 'objet' : pm.kind, scan: null, video: null }; pieces.splice(pi + 1, 0, tail); saveLayout(); renderReview(); }));
        // Un ensemble d'objets sur la photo : l'IA les repère, on touche celui à vendre.
        bar.appendChild(iconBtn(SVG_PICK, 'Isoler un objet dans la photo', function () {
          if (!window.studioPickObject) return;
          window.studioPickObject(shot.blob).then(function (b) {
            if (!b) return;
            URL.revokeObjectURL(shot.url); shot.blob = b; shot.url = URL.createObjectURL(b);
            idbDo('blobs', 'readwrite', function (st) { return st.put(b, shot.id); }); renderReview();
          });
        }));
        bar.appendChild(iconBtn(SVG_X, 'Retirer cette photo', function () { piece.splice(i, 1); dropShot(shot); tidy(); saveLayout(); renderReview(); }));
        cell.appendChild(bar);
        row.appendChild(cell);
      });
      card.appendChild(row);
      list.appendChild(card);
    });
    if (!count) list.appendChild(el('p', 'qa-note', 'Plus aucune photo. Reprenez le shooting.'));
  }
  $('sb-back').addEventListener('click', function () {
    tidy();
    if (pieces[pieces.length - 1].length) pieces.push([]);
    showScreen('sb-shoot'); showCameraUI(true); resumeCameraIfShooting();
  });

  // ── Traitement d'une pièce (toutes les étapes IA) ─────────────────────────
  var STEP_LABEL = { detoure: 'Détourage', ambiance: 'Mise en situation', sheet: 'Rédaction de la fiche', angles: 'Détourage des autres angles', recto: 'Recto', verso: 'Verso' };
  function processPiece(ref, onStep) {
    var failed = [];
    // La fiche avant la mise en situation (la nature de la pièce décide du décor) ; avec 2 photos ou plus, détourage des
    // autres angles (un par appel) puis recto et verso générés à partir de tous les détourages.
    var chain = ['detoure', 'sheet', 'ambiance', 'angles', 'recto', 'verso'];
    return chain.reduce(function (p, step) {
      return p.then(function () {
        onStep(STEP_LABEL[step] + '…');
        var skipIds = [];
        function run() {
          var data = { action: step, ref: ref };
          if (step === 'angles' && skipIds.length) data.skip = skipIds.join(',');
          return api(data).then(function (res) {
            must(res);
            if (!res.ok) {
              // Un angle impossible à détourer : on passe aux suivants (le recto / verso utilisera sa photo telle quelle).
              if (step === 'angles' && res.photo_id) {
                skipIds.push(res.photo_id);
                if (res.remaining > 0) return run();
              }
              failed.push(step);
              return;
            }
            if (step === 'angles' && res.remaining > 0) return run();
          });
        }
        return run();
      });
    }, Promise.resolve()).then(function () {
      return api({ action: 'finish', ref: ref, failed: failed.join(',') }).then(function (res) { must(res); return failed; });
    });
  }
  function failedText(failed) {
    return failed.length ? ' (échec : ' + failed.map(function (s) { return STEP_LABEL[s].toLowerCase(); }).join(', ') + ')' : '';
  }

  // ── Lot : envoi et traitement ─────────────────────────────────────────────
  var jobs = [];       // {piece, row, ref, state: 'wait'|'run'|'ok'|'later'|'err'}
  var stopRequested = false;
  var lotNotes = '';

  function setRow(job, state, text) {
    job.state = state;
    job.row.dataset.state = state === 'ok' || state === 'later' ? 'ok' : state === 'run' ? 'run' : state === 'err' ? 'err' : '';
    if (text) job.row.querySelector('small').textContent = text;
  }

  function startRun(doProcess) {
    tidy();
    var todo = pieces.filter(pieceValid);
    if (!todo.length) return;
    lotNotes = $('sb-notes').value.trim();
    jobs = [];
    var list = $('sb-runlist'); list.textContent = '';
    todo.forEach(function (piece, i) {
      var li = el('li'); li.innerHTML = '<span class="dot"></span><b></b><small>En attente</small>';
      li.querySelector('b').textContent = 'Pièce ' + (i + 1) + ' · ' + plural(piece.length, 'photo', 'photos') + (meta(piece).video ? ' + vidéo' : '');
      list.appendChild(li);
      jobs.push({ piece: piece, row: li, ref: null, state: 'wait' });
    });
    $('sb-run-title').textContent = doProcess ? 'Traitement du lot' : 'Envoi du lot';
    $('sb-run-note').textContent = doProcess ? 'Comptez environ une minute par pièce. Gardez l\'écran allumé et cette page ouverte.' : 'Envoi des photos…';
    ['sb-retry', 'sb-finish', 'sb-again'].forEach(function (id) { $(id).hidden = true; });
    $('sb-stop').hidden = false; $('sb-stop').disabled = false;
    showScreen('sb-run');
    runJobs(doProcess);
  }

  function uploadPiece(job) {
    var size = job.piece.reduce(function (n, s) { return n + s.blob.size; }, 0);
    if (size > MAX_REQUEST_BYTES) return Promise.resolve({ ok: false, error: 'Photos trop lourdes (' + (size / 1048576).toFixed(1).replace('.', ',') + ' Mo) : retirez-en une.' });
    var fd = new FormData();
    var m = meta(job.piece), labels = shotLabels(job.piece);
    fd.append('action', 'create'); fd.append('source', 'batch'); fd.append('notes', lotNotes); fd.append('main', String(mainIndexOf(job.piece))); fd.append('kind', m.kind);
    if (m.scan && m.scan.code) fd.append('barcode', m.scan.code);
    if (m.scan && m.scan.cover) fd.append('cover_url', m.scan.cover);
    if (m.size) fd.append('size_text', m.size);
    job.piece.forEach(function (s, i) { fd.append('photos[]', s.blob, 'photo-' + i + '.jpg'); fd.append('labels[]', labels[i]); });
    return api(fd);
  }

  /** Envoie la vidéo d'une pièce par morceaux (chunk octets) ; résout en {ok, error?}. */
  function uploadVideo(ref, file, chunk) {
    var id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(8)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    var total = Math.max(1, Math.ceil(file.size / chunk));
    var name = file.name && /\.(mp4|mov|webm|m4v)$/i.test(file.name) ? file.name : (/webm/.test(file.type) ? 'video.webm' : (/quicktime/.test(file.type) ? 'video.mov' : 'video.mp4'));
    function send(i, attempt) {
      var fd = new FormData();
      fd.append('action', 'video'); fd.append('ref', ref); fd.append('upload_id', id); fd.append('index', i); fd.append('total', total); fd.append('size', file.size); fd.append('name', name);
      fd.append('file', file.slice(i * chunk, Math.min(file.size, (i + 1) * chunk)), 'part');
      return api(fd).then(function (r) { return r.ok || attempt >= 1 || r.status === 401 || r.status === 415 ? r : send(i, attempt + 1); });
    }
    return (function loop(i) { return i >= total ? Promise.resolve({ ok: true }) : send(i, 0).then(function (r) { return r.ok ? loop(i + 1) : r; }); })(0);
  }

  function runJobs(doProcess) {
    running = true; stopRequested = false;
    keepAwake(true);
    var done = 0;
    var meter = $('sb-meter');
    function tick() { meter.style.width = Math.round(done / jobs.length * 100) + '%'; }
    tick();
    var fatal = null;

    var chain = jobs.reduce(function (p, job) {
      return p.then(function () {
        if (job.state === 'ok' || job.state === 'later') { done++; tick(); return; }
        if (stopRequested || fatal) { setRow(job, 'wait', fatal ? 'Non envoyée' : 'Arrêtée : à reprendre'); return; }
        setRow(job, 'run', 'Envoi des photos…');
        return Promise.resolve(job.ref ? { ok: true, ref: job.ref } : uploadPiece(job)).then(function (res) {
          must(res);
          if (!res.ok) { setRow(job, 'err', res.error || 'Envoi impossible'); return; }
          job.ref = res.ref; job.chunk = res.chunk || job.chunk;
          // Les photos sont enregistrées sur le serveur : on les retire du brouillon du téléphone (la vidéo, après son envoi).
          job.piece.forEach(dropShot);
          var pm = meta(job.piece), vid = pm.video;
          var videoDone = vid ? (setRow(job, 'run', 'Envoi de la vidéo…'), uploadVideo(job.ref, vid.blob, job.chunk || 4 * 1048576)) : Promise.resolve({ ok: true });
          return videoDone.then(function (v) {
            var vnote = vid && !v.ok ? ' (vidéo non envoyée : ' + (v.error || 'réessayez') + ')' : '';
            if (vid) dropVideo(job.piece);
            pieces = pieces.filter(function (p) { return p !== job.piece; });
            if (!pieces.length) pieces = [[]];
            saveLayout();
            if (!doProcess) { setRow(job, 'later', 'Envoyée, à traiter depuis « Mes pièces » · Réf. N°' + job.ref + vnote); return; }
            return processPiece(job.ref, function (t) { setRow(job, 'run', t); }).then(function (failed) {
            setRow(job, 'ok', 'Fiche prête · Réf. N°' + job.ref + failedText(failed) + vnote);
            });
          });
        }).catch(function (err) {
          fatal = err.message === 'auth' ? 'auth' : 'network';
          if (job.ref) setRow(job, 'later', 'Interrompue : à reprendre depuis « Mes pièces » · Réf. N°' + job.ref);
          else setRow(job, 'err', fatal === 'auth' ? 'Session expirée' : 'Connexion perdue');
        }).then(function () { done++; tick(); });
      });
    }, Promise.resolve());

    chain.then(function () {
      running = false; keepAwake(false);
      $('sb-stop').hidden = true;
      var errors = jobs.filter(function (j) { return j.state === 'err' || j.state === 'wait'; });
      var okCount = jobs.filter(function (j) { return j.state === 'ok' || j.state === 'later'; }).length;
      var note = plural(okCount, 'pièce enregistrée', 'pièces enregistrées') + '.';
      if (fatal === 'auth') note += ' Votre session a expiré : rechargez la page et reconnectez-vous ; les photos restantes sont conservées sur ce téléphone.';
      else if (fatal) note += ' Connexion perdue : les photos restantes sont conservées sur ce téléphone, réessayez avec du réseau.';
      else if (errors.length) note += ' ' + plural(errors.length, 'pièce n\'a pas pu être envoyée', 'pièces n\'ont pas pu être envoyées') + ' : réessayez.';
      else if (doProcess) note += ' Relisez-les dans « Mes pièces » avant de les publier.';
      $('sb-run-note').textContent = note;
      $('sb-retry').hidden = !errors.length || fatal === 'auth';
      $('sb-finish').hidden = !okCount;
      $('sb-again').hidden = !!errors.length;
      $('sb-retry').onclick = function () { jobs.filter(function (j) { return j.state !== 'ok' && j.state !== 'later'; }).forEach(function (j) { setRow(j, 'wait', 'En attente'); }); $('sb-retry').hidden = $('sb-finish').hidden = $('sb-again').hidden = true; $('sb-stop').hidden = false; runJobs(doProcess); };
      if (okCount) { refreshCount(); runVariants(); }
    });
  }

  $('sb-generate').addEventListener('click', function () { startRun(true); });
  $('sb-send-only').addEventListener('click', function () { startRun(false); });
  $('sb-stop').addEventListener('click', function () { stopRequested = true; this.disabled = true; this.textContent = 'Arrêt après la pièce en cours…'; });
  $('sb-finish').addEventListener('click', function () { go('pieces'); });
  $('sb-again').addEventListener('click', function () {
    $('sb-stop').textContent = 'Arrêter après la pièce en cours';
    $('sb-notes').value = '';
    showScreen('sb-shoot'); showCameraUI(true); resumeCameraIfShooting();
  });

  // ── Mes pièces ────────────────────────────────────────────────────────────
  var jobList = [];
  var STATUS = { queued: 'À traiter', ready: 'À relire', reviewed: '' };

  function refreshCount() {
    return api({ action: 'jobs' }).then(function (res) {
      if (!res.ok) return res;
      jobList = res.jobs;
      var n = jobList.filter(function (j) { return j.status !== 'reviewed'; }).length;
      $('st-count').hidden = !n; $('st-count').textContent = n;
      updateQueueBanner();
      return res;
    });
  }

  function loadList() {
    var groups = $('sl-groups');
    if (!groups.children.length) groups.appendChild(el('p', 'qa-note', 'Chargement…'));
    refreshCount().then(function (res) {
      if (!res.ok) { groups.textContent = ''; groups.appendChild(el('p', 'qa-warn', res.status === 401 ? 'Session expirée : rechargez la page et reconnectez-vous.' : (res.error || 'Chargement impossible.'))); return; }
      renderList();
    });
  }

  function warnText(note) {
    if (!note) return '';
    return 'échec : ' + note.split(',').filter(Boolean).map(function (s) { return STEP_LABEL[s] ? STEP_LABEL[s].toLowerCase() : s; }).join(', ');
  }

  function renderList() {
    var groups = $('sl-groups'); groups.textContent = '';
    var queued = jobList.filter(function (j) { return j.status === 'queued'; });
    $('sl-bulk').hidden = !queued.length;
    $('sl-bulk-note').textContent = queued.length ? plural(queued.length, 'pièce attend', 'pièces attendent') + ' son traitement (détourage, mise en situation, fiche).' : '';
    $('sl-process-all').textContent = 'Traiter les ' + queued.length + ' pièce' + (queued.length > 1 ? 's' : '') + ' en attente';
    if (!jobList.length) {
      var empty = el('div', 'sl-empty');
      empty.appendChild(el('b', '', 'Aucune pièce pour l\'instant'));
      empty.appendChild(el('p', 'qa-note', 'Les pièces photographiées avec le Studio apparaissent ici, à relire puis à publier.'));
      var b = el('button', 'btn btn-primary', 'Photographier une pièce'); b.type = 'button'; b.addEventListener('click', function () { go('single'); });
      empty.appendChild(b);
      groups.appendChild(empty);
      return;
    }
    [['queued', 'À traiter'], ['ready', 'À relire'], ['reviewed', 'Enregistrées']].forEach(function (g) {
      var items = jobList.filter(function (j) { return j.status === g[0]; });
      if (!items.length) return;
      var sec = el('section', 'sl-group');
      sec.appendChild(el('h2', '', g[1] + ' (' + items.length + ')'));
      var ul = el('ul', 'sl-cards');
      items.forEach(function (j) {
        var li = el('li', 'sl-card'); li.dataset.ref = j.ref;
        var open = el('button', 'sl-open'); open.type = 'button';
        var th = el('span', 'sl-thumb');
        if (j.thumb) { var img = el('img'); img.src = '/' + j.thumb; img.alt = ''; img.loading = 'lazy'; th.appendChild(img); }
        open.appendChild(th);
        var txt = el('span', 'sl-text');
        txt.appendChild(el('b', '', j.status === 'queued' ? 'Pièce Réf. N°' + j.ref : j.name));
        var meta = j.status === 'queued' ? 'Photos reçues, pas encore traitée' : [j.price, j.status === 'reviewed' ? (j.hidden ? 'masquée' : 'en ligne') : 'masquée'].filter(Boolean).join(' · ');
        txt.appendChild(el('small', 'sl-meta', meta));
        var w = warnText(j.note);
        if (w) txt.appendChild(el('small', 'sl-warn', w));
        open.appendChild(txt);
        open.addEventListener('click', function () { if (j.status === 'queued') processOne(j); else openSheet(j.ref); });
        li.appendChild(open);
        if (j.status === 'queued') {
          var run = el('button', 'btn btn-ghost sl-run', 'Traiter'); run.type = 'button';
          run.addEventListener('click', function () { processOne(j); });
          li.appendChild(run);
        }
        ul.appendChild(li);
      });
      sec.appendChild(ul);
      groups.appendChild(sec);
    });
  }

  function cardOf(ref) { return document.querySelector('.sl-card[data-ref="' + ref + '"]'); }
  function cardStatus(ref, text, busy) {
    var card = cardOf(ref); if (!card) return;
    card.classList.toggle('is-busy', !!busy);
    var meta = card.querySelector('.sl-meta'); if (meta) meta.textContent = text;
    var run = card.querySelector('.sl-run'); if (run) run.disabled = !!busy;
  }

  var listBusy = false;
  function processOne(j) {
    if (listBusy) { toast('Un traitement est déjà en cours.'); return Promise.resolve(); }
    listBusy = true; keepAwake(true);
    return processPiece(j.ref, function (t) { cardStatus(j.ref, t, true); })
      .then(function () { toast('Fiche prête : à relire.'); })
      .catch(function (err) { toast(err.message === 'auth' ? 'Session expirée : rechargez la page.' : 'Connexion perdue : réessayez.'); })
      .then(function () { listBusy = false; keepAwake(false); loadList(); runVariants(); });
  }

  /** Traite les pièces en attente, une à une ; $btn : le bouton qui a lancé le traitement (progression affichée dessus). */
  function processQueued($btn) {
    var queued = jobList.filter(function (j) { return j.status === 'queued'; });
    if (!queued.length || listBusy) return;
    var label = $btn.textContent;
    $btn.disabled = true;
    listBusy = true; keepAwake(true);
    var stop = false;
    queued.reduce(function (p, j, i) {
      return p.then(function () {
        if (stop) return;
        $btn.textContent = 'Traitement ' + (i + 1) + ' / ' + queued.length + '…';
        return processPiece(j.ref, function (t) { cardStatus(j.ref, t, true); $btn.textContent = 'Pièce ' + (i + 1) + ' / ' + queued.length + ' : ' + t; }).catch(function (err) {
          stop = true; toast(err.message === 'auth' ? 'Session expirée : rechargez la page.' : 'Connexion perdue : réessayez.');
        });
      });
    }, Promise.resolve()).then(function () { listBusy = false; $btn.disabled = false; $btn.textContent = label; keepAwake(false); loadList(); runVariants(); });
  }
  $('sl-process-all').addEventListener('click', function () { processQueued(this); });
  // Mode « Shooting d'abord » : rappel, dans l'onglet « Une pièce », des pièces qui attendent leur traitement par lot.
  function updateQueueBanner() {
    var later = false;
    try { later = localStorage.getItem('studio-mode') === 'later'; } catch (e) {}
    var n = jobList.filter(function (j) { return j.status === 'queued'; }).length;
    $('st-queue').hidden = !(later && n);
    $('st-queue-text').textContent = plural(n, 'pièce attend', 'pièces attendent') + ' son traitement (détourage, mise en situation, recto / verso, fiche). Terminez le shooting, puis lancez-le d\'un coup.';
    $('st-queue-run').textContent = 'Traiter les ' + n + ' pièce' + (n > 1 ? 's' : '') + ' maintenant';
  }
  $('st-queue-run').addEventListener('click', function () { processQueued(this); });
  document.addEventListener('studio-mode', updateQueueBanner);
  $('sl-refresh').addEventListener('click', loadList);

  // ── Relecture d'une pièce ─────────────────────────────────────────────────
  var sheetRef = null, sheetPhotos = [];
  var dlg = $('sr');
  function openSheet(ref) {
    sheetRef = ref;
    $('sr-error').hidden = true;
    api({ action: 'get', ref: ref }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Pièce introuvable.'); return; }
      var p = res.product;
      $('sr-ref').textContent = 'Réf. N°' + p.ref;
      $('sr-body').dataset.aiRef = p.ref; // boutons « ↻ IA » (assets/product-ai.js)
      $('sr-body').querySelector('[data-ai-status]').textContent = '';
      sheetPhotos = res.photos;
      var box = $('sr-photos'); box.textContent = '';
      res.photos.forEach(function (ph) {
        var fig = el('figure'); var img = el('img'); img.src = '/' + ph.path; img.alt = ph.label; img.loading = 'lazy';
        fig.appendChild(img); fig.appendChild(el('figcaption', '', ph.label)); box.appendChild(fig);
      });
      var w = warnText(res.note);
      $('sr-warn').hidden = !w;
      if (w) $('sr-warn').textContent = 'Génération incomplète (' + w + '). Complétez la fiche à la main ou relancez depuis le catalogue.';
      $('sr-name').value = p.name; $('sr-price').value = p.price; $('sr-weight').value = p.weight_grams || '';
      $('sr-cat').value = p.cat; $('sr-desc').value = p.description; $('sr-materials').value = p.materials || ''; $('sr-colors').value = p.colors || ''; $('sr-brand').value = p.brand || ''; $('sr-distributor').value = p.distributor || ''; $('sr-etat').value = p.etat || ''; $('sr-size').value = p.size_text || ''; $('sr-nature').value = p.nature || ''; $('sr-nature').dispatchEvent(new Event('change')); $('sr-sous').value = p.sous_categorie || ''; $('sr-badge').value = p.badge;
      $('sr-publish').checked = !p.hidden;
      $('sr-discard').hidden = res.status === 'reviewed';
      $('sr-save').disabled = false; $('sr-save').textContent = 'Enregistrer';
      if (!dlg.open) dlg.showModal();
      dlg.querySelector('.sr-body').scrollTop = 0;
    });
  }
  $('sr-measure').addEventListener('click', function () {
    if (!window.studioMeasure) return;
    var qa = document.querySelector('.qa');
    window.studioMeasure({ images: sheetPhotos.map(function (ph) { return { url: '/' + ph.path, label: ph.label }; }), ai: !!(qa && qa.dataset.ai === '1') }).then(function (r) {
      if (!r) return;
      var f = $('sr-size'); f.value = r.text; f.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
  $('sr-save').addEventListener('click', function () {
    var btn = this; btn.disabled = true; btn.textContent = 'Enregistrement…';
    var fd = new FormData();
    fd.append('action', 'save'); fd.append('ref', sheetRef);
    fd.append('name', $('sr-name').value); fd.append('price', $('sr-price').value);
    fd.append('weight_grams', $('sr-weight').value || '0'); fd.append('cat', $('sr-cat').value);
    fd.append('description', $('sr-desc').value); fd.append('materials', $('sr-materials').value); fd.append('colors', $('sr-colors').value); fd.append('brand', $('sr-brand').value); fd.append('distributor', $('sr-distributor').value); fd.append('etat', $('sr-etat').value); fd.append('size_text', $('sr-size').value); fd.append('nature', $('sr-nature').value); fd.append('sous_categorie', $('sr-sous').value); fd.append('badge', $('sr-badge').value);
    if ($('sr-publish').checked) fd.append('publish', '1');
    api(fd).then(function (res) {
      btn.disabled = false; btn.textContent = 'Enregistrer';
      if (!res.ok) { var e = $('sr-error'); e.hidden = false; e.textContent = res.network ? 'Connexion perdue : réessayez.' : (res.error || 'Enregistrement impossible.'); return; }
      dlg.close(); toast(res.published ? 'Pièce publiée.' : 'Fiche enregistrée (masquée).'); loadList();
    });
  });
  $('sr-discard').addEventListener('click', function () {
    if (!confirm('Jeter cette pièce ? Ses photos restent dans la médiathèque.')) return;
    api({ action: 'discard', ref: sheetRef }).then(function (res) {
      if (!res.ok) { var e = $('sr-error'); e.hidden = false; e.textContent = res.error || 'Impossible de jeter cette pièce.'; return; }
      dlg.close(); toast('Pièce jetée.'); loadList();
    });
  });

  // ── Versions smartphone (9:16) ────────────────────────────────────────────
  // Chaque mise en situation 3:2 a sa version verticale, refaite par l'IA dans une requête à part
  // (admin/mobile-variants.php, comme l'administration le fait en arrière-plan). Jamais pendant un
  // traitement de pièces, pour ne pas enchaîner deux générations en même temps.
  var variantsRunning = false;
  function variantsPost(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(VARIANTS, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false }; });
  }
  function runVariants() {
    if (variantsRunning || running || listBusy || !VARIANTS) return;
    variantsRunning = true;
    var box = $('st-variants');
    variantsPost({ action: 'list' }).then(function (r) {
      var ids = (r && r.ok && r.ids) || [];
      if (!ids.length) { variantsRunning = false; return; }
      var failed = 0;
      (function next(i) {
        if (i >= ids.length) {
          variantsRunning = false;
          box.textContent = failed ? 'Versions smartphone : ' + plural(failed, 'échec (nouvel essai plus tard)', 'échecs (nouvel essai plus tard)') + '.' : 'Versions smartphone prêtes.';
          setTimeout(function () { box.hidden = true; }, 5000);
          return;
        }
        box.hidden = false; box.textContent = 'Versions smartphone (9:16) : ' + (i + 1) + ' / ' + ids.length + '…';
        if (running || listBusy) { variantsRunning = false; box.hidden = true; return; }
        variantsPost({ action: 'run', id: ids[i] }).then(function (res) {
          if (!res || !res.ok) failed++;
          if (res && res.next) ids.push(ids[i]);
          next(i + 1);
        });
      })(0);
    });
  }
  window.studioRunVariants = runVariants;

  // ── Installation sur l'écran d'accueil ────────────────────────────────────
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  var deferredInstall = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferredInstall = e;
    if (!standalone) $('st-install').hidden = false;
  });
  $('st-install').addEventListener('click', function () {
    if (!deferredInstall) return;
    deferredInstall.prompt(); deferredInstall = null; this.hidden = true;
  });
  try {
    if (/iphone|ipad|ipod/i.test(navigator.userAgent) && !standalone && !localStorage.getItem('studio-ios-hint')) $('st-ios').hidden = false;
  } catch (e) {}
  $('st-ios-close').addEventListener('click', function () { $('st-ios').hidden = true; try { localStorage.setItem('studio-ios-hint', '1'); } catch (e) {} });
  if ('serviceWorker' in navigator && script.dataset.sw) navigator.serviceWorker.register(script.dataset.sw).catch(function () {});

  // ── Démarrage ─────────────────────────────────────────────────────────────
  try { if (sessionStorage.getItem('studio-queued')) { sessionStorage.removeItem('studio-queued'); toast('Pièce enregistrée : à traiter plus tard. Photographiez la suivante.'); } } catch (e) {}
  go(location.hash.replace('#', '') || 'single');
  refreshCount();
  runVariants();
  renderShoot();

  // Lot interrompu (page fermée, téléphone rebooté) : on le retrouve.
  idbOpen().then(function (database) {
    idb = database;
    return idbDo('meta', 'readonly', function (st) { return st.get('layout'); });
  }).then(function (layout) {
    if (!layout || !layout.length) return;
    // Ancien format : un tableau d'identifiants par pièce ; nouveau : {ids, kind, scan, video}.
    layout = layout.map(function (p) { return Array.isArray(p) ? { ids: p, kind: 'objet', scan: null, video: null } : p; });
    var ids = [].concat.apply([], layout.map(function (p) { return p.ids.concat(p.video ? [p.video] : []); }));
    if (!ids.length) { layout = layout.filter(function (p) { return p.scan && p.scan.cover; }); if (!layout.length) return; }
    return Promise.all(ids.map(function (id) { return idbDo('blobs', 'readonly', function (st) { return st.get(id); }); })).then(function (blobs) {
      var byId = {};
      ids.forEach(function (id, i) { if (blobs[i]) byId[id] = { id: id, blob: blobs[i], url: URL.createObjectURL(blobs[i]) }; });
      var restored = layout.map(function (p) {
        var arr = p.ids.map(function (id) { return byId[id]; }).filter(Boolean);
        arr.forEach(function (x) { x.detail = (p.details || []).indexOf(x.id) >= 0; x.measure = (p.measures || []).indexOf(x.id) >= 0; });
        var v = p.video && byId[p.video] ? { id: p.video, blob: byId[p.video].blob, url: byId[p.video].url, seconds: 0 } : null;
        arr.meta = { kind: KIND_SHOTS[p.kind] ? p.kind : 'objet', scan: p.scan || null, video: v, detail: false, size: p.size || '' };
        return arr;
      }).filter(pieceValid);
      if (!restored.length) return;
      pieces = restored.concat([[]]);
      nextId = Math.max.apply(null, ids.concat([0])) + 1;
      var n = restored.reduce(function (c, p) { return c + p.length; }, 0);
      var note = $('sb-resume'); note.hidden = false;
      note.textContent = 'Shooting en cours retrouvé : ' + plural(restored.length, 'pièce', 'pièces') + ', ' + plural(n, 'photo', 'photos') + '. Ouvrez l\'appareil photo pour continuer, ou terminez-le.';
      var done = el('button', 'btn btn-ghost', 'Terminer et envoyer ce lot'); done.type = 'button';
      done.addEventListener('click', function () { tidy(); renderReview(); showScreen('sb-review'); });
      var drop = el('button', 'sb-link sb-danger', 'Abandonner ce lot'); drop.type = 'button';
      drop.addEventListener('click', function () { if (confirm('Supprimer ces photos du téléphone ?')) { clearDraft(); note.hidden = true; done.remove(); drop.remove(); renderShoot(); } });
      note.parentNode.insertBefore(done, note.nextSibling); note.parentNode.insertBefore(drop, done.nextSibling);
      renderShoot();
    });
  });
})();
