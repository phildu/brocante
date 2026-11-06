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
    shotsProgramKey: '',
    current: 0,
    keepAll: false,
    audio: true,
    audioAnswered: false,
    introMuted: false,
    typeAdding: null,
    mode: 'single',
    typeValidated: false,
    draft: [],
    batch: [],
    ref: null,
    visuals: [],
    sheet: null,
    failed: [],
    generationDone: false,
    stream: null,
    cameraError: '',
    cameraClosed: false,
    video: null,
    videoFrames: [],
    videoMode: '',
    videoRecorder: null,
    videoTimer: null
  };
  try {
    state.audio = localStorage.getItem('sa-audio') !== '0';
    state.audioAnswered = localStorage.getItem('sa-audio-answered') === '1';
    var savedMode = localStorage.getItem('sa-mode');
    if (savedMode === 'batch' || savedMode === 'group') state.mode = savedMode;
    state.introMuted = localStorage.getItem('sa-intro-mute') === '1';
  } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function clamp(n, min, max) { return Math.max(min, Math.min(max, n)); }
  function issueLabel(key) { return (DATA.issueLabels && DATA.issueLabels[key]) || key; }

  var panels = ['start', 'program', 'shots', 'finish', 'generate', 'done'];
  function showPanel(name) {
    panels.forEach(function (p) { var node = $('sa-panel-' + p); if (node) node.hidden = p !== name; });
    var step = name === 'start' ? 1 : (name === 'program' ? 2 : (name === 'finish' || name === 'generate' || name === 'done' ? 4 : 3));
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

  function storageGet(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
  function storageSet(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }

  // ── Première visite : questions audio et mode, explications repliables ───
  var TYPE_ICONS = {
    auto: '✨', other: '❓', objet: '📦', vetement: '👕', chaussures: '👟', accessoires: '👜',
    bijou: '💍', media: '📚', vaisselle: '🍽', deco: '🖼', mobilier: '🪑', luminaire: '💡',
    textile: '🧵', collection: '🗃', electronique: '🔌', jeux: '🧸', beaute: '💄'
  };
  var FALLBACK_TYPES = { objet: 'Objet', vetement: 'Vêtement', chaussures: 'Chaussures', media: 'Livre / média' };

  function typeLabel(type) {
    if (type === 'auto') return 'Automatique';
    if (type === 'other') return 'Autre';
    return (DATA.types && DATA.types[type]) || type;
  }
  function typeIcon(type) { return TYPE_ICONS[type] || '🏷'; }
  function manualKind() { return state.manual === 'auto' || state.manual === 'other' ? 'auto' : state.manual; }
  function typeEntries(includeAuto) {
    var entries = [];
    if (includeAuto) entries.push(['auto', typeLabel('auto')]);
    var types = DATA.types || FALLBACK_TYPES;
    Object.keys(types).forEach(function (key) { entries.push([key, types[key]]); });
    entries.push(['other', typeLabel('other')]);
    return entries;
  }

  function renderTypeChips() {
    ['sa-types', 'sa-program-types'].forEach(function (id) {
      var box = $(id);
      if (!box) return;
      box.textContent = '';
      typeEntries(true).forEach(function (entry) {
        var b = el('button', '', typeIcon(entry[0]) + ' ' + entry[1]);
        b.type = 'button';
        b.dataset.type = entry[0];
        b.setAttribute('aria-pressed', String(state.manual === entry[0]));
        b.addEventListener('click', function () { setManualType(entry[0]); });
        box.appendChild(b);
      });
    });
  }

  // ── Quel type de produit ? Saisie libre, proposition approchante, autodécouverte ──
  var TYPE_SYNONYMS = {
    vetement: ['robe', 'pantalon', 'veste', 'manteau', 'pull', 'jupe', 'chemise', 'blouson', 'costume', 'habit', 'tee shirt', 'jean', 'gilet', 'short'],
    chaussures: ['chaussure', 'basket', 'baskets', 'botte', 'bottes', 'sandale', 'escarpin', 'soulier', 'mocassin'],
    accessoires: ['sac', 'sacoche', 'ceinture', 'echarpe', 'foulard', 'chapeau', 'lunettes', 'montre', 'cravate', 'gant', 'maroquinerie'],
    bijou: ['bijoux', 'collier', 'bague', 'bracelet', 'boucle', 'broche', 'pendentif'],
    media: ['livre', 'livres', 'disque', 'vinyle', 'cd', 'dvd', 'bd', 'film', 'magazine', 'revue'],
    vaisselle: ['assiette', 'tasse', 'verre', 'couvert', 'plat', 'bol', 'theiere', 'carafe', 'service', 'casserole', 'cafetiere'],
    deco: ['cadre', 'tableau', 'miroir', 'vase', 'statuette', 'bibelot', 'affiche', 'horloge', 'pendule'],
    mobilier: ['chaise', 'table', 'fauteuil', 'armoire', 'commode', 'canape', 'etagere', 'buffet', 'tabouret', 'meuble'],
    luminaire: ['lampe', 'lustre', 'applique', 'suspension', 'lanterne'],
    textile: ['linge', 'drap', 'nappe', 'rideau', 'serviette', 'couverture', 'tapis', 'coussin', 'torchon'],
    electronique: ['radio', 'telephone', 'ordinateur', 'camera', 'appareil photo', 'electromenager', 'haut parleur', 'tele'],
    jeux: ['jouet', 'jouets', 'poupee', 'puzzle', 'lego', 'peluche', 'jeu de societe', 'console'],
    beaute: ['parfum', 'maquillage', 'creme', 'cosmetique', 'flacon']
  };

  function normText(v) {
    return String(v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function levenshtein(a, b) {
    if (a === b) return 0;
    var prev = [], cur, i, j;
    for (j = 0; j <= b.length; j++) prev[j] = j;
    for (i = 1; i <= a.length; i++) {
      cur = [i];
      for (j = 1; j <= b.length; j++) {
        cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      }
      prev = cur;
    }
    return prev[b.length];
  }
  /** { key, exact } si le mot est connu, { key, via } si une proposition approchante existe, sinon null. */
  function matchType(input) {
    var n = normText(input);
    if (n.length < 2) return null;
    var types = DATA.types || FALLBACK_TYPES;
    var terms = [];
    Object.keys(types).forEach(function (key) {
      terms.push([key, normText(key), types[key]]);
      String(types[key]).split('/').forEach(function (part) { terms.push([key, normText(part), types[key]]); });
      (TYPE_SYNONYMS[key] || []).forEach(function (w) { terms.push([key, w, w]); });
    });
    var i;
    for (i = 0; i < terms.length; i++) if (terms[i][1] === n) return { key: terms[i][0], exact: true };
    var best = null;
    for (i = 0; i < terms.length; i++) {
      var t = terms[i][1];
      if (!t) continue;
      if (n.length >= 3 && (t.indexOf(n) === 0 || n.indexOf(t) === 0 && t.length >= 4)) {
        if (!best || best.score > 0.5) best = { key: terms[i][0], via: terms[i][2], score: 0.5 };
        continue;
      }
      var limit = Math.max(1, Math.floor(n.length / 4));
      var d = levenshtein(n, t);
      if (d <= limit && (!best || d < best.score)) best = { key: terms[i][0], via: terms[i][2], score: d };
    }
    return best ? { key: best.key, via: best.via } : null;
  }

  function typeMessage(text, buttons) {
    var box = $('sa-type-msg');
    box.textContent = '';
    if (!text) { box.hidden = true; return; }
    box.appendChild(el('p', '', text));
    if (buttons && buttons.length) {
      var row = el('div', 'sa-typeask-actions');
      buttons.forEach(function (b) {
        var btn = el('button', 'btn ' + (b.primary ? 'btn-primary' : 'btn-ghost'), b.label);
        btn.type = 'button';
        btn.addEventListener('click', b.run);
        row.appendChild(btn);
      });
      box.appendChild(row);
    }
    box.hidden = false;
    if (state.audio) speak(text);
  }

  function pickType(key) {
    setManualType(key);
    $('sa-type-input').value = key === 'auto' ? '' : typeLabel(key);
    typeMessage(key === 'auto' ? "Type automatique : l'IA reconnaîtra la pièce." : 'Type retenu : ' + typeLabel(key) + '.');
  }

  /** Mot inconnu : proposer de photographier le produit, de l'ajouter à la liste et d'ouvrir l'appareil photo. */
  function proposeDiscovery(word) {
    typeMessage('« ' + word + " » n'est pas dans la liste. Photographier le produit pour que l'IA le découvre et l'ajouter aux types ?", [
      { label: '📷 Oui, photographier', primary: true, run: function () { acceptDiscovery(word); } },
      { label: 'Non, type automatique', run: function () { pickType('auto'); } }
    ]);
  }

  function acceptDiscovery(word) {
    // L'appareil photo s'ouvre tout de suite (geste de l'utilisateur) ; le type s'enregistre en même temps.
    $('sa-cam-first').click();
    var fd = new FormData();
    fd.append('action', 'add_type');
    fd.append('label', word);
    state.manual = 'other';
    state.typeAdding = post(ACTION, fd).then(function (res) {
      state.typeAdding = null;
      if (!res.ok) { typeMessage(res.error || "Le type n'a pas pu être ajouté."); return; }
      DATA.types = res.types;
      renderTypeChips();
      setManualType(res.key);
      $('sa-type-input').value = res.label;
      typeMessage('« ' + res.label + ' » est ajouté à la liste des types.');
    });
  }

  function submitTypeInput() {
    var word = $('sa-type-input').value.trim();
    if (!word) return;
    var m = matchType(word);
    if (m && m.exact) { pickType(m.key); return; }
    if (m) {
      var label = typeLabel(m.key);
      typeMessage('Vouliez-vous dire « ' + label + ' » ?', [
        { label: 'Oui, ' + label, primary: true, run: function () { pickType(m.key); } },
        { label: 'Non', run: function () { proposeDiscovery(word); } }
      ]);
      return;
    }
    proposeDiscovery(word);
  }
  $('sa-type-go').addEventListener('click', submitTypeInput);
  $('sa-type-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); submitTypeInput(); }
  });

  function setManualType(type) {
    state.manual = type;
    state.typeValidated = false;
    state.program = programFor();
    updateTypeButtons();
    if (state.analysis && !$('sa-panel-start').hidden) renderAnalysis();
    if (!$('sa-panel-program').hidden) renderProgram();
  }

  function updateTypeButtons() {
    document.querySelectorAll('#sa-types [data-type], #sa-program-types [data-type]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.type === state.manual));
    });
    var note = $('sa-type-note');
    if (note) {
      note.textContent = state.manual === 'auto'
        ? "Laissez « Automatique » : l'IA reconnaît la nature de la pièce et choisit le programme."
        : (state.manual === 'other'
          ? "« Autre » : l'IA choisit le programme d'après la photo."
          : 'Programme adapté à votre choix manuel : ' + typeLabel(state.manual) + '.');
    }
  }

  var MODE_LABELS = { single: 'article par article', batch: 'en lot, traitement après', group: "photo d'un ensemble" };
  var MODE_CONFIRM = { single: 'Un article à la fois.', batch: 'Articles en lot, à traiter plus tard.', group: "Photo d'un ensemble de produits." };
  var modeRec = null;
  var firstTexts = null;

  function newLabel() {
    if (state.mode === 'batch') return 'Photographier un autre article';
    if (state.mode === 'group') return 'Photographier un autre ensemble';
    return 'Ajouter une autre pièce';
  }

  /** Tant qu'une question de mise en route est affichée, le reste de l'étape reste en retrait. */
  function updateAsking() {
    root.classList.toggle('sa-asking', !$('sa-setup').hidden || !$('sa-mode-ask').hidden);
  }

  /** Textes de l'étape 1 selon ce qu'on photographie (une pièce, ou un ensemble de produits). */
  function applyMode() {
    var group = state.mode === 'group';
    var title = $('sa-start-title');
    var dropTitle = document.querySelector('#sa-first-drop .sa-drop-title');
    var dropHelp = document.querySelector('#sa-first-drop .sa-drop-help');
    if (!firstTexts) {
      firstTexts = {
        title: title ? title.textContent : '',
        dropTitle: dropTitle ? dropTitle.textContent : '',
        dropHelp: dropHelp ? dropHelp.textContent : ''
      };
    }
    if (title) title.textContent = group ? "La photo de l'ensemble" : firstTexts.title;
    if (dropTitle) dropTitle.textContent = group ? "Prenez la photo de l'ensemble" : firstTexts.dropTitle;
    if (dropHelp && !state.cameraError) {
      dropHelp.textContent = group
        ? 'Tous les produits visibles, bien séparés les uns des autres, avec une marge. Pas de flash.'
        : firstTexts.dropHelp;
    }
    $('sa-new').textContent = newLabel();
    $('sa-generate').textContent = state.mode === 'batch' ? 'Ajouter cet article au lot' : 'Générer la fiche';
  }

  function renderSession() {
    var box = $('sa-session');
    if (!box) return;
    box.textContent = '';
    box.appendChild(document.createTextNode('Assistance vocale : ' + (state.audio ? 'oui' : 'non')
      + ' · ' + MODE_LABELS[state.mode] + '. '));
    var change = el('button', 'btn-small', 'Changer');
    change.type = 'button';
    change.addEventListener('click', function () {
      storageSet('sa-mode-never', '0');
      askMode(true, false);
    });
    box.appendChild(change);
    box.hidden = !$('sa-mode-ask').hidden;
  }

  // ── Question « Voulez-vous photographier… » : un, deux ou trois, touché ou dit ──
  function modeSpeech() {
    return 'Voulez-vous photographier : un ou des articles, un par un ? Dites un. '
      + 'Des articles en lot, pour les traiter plus tard ? Dites deux. '
      + "Ou une photo d'un ensemble de produits, pour les traiter rapidement ? Dites trois.";
  }
  /** « un », « 2 », « trois »… (les mots les plus précis d'abord : « un lot » vaut deux). */
  function parseMode(list) {
    for (var i = 0; i < list.length; i++) {
      var t = ' ' + String(list[i]).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim() + ' ';
      if (/ (trois|3|ensemble|rapide|rapidement) /.test(t)) return 'group';
      if (/ (deux|2|lot|lots|plus tard) /.test(t)) return 'batch';
      if (/ (un|une|1|premier|premiere|article|articles|par un) /.test(t)) return 'single';
    }
    return '';
  }
  function setMicState(on) {
    var b = $('sa-mode-mic');
    if (!b) return;
    b.textContent = on ? '🎤 Je vous écoute… (toucher pour arrêter)' : '🎤 Répondre à la voix';
    $('sa-mode-ask').classList.toggle('sa-mode-ask-listening', on);
  }
  function stopModeListening() {
    if (modeRec) { try { modeRec.stop(); } catch (e) {} }
    modeRec = null;
    setMicState(false);
  }
  function listenMode() {
    if (modeRec) return;
    var heard = $('sa-mode-heard');
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { heard.textContent = "La reconnaissance vocale n'est pas disponible ici : touchez un choix."; return; }
    var rec = new SR();
    rec.lang = 'fr-FR';
    rec.continuous = false;
    rec.interimResults = false;
    rec.maxAlternatives = 3;
    heard.textContent = '🎤 Dites un, deux ou trois.';
    rec.onresult = function (event) {
      var alts = [];
      var r = event.results && event.results[0];
      for (var i = 0; r && i < r.length; i++) alts.push(r[i].transcript);
      var mode = parseMode(alts);
      if (mode) { answerMode(mode); return; }
      heard.textContent = 'Entendu : « ' + (alts[0] || '') + ' » — pas compris. Dites un, deux ou trois, ou touchez un choix.';
    };
    rec.onerror = function (event) {
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') heard.textContent = 'Micro refusé : touchez un choix.';
      else if (event.error === 'no-speech') heard.textContent = "Je n'ai rien entendu. Touchez le micro pour réessayer, ou touchez un choix.";
    };
    rec.onend = function () { modeRec = null; setMicState(false); };
    try { rec.start(); modeRec = rec; setMicState(true); } catch (e) { modeRec = null; setMicState(false); }
  }

  function speakModeQuestion() {
    if (!state.audio || $('sa-mode-ask').hidden) return;
    speak(modeSpeech(), true, function () { if (!$('sa-mode-ask').hidden) listenMode(); });
  }

  /** quiet : pas de voix ni de micro (chargement de la page : le navigateur exige un geste de l'utilisateur). */
  function askMode(force, quiet) {
    if (!force && storageGet('sa-mode-never') === '1') {
      $('sa-mode-ask').hidden = true;
      applyMode();
      updateAsking();
      return;
    }
    stopModeListening();
    $('sa-mode-heard').textContent = '';
    $('sa-mode-never').checked = false;
    $('sa-mode-ask').hidden = false;
    $('sa-session').hidden = true;
    document.querySelectorAll('[data-mode-answer]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.modeAnswer === state.mode));
    });
    updateAsking();
    if (!quiet) speakModeQuestion();
  }

  function answerMode(mode) {
    if (!MODE_LABELS[mode]) return;
    stopModeListening();
    stopSpeaking();
    state.mode = mode;
    storageSet('sa-mode', mode);
    if ($('sa-mode-never').checked) storageSet('sa-mode-never', '1');
    $('sa-mode-ask').hidden = true;
    applyMode();
    renderSession();
    updateAsking();
    if (state.audio) speak(MODE_CONFIRM[mode]);
  }

  // ── Présentation vocale : on peut ne plus l'écouter dorénavant ──────────
  function offerIntroMute() {
    var wrap = $('sa-intro-mute-wrap');
    if (!wrap) return;
    $('sa-intro-mute').checked = state.introMuted;
    wrap.hidden = false;
  }
  function playIntro(then) {
    speak(introSpeech(), true, function () {
      offerIntroMute();
      if (then) then();
    });
  }
  $('sa-intro-mute').addEventListener('change', function () {
    state.introMuted = this.checked;
    storageSet('sa-intro-mute', this.checked ? '1' : '0');
  });

  function completeSetupIfReady() {
    if (storageGet('sa-audio-answered') !== '1') return;
    storageSet('sa-setup', '1');
    storageSet('sa-intro-seen', '1');
    $('sa-setup').hidden = true;
    var intro = $('sa-intro-fold');
    if (intro) intro.open = false;
    renderSession();
    updateAsking();
  }

  var setupDone = storageGet('sa-setup') === '1';
  $('sa-setup').hidden = setupDone;
  $('sa-mode-ask').hidden = true;
  applyMode();
  if (setupDone) { renderSession(); askMode(false, true); } else updateAsking();
  $('sa-intro-fold').open = storageGet('sa-intro-seen') !== '1';
  $('sa-first-fold').open = storageGet('sa-first-seen') !== '1';

  document.querySelectorAll('[data-audio-answer]').forEach(function (b) {
    b.addEventListener('click', function () {
      setAudio(this.dataset.audioAnswer === '1');
      state.audioAnswered = true;
      storageSet('sa-audio-answered', '1');
      completeSetupIfReady();
      // Présentation d'abord (sauf si on a choisi de ne plus l'écouter), puis la question.
      var ask = function () { askMode(false, false); };
      if (state.audio && !state.introMuted) playIntro(ask);
      else ask();
    });
  });
  document.querySelectorAll('[data-mode-answer]').forEach(function (b) {
    b.addEventListener('click', function () { answerMode(this.dataset.modeAnswer); });
  });
  $('sa-mode-mic').addEventListener('click', function () {
    if (modeRec) stopModeListening(); else { stopSpeaking(); listenMode(); }
  });

  function markExplanationsSeen() {
    storageSet('sa-intro-seen', '1');
    storageSet('sa-first-seen', '1');
    var intro = $('sa-intro-fold');
    var first = $('sa-first-fold');
    if (intro) intro.open = false;
    if (first) first.open = false;
  }

  function renderDraft() {
    var box = $('sa-draft');
    if (!box) return;
    box.textContent = '';
    state.draft.forEach(function (item, i) {
      box.appendChild(el('span', 'sa-draft-item', '✓ ' + item.label + (state.mode === 'batch' ? ' · article ' + (i + 1) : '')));
    });
    box.hidden = !state.draft.length;
  }

  function currentTypeLabel() {
    if (state.manual !== 'auto' && state.manual !== 'other') return typeLabel(state.manual);
    var subject = state.analysis && state.analysis.subject;
    if (subject && subject.nature_label) return subject.nature_label + (subject.sub_label ? ' · ' + subject.sub_label : '');
    return 'Type à préciser';
  }

  function validateCurrentType() {
    state.typeValidated = true;
    state.draft = [{ label: currentTypeLabel() }];
    renderDraft();
    markExplanationsSeen();
    state.program = programFor();
    beginShots();
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
    state.typeValidated = false;
    state.draft = [];
    renderDraft();
    if (state.photoUrl) URL.revokeObjectURL(state.photoUrl);
    state.photoUrl = URL.createObjectURL(blob);
    $('sa-first-img').src = state.photoUrl;
    $('sa-first-caption').textContent = caption || "Photo d'ensemble";
    $('sa-first-preview').hidden = false;
    $('sa-first-drop').hidden = true;
    $('sa-first-live').hidden = true;
    $('sa-first-drop').classList.remove('is-camera-open');
  }

  function firstPhoto(file) {
    if (!file) return;
    shrink(file, 1600, 0.9).then(function (blob) {
      setFirstPhoto(blob);
      analyzePhoto();
    });
  }

  // ── Caméra live (repli sur l'appareil photo natif ou la galerie) ────────
  function cameraSupported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }
  function ensureStream() {
    if (state.stream) return Promise.resolve(true);
    if (!cameraSupported()) return Promise.resolve(false);
    return new Promise(function (resolve) {
      var settled = false;
      // Certains navigateurs laissent la demande en attente : on rend la main pour proposer la galerie.
      var timer = setTimeout(function () {
        if (settled) return;
        settled = true;
        state.cameraError = 'Caméra indisponible : utilisez la galerie.';
        resolve(false);
      }, 10000);
      navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
      }).then(function (stream) {
        clearTimeout(timer);
        if (settled) {
          stream.getTracks().forEach(function (track) { try { track.stop(); } catch (e) {} });
          return;
        }
        settled = true;
        state.stream = stream;
        state.cameraError = '';
        resolve(true);
      }).catch(function (err) {
        clearTimeout(timer);
        if (settled) return;
        settled = true;
        state.cameraError = err && err.name === 'NotAllowedError'
          ? 'Caméra refusée : autorisez-la dans le navigateur, ou utilisez la galerie.'
          : 'Caméra indisponible : utilisez la galerie.';
        resolve(false);
      });
    });
  }
  function attachStream(video) {
    if (!video || !state.stream) return;
    if (video.srcObject !== state.stream) video.srcObject = state.stream;
    var playing = video.play();
    if (playing && playing.catch) playing.catch(function () {});
  }
  function stopLiveCam() {
    if (state.stream) {
      state.stream.getTracks().forEach(function (track) { try { track.stop(); } catch (e) {} });
      state.stream = null;
    }
    ['sa-first-video', 'sa-live-video', 'sa-video-preview'].forEach(function (id) {
      var v = $(id);
      if (v) v.srcObject = null;
    });
    $('sa-first-live').hidden = true;
    $('sa-live').hidden = true;
    $('sa-shot-stage').classList.remove('is-live');
    var drop = $('sa-first-drop');
    if (drop) drop.classList.remove('is-camera-open');
  }
  function captureFromVideo(video) {
    return new Promise(function (resolve) {
      var w = video && video.videoWidth, h = video && video.videoHeight;
      if (!w || !h) { resolve(null); return; }
      var c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(video, 0, 0, w, h);
      c.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', 0.9);
    });
  }

  /** Téléphone ou tablette : l'appareil photo de l'appareil (réglages standard) plutôt qu'une fenêtre dans la page. */
  function preferNativeCamera() {
    try { return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); } catch (e) { return false; }
  }
  function openFirstCam() {
    if (!cameraSupported() || preferNativeCamera()) { $('sa-cam-first').click(); return; }
    ensureStream().then(function (ok) {
      if (!ok) {
        var help = document.querySelector('#sa-first-drop .sa-drop-help');
        if (help) help.textContent = state.cameraError;
        return;
      }
      $('sa-first-live').hidden = false;
      $('sa-first-preview').hidden = true;
      $('sa-first-drop').classList.add('is-camera-open');
      attachStream($('sa-first-video'));
    });
  }
  function openShotCam() {
    if (!cameraSupported()) { $('sa-shot-cam').click(); return; }
    ensureStream().then(function (ok) {
      if (!ok) {
        $('sa-shot-quality').hidden = false;
        $('sa-shot-quality').textContent = state.cameraError;
        return;
      }
      state.cameraClosed = false;
      $('sa-live').hidden = false;
      $('sa-shot-empty').hidden = true;
      $('sa-shot-stage').classList.add('is-live');
      attachStream($('sa-live-video'));
    });
  }

  $('sa-take-first').addEventListener('click', function () { openFirstCam(); });
  $('sa-lib-first-btn').addEventListener('click', function () { $('sa-lib-first').click(); });
  $('sa-first-retake').addEventListener('click', function () { openFirstCam(); });
  $('sa-first-lib-again').addEventListener('click', function () { $('sa-lib-first').click(); });
  $('sa-cam-first').addEventListener('change', function () { if (this.files && this.files[0]) firstPhoto(this.files[0]); this.value = ''; });
  $('sa-lib-first').addEventListener('change', function () { if (this.files && this.files[0]) firstPhoto(this.files[0]); this.value = ''; });
  $('sa-first-capture').addEventListener('click', function () {
    captureFromVideo($('sa-first-video')).then(function (blob) {
      if (blob) firstPhoto(blob);
      else if ($('sa-first-live') && !$('sa-first-live').hidden) {
        var help = document.querySelector('#sa-first-drop .sa-drop-help');
        if (help) help.textContent = 'La caméra démarre encore : patientez une seconde, puis reprenez.';
      }
    });
  });
  $('sa-first-close').addEventListener('click', function () {
    stopLiveCam();
    $('sa-first-live').hidden = true;
    if (state.photo) $('sa-first-preview').hidden = false;
    else $('sa-first-drop').hidden = false;
  });
  window.addEventListener('pagehide', stopLiveCam);

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
    // Un type tout juste ajouté doit être enregistré avant que l'analyse le reçoive.
    (state.typeAdding || Promise.resolve()).then(function () {
      var fd = new FormData();
      fd.append('action', 'analyze');
      fd.append('photo', state.photo, 'photo.jpg');
      fd.append('kind', state.manual);
      return post(ACTION, fd);
    }).then(function (res) {
      if (!res.ok) { analysisError(res.error); return; }
      state.analysis = res.analysis;
      state.metrics = res.metrics;
      state.ai = !!res.ai;
      state.aiError = res.ai_error || '';
      state.program = res.program || programFor();
      state.keepAll = false;
      renderAnalysis();
      markExplanationsSeen();
      if (state.audio) speak(analysisSpeech(res.analysis));
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

    if (state.mode === 'group') { renderGroupActions(box, a); return; }

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

    // Validation du type : la reconnaissance automatique est proposée, jamais imposée.
    var canProceed = !(a.count > 1 && !state.keepAll);
    var typeBox = el('div', 'sa-type-validation');
    var manualChosen = state.manual !== 'auto' && state.manual !== 'other';
    var recognized = !manualChosen && !!(a.subject && a.subject.nature_label);
    var label = currentTypeLabel();
    var intro = manualChosen ? 'Type choisi : ' : (recognized ? 'Type reconnu : ' : 'Type non reconnu : ');
    typeBox.appendChild(el('p', '', intro));
    typeBox.lastChild.appendChild(el('strong', '', label));
    typeBox.appendChild(el('p', 'sa-note', manualChosen
      ? 'Le programme suit votre choix. Vérifiez qu\'il correspond bien à la pièce.'
      : (recognized
        ? 'Vérifiez que le programme correspond bien à la pièce.'
        : 'Choisissez un type ci-dessus, ou continuez avec le programme générique.')));
    var typeActions = el('div', 'sa-actions');
    var yes = el('button', 'btn btn-primary', manualChosen
      ? '✓ Continuer avec ' + label
      : (recognized ? '✓ C\'est bien, ouvrir la caméra' : '✓ Continuer avec ce type'));
    yes.type = 'button';
    yes.disabled = !canProceed;
    if (!canProceed) yes.title = 'Choisissez un objet, ou gardez toute la photo.';
    yes.addEventListener('click', validateCurrentType);
    var change = el('button', 'btn btn-ghost', 'Changer le type');
    change.type = 'button';
    change.addEventListener('click', function () {
      var chips = $('sa-types');
      if (chips) chips.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    typeActions.appendChild(yes);
    typeActions.appendChild(change);
    typeBox.appendChild(typeActions);
    box.appendChild(typeBox);

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
        document.querySelectorAll('#sa-analysis .sa-type-validation .btn-primary').forEach(function (b) {
          b.disabled = false;
          b.removeAttribute('title');
        });
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
    retake.addEventListener('click', function () { openFirstCam(); });
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
    var next = el('button', 'btn btn-ghost', 'Voir le programme');
    next.type = 'button';
    next.id = 'sa-next-program';
    next.addEventListener('click', enterProgram);
    if (a.count > 1 && !state.keepAll) { next.disabled = true; next.title = 'Choisissez un objet, ou gardez toute la photo.'; }
    actionsRow.appendChild(next);
    box.appendChild(actionsRow);
  }

  // ── Photo d'un ensemble : un article par objet repéré, à traiter dans le Studio ──
  function cropBlob(blob, box) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(blob), img = new Image();
      img.onload = function () {
        var W = img.naturalWidth, H = img.naturalHeight, m = 0.05;
        var x0 = Math.max(0, box[0] - box[2] * m), y0 = Math.max(0, box[1] - box[3] * m);
        var x1 = Math.min(1, box[0] + box[2] * (1 + m)), y1 = Math.min(1, box[1] + box[3] * (1 + m));
        var sx = Math.round(x0 * W), sy = Math.round(y0 * H);
        var sw = Math.max(1, Math.round((x1 - x0) * W)), sh = Math.max(1, Math.round((y1 - y0) * H));
        var k = Math.min(1, 1600 / Math.max(sw, sh));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(sw * k));
        c.height = Math.max(1, Math.round(sh * k));
        c.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || blob); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(blob); };
      img.src = url;
    });
  }

  function groupItems() {
    var objs = ((state.analysis && state.analysis.objects) || []).filter(function (o) { return o && o.box; });
    return objs.length >= 2 ? objs : [{ label: 'Ensemble', box: null }];
  }

  function renderGroupActions(box, a) {
    var items = groupItems();
    var split = items.length >= 2 || (items[0] && items[0].box);
    var callout = el('div', 'sa-callout');
    if (split) {
      callout.appendChild(el('p', '', items.length + ' produits repérés : ' + items.map(function (o) { return o.label; }).join(', ')
        + '. Chacun devient un article à part, mis de côté pour le Studio.'));
    } else {
      callout.appendChild(el('p', '', "Un seul produit repéré : la photo entière devient un article, mis de côté pour le Studio."
        + (AI ? '' : " (Sans IA, l'ensemble n'est pas découpé.)")));
    }
    var actions = el('div', 'sa-actions');
    var go = el('button', 'btn btn-primary', split ? '✓ Créer ' + items.length + ' articles' : '✓ Créer l\'article');
    go.type = 'button';
    go.addEventListener('click', groupProcess);
    var retake = el('button', 'btn btn-ghost', '↺ Reprendre la photo');
    retake.type = 'button';
    retake.addEventListener('click', function () { openFirstCam(); });
    var auto = el('button', 'btn btn-ghost', '☀ Corriger la lumière');
    auto.type = 'button';
    auto.addEventListener('click', autoCorrectFirst);
    actions.appendChild(go);
    actions.appendChild(retake);
    actions.appendChild(auto);
    callout.appendChild(actions);
    box.appendChild(callout);
    if (a.ai_error) box.appendChild(el('p', 'sa-note', a.ai_error));
  }

  function groupProcess() {
    if (!state.photo) return;
    var items = groupItems();
    var kind = (state.program || programFor()).kind || 'objet';
    var created = [];
    var lastError = '';
    var i = 0;
    function next() {
      if (i >= items.length) { finishGroup(created, items.length - created.length, lastError); return; }
      var it = items[i++];
      analysisLoading('Article ' + i + ' sur ' + items.length + '…');
      (it.box ? cropBlob(state.photo, it.box) : Promise.resolve(state.photo)).then(function (blob) {
        var fd = new FormData();
        fd.append('action', 'create');
        fd.append('kind', kind);
        fd.append('source', 'batch');
        fd.append('notes', it.box ? it.label : '');
        fd.append('main', '0');
        fd.append('photos[]', blob, 'photo-ensemble-' + i + '.jpg');
        fd.append('labels[]', it.label);
        return post(QA, fd);
      }).then(function (res) {
        if (res.ok) created.push({ ref: res.ref, photo: res.photos && res.photos[0], label: it.label });
        else lastError = res.error || 'Envoi impossible.';
        next();
      });
    }
    next();
  }

  function finishGroup(created, failed, lastError) {
    if (!created.length) { analysisError(lastError || "Aucun article n'a pu être créé."); return; }
    state.batch = state.batch.concat(created);
    state.draft = [];
    renderDraft();
    renderBatch();
    $('sa-visuals').textContent = '';
    $('sa-review').hidden = true;
    $('sa-catalog-link').hidden = true;
    $('sa-batch-studio').hidden = false;
    $('sa-new').textContent = newLabel();
    $('sa-done-title').textContent = created.length + ' article' + (created.length > 1 ? 's' : '') + ' ajouté' + (created.length > 1 ? 's' : '') + ' au lot';
    $('sa-done-text').textContent = state.batch.length + ' article' + (state.batch.length > 1 ? 's' : '')
      + " en attente. L'IA préparera les fiches dans le Studio, quand vous aurez terminé."
      + (failed ? ' ' + failed + ' n\'a pas pu être créé : ' + lastError : '');
    showPanel('done');
    if (state.audio) speak(created.length + ' article' + (created.length > 1 ? 's' : '') + ' ajouté' + (created.length > 1 ? 's' : '') + ' au lot. Vous pouvez photographier un autre ensemble, ou ouvrir le Studio.');
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
  function autoCorrectBlob(blob) {
    return loadImage(blob).then(function (o) {
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
      return new Promise(function (resolve) {
        c.toBlob(function (result) { resolve(result); }, 'image/jpeg', 0.9);
      });
    });
  }

  function autoCorrectFirst() {
    if (!state.photo) return;
    analysisLoading('Correction de la lumière…');
    autoCorrectBlob(state.photo).then(function (blob) {
      if (!blob) { analysisError('La correction a échoué.'); return; }
      setFirstPhoto(blob, 'Photo corrigée (lumière)');
      analyzePhoto();
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
  renderTypeChips();
  updateTypeButtons();

  function programFor() {
    if (state.manual !== 'auto' && state.manual !== 'other') {
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
  }

  function renderProgram() {
    var p = state.program || programFor();
    $('sa-program-title').textContent = p.title;
    $('sa-program-intro').textContent = p.intro;
    var manualChosen = state.manual !== 'auto' && state.manual !== 'other';
    var heading = manualChosen
      ? typeLabel(state.manual)
      : (state.analysis && state.analysis.subject && state.analysis.subject.nature_label ? state.analysis.subject.nature_label : '');
    $('sa-program-kicker').textContent = heading ? 'Programme conseillé · ' + heading : 'Programme conseillé';

    renderTypeChips();

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

    syncAudioToggles();
  }

  $('sa-back-start').addEventListener('click', function () { showPanel('start'); });
  $('sa-start-shots').addEventListener('click', function () { state.program = programFor(); beginShots(); });
  $('sa-stop-program').addEventListener('click', stopShooting);
  $('sa-stop-shooting').addEventListener('click', stopShooting);
  $('sa-view-program').addEventListener('click', function () {
    state.program = state.program || programFor();
    renderProgram();
    showPanel('program');
  });
  $('sa-shot-audio-off').addEventListener('click', function () { setAudio(false); });

  function stopShooting() {
    if (!window.confirm('Arrêter la séance ? Les photos déjà prises sont conservées.')) return;
    stopSpeaking();
    stopVoiceRecognition();
    stopLiveCam();
    if (state.shots.some(function (s) { return s.blob; })) {
      renderFinish();
      showPanel('finish');
      if (state.audio) speak('Séance arrêtée. Vérifiez les photos déjà prises.');
    } else {
      showPanel('start');
    }
  }

  function syncAudioToggles() {
    ['sa-audio-first', 'sa-audio-toggle'].forEach(function (id) {
      var node = $(id);
      if (node) node.checked = state.audio;
    });
  }
  function setAudio(on) {
    state.audio = !!on;
    try { localStorage.setItem('sa-audio', state.audio ? '1' : '0'); } catch (e) {}
    syncAudioToggles();
    if (!state.audio) stopSpeaking();
  }
  ['sa-audio-first', 'sa-audio-toggle'].forEach(function (id) {
    var node = $(id);
    if (!node) return;
    node.addEventListener('change', function () {
      setAudio(this.checked);
      if (state.audio && !$('sa-panel-start').hidden) speak(audioOnSpeech(), true);
    });
  });
  $('sa-audio-first-play').addEventListener('click', function () { playIntro(); });

  // ── Shooting guidé ───────────────────────────────────────────────────────
  function beginShots() {
    var p = state.program || programFor();
    var programKey = p.key || p.title;
    if (state.shots.length && state.shotsProgramKey === programKey) {
      showPanel('shots');
      if (!state.stream && !state.cameraClosed) openShotCam();
      renderShot();
      renderThumbs();
      announceShot();
      return;
    }
    if (state.shots.some(function (s) { return s.blob; })) {
      if (!window.confirm('Changer de programme effacera les photos déjà prises. Continuer ?')) {
        showPanel('shots');
        return;
      }
    }
    state.shots = p.shots.map(function (s) {
      return { key: s.key, label: s.label, tip: s.tip, coach: s.coach, required: !!s.required, blob: null, url: null, quality: null };
    });
    state.shotsProgramKey = programKey;
    state.current = 0;
    renderShot();
    renderThumbs();
    showPanel('shots');
    openShotCam();
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
    var live = $('sa-live');
    if (s.blob) {
      if (s.url) URL.revokeObjectURL(s.url);
      s.url = URL.createObjectURL(s.blob);
      img.src = s.url;
      img.alt = s.label;
      img.hidden = false;
      live.hidden = true;
      stage.classList.remove('is-live');
      $('sa-shot-empty').hidden = true;
      stage.classList.add('has-photo');
      renderShotQuality(s.quality);
      showQualityActions(s.quality);
      $('sa-shot-skip').hidden = true;
    } else {
      img.hidden = true; img.removeAttribute('src');
      stage.classList.remove('has-photo');
      $('sa-shot-quality').hidden = true;
      $('sa-shot-next').hidden = true;
      $('sa-shot-again').hidden = true;
      $('sa-shot-correct').hidden = true;
      $('sa-shot-enhance').hidden = true;
      $('sa-shot-skip').hidden = s.required;
      if (state.stream && !state.cameraClosed) {
        live.hidden = false;
        $('sa-shot-empty').hidden = true;
        stage.classList.add('is-live');
        attachStream($('sa-live-video'));
      } else {
        live.hidden = true;
        stage.classList.remove('is-live');
        $('sa-shot-empty').hidden = false;
      }
    }

    var taken = state.shots.filter(function (x) { return x.blob; }).length;
    var ratio = (state.current + (s.blob ? 1 : 0)) / total;
    $('sa-progress-fill').style.width = Math.round(ratio * 100) + '%';
    $('sa-progress-text').textContent = taken + ' photo' + (taken > 1 ? 's' : '') + ' prise' + (taken > 1 ? 's' : '') + ' · vue ' + (state.current + 1) + ' sur ' + total;
    $('sa-to-finish').hidden = !allRequiredCaptured();
  }

  function qualityBad(q) {
    return !!(q && q.issues && q.issues.length);
  }

  function showQualityActions(q) {
    var bad = qualityBad(q);
    $('sa-shot-next').hidden = false;
    $('sa-shot-next').textContent = state.current === state.shots.length - 1 ? 'Terminer le shooting' : 'Valider et continuer';
    $('sa-shot-again').hidden = false;
    $('sa-shot-correct').hidden = !bad;
    $('sa-shot-enhance').hidden = !(bad && AI);
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
    if (q.issues && q.issues.length) {
      box.appendChild(el('span', 'sa-quality-hint', AI
        ? 'Corrigez avec l\'IA, reprenez, ou continuez.'
        : 'Reprenez, ou continuez.'));
    }
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
    if (!s) return;
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

  function autoCorrectShot() {
    var s = currentShot();
    if (!s || !s.blob) return;
    $('sa-shot-quality').hidden = false;
    $('sa-shot-quality').textContent = 'Correction de la lumière…';
    autoCorrectBlob(s.blob).then(function (blob) {
      if (!blob) throw new Error();
      return shrink(blob, 1600, 0.9);
    }).then(function (blob) {
      return localQuality(blob).then(function (q) {
        s.blob = blob;
        s.quality = q;
        renderShot();
        renderThumbs();
        if (state.audio) speak('Lumière corrigée. ' + qualitySpeech(q, s));
      });
    }).catch(function () {
      $('sa-shot-quality').hidden = false;
      $('sa-shot-quality').textContent = 'La correction a échoué.';
    });
  }

  function enhanceShot() {
    var s = currentShot();
    if (!s || !s.blob || !AI) return;
    if (!window.confirm("L'amélioration IA est payante (quelques centimes) et peut légèrement modifier la pièce. Continuer ?")) return;
    $('sa-shot-quality').hidden = false;
    $('sa-shot-quality').textContent = 'Amélioration IA en cours… (30 à 60 secondes)';
    var fd = new FormData();
    fd.append('action', 'enhance');
    fd.append('photo', s.blob, 'photo.jpg');
    post(ACTION, fd).then(function (res) {
      if (!res.ok) {
        $('sa-shot-quality').hidden = false;
        $('sa-shot-quality').textContent = res.error || "L'amélioration IA a échoué.";
        return;
      }
      fetch(res.url, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.blob(); })
        .then(function (blob) { return shrink(blob, 1600, 0.9); })
        .then(function (blob) {
          return localQuality(blob).then(function (q) {
            s.blob = blob;
            s.quality = q;
            renderShot();
            renderThumbs();
            if (state.audio) speak('Photo corrigée par l\'IA. ' + qualitySpeech(q, s));
          });
        })
        .catch(function () {
          $('sa-shot-quality').hidden = false;
          $('sa-shot-quality').textContent = "La photo retouchée n'a pas pu être récupérée.";
        });
    });
  }

  $('sa-shot-take').addEventListener('click', function () { openShotCam(); });
  $('sa-shot-lib-btn').addEventListener('click', function () { $('sa-shot-lib').click(); });
  $('sa-shot-again').addEventListener('click', function () { openShotCam(); });
  $('sa-shot-correct').addEventListener('click', autoCorrectShot);
  $('sa-shot-enhance').addEventListener('click', enhanceShot);
  $('sa-live-capture').addEventListener('click', function () {
    captureFromVideo($('sa-live-video')).then(function (blob) {
      if (blob) captureShot(blob);
      else {
        $('sa-shot-quality').hidden = false;
        $('sa-shot-quality').textContent = 'La caméra démarre encore : patientez une seconde, puis reprenez.';
      }
    });
  });
  $('sa-live-close').addEventListener('click', function () {
    state.cameraClosed = true;
    stopLiveCam();
    renderShot();
  });
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
    var batch = state.mode === 'batch';
    $('sa-finish-mode').textContent = batch
      ? "Mode lot : cet article sera mis de côté, et l'IA préparera toutes les fiches plus tard dans le Studio."
      : "";
    $('sa-generate').textContent = batch ? 'Ajouter cet article au lot' : 'Générer la fiche';
    $('sa-to-finish').hidden = true;
  }

  // ── Vidéo de l'objet : rotation ou tour, continu ou image par image ─────
  function videoHelp(mode) {
    if (!mode) return '';
    var rotate = mode.indexOf('rotate') === 0;
    var frame = /-frame$/.test(mode);
    var base = rotate
      ? "Posez l'objet sur un plateau ou une feuille, puis faites-le tourner lentement sur lui-même. Gardez le téléphone fixe."
      : "Gardez l'objet immobile et tournez autour de lui, à la même distance, sans changer de hauteur.";
    if (frame) base += ' Prenez une image à chaque quart de tour : la vidéo sera assemblée à la fin.';
    else base += ' Enregistrez 10 à 20 secondes, puis arrêtez.';
    return base;
  }

  function pickVideoMime() {
    if (!window.MediaRecorder) return '';
    var options = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'];
    for (var i = 0; i < options.length; i++) {
      try { if (MediaRecorder.isTypeSupported(options[i])) return options[i]; } catch (e) {}
    }
    return '';
  }

  function resetVideoResult() {
    var result = $('sa-video-result');
    if (result.src) URL.revokeObjectURL(result.src);
    result.hidden = true;
    result.removeAttribute('src');
  }

  function chooseVideoMode(mode) {
    if (state.videoRecorder && state.videoRecorder.state === 'recording') {
      $('sa-video-status').textContent = 'Arrêtez l\'enregistrement avant de changer de mode.';
      return;
    }
    state.videoMode = mode;
    state.video = null;
    state.videoFrames = [];
    resetVideoResult();
    document.querySelectorAll('#sa-video-modes [data-video-mode]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.videoMode === mode));
    });
    $('sa-video-help').textContent = videoHelp(mode);
    $('sa-video-stage').hidden = false;
    $('sa-video-start').hidden = !/-continuous$/.test(mode);
    $('sa-video-frame').hidden = !/-frame$/.test(mode);
    $('sa-video-finish').hidden = true;
    $('sa-video-stop').hidden = true;
    $('sa-video-status').textContent = 'Ouverture de la caméra…';
    ensureStream().then(function (ok) {
      if (!ok) { $('sa-video-status').textContent = state.cameraError; return; }
      attachStream($('sa-video-preview'));
      $('sa-video-status').textContent = /-continuous$/.test(mode) ? 'Prêt : lancez l\'enregistrement.' : 'Prenez la première image.';
    });
    if (state.audio) speak(videoHelp(mode));
  }

  function startVideoRecording() {
    if (!state.stream) {
      ensureStream().then(function (ok) {
        if (ok) startVideoRecording();
        else $('sa-video-status').textContent = state.cameraError;
      });
      return;
    }
    if (!window.MediaRecorder) {
      $('sa-video-status').textContent = 'La vidéo n\'est pas disponible dans ce navigateur.';
      return;
    }
    var mime = pickVideoMime();
    var chunks = [];
    var recorder;
    try {
      recorder = new MediaRecorder(state.stream, mime ? { mimeType: mime } : undefined);
    } catch (e) {
      $('sa-video-status').textContent = 'La vidéo n\'est pas disponible dans ce navigateur.';
      return;
    }
    state.videoRecorder = recorder;
    recorder.ondataavailable = function (event) { if (event.data && event.data.size) chunks.push(event.data); };
    recorder.onstop = function () {
      var blob = new Blob(chunks, { type: recorder.mimeType || mime || 'video/webm' });
      state.video = { blob: blob, mode: state.videoMode };
      var result = $('sa-video-result');
      if (result.src) URL.revokeObjectURL(result.src);
      result.src = URL.createObjectURL(blob);
      result.hidden = false;
      $('sa-video-status').textContent = 'Vidéo prête (' + Math.max(1, Math.round(blob.size / 1024)) + ' Ko). Vous pouvez la revoir, refaire une prise, ou continuer.';
      $('sa-video-start').hidden = false;
      $('sa-video-stop').hidden = true;
      if (state.videoTimer) { clearTimeout(state.videoTimer); state.videoTimer = null; }
      if (state.audio) speak('Vidéo prête. Rejouez-la si besoin, ou continuez avec la fiche.');
    };
    state.videoRecorder.start();
    $('sa-video-start').hidden = true;
    $('sa-video-stop').hidden = false;
    $('sa-video-status').textContent = 'Enregistrement… 20 secondes au plus.';
    state.videoTimer = setTimeout(function () {
      if (state.videoRecorder && state.videoRecorder.state === 'recording') state.videoRecorder.stop();
    }, 20000);
  }

  function stopVideoRecording() {
    if (state.videoRecorder && state.videoRecorder.state === 'recording') state.videoRecorder.stop();
  }

  function captureVideoFrame() {
    if (!state.stream) {
      ensureStream().then(function (ok) { if (ok) captureVideoFrame(); else $('sa-video-status').textContent = state.cameraError; });
      return;
    }
    captureFromVideo($('sa-video-preview')).then(function (blob) {
      if (!blob) { $('sa-video-status').textContent = 'La caméra démarre encore : patientez une seconde.'; return; }
      state.videoFrames.push(blob);
      $('sa-video-status').textContent = state.videoFrames.length + ' image(s) prise(s). Terminez quand le tour est complet.';
      $('sa-video-finish').hidden = state.videoFrames.length < 2;
    });
  }

  function assembleFrames(frames) {
    if (!window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) return Promise.resolve(null);
    return Promise.all(frames.map(function (blob) { return createImageBitmap(blob); })).then(function (bitmaps) {
      var w = bitmaps[0].width, h = bitmaps[0].height;
      var k = Math.min(1, 960 / Math.max(w, h));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(w * k));
      c.height = Math.max(1, Math.round(h * k));
      var ctx = c.getContext('2d');
      var stream = c.captureStream(10);
      var mime = pickVideoMime();
      var rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
      var chunks = [];
      rec.ondataavailable = function (event) { if (event.data && event.data.size) chunks.push(event.data); };
      var done = new Promise(function (resolve) {
        rec.onstop = function () { resolve(new Blob(chunks, { type: rec.mimeType || mime || 'video/webm' })); };
      });
      rec.start();
      var i = 0;
      function draw() {
        if (i >= bitmaps.length) { setTimeout(function () { rec.stop(); }, 350); return; }
        ctx.drawImage(bitmaps[i], 0, 0, c.width, c.height);
        i++;
        setTimeout(draw, 260);
      }
      draw();
      return done;
    });
  }

  function finishVideoFrames() {
    if (state.videoFrames.length < 2) {
      $('sa-video-status').textContent = 'Prenez au moins deux images pour faire une vidéo.';
      return;
    }
    $('sa-video-status').textContent = 'Assemblage de la vidéo…';
    assembleFrames(state.videoFrames).then(function (blob) {
      if (!blob) { $('sa-video-status').textContent = 'La vidéo image par image n\'est pas disponible dans ce navigateur.'; return; }
      state.video = { blob: blob, mode: state.videoMode };
      var result = $('sa-video-result');
      if (result.src) URL.revokeObjectURL(result.src);
      result.src = URL.createObjectURL(blob);
      result.hidden = false;
      $('sa-video-frame').hidden = true;
      $('sa-video-finish').hidden = true;
      $('sa-video-status').textContent = 'Vidéo prête (' + state.videoFrames.length + ' images). Vous pouvez la revoir, ou continuer.';
      if (state.audio) speak('Vidéo assemblée. Vous pouvez la revoir, ou continuer avec la fiche.');
    });
  }

  function closeVideo() {
    stopVideoRecording();
    if (state.videoTimer) { clearTimeout(state.videoTimer); state.videoTimer = null; }
    stopLiveCam();
    $('sa-video-stage').hidden = true;
  }

  document.querySelectorAll('#sa-video-modes [data-video-mode]').forEach(function (b) {
    b.addEventListener('click', function () { chooseVideoMode(this.dataset.videoMode); });
  });
  $('sa-video-start').addEventListener('click', startVideoRecording);
  $('sa-video-stop').addEventListener('click', stopVideoRecording);
  $('sa-video-frame').addEventListener('click', captureVideoFrame);
  $('sa-video-finish').addEventListener('click', finishVideoFrames);
  $('sa-video-close').addEventListener('click', closeVideo);

  // ── Mode lot : chaque article attend le traitement dans le Studio ───────
  function renderBatch() {
    var box = $('sa-batch');
    var list = $('sa-batch-list');
    if (!box || !list) return;
    list.textContent = '';
    state.batch.forEach(function (item, i) {
      var li = el('li');
      if (item.photo) {
        var img = el('img'); img.alt = ''; img.src = '/' + String(item.photo).replace(/^\//, '');
        li.appendChild(img);
      }
      var body = el('span');
      body.appendChild(el('b', '', 'Article ' + (i + 1)));
      body.appendChild(el('small', '', item.label + ' · Réf. N°' + item.ref));
      li.appendChild(body);
      list.appendChild(li);
    });
    box.hidden = !state.batch.length;
  }

  function finishBatchArticle(res) {
    state.batch.push({
      ref: res.ref,
      photo: res.photos && res.photos[0],
      label: state.draft[0] ? state.draft[0].label : 'Article'
    });
    state.draft = [];
    renderDraft();
    renderBatch();
    $('sa-visuals').textContent = '';
    $('sa-review').hidden = true;
    $('sa-catalog-link').hidden = true;
    $('sa-batch-studio').hidden = false;
    $('sa-new').textContent = 'Photographier un autre article';
    $('sa-done-title').textContent = 'Article ajouté au lot';
    $('sa-done-text').textContent = state.batch.length + ' article' + (state.batch.length > 1 ? 's' : '')
      + ' en attente. L\'IA préparera toutes les fiches dans le Studio, quand vous aurez terminé.';
    showPanel('done');
    if (state.audio) speak('Article ajouté au lot. Vous pouvez en photographier un autre, ou ouvrir le Studio pour lancer le traitement.');
  }

  function resetArticle() {
    stopSpeaking();
    stopLiveCam();
    if (state.photoUrl) URL.revokeObjectURL(state.photoUrl);
    state.shots.forEach(function (s) { if (s.url) URL.revokeObjectURL(s.url); });
    state.photo = null;
    state.photoUrl = null;
    state.analysis = null;
    state.metrics = null;
    state.ai = false;
    state.aiError = '';
    state.program = null;
    state.shots = [];
    state.shotsProgramKey = '';
    state.current = 0;
    state.keepAll = false;
    state.typeValidated = false;
    state.draft = [];
    state.ref = null;
    state.visuals = [];
    state.sheet = null;
    state.failed = [];
    state.generationDone = false;
    state.cameraClosed = false;
    state.video = null;
    state.videoFrames = [];
    state.videoMode = '';
    $('sa-first-preview').hidden = true;
    $('sa-first-drop').hidden = false;
    $('sa-first-live').hidden = true;
    $('sa-first-img').removeAttribute('src');
    $('sa-analysis').textContent = '';
    $('sa-analysis').hidden = true;
    $('sa-notes').value = '';
    $('sa-finish-grid').textContent = '';
    $('sa-finish-mode').textContent = '';
    $('sa-visuals').textContent = '';
    $('sa-review').hidden = true;
    $('sa-batch').hidden = true;
    $('sa-batch-studio').hidden = true;
    $('sa-catalog-link').hidden = false;
    $('sa-video-stage').hidden = true;
    $('sa-video-help').textContent = '';
    $('sa-video-status').textContent = '';
    resetVideoResult();
    document.querySelectorAll('#sa-video-modes [data-video-mode]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    state.manual = 'auto';
    state.typeAdding = null;
    $('sa-type-input').value = '';
    typeMessage('');
    renderTypeChips();
    updateTypeButtons();
    renderDraft();
    renderThumbs();
    showPanel('start');
    askMode(false, false);
  }

  $('sa-resume-shots').addEventListener('click', function () {
    if (!state.stream && !state.cameraClosed) openShotCam();
    renderShot();
    renderThumbs();
    showPanel('shots');
    announceShot();
  });
  $('sa-restart').addEventListener('click', function () { resetArticle(); });
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
    var batch = state.mode === 'batch';
    var fd = new FormData();
    fd.append('action', 'create');
    fd.append('kind', p.kind || 'objet');
    fd.append('source', batch ? 'batch' : 'single');
    fd.append('notes', $('sa-notes').value || '');
    var mainSet = false;
    state.shots.forEach(function (s) {
      if (!s.blob) return;
      fd.append('photos[]', s.blob, 'photo-' + s.key + '.jpg');
      fd.append('labels[]', s.label);
      if (!mainSet) { fd.append('main', '0'); mainSet = true; }
    });
    if (!mainSet) { window.alert('Aucune photo à envoyer.'); return; }

    stopLiveCam();
    document.querySelectorAll('#sa-gen-list li').forEach(function (li) {
      li.dataset.state = '';
      li.hidden = batch && ['detoure', 'sheet', 'ambiance', 'angles', 'recto', 'verso'].indexOf(li.dataset.step) >= 0;
    });
    document.querySelector('#sa-gen-list li[data-step="video"]').hidden = !state.video;
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
      var afterCreate = function () {
        if (batch) finishBatchArticle(res);
        else runChain(res.ref);
      };
      if (!state.video) { afterCreate(); return; }
      genStep('video', 'run');
      uploadVideo(res.ref, res.chunk).then(function (result) {
        if (result.ok) genStep('video', 'ok', 'Vidéo enregistrée');
        else genStep('video', 'err', result.error || 'Vidéo non envoyée.');
        afterCreate();
      });
    });
  }

  function videoExt(blob) {
    return blob && /mp4/.test(blob.type || '') ? 'mp4' : 'webm';
  }

  /** Envoi de la vidéo par morceaux, dans l'ordre, comme le parcours « Nouvelle pièce ». */
  function uploadVideo(ref, serverChunk) {
    var blob = state.video && state.video.blob;
    if (!blob) return Promise.resolve({ ok: true });
    var chunkBytes = Math.min(4 * 1048576, Number(serverChunk) || 4 * 1048576);
    var total = Math.max(1, Math.ceil(blob.size / chunkBytes));
    var uploadId = '';
    for (var i = 0; i < 16; i++) uploadId += Math.floor(Math.random() * 16).toString(16);
    var name = 'objet-' + ref + '.' + videoExt(blob);
    var index = 0;
    function step() {
      if (index >= total) return Promise.resolve({ ok: true });
      var start = index * chunkBytes;
      var part = blob.slice(start, Math.min(blob.size, start + chunkBytes));
      var fd = new FormData();
      fd.append('action', 'video');
      fd.append('ref', ref);
      fd.append('upload_id', uploadId);
      fd.append('index', String(index));
      fd.append('total', String(total));
      fd.append('size', String(blob.size));
      fd.append('name', name);
      fd.append('file', part, 'part-' + index);
      return post(QA, fd).then(function (res) {
        if (!res.ok) return { ok: false, error: res.error || 'Envoi de la vidéo interrompu.' };
        index++;
        genStep('video', 'run', Math.round(index / total * 100) + ' %');
        return step();
      });
    }
    return step().catch(function () {
      return { ok: false, error: 'Connexion perdue pendant l\'envoi de la vidéo.' };
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
    state.failed = failed.slice();
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
      $('sa-batch').hidden = true;
      $('sa-batch-studio').hidden = true;
      $('sa-catalog-link').hidden = false;
      $('sa-new').textContent = 'Ajouter une autre pièce';
      $('sa-done-title').textContent = 'Fiche prête à relire';
      $('sa-done-text').textContent = 'Réf. N°' + ref + ' — relisez le nom, le prix et la description, puis publiez si tout est correct.';
      $('sa-catalog-link').href = CATALOG + encodeURIComponent(ref);
      if (res.ok && res.product) {
        $('sa-f-name').value = res.product.name || '';
        $('sa-f-price').value = String(res.product.price || '').replace(/[^0-9.,]/g, '').replace(',', '.');
        $('sa-f-new-price').value = moneyValue(res.product.new_price);
        $('sa-f-desc').value = res.product.description || '';
        $('sa-f-weight').value = res.product.weight_grams || '';
        $('sa-review').hidden = false;
        setupVoice(res.product);
      } else {
        $('sa-review').hidden = true;
        $('sa-voice').hidden = true;
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
    fd.append('new_price', $('sa-f-new-price').value);
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

  $('sa-new').addEventListener('click', function () {
    if ((state.mode === 'batch' || state.mode === 'group') && state.batch.length) { resetArticle(); return; }
    location.reload();
  });
  $('sa-generate-again').addEventListener('click', generate);
  $('sa-generate-back').addEventListener('click', function () {
    renderShot(); renderThumbs(); showPanel('shots'); announceShot();
  });

  // ── Validation vocale de la fiche ────────────────────────────────────────
  // L'assistant pose les questions à voix haute, écoute la réponse, et n'envoie au serveur que les
  // corrections explicites : « oui » et « je ne sais pas » laissent la fiche telle que l'IA l'a remplie.
  var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
  var voice = {
    active: false,
    product: null,
    questions: [],
    index: 0,
    awaiting: null,
    summary: [],
    notes: [],
    token: 0,
    rec: null,
    listening: false
  };

  function conditionLabel(key) {
    if (!key) return '';
    return (DATA.conditionLabels && DATA.conditionLabels[key]) || key;
  }
  function moneyValue(text) {
    var m = String(text || '').replace(/\s/g, '').match(/\d+(?:[.,]\d{1,2})?/);
    return m ? m[0].replace(',', '.') : '';
  }
  function formatEuros(text) {
    var v = moneyValue(text);
    if (v === '') return '';
    return (v.indexOf('.') >= 0 ? v.replace('.', ',') : v) + ' €';
  }
  function voiceHint(text) { $('sa-voice-hint').textContent = text || ''; }

  function voiceSupportText() {
    return SpeechRec
      ? "Répondez à la voix ou avec les boutons. « Je ne sais pas » ne change rien. La dictée utilise la reconnaissance vocale du navigateur."
      : "La dictée n'est pas disponible dans ce navigateur : répondez avec les boutons, et au clavier pour les montants.";
  }

  function setupVoice(product) {
    voice.product = product;
    voice.active = false;
    voice.awaiting = null;
    voice.questions = [];
    voice.index = 0;
    voice.summary = [];
    voice.notes = [];
    stopVoiceRecognition();
    $('sa-voice').hidden = false;
    $('sa-voice-intro').hidden = false;
    $('sa-voice-panel').hidden = true;
    $('sa-voice-start').textContent = '🎙 Vérifier à la voix';
    $('sa-voice-support').textContent = voiceSupportText();
  }

  function buildVoiceQuestions(p) {
    var qs = [];
    var colors = String(p.colors || '').trim();
    var etatLabel = String(p.etat_label || conditionLabel(p.etat) || '').trim();
    var newPrice = parseFloat(moneyValue(p.new_price) || '0');
    var price = parseFloat(moneyValue(p.price) || '0');
    var name = String(p.name || '').trim();
    var weight = Number(p.weight_grams) || 0;
    var barcode = String(p.barcode || '').trim();
    var options = DATA.voiceConditions || [];
    var optionText = options.map(function (o) { return o.label; }).join(', ');

    if (state.visuals.length && state.failed.indexOf('detoure') < 0) {
      qs.push({
        key: 'cutout', type: 'confirm', field: null,
        text: 'Le détourage de la photo vous convient-il ?',
        hint: 'Si non, vous pourrez le refaire depuis la fiche, dans le catalogue.',
        onNo: function () { addVoiceNote('Détourage à revoir : relancez-le depuis la fiche dans le catalogue.'); }
      });
    }

    if (colors) {
      qs.push({
        key: 'colors', type: 'confirm', field: 'colors',
        text: 'L\'IA indique les couleurs : ' + colors + '. Est-ce exact ?',
        follow: { type: 'text', field: 'colors', text: 'Quelle est la couleur ?', input: 'Ex. : bleu marine, blanc', unknown: 'clear' }
      });
    } else {
      qs.push({
        key: 'colors', type: 'text', field: 'colors',
        text: 'Quelle est la couleur dominante de la pièce ?',
        hint: 'Dites la couleur, ou « je ne sais pas ».',
        input: 'Ex. : bleu marine, blanc',
        unknown: 'keep'
      });
    }

    if (etatLabel) {
      qs.push({
        key: 'etat', type: 'confirm', field: 'etat',
        text: 'L\'état indiqué est « ' + etatLabel + ' ». Est-ce exact ?',
        follow: { type: 'choice', field: 'etat', text: 'Quel est l\'état de la pièce ?', hint: 'Dites par exemple : ' + optionText + '.', options: options, unknown: 'clear' }
      });
    } else {
      qs.push({
        key: 'etat', type: 'choice', field: 'etat',
        text: 'Quel est l\'état de la pièce ?',
        hint: 'Dites par exemple : ' + optionText + '.',
        options: options,
        unknown: 'keep'
      });
    }

    if (newPrice > 0) {
      qs.push({
        key: 'new_price', type: 'confirm', field: 'new_price',
        text: 'Le prix du neuf est indiqué à ' + formatEuros(p.new_price) + '. Est-ce exact ?',
        follow: { type: 'number', field: 'new_price', text: 'Quel est le prix du neuf ?', input: 'Ex. : 40', unknown: 'clear' }
      });
    } else {
      qs.push({
        key: 'new_price', type: 'number', field: 'new_price',
        text: 'Quel est le prix du neuf de cette pièce ?',
        hint: 'Dites un montant, ou « je ne sais pas » si vous ne le connaissez pas.',
        input: 'Ex. : 40',
        unknown: 'keep'
      });
    }

    if (price > 0) {
      qs.push({
        key: 'price', type: 'confirm', field: 'price',
        text: 'Le prix affiché est de ' + formatEuros(p.price) + '. Est-ce exact ?',
        follow: { type: 'number', field: 'price', text: 'Quel prix voulez-vous afficher ?', input: 'Ex. : 25', unknown: 'keep' }
      });
    } else {
      qs.push({
        key: 'price', type: 'number', field: 'price',
        text: 'Quel prix voulez-vous afficher ?',
        input: 'Ex. : 25',
        unknown: 'keep'
      });
    }

    if (!barcode && name) {
      qs.push({
        key: 'name', type: 'confirm', field: 'name',
        text: 'Le nom « ' + name + ' » vous convient-il ?',
        follow: { type: 'text', field: 'name', text: 'Dites le bon nom.', input: 'Ex. : Chemise en lin bleue', unknown: 'keep' }
      });
    }

    if (!barcode && String(p.description || '').trim()) {
      qs.push({
        key: 'description', type: 'confirm', field: 'description',
        text: 'La description vous convient-elle ?',
        hint: 'Elle est affichée à l\'écran. Si non, vous pourrez la modifier juste après.',
        onNo: function () {
          addVoiceNote('Description à modifier à l\'écran.');
          var field = $('sa-f-desc');
          if (field) { field.focus(); field.classList.add('is-flagged'); }
        }
      });
    }

    if (weight > 0) {
      qs.push({
        key: 'weight_grams', type: 'confirm', field: 'weight_grams',
        text: 'Le poids est estimé à ' + weight + ' grammes. Est-ce exact ?',
        follow: { type: 'number', field: 'weight_grams', text: 'Quel est le poids, en grammes ?', input: 'Ex. : 800, ou 1 kilo 200', unknown: 'keep' }
      });
    } else {
      qs.push({
        key: 'weight_grams', type: 'number', field: 'weight_grams',
        text: 'Quel est le poids de la pièce, en grammes ?',
        input: 'Ex. : 800, ou 1 kilo 200',
        unknown: 'keep'
      });
    }

    return qs;
  }

  function startVoice() {
    if (!voice.product) return;
    voice.questions = buildVoiceQuestions(voice.product);
    if (!voice.questions.length) return;
    voice.active = true;
    voice.index = 0;
    voice.summary = [];
    voice.notes = [];
    $('sa-voice-support').textContent = voiceSupportText();
    $('sa-voice-intro').hidden = true;
    $('sa-voice-panel').hidden = false;
    $('sa-voice-stop').hidden = false;
    renderVoiceSummary();
    askQuestion(voice.questions[0]);
  }

  function askQuestion(q) {
    voice.awaiting = q;
    voice.token++;
    var token = voice.token;
    stopVoiceRecognition();
    $('sa-voice-count').textContent = 'Question ' + (voice.index + 1) + ' sur ' + voice.questions.length;
    $('sa-voice-question').textContent = q.text;
    $('sa-voice-hint').textContent = q.hint || '';
    $('sa-voice-heard').textContent = '';
    $('sa-voice-answers').hidden = false;
    renderVoiceAnswers(q);
    renderVoiceInput(q);
    $('sa-voice-mic').hidden = !SpeechRec;
    $('sa-voice-repeat').hidden = false;
    $('sa-voice-unknown').hidden = false;
    $('sa-voice-stop').hidden = false;
    setMicState(false);
    speak(q.text + (q.hint ? ' ' + q.hint : ''), false, function () {
      if (!voice.active || voice.token !== token || voice.awaiting !== q) return;
      setTimeout(function () {
        if (!voice.active || voice.token !== token || voice.awaiting !== q) return;
        listenVoice();
      }, 350);
    });
  }

  function renderVoiceAnswers(q) {
    var box = $('sa-voice-answers');
    box.textContent = '';
    if (q.type === 'confirm') {
      var yes = el('button', 'btn btn-primary', 'Oui');
      yes.type = 'button';
      yes.addEventListener('click', function () { answerYesNo('yes'); });
      var no = el('button', 'btn btn-ghost', 'Non');
      no.type = 'button';
      no.addEventListener('click', function () { answerYesNo('no'); });
      box.appendChild(yes);
      box.appendChild(no);
    } else if (q.type === 'choice') {
      (q.options || []).forEach(function (o) {
        var b = el('button', 'btn btn-ghost', o.label);
        b.type = 'button';
        if (o.hint) b.title = o.hint;
        b.addEventListener('click', function () { answerChoice(o); });
        box.appendChild(b);
      });
    }
    box.hidden = !box.childNodes.length;
  }

  function renderVoiceInput(q) {
    var wrap = $('sa-voice-input-wrap');
    var input = $('sa-voice-input');
    var open = q.type === 'text' || q.type === 'number';
    wrap.hidden = !open;
    input.value = '';
    if (!open) return;
    input.inputMode = q.type === 'number' ? 'decimal' : 'text';
    input.placeholder = q.input || '';
    $('sa-voice-input-label').textContent = q.field === 'weight_grams' ? 'Ou tapez le poids (g)' : (q.type === 'number' ? 'Ou tapez le montant' : 'Ou tapez votre réponse');
  }

  function listenVoice() {
    if (!SpeechRec || !voice.active || voice.listening || !voice.awaiting) return;
    stopSpeaking();
    var rec = new SpeechRec();
    rec.lang = 'fr-FR';
    rec.continuous = false;
    rec.interimResults = true;
    rec.maxAlternatives = 1;
    voice.rec = rec;
    voice.listening = true;
    setMicState(true);
    $('sa-voice-heard').textContent = '🎤 Je vous écoute…';
    voiceHint('');
    rec.onresult = function (event) {
      var interim = '', final = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var r = event.results[i];
        if (r.isFinal) final += r[0].transcript; else interim += r[0].transcript;
      }
      if (interim) $('sa-voice-heard').textContent = '🎤 ' + interim;
      if (!final.trim()) return;
      $('sa-voice-heard').textContent = 'Entendu : « ' + final.trim() + ' »';
      voice.listening = false;
      setMicState(false);
      try { rec.stop(); } catch (e) {}
      handleVoiceAnswer(final.trim());
    };
    rec.onerror = function (event) {
      voice.listening = false;
      setMicState(false);
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        voiceHint('Micro refusé : autorisez-le dans le navigateur, ou répondez avec les boutons.');
      } else if (event.error === 'no-speech') {
        voiceHint('Je n\'ai rien entendu. Réessayez, ou répondez avec les boutons.');
      } else if (event.error !== 'aborted') {
        voiceHint('La dictée s\'est arrêtée. Réessayez, ou répondez avec les boutons.');
      }
    };
    rec.onend = function () {
      voice.listening = false;
      voice.rec = null;
      setMicState(false);
    };
    try { rec.start(); } catch (e) {
      voice.listening = false;
      voice.rec = null;
      setMicState(false);
    }
  }

  function stopVoiceRecognition() {
    if (voice.rec) { try { voice.rec.abort(); } catch (e) {} }
    voice.rec = null;
    voice.listening = false;
    setMicState(false);
  }

  function setMicState(on) {
    var b = $('sa-voice-mic');
    if (!b) return;
    b.classList.toggle('is-listening', on);
    b.textContent = on ? '⏹ J\'écoute…' : '🎤 Répondre';
  }

  function handleVoiceAnswer(text) {
    var q = voice.awaiting;
    if (!q) return;
    if (q.type === 'confirm') {
      var answer = parseYesNo(text);
      if (!answer) { voiceHint('Répondez par oui, non, ou « je ne sais pas ».'); return; }
      answerYesNo(answer);
    } else if (q.type === 'choice') {
      var option = matchCondition(text);
      if (!option) { voiceHint('Je n\'ai pas reconnu l\'état. Réessayez, ou choisissez un bouton.'); return; }
      answerChoice(option);
    } else if (q.type === 'number') {
      var value = q.field === 'weight_grams' ? parseGrams(text) : parseAmount(text);
      if (value == null || !(value > 0)) { voiceHint('Je n\'ai pas compris le nombre. Réessayez, ou tapez-le.'); return; }
      answerNumber(value, q);
    } else {
      var spoken = cleanSpokenText(text);
      if (spoken === '') { voiceHint('Je n\'ai pas compris. Réessayez, ou tapez votre réponse.'); return; }
      answerText(spoken, q);
    }
  }

  function answerYesNo(kind) {
    var q = voice.awaiting;
    if (!q || q.type !== 'confirm') return;
    if (kind === 'yes') { nextVoiceQuestion(); return; }
    if (kind === 'unknown') {
      if (q.follow && q.follow.unknown === 'clear' && q.field) applyCorrection(q.field, '');
      nextVoiceQuestion();
      return;
    }
    if (q.follow) { askFollow(q); return; }
    if (q.onNo) q.onNo();
    nextVoiceQuestion();
  }

  function askFollow(parent) {
    var f = parent.follow;
    askQuestion({
      key: parent.key,
      type: f.type,
      field: f.field || parent.field,
      text: f.text,
      hint: f.hint || '',
      input: f.input || '',
      options: f.options || null,
      unknown: f.unknown || 'keep',
      isFollow: true
    });
  }

  function answerChoice(option) {
    var q = voice.awaiting;
    if (!q) return;
    applyCorrection(q.field || 'etat', option.key);
    nextVoiceQuestion();
  }

  function answerNumber(value, q) {
    if (q.field === 'weight_grams') {
      var grams = Math.round(value);
      if (grams <= 0) { voiceHint('Le poids doit être supérieur à zéro.'); return; }
      applyCorrection('weight_grams', String(grams));
    } else {
      if (!(value > 0)) { voiceHint('Le montant doit être supérieur à zéro.'); return; }
      applyCorrection(q.field, String(Math.round(value * 100) / 100));
    }
    nextVoiceQuestion();
  }

  function answerText(value, q) {
    if (q.field === 'name') value = value.charAt(0).toUpperCase() + value.slice(1);
    applyCorrection(q.field, value);
    nextVoiceQuestion();
  }

  function nextVoiceQuestion() {
    stopVoiceRecognition();
    voice.awaiting = null;
    voice.index++;
    if (voice.index >= voice.questions.length) { finishVoice(); return; }
    askQuestion(voice.questions[voice.index]);
  }

  function finishVoice() {
    voice.active = false;
    voice.awaiting = null;
    stopVoiceRecognition();
    stopSpeaking();
    $('sa-voice-count').textContent = 'Vérification terminée';
    $('sa-voice-question').textContent = (voice.summary.length || voice.notes.length)
      ? 'Merci : vos corrections sont enregistrées.'
      : 'Aucune correction : la fiche reste telle quelle.';
    $('sa-voice-hint').textContent = '';
    $('sa-voice-heard').textContent = '';
    $('sa-voice-input-wrap').hidden = true;
    $('sa-voice-mic').hidden = true;
    $('sa-voice-repeat').hidden = true;
    $('sa-voice-unknown').hidden = true;
    $('sa-voice-stop').hidden = true;
    var box = $('sa-voice-answers');
    box.textContent = '';
    box.hidden = false;
    var again = el('button', 'btn btn-ghost', '↺ Recommencer');
    again.type = 'button';
    again.addEventListener('click', startVoice);
    var close = el('button', 'btn btn-primary', 'Terminer');
    close.type = 'button';
    close.addEventListener('click', closeVoice);
    box.appendChild(again);
    box.appendChild(close);
    renderVoiceSummary();
    speak('Vérification terminée. Vérifiez la fiche, puis enregistrez.');
  }

  function voiceRecap() {
    return voice.summary.concat(voice.notes).join(' · ');
  }

  function closeVoice() {
    voice.active = false;
    voice.awaiting = null;
    stopVoiceRecognition();
    stopSpeaking();
    $('sa-voice-panel').hidden = true;
    $('sa-voice-intro').hidden = false;
    $('sa-voice-start').textContent = '🎙 Refaire la vérification';
    var recap = voiceRecap();
    if (recap) $('sa-voice-support').textContent = 'Vérification terminée : ' + recap;
  }

  function stopVoice() {
    var wasActive = voice.active;
    voice.active = false;
    voice.awaiting = null;
    stopVoiceRecognition();
    stopSpeaking();
    $('sa-voice-panel').hidden = true;
    $('sa-voice-intro').hidden = false;
    $('sa-voice-start').textContent = '🎙 Reprendre la vérification';
    var recap = voiceRecap();
    if (wasActive && recap) $('sa-voice-support').textContent = 'Vérification arrêtée. À retenir : ' + recap;
  }

  function answerVoiceUnknown() {
    var q = voice.awaiting;
    if (!voice.active || !q) return;
    if (q.type === 'confirm') { answerYesNo('unknown'); return; }
    if (q.unknown === 'clear' && q.field) applyCorrection(q.field, '');
    nextVoiceQuestion();
  }

  function submitTypedVoice() {
    var q = voice.awaiting;
    if (!voice.active || !q) return;
    var text = $('sa-voice-input').value.trim();
    if (text === '') return;
    stopVoiceRecognition();
    $('sa-voice-heard').textContent = 'Saisi : « ' + text + ' »';
    handleVoiceAnswer(text);
  }

  function addVoiceNote(text) {
    if (voice.notes.indexOf(text) < 0) voice.notes.push(text);
    renderVoiceSummary();
  }

  function renderVoiceSummary() {
    var box = $('sa-voice-summary');
    box.textContent = '';
    voice.summary.forEach(function (line) { box.appendChild(el('li', '', line)); });
    voice.notes.forEach(function (line) { box.appendChild(el('li', 'is-note', line)); });
    box.hidden = !(voice.summary.length + voice.notes.length);
  }

  function applyCorrection(field, value) {
    var p = voice.product || (voice.product = {});
    if (field === 'colors') {
      p.colors = String(value || '');
      addVoiceSummary(p.colors ? 'Couleurs : ' + p.colors : 'Couleurs retirées : à vérifier');
    } else if (field === 'etat') {
      p.etat = String(value || '');
      p.etat_label = p.etat ? conditionLabel(p.etat) : '';
      addVoiceSummary(p.etat_label ? 'État : ' + p.etat_label : 'État retiré : à préciser');
    } else if (field === 'new_price') {
      p.new_price = value ? formatEuros(value) : '';
      $('sa-f-new-price').value = moneyValue(p.new_price);
      addVoiceSummary(p.new_price ? 'Prix du neuf : ' + p.new_price : 'Prix du neuf retiré');
    } else if (field === 'price') {
      p.price = formatEuros(value);
      $('sa-f-price').value = moneyValue(p.price);
      addVoiceSummary('Prix affiché : ' + p.price);
    } else if (field === 'weight_grams') {
      p.weight_grams = Number(value) || 0;
      $('sa-f-weight').value = p.weight_grams || '';
      addVoiceSummary(p.weight_grams ? 'Poids : ' + p.weight_grams + ' g' : 'Poids retiré');
    } else if (field === 'name') {
      p.name = String(value || '');
      $('sa-f-name').value = p.name;
      addVoiceSummary('Nom : ' + p.name);
    } else if (field === 'description') {
      p.description = String(value || '');
      $('sa-f-desc').value = p.description;
      addVoiceSummary('Description corrigée');
    }
    sendVoiceCorrection(field, value);
  }

  function addVoiceSummary(text) {
    if (voice.summary.indexOf(text) < 0) voice.summary.push(text);
    renderVoiceSummary();
  }

  function sendVoiceCorrection(field, value) {
    if (!state.ref) return;
    var fd = new FormData();
    fd.append('action', 'validate');
    fd.append('ref', state.ref);
    fd.append(field, value == null ? '' : String(value));
    post(ACTION, fd).then(function (res) {
      if (!res.ok) { voiceHint(res.error || 'La correction n\'a pas pu être enregistrée.'); return; }
      if (!res.product) return;
      voice.product = res.product;
      // Le serveur normalise : le champ du formulaire reprend sa valeur enregistrée.
      if (field === 'price') $('sa-f-price').value = moneyValue(res.product.price);
      if (field === 'new_price') $('sa-f-new-price').value = moneyValue(res.product.new_price);
      if (field === 'name') $('sa-f-name').value = res.product.name || '';
    });
  }

  function normalizeVoice(text) {
    return String(text || '').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[’']/g, ' ')
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
  }
  function hasPhrase(haystack, phrase) {
    return (' ' + haystack + ' ').indexOf(' ' + phrase + ' ') >= 0;
  }

  function parseYesNo(text) {
    var t = normalizeVoice(text);
    var unknown = ['je ne sais pas', 'je sais pas', 'je ne sais', 'sais pas', 'aucune idee', 'pas d idee', 'je ne connais pas', 'connais pas', 'pas sur', 'pas certain', 'pas certaine', 'peut etre', 'incertain', 'impossible a dire', 'je ne peux pas dire', 'je ne me souviens pas'];
    var no = ['non', 'pas bon', 'pas bonne', 'pas exact', 'pas exacte', 'pas correct', 'pas correcte', 'incorrect', 'incorrecte', 'faux', 'pas du tout', 'pas vraiment', 'negatif', 'ce n est pas bon', 'ce n est pas ca', 'c est pas bon', 'pas ca'];
    var yes = ['oui', 'ouais', 'exact', 'exacte', 'c est bon', 'c est exact', 'c est ca', 'tout a fait', 'absolument', 'parfait', 'correct', 'correcte', 'd accord', 'affirmatif', 'ca me va', 'tres bien', 'valide', 'confirme', 'ok'];
    var i;
    for (i = 0; i < unknown.length; i++) if (hasPhrase(t, unknown[i])) return 'unknown';
    for (i = 0; i < no.length; i++) if (hasPhrase(t, no[i])) return 'no';
    for (i = 0; i < yes.length; i++) if (hasPhrase(t, yes[i])) return 'yes';
    return null;
  }

  var CONDITION_ALIASES = [
    ['neuf avec etiquette', 'neuf'], ['neuf emballe', 'neuf'], ['neuf avec sa boite', 'neuf'], ['neuf sans etiquette', 'neuf'],
    ['comme neuf', 'comme_neuf'], ['presque neuf', 'comme_neuf'], ['excellent etat', 'comme_neuf'], ['impeccable', 'comme_neuf'],
    ['tres bon etat', 'tres_bon'], ['tres bon', 'tres_bon'],
    ['bon etat pour son age', 'bon'], ['belle patine', 'bon'], ['bon etat', 'bon'],
    ['etat correct', 'correct'], ['traces d usage', 'correct'], ['correct', 'correct'],
    ['petits defauts', 'use'], ['tres use', 'use'], ['usage marque', 'use'], ['use', 'use'],
    ['a restaurer', 'restaurer'], ['restaurer', 'restaurer'], ['restauration', 'restaurer'],
    ['pour pieces', 'pieces'], ['pour decoration', 'pieces'], ['ne fonctionne pas', 'pieces'], ['pieces', 'pieces'],
    ['neuf', 'neuf'], ['bon', 'bon']
  ];

  function matchCondition(text) {
    var t = normalizeVoice(text);
    var options = (voice.awaiting && voice.awaiting.options) || DATA.voiceConditions || [];
    for (var i = 0; i < CONDITION_ALIASES.length; i++) {
      if (!hasPhrase(t, CONDITION_ALIASES[i][0])) continue;
      var key = CONDITION_ALIASES[i][1];
      for (var j = 0; j < options.length; j++) {
        if (options[j].key === key) return options[j];
      }
      return { key: key, label: conditionLabel(key) };
    }
    return null;
  }

  function wordsToNumber(text) {
    var words = normalizeVoice(text).split(' ').filter(Boolean);
    var units = { zero: 0, un: 1, une: 1, deux: 2, trois: 3, quatre: 4, cinq: 5, six: 6, sept: 7, huit: 8, neuf: 9, dix: 10, onze: 11, douze: 12, treize: 13, quatorze: 14, quinze: 15, seize: 16 };
    var tens = { vingt: 20, trente: 30, quarante: 40, cinquante: 50, soixante: 60 };
    var total = 0, current = 0, seen = false;
    for (var i = 0; i < words.length; i++) {
      var w = words[i];
      if (w === 'et') continue;
      if (w === 'quatre' && words[i + 1] === 'vingt') { current += 80; i++; seen = true; continue; }
      if (units[w] != null) { current += units[w]; seen = true; continue; }
      if (tens[w] != null) { current += tens[w]; seen = true; continue; }
      if (w === 'cent' || w === 'cents') { current = (current || 1) * 100; seen = true; continue; }
      if (w === 'mille') { total += (current || 1) * 1000; current = 0; seen = true; continue; }
    }
    return seen ? total + current : null;
  }

  function parseAmount(text) {
    var raw = String(text || '');
    var t = normalizeVoice(raw);
    var nums = raw.match(/\d+(?:[.,]\d{1,2})?/g);
    if (nums) {
      var first = parseFloat(nums[0].replace(',', '.'));
      if (nums.length > 1) {
        var second = parseFloat(nums[1].replace(',', '.'));
        // « 1 200 euros » : le second groupe est le reste des milliers, pas des centimes.
        if (second >= 100 && !/[.,]/.test(nums[0])) return Math.round((first * 1000 + second) * 100) / 100;
        if (second > 0 && second < 100 && (hasPhrase(t, 'euro') || hasPhrase(t, 'euros') || hasPhrase(t, 'centime') || hasPhrase(t, 'centimes'))) {
          return Math.round((first + second / 100) * 100) / 100;
        }
      }
      return Math.round(first * 100) / 100;
    }
    var parts = t.split(/ euros? /);
    var euros = wordsToNumber(parts[0]);
    if (euros == null) return null;
    if (parts.length > 1) {
      var cents = wordsToNumber(parts[1]);
      if (cents != null && cents > 0 && cents < 100) euros += cents / 100;
    }
    return Math.round(euros * 100) / 100;
  }

  function parseGrams(text) {
    var raw = String(text || '');
    var t = normalizeVoice(raw);
    // « 1 kilo 200 » : le nombre après l'unité s'ajoute, ce ne sont pas des décimales.
    var kg = raw.match(/(\d+(?:[.,]\d{1,3})?)\s*(?:kilos?|kg)\b\s*(?:et\s+)?(\d{1,3})?/i);
    if (kg) {
      var kilos = parseFloat(kg[1].replace(',', '.'));
      var extra = kg[2] ? parseInt(kg[2], 10) : 0;
      return Math.round(kilos * 1000 + extra);
    }
    var m = raw.match(/(\d+(?:[.,]\d{1,3})?)\s*(?:grammes?|g)\b/i);
    if (m) return Math.round(parseFloat(m[1].replace(',', '.')));
    var idx = t.indexOf('kilo');
    if (idx >= 0) {
      var before = wordsToNumber(t.slice(0, idx));
      var after = wordsToNumber(t.slice(idx + 4));
      var kg = before == null ? 1 : before;
      return Math.round(kg * 1000 + (after || 0));
    }
    var n = wordsToNumber(t);
    if (n != null) return Math.round(n);
    var bare = t.match(/^\d+(?:[.,]\d+)?$/);
    return bare ? Math.round(parseFloat(bare[0].replace(',', '.'))) : null;
  }

  function cleanSpokenText(text) {
    var t = String(text || '').replace(/\s+/g, ' ').trim().replace(/[.!?…]+$/g, '').trim();
    t = t.replace(/^(alors|donc|euh|hum)\s+/i, '');
    t = t.replace(/^(la |les |des )?couleurs? (est|sont|serait|seraient)\s+/i, '');
    t = t.replace(/^(le nom|c'est|ce sont|ça s'appelle|ca s'appelle)\s+(est\s+)?/i, '');
    return t.trim();
  }

  $('sa-voice-start').addEventListener('click', startVoice);
  $('sa-voice-mic').addEventListener('click', function () { if (voice.listening) stopVoiceRecognition(); else listenVoice(); });
  $('sa-voice-repeat').addEventListener('click', function () {
    var q = voice.awaiting;
    if (!q) return;
    stopVoiceRecognition();
    speak(q.text + (q.hint ? ' ' + q.hint : ''), true);
  });
  $('sa-voice-unknown').addEventListener('click', answerVoiceUnknown);
  $('sa-voice-stop').addEventListener('click', stopVoice);
  $('sa-voice-send').addEventListener('click', submitTypedVoice);
  $('sa-voice-input').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); submitTypedVoice(); }
  });
  $('sa-f-desc').addEventListener('input', function () { this.classList.remove('is-flagged'); });

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
  function speak(text, force, done) {
    if ((!state.audio && !force) || !synth || !text) { if (done) done(); return; }
    var finished = false;
    function finish() {
      if (finished) return;
      finished = true;
      if (done) done();
    }
    try {
      synth.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'fr-FR'; u.rate = 0.98; u.pitch = 1;
      var v = frenchVoice(); if (v) u.voice = v;
      u.onstart = function () { audioStatus('🔊 Assistance vocale en cours…'); $('sa-audio-stop').hidden = false; };
      u.onend = function () { audioStatus(''); $('sa-audio-stop').hidden = true; finish(); };
      u.onerror = function () { audioStatus(''); $('sa-audio-stop').hidden = true; finish(); };
      synth.speak(u);
      // Filet de sécurité : certains navigateurs n'appellent pas onend après cancel().
      setTimeout(finish, Math.max(2500, text.length * 120));
    } catch (e) { finish(); }
  }
  $('sa-audio-play').addEventListener('click', function () { playIntro(); });
  $('sa-audio-stop').addEventListener('click', stopSpeaking);

  function analysisSpeech(a) {
    if (!a) return '';
    var text = 'Analyse terminée. ' + (a.summary || '');
    var issues = (a.quality && a.quality.issues) || [];
    if (issues.length) {
      text += ' Attention : ' + issues.map(issueLabel).join(', ').toLowerCase() + '.';
      text += AI
        ? " Vous pouvez reprendre la photo, la corriger avec l'IA, ou continuer."
        : ' Vous pouvez reprendre la photo, la corriger, ou continuer.';
    } else {
      text += ' La photo est nette et bien exposée. Vous pouvez valider le type et ouvrir la caméra.';
    }
    return text;
  }

  function introSpeech() {
    return "Bienvenue dans l'assistant de shooting. Posez la pièce seule sur un fond simple, à la lumière du jour, puis prenez une photo d'ensemble. "
      + "L'assistant contrôle la netteté, le cadrage et la lumière, reconnaît la pièce et propose un programme de photos. "
      + "À chaque vue, une voix annonce le conseil, sans lire tout le programme. À la fin, la fiche se vérifie aussi à la voix. "
      + 'Pour couper le son, décochez l\'assistance vocale.';
  }
  function audioOnSpeech() {
    return "Assistance vocale activée. Posez la pièce seule sur un fond simple, à la lumière du jour, puis prenez la photo d'ensemble.";
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
    var tail = AI
      ? 'Vous pouvez reprendre la photo, la corriger avec l\'IA, ou continuer si cela vous convient.'
      : 'Vous pouvez reprendre la photo, ou continuer si cela vous convient.';
    return 'Attention pour ' + s.label + ' : ' + parts.join(', ') + '. ' + tail;
  }

  // ── Démarrage ────────────────────────────────────────────────────────────
  syncAudioToggles();
  showPanel('start');
})();
