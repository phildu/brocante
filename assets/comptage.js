/* Comptage de foule — logique du compteur (téléphone).
 *
 * Principes :
 *  - Chaque appui est une opération {op:'tap', uid, gate, dir, qty, ts} placée
 *    dans une file d'attente enregistrée dans localStorage : rien n'est perdu
 *    si le réseau coupe, si l'onglet se ferme ou si le téléphone redémarre.
 *  - La file est envoyée au serveur dès que possible (et toutes les 10 s, ce
 *    qui sert aussi à rafraîchir les totaux des autres postes). L'uid rend
 *    l'envoi idempotent : un renvoi ne compte jamais deux fois.
 *  - Ce qui s'affiche = derniers totaux connus du serveur + file en attente,
 *    donc le compteur réagit instantanément, même hors ligne.
 *  - « Annuler » retire le dernier appui : directement de la file s'il n'a
 *    jamais été envoyé, sinon par une opération {op:'undo', uid}.
 */
(function () {
  'use strict';

  var C = window.CP;
  if (!C) return;

  var $ = function (id) { return document.getElementById(id); };
  var app = $('app');

  /* ---------- stockage (jamais bloquant : le compteur marche sans) ---------- */

  var NS = 'cp:' + C.eventId + ':';
  function load(key, fallback, ns) {
    try { var v = localStorage.getItem((ns || NS) + key); return v === null ? fallback : JSON.parse(v); }
    catch (e) { return fallback; }
  }
  function store(key, value, ns) {
    try { localStorage.setItem((ns || NS) + key, JSON.stringify(value)); } catch (e) { /* stockage plein ou interdit */ }
  }

  var PREF = 'cp:pref:';
  var prefs = {
    vibrate: load('vibrate', true, PREF),
    sound: load('sound', false, PREF),
    wake: load('wake', true, PREF)
  };

  /* ---------- état ---------- */

  var queue = load('queue', []);        // opérations pas encore acquittées par le serveur
  var history = load('history', []);    // derniers appuis de CE téléphone (pour « Annuler »)
  var gate = load('gate', C.gates[0].id);
  if (!C.gates.some(function (g) { return g.id === gate; })) gate = C.gates[0].id;
  var qty = 1;
  var state = 'ok';                     // ok | offline | auth
  var busy = false;
  var again = false;
  var timer = null;

  // Totaux du serveur : les plus récents entre ceux de la page et ceux gardés
  // sur le téléphone (utile quand la page vient du cache, hors ligne).
  var server = C.totals;
  var saved = load('server', null);
  if (saved && saved.at > (C.generated || 0)) server = saved.totals;

  function uid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    var s = '';
    for (var i = 0; i < 24; i++) s += Math.floor(Math.random() * 36).toString(36);
    return s;
  }
  function key(op) { return op.op + ':' + op.uid; }
  function persist() { store('queue', queue); store('history', history); }

  /* ---------- calcul de l'affichage ---------- */

  function view() {
    var per = {};
    C.gates.forEach(function (g) { per[g.id] = { name: g.name, inn: 0, out: 0 }; });
    (server.gates || []).forEach(function (g) {
      if (per[g.id]) { per[g.id].inn = g.in; per[g.id].out = g.out; }
    });
    queue.forEach(function (op) {
      var t = per[op.gate];
      if (!t) return;
      var sign = op.op === 'tap' ? 1 : -1;   // une annulation retire ce que l'appui avait ajouté
      if (op.dir > 0) t.inn += sign * op.qty; else t.out += sign * op.qty;
    });
    var tin = 0, tout = 0;
    Object.keys(per).forEach(function (k) { tin += per[k].inn; tout += per[k].out; });
    return { per: per, tin: tin, tout: tout, present: Math.max(0, tin - tout) };
  }

  function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  /* ---------- rendu ---------- */

  var lastPresent = null;

  function render() {
    var v = view();
    var shown = C.trackExits ? v.present : v.tin;
    var num = $('present');
    num.textContent = fmt(shown);
    if (lastPresent !== null && lastPresent !== shown) {
      num.classList.remove('bump'); void num.offsetWidth; num.classList.add('bump');
    }
    lastPresent = shown;
    $('presentLabel').textContent = C.trackExits ? 'personnes sur place' : 'visiteurs comptés';

    var me = v.per[gate];
    var sub = 'Ce poste : <b>' + fmt(me.inn) + '</b> entrée' + (me.inn > 1 ? 's' : '');
    if (C.trackExits) sub += ' · <b>' + fmt(me.out) + '</b> sortie' + (me.out > 1 ? 's' : '');
    sub += '<br>Tous postes : <b>' + fmt(v.tin) + '</b> entrée' + (v.tin > 1 ? 's' : '');
    if (C.trackExits) sub += ' · <b>' + fmt(v.tout) + '</b> sortie' + (v.tout > 1 ? 's' : '');
    $('sub').innerHTML = sub;

    if (C.capacity > 0) {
      var ratio = shown / C.capacity;
      $('gauge').style.width = Math.min(100, ratio * 100) + '%';
      $('gaugeText').textContent = Math.round(ratio * 100) + ' % de la capacité (' + fmt(C.capacity) + ')';
      app.setAttribute('data-level', ratio >= 0.9 ? 'full' : ratio >= 0.7 ? 'warn' : 'ok');
    }

    // postes
    var nav = $('gates');
    if (C.gates.length > 1) {
      nav.innerHTML = '';
      C.gates.forEach(function (g) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'cp-gate' + (g.id === gate ? ' on' : '');
        b.setAttribute('aria-pressed', g.id === gate ? 'true' : 'false');
        var name = document.createElement('span');
        name.textContent = g.name;
        var cnt = document.createElement('small');
        cnt.textContent = fmt(v.per[g.id].inn);
        b.appendChild(name); b.appendChild(cnt);
        b.addEventListener('click', function () { gate = g.id; store('gate', gate); render(); });
        nav.appendChild(b);
      });
    }

    // quantité
    var q = $('qty');
    if (!q.children.length) {
      (C.qtyChoices || [1, 2, 3, 5, 10]).forEach(function (n) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = '×' + n;
        b.setAttribute('data-q', n);
        b.addEventListener('click', function () { qty = n; render(); });
        q.appendChild(b);
      });
    }
    Array.prototype.forEach.call(q.children, function (b) {
      var on = Number(b.getAttribute('data-q')) === qty;
      b.className = on ? 'on' : '';
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    $('inSign').textContent = '+' + qty;
    if ($('outSign')) $('outSign').textContent = '−' + qty;

    $('btnUndo').disabled = history.length === 0;
    renderPill();
  }

  function renderPill() {
    var p = $('sync');
    var n = queue.length;
    var text, st;
    if (state === 'auth') { text = '🔒 Reconnexion requise'; st = 'bad'; }
    else if (state === 'offline') { text = '⚠ Hors ligne' + (n ? ' · ' + n + ' en attente' : ''); st = 'warn'; }
    else if (n) { text = '↻ ' + n + ' en attente'; st = 'warn'; }
    else { text = '✓ À jour'; st = 'ok'; }
    p.textContent = text;
    p.setAttribute('data-state', st);
  }

  function banner(message, linkText, href) {
    var b = $('banner');
    b.textContent = message + ' ';
    if (linkText) {
      var a = document.createElement('a');
      a.href = href || location.href;
      a.textContent = linkText;
      b.appendChild(a);
    }
    b.hidden = false;
  }

  /* ---------- retours sensoriels ---------- */

  var audio = null;
  function beep(freq) {
    if (!prefs.sound) return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      var o = audio.createOscillator(), g = audio.createGain();
      o.frequency.value = freq; o.type = 'sine';
      g.gain.value = 0.08;
      o.connect(g); g.connect(audio.destination);
      o.start(); o.stop(audio.currentTime + 0.05);
    } catch (e) { /* pas de son, tant pis */ }
  }
  function buzz(ms) {
    if (prefs.vibrate && navigator.vibrate) { try { navigator.vibrate(ms); } catch (e) {} }
  }

  /* ---------- actions ---------- */

  // n : nombre de personnes (par défaut la quantité choisie) ; auto : appui déclenché par la caméra.
  function tap(dir, n, auto) {
    var count = n || qty;
    var op = { op: 'tap', uid: uid(), gate: gate, dir: dir, qty: count, ts: Math.floor(Date.now() / 1000) };
    queue.push(op);
    history.push({ uid: op.uid, gate: gate, dir: dir, qty: count });
    if (history.length > 30) history.shift();
    persist();
    if (!auto) qty = 1;           // la quantité ne vaut que pour un appui manuel
    buzz(dir > 0 ? 18 : [12, 40, 12]);
    beep(dir > 0 ? 880 : 440);
    render();
    scheduleSync(1500);
  }

  function undo() {
    var last = history.pop();
    if (!last) return;
    var idx = -1;
    for (var i = queue.length - 1; i >= 0; i--) {
      if (queue[i].op === 'tap' && queue[i].uid === last.uid) { idx = i; break; }
    }
    if (idx >= 0 && !queue[idx].sent) {
      queue.splice(idx, 1);       // jamais parti : on l'efface, le serveur n'en saura rien
    } else {
      // Déjà (peut-être) reçu par le serveur : on envoie une annulation.
      queue.push({ op: 'undo', uid: last.uid, gate: last.gate, dir: last.dir, qty: last.qty });
    }
    persist();
    buzz(30);
    render();
    scheduleSync(1500);
  }

  /* ---------- synchronisation ---------- */

  function scheduleSync(ms) {
    clearTimeout(timer);
    timer = setTimeout(sync, ms);
  }

  function sync() {
    if (busy) { again = true; return; }
    busy = true;
    var batch = queue.slice(0, 200);
    batch.forEach(function (op) { op.sent = true; });   // a peut-être atteint le serveur : voir undo()
    if (batch.length) persist();

    var ctl = window.AbortController ? new AbortController() : null;
    var abortTimer = setTimeout(function () { if (ctl) ctl.abort(); }, 9000);

    fetch(C.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf },
      body: JSON.stringify({
        event: C.eventId,
        ops: batch.map(function (o) { return { op: o.op, uid: o.uid, gate: o.gate, dir: o.dir, qty: o.qty, ts: o.ts }; })
      }),
      signal: ctl ? ctl.signal : undefined
    }).then(function (res) {
      if (res.status === 401 || res.status === 403) { var e = new Error('auth'); e.auth = true; throw e; }
      if (res.status === 404) { var e2 = new Error('gone'); e2.gone = true; throw e2; }
      if (!res.ok) throw new Error('http ' + res.status);
      return res.json();
    }).then(function (j) {
      var done = {};
      batch.forEach(function (o) { done[key(o)] = true; });
      queue = queue.filter(function (o) { return !done[key(o)]; });
      server = j.totals;
      store('server', { at: j.server_time || Math.floor(Date.now() / 1000), totals: j.totals });
      state = 'ok';
      persist();

      if (j.active_event && j.active_event !== C.eventId) {
        banner('Un autre événement est maintenant actif.', 'Recharger', location.href);
      } else if (j.config && C.configHash && j.config !== C.configHash) {
        banner('La configuration (postes, capacité…) a changé.', 'Recharger', location.href);
      }
    }).catch(function (e) {
      if (e && e.auth) {
        state = 'auth';
        banner('Votre session a expiré. Vos appuis sont gardés sur ce téléphone.', 'Se reconnecter', '/comptage/login.php');
      } else if (e && e.gone) {
        banner('Cet événement n’existe plus.', 'Recharger', location.href);
      } else {
        state = 'offline';
      }
    }).then(function () {
      clearTimeout(abortTimer);
      busy = false;
      render();
      if (again || (queue.length && state === 'ok')) { again = false; scheduleSync(400); }
    });
  }

  /* ---------- écran allumé ---------- */

  var lock = null;
  function wake() {
    if (!prefs.wake || !navigator.wakeLock || document.visibilityState !== 'visible') return;
    navigator.wakeLock.request('screen').then(function (l) {
      lock = l;
      l.addEventListener('release', function () { lock = null; });
    }).catch(function () {});
  }
  function unwake() { if (lock) { lock.release().catch(function () {}); lock = null; } }


  /* ---------- comptage par caméra ----------
   * Le téléphone filme le passage ; un modèle de détection de personnes
   * (MediaPipe, hébergé sur ce site) tourne sur le téléphone ; comptage-vision.js
   * suit chaque personne et compte celles qui franchissent la ligne. Les images
   * ne quittent jamais l'appareil et ne sont jamais enregistrées. Chaque
   * franchissement devient un appui normal (même file d'attente, même annulation).
   */

  var VENDOR = '/assets/vendor/mediapipe/';
  var MIN_SCORE = 0.45;       // confiance minimale d'une détection
  var MIN_HEIGHT = 0.12;      // ignore les silhouettes trop petites (public lointain, hors de la zone)
  var DETECT_EVERY_MS = 90;   // ~11 images analysées par seconde au maximum
  var SLOW_FPS = 4;           // en dessous, on avertit : la précision baisse
  var HINT = $('camHint').textContent;

  var cam = {
    mode: load('mode', 'manual', PREF) === 'cam' ? 'cam' : 'manual',
    stream: null, detector: null, running: false, starting: false,
    facing: load('facing', 'environment', PREF),
    counter: null, flashes: {}, lastT: -1, lastDetect: 0, fps: 0, frames: 0, fpsAt: 0, people: 0,
    line: load('line', null, PREF) || { x1: 0.5, y1: 0.88, x2: 0.5, y2: 0.12 },
    invert: !!load('invert', false, PREF),
    drag: null, rect: { left: 0, top: 0, w: 0, h: 0 }
  };
  var elVideo = $('camVideo'), elCanvas = $('camCanvas'), elView = $('camView');

  function camStatus(text, showStart) {
    $('camStatus').textContent = text;
    $('camStart').hidden = !showStart;
    $('camOverlay').hidden = !text;
  }

  function camMessage(e) {
    var name = e && e.name;
    if (name === 'NotAllowedError' || name === 'SecurityError') return 'Caméra refusée. Autorisez-la pour ce site dans les réglages du navigateur, puis réessayez.';
    if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'Aucune caméra trouvée sur cet appareil.';
    if (name === 'NotReadableError') return 'La caméra est déjà utilisée par une autre application.';
    if (!window.isSecureContext || !navigator.mediaDevices) return 'La caméra exige une connexion sécurisée (https).';
    return 'Impossible de démarrer la détection (' + ((e && e.message) || 'erreur') + '). Utilisez le mode manuel.';
  }

  function setMode(mode) {
    cam.mode = mode;
    store('mode', mode, PREF);
    app.setAttribute('data-mode', mode);
    $('cam').hidden = mode !== 'cam';
    Array.prototype.forEach.call($('mode').children, function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-mode') === mode ? 'true' : 'false');
    });
    if (mode === 'cam') {
      camStatus('La caméra compte les personnes qui franchissent la ligne. Les images restent sur ce téléphone : elles ne sont ni enregistrées ni envoyées.', true);
      layoutCam();
    } else {
      stopCamera();
    }
  }

  function layoutCam() {
    var cw = elView.clientWidth, ch = elView.clientHeight;
    var vw = elVideo.videoWidth || 4, vh = elVideo.videoHeight || 3;
    var va = vw / vh, w, h;
    if (cw / ch > va) { h = ch; w = ch * va; } else { w = cw; h = cw / va; }
    var left = (cw - w) / 2, top = (ch - h) / 2;
    [elVideo, elCanvas].forEach(function (el) {
      el.style.left = left + 'px'; el.style.top = top + 'px'; el.style.width = w + 'px'; el.style.height = h + 'px';
    });
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    elCanvas.width = Math.round(w * dpr); elCanvas.height = Math.round(h * dpr);
    cam.rect = { left: left, top: top, w: w, h: h, dpr: dpr };
    if (cam.counter && elVideo.videoWidth) cam.counter.setAspect(va);
    drawCam();
  }

  function loadDetector() {
    if (cam.detector) return Promise.resolve(cam.detector);
    return import(VENDOR + 'vision_bundle.js').then(function (mod) {
      return mod.FilesetResolver.forVisionTasks(VENDOR + 'wasm').then(function (fileset) {
        function make(delegate) {
          return mod.ObjectDetector.createFromOptions(fileset, {
            baseOptions: { modelAssetPath: VENDOR + 'efficientdet_lite0.tflite', delegate: delegate },
            runningMode: 'VIDEO', scoreThreshold: MIN_SCORE, maxResults: 25, categoryAllowlist: ['person']
          });
        }
        return make('GPU').catch(function () { return make('CPU'); });
      });
    }).then(function (d) { cam.detector = d; return d; });
  }

  function startCamera() {
    if (cam.starting || cam.running) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { camStatus(camMessage(), false); return; }
    cam.starting = true;
    camStatus('Démarrage de la caméra…', false);
    stopStream();
    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: cam.facing }, width: { ideal: 640 }, height: { ideal: 480 } }, audio: false
    }).then(function (stream) {
      cam.stream = stream;
      elVideo.srcObject = stream;
      return elVideo.play();
    }).then(function () {
      camStatus('Chargement du modèle de détection…', false);
      return loadDetector();
    }).then(function () {
      if (cam.mode !== 'cam') { stopCamera(); return; }
      cam.counter = new CpVision.LineCounter({
        line: cam.line, invert: cam.invert, aspect: (elVideo.videoWidth / elVideo.videoHeight) || 4 / 3
      });
      cam.running = true; cam.starting = false; cam.lastT = -1;
      layoutCam();
      camStatus('', false);
      loopCam();
    }).catch(function (e) {
      cam.starting = false;
      stopStream();
      camStatus(camMessage(e), true);
    });
  }

  function stopStream() {
    if (cam.stream) { cam.stream.getTracks().forEach(function (t) { t.stop(); }); cam.stream = null; }
    elVideo.srcObject = null;
  }

  function stopCamera() {
    cam.running = false; cam.starting = false;
    stopStream();
    var ctx = elCanvas.getContext('2d');
    ctx.clearRect(0, 0, elCanvas.width, elCanvas.height);
    if (cam.mode === 'cam') camStatus('Caméra arrêtée.', true);
  }

  function nextFrame(fn) {
    if (elVideo.requestVideoFrameCallback) elVideo.requestVideoFrameCallback(function () { fn(); });
    else setTimeout(fn, DETECT_EVERY_MS);
  }

  function loopCam() {
    if (!cam.running) return;
    var now = performance.now();
    if (elVideo.readyState >= 2 && elVideo.videoWidth && now - cam.lastDetect >= DETECT_EVERY_MS && elVideo.currentTime !== cam.lastT) {
      cam.lastT = elVideo.currentTime;
      cam.lastDetect = now;
      var result;
      try { result = cam.detector.detectForVideo(elVideo, now); } catch (e) { result = null; }
      if (result) handleDetections(result.detections || [], now);
    }
    nextFrame(loopCam);
  }

  function handleDetections(list, now) {
    var vw = elVideo.videoWidth, vh = elVideo.videoHeight;
    var boxes = [];
    list.forEach(function (d) {
      var b = d.boundingBox, score = d.categories && d.categories[0] ? d.categories[0].score : 1;
      if (!b || score < MIN_SCORE || b.height / vh < MIN_HEIGHT) return;
      boxes.push({ x: b.originX / vw, y: b.originY / vh, w: b.width / vw, h: b.height / vh });
    });
    cam.people = boxes.length;
    cam.counter.update(boxes, now).forEach(function (ev) {
      cam.flashes[ev.id] = { until: now + 700, dir: ev.dir };
      tap(ev.dir, 1, true);
    });
    cam.frames++;
    if (now - cam.fpsAt >= 1000) {
      cam.fps = cam.frames * 1000 / (now - cam.fpsAt); cam.frames = 0; cam.fpsAt = now;
      // En dessous de ~4 images/s, les personnes bougent trop entre deux analyses : des passages peuvent être manqués.
      var slow = cam.fps < SLOW_FPS;
      if (slow !== cam.slow) {
        cam.slow = slow;
        $('camHint').textContent = slow
          ? '⚠ Détection lente (' + cam.fps.toFixed(1) + ' img/s) : des passages peuvent être manqués. Surveillez le total et corrigez avec +1 / −1, ou repassez en manuel.'
          : HINT;
        $('camHint').style.color = slow ? 'var(--accent)' : '';
      }
    }
    drawCam(now);
  }

  function drawCam(now) {
    var r = cam.rect, ctx = elCanvas.getContext('2d');
    if (!r.w) return;
    var W = elCanvas.width, H = elCanvas.height, k = r.dpr || 1;
    ctx.clearRect(0, 0, W, H);

    // personnes suivies
    if (cam.running && cam.counter) {
      cam.counter.visible().forEach(function (t) {
        var f = cam.flashes[t.id], on = f && f.until > (now || 0);
        ctx.lineWidth = (on ? 4 : 2) * k;
        ctx.strokeStyle = on ? (f.dir > 0 ? '#7bd88f' : '#ff8a6b') : 'rgba(255,255,255,0.75)';
        ctx.strokeRect((t.x - t.w / 2) * W, (t.y - t.h / 2) * H, t.w * W, t.h * H);
      });
    }

    // ligne, poignées et flèche du sens « entrée »
    var L = cam.line, x1 = L.x1 * W, y1 = L.y1 * H, x2 = L.x2 * W, y2 = L.y2 * H;
    var dx = x2 - x1, dy = y2 - y1, len = Math.hypot(dx, dy) || 1;
    var nx = -dy / len, ny = dx / len;                 // normale vers le côté « positif » (voir comptage-vision.js)
    if (cam.invert) { nx = -nx; ny = -ny; }
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#ffd34d'; ctx.lineWidth = 3 * k;
    ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
    var mx = (x1 + x2) / 2, my = (y1 + y2) / 2, a = 34 * k;
    var tx = mx + nx * a, ty = my + ny * a;
    ctx.beginPath(); ctx.moveTo(mx, my); ctx.lineTo(tx, ty); ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(tx, ty);
    ctx.lineTo(tx - nx * 10 * k - ny * 8 * k, ty - ny * 10 * k + nx * 8 * k);
    ctx.lineTo(tx - nx * 10 * k + ny * 8 * k, ty - ny * 10 * k - nx * 8 * k);
    ctx.closePath(); ctx.fillStyle = '#ffd34d'; ctx.fill();
    ctx.font = '700 ' + 12 * k + 'px sans-serif'; ctx.fillStyle = '#ffd34d';
    ctx.textAlign = nx >= 0 ? 'left' : 'right';
    ctx.fillText('ENTRÉE', tx + (nx >= 0 ? 8 : -8) * k, ty + 4 * k);
    [[x1, y1], [x2, y2]].forEach(function (p) {
      ctx.beginPath(); ctx.arc(p[0], p[1], 11 * k, 0, 6.2832);
      ctx.fillStyle = 'rgba(255,211,77,0.35)'; ctx.fill();
      ctx.strokeStyle = '#ffd34d'; ctx.lineWidth = 2.5 * k; ctx.stroke();
    });

    // état
    if (cam.running) {
      ctx.textAlign = 'left'; ctx.font = '600 ' + 11 * k + 'px sans-serif'; ctx.fillStyle = cam.slow ? '#ffb199' : 'rgba(255,255,255,0.9)';
      ctx.fillText(cam.people + ' personne' + (cam.people > 1 ? 's' : '') + ' · ' + cam.fps.toFixed(cam.fps < 10 ? 1 : 0) + ' img/s' + (cam.slow ? ' ⚠' : ''), 8 * k, 16 * k);
    }
  }

  // réglage de la ligne au doigt : on attrape l'une des deux extrémités
  function camPoint(e) {
    var box = elCanvas.getBoundingClientRect();
    return { x: Math.min(1, Math.max(0, (e.clientX - box.left) / box.width)), y: Math.min(1, Math.max(0, (e.clientY - box.top) / box.height)), box: box };
  }
  elCanvas.style.pointerEvents = 'auto';
  elCanvas.addEventListener('pointerdown', function (e) {
    var p = camPoint(e), best = null, bestD = 44;       // 44 px de rayon de saisie
    [['1', cam.line.x1, cam.line.y1], ['2', cam.line.x2, cam.line.y2]].forEach(function (h) {
      var d = Math.hypot((h[1] - p.x) * p.box.width, (h[2] - p.y) * p.box.height);
      if (d < bestD) { bestD = d; best = h[0]; }
    });
    if (best) { cam.drag = best; elCanvas.setPointerCapture(e.pointerId); e.preventDefault(); }
  });
  elCanvas.addEventListener('pointermove', function (e) {
    if (!cam.drag) return;
    var p = camPoint(e);
    cam.line['x' + cam.drag] = p.x; cam.line['y' + cam.drag] = p.y;
    drawCam(performance.now());
  });
  function endDrag() {
    if (!cam.drag) return;
    cam.drag = null;
    store('line', cam.line, PREF);
    if (cam.counter) cam.counter.setLine(cam.line);
  }
  elCanvas.addEventListener('pointerup', endDrag);
  elCanvas.addEventListener('pointercancel', endDrag);

  $('mode').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-mode]');
    if (!b) return;
    setMode(b.getAttribute('data-mode'));
    if (b.getAttribute('data-mode') === 'cam') startCamera();   // le clic fournit le « geste » exigé par iOS
  });
  $('camStart').addEventListener('click', startCamera);
  $('camStop').addEventListener('click', stopCamera);
  $('camFlip').addEventListener('click', function () {
    cam.invert = !cam.invert;
    store('invert', cam.invert, PREF);
    if (cam.counter) cam.counter.setInvert(cam.invert);
    drawCam(performance.now());
  });
  $('camSwitch').addEventListener('click', function () {
    cam.facing = cam.facing === 'environment' ? 'user' : 'environment';
    store('facing', cam.facing, PREF);
    stopCamera();
    camStatus('', false);
    startCamera();
  });
  window.addEventListener('resize', layoutCam);
  elVideo.addEventListener('loadedmetadata', layoutCam);
  // En arrière-plan, le navigateur coupe la caméra : on la relance au retour.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && cam.mode === 'cam' && !cam.running && !cam.starting && cam.stream === null && cam.wasRunning) startCamera();
    if (document.visibilityState === 'hidden') { cam.wasRunning = cam.running; if (cam.running) stopCamera(); }
  });

  /* ---------- branchements ---------- */

  $('btnIn').addEventListener('click', function () { tap(1); });
  if ($('btnOut')) $('btnOut').addEventListener('click', function () { tap(-1); });
  $('btnUndo').addEventListener('click', undo);
  $('sync').addEventListener('click', function () { if (state === 'auth') { location.href = '/comptage/login.php'; } else { sync(); } });

  [['optVibrate', 'vibrate'], ['optSound', 'sound'], ['optWake', 'wake']].forEach(function (pair) {
    var el = $(pair[0]);
    el.checked = !!prefs[pair[1]];
    el.addEventListener('change', function () {
      prefs[pair[1]] = el.checked;
      store(pair[1], el.checked, PREF);
      if (pair[1] === 'wake') { if (el.checked) wake(); else unwake(); }
      if (pair[1] === 'vibrate' && el.checked) buzz(30);
    });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { wake(); sync(); }
  });
  window.addEventListener('online', function () { sync(); });
  window.addEventListener('offline', function () { state = 'offline'; renderPill(); });
  // Pas de zoom involontaire à force de taper vite (iOS ignore user-scalable=no).
  document.addEventListener('gesturestart', function (e) { e.preventDefault(); });
  // Raccourcis clavier (tablette avec clavier, essais sur ordinateur).
  document.addEventListener('keydown', function (e) {
    if (e.target && /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
    if (e.key === '+') tap(1);
    else if (e.key === '-' && C.trackExits) tap(-1);
    else if (e.key === 'Backspace') undo();
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/comptage/sw.js', { scope: '/comptage/' }).catch(function () {});
  }

  render();
  setMode(cam.mode);      // en mode caméra, l'utilisateur relance lui-même la caméra (geste exigé par iOS)
  wake();
  sync();
  setInterval(function () { if (document.visibilityState === 'visible') sync(); }, 10000);
})();
