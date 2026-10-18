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
    window.showPage = function (id) {
      // Apparence (palettes des visuels) depuis une autre page : la page « Palettes des visuels » de la boutique
      if (id === 'appearance' && start !== 'appearance' && allowed.indexOf('appearance') !== -1) {
        try { var u = new URL(window.parent.location.href); u.searchParams.set('v', 'palettes'); window.parent.location.href = u.toString(); } catch (e) { /* page hôte injoignable */ }
        return;
      }
      return showPage(allowed.indexOf(id) === -1 ? start : id);
    };
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
    // Les champs de l'éditeur ont été remplis avant ce script : ils doivent montrer ces textes, pas ceux d'Electroboy 80.
    if (typeof _posterSyncUI === 'function') _posterSyncUI();
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
  // « API & Réseaux » : la page complète d'electroboy80. Les réglages en double avec la plateforme y sont les mêmes que dans
  // l'administration (le serveur les lit et les écrit chez elle, lib/reglages.php) : chacun est marqué et renvoie à sa page. Ceux
  // qu'aucune fonction de la boutique n'utilise sont grisés (et ignorés à l'enregistrement). L'adresse de retour TikTok est celle
  // de la boutique.
  // Palettes des visuels : seulement la partie palettes de la page Apparence (couleurs du site d'electroboy80 masquées).
  if (start === 'appearance') document.documentElement.classList.add('eb80-palettes-only');
  if (start === 'rs-settings') {
    document.documentElement.classList.add('eb80-api-page');
    var base = '';
    try { base = String(window.parent.APP_BASE || ''); } catch (e) { base = ''; }
    var link = function (path, text) { return '<a target="_top" href="' + base + path + '">' + text + '</a>'; };
    var BRANCHES = {
      gemini_api_key: 'Clé Gemini de la boutique : la même que dans le ' + link('/admin/index.php#gemini-settings', 'tableau de bord') +
        ' (sans clé, la clé partagée de la plateforme sert) · ' + link('/admin/ai-usage.php', 'consommation et quota'),
      oauth_google_client_id: 'Clés de la boutique : les mêmes que dans ' + link('/admin/social.php', 'Connexion sociale'),
    };
    BRANCHES.claude_api_key = 'Clé Claude de la boutique, pour le scénario de la Vidéo IA si « Claude » est choisi ci-dessous ' +
      '(sans clé, la clé partagée de la plateforme sert s\'il y en a une) · coût compté dans le ' + link('/admin/ai-usage.php', 'quota d\'IA');
    ['oauth_google_client_secret', 'oauth_facebook_client_id', 'oauth_facebook_client_secret'].forEach(function (k) { BRANCHES[k] = BRANCHES.oauth_google_client_id; });
    var INUTILISE = /^(smtp_.*|meta_wa_.*|twilio_.*|billetweb_api_.*|brave_search_key|google_cse_.*|google_places_key|serper_api_key)$/;
    // Choix de l'IA qui écrit le scénario de la Vidéo IA (réglage video_ai_provider, enregistré avec les clés).
    var addProvider = function () {
      var gemini = document.querySelector('#apitab-ia [data-key="gemini_api_key"]');
      var grid = gemini && gemini.closest('.form-grid');
      if (!grid || document.querySelector('[data-key="video_ai_provider"]')) return;
      var box = document.createElement('div');
      box.className = 'form-grid';
      box.innerHTML = '<div class="form-group" style="grid-column:1/-1"><label class="form-label">IA du scénario de la Vidéo IA</label>' +
        '<select class="form-input auth-set" data-key="video_ai_provider"><option value="gemini">Gemini (clé de la boutique ou partagée)</option>' +
        '<option value="claude">Claude (clé Claude ci-dessus)</option></select>' +
        '<div class="eb80-field-note eb80-field-shop">★ Les autres fonctions d\'IA du pack utilisent Gemini. Coût compté dans le quota d\'IA de la boutique.</div></div>';
      grid.parentNode.insertBefore(box, grid.nextSibling);
      fetch('/api/settings').then(function (r) { return r.json(); }).then(function (d) {
        var v = d && d.settings && d.settings.video_ai_provider;
        if (v === 'claude' || v === 'gemini') box.querySelector('select').value = v;
      }).catch(function () { /* valeur par défaut : Gemini */ });
    };
    var annotate = function () {
      addProvider();
      ['ia', 'conn', 'search'].forEach(function (tab) {
        var pane = document.getElementById('apitab-' + tab);
        if (!pane) return;
        pane.querySelectorAll('[data-key]').forEach(function (input) {
          if (input.dataset.eb80Note) return;
          var key = input.dataset.key;
          var group = input.closest('.form-group') || input.parentNode;
          var note = document.createElement('div');
          if (BRANCHES[key]) {
            note.className = 'eb80-field-note eb80-field-shop';
            note.innerHTML = '★ ' + BRANCHES[key];
          } else if (INUTILISE.test(key)) {
            input.disabled = true;
            input.classList.remove('auth-set');
            group.classList.add('eb80-field-off');
            note.className = 'eb80-field-note';
            note.textContent = 'Non utilisé par la boutique';
          } else {
            return;
          }
          input.dataset.eb80Note = '1';
          group.appendChild(note);
        });
      });
      // Envoi d'un e-mail de test (SMTP d'electroboy80) : la boutique envoie ses e-mails sans ce réglage.
      var smtpResult = document.getElementById('smtp-test-result');
      var smtpBar = smtpResult && smtpResult.parentNode;
      if (smtpBar && !smtpBar.dataset.eb80Note) {
        smtpBar.dataset.eb80Note = '1';
        smtpBar.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      }
    };
    var addNote = function () {
      var firstTab = document.querySelector('[data-apitab]');
      var bar = firstTab && firstTab.parentNode;
      if (!bar || document.querySelector('.eb80-platform-note')) return;
      var note = document.createElement('p');
      note.className = 'eb80-platform-note';
      note.innerHTML = '★ Réglage de la boutique, le même que dans son administration (un seul endroit où il est rangé). ' +
        'Les champs grisés servent à electroboy80 mais pas à la boutique.';
      bar.parentNode.insertBefore(note, bar);
    };
    // Le panneau des réseaux est construit après le chargement : l'adresse de retour TikTok est posée dès qu'il apparaît.
    var callback = location.origin + (window.EB80_TIKTOK_CALLBACK || '');
    var fillHint = function () {
      var hint = document.getElementById('tiktok-redirect-hint');
      if (hint && window.EB80_TIKTOK_CALLBACK && hint.textContent !== callback) hint.textContent = callback;
    };
    new MutationObserver(function () { fillHint(); if (document.getElementById('apitab-ia')) { addNote(); annotate(); } }).observe(document.documentElement, { childList: true, subtree: true });
    var rsOnly = function () {
      fillHint();
      addNote();
      annotate();
    };
    // Les scripts du cadre sont injectés tardivement : la page peut être déjà chargée.
    if (document.readyState === 'complete') rsOnly(); else window.addEventListener('load', rsOnly);
  }
  // Clé Claude : jamais gardée dans le navigateur (electroboy80 la recopiait dans localStorage) ; elle se range côté serveur.
  window.saveSeoApiKey = function () {};
  try { localStorage.removeItem('eb80_anthropic_key'); } catch (e) { /* stockage indisponible */ }
  ['loadDashboardFromAPI', 'refreshPendingBadge'].forEach(function (fn) {
    if (typeof window[fn] === 'function') window[fn] = function () {};
  });
})();
