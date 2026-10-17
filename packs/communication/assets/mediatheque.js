// Pack Communication : page « Médiathèque » (pages/mediatheque.php). S'appuie sur window.ComMedia (assets/picker.js) pour
// l'API, l'envoi par morceaux et les vignettes.
(function () {
  'use strict';
  var C = window.ComMedia;
  var root = document.getElementById('com-media');
  if (!C || !root) return;
  var $ = function (id) { return document.getElementById(id); };
  var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  var data = { items: [], links: [], folders: [], stats: {}, limits: {}, ai: false };
  var folded = false;
  var current = null; // élément ouvert dans la fiche d'édition

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function stats(text) { $('com-stats').textContent = text; }

  function load() {
    return C.list(true).then(function (d) {
      data = d;
      $('com-limits').textContent = 'JPEG, PNG, WebP, GIF jusqu\'à ' + C.fmtBytes(d.limits.image) + ' ; MP4, MOV, WebM jusqu\'à ' + C.fmtBytes(d.limits.video) + ' (envoyées en plusieurs morceaux).';
      var dl = $('com-folders');
      dl.innerHTML = '';
      d.folders.forEach(function (f) { dl.appendChild(el('option', { value: f })); });
      render();
    }, function (err) { stats(err.message); });
  }

  // ── Liste ────────────────────────────────────────────────────────────────
  function entries() {
    var type = $('com-type').value;
    var q = $('com-q').value.trim().toLowerCase();
    var list = [];
    if (type !== 'link') data.items.forEach(function (i) { if (!type || i.type === type) list.push({ kind: 'item', i: i }); });
    if (!type || type === 'link') data.links.forEach(function (l) { list.push({ kind: 'link', i: l }); });
    if (q) {
      list = list.filter(function (e) {
        var i = e.i;
        return [i.title, i.tags, i.alt, i.credit, i.folder, i.origin_name, i.source, i.url].join(' ').toLowerCase().indexOf(q) !== -1;
      });
    }
    var sort = $('com-sort').value;
    var date = function (e) { return e.i.created_at || ''; };
    list.sort(function (a, b) {
      if (sort === 'old') return date(a) < date(b) ? -1 : 1;
      if (sort === 'name') return (a.i.title || '').localeCompare(b.i.title || '', 'fr');
      if (sort === 'size') return (b.i.bytes || 0) - (a.i.bytes || 0);
      if (sort === 'ratio') {
        var r = function (e) { return e.i.width && e.i.height ? e.i.width / e.i.height : 0; };
        return r(b) - r(a);
      }
      return date(a) < date(b) ? 1 : -1;
    });
    return list;
  }

  function groupOf(e) {
    var by = $('com-group').value;
    var i = e.i;
    if (by === 'folder') return i.folder || 'Sans dossier';
    if (by === 'product') return e.kind === 'link' ? 'Vidéos en ligne' : (i.fiche ? 'Réf. ' + i.fiche.ref + ' — ' + i.fiche.name : (i.origin_name ? 'Ancien article : ' + i.origin_name : 'Sans produit'));
    if (by === 'source') return e.kind === 'link' ? 'Vidéos en ligne' : (i.source || 'Sans source');
    var d = (i.created_at || '').match(/^(\d{4})-(\d{2})/);
    return d ? MONTHS[parseInt(d[2], 10) - 1] + ' ' + d[1] : 'Sans date';
  }

  function card(e) {
    var i = e.i;
    var badges = [];
    if (e.kind === 'link') badges.push(i.platform === 'youtube' ? 'YouTube' : 'Vimeo');
    else {
      if (i.fiche) badges.push('Fiche');
      (i.badges || []).forEach(function (b) { badges.push(b); });
      if (i.type === 'video') badges.push('Vidéo');
      if (i.in_gallery) badges.push('Galerie');
      if (i.missing) badges.push('Fichier absent');
    }
    var media = e.kind === 'link'
      ? (i.thumb ? el('img', { src: i.thumb, alt: '', loading: 'lazy' }) : el('span', { class: 'com-card-ph', text: '▶' }))
      : C.thumb(i);
    var meta = e.kind === 'link' ? 'Vidéo en ligne' : [i.width && i.height ? i.width + '×' + i.height : '', C.fmtBytes(i.bytes)].filter(Boolean).join(' · ');
    var btn = el('button', { type: 'button', class: 'com-card' }, [
      el('span', { class: 'com-card-media' }, [media, badges.length ? el('span', { class: 'com-badges', text: badges.join(' · ') }) : null]),
      el('span', { class: 'com-card-title', text: i.title || (i.path || '').split('/').pop() }),
      el('span', { class: 'com-card-meta', text: meta }),
    ]);
    btn.addEventListener('click', function () { e.kind === 'link' ? openLink(i) : openItem(i); });
    return btn;
  }

  function render() {
    var list = entries();
    var groups = {};
    var order = [];
    list.forEach(function (e) {
      var g = groupOf(e);
      if (!groups[g]) { groups[g] = []; order.push(g); }
      groups[g].push(e);
    });
    if ($('com-group').value === 'folder') order.sort(function (a, b) { return a === 'Sans dossier' ? 1 : b === 'Sans dossier' ? -1 : a.localeCompare(b, 'fr'); });
    var wrap = $('com-groups');
    wrap.innerHTML = '';
    order.forEach(function (g) {
      var grid = el('div', { class: 'com-grid' });
      groups[g].forEach(function (e) { grid.appendChild(card(e)); });
      var det = el('details', { class: 'com-group' }, [el('summary', {}, [el('strong', { text: g }), ' ', el('span', { text: '(' + groups[g].length + ')' })]), grid]);
      det.open = !folded;
      wrap.appendChild(det);
    });
    stats(list.length + ' élément(s) affiché(s) sur ' + data.stats.count + (data.stats.own_bytes ? ' · vos envois occupent ' + C.fmtBytes(data.stats.own_bytes) : '') + '.');
    var missing = data.stats.missing_web || 0;
    $('com-web-row').hidden = !missing;
    $('com-web-all').textContent = 'Créer les versions web manquantes (' + missing + ')';
  }

  // ── Envois ───────────────────────────────────────────────────────────────
  function sendFiles(files) {
    var listEl = $('com-uploads');
    var chain = Promise.resolve();
    Array.prototype.forEach.call(files, function (file) {
      var bar = el('progress', { max: '100', value: '0' });
      var msg = el('span', { text: 'en attente' });
      listEl.appendChild(el('li', {}, [el('strong', { text: file.name }), ' ', bar, ' ', msg]));
      chain = chain.then(function () {
        msg.textContent = 'envoi…';
        return C.upload(file, function (p) { bar.value = Math.round(p * 100); }).then(function () {
          msg.textContent = '✓ ajouté';
        }, function (err) {
          msg.textContent = '✗ ' + err.message;
          msg.className = 'com-error';
        });
      });
    });
    chain.then(load);
  }
  $('com-choose').addEventListener('click', function () { $('com-files').click(); });
  $('com-files').addEventListener('change', function () { sendFiles(this.files); this.value = ''; });
  var drop = $('com-drop');
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files.length) sendFiles(e.dataTransfer.files); });

  function formSubmit(id, route, done) {
    var form = $(id);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button');
      btn.disabled = true;
      stats('Un instant…');
      C.api(route, { url: form.elements.url.value }).then(function () {
        form.reset();
        return load().then(function () { stats(done); });
      }, function (err) { stats(err.message); }).then(function () { btn.disabled = false; });
    });
  }
  formSubmit('com-import', 'media-import-url', 'Fichier importé.');
  formSubmit('com-link', 'links', 'Vidéo en ligne ajoutée.');

  $('com-web-all').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    (function step(total) {
      C.api('media-web', { batch: true }).then(function (r) {
        total += r.done;
        btn.textContent = 'Versions web : ' + total + ' créée(s), ' + r.remaining + ' restante(s)…';
        if (r.remaining > 0 && (r.done || r.failed)) step(total); else { btn.disabled = false; load(); }
      }, function (err) { btn.disabled = false; stats(err.message); });
    })(0);
  });

  ['com-q', 'com-type', 'com-group', 'com-sort'].forEach(function (id) { $(id).addEventListener(id === 'com-q' ? 'input' : 'change', render); });
  $('com-fold').addEventListener('click', function () {
    folded = !folded;
    this.textContent = folded ? 'Tout déplier' : 'Tout replier';
    document.querySelectorAll('.com-group').forEach(function (d) { d.open = !folded; });
  });

  // ── Fiche d'édition ──────────────────────────────────────────────────────
  var dialog = $('com-edit');
  var form = $('com-edit-form');
  var editStatus = $('com-edit-status');
  function act(name) { return form.querySelector('[data-act="' + name + '"]'); }
  function info(rows) {
    var dl = $('com-edit-info');
    dl.innerHTML = '';
    rows.forEach(function (r) {
      if (!r[1]) return;
      dl.appendChild(el('dt', { text: r[0] }));
      dl.appendChild(el('dd', {}, [r[1]]));
    });
  }

  function openItem(i) {
    current = { kind: 'item', i: i };
    var prev = $('com-edit-preview');
    prev.innerHTML = '';
    prev.appendChild(i.type === 'video' ? el('video', { src: i.url, controls: '', preload: 'metadata', playsinline: '' }) : el('img', { src: i.web_url || i.url, alt: i.alt || '' }));
    $('com-edit-kind').textContent = i.fiche ? 'Photo de la fiche Réf. ' + i.fiche.ref : (i.type === 'video' ? 'Vidéo' : 'Image');
    ['title', 'alt', 'credit', 'tags', 'folder'].forEach(function (k) { form.elements[k].value = i[k] || ''; });
    form.elements.in_gallery.checked = !!i.in_gallery;
    Array.prototype.forEach.call(form.querySelectorAll('label'), function (l) { l.hidden = false; });
    var ficheLink = i.fiche ? el('a', { href: (window.APP_BASE || '') + '/admin/gallery.php?ref=' + encodeURIComponent(i.fiche.ref), text: i.fiche.name + ' (gérer la galerie)' }) : null;
    info([
      ['Dimensions', i.width && i.height ? i.width + ' × ' + i.height + ' px' : ''],
      ['Poids', C.fmtBytes(i.bytes)],
      ['Source', i.source],
      ['Fiche', ficheLink],
      ['Version web', i.web_url ? el('a', { href: i.web_url, target: '_blank', rel: 'noopener', text: i.web_url === i.url ? 'l\'original (déjà léger)' : 'ouvrir' }) : (i.type === 'photo' ? 'pas encore créée' : '')],
      ['Fichier', el('a', { href: i.url, target: '_blank', rel: 'noopener', text: i.path })],
    ]);
    act('ai').hidden = i.type !== 'photo' || !data.ai;
    act('web').hidden = i.type !== 'photo';
    act('delete').hidden = !i.media_id;
    act('save').hidden = false;
    editStatus.textContent = i.media_id ? '' : 'Photo de fiche : ses informations s\'enregistrent ici, la photo se retire depuis la galerie du produit.';
    dialog.showModal();
  }

  function openLink(l) {
    current = { kind: 'link', i: l };
    var prev = $('com-edit-preview');
    prev.innerHTML = '';
    prev.appendChild(el('iframe', { src: l.embed, title: l.title, allow: 'fullscreen; picture-in-picture', loading: 'lazy', referrerpolicy: 'strict-origin-when-cross-origin' }));
    $('com-edit-kind').textContent = 'Vidéo en ligne';
    Array.prototype.forEach.call(form.querySelectorAll('label'), function (lab) {
      var input = lab.querySelector('input, textarea');
      lab.hidden = !input || (input.name !== 'title' && input.name !== 'folder');
    });
    form.elements.title.value = l.title || '';
    form.elements.folder.value = l.folder || '';
    info([['Adresse', el('a', { href: l.url, target: '_blank', rel: 'noopener', text: l.url })]]);
    act('ai').hidden = true;
    act('web').hidden = true;
    act('delete').hidden = false;
    editStatus.textContent = '';
    dialog.showModal();
  }

  function busy(promise, okText) {
    editStatus.textContent = 'Un instant…';
    return promise.then(function (r) { editStatus.textContent = okText || ''; return r; }, function (err) { editStatus.textContent = err.message; throw err; });
  }

  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || !current) return;
    var i = current.i;
    var name = b.dataset.act;
    if (name === 'close') { dialog.close(); return; }
    if (current.kind === 'link') {
      if (name === 'save') busy(C.api('links', { url: i.url, title: form.elements.title.value, folder: form.elements.folder.value }), 'Enregistré.').then(load);
      if (name === 'copy') navigator.clipboard.writeText(i.url).then(function () { editStatus.textContent = 'Adresse copiée.'; });
      if (name === 'delete' && confirm('Retirer cette vidéo en ligne de la médiathèque ?')) busy(C.api('link-delete', { id: i.id })).then(function () { dialog.close(); load(); });
      return;
    }
    if (name === 'save') {
      busy(C.api('media-update', {
        path: i.path, title: form.elements.title.value, alt: form.elements.alt.value, credit: form.elements.credit.value,
        tags: form.elements.tags.value, folder: form.elements.folder.value, in_gallery: form.elements.in_gallery.checked ? 1 : 0,
      }), 'Enregistré.').then(function (r) { current.i = r.item; load(); });
    } else if (name === 'ai') {
      busy(C.api('media-describe', { path: i.path }), 'Proposition de l\'IA : vérifiez puis enregistrez.').then(function (r) {
        if (!form.elements.title.value) form.elements.title.value = r.title;
        form.elements.alt.value = r.alt;
        form.elements.tags.value = r.tags;
      });
    } else if (name === 'web') {
      busy(C.api('media-web', { path: i.path }), 'Version web créée.').then(function (r) { current.i = r.item; load(); openItem(r.item); });
    } else if (name === 'copy') {
      navigator.clipboard.writeText(location.origin + i.url).then(function () { editStatus.textContent = 'Adresse copiée.'; });
    } else if (name === 'delete') {
      if (!confirm('Retirer « ' + (i.title || i.path) + ' » de la médiathèque ?')) return;
      busy(C.api('media-delete', { path: i.path })).then(function (r) {
        dialog.close();
        load().then(function () {
          stats(r.file_kept ? 'Retiré de la médiathèque (fichier conservé : il est utilisé ailleurs dans la boutique ou ne vient pas de vos envois).' : 'Retiré de la médiathèque, fichier effacé.');
        });
      }).catch(function () {});
    }
  });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });

  load();
})();
