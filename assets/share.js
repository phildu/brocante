// Fenêtre « Partager » : réseaux sociaux, lien à copier, code QR (téléchargeable en PNG ou SVG, imprimable).
// Boutons : [data-share-open="share|qr"] avec data-share-url / -title / -text / -image (voir includes/share.php). Sur téléphone, « Partager »
// ouvre d'abord le menu de partage du système (navigator.share) quand il existe ; le code QR est fabriqué dans le navigateur (assets/vendor/qrcode-generator.js).
(function () {
  'use strict';
  var base = (document.currentScript && document.currentScript.src || '').replace(/\/share\.js.*$/, '');
  var overlay, dialog, current, lastFocus;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function enc(s) { return encodeURIComponent(s || ''); }
  function loadQr() {
    if (typeof window.qrcode === 'function') return Promise.resolve();
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script'); s.src = base + '/vendor/qrcode-generator.js'; s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
    });
  }
  function qrFor(url) { var q = window.qrcode(0, 'M'); q.addData(url); q.make(); return q; }

  function networks(d) {
    var u = enc(d.url), t = enc(d.title), x = enc(d.text || d.title);
    return [
      ['WhatsApp', '#25d366', 'W', 'https://wa.me/?text=' + enc((d.text || d.title) + ' ' + d.url)],
      ['Facebook', '#1877f2', 'f', 'https://www.facebook.com/sharer/sharer.php?u=' + u],
      ['X', '#111111', 'X', 'https://twitter.com/intent/tweet?url=' + u + '&text=' + x],
      ['Pinterest', '#e60023', 'P', 'https://www.pinterest.com/pin/create/button/?url=' + u + '&description=' + x + (d.image ? '&media=' + enc(d.image) : '')],
      ['LinkedIn', '#0a66c2', 'in', 'https://www.linkedin.com/sharing/share-offsite/?url=' + u],
      ['Telegram', '#229ed9', 'T', 'https://t.me/share/url?url=' + u + '&text=' + x],
      ['SMS', '#34c759', '💬', 'sms:?&body=' + enc((d.text || d.title) + ' ' + d.url)],
      ['E-mail', '#6b6b6b', '✉', 'mailto:?subject=' + t + '&body=' + enc((d.text ? d.text + '\n\n' : '') + d.url)]
    ];
  }

  function toast(msg) { var n = dialog.querySelector('.share-toast'); n.textContent = msg; clearTimeout(toast.t); toast.t = setTimeout(function () { n.textContent = ''; }, 2600); }
  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var i = dialog.querySelector('.share-copy input'); i.focus(); i.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
    });
  }
  function download(name, blob) { var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500); }
  function fileName(ext) { var s = (current.title || 'page').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); return 'qr-' + (s || 'page') + '.' + ext; }

  function png(q) {
    var n = q.getModuleCount(), margin = 4, cell = Math.max(4, Math.floor(1024 / (n + margin * 2))), size = (n + margin * 2) * cell;
    var c = document.createElement('canvas'); c.width = c.height = size; var g = c.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, size, size); g.fillStyle = '#000';
    for (var r = 0; r < n; r++) for (var k = 0; k < n; k++) if (q.isDark(r, k)) g.fillRect((k + margin) * cell, (r + margin) * cell, cell, cell);
    return new Promise(function (resolve) { c.toBlob(resolve, 'image/png'); });
  }

  function renderQr() {
    var box = dialog.querySelector('.share-qr-img');
    return loadQr().then(function () {
      var q = qrFor(current.url);
      box.innerHTML = q.createSvgTag({ cellSize: 4, margin: 4, scalable: true, alt: 'Code QR : ' + current.url });
      dialog._qr = q;
    }, function () { box.textContent = 'Code QR indisponible.'; });
  }

  function select(tab) {
    dialog.querySelectorAll('.share-tabs button').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === tab)); });
    dialog.querySelector('[data-panel="share"]').hidden = tab !== 'share';
    dialog.querySelector('[data-panel="qr"]').hidden = tab !== 'qr';
    if (tab === 'qr' && !dialog._qr) renderQr();
  }

  function build() {
    overlay = el('div', 'share-overlay'); overlay.hidden = true;
    dialog = el('div', 'share-dialog'); dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.setAttribute('aria-labelledby', 'share-title');
    var close = el('button', 'share-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'Fermer');
    var h = el('h2', '', 'Partager'); h.id = 'share-title';
    dialog.appendChild(close); dialog.appendChild(h); dialog.appendChild(el('p', 'share-what'));
    var tabs = el('div', 'share-tabs'); tabs.setAttribute('role', 'tablist');
    [['share', 'Réseaux et lien'], ['qr', 'Code QR']].forEach(function (t) { var b = el('button', '', t[1]); b.type = 'button'; b.dataset.tab = t[0]; b.setAttribute('role', 'tab'); b.addEventListener('click', function () { select(t[0]); }); tabs.appendChild(b); });
    dialog.appendChild(tabs);

    var p1 = el('div'); p1.dataset.panel = 'share'; p1.appendChild(el('div', 'share-nets'));
    var cp = el('div', 'share-copy'); var inp = document.createElement('input'); inp.readOnly = true; inp.setAttribute('aria-label', 'Adresse de la page');
    var cb = el('button', '', 'Copier'); cb.type = 'button'; cb.addEventListener('click', function () { copy(current.url).then(function () { toast('Lien copié ✓'); cb.textContent = 'Copié ✓'; setTimeout(function () { cb.textContent = 'Copier'; }, 2000); }, function () { toast('Copie impossible : sélectionnez le lien.'); }); });
    cp.appendChild(inp); cp.appendChild(cb); p1.appendChild(cp);
    if (navigator.share) { var nb = el('button', 'share-native', 'Autres applications…'); nb.type = 'button'; nb.addEventListener('click', function () { nativeShare(); }); p1.appendChild(nb); }
    dialog.appendChild(p1);

    var p2 = el('div'); p2.dataset.panel = 'qr'; p2.hidden = true;
    var qr = el('div', 'share-qr'); qr.appendChild(el('div', 'share-qr-img'));
    qr.appendChild(el('p', 'share-qr-note', 'Scannez avec l\'appareil photo d\'un téléphone pour ouvrir la page.'));
    var act = el('div', 'share-qr-actions');
    [['Télécharger (PNG)', function () { png(dialog._qr).then(function (b) { download(fileName('png'), b); }); }, false],
     ['Télécharger (SVG)', function () { download(fileName('svg'), new Blob([dialog._qr.createSvgTag({ cellSize: 10, margin: 40 })], { type: 'image/svg+xml' })); }, true],
     ['Imprimer', printQr, true]].forEach(function (a) { var b = el('button', a[2] ? 'is-ghost' : '', a[0]); b.type = 'button'; b.addEventListener('click', function () { if (dialog._qr) a[1](); }); act.appendChild(b); });
    qr.appendChild(act); p2.appendChild(qr); dialog.appendChild(p2);
    dialog.appendChild(el('p', 'share-toast')); dialog.querySelector('.share-toast').setAttribute('role', 'status');
    overlay.appendChild(dialog); document.body.appendChild(overlay);
    close.addEventListener('click', closeDialog);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeDialog(); });
    document.addEventListener('keydown', function (e) { if (!overlay.hidden && e.key === 'Escape') closeDialog(); });
  }

  function printQr() {
    var w = window.open('', '_blank', 'width=640,height=760'); if (!w) { toast('Autorisez les fenêtres pour imprimer.'); return; }
    var svg = dialog._qr.createSvgTag({ cellSize: 8, margin: 32, scalable: true });
    w.document.write('<!doctype html><meta charset="utf-8"><title>Code QR</title><style>body{font-family:sans-serif;text-align:center;margin:30px}svg{width:420px;height:420px}h1{font-size:22px;margin:0 0 6px}p{color:#555;word-break:break-all}</style><h1>'
      + current.title.replace(/</g, '&lt;') + '</h1>' + svg + '<p>' + current.url.replace(/</g, '&lt;') + '</p><script>window.onload=function(){window.print()}<\/script>');
    w.document.close();
  }

  function nativeShare() {
    return navigator.share({ title: current.title, text: current.text || current.title, url: current.url });
  }

  function closeDialog() { overlay.hidden = true; document.body.style.overflow = ''; if (lastFocus) lastFocus.focus(); }

  function open(btn, tab) {
    if (!overlay) build();
    current = { url: btn.dataset.shareUrl || location.href, title: btn.dataset.shareTitle || document.title, text: btn.dataset.shareText || '', image: btn.dataset.shareImage || '' };
    dialog._qr = null; dialog.querySelector('.share-qr-img').innerHTML = '';
    dialog.querySelector('.share-what').textContent = current.title;
    dialog.querySelector('.share-copy input').value = current.url;
    var nets = dialog.querySelector('.share-nets'); nets.innerHTML = '';
    networks(current).forEach(function (n) {
      var a = el('a'); a.href = n[3]; a.target = '_blank'; a.rel = 'noopener noreferrer';
      var i = el('span', 'ico', n[2]); i.style.background = n[1]; a.appendChild(i); a.appendChild(el('span', '', n[0])); nets.appendChild(a);
    });
    dialog.querySelector('.share-toast').textContent = '';
    lastFocus = btn; overlay.hidden = false; document.body.style.overflow = 'hidden';
    select(tab); dialog.querySelector('.share-close').focus();
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-share-open]'); if (!btn) return;
    e.preventDefault();
    var tab = btn.dataset.shareOpen === 'qr' ? 'qr' : 'share';
    // Téléphone : le menu de partage du système d'abord (WhatsApp, Messages, Instagram…) ; la fenêtre reste accessible si on l'annule.
    // [data-share-dialog] : on veut la fenêtre complète (réseaux, WhatsApp, SMS, code QR) même sur téléphone.
    if (tab === 'share' && !btn.hasAttribute('data-share-dialog') && navigator.share && window.matchMedia('(pointer: coarse)').matches) {
      current = { url: btn.dataset.shareUrl || location.href, title: btn.dataset.shareTitle || document.title, text: btn.dataset.shareText || '' };
      nativeShare().catch(function (err) { if (err && err.name !== 'AbortError') open(btn, 'share'); });
      return;
    }
    open(btn, tab);
  });
})();
