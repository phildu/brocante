// Boutons « ↻ IA » des formulaires de fiche produit (catalogue : ajout et édition ; relecture du Studio et
// de l'ajout rapide) :
// (re)génèrent le nom, la description, la catégorie, les matières ou le prix, ou tout d'un coup.
// Les valeurs proposées sont écrites dans le formulaire, sans rien enregistrer : on relit, on
// corrige, puis on clique sur « Enregistrer » (ou « Ajouter »). Serveur : admin/product-ai.php.
(function () {
  'use strict';
  var URL = (window.APP_BASE || '') + '/admin/product-ai.php';
  var ALL = 'name,description,category,nature,materials,etat,size,weight,price';
  var LABEL = { name: 'nom', description: 'description', category: 'catégorie', materials: 'matières', etat: 'état', nature: 'nature', sous_categorie: 'sous-catégorie', size: 'taille', size_text: 'taille', weight: 'poids', weight_grams: 'poids', weight_text: 'poids', price: 'prix' };
  var INPUT = { name: 'name', description: 'description', category: 'cat', materials: 'materials', etat: 'etat', nature: 'nature', sous_categorie: 'sous_categorie', size_text: 'size_text', weight_grams: 'weight_grams', weight_text: 'weight_text', price: 'price' };

  // Photo choisie dans le formulaire d'ajout, réduite à 1280 px avant l'envoi (limite d'envoi de l'hébergement).
  function shrink(file) {
    return new Promise(function (resolve) {
      var url = window.URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, 1280 / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        window.URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { window.URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  // Champ d'un bloc [data-ai-form] : par son name (formulaires du catalogue) ou son data-ai-input (relectures).
  function control(form, field) { return form.querySelector('[name="' + INPUT[field] + '"]') || form.querySelector('[data-ai-input="' + INPUT[field] + '"]'); }

  function setStatus(form, text, kind) {
    var el = form.querySelector('[data-ai-status]');
    if (!el) return;
    el.textContent = text;
    el.dataset.kind = kind || '';
  }

  function busy(form, on, clicked) {
    form.querySelectorAll('[data-ai-field]').forEach(function (b) {
      b.disabled = on;
      if (b === clicked) {
        if (on) { b.dataset.label = b.textContent; b.textContent = '…'; }
        else if (b.dataset.label) b.textContent = b.dataset.label;
      }
    });
  }

  function run(btn) {
    var form = btn.closest('[data-ai-form]');
    if (!form) return;
    var fields = btn.dataset.aiField === 'all' ? ALL : btn.dataset.aiField;
    var fd = new FormData();
    fd.append('fields', fields);
    // Pièce existante : son name="ref" (catalogue) ou data-ai-ref (relectures, renseigné par la page).
    var refInput = form.querySelector('[name="ref"]');
    var ref = (refInput && refInput.value) || form.dataset.aiRef || '';
    if (ref) fd.append('ref', ref);
    ['name', 'description', 'cat', 'materials', 'etat', 'nature', 'sous_categorie', 'price', 'size_text', 'weight_text', 'weight_grams'].forEach(function (n) {
      var c = form.querySelector('[name="' + n + '"]') || form.querySelector('[data-ai-input="' + n + '"]');
      if (c && c.value) fd.append(n, c.value);
    });
    var file = form.querySelector('input[type="file"][name="photo"]');
    var photo = !ref && file && file.files[0] ? shrink(file.files[0]) : Promise.resolve(null);

    busy(form, true, btn);
    setStatus(form, 'L\'IA réfléchit… (10 à 30 secondes)', 'run');
    photo.then(function (blob) {
      if (blob) fd.append('photo', blob, 'photo.jpg');
      return fetch(URL, { method: 'POST', body: fd, credentials: 'same-origin' });
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').' }; });
    }).catch(function () {
      return { ok: false, error: 'Connexion perdue : réessayez.' };
    }).then(function (res) {
      busy(form, false, btn);
      if (!res.ok) { setStatus(form, res.error || 'Génération impossible.', 'error'); return; }
      var done = [];
      Object.keys(res.values).forEach(function (field) {
        var c = control(form, field);
        if (!c) return;
        c.value = res.values[field];
        // La nature filtre les sous-catégories proposées (assets/nature-select.js) : on l'annonce avant de poser la sous-catégorie.
        c.dispatchEvent(new Event('change', { bubbles: true }));
        c.classList.remove('ai-filled'); void c.offsetWidth; c.classList.add('ai-filled');
        done.push(LABEL[field]);
      });
      var shown = done.filter(function (l, i) { return done.indexOf(l) === i; });
      var notes = [];
      if (res.values.weight_grams) notes.push('le poids est une estimation : pesez la pièce si possible (il sert au calcul des frais de port)');
      if (fields.indexOf('category') > -1 && !res.values.category) notes.push('aucun univers de la boutique ne convient à cette pièce : adaptez-les dans « Univers »');
      var src = res.sources ? Object.keys(res.sources).map(function (k) { return res.sources[k]; }) : [];
      setStatus(form, done.length ? 'Proposé : ' + shown.join(', ') + '. Relisez, puis enregistrez.' + (src.length ? ' Source : ' + src.join(' ; ') + '.' : '') + (notes.length ? ' À noter : ' + notes.join(' ; ') + '.' : '') : 'L\'IA n\'a rien proposé.', done.length ? 'ok' : 'error');
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ai-field]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    run(btn);
  });
})();
