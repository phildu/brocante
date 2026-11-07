/**
 * Compteur d'attente pour les générations IA (texte et photos).
 *
 * Affiche une petite pastille « Génération en cours… 12 s » dès qu'une requête
 * de génération part vers le serveur, la fait avancer chaque seconde, puis la
 * retire à la réponse (succès ou erreur).
 *
 *  - Branchement automatique : window.fetch est enveloppé, et les envois de
 *    formulaires sont écoutés. Seules les requêtes listées dans RULES (point
 *    d'entrée + action) déclenchent le compteur : les envois de fichiers, les
 *    enregistrements et les interrogations de file n'en affichent pas.
 *  - La pastille se place sous le bouton qui a déclenché la génération (dernier
 *    clic) ; sans bouton identifiable ou s'il est masqué, elle s'affiche en bas
 *    de l'écran.
 *  - Utilisation manuelle (autre appelant, XHR…) :
 *        var t = AiWait.start({ anchor: bouton, label: 'Génération en cours…' });
 *        …                                   t.stop();
 *        AiWait.track(promesse, { anchor: bouton });   // renvoie la promesse
 *  - Un formulaire peut aussi s'y abonner avec l'attribut data-ai-wait
 *    (libellé facultatif : data-ai-wait-label).
 *
 * Accessible : la pastille est un role="status" aria-live="polite" ; les
 * secondes sont masquées aux lecteurs d'écran pour qu'ils n'annoncent le
 * message qu'une fois. Sans dépendance.
 */
