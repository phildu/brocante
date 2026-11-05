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

  function tap(dir) {
    var op = { op: 'tap', uid: uid(), gate: gate, dir: dir, qty: qty, ts: Math.floor(Date.now() / 1000) };
    queue.push(op);
    history.push({ uid: op.uid, gate: gate, dir: dir, qty: qty });
    if (history.length > 30) history.shift();
    persist();
    qty = 1;                      // la quantité ne vaut que pour un appui
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
  wake();
  sync();
  setInterval(function () { if (document.visibilityState === 'visible') sync(); }, 10000);
})();
