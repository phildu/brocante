// Portage d'electroboy80 : adaptation chargée APRÈS le code d'electroboy80 (avant son démarrage, DOMContentLoaded).
// - seules les pages portées sont ouvertes (window.COM_EB80.allowed) : toute autre page demandée mène à la page de départ ;
// - le tableau de bord et les compteurs d'electroboy80, absents de la boutique, ne sont pas chargés.
(function () {
  'use strict';
  var P = window.COM_EB80;
  if (!P) return;
  var allowed = P.allowed || ['media'];
  var start = P.start && allowed.indexOf(P.start) !== -1 ? P.start : allowed[0];
  window.canAccess = function (id) { return allowed.indexOf(id) !== -1; };
  if (typeof window.showPage === 'function') {
    var showPage = window.showPage;
    window.showPage = function (id) { return showPage(allowed.indexOf(id) === -1 ? start : id); };
  }
  ['loadDashboardFromAPI', 'refreshPendingBadge', '_preloadEffectsConfig'].forEach(function (fn) {
    if (typeof window[fn] === 'function') window[fn] = function () {};
  });
})();
