// Suivi des appels à l'action du site public : un affichage est compté UNE fois par visite (onglet), quand au moins
// la moitié du bouton est visible pendant une seconde ; un clic est compté une fois par visite. Éléments : [data-cta] avec
// data-cta-variant / data-cta-token (voir includes/cta.php). Serveur : cta-track.php.
(function () {
  'use strict';
  var URL_TRACK = (window.APP_BASE || '') + '/cta-track.php';
  var els = document.querySelectorAll('[data-cta]');
  if (!els.length) return;

  function seen(prefix, key) { try { return sessionStorage.getItem(prefix + ':' + key) === '1'; } catch (e) { return false; } }
  function mark(prefix, key) { try { sessionStorage.setItem(prefix + ':' + key, '1'); } catch (e) {} }

  function send(el, click) {
    var d = el.dataset, key = d.cta + '|' + d.ctaVariant;
    var prefix = click ? 'cta-click' : 'cta-view';
    if (seen(prefix, key)) return;
    mark(prefix, key);
    var body = JSON.stringify({ slot: d.cta, variant: d.ctaVariant, token: d.ctaToken, click: click ? 1 : 0 });
    if (navigator.sendBeacon) { navigator.sendBeacon(URL_TRACK, new Blob([body], { type: 'application/json' })); return; }
    fetch(URL_TRACK, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'omit' }).catch(function () {});
  }

  if ('IntersectionObserver' in window) {
    var timers = new WeakMap();
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting && e.intersectionRatio >= 0.5) {
          if (!timers.has(e.target)) timers.set(e.target, setTimeout(function () { send(e.target, false); io.unobserve(e.target); }, 1000));
        } else if (timers.has(e.target)) { clearTimeout(timers.get(e.target)); timers.delete(e.target); }
      });
    }, { threshold: [0, 0.5] });
    els.forEach(function (el) { io.observe(el); });
  }

  document.addEventListener('click', function (event) {
    var el = event.target && event.target.closest ? event.target.closest('[data-cta]') : null;
    if (el) send(el, true);
  }, true);
})();
