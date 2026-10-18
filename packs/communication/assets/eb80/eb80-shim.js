// Portage d'electroboy80 : adaptation chargée AVANT le code d'electroboy80 (admin.html, cadre de la page Médiathèque).
// - lit la configuration posée par la page de la boutique (window.parent.COM_EB80 : adresse de l'API, jeton CSRF, dossier
//   d'envoi, pages autorisées) ;
// - redirige chaque appel fetch('/api/…') vers l'API du plugin, avec la session de la boutique et le jeton CSRF (le jeton
//   d'electroboy80 n'existe plus ; une valeur factice satisfait son code) ;
// - session expirée (401) : recharge la page de la boutique, qui affiche la connexion ;
// - prend les couleurs et les polices de l'administration de la boutique (variables d'electroboy80, voir eb80-theme.css).
(function () {
  'use strict';
  var P = null;
  try { P = window.parent !== window ? window.parent.COM_EB80 : null; } catch (e) { P = null; }
  if (!P || !P.api) {
    document.documentElement.innerHTML = '<body style="font:16px sans-serif;padding:2em">Ouvrez cette page depuis la médiathèque de votre boutique.</body>';
    throw new Error('Médiathèque : configuration de la boutique absente.');
  }
  window.COM_EB80 = P;
  window.EB80_UPLOADS = P.uploads;
  // Retour OAuth de TikTok : la route eb80 du plugin (même adresse que celle calculée par rs-tiktok-callback.php).
  window.EB80_TIKTOK_CALLBACK = P.api + '&path=' + encodeURIComponent('/rs/tiktok-callback');
  try { localStorage.setItem('eb80_token', 'session-boutique'); } catch (e) { /* stockage indisponible */ }

  var origFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var m = url.match(/^(?:https?:\/\/[^/]+)?\/api(\/[^?#]*)(?:\?([^#]*))?/);
    if (!m) return origFetch(input, init);
    init = Object.assign({}, init || {});
    if (typeof input !== 'string') {
      init.method = init.method || input.method;
      init.headers = init.headers || input.headers;
    }
    var headers = new Headers(init.headers || {});
    headers.delete('Authorization');
    headers.set('X-CSRF-Token', P.csrf);
    init.headers = headers;
    init.credentials = 'same-origin';
    var target = P.api + '&path=' + encodeURIComponent(m[1]) + (m[2] ? '&eb80q=' + encodeURIComponent(m[2]) : '');
    return origFetch(target, init).then(function (res) {
      if (res.status === 401) { try { window.parent.location.reload(); } catch (e) { /* page hôte injoignable */ } }
      return res;
    });
  };

  // Couleurs et polices : celles de l'administration de la boutique, toujours en thème clair.
  try {
    var host = window.parent.document.documentElement;
    var before = host.getAttribute('data-theme');
    host.setAttribute('data-theme', 'light');
    var cs = window.parent.getComputedStyle(host);
    var v = function (n, d) { return (cs.getPropertyValue(n) || '').trim() || d; };
    var pal = {
      bg: v('--bg', '#f4eee1'), surface: v('--surface', '#eae0cc'), surface2: v('--surface-2', '#e2d5b4'), ink: v('--ink', '#2e2418'),
      soft: v('--ink-soft', '#6b5c48'), line: v('--line', '#c9b994'), accent: v('--accent', '#b5502e'), accent2: v('--accent-2', '#46647a'),
      display: v('--font-display', 'Georgia, serif'), body: v('--font-body', 'Arial, sans-serif'), mono: v('--font-mono', 'monospace'),
    };
    if (before === null) host.removeAttribute('data-theme'); else host.setAttribute('data-theme', before);
    var map = {
      '--dark': pal.bg, '--black': pal.surface, '--mid': pal.surface, '--panel': pal.surface2, '--border': pal.line,
      '--white': pal.ink, '--muted': pal.soft, '--yellow': pal.accent, '--pink': pal.accent, '--cyan': pal.accent2, '--blue': pal.accent2,
      '--shop-display': pal.display, '--shop-body': pal.body, '--shop-mono': pal.mono, '--shop-accent': pal.accent, '--shop-bg': pal.bg,
    };
    var root = document.documentElement;
    Object.keys(map).forEach(function (k) { root.style.setProperty(k, map[k]); });
    root.classList.add('eb80-boutique');
  } catch (e) { /* couleurs d'origine */ }
})();
