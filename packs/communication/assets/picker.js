// Pack Communication : le sélecteur de médias d'electroboy80 dans les pages de la plateforme. Chargé sur toute l'administration
// quand le plugin est actif (admin_assets du manifeste), il enrichit ces pages sans les modifier :
//  - les fenêtres « Choisir dans la médiathèque » du hero, du diaporama, des bandeaux et des réglages gagnent un bouton
//    « Toute la médiathèque… » ;
//  - la galerie d'une fiche produit gagne « Ajouter depuis la médiathèque… » ;
//  - la médiathèque classique signale la médiathèque d'electroboy80.
// Le bouton ouvre, en surimpression, l'admin d'electroboy80 portée (assets/eb80/admin.html) en mode « sélecteur » : son vrai
// sélecteur (dossiers par préfixe, recherche, tri, envoi de fichiers, photos des fiches comprises). Le fichier choisi est inscrit
// dans media_library (route media-adopt), seule source acceptée par les actions de la plateforme, puis appliqué à la page.
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me || !me.dataset.api) return;
  var API = me.dataset.api;
  var CSRF = me.dataset.csrf;
  var FRAME = me.src.replace(/picker\.js(\?.*)?$/, 'eb80/admin.html') + (me.src.match(/\?v=\d+/) || [''])[0];

  function api(route, body) {
    var opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = CSRF;
      opts.body = JSON.stringify(body);
    }
    return fetch(API + route, opts).then(function (r) {
      return r.json().catch(function () { return { error: 'Réponse illisible du serveur (' + r.status + ').' }; }).then(function (data) {
        if (!r.ok || data.error) throw new Error(data.error || ('Erreur ' + r.status));
        return data;
      });
    });
  }

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) e.appendChild(c); });
    return e;
  }

  /** Ouvre le sélecteur d'electroboy80 ; onPick(élément de la médiathèque, inscrit dans media_library). type : image | video | all. */
  function open(type, onPick) {
    api('eb80&path=' + encodeURIComponent('/auth/me')).then(function (me80) {
      var overlay = el('div', { class: 'com-eb80-overlay' });
      var status = el('p', { class: 'com-eb80-status', role: 'status' });
      var frame = el('iframe', { class: 'com-eb80-frame', title: 'Choisir dans la médiathèque', src: FRAME });
      var done = false;
      function close() {
        if (done) return;
        done = true;
        // Différé : le sélecteur d'electroboy80 termine son propre code (fermeture) avant que son cadre ne disparaisse.
        setTimeout(function () { overlay.remove(); }, 0);
      }
      window.COM_EB80 = {
        api: API + 'eb80', csrf: CSRF, uploads: me80.uploads, start: 'media', allowed: ['media'], mode: 'picker',
        pickType: type === 'video' || type === 'image' ? type : null,
        onPick: function (url) {
          setTimeout(function () {
            status.textContent = 'Un instant…';
            api('media-adopt', { path: url }).then(function (r) {
              return Promise.resolve(onPick(r.item));
            }).then(close, function (e) { status.textContent = e.message; });
          }, 0);
        },
        onClose: function () { if (!status.textContent) close(); },
      };
      overlay.appendChild(frame);
      overlay.appendChild(status);
      document.body.appendChild(overlay);
    }, function (e) { alert(e.message); });
  }

  window.ComMedia = { api: api, open: open };

  // ── Fenêtres « Choisir dans la médiathèque » de la plateforme ────────────
  // Le choix est appliqué en passant par un élément déjà présent dans la fenêtre (ses gestionnaires lisent data-media-* au
  // moment du clic) : le champ visé (réglages, bandeau…) est conservé.
  function enhancePlatformPicker() {
    var box = document.querySelector('.media-pick-box');
    if (!box) return;
    var page = location.pathname.split('/').pop();
    var type = page === 'banners.php' || page === 'slideshow.php' ? 'all' : 'image';
    var bar = box.lastElementChild;
    var btn = el('button', { type: 'button', class: 'btn-small com-pick-open', text: 'Toute la médiathèque…' });
    bar.insertBefore(btn, bar.firstChild);
    btn.addEventListener('click', function () {
      open(type, function (item) {
        var proxy = box.querySelector('[data-media-path]');
        if (!proxy) {
          alert('Fichier ajouté à la médiathèque : rouvrez « Choisir dans la médiathèque » pour le sélectionner.');
          location.reload();
          return;
        }
        var span = proxy.querySelector('span');
        var saved = { path: proxy.dataset.mediaPath, label: proxy.dataset.mediaLabel, type: proxy.dataset.mediaType, text: span ? span.textContent : null };
        proxy.dataset.mediaPath = item.path;
        if (saved.label !== undefined) proxy.dataset.mediaLabel = item.title;
        if (saved.type !== undefined) proxy.dataset.mediaType = item.type;
        if (span) span.textContent = item.title;
        proxy.click();
        proxy.dataset.mediaPath = saved.path;
        if (saved.label !== undefined) proxy.dataset.mediaLabel = saved.label;
        if (saved.type !== undefined) proxy.dataset.mediaType = saved.type;
        if (span) span.textContent = saved.text;
      });
    });
  }

  // ── Galerie d'une fiche produit ──────────────────────────────────────────
  function enhanceGallery() {
    if (location.pathname.split('/').pop() !== 'gallery.php') return;
    var action = document.querySelector('form[action$="gallery-action.php"] input[name="action"][value="add"]');
    var ref = new URLSearchParams(location.search).get('ref');
    if (!action || !ref) return;
    var form = action.form;
    var btn = el('button', { type: 'button', class: 'btn-small com-pick-open', text: 'Ajouter depuis la médiathèque…' });
    var title = form.querySelector('h2');
    (title || form.firstChild).insertAdjacentElement('afterend', btn);
    btn.addEventListener('click', function () {
      open('all', function (item) {
        return api('product-photo-add', { ref: ref, path: item.path }).then(function () { location.reload(); });
      });
    });
  }

  // ── Médiathèque classique ────────────────────────────────────────────────
  function enhanceClassicMedia() {
    if (location.pathname.split('/').pop() !== 'media.php') return;
    var h1 = document.querySelector('main h1');
    var link = document.querySelector('.admin-sidebar-nav a[href*="plugin.php?p=communication"]');
    if (!h1 || !link) return;
    var note = el('p', { class: 'com-classic-note' }, [
      document.createTextNode('Vue classique. La médiathèque d\'electroboy80 (dossiers, tris, envois groupés, versions web, éditeur) : '),
      el('a', { href: link.getAttribute('href'), text: 'ouvrir la médiathèque' }),
      document.createTextNode('.'),
    ]);
    h1.insertAdjacentElement('afterend', note);
  }

  function start() {
    enhancePlatformPicker();
    enhanceGallery();
    enhanceClassicMedia();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
