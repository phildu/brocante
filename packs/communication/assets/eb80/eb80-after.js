// Portage d'electroboy80 : adaptation chargée APRÈS le code d'electroboy80 (avant son démarrage, DOMContentLoaded).
// - seules les pages portées sont ouvertes (window.COM_EB80.allowed) : toute autre page demandée mène à la page de départ ;
// - le tableau de bord et les compteurs d'electroboy80, absents de la boutique, ne sont pas chargés ;
// - l'éditeur d'affiche part des textes de la boutique (nom, site) au lieu de ceux d'Electroboy 80 ;
// - mode « sélecteur » : seul le sélecteur de médias d'electroboy80, pour les pages de la plateforme (picker.js).
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
  // Éditeur d'affiche : textes par défaut de la boutique au lieu de ceux d'Electroboy 80 (concert, salle, site). Les blocs gardent
  // leurs libellés génériques (Logo, Titre, Date, Lieu, Infos, Pied) ; un projet enregistré garde ses propres textes.
  try {
    /* global POSTER, POSTER_DEFAULT */
    var texts = { logo: (P.shop || 'Ma boutique').toUpperCase(), title: 'NOUVEAUTÉS EN BOUTIQUE', date: 'CE WEEK-END', venue: 'À LA BOUTIQUE', info: 'Entrée libre · Tout public', footer: P.site || '' };
    [typeof POSTER !== 'undefined' ? POSTER : null, typeof POSTER_DEFAULT !== 'undefined' ? POSTER_DEFAULT : null].forEach(function (st) {
      if (!st) return;
      Object.keys(texts).forEach(function (k) { if (st[k] && 'text' in st[k]) st[k].text = texts[k]; });
    });
  } catch (e) { /* éditeur absent de cette page */ }
  // Mode « sélecteur » (fenêtre ouverte par picker.js depuis une page de la plateforme) : seul le sélecteur de médias
  // d'electroboy80 est montré ; le choix est remis à la page hôte (P.onPick), sa fermeture aussi (P.onClose).
  if (P.mode === 'picker') {
    document.documentElement.classList.add('eb80-picker-mode');
    if (typeof window.closeMediaPicker === 'function') {
      var closePicker = window.closeMediaPicker;
      window.closeMediaPicker = function () { closePicker(); if (P.onClose) P.onClose(); };
    }
    window.addEventListener('load', function () {
      if (typeof window.openMediaPicker !== 'function') return;
      window.openMediaPicker(function (url) { if (P.onPick) P.onPick(url); }, { type: P.pickType === 'video' || P.pickType === 'image' ? P.pickType : null });
    });
  }
  ['loadDashboardFromAPI', 'refreshPendingBadge'].forEach(function (fn) {
    if (typeof window[fn] === 'function') window[fn] = function () {};
  });
})();
