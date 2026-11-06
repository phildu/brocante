// Incitation à enregistrer la boutique comme web app (écran d'accueil du téléphone) : bandeau discret au bon moment + lien « Installer l'appli » en pied de page.
// Android / Chrome / Edge : l'invite d'installation du navigateur ; iPhone et autres : la marche à suivre. Réglage : Administration → Réglages du site.
(function () {
  var tag = document.currentScript, cfg = tag ? tag.dataset : {};
  var LS = { views: 'pwa_views', snooze: 'pwa_snooze', done: 'pwa_done' }, DAY = 86400000;
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  if (standalone) return;                                            // déjà installée : rien à proposer
  var ua = navigator.userAgent || '', ios = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var inApp = /FBAN|FBAV|Instagram|Line\/|Snapchat|TikTok|MicroMessenger/i.test(ua);
  var touch = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || 'ontouchstart' in window;
  var name = cfg.name || document.title, evt = null, banner = null, sheet = null;

  if ('serviceWorker' in navigator && cfg.sw) { try { navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope || '/' }).catch(function () {}); } catch (e) {} }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); evt = e; });
  window.addEventListener('appinstalled', function () { set(LS.done, '1'); hide(); closeSheet(); var l = document.getElementById('pwa-link'); if (l) l.remove(); });
  document.addEventListener('pwasave:never', function () { set(LS.done, '1'); hide(); var l = document.getElementById('pwa-link'); if (l) l.remove(); });
  document.addEventListener('pwasave:snooze', function () { set(LS.snooze, String(Date.now() + 14 * DAY)); hide(); });
  document.addEventListener('pwasave:installed', function () { set(LS.done, '1'); hide(); var l = document.getElementById('pwa-link'); if (l) l.remove(); });
  if (get(LS.done) === '1') return;

  var css = document.createElement('style');
  css.textContent = [
    '.pwa-bar{position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:250;max-width:520px;margin:0 auto;display:flex;gap:12px;align-items:center;padding:12px 14px;background:var(--surface,#fff);color:var(--ink,#222);border:1px solid var(--line,#ddd);border-radius:14px;box-shadow:0 10px 36px rgba(0,0,0,.25);transform:translateY(140%);transition:transform .35s ease}',
    '.pwa-bar.is-on{transform:none}.pwa-bar img{width:48px;height:48px;border-radius:12px;flex:none;background:var(--surface-2,#eee)}',
    '.pwa-bar .pwa-txt{flex:1;min-width:0;font-size:.9rem;line-height:1.3}.pwa-bar strong{display:block;font-size:.98rem}',
    '.pwa-bar .pwa-act{display:flex;flex-direction:column;gap:2px;align-items:stretch}',
    '.pwa-bar button,.pwa-sheet button{font:inherit;cursor:pointer;border-radius:10px;min-height:44px;padding:8px 14px}',
    '.pwa-go{background:var(--accent,#b0622b);color:var(--accent-ink,#fff);border:1px solid var(--accent,#b0622b);font-weight:700}',
    '.pwa-later{background:transparent;color:var(--ink-soft,#666);border:0;font-size:.82rem;min-height:36px!important}',
    '.pwa-x{position:absolute;top:2px;right:4px;background:transparent;border:0;color:var(--ink-soft,#666);font-size:1.2rem;line-height:1;min-height:36px!important;min-width:36px;padding:0!important}',
    '.pwa-over{position:fixed;inset:0;z-index:260;background:rgba(20,15,8,.55);display:flex;align-items:flex-end;justify-content:center}',
    '.pwa-sheet{background:var(--bg,#fff);color:var(--ink,#222);width:min(520px,100%);border-radius:18px 18px 0 0;padding:20px 20px calc(20px + env(safe-area-inset-bottom));box-shadow:0 -10px 40px rgba(0,0,0,.3)}',
    '.pwa-sheet h2{margin:0 0 6px;font-size:1.15rem}.pwa-sheet ol{margin:12px 0;padding-left:1.2rem;display:grid;gap:8px}.pwa-sheet .pwa-note{font-size:.85rem;color:var(--ink-soft,#666);margin:8px 0}',
    '.pwa-sheet .pwa-close{width:100%;background:var(--surface,#eee);color:var(--ink,#222);border:1px solid var(--line,#ccc);font-weight:600}',
    '@media (min-width:700px){.pwa-over{align-items:center}.pwa-sheet{border-radius:18px}}',
    '@media (prefers-reduced-motion:reduce){.pwa-bar{transition:none}}'
  ].join('\n');
  document.head.appendChild(css);

  function el(t, cls, txt) { var n = document.createElement(t); if (cls) n.className = cls; if (txt != null) n.textContent = txt; return n; }

  function steps() {
    if (inApp) return { title: 'Ouvrez la boutique dans votre navigateur', list: ['Touchez le menu de cette application (⋯ ou ⋮).', 'Choisissez « Ouvrir dans le navigateur » (Safari ou Chrome).', 'Revenez ici : l\'ajout à l\'écran d\'accueil y sera proposé.'] };
    if (ios) return { title: 'Ajouter ' + name + ' à l\'écran d\'accueil', list: ['Touchez le bouton Partager (le carré avec une flèche vers le haut) en bas de l\'écran, ou en haut sur iPad.', 'Faites défiler et choisissez « Sur l\'écran d\'accueil ».', 'Touchez « Ajouter » : l\'icône de la boutique apparaît avec vos applications.'] };
    return { title: 'Ajouter ' + name + ' à l\'écran d\'accueil', list: ['Ouvrez le menu de votre navigateur (⋮ ou ⋯).', 'Choisissez « Installer l\'application » ou « Ajouter à l\'écran d\'accueil ».', 'Confirmez : l\'icône de la boutique apparaît avec vos applications.'] };
  }
  function closeSheet() { if (sheet) { sheet.remove(); sheet = null; } }
  function openSheet() {
    if (window.PwaSave && window.PwaSave.open) { window.PwaSave.open(); return; }   // marche à suivre commune, adaptée au navigateur
    closeSheet();
    var s = steps(), over = el('div', 'pwa-over'), box = el('div', 'pwa-sheet');
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', s.title);
    box.appendChild(el('h2', null, s.title));
    var ol = el('ol'); s.list.forEach(function (t) { ol.appendChild(el('li', null, t)); }); box.appendChild(ol);
    box.appendChild(el('p', 'pwa-note', 'Pas de téléchargement ni de boutique d\'applications : la boutique s\'ouvre en plein écran, comme une application.'));
    var b = el('button', 'pwa-close', 'Compris'); b.type = 'button'; b.addEventListener('click', closeSheet); box.appendChild(b);
    over.appendChild(box); over.addEventListener('click', function (e) { if (e.target === over) closeSheet(); });
    document.body.appendChild(over); sheet = over; b.focus();
  }
  function install() {
    if (evt) { var e = evt; evt = null; e.prompt(); e.userChoice.then(function (c) { if (c && c.outcome === 'accepted') set(LS.done, '1'); hide(); }); return; }
    openSheet();
  }
  function hide() { if (banner) { banner.classList.remove('is-on'); var b = banner; banner = null; setTimeout(function () { b.remove(); }, 400); } }
  function snooze(days) { set(LS.snooze, String(Date.now() + days * DAY)); hide(); }

  function show() {
    if (banner || sheet) return;
    banner = el('div', 'pwa-bar'); banner.setAttribute('role', 'region'); banner.setAttribute('aria-label', 'Ajouter la boutique à l\'écran d\'accueil');
    if (cfg.icon) { var im = el('img'); im.src = cfg.icon; im.alt = ''; im.width = 48; im.height = 48; banner.appendChild(im); }
    var t = el('div', 'pwa-txt'); t.appendChild(el('strong', null, 'Ajoutez la boutique à votre écran d\'accueil'));
    t.appendChild(document.createTextNode('Un geste pour y revenir, en plein écran, comme une application.')); banner.appendChild(t);
    var act = el('div', 'pwa-act'), go = el('button', 'pwa-go', 'Ajouter'), later = el('button', 'pwa-later', 'Plus tard'); go.type = later.type = 'button';
    go.addEventListener('click', function () { install(); }); later.addEventListener('click', function () { snooze(14); });
    act.appendChild(go); act.appendChild(later); banner.appendChild(act);
    var x = el('button', 'pwa-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Ne plus me le proposer'); x.addEventListener('click', function () { snooze(90); }); banner.appendChild(x);
    document.body.appendChild(banner); requestAnimationFrame(function () { requestAnimationFrame(function () { if (banner) banner.classList.add('is-on'); }); });
  }

  // Lien permanent en pied de page (toutes les mises en page) : « Installer l'appli »
  function footerLink() {
    var host = document.querySelector('footer.site .footer-bottom') || document.querySelector('footer');
    if (!host || document.getElementById('pwa-link')) return;
    var a = el('a', null, '📲 Installer l\'appli'); a.id = 'pwa-link'; a.href = '#installer'; a.style.cssText = 'display:inline-block;padding:10px 0;';
    a.addEventListener('click', function (e) { e.preventDefault(); install(); });
    host.appendChild(a);
  }
  footerLink();

  // Au bon moment : code QR (from=qr) ou lien partagé (from=share) dès l'ouverture, sinon à partir de la 2e page vue ;
  // jamais dans le tunnel d'achat ni dans un navigateur intégré.
  var n = (parseInt(get(LS.views), 10) || 0) + 1; set(LS.views, String(n));
  var snoozed = parseInt(get(LS.snooze), 10) || 0;
  var quiet = /\/(cart|checkout|checkout-success|compte|offre|inscription)\.php|\/admin\//.test(location.pathname);
  if (!touch || inApp || quiet || snoozed > Date.now()) return;
  var search = location.search || '';
  var fromQr = /[?&]from=qr(?:&|$)/.test(search);
  var fromShare = /[?&]from=share(?:&|$)/.test(search);
  var external = !!document.referrer && document.referrer.indexOf(location.origin) !== 0;
  var delay;
  if (fromQr) delay = 1200;
  else if (n >= 2) delay = 4000;
  else if (/produit\.php/.test(location.pathname)) delay = fromShare ? 3000 : 20000;
  else if (fromShare || external) delay = 3500;
  else delay = 0;
  if (delay) setTimeout(show, delay);
})();
