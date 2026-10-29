// Assistant de shooting (admin/shooting-assistant.php) : première photo analysée
// par l'IA, choix de l'objet, contrôle de la qualité, programme adapté et
// coaching audio. La génération finale utilise les actions existantes de
// admin/quick-add-action.php.
(function () {
  'use strict';
  var root = document.querySelector('.sa');
  if (!root) return;

  var DATA = {};
  try { DATA = JSON.parse(document.getElementById('sa-data').textContent); } catch (e) { return; }
  var ACTION = root.dataset.action;
  var QA = root.dataset.qa;
  var CATALOG = root.dataset.catalog || '/admin/catalog.php#produit-';
  var AI = root.dataset.ai === '1';

  var state = {
    photo: null,
    photoUrl: null,
    analysis: null,
    metrics: null,
    ai: false,
    aiError: '',
    manual: 'auto',
    program: null,
    shots: [],
    current: 0,
    keepAll: false,
    audio: true,
    ref: null,
    visuals: [],
    sheet: null,
    generationDone: false
  };
  try { state.audio = localStorage.getItem('sa-audio') !== '0'; } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function clamp(n, min, max) { return Math.max(min, Math.min(max, n)); }
  function issueLabel(key) { return (DATA.issueLabels && DATA.issueLabels[key]) || key; }

  var panels = ['start', 'program', 'shots', 'finish', 'generate', 'done'];
  function showPanel(name) {
    panels.forEach(function (p) { var node = $('sa-panel-' + p); if (node) node.hidden = p !== name; });
    var step = name === 'start' ? 1 : (name === 'program' ? 2 : (name === 'generate' || name === 'done' ? 4 : 3));
    setStep(step);
    window.scrollTo(0, 0);
  }
  function setStep(n) {
    for (var i = 1; i <= 4; i++) {
      var li = $('sa-step-' + i);
      if (!li) continue;
      li.classList.toggle('is-done', i < n);
      if (i === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    }
  }

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

  function loadImage(blob) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(blob), img = new Image();
      img.onload = function () { resolve({ img: img, url: url }); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Image illisible')); };
      img.src = url;
    });
  }

  // ── Première photo ───────────────────────────────────────────────────────
  function setFirstPhoto(blob, caption) {
    state.photo = blob;
    if (state.photoUrl) URL.revokeObjectURL(state.photoUrl);
    state.photoUrl = URL.createObjectURL(blob);
    $('sa-first-img').src = state.photoUrl;
    $('sa-first-caption').textContent = caption || "Photo d'ensemble";
    $('sa-first-preview').hidden = false;
    $('sa-first-drop').hidden = true;
  }

  function firstPhoto(file) {
    if (!file) return;
    shrink(file, 1600, 0.9).then(function (blob) {
      setFirstPhoto(blob);
      analyzePhoto();
    });
  }

  $('sa-take-first').addEventListener('click', function () { $('sa-cam-first').click(); });
  $('sa-lib-first-btn').addEventListener('click', function () { $('sa-lib-first').click(); });
  $('sa-first-retake').addEventListener('click', function () { $('sa-cam-first').click(); });
  $('sa-cam-first').addEventListener('change', function () { if (this.files && this.files[0]) firstPhoto(this.files[0]); this.value = ''; });
  $('sa-lib-first').addEventListener('change', function () { if (this.files && this.files[0]) firstPhoto(this.files[0]); this.value = ''; });

  function analysisLoading(text) {
    var box = $('sa-analysis');
    box.hidden = false;
    box.className = 'sa-analysis is-loading';
    box.textContent = '';
    var wrap = el('div', 'sa-loading');
    wrap.appendChild(el('span', 'sa-spinner'));
    var body = el('div');
    body.appendChild(el('b', '', text || 'Analyse de la photo…'));
    body.appendChild(el('p', 'sa-note', "L'IA compte les objets, contrôle la lumière et reconnaît la pièce. Cela prend quelques secondes."));
    wrap.appendChild(body);
    box.appendChild(wrap);
  }

  function analysisError(message) {
    var box = $('sa-analysis');
    box.hidden = false;
    box.className = 'sa-analysis';
    box.textContent = '';
    var p = el('p', 'sa-warn', message || "L'analyse a échoué.");
    box.appendChild(p);
    var again = el('button', 'btn btn-ghost', '↺ Relancer l\'analyse');
    again.type = 'button';
    again.addEventListener('click', analyzePhoto);
    box.appendChild(again);
  }

  function analyzePhoto() {
    if (!state.photo) return;
    analysisLoading();
    var fd = new FormData();
    fd.append('action', 'analyze');
    fd.append('photo', state.photo, 'photo.jpg');
    fd.append('kind', state.manual);
    post(ACTION, fd).then(function (res) {
      if (!res.ok) { analysisError(res.error); return; }
      state.analysis = res.analysis;
      state.metrics = res.metrics;
      state.ai = !!res.ai;
      state.aiError = res.ai_error || '';
      state.program = res.program || programFor();
      state.keepAll = false;
      renderAnalysis();
      if (state.audio) speak('Analyse terminée. ' + res.analysis.summary);
    });
  }

  function meterRow(label, value) {
    var row = el('div', 'sa-meter');
    row.appendChild(el('span', 'sa-meter-label', label));
    var track = el('span', 'sa-meter-track');
    var fill = el('span', 'sa-meter-fill');
    fill.style.width = clamp(value, 0, 100) + '%';
    if (value < 45) fill.className += ' is-bad';
    else if (value < 65) fill.className += ' is-warn';
    track.appendChild(fill);
    row.appendChild(track);
    row.appendChild(el('span', 'sa-meter-value', Math.round(value) + '/100'));
    return row;
  }

  function renderAnalysis() {
    var res = { analysis: state.analysis, metrics: state.metrics, ai: state.ai, ai_error: state.aiError };
    var a = res.analysis;
    var box = $('sa-analysis');
    box.hidden = false;
    box.className = 'sa-analysis';
    box.textContent = '';

    var verdict = el('div', 'sa-verdict');
    verdict.appendChild(el('span', 'sa-pill ' + (a.ready ? 'is-good' : 'is-warn'), a.ready ? '✓ Photo prête' : '↻ À améliorer'));
    if (a.count > 1) verdict.appendChild(el('span', 'sa-pill is-warn', a.count + ' objets détectés'));
    if (a.subject && a.subject.nature_label) verdict.appendChild(el('span', 'sa-pill is-info', a.subject.nature_label + (a.subject.sub_label ? ' · ' + a.subject.sub_label : '')));
    box.appendChild(verdict);

    box.appendChild(el('p', 'sa-summary', a.summary));

    var meters = el('div', 'sa-meters');
    meters.appendChild(meterRow('Netteté', a.quality.sharpness));
    meters.appendChild(meterRow('Cadrage', a.quality.framing));
    meters.appendChild(meterRow('Lumière', a.quality.lighting));
    box.appendChild(meters);

    if (a.quality.issues && a.quality.issues.length) {
      var issues = el('div', 'sa-issues');
      a.quality.issues.forEach(function (key) { issues.appendChild(el('span', 'sa-issue', issueLabel(key))); });
      box.appendChild(issues);
    }
    if (a.quality.advice && a.quality.advice.length) {
      var advice = el('ul', 'sa-advice');
      a.quality.advice.forEach(function (line) { advice.appendChild(el('li', '', line)); });
      box.appendChild(advice);
    }

    var detected = el('p', 'sa-detected');
    if (a.subject && a.subject.nature_label) {
      detected.appendChild(document.createTextNode('Nature reconnue : '));
      detected.appendChild(el('strong', '', a.subject.nature_label + (a.subject.sub_label ? ' · ' + a.subject.sub_label : '')));
    } else {
      var autoType = state.manual === 'auto';
      var said = a.subject && a.subject.nature_raw ? a.subject.nature_raw : '';
      if (!AI) {
        detected.appendChild(document.createTextNode(autoType ? 'Analyse IA indisponible : programme générique.' : 'Analyse IA indisponible : programme selon votre choix.'));
      } else if (said) {
        detected.appendChild(document.createTextNode('Type « ' + said + ' » non reconnu. Choisissez le type de pièce : le programme s\'adaptera.'));
      } else {
        detected.appendChild(document.createTextNode(autoType ? 'Nature non reconnue : programme générique.' : 'Nature non reconnue : programme selon votre choix.'));
      }
      if (!AI) detected.appendChild(document.createTextNode(' Si plusieurs objets sont visibles, recadrez sur celui que vous vendez.'));
    }
    var prog = state.program || programFor();
    detected.appendChild(document.createTextNode(' · Programme : ' + prog.title + ' (' + prog.shots.length + ' vues).'));
    box.appendChild(detected);

    if (res.ai_error) box.appendChild(el('p', 'sa-note', res.ai_error));

    if (a.count > 1) {
      var callout = el('div', 'sa-callout');
      callout.appendChild(el('p', '', a.objects && a.objects.length
        ? a.count + ' objets distincts sont visibles. Choisissez celui que vous vendez : les autres vues seront cadrées sur lui.'
        : a.count + ' objets semblent visibles. Vous pouvez isoler celui que vous vendez, ou garder toute la photo.'));
      var actions = el('div', 'sa-callout-actions');
      var pick = el('button', 'btn btn-primary', 'Choisir l\'objet');
      pick.type = 'button';
      pick.addEventListener('click', chooseObject);
      var keep = el('button', 'btn btn-ghost', 'Garder toute la photo');
      keep.type = 'button';
      keep.addEventListener('click', function () {
        state.keepAll = true;
        callout.remove();
        var nextBtn = document.getElementById('sa-next-program');
        if (nextBtn) { nextBtn.disabled = false; nextBtn.removeAttribute('title'); }
      });
      actions.appendChild(pick);
      actions.appendChild(keep);
      callout.appendChild(actions);
      box.appendChild(callout);
    }

    if (!a.ready) box.appendChild(el('p', 'sa-note', 'Vous pouvez continuer, mais une photo nette, entière et bien éclairée fera une bien meilleure fiche.'));

    var actionsRow = el('div', 'sa-actions');
    var retake = el('button', 'btn btn-ghost', '↺ Reprendre la photo');
    retake.type = 'button';
    retake.addEventListener('click', function () { $('sa-cam-first').click(); });
    var auto = el('button', 'btn btn-ghost', '☀ Corriger la lumière');
    auto.type = 'button';
    auto.addEventListener('click', function () { autoCorrectFirst(); });
    // Même sans IA : dessiner un cadre isole l'objet quand plusieurs sont visibles.
    var cropObject = el('button', 'btn btn-ghost', '⛶ Recadrer sur un objet');
    cropObject.type = 'button';
    cropObject.addEventListener('click', chooseObject);
    actionsRow.appendChild(retake);
    actionsRow.appendChild(auto);
    actionsRow.appendChild(cropObject);
    if (AI) {
      var ia = el('button', 'btn btn-ghost', '✨ Améliorer avec l\'IA');
      ia.type = 'button';
      ia.addEventListener('click', enhanceFirst);
      actionsRow.appendChild(ia);
    }
    var next = el('button', 'btn btn-primary', 'Voir le programme');
    next.type = 'button';
    next.id = 'sa-next-program';
    next.addEventListener('click', enterProgram);
    if (a.count > 1 && !state.keepAll) { next.disabled = true; next.title = 'Choisissez un objet, ou gardez toute la photo.'; }
    actionsRow.appendChild(next);
    box.appendChild(actionsRow);
  }

  function chooseObject() {
    if (!window.studioPickObject || !state.photo) return;
    var objects = (state.analysis && state.analysis.objects) || [];
    // IA indisponible : on saute la détection (elle échouerait) et on laisse dessiner.
    var opts = objects.length ? { objects: objects } : (state.ai ? { objects: null } : { objects: [] });
    window.studioPickObject(state.photo, opts).then(function (blob) {
      if (!blob) return;
      setFirstPhoto(blob, 'Objet choisi');
      analyzePhoto();
    });
  }

  /** Correction locale gratuite : niveaux automatiques + légère correction gamma. */
  function autoCorrectFirst() {
    if (!state.photo) return;
    analysisLoading('Correction de la lumière…');
    loadImage(state.photo).then(function (o) {
      var img = o.img;
      var k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.naturalWidth * k));
      c.height = Math.max(1, Math.round(img.naturalHeight * k));
      var ctx = c.getContext('2d');
      ctx.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(o.url);
      var data = ctx.getImageData(0, 0, c.width, c.height);
      var px = data.data;
      var hist = new Array(256).fill(0);
      var sum = 0;
      for (var i = 0; i < px.length; i += 4) {
        var l = Math.round(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]);
        hist[l]++; sum += l;
      }
      var n = px.length / 4;
      var mean = sum / n;
      var spread = 0;
      for (var h = 0; h < 256; h++) spread += hist[h] * (h - mean) * (h - mean);
      var std = Math.sqrt(spread / Math.max(1, n));
      var low = 0, high = 255, acc = 0, target = n * 0.005;
      for (var v = 0; v < 256; v++) { acc += hist[v]; if (acc >= target) { low = v; break; } }
      acc = 0; target = n * 0.995;
      for (var w = 255; w >= 0; w--) { acc += hist[w]; if (acc >= target) { high = w; break; } }
      if (high - low < 24) { low = Math.max(0, low - 20); high = Math.min(255, high + 20); }
      var range = Math.max(24, high - low);
      var gamma = mean < 100 ? 0.92 : (mean > 170 ? 1.06 : 1);
      // Correction douce (35 % au plus) : niveaux automatiques sans brûler les hautes lumières.
      var strength = (mean < 100 || mean > 170 || std < 45) ? 0.35 : 0.15;
      var lut = new Array(256);
      for (var j = 0; j < 256; j++) {
        var corrected = 255 * Math.pow(clamp((j - low) / range, 0, 1), gamma);
        lut[j] = clamp(Math.round(j * (1 - strength) + corrected * strength), 0, 255);
      }
      for (var p = 0; p < px.length; p += 4) {
        px[p] = lut[px[p]]; px[p + 1] = lut[px[p + 1]]; px[p + 2] = lut[px[p + 2]];
      }
      ctx.putImageData(data, 0, 0);
      c.toBlob(function (blob) {
        if (!blob) { analysisError('La correction a échoué.'); return; }
        setFirstPhoto(blob, 'Photo corrigée (lumière)');
        analyzePhoto();
      }, 'image/jpeg', 0.9);
    }).catch(function () { analysisError('La correction a échoué.'); });
  }

  /** Retouche IA payante : Gemini corrige exposition, contraste et netteté. */
  function enhanceFirst() {
    if (!state.photo || !AI) return;
    if (!window.confirm("L'amélioration IA est payante (quelques centimes) et peut légèrement modifier la pièce. Continuer ?")) return;
    analysisLoading('Amélioration IA en cours… (30 à 60 secondes)');
    var fd = new FormData();
    fd.append('action', 'enhance');
    fd.append('photo', state.photo, 'photo.jpg');
    post(ACTION, fd).then(function (res) {
      if (!res.ok) { analysisError(res.error || "L'amélioration IA a échoué."); return; }
      fetch(res.url, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.blob(); })
        .then(function (blob) { setFirstPhoto(blob, 'Photo améliorée par l\'IA'); analyzePhoto(); })
        .catch(function () { analysisError("La photo retouchée n'a pas pu être récupérée."); });
    });
  }

  // ── Choix manuel du type de pièce ────────────────────────────────────────
  function typeLabel(type) {
    return { auto: 'Automatique', objet: 'Objet', vetement: 'Vêtement', media: 'Livre / média', chaussures: 'Chaussures', bijou: 'Bijou', mobilier: 'Mobilier' }[type] || type;
  }
  function updateTypeButtons() {
    document.querySelectorAll('#sa-types [data-type], #sa-program-types [data-type]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.type === state.manual));
    });
    $('sa-type-note').textContent = state.manual === 'auto'
      ? "Laissez « Automatique » : l'IA reconnaît la nature de la pièce et choisit le programme."
      : 'Programme adapté à votre choix manuel : ' + typeLabel(state.manual) + '.';
  }
  function bindTypeButtons() {
    document.querySelectorAll('#sa-types [data-type], #sa-program-types [data-type]').forEach(function (b) {
      b.addEventListener('click', function () {
        state.manual = b.dataset.type;
        updateTypeButtons();
        state.program = programFor();
        if (state.analysis && !$('sa-panel-start').hidden) renderAnalysis();
        if (!$('sa-panel-program').hidden) renderProgram();
      });
    });
  }
  bindTypeButtons();
  updateTypeButtons();

  function programFor() {
    if (state.manual !== 'auto') {
      var manualKey = DATA.manualPrograms[state.manual] || 'objet';
      return DATA.programs[manualKey] || DATA.programs.objet;
    }
    var nature = state.analysis && state.analysis.subject ? state.analysis.subject.nature : '';
    var key = DATA.naturePrograms[nature] || 'objet';
    return DATA.programs[key] || DATA.programs.objet;
  }

  // ── Programme ────────────────────────────────────────────────────────────
  function enterProgram() {
    state.program = programFor();
    renderProgram();
    showPanel('program');
    if (state.audio) speak(programSpeech(state.program));
  }

  function renderProgram() {
    var p = state.program || programFor();
    $('sa-program-title').textContent = p.title;
    $('sa-program-intro').textContent = p.intro;
    $('sa-program-kicker').textContent = state.analysis && state.analysis.subject && state.analysis.subject.nature_label
      ? 'Programme conseillé · ' + state.analysis.subject.nature_label
      : 'Programme conseillé';

    var chips = $('sa-program-types');
    chips.textContent = '';
    ['auto', 'objet', 'vetement', 'media', 'chaussures', 'bijou', 'mobilier'].forEach(function (type) {
      var b = el('button', '', typeLabel(type));
      b.type = 'button'; b.dataset.type = type;
      b.setAttribute('aria-pressed', String(state.manual === type));
      b.addEventListener('click', function () { state.manual = type; updateTypeButtons(); state.program = programFor(); renderProgram(); });
      chips.appendChild(b);
    });

    var tips = $('sa-general-tips');
    tips.textContent = '';
    (DATA.generalTips || []).forEach(function (t) {
      var li = el('li');
      li.appendChild(el('span', '', t.icon));
      li.appendChild(el('span', '', t.text));
      tips.appendChild(li);
    });

    var plan = $('sa-plan');
    plan.textContent = '';
    p.shots.forEach(function (s) {
      var li = el('li');
      var body = el('div');
      var b = el('b', '', s.label + ' ');
      if (!s.required) b.appendChild(el('span', 'sa-optional', '(facultatif)'));
      body.appendChild(b);
      body.appendChild(el('small', '', s.tip));
      li.appendChild(body);
      plan.appendChild(li);
    });

    $('sa-audio-toggle').checked = state.audio;
  }

  $('sa-back-start').addEventListener('click', function () { showPanel('start'); });
  $('sa-start-shots').addEventListener('click', function () { state.program = programFor(); beginShots(); });

  $('sa-audio-toggle').addEventListener('change', function () {
    state.audio = this.checked;
    try { localStorage.setItem('sa-audio', state.audio ? '1' : '0'); } catch (e) {}
    if (!state.audio) stopSpeaking();
  });

  // ── Shooting guidé ───────────────────────────────────────────────────────
  function beginShots() {
    var p = state.program || programFor();
    state.shots = p.shots.map(function (s) {
      return { key: s.key, label: s.label, tip: s.tip, coach: s.coach, required: !!s.required, blob: null, url: null, quality: null };
    });
    state.current = 0;
    renderShot();
    renderThumbs();
    showPanel('shots');
    announceShot();
  }

  function currentShot() { return state.shots[state.current]; }

  function renderShot() {
    var s = currentShot();
    if (!s) return;
    var total = state.shots.length;
    $('sa-shot-badge').textContent = 'Vue ' + (state.current + 1) + ' / ' + total + (s.required ? ' · obligatoire' : ' · facultatif');
    $('sa-shot-title').textContent = s.label;
    $('sa-shot-tip').textContent = s.tip;

    var stage = $('sa-shot-stage');
    var img = $('sa-shot-img');
    if (s.blob) {
      if (s.url) URL.revokeObjectURL(s.url);
      s.url = URL.createObjectURL(s.blob);
      img.src = s.url;
      img.alt = s.label;
      img.hidden = false;
      $('sa-shot-empty').hidden = true;
      stage.classList.add('has-photo');
      renderShotQuality(s.quality);
      $('sa-shot-next').hidden = false;
      $('sa-shot-next').textContent = state.current === total - 1 ? 'Terminer le shooting' : 'Valider et continuer';
      $('sa-shot-again').hidden = false;
      $('sa-shot-skip').hidden = true;
    } else {
      img.hidden = true; img.removeAttribute('src');
      $('sa-shot-empty').hidden = false;
      stage.classList.remove('has-photo');
      $('sa-shot-quality').hidden = true;
      $('sa-shot-next').hidden = true;
      $('sa-shot-again').hidden = true;
      $('sa-shot-skip').hidden = s.required;
    }

    var taken = state.shots.filter(function (x) { return x.blob; }).length;
    var ratio = (state.current + (s.blob ? 1 : 0)) / total;
    $('sa-progress-fill').style.width = Math.round(ratio * 100) + '%';
    $('sa-progress-text').textContent = taken + ' photo' + (taken > 1 ? 's' : '') + ' prise' + (taken > 1 ? 's' : '') + ' · vue ' + (state.current + 1) + ' sur ' + total;
    $('sa-to-finish').hidden = !allRequiredCaptured();
  }

  function renderShotQuality(q) {
    var box = $('sa-shot-quality');
    if (!q) { box.hidden = true; return; }
    box.hidden = false;
    box.textContent = '';
    box.appendChild(el('strong', '', 'Contrôle rapide :'));
    if (q.sharpness >= 45) box.appendChild(el('span', 'sa-pill is-good', 'Nette'));
    else if (q.sharpness >= 30) box.appendChild(el('span', 'sa-pill is-warn', 'Un peu floue'));
    else box.appendChild(el('span', 'sa-pill is-warn', 'Floue'));
    if (q.brightness < 24) box.appendChild(el('span', 'sa-pill is-warn', 'Trop sombre'));
    else if (q.brightness > 84) box.appendChild(el('span', 'sa-pill is-warn', 'Surexposée'));
    else box.appendChild(el('span', 'sa-pill is-good', 'Lumière correcte'));
    if (q.issues && q.issues.indexOf('contraste') >= 0) box.appendChild(el('span', 'sa-pill is-warn', 'Peu de contraste'));
  }

  /** Contrôle local rapide (canvas) : luminosité, netteté approximative, contraste. */
  function localQuality(blob) {
    return loadImage(blob).then(function (o) {
      var img = o.img;
      var k = Math.min(1, 480 / Math.max(img.naturalWidth, img.naturalHeight));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.naturalWidth * k));
      c.height = Math.max(1, Math.round(img.naturalHeight * k));
      var ctx = c.getContext('2d');
      ctx.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(o.url);
      var px = ctx.getImageData(0, 0, c.width, c.height).data;
      var w = c.width, h = c.height;
      var lum = new Float32Array(w * h);
      var sum = 0, sum2 = 0;
      for (var i = 0, p = 0; i < px.length; i += 4, p++) {
        var l = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
        lum[p] = l; sum += l; sum2 += l * l;
      }
      var n = w * h;
      var mean = sum / n;
      var std = Math.sqrt(Math.max(0, sum2 / n - mean * mean));
      var lapSum = 0, lapSum2 = 0, lapN = 0;
      for (var y = 1; y < h - 1; y++) {
        for (var x = 1; x < w - 1; x++) {
          var idx = y * w + x;
          var v = 4 * lum[idx] - lum[idx - 1] - lum[idx + 1] - lum[idx - w] - lum[idx + w];
          lapSum += v; lapSum2 += v * v; lapN++;
        }
      }
      var lapMean = lapN ? lapSum / lapN : 0;
      var lapVar = lapN ? Math.max(0, lapSum2 / lapN - lapMean * lapMean) : 0;
      var sharpness = Math.round(100 * Math.min(1, Math.sqrt(lapVar / 800)));
      var brightness = Math.round(mean / 255 * 100);
      var issues = [];
      if (sharpness < 30) issues.push('flou');
      if (brightness < 24) issues.push('sombre');
      if (brightness > 84) issues.push('surexpose');
      if (std < 12) issues.push('contraste');
      return { sharpness: sharpness, brightness: brightness, contrast: Math.round(std), issues: issues };
    });
  }

  function captureShot(file) {
    if (!file) return;
    var s = currentShot();
    $('sa-shot-quality').hidden = false;
    $('sa-shot-quality').textContent = '';
    $('sa-shot-quality').appendChild(el('span', 'sa-spinner'));
    $('sa-shot-quality').appendChild(document.createTextNode(' Contrôle de la photo…'));
    shrink(file, 1600, 0.9).then(function (blob) {
      return localQuality(blob).then(function (q) {
        s.blob = blob;
        s.quality = q;
        renderShot();
        renderThumbs();
        if (state.audio) speak(qualitySpeech(q, s));
      });
    });
  }

  $('sa-shot-take').addEventListener('click', function () { $('sa-shot-cam').click(); });
  $('sa-shot-lib-btn').addEventListener('click', function () { $('sa-shot-lib').click(); });
  $('sa-shot-again').addEventListener('click', function () { $('sa-shot-cam').click(); });
  $('sa-shot-cam').addEventListener('change', function () { if (this.files && this.files[0]) captureShot(this.files[0]); this.value = ''; });
  $('sa-shot-lib').addEventListener('change', function () { if (this.files && this.files[0]) captureShot(this.files[0]); this.value = ''; });

  $('sa-shot-next').addEventListener('click', function () {
    if (state.current >= state.shots.length - 1) { finishShooting(); return; }
    state.current++;
    renderShot(); renderThumbs(); announceShot();
  });
  $('sa-shot-skip').addEventListener('click', function () {
    if (currentShot().required) return;
    if (state.current >= state.shots.length - 1) { finishShooting(); return; }
    state.current++;
    renderShot(); renderThumbs(); announceShot();
  });

  function renderThumbs() {
    var box = $('sa-thumbs');
    box.textContent = '';
    state.shots.forEach(function (s, i) {
      var b = el('button', 'sa-thumb');
      b.type = 'button';
      b.setAttribute('aria-label', s.label + (s.blob ? ' — photo prise' : ' — à prendre'));
      if (s.blob) {
        var img = el('img'); img.alt = ''; img.src = s.url || URL.createObjectURL(s.blob); b.appendChild(img);
      } else {
        b.className += ' is-empty';
        b.appendChild(el('span', '', String(i + 1)));
      }
      var cap = el('span', '', s.label);
      b.appendChild(cap);
      if (i === state.current) b.className += ' is-current';
      b.addEventListener('click', function () { state.current = i; renderShot(); renderThumbs(); announceShot(); });
      box.appendChild(b);
    });
  }

  function allRequiredCaptured() {
    return state.shots.every(function (s) { return !s.required || s.blob; });
  }

  function finishShooting() {
    var missing = state.shots.filter(function (s) { return s.required && !s.blob; });
    if (missing.length) {
      state.current = state.shots.indexOf(missing[0]);
      renderShot(); renderThumbs(); announceShot();
      window.alert('Il manque une vue obligatoire : « ' + missing[0].label + ' ».');
      return;
    }
    renderFinish();
    showPanel('finish');
    if (state.audio) speak('Toutes les photos sont prises. Vérifiez la série, puis lancez la préparation de la fiche.');
  }

  $('sa-to-finish').addEventListener('click', finishShooting);
  $('sa-shot-audio').addEventListener('click', function () {
    var s = currentShot();
    if (s) speak(shotSpeech(s, state.current, state.shots.length), true);
  });

  // ── Récapitulatif et génération ──────────────────────────────────────────
  function renderFinish() {
    var grid = $('sa-finish-grid');
    grid.textContent = '';
    state.shots.forEach(function (s) {
      if (!s.blob) return;
      var fig = el('figure');
      var img = el('img'); img.alt = s.label; img.src = s.url || URL.createObjectURL(s.blob);
      fig.appendChild(img);
      fig.appendChild(el('figcaption', '', s.label));
      grid.appendChild(fig);
    });
    $('sa-to-finish').hidden = true;
  }

  $('sa-resume-shots').addEventListener('click', function () { renderShot(); renderThumbs(); showPanel('shots'); announceShot(); });
  $('sa-restart').addEventListener('click', function () { location.reload(); });
  $('sa-generate').addEventListener('click', generate);

  function genStep(name, st, text) {
    var li = document.querySelector('#sa-gen-list li[data-step="' + name + '"]');
    if (!li) return;
    li.dataset.state = st;
    var small = li.querySelector('small');
    if (small && text) small.textContent = text;
  }

  function generate() {
    if (!allRequiredCaptured()) { finishShooting(); return; }
    var p = state.program || programFor();
    var fd = new FormData();
    fd.append('action', 'create');
    fd.append('kind', p.kind || 'objet');
    fd.append('source', 'single');
    fd.append('notes', $('sa-notes').value || '');
    var mainSet = false;
    state.shots.forEach(function (s) {
      if (!s.blob) return;
      fd.append('photos[]', s.blob, 'photo-' + s.key + '.jpg');
      fd.append('labels[]', s.label);
      if (!mainSet) { fd.append('main', '0'); mainSet = true; }
    });
    if (!mainSet) { window.alert('Aucune photo à envoyer.'); return; }

    document.querySelectorAll('#sa-gen-list li').forEach(function (li) { li.dataset.state = ''; li.hidden = false; });
    $('sa-generate-retry').hidden = true;
    showPanel('generate');
    genStep('create', 'run');

    post(QA, fd).then(function (res) {
      if (!res.ok) {
        genStep('create', 'err', res.error || 'Envoi impossible.');
        $('sa-generate-retry').hidden = false;
        return;
      }
      state.ref = res.ref;
      genStep('create', 'ok', res.photos.length + ' photo(s) enregistrée(s) — Réf. N°' + res.ref);
      runChain(res.ref);
    });
  }

  function runChain(ref) {
    var notes = $('sa-notes').value || '';
    var chain = ['detoure', 'sheet', 'ambiance', 'angles', 'recto', 'verso'];
    var failed = [];

    function stepData(action) {
      var fd = new FormData();
      fd.append('action', action);
      fd.append('ref', ref);
      fd.append('notes', notes);
      return fd;
    }
    function next() {
      var name = chain.shift();
      if (!name) { finishJob(ref, failed); return; }
      genStep(name, 'run');
      var skipIds = [];
      (function run() {
        var fd = stepData(name);
        if (name === 'angles' && skipIds.length) fd.append('skip', skipIds.join(','));
        post(QA, fd).then(function (r) {
          if (r.ok && r.skipped) {
            var li = document.querySelector('#sa-gen-list li[data-step="' + name + '"]');
            if (li) li.hidden = true;
          } else if (r.ok) {
            var small = name === 'sheet' && r.sheet ? 'Fiche rédigée : « ' + r.sheet.name + ' »' : (name === 'angles' ? 'Toutes les vues détourées' : 'Terminé');
            genStep(name, 'ok', small);
            if (r.path) state.visuals.unshift({ path: r.path, label: r.label || '' });
            if (r.sheet) state.sheet = r.sheet;
            if (name === 'angles' && r.remaining > 0) { genStep(name, 'run', r.remaining + ' vue(s) restante(s)…'); run(); return; }
          } else {
            if (name === 'angles' && r.photo_id) {
              skipIds.push(r.photo_id);
              if (r.remaining > 0) { run(); return; }
            }
            genStep(name, 'err', r.error || 'Étape ignorée.');
            failed.push(name);
          }
          next();
        });
      })();
    }
    next();
  }

  function finishJob(ref, failed) {
    var fd = new FormData();
    fd.append('action', 'finish');
    fd.append('ref', ref);
    fd.append('failed', failed.join(','));
    post(QA, fd).then(function () { loadProduct(ref); });
  }

  function loadProduct(ref) {
    var fd = new FormData();
    fd.append('action', 'get');
    fd.append('ref', ref);
    post(QA, fd).then(function (res) {
      state.generationDone = true;
      renderVisuals(res);
      $('sa-done-title').textContent = 'Fiche prête à relire';
      $('sa-done-text').textContent = 'Réf. N°' + ref + ' — relisez le nom, le prix et la description, puis publiez si tout est correct.';
      $('sa-catalog-link').href = CATALOG + encodeURIComponent(ref);
      if (res.ok && res.product) {
        $('sa-f-name').value = res.product.name || '';
        $('sa-f-price').value = String(res.product.price || '').replace(/[^0-9.,]/g, '').replace(',', '.');
        $('sa-f-desc').value = res.product.description || '';
        $('sa-f-weight').value = res.product.weight_grams || '';
        $('sa-review').hidden = false;
      } else {
        $('sa-review').hidden = true;
      }
      showPanel('done');
      if (state.audio) speak('La fiche est prête. Relisez le nom, le prix et la description, puis enregistrez.');
    });
  }

  function renderVisuals(res) {
    var box = $('sa-visuals');
    box.textContent = '';
    var visuals = state.visuals.slice();
    if (!visuals.length && res && res.photos) {
      res.photos.forEach(function (p, i) {
        visuals.push(typeof p === 'string' ? { path: p, label: i === 0 ? 'Photo principale' : 'Photo' } : { path: p.path, label: p.label || 'Photo' });
      });
    }
    visuals.forEach(function (v) {
      var fig = el('figure');
      var img = el('img'); img.alt = v.label || ''; img.loading = 'lazy'; img.src = '/' + String(v.path).replace(/^\//, '');
      fig.appendChild(img);
      fig.appendChild(el('figcaption', '', v.label || ''));
      box.appendChild(fig);
    });
  }

  $('sa-save').addEventListener('click', function () {
    if (!state.ref) return;
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Enregistrement…';
    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('ref', state.ref);
    fd.append('name', $('sa-f-name').value);
    fd.append('price', $('sa-f-price').value);
    fd.append('weight_grams', $('sa-f-weight').value || '0');
    fd.append('description', $('sa-f-desc').value);
    if ($('sa-f-publish').checked) fd.append('publish', '1');
    post(QA, fd).then(function (res) {
      btn.disabled = false;
      btn.textContent = 'Enregistrer';
      if (!res.ok) { window.alert(res.error || 'Enregistrement impossible.'); return; }
      $('sa-review').hidden = true;
      $('sa-done-title').textContent = res.published ? 'Pièce publiée' : 'Fiche enregistrée (masquée)';
      $('sa-done-text').textContent = 'Réf. N°' + state.ref + (res.published ? ' — visible dans la boutique.' : ' — à relire puis publier depuis le catalogue.');
      if (state.audio) speak(res.published ? 'La pièce est publiée.' : 'La fiche est enregistrée, mais reste masquée.');
    });
  });

  $('sa-new').addEventListener('click', function () { location.reload(); });
  $('sa-generate-again').addEventListener('click', generate);
  $('sa-generate-back').addEventListener('click', function () {
    renderShot(); renderThumbs(); showPanel('shots'); announceShot();
  });

  // ── Coaching audio ───────────────────────────────────────────────────────
  var synth = window.speechSynthesis || null;
  var voices = [];
  function loadVoices() { try { voices = synth ? synth.getVoices() : []; } catch (e) { voices = []; } }
  if (synth) { loadVoices(); synth.onvoiceschanged = loadVoices; }
  function frenchVoice() {
    var v = voices.filter(function (x) { return /^fr[-_]FR/i.test(x.lang); })[0]
      || voices.filter(function (x) { return /^fr/i.test(x.lang); })[0];
    return v || null;
  }
  function audioStatus(text) { var n = $('sa-audio-status'); if (n) n.textContent = text || ''; }
  function stopSpeaking() {
    if (synth) { try { synth.cancel(); } catch (e) {} }
    audioStatus('');
    $('sa-audio-stop').hidden = true;
  }
  function speak(text, force) {
    if ((!state.audio && !force) || !synth || !text) return;
    try {
      synth.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'fr-FR'; u.rate = 0.98; u.pitch = 1;
      var v = frenchVoice(); if (v) u.voice = v;
      u.onstart = function () { audioStatus('🔊 Coaching audio en cours…'); $('sa-audio-stop').hidden = false; };
      u.onend = function () { audioStatus(''); $('sa-audio-stop').hidden = true; };
      u.onerror = function () { audioStatus(''); $('sa-audio-stop').hidden = true; };
      synth.speak(u);
    } catch (e) {}
  }
  $('sa-audio-play').addEventListener('click', function () { speak(programSpeech(state.program || programFor()), true); });
  $('sa-audio-stop').addEventListener('click', stopSpeaking);

  function programSpeech(p) {
    if (!p) return '';
    return 'Programme conseillé : ' + p.title + '. ' + p.intro + ' Vous allez prendre ' + p.shots.length + ' photos. '
      + p.shots.map(function (s, i) { return 'Photo ' + (i + 1) + ' : ' + s.label + '.'; }).join(' ');
  }
  function shotSpeech(s, i, total) {
    return 'Photo ' + (i + 1) + ' sur ' + total + '. ' + s.label + '. ' + s.coach;
  }
  function announceShot() {
    var s = currentShot();
    if (s && state.audio) speak(shotSpeech(s, state.current, state.shots.length));
  }
  function qualitySpeech(q, s) {
    if (!q) return '';
    var parts = [];
    if (q.issues.indexOf('flou') >= 0) parts.push('la photo manque un peu de netteté');
    if (q.issues.indexOf('sombre') >= 0) parts.push('elle est trop sombre');
    if (q.issues.indexOf('surexpose') >= 0) parts.push('elle est surexposée');
    if (!parts.length) return 'Photo nette et bien exposée. Vous pouvez continuer.';
    return 'Attention pour ' + s.label + ' : ' + parts.join(', ') + '. Vous pouvez reprendre la photo, ou continuer si cela vous convient.';
  }

  // ── Démarrage ────────────────────────────────────────────────────────────
  showPanel('start');
})();
