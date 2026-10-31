// Enregistrer l'appli sur le téléphone : feuille « Installer l'appli » avec la marche à suivre du navigateur détecté
// (Safari, Chrome, Samsung Internet, Firefox, Edge, Opera, Brave, navigateur intégré de Facebook / Instagram / TikTok…).
// Utilisable seule (data-auto) ou comme tutoriel commun des boutons « Installer » des apps. Réglages par attributs data-* :
//   data-name, data-icon, data-accent, data-manifest, data-sw, data-scope, data-auto, data-delay, data-key,
//   data-quiet, data-done-key, data-snooze-key, data-no-prompt.
(function () {
  'use strict';
  if (window.PwaSave) return;

  var DAY = 86400000;
  var script = document.currentScript;
  var cfg = (script && script.dataset) || {};
  var pageKey = cfg.key || ((location.pathname || '/').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '') || 'app');
  var K = {
    done: cfg.doneKey || ('pwa_save_done_' + pageKey),
    snooze: cfg.snoozeKey || ('pwa_save_snooze_' + pageKey),
    seen: cfg.seenKey || ('pwa_save_seen_' + pageKey)
  };
  var name = cfg.name || document.title || 'cette application';
  var icon = cfg.icon || '';
  var accent = cfg.accent || '';
  var deferred = null;
  var overlay = null;
  var sheet = null;
  var lastFocus = null;

  function store(k, v) { try { localStorage.setItem(k, String(v)); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

  function isStandalone() {
    try {
      return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches)
        || navigator.standalone === true;
    } catch (e) { return navigator.standalone === true; }
  }

  // ── Appareil et navigateur ─────────────────────────────────────────────────
  function browserLabel(b) {
    return ({ safari: 'Safari', chrome: 'Chrome', edge: 'Edge', firefox: 'Firefox', samsung: 'Samsung Internet', opera: 'Opera', brave: 'Brave', vivaldi: 'Vivaldi', duckduckgo: 'DuckDuckGo', inapp: 'Navigateur intégré' })[b] || 'Navigateur';
  }

  function detect(ua) {
    ua = ua || (navigator && navigator.userAgent) || '';
    var platform = (navigator && navigator.platform) || '';
    var touch = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || ('ontouchstart' in window);
    var ipad = /ipad/i.test(ua) || (platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1);
    var iphone = /iphone|ipod/i.test(ua) && !ipad;
    var ios = iphone || ipad;
    var android = /android/i.test(ua);
    var apps = [
      [/FBAN|FBAV|FB_IAB|\[FBAN|Messenger/i, 'Facebook'],
      [/Instagram/i, 'Instagram'],
      [/TikTok|BytedanceWebview|musical_ly/i, 'TikTok'],
      [/Snapchat/i, 'Snapchat'],
      [/LinkedInApp/i, 'LinkedIn'],
      [/Pinterest/i, 'Pinterest'],
      [/Line\//i, 'LINE'],
      [/MicroMessenger/i, 'WeChat'],
      [/Twitter/i, 'X']
    ];
    var inAppName = '';
    for (var i = 0; i < apps.length; i++) {
      if (apps[i][0].test(ua)) { inAppName = apps[i][1]; break; }
    }
    var browser = 'generic';
    if (inAppName) browser = 'inapp';
    else if (/Brave/i.test(ua)) browser = 'brave';
    else if (/Vivaldi/i.test(ua)) browser = 'vivaldi';
    else if (/DuckDuckGo/i.test(ua)) browser = 'duckduckgo';
    else if (/SamsungBrowser/i.test(ua)) browser = 'samsung';
    else if (/EdgA|EdgiOS|Edg\//i.test(ua)) browser = 'edge';
    else if (/OPR\/|OPiOS|Opera Mini|Opera/i.test(ua)) browser = 'opera';
    else if (/Firefox|FxiOS/i.test(ua)) browser = 'firefox';
    else if (/CriOS|Chrome|Chromium/i.test(ua)) browser = 'chrome';
    else if (/Safari/i.test(ua)) browser = 'safari';
    var mobile = ios || android || /Mobi|Tablet/i.test(ua) || touch;
    var os = ipad ? 'iPad' : (iphone ? 'iPhone' : (android ? 'Android' : 'ordinateur'));
    return {
      ua: ua, ios: ios, android: android, mobile: mobile, touch: touch,
      inApp: !!inAppName, inAppName: inAppName, browser: browser, os: os,
      label: (inAppName ? inAppName : browserLabel(browser)) + ' · ' + os
    };
  }

  // ── Marche à suivre, selon l'appareil et le navigateur ─────────────────────
  function guide(d) {
    var steps;
    var note = '';
    var copy = false;
    var title = 'Enregistrer ' + name + ' sur cet appareil';

    if (d.inApp) {
      var nav = d.ios ? 'Safari' : 'Chrome';
      title = 'Ouvrir dans ' + nav + ' pour installer';
      steps = [
        { icon: 'dots', text: 'Touchez le menu de l’application (' + (d.ios ? '⋯' : '⋮') + ').' },
        { icon: 'browser', text: 'Choisissez « Ouvrir dans ' + nav + ' ».' },
        { icon: 'add', text: 'Dans ' + nav + ', touchez « Installer l’appli » : les étapes s’adaptent au navigateur.' }
      ];
      note = 'L’ajout à l’écran d’accueil n’est pas proposé dans l’application ' + d.inAppName + '.';
      copy = true;
    } else if (d.ios) {
      if (d.browser === 'safari') {
        steps = [
          { icon: 'share', text: 'Touchez Partager : le carré avec une flèche vers le haut (en bas sur iPhone, en haut sur iPad).' },
          { icon: 'list', text: 'Faites défiler le menu et touchez « Sur l’écran d’accueil ».' },
          { icon: 'add', text: 'Touchez « Ajouter » : l’icône rejoint vos applications.' }
        ];
        note = 'Safari enregistre l’appli en plein écran, sans passer par l’App Store.';
      } else {
        steps = [
          { icon: 'share', text: 'Touchez Partager (à droite de la barre d’adresse).' },
          { icon: 'list', text: 'Touchez « Sur l’écran d’accueil » (faites défiler si besoin).' },
          { icon: 'add', text: 'Touchez « Ajouter ».' }
        ];
        note = 'Pour l’ouvrir en plein écran, refaites la même chose depuis Safari.';
      }
    } else if (d.android) {
      if (d.browser === 'samsung') {
        steps = [
          { icon: 'menu', text: 'Touchez ☰ en bas à droite.' },
          { icon: 'list', text: 'Touchez « Ajouter page à », puis « Écran d’accueil ».' },
          { icon: 'add', text: 'Touchez « Ajouter ».' }
        ];
      } else if (d.browser === 'firefox') {
        steps = [
          { icon: 'dots', text: 'Touchez le menu ⋮ en haut à droite.' },
          { icon: 'add', text: 'Touchez « Installer » ou « Ajouter à l’écran d’accueil ».' },
          { icon: 'check', text: 'Confirmez : l’icône rejoint vos applications.' }
        ];
        note = 'Firefox propose parfois seulement un raccourci ; pour l’installation complète, ouvrez la page dans Chrome.';
      } else if (d.browser === 'opera') {
        steps = [
          { icon: 'dots', text: 'Touchez le menu en bas à droite.' },
          { icon: 'add', text: 'Touchez « Ajouter à l’écran d’accueil ».' },
          { icon: 'check', text: 'Confirmez « Ajouter ».' }
        ];
      } else if (d.browser === 'edge') {
        steps = [
          { icon: 'dots', text: 'Touchez … en bas à droite.' },
          { icon: 'add', text: 'Touchez « Ajouter à l’écran d’accueil » ou « Installer cette application ».' },
          { icon: 'check', text: 'Confirmez.' }
        ];
      } else {
        steps = [
          { icon: 'dots', text: 'Touchez le menu ⋮ en haut à droite.' },
          { icon: 'add', text: 'Touchez « Installer l’application » (ou « Ajouter à l’écran d’accueil »).' },
          { icon: 'check', text: 'Confirmez « Installer » : l’icône rejoint vos applications.' }
        ];
        if (d.browser !== 'chrome') note = 'Si l’option n’apparaît pas, ouvrez la page dans Chrome.';
      }
    } else if (d.browser === 'safari') {
      steps = [
        { icon: 'browser', text: 'Dans la barre de menus de Safari, choisissez Fichier → Partager.' },
        { icon: 'add', text: 'Touchez « Ajouter au Dock ».' },
        { icon: 'check', text: 'L’appli rejoint le Dock et le Launchpad.' }
      ];
      note = 'Disponible sur macOS Sonoma ou plus récent.';
    } else if (d.browser === 'firefox') {
      steps = [
        { icon: 'browser', text: 'Firefox n’installe pas les web apps.' },
        { icon: 'check', text: 'Ouvrez cette page dans Chrome, Edge ou Safari pour l’enregistrer.' },
        { icon: 'list', text: 'Sinon, ajoutez-la aux favoris : elle restera à portée de main.' }
      ];
      note = 'Aucune icône d’application ne sera créée depuis Firefox.';
    } else {
      steps = [
        { icon: 'browser', text: 'Regardez à droite de la barre d’adresse : une icône d’écran avec une flèche propose l’installation.' },
        { icon: 'dots', text: 'Sinon, ouvrez le menu ⋮ et choisissez « Installer ' + name + ' ».' },
        { icon: 'check', text: 'Confirmez : l’application s’ouvre dans sa propre fenêtre.' }
      ];
      note = 'L’invite ne s’affiche que sur un site sécurisé (HTTPS) et dans Chrome, Edge, Brave ou Opera.';
    }
    return { title: title, subtitle: d.label, steps: steps, note: note, copy: copy };
  }

  var ICONS = {
    share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M8 8l4-4 4 4"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>',
    dots: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg>',
    menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
    list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/></svg>',
    add: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    browser: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4.5 4.5L19 7"/></svg>'
  };

  function css() {
    if (document.getElementById('pwa-save-css')) return;
    var s = document.createElement('style');
    s.id = 'pwa-save-css';
    s.textContent = [
      '.ps-over{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:flex-end;justify-content:center;background:rgba(20,16,10,.55);opacity:0;transition:opacity .2s ease;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}',
      '.ps-over.is-on{opacity:1}',
      '.ps-sheet{--ps-a:#b0622b;--ps-ink:#1f1b16;--ps-soft:#655d53;--ps-line:#e6dfd4;--ps-bg:#fff;position:relative;width:min(560px,100%);max-height:92dvh;overflow:auto;background:var(--ps-bg);color:var(--ps-ink);border-radius:20px 20px 0 0;padding:20px 20px calc(18px + env(safe-area-inset-bottom));box-shadow:0 -12px 48px rgba(0,0,0,.35);transform:translateY(28px);transition:transform .25s ease}',
      '.ps-over.is-on .ps-sheet{transform:none}',
      '@media(min-width:700px){.ps-over{align-items:center;padding:24px}.ps-sheet{border-radius:20px}}',
      '@media(prefers-reduced-motion:reduce){.ps-over,.ps-sheet{transition:none}}',
      '.ps-x{position:absolute;top:8px;right:8px;width:42px;height:42px;border:0;border-radius:50%;background:transparent;color:var(--ps-soft);font-size:26px;line-height:1;cursor:pointer}',
      '.ps-x:hover{background:rgba(0,0,0,.05)}',
      '.ps-head{display:flex;gap:14px;align-items:center;padding-right:36px}',
      '.ps-ico{width:52px;height:52px;border-radius:13px;object-fit:cover;flex:none;background:#f1ece3}',
      '.ps-ico-fallback{display:grid;place-items:center;font-size:1.35rem;font-weight:800;color:#fff;background:var(--ps-a)}',
      '.ps-headtxt{min-width:0}',
      '.ps-kicker{margin:0;font-size:.72rem;font-weight:800;letter-spacing:.11em;text-transform:uppercase;color:var(--ps-a)}',
      '.ps-head h2{margin:2px 0;font-size:1.15rem;line-height:1.25;letter-spacing:-.01em}',
      '.ps-sub{margin:0;font-size:.82rem;color:var(--ps-soft)}',
      '.ps-primary,.ps-ghost,.ps-later,.ps-never{font:inherit;cursor:pointer;border-radius:12px;min-height:46px;padding:10px 16px}',
      '.ps-primary{display:block;width:100%;margin:16px 0 6px;border:1px solid var(--ps-a);background:var(--ps-a);color:#fff;font-weight:750}',
      '.ps-native{margin:0 0 12px;font-size:.82rem;color:var(--ps-soft);text-align:center}',
      '.ps-steps{list-style:none;margin:16px 0 8px;padding:0;display:grid;gap:10px}',
      '.ps-steps li{display:grid;grid-template-columns:26px 26px 1fr;gap:8px;align-items:start}',
      '.ps-n{display:grid;place-items:center;width:24px;height:24px;border-radius:50%;background:#f7efe5;color:var(--ps-a);font-size:.78rem;font-weight:800}',
      '@supports(background:color-mix(in srgb,red 10%,white)){.ps-n{background:color-mix(in srgb,var(--ps-a) 14%,#fff)}}',
      '.ps-ic{display:grid;place-items:center;width:24px;height:24px;color:var(--ps-a)}',
      '.ps-ic svg{width:20px;height:20px}',
      '.ps-t{font-size:.94rem;line-height:1.4}',
      '.ps-note{margin:6px 0 0;padding:10px 12px;border-radius:10px;background:#faf7f1;border:1px solid var(--ps-line);font-size:.84rem;color:var(--ps-soft)}',
      '.ps-ghost{display:block;width:100%;margin:12px 0 0;border:1px solid var(--ps-line);background:#fff;color:var(--ps-ink);font-weight:650}',
      '.ps-fine{margin:10px 0 0;font-size:.76rem;color:var(--ps-soft);text-align:center}',
      '.ps-actions{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}',
      '.ps-later,.ps-never{flex:1;min-width:130px}',
      '.ps-later{border:1px solid var(--ps-line);background:#fff;color:var(--ps-ink);font-weight:650}',
      '.ps-never{border:0;background:transparent;color:var(--ps-soft);text-decoration:underline;font-size:.86rem}'
    ].join('');
    document.head.appendChild(s);
  }

  function el(t, cls, text) {
    var n = document.createElement(t);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function fire(type) {
    try { document.dispatchEvent(new CustomEvent('pwasave:' + type)); } catch (e) {}
  }

  function copyLink(button) {
    var url = location.href;
    function ok() {
      button.textContent = 'Lien copié ✓';
      setTimeout(function () { button.textContent = 'Copier le lien'; }, 2200);
    }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = url;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;left:-9999px;top:0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); ok(); } catch (e) { button.textContent = 'Copiez l’adresse de la page'; }
      document.body.removeChild(ta);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(ok, fallback);
    else fallback();
  }

  function close() {
    if (!overlay) return;
    var o = overlay;
    overlay = null;
    sheet = null;
    document.removeEventListener('keydown', onKey);
    o.classList.remove('is-on');
    document.body.style.overflow = o._prevOverflow || '';
    setTimeout(function () { if (o.parentNode) o.parentNode.removeChild(o); }, 220);
    if (lastFocus && lastFocus.focus) {
      try { lastFocus.focus(); } catch (e) {}
    }
  }

  function onKey(e) {
    if (e.key === 'Escape') { close(); return; }
    if (e.key !== 'Tab' || !sheet) return;
    var f = sheet.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function promptInstall(button) {
    var e = deferred;
    deferred = null;
    if (!e) { button.hidden = true; return; }
    try {
      e.prompt();
      e.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') {
          store(K.done, '1');
          fire('installed');
          close();
        }
      }, function () {});
    } catch (err) { button.hidden = true; }
  }

  function open() {
    if (isStandalone() || overlay) return false;
    var d = detect();
    var g = guide(d);
    css();
    lastFocus = document.activeElement;
    var over = el('div', 'ps-over');
    var box = el('div', 'ps-sheet');
    sheet = box;
    if (accent) box.style.setProperty('--ps-a', accent);
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'ps-title');

    var x = el('button', 'ps-x');
    x.type = 'button';
    x.setAttribute('aria-label', 'Fermer');
    x.innerHTML = '&times;';
    x.addEventListener('click', close);
    box.appendChild(x);

    var head = el('div', 'ps-head');
    if (icon) {
      var img = document.createElement('img');
      img.className = 'ps-ico';
      img.src = icon;
      img.alt = '';
      img.width = 52;
      img.height = 52;
      head.appendChild(img);
    } else {
      head.appendChild(el('div', 'ps-ico ps-ico-fallback', (name.charAt(0) || 'A').toUpperCase()));
    }
    var txt = el('div', 'ps-headtxt');
    txt.appendChild(el('p', 'ps-kicker', 'Installer l’appli'));
    var h = el('h2', null, g.title);
    h.id = 'ps-title';
    txt.appendChild(h);
    txt.appendChild(el('p', 'ps-sub', g.subtitle));
    head.appendChild(txt);
    box.appendChild(head);

    if (deferred) {
      var install = el('button', 'ps-primary', 'Installer maintenant');
      install.type = 'button';
      install.addEventListener('click', function () { promptInstall(install); });
      box.appendChild(install);
      box.appendChild(el('p', 'ps-native', 'Le navigateur ouvre sa fenêtre d’installation. Sinon, suivez les étapes ci-dessous.'));
    }

    var ol = document.createElement('ol');
    ol.className = 'ps-steps';
    g.steps.forEach(function (st, i) {
      var li = document.createElement('li');
      li.appendChild(el('span', 'ps-n', String(i + 1)));
      var ic = el('span', 'ps-ic');
      ic.innerHTML = ICONS[st.icon] || ICONS.add;
      li.appendChild(ic);
      li.appendChild(el('span', 'ps-t', st.text));
      ol.appendChild(li);
    });
    box.appendChild(ol);

    if (g.note) box.appendChild(el('p', 'ps-note', g.note));
    if (g.copy) {
      var copy = el('button', 'ps-ghost', 'Copier le lien');
      copy.type = 'button';
      copy.addEventListener('click', function () { copyLink(copy); });
      box.appendChild(copy);
    }
    box.appendChild(el('p', 'ps-fine', 'Aucun téléchargement ni boutique d’applications : l’appli s’ouvre en plein écran depuis son icône.'));

    var acts = el('div', 'ps-actions');
    var later = el('button', 'ps-later', 'Plus tard');
    later.type = 'button';
    later.addEventListener('click', function () {
      store(K.snooze, Date.now() + 14 * DAY);
      fire('snooze');
      close();
    });
    var never = el('button', 'ps-never', 'Ne plus me proposer');
    never.type = 'button';
    never.addEventListener('click', function () {
      store(K.done, '1');
      fire('never');
      close();
    });
    acts.appendChild(later);
    acts.appendChild(never);
    box.appendChild(acts);

    over.appendChild(box);
    over.addEventListener('click', function (e) { if (e.target === over) close(); });
    overlay = over;
    document.body.appendChild(over);
    over._prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    store(K.seen, Date.now());
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { if (overlay === over) over.classList.add('is-on'); });
    });
    setTimeout(function () {
      if (overlay !== over) return;
      var target = over.querySelector('.ps-primary') || over.querySelector('.ps-x');
      if (target) { try { target.focus(); } catch (e) {} }
    }, 60);
    return true;
  }

  function auto() {
    if (cfg.auto == null || cfg.auto === '0') return;
    if (isStandalone()) return;
    var d = detect();
    if (!d.mobile) return;
    if (read(K.done) === '1') return;
    if ((parseInt(read(K.snooze), 10) || 0) > Date.now()) return;
    var seen = parseInt(read(K.seen), 10) || 0;
    if (seen && Date.now() - seen < 3 * DAY) return;
    var quietRe;
    try { quietRe = cfg.quiet ? new RegExp(cfg.quiet, 'i') : /\/(cart|checkout|checkout-success|compte|offre|inscription)\.php/i; } catch (e) { quietRe = /\/(cart|checkout|compte)\.php/i; }
    if (quietRe.test(location.pathname || '')) return;
    var delay = parseInt(cfg.delay, 10);
    if (isNaN(delay) || delay < 0) delay = 1800;
    function show() {
      if (isStandalone() || overlay) return;
      if (document.visibilityState === 'hidden') {
        document.addEventListener('visibilitychange', onVisible);
        return;
      }
      open();
    }
    function onVisible() {
      if (document.visibilityState !== 'visible') return;
      document.removeEventListener('visibilitychange', onVisible);
      show();
    }
    setTimeout(show, delay);
  }

  function bind() {
    var nodes = document.querySelectorAll('[data-pwa-install]');
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].addEventListener('click', function (e) {
        if (e && e.preventDefault) e.preventDefault();
        open();
      });
    }
  }

  if (cfg.manifest && !document.querySelector('link[rel="manifest"]')) {
    var link = document.createElement('link');
    link.rel = 'manifest';
    link.href = cfg.manifest;
    document.head.appendChild(link);
  }
  if (cfg.sw && 'serviceWorker' in navigator) {
    try { navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope || '/' }).catch(function () {}); } catch (e) {}
  }
  if (cfg.noPrompt !== '1') {
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferred = e;
    });
  }
  window.addEventListener('appinstalled', function () {
    store(K.done, '1');
    fire('installed');
    close();
  });

  window.PwaSave = {
    open: open,
    close: close,
    detect: detect,
    guide: guide,
    isStandalone: isStandalone,
    browserLabel: browserLabel
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { bind(); auto(); });
  else { bind(); auto(); }
})();