(function () {
  if (window.AiWait) return;

  var DEFAULT_LABEL = 'Génération en cours…';
  var SHOW_DELAY_MS = 400;        // pas de clignotement pour les réponses quasi immédiates
  var TRIGGER_MAX_AGE_MS = 20000; // un clic plus ancien n'est plus considéré comme le déclencheur
  var FORM_MAX_MS = 180000;       // filet : un envoi de formulaire qui ne quitte pas la page

  // Points d'entrée qui génèrent du contenu IA. `actions` : valeurs du champ
  // « action » (ou de `field`) concernées ; absent = toute requête.
  var RULES = [
    { re: /\/admin\/product-ai\.php$/ },                    // fiches : champs ↻ IA
    { re: /\/admin\/price-research\.php$/ },                // prix du marché
    { re: /\/admin\/enhance-image\.php$/ },                 // amélioration de détail (envoi de photos)
    { re: /\/admin\/media-describe\.php$/ },                // médiathèque : description
    { re: /\/admin\/regenerate-image\.php$/ },              // logos, bandeaux, héros
    { re: /\/admin\/universes-action\.php$/, actions: ['suggest'] },
    { re: /\/admin\/prompts-action\.php$/, actions: ['suggest'] },
    { re: /\/admin\/quick-add-action\.php$/, actions: ['detoure', 'sheet', 'ambiance', 'angles', 'recto', 'verso', 'objects', 'measure'] },
    { re: /\/admin\/shop-shooting-action\.php$/, actions: ['review', 'caption', 'script', 'enhance'] },
    { re: /\/admin\/shooting-assistant-action\.php$/, actions: ['objects', 'analyze', 'enhance'] },
    { re: /\/admin\/recto-verso-action\.php$/, actions: ['cutout', 'generate'] },
    { re: /\/admin\/gallery-action\.php$/, actions: ['detail', 'generate', 'mobile_variant', 'fit_formats'] },
    { re: /\/admin\/banners-action\.php$/, actions: ['reserve_from_library'] },
    { re: /\/admin\/batch-import-action\.php$/, actions: ['ai_regroup_chunk', 'process_group', 'suggest_best_photo'] },
    { re: /\/admin\/video-action\.php$/, actions: ['veo_start', 'cutout_start'] },
    { re: /\/admin\/profile-action\.php$/, field: 'avatar_action', actions: ['generate'] },
    // File d'attente : version smartphone (9:16) produite visuel par visuel (queue_mobile_variant).
    { re: /\/admin\/mobile-variants\.php$/, actions: ['run'], label: 'Version smartphone en cours…' }
  ];

  var origFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
  var jobs = [];
  var nextId = 1;
  var ticker = null;
  var floatEl = null;
  var lastTrigger = { el: null, t: 0 };

  // ── Style ────────────────────────────────────────────────────────────────
  function injectStyle() {
    if (document.getElementById('ai-wait-style')) return;
    var st = document.createElement('style');
    st.id = 'ai-wait-style';
    st.textContent =
      '.ai-wait{position:fixed;z-index:100000;display:inline-flex;align-items:center;gap:7px;max-width:calc(100vw - 16px);' +
      'padding:5px 11px;border-radius:999px;background:rgba(24,24,24,.92);color:#fff;font:500 13px/1.3 system-ui,-apple-system,"Segoe UI",sans-serif;' +
      'box-shadow:0 2px 10px rgba(0,0,0,.25);pointer-events:none;white-space:nowrap}' +
      '.ai-wait[hidden]{display:none}' +
      '.ai-wait--float{left:50%;bottom:calc(16px + env(safe-area-inset-bottom,0px));transform:translateX(-50%)}' +
      '.ai-wait__spin{width:11px;height:11px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;' +
      'animation:ai-wait-spin .8s linear infinite;flex:none}' +
      '.ai-wait__sec{font-variant-numeric:tabular-nums;opacity:.85}' +
      '@keyframes ai-wait-spin{to{transform:rotate(360deg)}}' +
      '@media (prefers-reduced-motion:reduce){.ai-wait__spin{animation:none;border-top-color:rgba(255,255,255,.35)}}';
    (document.head || document.documentElement).appendChild(st);
  }

  function makePill(extraClass) {
    injectStyle();
    var el = document.createElement('div');
    el.className = 'ai-wait' + (extraClass ? ' ' + extraClass : '');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    var spin = document.createElement('span');
    spin.className = 'ai-wait__spin';
    spin.setAttribute('aria-hidden', 'true');
    var label = document.createElement('span');
    label.className = 'ai-wait__label';
    var sec = document.createElement('span');
    sec.className = 'ai-wait__sec';
    sec.setAttribute('aria-hidden', 'true');
    el.appendChild(spin); el.appendChild(label); el.appendChild(sec);
    (document.body || document.documentElement).appendChild(el);
    return el;
  }

  function setPill(el, label, seconds) {
    var l = el.querySelector('.ai-wait__label');
    var s = el.querySelector('.ai-wait__sec');
    if (l.textContent !== label) l.textContent = label;
    s.textContent = seconds + ' s';
  }

  // ── Affichage ────────────────────────────────────────────────────────────
  function anchorRect(el) {
    if (!el || !el.isConnected) return null;
    var r = el.getBoundingClientRect();
    if (!r.width || !r.height) return null;
    var vh = window.innerHeight || document.documentElement.clientHeight;
    if (vh && (r.bottom < 0 || r.top > vh)) return null;
    return r;
  }

  function place(pill, r) {
    var w = pill.offsetWidth, h = pill.offsetHeight;
    var top = r.bottom + 6;
    if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
    var left = Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - w - 8));
    pill.style.top = top + 'px';
    pill.style.left = left + 'px';
    pill.style.bottom = 'auto';
    pill.style.transform = 'none';
  }

  function render() {
    var now = Date.now();
    var floating = [];
    jobs.forEach(function (job) {
      var elapsed = now - job.start;
      if (elapsed < SHOW_DELAY_MS) return;
      var secs = Math.floor(elapsed / 1000);
      var r = anchorRect(job.anchor);
      if (r) {
        if (!job.pill) job.pill = makePill('');
        setPill(job.pill, job.label, secs);
        place(job.pill, r);
      } else {
        if (job.pill) { job.pill.remove(); job.pill = null; }
        floating.push(job);
      }
    });
    if (floating.length) {
      if (!floatEl) floatEl = makePill('ai-wait--float');
      floatEl.hidden = false;
      var oldest = Math.floor((now - floating[0].start) / 1000);
      setPill(floatEl, floating.length === 1 ? floating[0].label : floating.length + ' générations en cours…', oldest);
    } else if (floatEl) {
      floatEl.hidden = true;
    }
  }

  function onViewportChange() { if (jobs.length) render(); }

  function ensureTicker() {
    if (ticker) return;
    ticker = setInterval(render, 250);
    window.addEventListener('scroll', onViewportChange, { passive: true, capture: true });
    window.addEventListener('resize', onViewportChange, { passive: true });
  }

  function stopTicker() {
    if (!ticker) return;
    clearInterval(ticker); ticker = null;
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
  }

  // ── API ──────────────────────────────────────────────────────────────────
  function recentTrigger() {
    if (lastTrigger.el && lastTrigger.el.isConnected && Date.now() - lastTrigger.t < TRIGGER_MAX_AGE_MS) return lastTrigger.el;
    return null;
  }

  function start(opts) {
    opts = opts || {};
    var job = {
      id: nextId++,
      start: Date.now(),
      anchor: opts.anchor || recentTrigger(),
      label: opts.label || DEFAULT_LABEL,
      pill: null
    };
    jobs.push(job);
    ensureTicker();
    var timeout = opts.maxMs ? setTimeout(function () { stop(); }, opts.maxMs) : null;
    function stop() {
      if (timeout) clearTimeout(timeout);
      var i = jobs.indexOf(job);
      if (i === -1) return;
      jobs.splice(i, 1);
      if (job.pill) { job.pill.remove(); job.pill = null; }
      if (!jobs.length) stopTicker();
      render();
    }
    return { stop: stop };
  }

  function track(promise, opts) {
    var timer = start(opts);
    Promise.resolve(promise).then(timer.stop, timer.stop);
    return promise;
  }

  function stopAll() {
    jobs.slice().forEach(function (j) { if (j.pill) j.pill.remove(); });
    jobs = [];
    stopTicker();
    if (floatEl) floatEl.hidden = true;
  }

  // ── Reconnaissance des requêtes de génération ────────────────────────────
  function fieldFrom(body, name) {
    if (!body) return '';
    try {
      if (typeof FormData !== 'undefined' && body instanceof FormData) return String(body.get(name) || '');
      if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) return String(body.get(name) || '');
      if (typeof body === 'string') {
        if (body.charAt(0) === '{') { var j = JSON.parse(body); return j && j[name] != null ? String(j[name]) : ''; }
        return String(new URLSearchParams(body).get(name) || '');
      }
    } catch (e) {}
    return '';
  }

  function ruleFor(url, getField) {
    var path;
    try { path = new URL(String(url), window.location.href).pathname; } catch (e) { return null; }
    for (var i = 0; i < RULES.length; i++) {
      var rule = RULES[i];
      if (!rule.re.test(path)) continue;
      if (!rule.actions) return rule;
      var value = getField(rule.field || 'action');
      if (rule.actions.indexOf(value) !== -1) return rule;
    }
    return null;
  }

  // ── fetch ────────────────────────────────────────────────────────────────
  if (origFetch) {
    window.fetch = function (input, init) {
      var timer = null;
      try {
        var url = typeof input === 'string' ? input : (input && input.url) || String(input);
        var body = init && init.body;
        var rule = ruleFor(url, function (name) { return fieldFrom(body, name); });
        if (rule) timer = start({ label: rule.label });
      } catch (e) {}
      var p = origFetch(input, init);
      if (timer) p.then(timer.stop, timer.stop);
      return p;
    };
  }

  // ── Formulaires classiques (la page se recharge à la réponse) ────────────
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return; // annulé (confirm…) ou repris en fetch par la page
    var form = e.target;
    if (!form || form.nodeType !== 1 || form.tagName !== 'FORM') return;
    var submitter = e.submitter || null;
    var rule = null;
    var label = null;
    // form.action peut être masqué par un champ nommé « action » : lire l'attribut.
    var url = (submitter && submitter.getAttribute('formaction')) || form.getAttribute('action') || window.location.href;
    try {
      var data = null;
      try { data = new FormData(form, submitter || undefined); } catch (err) { data = new FormData(form); }
      rule = ruleFor(url, function (name) { return String(data.get(name) || ''); });
    } catch (err) {}
    if (form.hasAttribute('data-ai-wait')) { label = form.getAttribute('data-ai-wait-label'); rule = rule || {}; }
    if (!rule) return;
    start({ anchor: submitter || recentTrigger(), label: label || rule.label, maxMs: FORM_MAX_MS });
  });

  // ── Dernier bouton cliqué = point d'ancrage de la pastille ───────────────
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('button, a[href], input[type="submit"], input[type="button"], [role="button"]') : null;
    if (t) lastTrigger = { el: t, t: Date.now() };
  }, true);

  window.addEventListener('pagehide', stopAll);

  window.AiWait = { start: start, track: track, stopAll: stopAll, rules: RULES };
})();
