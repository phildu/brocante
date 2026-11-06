// Shooting du lieu (admin/shop-shooting.php) : programme de prise de vue de la
// boutique, du stand ou de l'atelier, contrôle de chaque photo, légende, puis
// publication sur le site (accueil, « Notre histoire », écran en magasin,
// bandeaux de page). Les photos publiées restent réglables dans les écrans
// existants : rien n'est verrouillé ici.
(function () {
  'use strict';
  var root = document.querySelector('.ss');
  if (!root) return;

  var DATA = {};
  try { DATA = JSON.parse(document.getElementById('ss-data').textContent); } catch (e) { return; }
  var ACTION = root.dataset.action;
  var AI = root.dataset.ai === '1';
  // Un photographe prépare tout, mais ses photos partent en validation : seuls
  // les administrateurs mettent en ligne (et voient la file de validation).
  var LIVE = DATA.canPublishLive !== false;
  var SESSION_KEY = DATA.sessionKey || '';
  var IS_ADMIN = root.dataset.role === 'admin';

  var state = {
    place: DATA.defaultPlace || 'boutique',
    shots: [],
    index: 0,
    photo: null,      // fichier réduit à envoyer (null si la photo est déjà stockée)
    photoUrl: null,   // ce qui est affiché : URL objet ou adresse serveur
    stored: '',       // chemin serveur de la photo retenue (retouche IA)
    before: null,     // version précédente pendant la comparaison {blob, url, stored}
    review: null,
    metrics: null,
    published: [],
    audio: true
  };
  try { state.audio = localStorage.getItem('ss-audio') !== '0'; } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function image(src, alt) { var i = document.createElement('img'); i.src = src; i.alt = alt || ''; i.loading = 'lazy'; return i; }
  function revoke(url) { if (url && String(url).indexOf('blob:') === 0) URL.revokeObjectURL(url); }
  function place() { return DATA.places[state.place] || DATA.places.boutique; }
  function currentShot() { return state.shots[state.index] || null; }
  function targetLabel(key) { var t = DATA.targets[key]; return t ? t.label : key; }
  function issueLabel(key) { return (DATA.issueLabels && DATA.issueLabels[key]) || key; }
  function note(text) { var n = $('ss-shot-note'); n.hidden = !text; n.textContent = text || ''; }

  function post(url, fd) {
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : vérifiez le réseau.' }; });
  }

  /** Réduit la photo sur le téléphone (JPEG, 1600 px au plus) avant l'envoi. */
  function shrink(file, max, quality) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var k = Math.min(1, (max || 1600) / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * k));
        c.height = Math.max(1, Math.round(img.naturalHeight * k));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', quality || 0.88);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  // ── Panneaux ─────────────────────────────────────────────────────────────
  var panels = ['place', 'program', 'shot', 'done'];
  function showPanel(name) {
    panels.forEach(function (p) { var n = $('ss-panel-' + p); if (n) n.hidden = p !== name; });
    setStep(name === 'place' ? 1 : (name === 'program' ? 2 : (name === 'shot' ? 3 : 4)));
    window.scrollTo(0, 0);
  }
  function setStep(n) {
    for (var i = 1; i <= 4; i++) {
      var li = $('ss-step-' + i);
      if (!li) continue;
      li.classList.toggle('is-done', i < n);
      if (i === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    }
  }

  // ── Étape 1 : le lieu ────────────────────────────────────────────────────
  function selectPlace(key) {
    if (!DATA.places[key]) return;
    state.place = key;
    Array.prototype.forEach.call($('ss-places').querySelectorAll('button'), function (b) {
      b.setAttribute('aria-pressed', b.dataset.place === key ? 'true' : 'false');
    });
    var list = $('ss-before-list');
    list.textContent = '';
    (place().before || []).forEach(function (line) { list.appendChild(el('li', null, line)); });
    $('ss-before').hidden = false;
    $('ss-to-program').disabled = false;
  }

  // ── Étape 2 : le programme ───────────────────────────────────────────────
  function renderProgram() {
    var p = place();
    $('ss-program-title').textContent = p.title + ' : ' + p.shots.length + ' vues';
    $('ss-program-intro').textContent = p.intro;
    var plan = $('ss-plan');
    plan.textContent = '';
    p.shots.forEach(function (shot) {
      var body = el('div');
      var title = el('b', null, shot.label);
      if (!shot.required) title.appendChild(el('span', 'ss-optional', ' — facultatif'));
      body.appendChild(title);
      body.appendChild(el('small', null, shot.tip));
      body.appendChild(el('small', 'ss-plan-where', 'Publié sur : ' + (shot.targets || []).map(targetLabel).join(' · ')));
      var li = el('li');
      li.appendChild(body);
      plan.appendChild(li);
    });
    $('ss-audio-toggle').checked = state.audio;
  }

  // ── Étape 3 : la prise de vue ────────────────────────────────────────────
  function startShooting() {
    state.shots = place().shots.slice();
    state.index = 0;
    state.published = [];
    showPanel('shot');
    openShot();
  }

  function openShot() {
    var shot = currentShot();
    if (!shot) return finish();
    resetPhoto();
    var total = state.shots.length;
    $('ss-progress-fill').style.width = Math.round(state.index / total * 100) + '%';
    $('ss-progress-text').textContent = 'Vue ' + (state.index + 1) + ' sur ' + total + ' · ' + place().title;
    $('ss-shot-badge').textContent = shot.required ? 'Vue essentielle' : 'Vue facultative';
    $('ss-shot-title').textContent = shot.label;
    $('ss-shot-tip').textContent = shot.tip;
    var ratio = $('ss-shot-ratio');
    if (shot.ratio && shot.ratio !== 'libre') {
      ratio.textContent = 'Format conseillé : ' + shot.ratio;
      ratio.hidden = false;
    } else {
      ratio.hidden = true;
    }
    renderTargets(shot);
    $('ss-caption').value = '';
    $('ss-publish-box').hidden = true;
    ['ss-shot-publish', 'ss-shot-review', 'ss-shot-enhance', 'ss-shot-again'].forEach(function (id) { $(id).hidden = true; });
    var already = publishedFor(state.index);
    note(already ? 'Cette vue a déjà été ' + (LIVE ? 'publiée' : 'envoyée') + ' pendant la séance : '
      + (LIVE ? 'publier' : 'envoyer') + ' à nouveau ajoutera une photo de plus.' : '');
    renderThumbs();
    announceShot();
  }

  function resetPhoto() {
    revoke(state.photoUrl);
    if (state.before) revoke(state.before.url);
    state.photo = null;
    state.photoUrl = null;
    state.stored = '';
    state.before = null;
    state.review = null;
    state.metrics = null;
    $('ss-shot-img').removeAttribute('src');
    $('ss-shot-img').hidden = true;
    $('ss-shot-empty').hidden = false;
    $('ss-shot-stage').classList.remove('has-photo');
    $('ss-shot-quality').hidden = true;
    $('ss-shot-quality').textContent = '';
    $('ss-compare').hidden = true;
    $('ss-shot-cam').value = '';
    $('ss-shot-lib').value = '';
  }

  function setPhoto(blob) {
    return shrink(blob, 1600, 0.88).then(function (small) {
      resetPhoto();
      state.photo = small;
      state.photoUrl = URL.createObjectURL(small);
      $('ss-shot-img').src = state.photoUrl;
      $('ss-shot-img').hidden = false;
      $('ss-shot-empty').hidden = true;
      $('ss-shot-stage').classList.add('has-photo');
      $('ss-shot-again').hidden = false;
      note('');
      return reviewPhoto(false);
    });
  }

  function appendPhoto(fd) {
    if (state.stored) fd.append('path', state.stored);
    else if (state.photo) fd.append('photo', state.photo, 'photo.jpg');
  }

  function reviewPhoto(withAi) {
    var shot = currentShot();
    if (!shot) return Promise.resolve(null);
    var box = $('ss-shot-quality');
    box.hidden = false;
    box.classList.add('is-loading');
    box.textContent = withAi ? "L'IA regarde la photo…" : 'Contrôle de la photo…';
    var fd = new FormData();
    fd.append('action', 'review');
    fd.append('place', state.place);
    fd.append('shot', shot.key);
    if (withAi) fd.append('ai', '1');
    appendPhoto(fd);
    return post(ACTION, fd).then(function (res) {
      box.classList.remove('is-loading');
      if (!res.ok) { box.textContent = res.error || 'Le contrôle a échoué.'; return null; }
      state.metrics = res.metrics;
      state.review = res.review;
      renderQuality(res.review, res.metrics, !!res.ai, res.ai_error || '');
      showPublishButtons();
      if (withAi) speak(qualitySpeech(res.review));
      return res;
    });
  }

  function renderQuality(review, metrics, ai, aiError) {
    var box = $('ss-shot-quality');
    var q = review.quality;
    box.textContent = '';

    var verdict = el('div', 'ss-verdict');
    verdict.appendChild(el('span', 'ss-pill ' + (review.ready ? 'is-good' : 'is-warn'), review.ready ? '✓ Photo exploitable' : '⚠ À reprendre'));
    verdict.appendChild(el('span', 'ss-pill', ai ? 'Contrôle IA + mesures' : 'Mesures locales'));
    box.appendChild(verdict);

    box.appendChild(el('p', 'ss-summary', review.summary));

    var meters = el('div', 'ss-meters');
    meters.appendChild(meter('Netteté', q.sharpness));
    meters.appendChild(meter('Lumière', q.lighting));
    meters.appendChild(meter('Cadre', q.framing));
    box.appendChild(meters);

    if (q.issues.length) {
      var issues = el('div', 'ss-issues');
      q.issues.forEach(function (key) { issues.appendChild(el('span', 'ss-issue', issueLabel(key))); });
      box.appendChild(issues);
    }
    if (q.advice.length) {
      var advice = el('ul', 'ss-advice');
      q.advice.forEach(function (line) { advice.appendChild(el('li', null, line)); });
      box.appendChild(advice);
    }
    if (metrics && metrics.width) {
      box.appendChild(el('p', 'ss-note', metrics.width + ' × ' + metrics.height + ' px · ' + (metrics.brightness || 0) + ' % de luminosité'));
    }
    if (aiError) box.appendChild(el('p', 'ss-note', aiError));
  }

  function meter(label, value) {
    var row = el('div', 'ss-meter');
    row.appendChild(el('span', 'ss-meter-label', label));
    var track = el('span', 'ss-meter-track');
    var fill = el('span', 'ss-meter-fill' + (value < 45 ? ' is-bad' : (value < 65 ? ' is-warn' : '')));
    fill.style.width = Math.max(0, Math.min(100, value)) + '%';
    track.appendChild(fill);
    row.appendChild(track);
    row.appendChild(el('span', 'ss-meter-value', String(value)));
    return row;
  }

  function showPublishButtons() {
    $('ss-publish-box').hidden = false;
    $('ss-shot-publish').hidden = false;
    $('ss-shot-review').hidden = !AI;
    $('ss-shot-enhance').hidden = !AI;
    $('ss-caption-ai').hidden = !AI;
  }

  // ── Retouche IA ──────────────────────────────────────────────────────────
  function enhance() {
    note('Retouche IA en cours : comptez 20 à 60 secondes, ne fermez pas la page.');
    setBusy(true);
    var fd = new FormData();
    fd.append('action', 'enhance');
    fd.append('place', state.place);
    fd.append('shot', currentShot().key);
    appendPhoto(fd);
    return post(ACTION, fd).then(function (res) {
      setBusy(false);
      if (!res.ok) { note(res.error || 'La retouche a échoué.'); return null; }
      // L'original est gardé pour la comparaison ; la retouche devient la photo retenue.
      state.before = { blob: state.photo, url: state.photoUrl, stored: state.stored };
      state.stored = res.path;
      state.photo = null;
      state.photoUrl = res.url;
      $('ss-shot-img').src = res.url;
      $('ss-compare-before').src = state.before.url || '';
      $('ss-compare-after').src = res.url;
      $('ss-compare').hidden = false;
      note('Retouche prête : choisissez la version à publier.');
      return reviewPhoto(false);
    });
  }

  function keepVersion(which) {
    if (which === 'before' && state.before) {
      revoke(state.photoUrl);
      state.photo = state.before.blob;
      state.photoUrl = state.before.url;
      state.stored = state.before.stored;
      state.before = null;
      $('ss-shot-img').src = state.photoUrl || '';
      $('ss-compare').hidden = true;
      note('');
      return reviewPhoto(false);
    }
    if (state.before) revoke(state.before.url);
    state.before = null;
    $('ss-compare').hidden = true;
    note('Retouche conservée.');
    return Promise.resolve(null);
  }

  // ── Légende et cibles de publication ─────────────────────────────────────
  function suggestCaption() {
    note('Légende en cours de rédaction…');
    var fd = new FormData();
    fd.append('action', 'caption');
    fd.append('place', state.place);
    fd.append('shot', currentShot().key);
    appendPhoto(fd);
    return post(ACTION, fd).then(function (res) {
      if (!res.ok) { note(res.error || "La légende n'a pas pu être rédigée."); return null; }
      $('ss-caption').value = res.caption;
      note('Légende suggérée : modifiez-la si besoin.');
      return res;
    });
  }

  function renderTargets(shot) {
    var box = $('ss-targets');
    box.textContent = '';
    var defaults = shot.targets || [];
    Object.keys(DATA.targets).forEach(function (key) {
      var t = DATA.targets[key];
      var input = document.createElement('input');
      input.type = 'checkbox';
      input.value = key;
      input.checked = defaults.indexOf(key) >= 0;
      input.addEventListener('change', syncTargetOptions);
      var text = el('span');
      text.appendChild(el('b', null, t.label));
      text.appendChild(el('small', null, t.where));
      var label = el('label', 'ss-target');
      label.appendChild(input);
      label.appendChild(text);
      box.appendChild(label);
    });
    syncTargetOptions();
  }

  function checkedTargets() {
    return Array.prototype.map.call($('ss-targets').querySelectorAll('input:checked'), function (i) { return i.value; });
  }

  function syncTargetOptions() {
    var targets = checkedTargets();
    $('ss-overlay-wrap').hidden = !targets.some(function (t) { return t.indexOf('banner:') === 0; });
    $('ss-duration-wrap').hidden = targets.indexOf('slideshow') < 0;
  }

  // ── Publication (ou envoi en validation) ─────────────────────────────────
  function publish() {
    var shot = currentShot();
    if (!shot) return Promise.resolve(null);
    note(LIVE ? 'Publication en cours…' : 'Envoi pour validation…');
    setBusy(true);
    var fd = new FormData();
    fd.append('action', 'publish');
    fd.append('place', state.place);
    fd.append('shot', shot.key);
    fd.append('session_key', SESSION_KEY);
    fd.append('caption', $('ss-caption').value.trim());
    fd.append('overlay', $('ss-overlay').value);
    fd.append('duration', $('ss-duration').value);
    checkedTargets().forEach(function (t) { fd.append('targets[]', t); });
    appendPhoto(fd);
    var index = state.index;
    return post(ACTION, fd).then(function (res) {
      setBusy(false);
      if (!res.ok) { note(res.error || (LIVE ? 'La publication a échoué.' : "L'envoi a échoué.")); return null; }
      state.published.push({
        index: index,
        url: res.url,
        label: shot.label,
        caption: res.caption,
        targets: checkedTargets().map(targetLabel),
        applied: res.applied || [],
        media_only: !!res.media_only,
        pending: !!res.pending
      });
      return next();
    });
  }

  function publishedFor(index) {
    return state.published.filter(function (p) { return p.index === index; })[0] || null;
  }

  function next() {
    state.index++;
    if (state.index >= state.shots.length) return finish();
    openShot();
    return null;
  }

  function renderThumbs() {
    var box = $('ss-thumbs');
    box.textContent = '';
    state.shots.forEach(function (shot, i) {
      var done = publishedFor(i);
      var b = el('button', 'ss-thumb' + (i === state.index ? ' is-current' : '') + (done ? '' : ' is-empty'));
      b.type = 'button';
      b.title = shot.label;
      if (done) {
        b.appendChild(image(done.url, shot.label));
        b.appendChild(el('span', null, shot.label));
      } else {
        b.appendChild(el('span', null, String(i + 1)));
      }
      b.addEventListener('click', function () {
        if (i === state.index) return;
        state.index = i;
        openShot();
      });
      box.appendChild(b);
    });
    $('ss-to-done').hidden = state.published.length === 0;
  }

  // ── Étape 4 : récapitulatif ──────────────────────────────────────────────
  function finish() {
    stopSpeaking();
    var n = state.published.length;
    var waiting = state.published.filter(function (p) { return p.pending; }).length;
    if (!n) {
      $('ss-done-text').textContent = 'Aucune photo ' + (LIVE ? 'publiée' : 'envoyée') + ' pendant cette séance.';
    } else if (waiting) {
      $('ss-done-text').textContent = n + ' photo' + (n > 1 ? 's' : '') + ' de ' + place().title.toLowerCase()
        + ' envoyée' + (n > 1 ? 's' : '') + ' pour validation. L\'administrateur reçoit un message de fin de séance.';
    } else {
      $('ss-done-text').textContent = n + ' photo' + (n > 1 ? 's' : '') + ' de ' + place().title.toLowerCase()
        + ' publiée' + (n > 1 ? 's' : '') + ' pendant cette séance.';
    }
    var grid = $('ss-done-grid');
    grid.textContent = '';
    state.published.forEach(function (row) {
      var cap = el('figcaption');
      cap.appendChild(el('b', null, row.label));
      if (row.caption) cap.appendChild(el('span', null, row.caption));
      (row.applied || []).forEach(function (a) { cap.appendChild(el('small', null, a.message)); });
      if (row.media_only) cap.appendChild(el('small', null, 'Enregistrée dans la médiathèque uniquement.'));
      if (row.pending) {
        var pill = el('span', 'ss-pill', 'En attente de validation');
        pill.dataset.status = 'pending';
        cap.appendChild(pill);
        if (row.targets && row.targets.length) cap.appendChild(el('small', null, 'Prévue sur : ' + row.targets.join(' · ')));
      }
      var fig = el('figure');
      fig.appendChild(image(row.url, row.label));
      fig.appendChild(cap);
      grid.appendChild(fig);
    });
    $('ss-progress-fill').style.width = '100%';
    showPanel('done');
    if (!LIVE && n) notifyAdmins();
    return null;
  }

  /**
   * Fin de séance d'un photographe : un seul message aux administrateurs, pour
   * les photos de cette séance encore non annoncées. L'écran dit ce qui s'est
   * passé, y compris quand aucun e-mail n'est configuré.
   */
  function notifyAdmins() {
    var box = $('ss-done-notice');
    var fd = new FormData();
    fd.append('action', 'finish');
    fd.append('session_key', SESSION_KEY);
    post(ACTION, fd).then(function (res) {
      if (!box) return;
      box.hidden = false;
      if (!res.ok) {
        box.textContent = "L'administrateur n'a pas pu être prévenu automatiquement : prévenez-le directement.";
        return;
      }
      if (res.notified > 0) {
        box.textContent = '✓ L\'administrateur a été prévenu par e-mail : ' + res.notified
          + ' photo' + (res.notified > 1 ? 's' : '') + ' attend' + (res.notified > 1 ? 'ent' : '') + ' sa validation.';
      } else if (res.pending > 0) {
        box.textContent = 'Vos photos attendent la validation de l\'administrateur.';
      } else {
        box.textContent = 'Aucune adresse e-mail administrateur n\'est configurée : prévenez l\'administrateur directement.';
      }
    });
    refreshMine();
  }

  /** Liste « mes envois » tenue à jour sans recharger la page. */
  var STATUS = { pending: 'En attente', published: 'Publiée', rejected: 'Refusée' };
  function refreshMine() {
    var list = $('ss-mine-list');
    if (!list) return;
    var fd = new FormData();
    fd.append('action', 'mine');
    post(ACTION, fd).then(function (res) {
      if (!res.ok || !res.requests) return;
      list.textContent = '';
      res.requests.forEach(function (row) {
        var li = el('li');
        li.dataset.status = row.status;
        li.appendChild(image(row.url, row.shot));
        var body = el('div', 'ss-pending-body');
        body.appendChild(el('b', null, row.shot));
        body.appendChild(el('small', null, row.targets.length ? row.targets.join(' · ') : 'Aucun support choisi'));
        li.appendChild(body);
        var pill = el('span', 'ss-pill', STATUS[row.status] || row.status);
        pill.dataset.status = row.status;
        li.appendChild(pill);
        list.appendChild(li);
      });
      list.hidden = res.requests.length === 0;
      var empty = $('ss-mine-empty');
      if (empty) empty.hidden = res.requests.length > 0;
    });
  }

  // ── Validation par l'administrateur ──────────────────────────────────────
  var pendingList = $('ss-pending-list');
  if (pendingList) {
    pendingList.addEventListener('click', function (event) {
      var button = event.target.closest('[data-decide]');
      if (!button) return;
      var row = button.closest('li[data-request]');
      if (row) decide(row, button.dataset.decide);
    });
  }

  function decide(row, decision) {
    var buttons = row.querySelectorAll('button');
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
    var fd = new FormData();
    fd.append('action', 'decide');
    fd.append('id', row.dataset.request);
    fd.append('decision', decision);
    post(ACTION, fd).then(function (res) {
      if (!res.ok) {
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
        pendingNote(res.error || 'La décision a échoué.');
        return;
      }
      row.remove();
      updatePendingCount(res.count);
      var lines = (res.messages || []).map(function (m) { return m.message; });
      pendingNote(lines.length ? lines.join(' ') : (decision === 'publish' ? 'Photo publiée.' : 'Photo refusée.'));
    });
  }

  function pendingNote(text) {
    var box = $('ss-pending-note');
    if (!box) return;
    box.hidden = !text;
    box.textContent = text || '';
  }

  function updatePendingCount(count) {
    var badge = $('ss-pending-count');
    if (badge) { badge.textContent = String(count); badge.hidden = count === 0; }
    var list = $('ss-pending-list');
    var empty = $('ss-pending-empty');
    if (list) list.hidden = count === 0;
    if (empty) empty.hidden = count > 0;
    if (count === 0) {
      var notice = document.querySelector('.ss-notice-admin');
      if (notice) notice.hidden = true;
    }
  }

  // ── Partage : lien de connexion du photographe ───────────────────────────
  function copyShare() {
    var input = $('ss-share-url');
    var button = $('ss-share-copy');
    var done = function (ok) {
      button.textContent = ok ? '✓ Lien copié' : 'Sélectionnez le lien ci-dessus';
      setTimeout(function () { button.textContent = 'Copier le lien'; }, 2000);
    };
    var fallback = function () {
      input.focus();
      input.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) {}
      done(ok);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(function () { done(true); }, fallback);
    } else {
      fallback();
    }
  }

  var copyButton = $('ss-share-copy');
  if (copyButton) copyButton.addEventListener('click', copyShare);

  function setBusy(busy) {
    ['ss-shot-publish', 'ss-shot-review', 'ss-shot-enhance', 'ss-shot-again', 'ss-shot-skip'].forEach(function (id) {
      var b = $(id);
      if (b) b.disabled = busy;
    });
  }

  // ── Coaching audio ───────────────────────────────────────────────────────
  var synth = window.speechSynthesis || null;
  var voices = [];
  function loadVoices() { try { voices = synth ? synth.getVoices() : []; } catch (e) { voices = []; } }
  if (synth) { loadVoices(); synth.onvoiceschanged = loadVoices; }
  function frenchVoice() {
    return voices.filter(function (x) { return /^fr[-_]FR/i.test(x.lang); })[0]
      || voices.filter(function (x) { return /^fr/i.test(x.lang); })[0]
      || null;
  }
  function audioStatus(text) { $('ss-audio-status').textContent = text || ''; }
  function stopSpeaking() {
    if (synth) { try { synth.cancel(); } catch (e) {} }
    audioStatus('');
    $('ss-audio-stop').hidden = true;
  }
  function speak(text, force) {
    if ((!state.audio && !force) || !synth || !text) return;
    try {
      synth.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'fr-FR'; u.rate = 0.98; u.pitch = 1;
      var v = frenchVoice(); if (v) u.voice = v;
      u.onstart = function () { audioStatus('🔊 Coaching audio en cours…'); $('ss-audio-stop').hidden = false; };
      u.onend = function () { audioStatus(''); $('ss-audio-stop').hidden = true; };
      u.onerror = function () { audioStatus(''); $('ss-audio-stop').hidden = true; };
      synth.speak(u);
    } catch (e) { audioStatus(''); }
  }
  function announceShot() {
    var shot = currentShot();
    if (!shot) return;
    speak('Vue ' + (state.index + 1) + ' sur ' + state.shots.length + ' : ' + shot.label + '. ' + shot.coach);
  }
  function qualitySpeech(review) {
    if (!review) return '';
    var q = review.quality;
    var parts = [];
    if (q.issues.indexOf('flou') >= 0) parts.push('elle manque de netteté');
    if (q.issues.indexOf('sombre') >= 0) parts.push('elle est trop sombre');
    if (q.issues.indexOf('surexpose') >= 0) parts.push('elle est surexposée');
    if (q.issues.indexOf('penche') >= 0) parts.push('les lignes sont penchées');
    if (q.issues.indexOf('encombre') >= 0) parts.push('le décor est encombré');
    if (q.issues.indexOf('personne') >= 0) parts.push('une personne est reconnaissable');
    if (!parts.length) return 'Photo nette et bien exposée : vous pouvez la publier.';
    return 'Attention : ' + parts.join(', ') + '. Vous pouvez reprendre la photo, améliorer la lumière, ou continuer si cela vous convient.';
  }

  // ── Vidéo du commerce : présentation ou témoignage client ────────────────
  // Le texte (généré ou écrit) sert de prompteur ; la vidéo reste dans la
  // médiathèque de la boutique, rien n'est publié automatiquement.
  var MAX_CLIP = 120;
  var clip = {
    program: '',
    blob: null,
    url: '',
    duration: 0,
    recording: false,
    recorder: null,
    stream: null,
    chunks: [],
    startedAt: 0,
    timer: null,
    mime: ''
  };
  var PROMPTER = { open: false, playing: false, speed: 24, last: 0, frame: 0, pos: 0, written: 0 };

  function vNote(text) {
    var box = $('ss-video-note');
    box.hidden = !text;
    box.textContent = text || '';
  }
  function vAiNote(text) {
    $('ss-video-ai-note').textContent = text || '';
  }
  var videosTimer = null;
  function videosNote(text) {
    var box = $('ss-videos-note');
    if (!box) return;
    box.hidden = !text;
    box.textContent = text || '';
    if (videosTimer) clearTimeout(videosTimer);
    videosTimer = text ? setTimeout(function () { box.hidden = true; }, 4000) : null;
  }
  function fmtTime(seconds) {
    var m = Math.floor(seconds / 60), s = seconds % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }
  function fmtSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(bytes >= 10485760 ? 0 : 1) + ' Mo';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' Ko';
    return bytes + ' o';
  }
  function setProgress(pct) {
    $('ss-video-progress-fill').style.width = Math.max(0, Math.min(100, pct)) + '%';
  }

  function setMode(mode) {
    Array.prototype.forEach.call(document.querySelectorAll('.ss-modes button'), function (b) {
      b.setAttribute('aria-pressed', b.dataset.mode === mode ? 'true' : 'false');
    });
    var videoMode = mode === 'video';
    if (!videoMode) {
      if (clip.recording) stopRecording();
      closePrompter();
    }
    $('ss-head').hidden = videoMode;
    $('ss-photo-flow').hidden = videoMode;
    $('ss-panel-video').hidden = !videoMode;
    if (videoMode) {
      window.scrollTo(0, 0);
    } else {
      showPanel('place');
    }
  }

  function selectVideoProgram(key) {
    var p = DATA.videoPrograms[key];
    if (!p) return;
    clip.program = key;
    Array.prototype.forEach.call($('ss-video-programs').querySelectorAll('button'), function (b) {
      b.setAttribute('aria-pressed', b.dataset.videoProgram === key ? 'true' : 'false');
    });
    $('ss-video-work').hidden = false;
    var tips = $('ss-video-tips');
    tips.textContent = '';
    (p.tips || []).forEach(function (line) { tips.appendChild(el('li', null, line)); });
    $('ss-video-text-label').textContent = p.text_label || 'Texte à dire (modifiable)';
    $('ss-video-text-hint').textContent = p.text_hint || '';
    $('ss-video-text').placeholder = p.placeholder || '';
    $('ss-video-consent-wrap').hidden = !p.consent;
    $('ss-video-caption').placeholder = p.consent ? 'Ex. : Témoignage d’un client' : 'Ex. : La boutique se présente';
    $('ss-video-support').textContent = (p.capture === 'environment' ? 'Caméra arrière conseillée' : 'Caméra avant conseillée')
      + ' · enregistrement direct (' + MAX_CLIP + ' s au plus) ou import d’une vidéo.';
    syncPrompterButton();
    vAiNote('');
  }

  function generateScript() {
    if (!clip.program) {
      vNote('Choisissez d’abord un programme vidéo.');
      return;
    }
    if ($('ss-video-text').value.trim() !== ''
      && !window.confirm('Le texte actuel sera remplacé par la proposition de l’IA. Continuer ?')) return;
    var button = $('ss-video-ai');
    button.disabled = true;
    vAiNote('L’IA prépare le texte… comptez quelques secondes.');
    var fd = new FormData();
    fd.append('action', 'script');
    fd.append('program', clip.program);
    fd.append('place', state.place);
    post(ACTION, fd).then(function (res) {
      button.disabled = !AI;
      if (!res.ok) {
        vAiNote(res.error || 'L’IA n’a pas répondu : écrivez le texte à la main.');
        return;
      }
      $('ss-video-text').value = res.text || '';
      syncPrompterButton();
      vAiNote('Texte proposé : corrigez les noms, les horaires et les détails avant de le lire.');
    });
  }

  function syncPrompterButton() {
    $('ss-video-prompter-open').disabled = $('ss-video-text').value.trim() === '';
  }

  function prompterLabel(force) {
    var base = clip.recording ? '● Enregistrement' : (PROMPTER.playing ? 'Défilement' : 'Pause');
    var speed = PROMPTER.speed <= 16 ? 'lente' : (PROMPTER.speed >= 40 ? 'rapide' : 'moyenne');
    $('ss-prompter-state').textContent = (force || base) + ' · vitesse ' + speed;
  }

  function updatePrompterState(force) {
    if (!PROMPTER.open && !force) return;
    prompterLabel(force);
    var button = $('ss-prompter-rec');
    button.textContent = clip.recording ? '■ Arrêter' : '● Enregistrer';
    button.classList.toggle('is-rec', clip.recording);
    var time = $('ss-prompter-time');
    time.hidden = !clip.recording;
    if (clip.recording) time.textContent = $('ss-video-time').textContent;
  }

  function openPrompter() {
    var text = $('ss-video-text').value.trim();
    if (!text) {
      vNote('Écrivez ou générez le texte avant d’ouvrir le prompteur.');
      return;
    }
    closePrompter();
    $('ss-prompter-text').textContent = text;
    $('ss-prompter-text').scrollTop = 0;
    PROMPTER.pos = 0;
    PROMPTER.written = 0;
    $('ss-prompter').hidden = false;
    PROMPTER.open = true;
    PROMPTER.playing = true;
    PROMPTER.last = 0;
    $('ss-prompter-play').textContent = 'Pause';
    PROMPTER.frame = requestAnimationFrame(prompterTick);
    updatePrompterState();
    vNote('Prompteur ouvert : réglez la vitesse, puis enregistrez.');
  }

  function closePrompter() {
    if (PROMPTER.frame) cancelAnimationFrame(PROMPTER.frame);
    PROMPTER.frame = 0;
    PROMPTER.open = false;
    PROMPTER.playing = false;
    $('ss-prompter').hidden = true;
  }

  function prompterTick(now) {
    if (!PROMPTER.open || !PROMPTER.playing) return;
    var box = $('ss-prompter-text');
    if (!PROMPTER.last) PROMPTER.last = now;
    var dt = Math.min(0.12, (now - PROMPTER.last) / 1000);
    PROMPTER.last = now;
    // scrollTop est arrondi à l'entier par le navigateur : on garde la position
    // exacte en mémoire, sinon un pas de 0,4 px ne décolle jamais de zéro.
    var max = Math.max(0, box.scrollHeight - box.clientHeight);
    if (Math.abs(box.scrollTop - PROMPTER.written) > 2) PROMPTER.pos = box.scrollTop;
    PROMPTER.pos = Math.min(max, PROMPTER.pos + PROMPTER.speed * dt);
    box.scrollTop = PROMPTER.pos;
    PROMPTER.written = box.scrollTop;
    if (PROMPTER.pos >= max - 1) {
      PROMPTER.playing = false;
      $('ss-prompter-play').textContent = 'Rejouer';
      prompterLabel('Fin du texte');
      return;
    }
    PROMPTER.frame = requestAnimationFrame(prompterTick);
  }

  function restartPrompter() {
    var box = $('ss-prompter-text');
    box.scrollTop = 0;
    PROMPTER.pos = 0;
    PROMPTER.written = 0;
    if (PROMPTER.frame) cancelAnimationFrame(PROMPTER.frame);
    PROMPTER.playing = true;
    PROMPTER.last = 0;
    $('ss-prompter-play').textContent = 'Pause';
    PROMPTER.frame = requestAnimationFrame(prompterTick);
  }

  function togglePrompter() {
    if (!PROMPTER.open) return;
    var box = $('ss-prompter-text');
    if (PROMPTER.playing) {
      PROMPTER.playing = false;
      if (PROMPTER.frame) cancelAnimationFrame(PROMPTER.frame);
      PROMPTER.frame = 0;
      $('ss-prompter-play').textContent = 'Reprendre';
      prompterLabel('Pause');
      return;
    }
    if (box.scrollTop + box.clientHeight >= box.scrollHeight - 2) {
      box.scrollTop = 0;
      PROMPTER.pos = 0;
      PROMPTER.written = 0;
    } else {
      PROMPTER.pos = box.scrollTop;
    }
    PROMPTER.playing = true;
    PROMPTER.last = 0;
    $('ss-prompter-play').textContent = 'Pause';
    PROMPTER.frame = requestAnimationFrame(prompterTick);
    prompterLabel();
  }

  function pickVideoMime() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    var types = ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
    for (var i = 0; i < types.length; i++) {
      try { if (MediaRecorder.isTypeSupported(types[i])) return types[i]; } catch (e) {}
    }
    return '';
  }

  function stopStream() {
    if (!clip.stream) return;
    clip.stream.getTracks().forEach(function (track) { try { track.stop(); } catch (e) {} });
    clip.stream = null;
  }

  function clearClip() {
    revoke(clip.url);
    clip.blob = null;
    clip.url = '';
    clip.duration = 0;
    clip.chunks = [];
    var preview = $('ss-video-preview');
    try { preview.pause(); } catch (e) {}
    preview.removeAttribute('src');
    preview.srcObject = null;
    preview.controls = false;
    preview.muted = true;
    preview.hidden = true;
    $('ss-video-idle').hidden = false;
    $('ss-video-rec').hidden = true;
    $('ss-video-start').hidden = false;
    $('ss-video-start').disabled = false;
    $('ss-video-stop').hidden = true;
    $('ss-video-retake').hidden = true;
    $('ss-video-upload').hidden = true;
    $('ss-video-file').hidden = false;
    $('ss-video-input').value = '';
    $('ss-video-progress').hidden = true;
    setProgress(0);
  }

  function startRecording() {
    if (clip.recording) return;
    var p = clip.program ? DATA.videoPrograms[clip.program] : null;
    if (!p) {
      vNote('Choisissez d’abord un programme vidéo.');
      updatePrompterState('Choisissez un programme');
      return;
    }
    if (p.consent && !$('ss-video-consent').checked) {
      vNote('Cochez l’accord du client avant d’enregistrer le témoignage.');
      updatePrompterState('Accord du client requis');
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      vNote('Ce navigateur ne peut pas filmer directement : utilisez « Importer une vidéo ».');
      updatePrompterState('Caméra indisponible');
      return;
    }
    clearClip();
    vNote('Ouverture de la caméra…');
    updatePrompterState('Ouverture de la caméra');
    $('ss-video-start').disabled = true;
    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: p.capture === 'environment' ? 'environment' : 'user' }, width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: { echoCancellation: true, noiseSuppression: true }
    }).then(function (stream) {
      clip.stream = stream;
      var preview = $('ss-video-preview');
      preview.hidden = false;
      preview.muted = true;
      preview.controls = false;
      preview.srcObject = stream;
      var playing = preview.play();
      if (playing && playing.catch) playing.catch(function () {});
      $('ss-video-idle').hidden = true;

      var mime = pickVideoMime();
      var recorder = null;
      try { recorder = mime ? new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 2500000 }) : new MediaRecorder(stream); } catch (e) {}
      if (!recorder) {
        stopStream();
        $('ss-video-start').disabled = false;
        vNote('Enregistrement direct impossible sur ce navigateur : utilisez « Importer une vidéo ».');
        updatePrompterState('Caméra indisponible');
        return;
      }
      clip.recorder = recorder;
      clip.mime = recorder.mimeType || mime || 'video/webm';
      clip.chunks = [];
      recorder.ondataavailable = function (event) {
        if (event.data && event.data.size) clip.chunks.push(event.data);
      };
      recorder.onstop = finalizeRecording;
      try {
        recorder.start(1000);
      } catch (e) {
        clip.recorder = null;
        stopStream();
        $('ss-video-start').disabled = false;
        vNote('Enregistrement direct impossible : utilisez « Importer une vidéo ».');
        updatePrompterState('Caméra indisponible');
        return;
      }

      clip.recording = true;
      clip.startedAt = Date.now();
      $('ss-video-time').textContent = '00:00';
      $('ss-video-rec').hidden = false;
      $('ss-video-start').hidden = true;
      $('ss-video-stop').hidden = false;
      $('ss-video-retake').hidden = true;
      $('ss-video-upload').hidden = true;
      $('ss-video-file').hidden = true;
      clip.timer = setInterval(tickRecording, 250);
      vNote('Enregistrement en cours… parlez tranquillement.');
      if (PROMPTER.open) restartPrompter();
      updatePrompterState();
    }, function () {
      $('ss-video-start').disabled = false;
      vNote('Caméra refusée ou indisponible : autorisez la caméra, ou importez une vidéo.');
      updatePrompterState('Caméra indisponible');
    });
  }

  function tickRecording() {
    if (!clip.recording) return;
    var seconds = Math.floor((Date.now() - clip.startedAt) / 1000);
    var text = fmtTime(seconds);
    $('ss-video-time').textContent = text;
    $('ss-prompter-time').textContent = text;
    if (seconds >= MAX_CLIP) {
      stopRecording();
      vNote('Durée maximale atteinte (' + MAX_CLIP + ' s) : vérifiez la vidéo puis envoyez-la.');
    }
  }

  function stopRecording() {
    if (!clip.recording) return;
    clip.recording = false;
    if (clip.timer) { clearInterval(clip.timer); clip.timer = null; }
    $('ss-video-rec').hidden = true;
    $('ss-video-stop').hidden = true;
    $('ss-video-start').hidden = false;
    $('ss-video-start').disabled = false;
    $('ss-video-file').hidden = false;
    var preview = $('ss-video-preview');
    if (preview.srcObject) {
      try { preview.pause(); } catch (e) {}
      preview.srcObject = null;
    }
    stopStream();
    if (clip.recorder && clip.recorder.state !== 'inactive') {
      try { clip.recorder.stop(); } catch (e) { finalizeRecording(); }
    } else {
      finalizeRecording();
    }
    updatePrompterState('Vidéo prête');
    closePrompter();
    vNote('Enregistrement terminé : vérifiez la vidéo, puis envoyez-la.');
  }

  function finalizeRecording() {
    var seconds = Math.max(1, Math.round((Date.now() - clip.startedAt) / 1000));
    var type = String(clip.mime || 'video/webm').split(';')[0];
    var blob = new Blob(clip.chunks, { type: type });
    clip.chunks = [];
    clip.recorder = null;
    if (!blob.size) {
      vNote('Rien n’a été enregistré : reprenez la vidéo.');
      clearClip();
      return;
    }
    clip.blob = blob;
    clip.duration = Math.min(MAX_CLIP, seconds);
    clip.url = URL.createObjectURL(blob);
    var preview = $('ss-video-preview');
    preview.srcObject = null;
    preview.src = clip.url;
    preview.muted = false;
    preview.controls = true;
    preview.hidden = false;
    var playing = preview.play();
    if (playing && playing.catch) playing.catch(function () {});
    $('ss-video-idle').hidden = true;
    $('ss-video-upload').hidden = false;
    $('ss-video-retake').hidden = false;
    $('ss-video-input').value = '';
    vNote('Vidéo prête (' + fmtSize(blob.size) + ' · ' + fmtTime(seconds) + ') : vérifiez-la puis envoyez-la.');
  }

  function retakeVideo() {
    if (clip.recording) return;
    clearClip();
    vNote('');
  }

  function onVideoPick(event) {
    var file = event.target.files && event.target.files[0];
    if (!file) return;
    if (!/^video\//.test(file.type)) {
      vNote('Fichier non reconnu : choisissez une vidéo MP4, MOV ou WebM.');
      return;
    }
    if (file.size > 120 * 1048576) {
      vNote('Vidéo trop lourde (120 Mo au plus).');
      return;
    }
    if (clip.recording) stopRecording();
    clearClip();
    clip.blob = file;
    clip.url = URL.createObjectURL(file);
    var preview = $('ss-video-preview');
    preview.srcObject = null;
    preview.src = clip.url;
    preview.muted = false;
    preview.controls = true;
    preview.hidden = false;
    $('ss-video-idle').hidden = true;
    $('ss-video-upload').hidden = false;
    $('ss-video-retake').hidden = false;
    preview.addEventListener('loadedmetadata', function onMeta() {
      preview.removeEventListener('loadedmetadata', onMeta);
      if (isFinite(preview.duration)) clip.duration = Math.round(preview.duration);
      vNote('Vidéo importée (' + fmtSize(file.size) + ' · ' + fmtTime(clip.duration) + ') : vérifiez-la puis envoyez-la.');
    });
    vNote('Vidéo importée (' + fmtSize(file.size) + ') : vérifiez-la puis envoyez-la.');
  }

  function uploadFailed(message, button, retake) {
    button.disabled = false;
    retake.disabled = false;
    setProgress(0);
    $('ss-video-progress').hidden = true;
    vNote(message);
  }

  function sendChunks(id, chunk) {
    var total = clip.blob.size;
    var offset = 0;
    function step() {
      if (offset >= total) return Promise.resolve(true);
      var end = Math.min(total, offset + chunk);
      var fd = new FormData();
      fd.append('action', 'video_chunk');
      fd.append('id', id);
      fd.append('chunk', clip.blob.slice(offset, end), 'chunk.bin');
      return sendChunk(fd, 0).then(function (res) {
        if (!res.ok) {
          if (res.error) vNote(res.error);
          return false;
        }
        offset = end;
        var pct = Math.round(offset / total * 100);
        setProgress(pct);
        vNote('Envoi de la vidéo : ' + pct + ' % (' + fmtSize(offset) + ' sur ' + fmtSize(total) + ')');
        return step();
      });
    }
    return step();
  }

  function sendChunk(fd, attempt) {
    return fetch(ACTION, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').' }; });
      })
      .catch(function () { return { ok: false, error: 'Connexion perdue : vérifiez le réseau.' }; })
      .then(function (res) {
        if (!res.ok && attempt < 2) {
          return new Promise(function (resolve) { setTimeout(resolve, 600 * (attempt + 1)); })
            .then(function () { return sendChunk(fd, attempt + 1); });
        }
        return res;
      });
  }

  function uploadVideo() {
    if (!clip.blob) {
      vNote('Enregistrez ou importez une vidéo d’abord.');
      return;
    }
    var p = clip.program ? DATA.videoPrograms[clip.program] : null;
    if (!p) {
      vNote('Choisissez d’abord un programme vidéo.');
      return;
    }
    if (p.consent && !$('ss-video-consent').checked) {
      vNote('Cochez l’accord du client avant d’envoyer le témoignage.');
      $('ss-video-consent').focus();
      return;
    }
    var button = $('ss-video-upload');
    var retake = $('ss-video-retake');
    button.disabled = true;
    retake.disabled = true;
    setProgress(0);
    $('ss-video-progress').hidden = false;
    vNote('Préparation de l’envoi…');

    var fd = new FormData();
    fd.append('action', 'video_begin');
    fd.append('program', clip.program);
    fd.append('caption', $('ss-video-caption').value.trim());
    fd.append('script', $('ss-video-text').value.trim());
    fd.append('duration', String(Math.round(clip.duration || 0)));
    fd.append('size', String(clip.blob.size));
    if ($('ss-video-consent').checked) fd.append('consent', '1');

    post(ACTION, fd).then(function (res) {
      if (!res.ok || !res.id) {
        uploadFailed(res.error || 'L’envoi n’a pas pu démarrer.', button, retake);
        return;
      }
      var chunk = Math.max(262144, Math.min(4194304, Number(res.chunk) || 1048576));
      sendChunks(res.id, chunk).then(function (ok) {
        if (!ok) {
          uploadFailed('L’envoi s’est interrompu : vérifiez le réseau puis réessayez.', button, retake);
          return;
        }
        vNote('Vérification de la vidéo…');
        var fin = new FormData();
        fin.append('action', 'video_finish');
        fin.append('id', res.id);
        post(ACTION, fin).then(function (done) {
          if (!done.ok) {
            uploadFailed(done.error || 'La vidéo n’a pas pu être enregistrée.', button, retake);
            return;
          }
          setProgress(100);
          $('ss-video-progress').hidden = true;
          addVideoCard(done.video);
          button.disabled = false;
          retake.disabled = false;
          clearClip();
          $('ss-video-caption').value = '';
          $('ss-video-consent').checked = false;
          vNote('✓ Vidéo enregistrée dans la médiathèque de la boutique.');
        });
      });
    });
  }

  function addVideoCard(video) {
    var list = $('ss-videos-list');
    if (!list || !video) return;
    var li = el('li');
    li.dataset.video = String(video.id);
    var player = document.createElement('video');
    player.src = video.url;
    player.controls = true;
    player.preload = 'metadata';
    player.setAttribute('playsinline', '');
    var meta = el('div', 'ss-video-meta');
    meta.appendChild(el('b', null, video.caption || video.program_title || 'Vidéo'));
    meta.appendChild(el('small', null, (video.author || 'Équipe') + ' · ' + video.created_at + ' · ' + (video.duration || 0) + ' s'));
    li.appendChild(player);
    li.appendChild(meta);
    if (IS_ADMIN) {
      var remove = el('button', 'btn-small ss-ghost', 'Supprimer');
      remove.type = 'button';
      remove.dataset.videoDelete = String(video.id);
      li.appendChild(remove);
    }
    list.insertBefore(li, list.firstChild);
    $('ss-videos-empty').hidden = true;
  }

  Array.prototype.forEach.call(document.querySelectorAll('.ss-modes button'), function (b) {
    b.addEventListener('click', function () { setMode(b.dataset.mode); });
  });
  Array.prototype.forEach.call($('ss-video-programs').querySelectorAll('button'), function (b) {
    b.addEventListener('click', function () { selectVideoProgram(b.dataset.videoProgram); });
  });
  $('ss-video-text').addEventListener('input', syncPrompterButton);
  $('ss-video-ai').addEventListener('click', generateScript);
  $('ss-video-prompter-open').addEventListener('click', openPrompter);
  $('ss-video-start').addEventListener('click', startRecording);
  $('ss-video-stop').addEventListener('click', stopRecording);
  $('ss-video-retake').addEventListener('click', retakeVideo);
  $('ss-video-file').addEventListener('click', function () { $('ss-video-input').click(); });
  $('ss-video-input').addEventListener('change', onVideoPick);
  $('ss-video-upload').addEventListener('click', uploadVideo);
  $('ss-prompter-play').addEventListener('click', togglePrompter);
  $('ss-prompter-close').addEventListener('click', closePrompter);
  $('ss-prompter-slower').addEventListener('click', function () {
    PROMPTER.speed = Math.max(10, PROMPTER.speed - 6);
    prompterLabel();
  });
  $('ss-prompter-faster').addEventListener('click', function () {
    PROMPTER.speed = Math.min(70, PROMPTER.speed + 6);
    prompterLabel();
  });
  $('ss-prompter-rec').addEventListener('click', function () {
    if (clip.recording) stopRecording(); else startRecording();
  });

  $('ss-videos-list').addEventListener('click', function (event) {
    var button = event.target.closest('[data-video-delete]');
    if (!button) return;
    if (!window.confirm('Supprimer cette vidéo ? Le fichier sera aussi retiré de la médiathèque.')) return;
    button.disabled = true;
    var fd = new FormData();
    fd.append('action', 'video_delete');
    fd.append('id', button.dataset.videoDelete);
    post(ACTION, fd).then(function (res) {
      if (!res.ok) {
        button.disabled = false;
        videosNote(res.error || 'La suppression a échoué.');
        return;
      }
      var row = button.closest('li');
      if (row) row.remove();
      $('ss-videos-empty').hidden = $('ss-videos-list').children.length > 0;
      videosNote('Vidéo supprimée de la médiathèque.');
    });
  });

  window.addEventListener('beforeunload', function (event) {
    if (clip.recording || clip.blob) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // ── Événements ───────────────────────────────────────────────────────────
  Array.prototype.forEach.call($('ss-places').querySelectorAll('button'), function (b) {
    b.addEventListener('click', function () { selectPlace(b.dataset.place); });
  });
  $('ss-to-program').addEventListener('click', function () { renderProgram(); showPanel('program'); });
  $('ss-back-place').addEventListener('click', function () { showPanel('place'); });
  $('ss-start').addEventListener('click', startShooting);
  $('ss-audio-toggle').addEventListener('change', function () {
    state.audio = this.checked;
    try { localStorage.setItem('ss-audio', this.checked ? '1' : '0'); } catch (e) {}
    if (!this.checked) stopSpeaking();
  });
  $('ss-audio-play').addEventListener('click', function () {
    var p = place();
    speak('Programme : ' + p.title + '. ' + p.intro + ' Vous allez prendre ' + p.shots.length + ' photos. '
      + p.shots.map(function (s, i) { return 'Photo ' + (i + 1) + ' : ' + s.label + '.'; }).join(' '), true);
  });
  $('ss-audio-stop').addEventListener('click', stopSpeaking);
  $('ss-shot-coach').addEventListener('click', function () {
    var shot = currentShot();
    if (shot) speak(shot.coach, true);
  });

  $('ss-shot-take').addEventListener('click', function () { $('ss-shot-cam').click(); });
  $('ss-shot-lib-btn').addEventListener('click', function () { $('ss-shot-lib').click(); });
  function onPick(event) {
    var file = event.target.files && event.target.files[0];
    if (!file) return;
    if (!/^image\//.test(file.type)) { note('Fichier non reconnu : choisissez une photo.'); return; }
    if (file.size > 12 * 1048576) { note('Photo trop lourde (12 Mo au plus).'); return; }
    note('Photo en cours de contrôle…');
    setBusy(true);
    setPhoto(file).then(function () { setBusy(false); }, function () {
      setBusy(false);
      note("La photo n'a pas pu être lue.");
    });
  }
  $('ss-shot-cam').addEventListener('change', onPick);
  $('ss-shot-lib').addEventListener('change', onPick);

  $('ss-shot-again').addEventListener('click', function () { resetPhoto(); note(''); });
  $('ss-shot-skip').addEventListener('click', function () { stopSpeaking(); next(); });
  $('ss-shot-review').addEventListener('click', function () { setBusy(true); reviewPhoto(true).then(function () { setBusy(false); }); });
  $('ss-shot-enhance').addEventListener('click', function () { enhance(); });
  $('ss-caption-ai').addEventListener('click', function () { suggestCaption(); });
  $('ss-shot-publish').addEventListener('click', function () { publish(); });
  $('ss-to-done').addEventListener('click', function () { finish(); });
  $('ss-again-place').addEventListener('click', function () { refreshMine(); showPanel('place'); });
  $('ss-overlay').addEventListener('input', function () { $('ss-overlay-value').textContent = this.value + ' %'; });
  Array.prototype.forEach.call($('ss-compare').querySelectorAll('[data-keep]'), function (b) {
    b.addEventListener('click', function () { keepVersion(b.dataset.keep); });
  });

  selectPlace(state.place);
  showPanel('place');
})();
