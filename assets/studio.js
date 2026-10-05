// Application smartphone Studio (studio.php) : onglets, shooting en lot avec viseur,
// file de traitement des pièces et relecture. Le parcours « Une pièce » est dans quick-add.js.
// Tous les appels passent par admin/quick-add-action.php (réponses JSON {ok, ...}).
(function () {
  'use strict';
  var script = document.currentScript;
  var ACTION = script.dataset.action;
  var VARIANTS = script.dataset.variants;
  var MAX_PER_PIECE = 8;          // photos par pièce (le serveur en accepte 10)
  var MAX_REQUEST_BYTES = 9 * 1048576; // OVH coupe les requêtes plus lourdes (~16 Mo mesurés)

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
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

  function total() { return pieces.reduce(function (n, p) { return n + p.length; }, 0); }
  function saveLayout() {
    return idbDo('meta', 'readwrite', function (s) { return s.put(pieces.map(function (p) { return p.map(function (x) { return x.id; }); }), 'layout'); });
  }
  function addShot(blob) {
    var piece = pieces[pieces.length - 1];
    if (piece.length >= MAX_PER_PIECE) { toast('Déjà ' + MAX_PER_PIECE + ' photos : passez à la pièce suivante.'); return false; }
    var shot = { id: nextId++, blob: blob, url: URL.createObjectURL(blob) };
    piece.push(shot);
    idbDo('blobs', 'readwrite', function (s) { return s.put(blob, shot.id); }).then(saveLayout);
    renderShoot();
    return true;
  }
  function dropShot(shot) {
    idbDo('blobs', 'readwrite', function (s) { return s.delete(shot.id); });
    URL.revokeObjectURL(shot.url);
  }
  function tidy() {
    // Plus de pièce vide, sauf la dernière (celle en cours de prise de vue).
    pieces = pieces.filter(function (p, i) { return p.length || i === pieces.length - 1; });
    if (!pieces.length) pieces = [[]];
  }
  function nextPiece() {
    if (!pieces[pieces.length - 1].length) return;
    pieces.push([]);
    saveLayout(); renderShoot();
    toast('Pièce ' + pieces.length + ' : prenez sa première photo.');
  }
  function clearDraft() {
    pieces.forEach(function (p) { p.forEach(function (s) { URL.revokeObjectURL(s.url); }); });
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
    var piece = pieces[pieces.length - 1];
    $('sb-piece-label').textContent = 'Pièce ' + pieces.length;
    $('sb-total-label').textContent = plural(piece.length, 'photo', 'photos') + ' · ' + plural(total(), 'au total', 'au total');
    $('sb-next').disabled = !piece.length;
    $('sb-done').disabled = !total();
    $('sb-done').textContent = total() ? 'Terminer (' + plural(pieces.filter(function (p) { return p.length; }).length, 'pièce', 'pièces') + ')' : 'Terminer le shooting';
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
      return p.then(function () { return shrink(f).then(function (blob) { if (pieces[pieces.length - 1].length >= MAX_PER_PIECE) pieces.push([]); addShot(blob); }); });
    }, Promise.resolve());
  });

  $('sb-next').addEventListener('click', nextPiece);
  $('sb-done').addEventListener('click', function () { tidy(); renderReview(); showScreen('sb-review'); });

  // ── Lot : relecture avant envoi ───────────────────────────────────────────
  var SVG_STAR = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>';
  var SVG_SPLIT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 3v18M5 8l-2 4 2 4M19 8l2 4-2 4"/></svg>';
  var SVG_X = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

  function iconBtn(svg, label, onClick, pressed) {
    var b = el('button', 'sb-ib'); b.type = 'button'; b.innerHTML = svg; b.setAttribute('aria-label', label);
    if (pressed != null) b.setAttribute('aria-pressed', String(pressed));
    b.addEventListener('click', onClick);
    return b;
  }

  function renderReview() {
    var list = $('sb-pieces'); list.textContent = '';
    var count = pieces.filter(function (p) { return p.length; }).length;
    $('sb-summary').textContent = plural(count, 'pièce', 'pièces') + ' · ' + plural(total(), 'photo', 'photos') + '. La première photo de chaque pièce est la principale (détourée et mise en situation). Corrigez si besoin.';
    $('sb-generate').disabled = $('sb-send-only').disabled = !count;
    pieces.forEach(function (piece, pi) {
      if (!piece.length) return;
      var card = el('section', 'sb-piece');
      var head = el('div', 'sb-piece-head');
      head.appendChild(el('b', '', 'Pièce ' + (pi + 1) + ' · ' + plural(piece.length, 'photo', 'photos')));
      var tools = el('span', 'sb-piece-tools');
      if (pi > 0 && pieces[pi - 1].length) {
        var merge = el('button', 'sb-link', 'Fusionner avec la précédente'); merge.type = 'button';
        merge.addEventListener('click', function () { pieces[pi - 1] = pieces[pi - 1].concat(piece); pieces.splice(pi, 1); saveLayout(); renderReview(); });
        tools.appendChild(merge);
      }
      var del = el('button', 'sb-link sb-danger', 'Retirer la pièce'); del.type = 'button';
      del.addEventListener('click', function () {
        if (!confirm('Retirer cette pièce et ses ' + plural(piece.length, 'photo', 'photos') + ' ?')) return;
        piece.forEach(dropShot); pieces.splice(pi, 1); tidy(); saveLayout(); renderReview();
      });
      tools.appendChild(del);
      head.appendChild(tools);
      card.appendChild(head);

      var row = el('div', 'sb-photos');
      piece.forEach(function (shot, i) {
        var cell = el('div', 'sb-photo' + (i === 0 ? ' is-main' : ''));
        var img = el('img'); img.src = shot.url; img.alt = 'Pièce ' + (pi + 1) + ', photo ' + (i + 1);
        cell.appendChild(img);
        var bar = el('div', 'sb-photo-bar');
        bar.appendChild(iconBtn(SVG_STAR, 'Photo principale', function () { piece.splice(i, 1); piece.unshift(shot); saveLayout(); renderReview(); }, i === 0));
        if (i > 0) bar.appendChild(iconBtn(SVG_SPLIT, 'Nouvelle pièce à partir d\'ici', function () { var tail = piece.splice(i); pieces.splice(pi + 1, 0, tail); saveLayout(); renderReview(); }));
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
  var STEP_LABEL = { detoure: 'Détourage', ambiance: 'Mise en situation', sheet: 'Rédaction de la fiche' };
  function processPiece(ref, onStep) {
    var failed = [];
    var chain = ['detoure', 'ambiance', 'sheet'];
    return chain.reduce(function (p, step) {
      return p.then(function () {
        onStep(STEP_LABEL[step] + '…');
        return api({ action: step, ref: ref }).then(function (res) {
          must(res);
          if (!res.ok) failed.push(step);
        });
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
    var todo = pieces.filter(function (p) { return p.length; });
    if (!todo.length) return;
    lotNotes = $('sb-notes').value.trim();
    jobs = [];
    var list = $('sb-runlist'); list.textContent = '';
    todo.forEach(function (piece, i) {
      var li = el('li'); li.innerHTML = '<span class="dot"></span><b></b><small>En attente</small>';
      li.querySelector('b').textContent = 'Pièce ' + (i + 1) + ' · ' + plural(piece.length, 'photo', 'photos');
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
    fd.append('action', 'create'); fd.append('source', 'batch'); fd.append('notes', lotNotes); fd.append('main', '0');
    job.piece.forEach(function (s, i) { fd.append('photos[]', s.blob, 'photo-' + i + '.jpg'); fd.append('labels[]', i === 0 ? 'Photo principale' : 'Vue ' + (i + 1)); });
    return api(fd);
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
          job.ref = res.ref;
          // Les photos sont enregistrées sur le serveur : on les retire du brouillon du téléphone.
          job.piece.forEach(dropShot);
          pieces = pieces.filter(function (p) { return p !== job.piece; });
          if (!pieces.length) pieces = [[]];
          saveLayout();
          if (!doProcess) { setRow(job, 'later', 'Envoyée, à traiter depuis « Mes pièces » · Réf. N°' + job.ref); return; }
          return processPiece(job.ref, function (t) { setRow(job, 'run', t); }).then(function (failed) {
            setRow(job, 'ok', 'Fiche prête · Réf. N°' + job.ref + failedText(failed));
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

  $('sl-process-all').addEventListener('click', function () {
    var queued = jobList.filter(function (j) { return j.status === 'queued'; });
    if (!queued.length || listBusy) return;
    var btn = this; btn.disabled = true;
    listBusy = true; keepAwake(true);
    var stop = false;
    queued.reduce(function (p, j, i) {
      return p.then(function () {
        if (stop) return;
        btn.textContent = 'Traitement ' + (i + 1) + ' / ' + queued.length + '…';
        return processPiece(j.ref, function (t) { cardStatus(j.ref, t, true); }).catch(function (err) {
          stop = true; toast(err.message === 'auth' ? 'Session expirée : rechargez la page.' : 'Connexion perdue : réessayez.');
        });
      });
    }, Promise.resolve()).then(function () { listBusy = false; btn.disabled = false; keepAwake(false); loadList(); runVariants(); });
  });
  $('sl-refresh').addEventListener('click', loadList);

  // ── Relecture d'une pièce ─────────────────────────────────────────────────
  var sheetRef = null;
  var dlg = $('sr');
  function openSheet(ref) {
    sheetRef = ref;
    $('sr-error').hidden = true;
    api({ action: 'get', ref: ref }).then(function (res) {
      if (!res.ok) { toast(res.error || 'Pièce introuvable.'); return; }
      var p = res.product;
      $('sr-ref').textContent = 'Réf. N°' + p.ref;
      var box = $('sr-photos'); box.textContent = '';
      res.photos.forEach(function (ph) {
        var fig = el('figure'); var img = el('img'); img.src = '/' + ph.path; img.alt = ph.label; img.loading = 'lazy';
        fig.appendChild(img); fig.appendChild(el('figcaption', '', ph.label)); box.appendChild(fig);
      });
      var w = warnText(res.note);
      $('sr-warn').hidden = !w;
      if (w) $('sr-warn').textContent = 'Génération incomplète (' + w + '). Complétez la fiche à la main ou relancez depuis le catalogue.';
      $('sr-name').value = p.name; $('sr-price').value = p.price; $('sr-weight').value = p.weight_grams || '';
      $('sr-cat').value = p.cat; $('sr-desc').value = p.description; $('sr-badge').value = p.badge;
      $('sr-publish').checked = !p.hidden;
      $('sr-discard').hidden = res.status === 'reviewed';
      $('sr-save').disabled = false; $('sr-save').textContent = 'Enregistrer';
      if (!dlg.open) dlg.showModal();
      dlg.querySelector('.sr-body').scrollTop = 0;
    });
  }
  $('sr-save').addEventListener('click', function () {
    var btn = this; btn.disabled = true; btn.textContent = 'Enregistrement…';
    var fd = new FormData();
    fd.append('action', 'save'); fd.append('ref', sheetRef);
    fd.append('name', $('sr-name').value); fd.append('price', $('sr-price').value);
    fd.append('weight_grams', $('sr-weight').value || '0'); fd.append('cat', $('sr-cat').value);
    fd.append('description', $('sr-desc').value); fd.append('badge', $('sr-badge').value);
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
  go(location.hash.replace('#', '') || 'single');
  refreshCount();
  runVariants();
  renderShoot();

  // Lot interrompu (page fermée, téléphone rebooté) : on le retrouve.
  idbOpen().then(function (database) {
    idb = database;
    return idbDo('meta', 'readonly', function (s) { return s.get('layout'); });
  }).then(function (layout) {
    if (!layout || !layout.length) return;
    var ids = [].concat.apply([], layout);
    return Promise.all(ids.map(function (id) { return idbDo('blobs', 'readonly', function (s) { return s.get(id); }); })).then(function (blobs) {
      var byId = {};
      ids.forEach(function (id, i) { if (blobs[i]) byId[id] = { id: id, blob: blobs[i], url: URL.createObjectURL(blobs[i]) }; });
      var restored = layout.map(function (p) { return p.map(function (id) { return byId[id]; }).filter(Boolean); }).filter(function (p) { return p.length; });
      if (!restored.length) return;
      pieces = restored.concat([[]]);
      nextId = Math.max.apply(null, ids) + 1;
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
