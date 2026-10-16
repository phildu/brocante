// Mesurer une pièce sur une photo : un repère de taille connue (double décimètre, mètre, pièce de monnaie) est posé à côté de la pièce,
// on trace le repère au doigt pour étalonner la photo, puis on trace les dimensions de la pièce (largeur, hauteur, profondeur…).
// Une loupe suit le doigt pour viser juste. Option : « Estimer avec l'IA » (action « measure » de admin/quick-add-action.php).
//   studioMeasure({images: [{url|blob, label}], ai: bool}) → Promise<{text, dims, newBlob}|null>
//     text : la taille composée (« H 30 × L 25 × P 12 cm ») ; newBlob : la photo de mesure si elle vient d'être prise ; null si annulé.
// La mesure est exacte quand l'appareil est parallèle à la pièce et que le repère est au même niveau qu'elle : sinon, perspective.
(function () {
  'use strict';
  var ACTION = (document.querySelector('.qa') && document.querySelector('.qa').dataset.action) || ((window.APP_BASE || '') + '/admin/quick-add-action.php');
  var REFS = [
    { key: 'regle', label: 'Double décimètre (20 cm)', cm: 20 },
    { key: 'metre', label: 'Mètre / mètre ruban (100 cm)', cm: 100 },
    { key: 'eur2', label: 'Pièce de 2 € (25,75 mm)', cm: 2.575 },
    { key: 'eur1', label: 'Pièce de 1 € (23,25 mm)', cm: 2.325 },
    { key: 'c50', label: 'Pièce de 50 c (24,25 mm)', cm: 2.425 },
    { key: 'c20', label: 'Pièce de 20 c (22,25 mm)', cm: 2.225 },
    { key: 'c10', label: 'Pièce de 10 c (19,75 mm)', cm: 1.975 },
    { key: 'autre', label: 'Autre repère (longueur à saisir)', cm: 10 },
  ];
  var KINDS = [['H', 'Hauteur', '#c0392b'], ['L', 'Largeur', '#1f77b4'], ['P', 'Profondeur', '#2e8b57'], ['Ø', 'Diamètre', '#8e44ad'], ['', 'Autre', '#d68910']];
  var LOUPE = 3;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function nf(v) { return String(v).replace('.', ','); }
  /** 0,5 cm près sous 20 cm, 1 cm près au-delà. */
  function round(v) { return v < 20 ? Math.round(v * 2) / 2 : Math.round(v); }
  function shrink(file, max) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var k = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  window.studioMeasure = function (opts) {
    opts = opts || {};
    var images = (opts.images || []).slice();
    return new Promise(function (resolve) {
      var urls = [];
      var state = { src: 0, newBlob: null, ref: null, refCm: 20, items: [], kind: 0, text: '', textEdited: false, aiNote: '' };
      var dlg = el('dialog', 'mt'); dlg.setAttribute('aria-label', 'Mesurer la pièce');
      var head = el('div', 'mt-head');
      head.appendChild(el('p', 'mt-title', 'Mesurer la pièce'));
      var closeX = el('button', 'mt-close', '×'); closeX.type = 'button'; closeX.setAttribute('aria-label', 'Fermer sans enregistrer');
      head.appendChild(closeX);
      dlg.appendChild(head);
      dlg.appendChild(el('p', 'mt-note', 'Photographiez la pièce de face (téléphone parallèle à la pièce, sur un support fixe si possible), avec un repère posé à côté d\'elle, au même niveau : double décimètre, mètre ou pièce de monnaie. Tracez ensuite le repère, puis les dimensions.'));

      // Photo à mesurer : une des photos de la pièce, ou une nouvelle photo.
      var srcRow = el('div', 'mt-src');
      var fileLab = el('label', 'mt-new', '📷 Nouvelle photo'); var fileIn = document.createElement('input'); fileIn.type = 'file'; fileIn.accept = 'image/*'; fileIn.setAttribute('capture', 'environment'); fileIn.hidden = true; fileLab.appendChild(fileIn);
      dlg.appendChild(srcRow);

      // Repère.
      var refRow = el('div', 'mt-ref');
      var sel = document.createElement('select'); sel.setAttribute('aria-label', 'Repère de taille');
      REFS.forEach(function (r) { var o = document.createElement('option'); o.value = r.key; o.textContent = r.label; sel.appendChild(o); });
      var cmIn = document.createElement('input'); cmIn.type = 'number'; cmIn.step = '0.01'; cmIn.min = '0.1'; cmIn.inputMode = 'decimal'; cmIn.value = '20'; cmIn.setAttribute('aria-label', 'Longueur tracée du repère, en cm');
      refRow.appendChild(sel); refRow.appendChild(el('span', 'mt-cm', 'longueur tracée :')); refRow.appendChild(cmIn); refRow.appendChild(el('span', 'mt-cm', 'cm'));
      dlg.appendChild(refRow);

      var step = el('p', 'mt-step', '');
      dlg.appendChild(step);

      var stage = el('div', 'mt-stage');
      var img = el('img'); img.alt = ''; img.draggable = false; stage.appendChild(img);
      var NS = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(NS, 'svg'); svg.setAttribute('class', 'mt-svg'); stage.appendChild(svg);
      var loupe = el('div', 'mt-loupe'); loupe.hidden = true; stage.appendChild(loupe);
      dlg.appendChild(stage);

      var kinds = el('div', 'mt-kinds'); kinds.setAttribute('role', 'group'); kinds.setAttribute('aria-label', 'Dimension à tracer');
      KINDS.forEach(function (k, i) {
        var b = el('button', '', k[1]); b.type = 'button'; b.style.setProperty('--c', k[2]);
        b.addEventListener('click', function () { state.kind = i; render(); });
        kinds.appendChild(b);
      });
      dlg.appendChild(kinds);

      var list = el('div', 'mt-list'); dlg.appendChild(list);
      var out = el('label', 'mt-out', 'Taille de la pièce'); var outIn = document.createElement('input'); outIn.type = 'text'; outIn.maxLength = 60; outIn.placeholder = 'H 30 × L 25 × P 12 cm'; out.appendChild(outIn);
      dlg.appendChild(out);
      var aiNote = el('p', 'mt-note'); aiNote.hidden = true; dlg.appendChild(aiNote);

      var bar = el('div', 'mt-bar');
      var ok = el('button', 'btn btn-primary', 'Utiliser cette taille'); ok.type = 'button';
      var ai = el('button', 'btn btn-ghost', 'Estimer avec l\'IA'); ai.type = 'button'; ai.hidden = !opts.ai;
      var undo = el('button', 'btn btn-ghost', '↶ Annuler le dernier tracé'); undo.type = 'button';
      var redo = el('button', 'btn btn-ghost', 'Retracer le repère'); redo.type = 'button';
      var cancel = el('button', 'btn btn-ghost', 'Annuler'); cancel.type = 'button';
      bar.appendChild(ok); bar.appendChild(undo); bar.appendChild(redo); bar.appendChild(ai); bar.appendChild(cancel);
      dlg.appendChild(bar);
      document.body.appendChild(dlg);

      var refLine = null;           // {a:[x,y], b:[x,y]} en fractions de l'image
      var temp = null, mode = 'ref';
      function srcUrl(s) { if (s.url) return s.url; if (!s._u) { s._u = URL.createObjectURL(s.blob); urls.push(s._u); } return s._u; }
      function natural() { return [img.naturalWidth || 1, img.naturalHeight || 1]; }
      function lenPx(l) { var n = natural(); return Math.hypot((l.b[0] - l.a[0]) * n[0], (l.b[1] - l.a[1]) * n[1]); }
      function pxPerCm() { return refLine && state.refCm > 0 ? lenPx(refLine) / state.refCm : 0; }
      function composeText() {
        var order = ['H', 'L', 'P', 'Ø', ''];
        var parts = state.items.filter(function (it) { return it.cm > 0; }).sort(function (a, b) { return order.indexOf(KINDS[a.kind][0]) - order.indexOf(KINDS[b.kind][0]); })
          .map(function (it) { var k = KINDS[it.kind][0]; return (k ? k + ' ' : '') + nf(round(it.cm)); });
        return parts.length ? parts.join(' × ') + ' cm' : '';
      }

      function line(l, color, dash) {
        var g = document.createElementNS(NS, 'g');
        var ln = document.createElementNS(NS, 'line');
        ln.setAttribute('x1', l.a[0] * 100 + '%'); ln.setAttribute('y1', l.a[1] * 100 + '%'); ln.setAttribute('x2', l.b[0] * 100 + '%'); ln.setAttribute('y2', l.b[1] * 100 + '%');
        ln.setAttribute('stroke', color); ln.setAttribute('stroke-width', '3'); if (dash) ln.setAttribute('stroke-dasharray', '6 4');
        g.appendChild(ln);
        // Points A et B : on peut les saisir et les déplacer pour corriger le tracé.
        [[l.a, 'A'], [l.b, 'B']].forEach(function (q) {
          var c = document.createElementNS(NS, 'circle'); c.setAttribute('cx', q[0][0] * 100 + '%'); c.setAttribute('cy', q[0][1] * 100 + '%'); c.setAttribute('r', '11'); c.setAttribute('fill', color); c.setAttribute('fill-opacity', '.85'); c.setAttribute('stroke', '#fff'); c.setAttribute('stroke-width', '2'); g.appendChild(c);
          var t = document.createElementNS(NS, 'text'); t.setAttribute('x', q[0][0] * 100 + '%'); t.setAttribute('y', q[0][1] * 100 + '%'); t.setAttribute('dy', '4'); t.setAttribute('text-anchor', 'middle'); t.setAttribute('fill', '#fff'); t.setAttribute('font-size', '11'); t.setAttribute('font-weight', '700'); t.setAttribute('pointer-events', 'none'); t.textContent = q[1]; g.appendChild(t);
        });
        return g;
      }
      function tag(l, text, color, at) {
        var t = document.createElementNS(NS, 'text'), f = at == null ? 0.5 : at;
        t.setAttribute('x', (l.a[0] + (l.b[0] - l.a[0]) * f) * 100 + '%'); t.setAttribute('y', (l.a[1] + (l.b[1] - l.a[1]) * f) * 100 + '%'); t.setAttribute('dy', '-8');
        t.setAttribute('text-anchor', 'middle'); t.setAttribute('fill', '#fff'); t.setAttribute('stroke', color); t.setAttribute('stroke-width', '4'); t.setAttribute('paint-order', 'stroke'); t.setAttribute('font-size', '14'); t.setAttribute('font-weight', '700');
        t.textContent = text; return t;
      }

      function render() {
        // Boutons de dimension.
        Array.prototype.forEach.call(kinds.children, function (b, i) { b.setAttribute('aria-pressed', String(i === state.kind)); });
        kinds.hidden = !refLine && !state.items.some(function (it) { return !it.line; });
        step.textContent = mode === 'ref' ? '1. Tracez le repère sur la photo, d\'un bout à l\'autre (' + (REFS.filter(function (r) { return r.key === sel.value; })[0].label.split(' (')[0].toLowerCase()) + ').' : '2. Choisissez la dimension, puis tracez-la sur la pièce. Pour corriger : glissez le point A ou B, ou « Annuler le dernier tracé ».';
        // Tracés.
        while (svg.firstChild) svg.removeChild(svg.firstChild);
        var k = pxPerCm();
        if (refLine) { svg.appendChild(line(refLine, '#111', true)); svg.appendChild(tag(refLine, 'repère ' + nf(state.refCm) + ' cm', '#111')); }
        state.items.forEach(function (it) {
          if (!it.line) return;
          it.cm = k ? lenPx(it.line) / k : it.cm;
          svg.appendChild(line(it.line, KINDS[it.kind][2], false)); svg.appendChild(tag(it.line, KINDS[it.kind][1] + ' ' + nf(round(it.cm)) + ' cm', KINDS[it.kind][2], [0.25, 0.75, 0.5, 0.3, 0.7][it.kind]));
        });
        if (temp) svg.appendChild(line(temp, mode === 'ref' ? '#111' : KINDS[state.kind][2], mode === 'ref'));
        // Liste des dimensions (modifiables à la main).
        list.textContent = '';
        state.items.forEach(function (it, i) {
          var row = el('div', 'mt-row'); row.style.setProperty('--c', KINDS[it.kind][2]);
          row.appendChild(el('b', '', KINDS[it.kind][1]));
          var n = document.createElement('input'); n.type = 'number'; n.step = '0.5'; n.min = '0'; n.inputMode = 'decimal'; n.value = it.cm ? String(round(it.cm)) : '';
          n.addEventListener('input', function () { it.cm = parseFloat(n.value) || 0; it.line = null; if (!state.textEdited) { state.text = composeText(); outIn.value = state.text; } });
          row.appendChild(n); row.appendChild(el('span', '', 'cm'));
          if (it.line) {
            var rt = el('button', 'mt-redo', 'Retracer'); rt.type = 'button'; rt.title = 'Tracer de nouveau cette dimension';
            rt.addEventListener('click', function () { pushHistory(); state.items.splice(i, 1); state.kind = it.kind; sync(); });
            row.appendChild(rt);
          }
          var x = el('button', 'mt-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Retirer cette dimension'); x.addEventListener('click', function () { pushHistory(); state.items.splice(i, 1); sync(); });
          row.appendChild(x); list.appendChild(row);
        });
        redo.hidden = !refLine;
        undo.disabled = !hist.length;
        if (!state.textEdited) { state.text = composeText(); outIn.value = state.text; }
        ok.disabled = !outIn.value.trim();
      }
      function sync() { state.textEdited = false; render(); }
      outIn.addEventListener('input', function () { state.textEdited = true; ok.disabled = !outIn.value.trim(); });

      // Photos proposées.
      function drawSources() {
        srcRow.textContent = '';
        images.forEach(function (s, i) {
          var b = el('button', 'mt-thumb'); b.type = 'button'; b.setAttribute('aria-pressed', String(i === state.src)); b.title = s.label || 'Photo ' + (i + 1);
          var t = el('img'); t.alt = ''; t.src = srcUrl(s); b.appendChild(t);
          b.addEventListener('click', function () { if (i !== state.src && hasWork() && !confirm('Changer de photo efface les tracés. Continuer ?')) return; state.src = i; state.newBlob = s.isNew ? s.blob : null; load(); });
          srcRow.appendChild(b);
        });
        srcRow.appendChild(fileLab);
      }
      function hasWork() { return !!refLine || state.items.some(function (it) { return it.line; }); }
      function load() {
        refLine = null; temp = null; mode = 'ref'; state.items = state.items.filter(function (it) { return !it.line; }); hist.length = 0;
        var s = images[state.src];
        if (!s) { stage.hidden = true; step.textContent = 'Prenez d\'abord une photo de la pièce avec son repère.'; drawSources(); return; }
        stage.hidden = false; img.src = srcUrl(s); drawSources(); render();
      }
      fileIn.addEventListener('change', function () {
        var f = fileIn.files && fileIn.files[0]; fileIn.value = '';
        if (!f) return;
        shrink(f, 1600).then(function (b) {
          if (hasWork() && !confirm('Une nouvelle photo efface les tracés. Continuer ?')) return;
          images.push({ blob: b, label: 'Photo de mesure', isNew: true });
          state.src = images.length - 1; state.newBlob = b; load();
        });
      });

      // Choix du repère.
      sel.addEventListener('change', function () { var r = REFS.filter(function (x) { return x.key === sel.value; })[0]; cmIn.value = String(r.cm); state.refCm = r.cm; render(); });
      cmIn.addEventListener('input', function () { state.refCm = parseFloat(cmIn.value) || 0; if (!state.textEdited) render(); else render(); });
      // Historique : chaque tracé, déplacement de point ou suppression peut être annulé.
      var hist = [];
      function snapshot() { return JSON.stringify({ refLine: refLine, items: state.items, mode: mode, kind: state.kind }); }
      function pushHistory() { hist.push(snapshot()); if (hist.length > 40) hist.shift(); }
      function restore(str) { var o = JSON.parse(str); refLine = o.refLine; state.items = o.items; mode = o.mode; state.kind = o.kind; }
      undo.addEventListener('click', function () { if (!hist.length) return; restore(hist.pop()); sync(); });
      redo.addEventListener('click', function () { pushHistory(); refLine = null; mode = 'ref'; state.items = state.items.filter(function (it) { return !it.line; }); sync(); });

      // Tracé au doigt, avec loupe.
      function pt(e) { var r = stage.getBoundingClientRect(); return [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))]; }
      function showLoupe(p) {
        var r = stage.getBoundingClientRect(), S = 96;
        loupe.hidden = false;
        loupe.style.backgroundImage = 'url("' + img.src + '")';
        loupe.style.backgroundSize = r.width * LOUPE + 'px ' + r.height * LOUPE + 'px';
        loupe.style.backgroundPosition = (-(p[0] * r.width * LOUPE - S / 2)) + 'px ' + (-(p[1] * r.height * LOUPE - S / 2)) + 'px';
        // Au-dessus du doigt, ou en dessous près du bord haut.
        var top = p[1] * r.height - S - 28; if (top < 0) top = p[1] * r.height + 28;
        loupe.style.left = Math.min(r.width - S, Math.max(0, p[0] * r.width - S / 2)) + 'px'; loupe.style.top = top + 'px';
      }
      var drawing = false, dragging = null;
      /** Point A ou B d'un tracé existant sous le doigt (à 26 px près), pour le déplacer. */
      function hitPoint(e) {
        var r = stage.getBoundingClientRect(), best = null, bestD = 26;
        var lines = (refLine ? [refLine] : []).concat(state.items.filter(function (it) { return it.line; }).map(function (it) { return it.line; }));
        lines.forEach(function (l) {
          ['a', 'b'].forEach(function (end) {
            var d = Math.hypot(l[end][0] * r.width + r.left - e.clientX, l[end][1] * r.height + r.top - e.clientY);
            if (d < bestD) { bestD = d; best = { line: l, end: end }; }
          });
        });
        return best;
      }
      svg.addEventListener('pointerdown', function (e) {
        if (!img.naturalWidth) return;
        svg.setPointerCapture(e.pointerId);
        var hit = hitPoint(e), p = pt(e);
        if (hit) { pushHistory(); dragging = hit; hit.line[hit.end] = p; showLoupe(p); render(); return; }
        drawing = true; temp = { a: p, b: p }; showLoupe(p); render();
      });
      svg.addEventListener('pointermove', function (e) {
        var p = pt(e);
        if (dragging) { dragging.line[dragging.end] = p; showLoupe(p); render(); return; }
        if (!drawing) return; temp.b = p; showLoupe(p); render();
      });
      function end() {
        if (dragging) { dragging = null; loupe.hidden = true; sync(); return; }
        if (!drawing) return; drawing = false; loupe.hidden = true;
        var l = temp; temp = null;
        if (l && lenPx(l) > 12) {
          pushHistory();
          if (mode === 'ref') { refLine = l; mode = 'measure'; }
          else {
            // Une seule ligne par dimension (sauf « Autre ») : la nouvelle remplace la précédente.
            if (state.kind !== 4) state.items = state.items.filter(function (it) { return it.kind !== state.kind; });
            state.items.push({ kind: state.kind, line: l, cm: 0 });
            // Dimension suivante proposée : H → L → P.
            if (state.kind < 2) state.kind++;
          }
        }
        sync();
      }
      svg.addEventListener('pointerup', end); svg.addEventListener('pointercancel', end);

      // Estimation par l'IA, à partir du repère choisi.
      ai.addEventListener('click', function () {
        var s = images[state.src]; if (!s) { alert('Prenez ou choisissez d\'abord une photo.'); return; }
        ai.disabled = true; aiNote.hidden = false; aiNote.textContent = 'L\'IA estime les dimensions… (10 à 20 secondes)';
        var refLabel = REFS.filter(function (r) { return r.key === sel.value; })[0].label;
        fetch(s.url || srcUrl(s)).then(function (r) { return r.blob(); }).then(function (blob) { return shrink(blob, 1280); }).then(function (b) {
          var fd = new FormData(); fd.append('action', 'measure'); fd.append('photo', b, 'photo.jpg'); fd.append('ref', refLabel.replace(/\(.*\)/, '').trim()); fd.append('ref_cm', String(state.refCm));
          return fetch(ACTION, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
        }).then(function (j) {
          ai.disabled = false;
          if (!j || !j.ok) { aiNote.textContent = (j && j.error) || 'Estimation impossible.'; return; }
          var d = j.dims || {}, added = 0;
          [['H', 'hauteur'], ['L', 'largeur'], ['P', 'profondeur']].forEach(function (k, i) {
            if (d[k[1]] > 0) { state.items = state.items.filter(function (it) { return it.kind !== i; }); state.items.push({ kind: i, line: null, cm: d[k[1]] }); added++; }
          });
          aiNote.textContent = added ? 'Estimation de l\'IA (' + (j.reliability || 'fiabilité inconnue') + ')' + (j.note ? ' : ' + j.note : '') + '. À vérifier : tracez le repère et les dimensions pour une mesure plus juste.' : 'L\'IA n\'a pas pu estimer ces dimensions.';
          sync();
        }).catch(function () { ai.disabled = false; aiNote.textContent = 'Estimation impossible : vérifiez la connexion.'; });
      });

      var done = false;
      function finish(result) {
        if (done) return; done = true;
        urls.forEach(function (u) { URL.revokeObjectURL(u); });
        try { dlg.close(); } catch (e) {}
        dlg.remove(); resolve(result);
      }
      ok.addEventListener('click', function () {
        var text = outIn.value.trim(); if (!text) return;
        finish({ text: text, dims: state.items.filter(function (it) { return it.cm > 0; }).map(function (it) { return { kind: KINDS[it.kind][1], cm: round(it.cm) }; }), newBlob: state.newBlob });
      });
      cancel.addEventListener('click', function () { finish(null); });
      closeX.addEventListener('click', function () { finish(null); });
      // Un clic en dehors de la fenêtre la ferme aussi.
      dlg.addEventListener('click', function (e) {
        if (e.target !== dlg) return;
        var r = dlg.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) finish(null);
      });
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); finish(null); });
      dlg.showModal();
      state.refCm = REFS[0].cm;
      if (images.length) state.src = 0;
      // Photo de départ : la première image marquée « isNew », sinon la première.
      load();
    });
  };
})();
