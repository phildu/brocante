// Compteur d'affichages des visuels promo : un visuel est compté UNE fois par visite (onglet), quand au moins la moitié est visible pendant une seconde.
// Éléments : [data-promo-track] avec data-promo-shop / -ref / -placement / -token (voir includes/promo.php). Serveur : promo-track.php.
(function () {
  'use strict';
  var URL_TRACK = (window.APP_BASE || '') + '/promo-track.php';
  var els = document.querySelectorAll('[data-promo-track]');
  if (!els.length || !('IntersectionObserver' in window)) return;

  function seen(key) { try { return sessionStorage.getItem('promo:' + key) === '1'; } catch (e) { return false; } }
  function mark(key) { try { sessionStorage.setItem('promo:' + key, '1'); } catch (e) {} }

  function send(el) {
    var d = el.dataset, key = d.promoShop + '|' + d.promoRef + '|' + d.promoPlacement;
    if (seen(key)) return;
    mark(key);
    var body = JSON.stringify({ shop: d.promoShop, ref: d.promoRef, placement: d.promoPlacement, token: d.promoToken });
    if (navigator.sendBeacon) { navigator.sendBeacon(URL_TRACK, new Blob([body], { type: 'application/json' })); return; }
    fetch(URL_TRACK, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'omit' }).catch(function () {});
  }

  var timers = new WeakMap();
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting && e.intersectionRatio >= 0.5) {
        if (!timers.has(e.target)) timers.set(e.target, setTimeout(function () { send(e.target); io.unobserve(e.target); }, 1000));
      } else if (timers.has(e.target)) { clearTimeout(timers.get(e.target)); timers.delete(e.target); }
    });
  }, { threshold: [0, 0.5] });
  els.forEach(function (el) { io.observe(el); });
})();
