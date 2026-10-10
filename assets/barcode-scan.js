// Scanner un code-barres (ISBN, EAN, UPC) ou un code QR pour remplir la fiche d'un article (livre, CD, vinyle, DVD…).
//
// Barre « Scanner » ajoutée en tête de tout formulaire marqué [data-scan-form] (ajout d'une pièce, relecture du Studio) ; sur les fiches existantes,
// un bouton « Fiche » à côté du champ « Code-barres » cherche le code saisi. La caméra lit le code (BarcodeDetector du navigateur, sinon la
// bibliothèque html5-qrcode chargée à la demande) ; à défaut de caméra (page non sécurisée, ordinateur sans webcam), on peut photographier le
// code ou le saisir. La recherche se fait côté serveur (admin/barcode-lookup.php) : voir includes/barcode.php pour les sources.
(function () {
  'use strict';
  var ENDPOINT = (window.APP_BASE || '') + '/admin/barcode-lookup.php';
  var LIB = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
  var FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'];
  var IDS = { name: 'f-name', description: 'f-desc', materials: 'f-materials', size_text: 'f-size', weight_grams: 'f-weight', nature: 'f-nature', sous_categorie: 'f-sous', cat: 'f-cat', barcode: 'f-barcode' };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function field(root, name) {
    return root.querySelector('[name="' + name + '"]') || root.querySelector('[data-ai-input="' + name + '"]') || (IDS[name] ? root.querySelector('#' + IDS[name]) : null);
  }
  function setValue(node, value) {
    if (!node) return;
    node.value = value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      if (document.querySelector('script[data-lib="' + src + '"]')) return resolve();
      var s = document.createElement('script'); s.src = src; s.dataset.lib = src; s.onload = resolve; s.onerror = function () { reject(new Error('lib')); };
      document.head.appendChild(s);
    });
  }

  // ── Recherche ──────────────────────────────────────────────────────────────
  function lookup(code) {
    var fd = new FormData(); fd.append('code', code);
    return fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  // ── Application à la fiche ─────────────────────────────────────────────────
  function guessCategory(root, res) {
    var sel = field(root, 'cat'); if (!sel || sel.tagName !== 'SELECT') return null;
    var re = res.kind === 'livre' ? /livre|biblioth|librairie|bouquin|lecture|culture|média|media/i : /disque|musique|vinyl|son|culture|média|media/i;
    var found = Array.prototype.filter.call(sel.options, function (o) { return re.test(o.textContent) || re.test(o.value); })[0];
    return found ? found.value : null;
  }
  function apply(root, res, overwrite) {
    var changed = [];
    function put(name, value, label) {
      var f = field(root, name); if (!f || value === undefined || value === null || value === '' || value === 0) return;
      if (!overwrite && String(f.value || '').trim() !== '' && !(name === 'weight_grams' && Number(f.value) === 0)) return;
      setValue(f, value); changed.push(label);
    }
    put('name', res.name, 'nom'); put('description', res.description, 'description'); put('materials', res.materials, 'matières'); put('size_text', res.size_text, 'taille');
    put('weight_grams', res.weight_grams, 'poids');
    if (res.nature) { put('nature', res.nature, 'nature'); var s = field(root, 'sous_categorie'); if (s && res.sous_categorie && (overwrite || !s.value)) { setValue(s, res.sous_categorie); } }
    var cat = guessCategory(root, res); if (cat) { var cf = field(root, 'cat'); if (cf && (overwrite || changed.length)) setValue(cf, cat); }
    var b = field(root, 'barcode'); if (b && res.code) setValue(b, res.code);
    return changed;
  }

  // ── Affichage du résultat ──────────────────────────────────────────────────
  function showResult(root, box, res) {
    box.innerHTML = '';
    if (!res.ok) {
      box.appendChild(el('p', 'scan-msg is-warn', res.error || 'Recherche impossible.'));
      var b = field(root, 'barcode'); if (b && res.code) { setValue(b, res.code); }
      return;
    }
    var hasPhotoInput = root.querySelector('input[type="file"][name="photo"]');
    var card = el('div', 'scan-card');
    if (res.cover) { var img = el('img', 'scan-cover'); img.alt = ''; img.src = res.cover; img.referrerPolicy = 'no-referrer'; img.onerror = function () { img.remove(); }; card.appendChild(img); }
    var body = el('div', 'scan-body');
    var head = el('p', 'scan-title'); head.appendChild(el('span', 'scan-kind', res.label || 'Article')); head.appendChild(document.createTextNode(' ' + res.name)); body.appendChild(head);
    var facts = el('p', 'scan-facts'); facts.textContent = Object.keys(res.facts || {}).filter(function (k) { return k !== 'Titre'; }).map(function (k) { return k + ' : ' + res.facts[k]; }).join(' · ');
    if (facts.textContent) body.appendChild(facts);
    body.appendChild(el('p', 'scan-src', 'Source : ' + (res.sources || []).join(', ') + (res.code ? ' · code ' + res.code : '')));
    if (res.existing) {
      var w = el('p', 'scan-msg is-warn'); w.appendChild(document.createTextNode('Déjà au catalogue : '));
      var a = el('a', '', res.existing.name + ' (réf. ' + res.existing.ref + ', stock ' + res.existing.stock + ')'); a.href = (window.APP_BASE || '') + '/admin/catalog.php#produit-' + encodeURIComponent(res.existing.ref); a.target = '_blank'; w.appendChild(a);
      body.appendChild(w);
    }
    var done = apply(root, res, false);
    if (res.weight_grams && res.weight_estimated && done.indexOf('poids') >= 0) body.appendChild(el('p', 'scan-msg', 'Poids estimé d\'après le support : à vérifier avec une balance.'));
    body.appendChild(el('p', 'scan-msg is-ok', done.length ? 'Fiche pré-remplie (' + done.join(', ') + '). Relisez, ajoutez prix et état.' : 'Les champs déjà remplis ont été gardés.'));
    var actions = el('p', 'scan-actions');
    var all = el('button', 'btn-small', 'Remplacer tout par ces informations'); all.type = 'button';
    all.addEventListener('click', function () { apply(root, res, true); all.textContent = 'Remplacé ✓'; });
    actions.appendChild(all);
    body.appendChild(actions);
    if (hasPhotoInput && res.cover) {
      var h = field(root, 'cover_url'); if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'cover_url'; root.appendChild(h); }
      h.value = res.cover;
      var lab = el('label', 'scan-cover-opt'); var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'use_cover'; cb.value = '1'; cb.checked = true;
      lab.appendChild(cb); lab.appendChild(document.createTextNode(' Utiliser cette image comme photo de la pièce (si vous n\'envoyez pas de photo)')); body.appendChild(lab);
    }
    card.appendChild(body);
    box.appendChild(card);
  }

  // ── Caméra ─────────────────────────────────────────────────────────────────
  function openCamera(onCode, onError) {
    var overlay = el('div', 'scan-overlay'); overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-label', 'Scanner un code');
    var panel = el('div', 'scan-panel');
    var stage = el('div', 'scan-stage'); var video = document.createElement('video'); video.setAttribute('playsinline', ''); video.muted = true; stage.appendChild(video);
    var frame = el('div', 'scan-frame'); stage.appendChild(frame);
    var reader = el('div', 'scan-reader'); reader.id = 'scan-reader-' + Date.now(); stage.appendChild(reader);
    var hint = el('p', 'scan-help', 'Placez le code-barres ou le code QR dans le cadre.');
    var close = el('button', 'btn-small', 'Annuler'); close.type = 'button';
    panel.appendChild(stage); panel.appendChild(hint); panel.appendChild(close); overlay.appendChild(panel); document.body.appendChild(overlay);
    var stream = null, timer = null, html5 = null, finished = false;
    function stop() {
      finished = true; clearInterval(timer);
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      if (html5) { try { html5.stop().then(function () { html5.clear(); }).catch(function () {}); } catch (e) {} }
      overlay.remove();
    }
    function found(code) { if (finished) return; if (navigator.vibrate) navigator.vibrate(60); stop(); onCode(code); }
    close.addEventListener('click', stop);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) stop(); });

    var useNative = 'BarcodeDetector' in window;
    function native() {
      return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }, audio: false }).then(function (s) {
        if (finished) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
        stream = s; video.srcObject = s; reader.hidden = true; return video.play().then(function () {
          var det = new window.BarcodeDetector({ formats: FORMATS }), busy = false;
          timer = setInterval(function () {
            if (busy || video.readyState < 2) return; busy = true;
            det.detect(video).then(function (codes) { if (codes && codes.length) found(codes[0].rawValue); }).catch(function () {}).then(function () { busy = false; });
          }, 220);
        });
      });
    }
    function library() {
      video.hidden = true; frame.hidden = true;
      return loadScript(LIB).then(function () {
        var H = window.Html5Qrcode, F = window.Html5QrcodeSupportedFormats;
        html5 = new H(reader.id, { formatsToSupport: [F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_128, F.CODE_39, F.QR_CODE], verbose: false });
        return html5.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 280, height: 160 } }, found, function () {});
      });
    }
    (useNative ? native() : library()).catch(function (err) {
      if (useNative) return library().catch(function () { stop(); onError(err); });
      stop(); onError(err);
    });
  }

  /** Lit un code sur une photo (appareil photo du téléphone, fichier). */
  function decodeFile(file) {
    if ('BarcodeDetector' in window) {
      return createImageBitmap(file).then(function (bmp) { return new window.BarcodeDetector({ formats: FORMATS }).detect(bmp); }).then(function (codes) {
        if (codes && codes.length) return codes[0].rawValue;
        throw new Error('none');
      });
    }
    return loadScript(LIB).then(function () {
      var holder = el('div'); holder.id = 'scan-file-' + Date.now(); holder.style.display = 'none'; document.body.appendChild(holder);
      var h = new window.Html5Qrcode(holder.id);
      return h.scanFile(file, false).then(function (t) { holder.remove(); return t; }, function (e) { holder.remove(); throw e; });
    });
  }

  // ── Barre de scan ──────────────────────────────────────────────────────────
  function buildBar(root) {
    var bar = el('div', 'scan-bar');
    var row = el('div', 'scan-row');
    var cam = el('button', 'btn-small scan-btn', '📷 Scanner un code-barres / QR'); cam.type = 'button';
    var photoLab = el('label', 'btn-small scan-photo', '🖼 Photo du code'); var photo = document.createElement('input'); photo.type = 'file'; photo.accept = 'image/*'; photo.setAttribute('capture', 'environment'); photo.hidden = true; photoLab.appendChild(photo);
    var code = document.createElement('input'); code.type = 'text'; code.className = 'scan-code'; code.placeholder = 'ou saisir un ISBN, un code-barres, une adresse…'; code.setAttribute('aria-label', 'Code-barres, ISBN ou adresse');
    var go = el('button', 'btn-small', 'Chercher'); go.type = 'button';
    row.appendChild(cam); row.appendChild(photoLab); row.appendChild(code); row.appendChild(go);
    var hint = el('p', 'scan-hint', 'Livres (ISBN), CD, vinyles, DVD : titre, auteur ou artiste, éditeur, pistes et pochette sont récupérés. Un code QR peut être une page Discogs ou une fiche web.');
    var box = el('div', 'scan-result'); box.setAttribute('role', 'status');
    bar.appendChild(row); bar.appendChild(hint); bar.appendChild(box);
    var busy = false;
    function run(text) {
      text = (text || '').trim(); if (!text || busy) return; busy = true; code.value = text;
      box.innerHTML = ''; box.appendChild(el('p', 'scan-msg', 'Recherche en cours…'));
      lookup(text).then(function (res) { showResult(root, box, res); }).catch(function () { box.innerHTML = ''; box.appendChild(el('p', 'scan-msg is-warn', 'La recherche a échoué : vérifiez la connexion et réessayez.')); }).then(function () { busy = false; });
    }
    cam.addEventListener('click', function () {
      if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        box.innerHTML = ''; box.appendChild(el('p', 'scan-msg is-warn', 'La caméra exige une connexion sécurisée (https) : ouvrez le site en ligne sur votre téléphone, ou utilisez « Photo du code » ou la saisie.'));
        return;
      }
      openCamera(run, function () { box.innerHTML = ''; box.appendChild(el('p', 'scan-msg is-warn', 'Caméra inaccessible (autorisation refusée ?). Utilisez « Photo du code » ou la saisie.')); });
    });
    photo.addEventListener('change', function () {
      var f = photo.files && photo.files[0]; if (!f) return;
      box.innerHTML = ''; box.appendChild(el('p', 'scan-msg', 'Lecture de la photo…'));
      decodeFile(f).then(run, function () { box.innerHTML = ''; box.appendChild(el('p', 'scan-msg is-warn', 'Aucun code lisible sur cette photo : rapprochez-vous, évitez les reflets, ou saisissez le code.')); });
      photo.value = '';
    });
    go.addEventListener('click', function () { run(code.value); });
    code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(code.value); } });
    return bar;
  }

  function start() {
    document.querySelectorAll('[data-scan-form]').forEach(function (root) {
      if (root.dataset.scanReady) return; root.dataset.scanReady = '1';
      root.insertBefore(buildBar(root), root.firstChild);
    });
    // Fiches existantes : bouton « Fiche » à côté du champ code-barres (complète seulement les champs vides).
    document.querySelectorAll('input[data-barcode-field]').forEach(function (input) {
      if (input.dataset.scanReady) return; input.dataset.scanReady = '1';
      var root = input.closest('[data-ai-form]') || input.form; if (!root) return;
      var btn = el('button', 'btn-small scan-lookup', 'Fiche'); btn.type = 'button'; btn.title = 'Chercher les informations de ce code (complète les champs vides)';
      var msg = el('span', 'scan-inline'); msg.setAttribute('role', 'status');
      input.parentNode.appendChild(btn); input.parentNode.appendChild(msg);
      btn.addEventListener('click', function () {
        var v = input.value.trim(); if (!v) { msg.textContent = 'Saisissez un code.'; return; }
        msg.textContent = 'Recherche…';
        lookup(v).then(function (res) {
          if (!res.ok) { msg.textContent = res.error; return; }
          var done = apply(root, res, false); msg.textContent = done.length ? 'Complété : ' + done.join(', ') + '.' : 'Rien à compléter (champs déjà remplis).';
        }).catch(function () { msg.textContent = 'Recherche impossible.'; });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.barcodeScanInit = start;
})();
