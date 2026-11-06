// Visuel promo d'un article en promo : la photo avec un encart « CTA » en overlay (remise, légende, prix promo, ancien prix, bouton), fabriqué DANS LE NAVIGATEUR
// avec les couleurs et les polices de la boutique, en deux formats (3:2 pour l'ordinateur, 9:16 pour le smartphone), puis envoyé au serveur
// (admin/promo-visual-action.php). Il s'affiche sur la page d'accueil et dans les promos de la galerie, avec un compteur d'affichages et de clics.
// Bouton : [data-promo-visual] avec data-ref, data-name, data-price, data-promo, data-headline, data-cta, data-csrf, data-photos (JSON [{path, label}]).
(function () {
  'use strict';
  var URL_ACTION = (window.APP_BASE || '') + '/admin/promo-visual-action.php';

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function euros(v) { var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.').match(/\d+(?:[.,]\d+)?/) || 0); return n; }
  function css(name, fallback) { var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim(); return v || fallback; }

  /** Texte coupé sur la largeur donnée : au plus $max lignes, la dernière terminée par « … » si besoin. */
  function wrap(ctx, text, maxW, max) {
    var words = String(text).split(/\s+/), lines = [], line = '';
    words.forEach(function (w) {
      var t = line ? line + ' ' + w : w;
      if (ctx.measureText(t).width <= maxW || !line) line = t; else { lines.push(line); line = w; }
    });
    if (line) lines.push(line);
    if (lines.length > max) { lines = lines.slice(0, max); var last = lines[max - 1]; while (last.length > 1 && ctx.measureText(last + '…').width > maxW) last = last.slice(0, -1); lines[max - 1] = last + '…'; }
    return lines;
  }
  function round(ctx, x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  /** Dessine le visuel : photo en plein cadre, dégradé sombre en bas, pastille de remise, légende, prix, bouton. */
  function draw(canvas, img, o, W, H) {
    canvas.width = W; canvas.height = H;
    var c = canvas.getContext('2d'), portrait = H > W, m = Math.round(W * (portrait ? 0.06 : 0.04));
    var bodyFont = o.bodyFont, dispFont = o.displayFont;
    c.fillStyle = '#222'; c.fillRect(0, 0, W, H);
    if (img && img.naturalWidth) {
      var k = Math.max(W / img.naturalWidth, H / img.naturalHeight), iw = img.naturalWidth * k, ih = img.naturalHeight * k;
      c.drawImage(img, (W - iw) / 2, (H - ih) / 2, iw, ih);
    }
    var g = c.createLinearGradient(0, H * (portrait ? 0.45 : 0.42), 0, H);
    g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,0.82)');
    c.fillStyle = g; c.fillRect(0, 0, W, H);

    // Pastille de remise (en haut à gauche).
    if (o.headline) {
      var hs = Math.round(W * (portrait ? 0.105 : 0.052));
      c.font = '700 ' + hs + 'px ' + dispFont;
      var hw = c.measureText(o.headline).width + hs * 0.9, hh = hs * 1.55;
      c.fillStyle = o.accent; round(c, m, m, hw, hh, hh * 0.18); c.fill();
      c.fillStyle = o.ink; c.textBaseline = 'middle'; c.textAlign = 'left'; c.fillText(o.headline, m + hs * 0.45, m + hh / 2 + hs * 0.04);
    }

    c.textBaseline = 'alphabetic'; c.textAlign = 'left';
    var cs = Math.round(W * (portrait ? 0.062 : 0.034)), ps = Math.round(W * (portrait ? 0.125 : 0.07)), os = Math.round(ps * 0.42);
    var btnH = Math.round(W * (portrait ? 0.14 : 0.082)), btnFont = Math.round(btnH * 0.38);
    c.font = '700 ' + btnFont + 'px ' + bodyFont;
    var label = (o.cta || 'Voir l\'offre') + '  →', btnW = portrait ? W - 2 * m : Math.round(c.measureText(label).width + btnH * 1.1);
    var bx = portrait ? m : W - m - btnW, by = H - m - btnH;
    c.fillStyle = o.accent; round(c, bx, by, btnW, btnH, btnH / 2); c.fill();
    c.fillStyle = o.ink; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(label, bx + btnW / 2, by + btnH / 2 + btnFont * 0.04);

    // Prix et légende, à gauche (au-dessus du bouton en portrait).
    c.textAlign = 'left'; c.textBaseline = 'alphabetic'; c.fillStyle = '#fff';
    var baseY = portrait ? by - Math.round(btnH * 0.45) : H - m;
    c.font = '700 ' + ps + 'px ' + dispFont;
    c.fillText(o.promo, m, baseY);
    var pw = c.measureText(o.promo).width;
    if (o.showOld && o.price) {
      c.font = '400 ' + os + 'px ' + bodyFont; c.fillStyle = 'rgba(255,255,255,0.78)';
      var ox = m + pw + os * 0.6;
      if (portrait && ox + c.measureText(o.price).width > W - m) ox = m;
      var oy = (portrait && ox === m) ? baseY - ps * 1.05 : baseY;
      c.fillText(o.price, ox, oy);
      var ow = c.measureText(o.price).width; c.fillRect(ox, oy - os * 0.3, ow, Math.max(2, os * 0.07));
    }
    c.fillStyle = '#fff'; c.font = '700 ' + cs + 'px ' + bodyFont;
    var maxW = portrait ? W - 2 * m : (bx - m * 1.5 - m);
    var lines = wrap(c, o.caption, Math.max(maxW, W * 0.4), 2);
    var capBottom = baseY - ps * 1.25 - (portrait && o.showOld ? ps * 0.5 : 0);
    lines.forEach(function (ln, i) { c.fillText(ln, m, capBottom - (lines.length - 1 - i) * cs * 1.2); });
  }

  function open(btn) {
    var d = btn.dataset, photos = []; try { photos = JSON.parse(d.photos || '[]'); } catch (e) {}
    var price = euros(d.price), promo = euros(d.promo);
    var pct = price > 0 && promo > 0 && promo < price ? Math.round((1 - promo / price) * 100) : 0;
    var o = {
      headline: d.headline || (pct ? '−' + pct + ' %' : 'PROMO'), caption: d.name || '', cta: d.cta || 'Voir l\'offre', showOld: true,
      promo: d.promo, price: d.price, accent: css('--accent', '#b5502e'), ink: css('--accent-ink', '#ffffff'),
      bodyFont: getComputedStyle(document.body).fontFamily || 'sans-serif', displayFont: css('--font-display', '') || getComputedStyle(document.body).fontFamily || 'sans-serif',
    };
    var selected = 0, img = null, first = true;   // first : à l'ouverture, une photo introuvable laisse place à la suivante

    var dlg = el('dialog', 'pv'); dlg.setAttribute('aria-label', 'Visuel promo');
    var head = el('div', 'pv-head'); head.appendChild(el('p', 'pv-title', 'Visuel promo — ' + (d.name || '')));
    var x = el('button', 'pv-close', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); head.appendChild(x); dlg.appendChild(head);
    dlg.appendChild(el('p', 'pv-note', 'La photo reçoit un encart avec la remise, le prix et un bouton. Il s\'affichera sur la page d\'accueil de la boutique et dans les promos de la galerie, avec un compteur d\'affichages et de clics. Il suit les prix de la fiche : si le prix change, refaites-le.'));

    var thumbs = el('div', 'pv-thumbs'); dlg.appendChild(thumbs);
    var form = el('div', 'pv-form');
    function field(label, value, max) { var l = el('label', 'pv-field', label); var i = document.createElement('input'); i.type = 'text'; i.maxLength = max; i.value = value; l.appendChild(i); form.appendChild(l); return i; }
    var inHead = field('Accroche (pastille)', o.headline, 20), inCap = field('Légende', o.caption, 70), inCta = field('Texte du bouton', o.cta, 28);
    var oldL = el('label', 'pv-check'); var oldC = document.createElement('input'); oldC.type = 'checkbox'; oldC.checked = true; oldL.appendChild(oldC); oldL.appendChild(document.createTextNode(' Montrer l\'ancien prix barré')); form.appendChild(oldL);
    dlg.appendChild(form);

    var previews = el('div', 'pv-previews'); var cv1 = el('canvas', 'pv-c1'), cv2 = el('canvas', 'pv-c2');
    cv1.setAttribute('aria-label', 'Aperçu ordinateur 3:2'); cv2.setAttribute('aria-label', 'Aperçu smartphone 9:16');
    var f1 = el('figure'); f1.appendChild(cv1); f1.appendChild(el('figcaption', '', 'Ordinateur (3:2)'));
    var f2 = el('figure'); f2.appendChild(cv2); f2.appendChild(el('figcaption', '', 'Smartphone (9:16)'));
    previews.appendChild(f1); previews.appendChild(f2); dlg.appendChild(previews);

    var status = el('p', 'pv-status'); status.setAttribute('role', 'status'); dlg.appendChild(status);
    var bar = el('div', 'pv-bar');
    var save = el('button', 'btn btn-primary', 'Enregistrer le visuel'); save.type = 'button';
    var cancel = el('button', 'btn btn-ghost', 'Annuler'); cancel.type = 'button';
    bar.appendChild(save); bar.appendChild(cancel); dlg.appendChild(bar);
    document.body.appendChild(dlg);

    function render() {
      o.headline = inHead.value.trim(); o.caption = inCap.value.trim(); o.cta = inCta.value.trim(); o.showOld = oldC.checked;
      draw(cv1, img, o, 1500, 1000); draw(cv2, img, o, 1080, 1920);
    }
    function loadPhoto(i) {
      selected = i;
      Array.prototype.forEach.call(thumbs.children, function (b, k) { b.setAttribute('aria-pressed', String(k === i)); });
      var p = photos[i]; if (!p) { img = null; render(); return; }
      var im = new Image(); im.onload = function () { first = false; img = im; status.textContent = ''; render(); }; im.onerror = function () { if (first && i + 1 < photos.length) { loadPhoto(i + 1); return; } first = false; status.textContent = 'Photo illisible : choisissez-en une autre.'; };
      im.src = '/' + p.path;
    }
    photos.forEach(function (p, i) {
      var b = el('button', 'pv-thumb'); b.type = 'button'; b.title = p.label || ''; var t = el('img'); t.src = '/' + p.path; t.alt = ''; b.appendChild(t);
      b.addEventListener('click', function () { loadPhoto(i); }); thumbs.appendChild(b);
    });
    [inHead, inCap, inCta, oldC].forEach(function (i) { i.addEventListener('input', render); i.addEventListener('change', render); });

    var done = false;
    function close() { if (done) return; done = true; try { dlg.close(); } catch (e) {} dlg.remove(); }
    x.addEventListener('click', close); cancel.addEventListener('click', close);
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    dlg.addEventListener('click', function (e) { if (e.target !== dlg) return; var r = dlg.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close(); });

    save.addEventListener('click', function () {
      render(); save.disabled = true; status.textContent = 'Enregistrement…';
      Promise.all([cv1, cv2].map(function (c) { return new Promise(function (res) { c.toBlob(res, 'image/jpeg', 0.9); }); })).then(function (blobs) {
        var fd = new FormData();
        fd.append('action', 'save'); fd.append('csrf', d.csrf); fd.append('ref', d.ref); fd.append('headline', o.headline); fd.append('cta', o.cta);
        fd.append('desktop', blobs[0], 'promo.jpg'); fd.append('mobile', blobs[1], 'promo-mobile.jpg');
        return fetch(URL_ACTION, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').' }; }); });
      }).then(function (res) {
        if (!res.ok) { save.disabled = false; status.textContent = res.error || 'Enregistrement impossible.'; return; }
        status.textContent = 'Visuel enregistré ✓'; setTimeout(function () { close(); location.reload(); }, 600);
      }).catch(function () { save.disabled = false; status.textContent = 'Connexion perdue : réessayez.'; });
    });

    dlg.showModal();
    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    ready.then(function () { loadPhoto(0); });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-promo-visual]'); if (b) { e.preventDefault(); open(b); }
    var r = e.target.closest('[data-promo-visual-remove]');
    if (r) {
      e.preventDefault();
      if (!confirm('Retirer le visuel promo de cette pièce ? Il ne sera plus montré sur l\'accueil ni dans la galerie.')) return;
      var fd = new FormData(); fd.append('action', 'delete'); fd.append('csrf', r.dataset.csrf); fd.append('ref', r.dataset.ref);
      fetch(URL_ACTION, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { location.reload(); });
    }
  });
})();
