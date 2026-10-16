// Champs prix numériques du back-office (€, deux décimales) et bouton « Mettre en promo ».
//  - input[data-price] : champ <input type="number"> ; tout texte écrit par le code (« 25 € », « 25-35 € » donné par l'IA, « 24 € » conseillé par
//    la recherche de prix) est converti en montant (la moyenne d'une fourchette) : window.priceNumber(texte).
//  - [data-promo-toggle] : dans un formulaire de fiche, affiche le prix promo (mention « Promo »), propose des remises rapides (−10, −20, −30, −50 %)
//    et « Retirer la promo » remet la mention d'avant. Serveur : admin/save-product.php (prix promo < prix, sinon ignoré).
(function () {
  'use strict';
  /** « 25 € » → "25" ; « 25-35 € » → "30" ; « 35,50 » → "35.5" ; "" si aucun montant. */
  function priceNumber(v) {
    var s = String(v == null ? '' : v).replace(/\s| /g, '');
    var m = s.match(/\d+(?:[.,]\d{1,2})?/g);
    if (!m) return '';
    var vals = m.slice(0, 2).map(function (x) { return parseFloat(x.replace(',', '.')); });
    var n = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
    if (vals.length > 1) n = n >= 10 ? Math.round(n) : Math.round(n * 2) / 2;
    return String(Math.round(n * 100) / 100);
  }
  window.priceNumber = priceNumber;

  var desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  function bindPrice(inp) {
    if (inp.dataset.priceBound) return; inp.dataset.priceBound = '1';
    Object.defineProperty(inp, 'value', { configurable: true, get: function () { return desc.get.call(this); }, set: function (v) { desc.set.call(this, priceNumber(v)); } });
  }

  function eur(n) { return (Math.round(n * 100) / 100).toString().replace('.', ',') + ' €'; }

  function bindPromo(btn) {
    if (btn.dataset.promoBound) return; btn.dataset.promoBound = '1';
    var form = btn.closest('form') || document;
    var price = form.querySelector('input[name="price"]');
    var promo = form.querySelector('input[name="promo_price"]');
    var badge = form.querySelector('input[name="badge"]');
    var row = form.querySelector('[data-promo-row]');
    var info = form.querySelector('[data-promo-info]');
    if (!price || !promo || !badge || !row) return;
    var prev = badge.value === 'Promo' ? 'Chiné' : badge.value;

    function active() { return badge.value === 'Promo'; }
    function show() {
      row.hidden = !active();
      btn.textContent = active() ? '✕ Retirer la promo' : '🏷 Mettre en promo';
      btn.setAttribute('aria-pressed', String(active()));
      var p = parseFloat(price.value), q = parseFloat(promo.value);
      if (info) {
        info.textContent = !active() ? '' : (q > 0 && p > 0 ? (q < p ? 'Remise de ' + Math.round((1 - q / p) * 100) + ' % (' + eur(p - q) + ' de moins).' : 'Le prix promo doit être inférieur au prix (' + eur(p) + ').') : 'Saisissez le prix promo.');
        info.dataset.kind = q >= p && q > 0 ? 'error' : '';
      }
    }
    btn.addEventListener('click', function () {
      if (active()) { badge.value = prev || ''; promo.value = ''; }
      else {
        prev = badge.value || 'Chiné'; badge.value = 'Promo';
        var p = parseFloat(price.value);
        if (!promo.value && p > 0) promo.value = String(Math.round(p * 0.8 * 100) / 100);   // −20 % proposé
      }
      show(); if (active()) { promo.focus(); promo.select(); }
    });
    row.querySelectorAll('[data-promo-pct]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = parseFloat(price.value);
        if (!(p > 0)) { price.focus(); if (info) { info.textContent = 'Fixez d\'abord le prix.'; info.dataset.kind = 'error'; } return; }
        promo.value = String(Math.round(p * (1 - parseInt(b.dataset.promoPct, 10) / 100) * 100) / 100); show();
      });
    });
    [price, promo].forEach(function (i) { i.addEventListener('input', show); });
    badge.addEventListener('input', function () { if (!active() && badge.value !== 'Promo') prev = badge.value; show(); });
    show();
  }

  function start() {
    document.querySelectorAll('input[data-price]').forEach(bindPrice);
    document.querySelectorAll('[data-promo-toggle]').forEach(bindPromo);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
