// Pack Communication : outils communs de la médiathèque, chargés sur toutes les pages de l'administration quand le plugin est
// actif (admin_assets du manifeste). Ils exposent window.ComMedia : api(), list(), upload(), fmtBytes(), open() (sélecteur
// commun), et enrichissent les pages de la plateforme sans les modifier :
//  - les fenêtres « Choisir dans la médiathèque » du hero, du diaporama, des bandeaux et des réglages gagnent un bouton
//    « Toute la médiathèque… » (photos des fiches comprises, recherche, dossiers, envoi depuis la fenêtre) ;
//  - la galerie d'une fiche produit gagne « Ajouter depuis la médiathèque » ;
//  - la médiathèque classique signale la médiathèque enrichie.
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me || !me.dataset.api) return;
  var API = me.dataset.api;
  var CSRF = me.dataset.csrf;
  var CHUNK = 1572864;
  var cache = null;

  function api(route, body, form) {
    var opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['X-CSRF-Token'] = CSRF;
      if (form) {
        opts.body = body;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
    }
    return fetch(API + route, opts).then(function (r) {
      return r.json().catch(function () { return { error: 'Réponse illisible du serveur (' + r.status + ').' }; }).then(function (data) {
        if (!r.ok || data.error) throw new Error(data.error || ('Erreur ' + r.status));
        return data;
      });
    });
  }

  function list(force) {
    if (!cache || force) {
      cache = api('media-list').then(function (data) {
        if (data.limits && data.limits.chunk) CHUNK = data.limits.chunk;
        return data;
      });
      cache.catch(function () { cache = null; });
    }
    return cache;
  }

  function randomId() {
    var a = new Uint8Array(8);
    crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  // Envoi par morceaux (un seul pour un petit fichier) ; onProgress(0..1). Rend l'élément créé.
  function upload(file, onProgress) {
    var id = randomId();
    var total = Math.max(1, Math.ceil(file.size / CHUNK));
    function send(i) {
      var fd = new FormData();
      fd.append('upload_id', id);
      fd.append('index', String(i));
      fd.append('total', String(total));
      fd.append('size', String(file.size));
      fd.append('name', file.name);
      fd.append('file', file.slice(i * CHUNK, Math.min(file.size, (i + 1) * CHUNK)), 'part');
      return api('media-upload', fd, true).then(function (res) {
        if (onProgress) onProgress((i + 1) / total);
        if (res.done) { cache = null; return res.item; }
        return send(i + 1);
      });
    }
    if (!file.size) return Promise.reject(new Error('Fichier vide.'));
    return send(0);
  }

  function fmtBytes(n) {
    if (n === null || n === undefined) return '';
    if (n < 1024) return n + ' o';
    if (n < 1048576) return Math.round(n / 1024) + ' Ko';
    return (n / 1048576).toFixed(n < 10485760 ? 1 : 0).replace('.', ',') + ' Mo';
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

  function thumb(item) {
    if (item.type === 'video') {
      var v = el('video', { src: item.url + '#t=0.5', preload: 'metadata', muted: '', playsinline: '' });
      v.muted = true;
      return v;
    }
    return el('img', { src: item.web_url || item.url, alt: item.alt || item.title || '', loading: 'lazy' });
  }

  // ── Sélecteur commun ─────────────────────────────────────────────────────
  // open({ accept: 'photo' | 'video' | 'all', title, onPick(item) })
  function open(opts) {
    opts = opts || {};
    var accept = opts.accept || 'all';
    var search = el('input', { type: 'search', placeholder: 'Rechercher (titre, mots-clés, produit…)', 'aria-label': 'Rechercher' });
    var folder = el('select', { 'aria-label': 'Dossier' });
    var fileInput = el('input', { type: 'file', multiple: '', hidden: '', accept: accept === 'photo' ? 'image/*' : accept === 'video' ? 'video/*' : 'image/*,video/*' });
    var sendBtn = el('button', { type: 'button', class: 'btn-small', text: 'Envoyer des fichiers…' });
    var closeBtn = el('button', { type: 'button', class: 'com-pick-close', 'aria-label': 'Fermer', text: '×' });
    var status = el('p', { class: 'com-pick-status', role: 'status' });
    var grid = el('div', { class: 'com-pick-grid' });
    var box = el('div', { class: 'com-pick-box', role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title || 'Médiathèque' }, [
      el('div', { class: 'com-pick-head' }, [el('p', { class: 'eyebrow', text: opts.title || 'Choisir dans la médiathèque' }), closeBtn]),
      el('div', { class: 'com-pick-tools' }, [search, folder, sendBtn, fileInput]),
      status, grid,
    ]);
    var backdrop = el('div', { class: 'com-pick-backdrop' }, [box]);
    var items = [];

    function close() {
      backdrop.remove();
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }

    function render() {
      var q = search.value.trim().toLowerCase();
      var f = folder.value;
      grid.innerHTML = '';
      var shown = items.filter(function (i) {
        if (i.missing || (accept !== 'all' && i.type !== accept)) return false;
        if (f && i.folder !== f) return false;
        if (!q) return true;
        return [i.title, i.tags, i.alt, i.credit, i.folder, i.origin_name, i.source].join(' ').toLowerCase().indexOf(q) !== -1;
      });
      shown.forEach(function (i) {
        var btn = el('button', { type: 'button', class: 'com-pick-item', title: i.title }, [
          thumb(i),
          el('span', { text: (i.fiche ? '[Fiche] ' : '') + (i.title || i.path.split('/').pop()) }),
        ]);
        btn.addEventListener('click', function () {
          status.textContent = 'Un instant…';
          Promise.resolve(opts.onPick ? opts.onPick(i) : null).then(close, function (err) { status.textContent = err.message; });
        });
        grid.appendChild(btn);
      });
      status.textContent = shown.length ? '' : 'Aucun élément ne correspond.';
    }

    function load(force) {
      status.textContent = 'Chargement…';
      list(force).then(function (data) {
        items = data.items;
        var current = folder.value;
        folder.innerHTML = '';
        folder.appendChild(el('option', { value: '', text: 'Tous les dossiers' }));
        data.folders.forEach(function (f) { folder.appendChild(el('option', { value: f, text: f })); });
        folder.value = current;
        render();
      }, function (err) { status.textContent = err.message; });
    }

    search.addEventListener('input', render);
    folder.addEventListener('change', render);
    sendBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      var files = Array.prototype.slice.call(fileInput.files);
      fileInput.value = '';
      var chain = Promise.resolve();
      files.forEach(function (file, n) {
        chain = chain.then(function () {
          return upload(file, function (p) { status.textContent = 'Envoi ' + (n + 1) + '/' + files.length + ' : ' + file.name + ' (' + Math.round(p * 100) + ' %)'; });
        });
      });
      chain.then(function () { load(true); }, function (err) { status.textContent = err.message; load(true); });
    });
    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(backdrop);
    search.focus();
    load(false);
  }

  window.ComMedia = { api: api, list: list, upload: upload, fmtBytes: fmtBytes, open: open, thumb: thumb };

  // ── Fenêtres « Choisir dans la médiathèque » de la plateforme ────────────
  // Le choix est appliqué en passant par un élément déjà présent dans la fenêtre (ses gestionnaires lisent data-media-* au
  // moment du clic) : le champ visé (réglages, bandeau…) est conservé, et l'action de la page reçoit un chemin inscrit dans
  // media_library (media-adopt), seul accepté par la plateforme.
  function enhancePlatformPicker() {
    var box = document.querySelector('.media-pick-box');
    if (!box) return;
    var page = location.pathname.split('/').pop();
    var accept = page === 'banners.php' || page === 'slideshow.php' ? 'all' : 'photo';
    var bar = box.lastElementChild;
    var btn = el('button', { type: 'button', class: 'btn-small com-pick-open', text: 'Toute la médiathèque…' });
    bar.insertBefore(btn, bar.firstChild);
    btn.addEventListener('click', function () {
      open({
        accept: accept,
        title: 'Choisir dans toute la médiathèque',
        onPick: function (item) {
          return api('media-adopt', { path: item.path }).then(function () {
            var proxy = box.querySelector('[data-media-path]');
            if (!proxy) {
              // Fenêtre vide : la ressource est maintenant inscrite, elle apparaîtra après rechargement.
              alert('Image ajoutée à la médiathèque : rouvrez « Choisir dans la médiathèque » pour la sélectionner.');
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
        },
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
      open({
        accept: 'all',
        title: 'Ajouter à la galerie de la fiche',
        onPick: function (item) {
          return api('product-photo-add', { ref: ref, path: item.path }).then(function () { location.reload(); });
        },
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
      document.createTextNode('Vue classique. La médiathèque enrichie (titres, crédits, dossiers, envois groupés, versions web) : '),
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
