// Isoler un objet dans une photo d'« ensemble » (plusieurs pièces posées ensemble) : l'IA repère les objets (cadres cliquables),
// ou on dessine soi-même un cadre ; la photo est recadrée sur l'objet choisi, sur le téléphone, avant l'envoi.
// Serveur : admin/quick-add-action.php (action « objects »). Utilisé par le Studio (quick-add.js, studio.js).
//   studioDetectObjects(blob)            → Promise<[{label, box:[x, y, largeur, hauteur]}]>  (fractions de l'image ; [] si un seul objet)
//   studioPickObject(blob, {objects})    → Promise<Blob | null>  (la photo recadrée, ou null si on garde toute la photo / annule)
(function () {
  'use strict';
  var ACTION = (document.querySelector('.qa') && document.querySelector('.qa').dataset.action) || ((window.APP_BASE || '') + '/admin/quick-add-action.php');

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  /** Version réduite (1024 px) pour l'analyse : rapide en 4G. */
  function small(blob) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(blob), img = new Image();
      img.onload = function () {
        var k = Math.min(1, 1024 / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || blob); }, 'image/jpeg', 0.8);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(blob); };
      img.src = url;
    });
  }

  function detect(blob) {
    return small(blob).then(function (b) {
      var fd = new FormData(); fd.append('action', 'objects'); fd.append('photo', b, 'photo.jpg');
      return fetch(ACTION, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { return j && j.ok ? { objects: j.objects || [], ai: j.ai !== false } : { objects: [], ai: false, error: (j && j.error) || '' }; });
    }).catch(function () { return { objects: [], ai: false, error: 'network' }; });
  }
  window.studioDetectObjects = function (blob) { return detect(blob).then(function (r) { return r.objects; }); };

  /** Recadre l'image sur la zone choisie (fractions), avec une petite marge, à 1600 px au plus. */
  function crop(blob, box) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(blob), img = new Image();
      img.onload = function () {
        var W = img.naturalWidth, H = img.naturalHeight, m = 0.05;
        var x0 = Math.max(0, box[0] - box[2] * m), y0 = Math.max(0, box[1] - box[3] * m);
        var x1 = Math.min(1, box[0] + box[2] * (1 + m)), y1 = Math.min(1, box[1] + box[3] * (1 + m));
        var sx = Math.round(x0 * W), sy = Math.round(y0 * H), sw = Math.max(1, Math.round((x1 - x0) * W)), sh = Math.max(1, Math.round((y1 - y0) * H));
        var k = Math.min(1, 1600 / Math.max(sw, sh));
        var c = document.createElement('canvas'); c.width = Math.max(1, Math.round(sw * k)); c.height = Math.max(1, Math.round(sh * k));
        c.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || blob); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(blob); };
      img.src = url;
    });
  }

  window.studioPickObject = function (blob, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(blob);
      var dlg = el('dialog', 'op'); dlg.setAttribute('aria-label', 'Choisir un objet dans la photo');
      var title = el('p', 'op-title', 'Quel objet vendez-vous ?');
      var note = el('p', 'op-note', 'Recherche des objets de la photo…');
      var stage = el('div', 'op-stage'); var img = el('img'); img.src = url; img.alt = ''; img.draggable = false; stage.appendChild(img);
      var layer = el('div', 'op-layer'); stage.appendChild(layer);
      var rect = el('div', 'op-rect'); rect.hidden = true; layer.appendChild(rect);
      var bar = el('div', 'op-bar');
      var use = el('button', 'btn btn-primary', 'Utiliser cette zone'); use.type = 'button'; use.hidden = true;
      var all = el('button', 'btn btn-ghost', 'Garder toute la photo'); all.type = 'button';
      var cancel = el('button', 'btn btn-ghost', 'Annuler'); cancel.type = 'button';
      bar.appendChild(use); bar.appendChild(all); bar.appendChild(cancel);
      dlg.appendChild(title); dlg.appendChild(note); dlg.appendChild(stage); dlg.appendChild(bar);
      document.body.appendChild(dlg);
      var chosen = null, done = false;

      function finish(result) {
        if (done) return; done = true;
        URL.revokeObjectURL(url);
        try { dlg.close(); } catch (e) {}
        dlg.remove(); resolve(result);
      }
      function select(box) {
        chosen = box;
        rect.hidden = false;
        rect.style.left = box[0] * 100 + '%'; rect.style.top = box[1] * 100 + '%'; rect.style.width = box[2] * 100 + '%'; rect.style.height = box[3] * 100 + '%';
        use.hidden = false;
      }
      function drawBoxes(objects) {
        objects.forEach(function (o) {
          var b = el('button', 'op-box'); b.type = 'button'; b.setAttribute('aria-label', o.label);
          b.style.left = o.box[0] * 100 + '%'; b.style.top = o.box[1] * 100 + '%'; b.style.width = o.box[2] * 100 + '%'; b.style.height = o.box[3] * 100 + '%';
          b.appendChild(el('span', '', o.label));
          // Toucher un cadre le choisit tout de suite.
          b.addEventListener('click', function (e) { e.stopPropagation(); crop(blob, o.box).then(finish); });
          layer.appendChild(b);
        });
      }

      // Cadre dessiné au doigt, quand l'IA n'a pas trouvé le bon objet.
      var start = null;
      function pt(e) { var r = stage.getBoundingClientRect(); return [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))]; }
      layer.addEventListener('pointerdown', function (e) { if (e.target.closest('.op-box')) return; start = pt(e); layer.setPointerCapture(e.pointerId); select([start[0], start[1], 0, 0]); use.hidden = true; });
      layer.addEventListener('pointermove', function (e) {
        if (!start) return; var p = pt(e);
        select([Math.min(start[0], p[0]), Math.min(start[1], p[1]), Math.abs(p[0] - start[0]), Math.abs(p[1] - start[1])]); use.hidden = true;
      });
      layer.addEventListener('pointerup', function () { start = null; if (!chosen || chosen[2] < 0.05 || chosen[3] < 0.05) { rect.hidden = true; chosen = null; use.hidden = true; } else use.hidden = false; });

      use.addEventListener('click', function () { if (chosen) crop(blob, chosen).then(finish); });
      all.addEventListener('click', function () { finish(null); });
      cancel.addEventListener('click', function () { finish(null); });
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); finish(null); });
      dlg.showModal();

      var ready = opts.objects ? Promise.resolve({ objects: opts.objects, ai: true }) : detect(blob);
      ready.then(function (r) {
        if (done) return;
        if (r.objects.length) {
          note.textContent = 'Touchez l\'objet à vendre, ou dessinez un cadre autour de lui.';
          drawBoxes(r.objects);
        } else {
          note.textContent = r.ai ? 'Un seul objet repéré : dessinez un cadre autour de la partie à garder, ou gardez toute la photo.' : 'Dessinez un cadre autour de l\'objet à vendre (la recherche automatique n\'est pas disponible).';
        }
      });
    });
  };
})();
