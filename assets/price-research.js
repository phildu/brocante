// « Prix du marché » : le même article, neuf et d'occasion, cherché sur le web par l'IA et croisé avec les pièces similaires du
// catalogue (admin/price-research.php). Le résultat s'affiche sous le champ Prix : fourchettes, prix conseillé, sources ; un clic
// sur « Utiliser » l'écrit dans le champ, sans rien enregistrer. S'active sur les boutons [data-price-research] d'un bloc [data-ai-form].
(function () {
  'use strict';
  var URL_PR = (window.APP_BASE || '') + '/admin/price-research.php';

  function shrink(file) {
    return new Promise(function (resolve) {
      var url = window.URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var scale = Math.min(1, 1280 / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * scale); c.height = Math.round(img.naturalHeight * scale);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        window.URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { window.URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function field(root, name) { return root.querySelector('[name="' + name + '"]') || root.querySelector('[data-ai-input="' + name + '"]'); }
  function eur(n) { return (Math.round(n * 100) / 100).toString().replace('.', ',') + ' €'; }
  function range(r) { return r ? (r.min === r.max ? eur(r.min) : eur(r.min) + ' à ' + eur(r.max)) : null; }

  function render(btn, root, res) {
    var old = root.querySelector('.pr-panel'); if (old) old.remove();
    var panel = el('div', 'pr-panel');
    var close = el('button', 'pr-close', 'Fermer'); close.type = 'button'; close.addEventListener('click', function () { panel.remove(); });
    var head = el('div', 'pr-head'); head.appendChild(el('strong', '', 'Prix du marché')); head.appendChild(close);
    panel.appendChild(head);

    if (res.web && res.web.identification) panel.appendChild(el('p', 'pr-ident', 'Article reconnu : ' + res.web.identification));

    var table = el('table', 'pr-table');
    var rows = [];
    var cat = res.catalogue || {};
    rows.push(['Votre catalogue' + (cat.n ? ' (' + cat.n + ' pièce' + (cat.n > 1 ? 's' : '') + ' similaire' + (cat.n > 1 ? 's' : '') + ')' : ''),
      cat.n ? range({ min: cat.min, max: cat.max }) : 'aucune pièce similaire', cat.n ? eur(cat.median) : '—']);
    if (res.web) {
      rows.push(['Neuf (web)', range(res.web['new']) || 'aucun prix trouvé', res.web['new'] ? eur(res.web['new'].median) : '—']);
      rows.push(['Occasion (web)', range(res.web.used) || 'aucun prix trouvé', res.web.used ? eur(res.web.used.median) : '—']);
    }
    var thead = el('tr'); ['', 'Fourchette', 'Médiane'].forEach(function (h) { thead.appendChild(el('th', '', h)); }); table.appendChild(thead);
    rows.forEach(function (r) { var tr = el('tr'); r.forEach(function (c, i) { tr.appendChild(el(i ? 'td' : 'th', '', c)); }); table.appendChild(tr); });
    panel.appendChild(table);

    if (res.suggested) {
      var adv = el('p', 'pr-advice');
      adv.appendChild(el('strong', '', 'Prix conseillé : ' + res.suggested));
      var why = res.web && res.web.advice_text ? res.web.advice_text : (res.suggested_from === 'catalogue' ? 'médiane des pièces similaires de votre catalogue.' : '');
      if (why) adv.appendChild(document.createTextNode(' — ' + why));
      var use = el('button', 'btn-small pr-use', 'Utiliser ' + res.suggested); use.type = 'button';
      use.addEventListener('click', function () {
        var price = field(root, 'price'); if (!price) return;
        price.value = res.suggested; price.dispatchEvent(new Event('input', { bubbles: true }));
        price.classList.remove('ai-filled'); void price.offsetWidth; price.classList.add('ai-filled');
        use.textContent = 'Prix appliqué : enregistrez la fiche'; use.disabled = true;
      });
      panel.appendChild(adv); panel.appendChild(use);
    }
    if (res.web) panel.appendChild(el('p', 'pr-reliab', 'Fiabilité de la recherche : ' + res.web.reliability + (res.web.reliability === 'faible' ? ' — peu de prix comparables trouvés, fiez-vous à votre propre jugement.' : '.')));

    if ((res.sources && res.sources.length) || (res.queries && res.queries.length)) {
      var det = el('details', 'pr-sources'); det.appendChild(el('summary', '', 'Sources et recherches effectuées'));
      if (res.sources && res.sources.length) {
        var ul = el('ul'); res.sources.forEach(function (s) {
          var li = el('li'), a = el('a', '', s.title || s.uri); a.href = s.uri; a.target = '_blank'; a.rel = 'noopener noreferrer'; li.appendChild(a); ul.appendChild(li);
        }); det.appendChild(ul);
      }
      if (res.queries && res.queries.length) det.appendChild(el('p', 'pr-q', 'Recherches : ' + res.queries.join(' · ')));
      panel.appendChild(det);
    }
    panel.appendChild(el('p', 'pr-note', "Estimation issue de recherches web faites par l'IA, à vérifier : l'état exact de votre pièce, sa rareté et son prix d'achat comptent plus que ces repères."));

    // sous la ligne du champ Prix
    var anchor = field(root, 'price');
    var holder = anchor && (anchor.closest('.field-row-3') || anchor.closest('label') || anchor.parentNode);
    holder.parentNode.insertBefore(panel, holder.nextSibling);
    panel.scrollIntoView({ block: 'nearest' });
  }

  function run(btn) {
    var root = btn.closest('[data-ai-form]'); if (!root) return;
    var fd = new FormData();
    var refInput = root.querySelector('[name="ref"]');
    var ref = (refInput && refInput.value) || root.dataset.aiRef || '';
    if (ref) fd.append('ref', ref);
    ['name', 'description', 'cat', 'materials', 'etat', 'nature', 'sous_categorie', 'size_text'].forEach(function (n) {
      var c = field(root, n); if (c && c.value) fd.append(n, c.value);
    });
    var file = root.querySelector('input[type="file"][name="photo"]');
    var photo = !ref && file && file.files[0] ? shrink(file.files[0]) : Promise.resolve(null);
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Recherche…';
    var status = root.querySelector('[data-ai-status]');
    if (status) { status.textContent = 'Recherche du prix sur le web… (20 à 40 secondes)'; status.dataset.kind = 'run'; }
    photo.then(function (blob) {
      if (blob) fd.append('photo', blob, 'photo.jpg');
      return fetch(URL_PR, { method: 'POST', body: fd, credentials: 'same-origin' });
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : réessayez.' }; })
      .then(function (res) {
        btn.disabled = false; btn.textContent = label;
        if (!res.ok) { if (status) { status.textContent = res.error || 'Recherche impossible.'; status.dataset.kind = 'error'; } return; }
        if (status) { status.textContent = ''; status.dataset.kind = ''; }
        render(btn, root, res);
      });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-price-research]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    run(btn);
  });
})();
