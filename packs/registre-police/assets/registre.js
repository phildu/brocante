(function () {
  var root = document.getElementById('pr-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('pr-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function eur(c) { return c === null || c === '' ? '—' : (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  function dfr(d) { return d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '—'; }
  var entries = [];

  var FIELDS = [
    ['entry_date', "Date d'entrée", 'date'], ['purchase_cents', "Prix d'achat (€)", 'text'], ['payment_mode', 'Mode de règlement', 'text'],
    ['seller_type', 'Vendeur', 'select'], ['seller_lastname', 'Nom', 'text'], ['seller_firstname', 'Prénom', 'text'],
    ['seller_birth_date', 'Date de naissance', 'date'], ['seller_birth_place', 'Lieu de naissance', 'text'], ['seller_address', 'Adresse', 'text'],
    ['id_doc_type', "Pièce d'identité (type)", 'text'], ['id_doc_number', 'N° de la pièce', 'text'], ['id_doc_issuer', 'Délivrée par', 'text'], ['id_doc_date', 'Délivrée le', 'date'],
    ['sale_date', 'Date de sortie', 'date'], ['sale_cents', 'Prix de vente (€)', 'text'], ['buyer', 'Acquéreur', 'text'], ['notes', 'Observations', 'text']
  ];

  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      entries = d.entries;
      document.getElementById('pr-integrity').textContent = d.integrity.ok ? 'Journal intègre (' + d.integrity.lines + ' lignes)' : 'ATTENTION : journal altéré (ligne ' + d.integrity.first_bad + ')';
      var tb = document.querySelector('#pr-table tbody'); tb.textContent = '';
      if (!entries.length) { var t0 = el('tr'); t0.appendChild(el('td', 'Aucune entrée : les nouvelles pièces s’inscriront seules, ou inscrivez les existantes ci-dessous.', { colspan: 6 })); tb.appendChild(t0); }
      entries.forEach(function (e) {
        var tr = el('tr');
        tr.appendChild(el('td', String(e.number))); tr.appendChild(el('td', dfr(e.entry_date))); tr.appendChild(el('td', e.description));
        var tdA = el('td', e.missing.length ? 'À compléter : ' + e.missing.join(', ') : 'Complet · ' + eur(e.purchase_cents === null ? null : +e.purchase_cents));
        if (e.missing.length) tdA.style.color = '#a4361b';
        tr.appendChild(tdA);
        tr.appendChild(el('td', e.sale_date ? dfr(e.sale_date) + ' · ' + eur(e.sale_cents === null ? null : +e.sale_cents) : '—'));
        var td = el('td'), b = el('button', 'Compléter / modifier', { type: 'button', 'class': 'btn-small' });
        b.addEventListener('click', function () { edit(e); });
        td.appendChild(b); tr.appendChild(td); tb.appendChild(tr);
      });
      var box = document.getElementById('pr-notreg'); box.textContent = '';
      document.getElementById('pr-notreg-count').textContent = '(' + d.not_registered.length + ')';
      d.not_registered.forEach(function (p) {
        var l = el('label'), c = el('input', null, { type: 'checkbox', value: p.ref });
        l.appendChild(c); l.appendChild(document.createTextNode(' ' + p.ref + ' — ' + p.name)); box.appendChild(l);
      });
    });
  }

  function edit(e) {
    var sec = document.getElementById('pr-edit'), f = document.getElementById('pr-form');
    sec.style.display = 'grid'; f.textContent = '';
    document.getElementById('pr-edit-title').textContent = 'Entrée n° ' + e.number + ' — réf. ' + e.product_ref;
    FIELDS.forEach(function (fd) {
      var lab = el('label', fd[1]); lab.appendChild(el('br'));
      var inp;
      if (fd[2] === 'select') { inp = el('select'); ['particulier', 'professionnel'].forEach(function (o) { inp.appendChild(el('option', o, { value: o })); }); inp.value = e.seller_type; }
      else { inp = el('input', null, { type: fd[2] }); var v = e[fd[0]]; if (fd[0].slice(-6) === '_cents') v = v === null || v === '' ? '' : (v / 100).toFixed(2); inp.value = v == null ? '' : String(v).slice(0, fd[2] === 'date' ? 10 : 400); }
      inp.name = fd[0]; lab.appendChild(inp); f.appendChild(lab);
    });
    var sub = el('button', 'Enregistrer', { type: 'submit', 'class': 'btn-small' }); f.appendChild(sub);
    f.onsubmit = function (ev) {
      ev.preventDefault();
      var d = { action: 'update', id: e.id };
      Array.prototype.forEach.call(f.elements, function (i) { if (i.name) d[i.name] = i.value; });
      post(d).then(function (r) { msg.textContent = r.error || 'Entrée enregistrée (modification journalisée).'; if (!r.error) { sec.style.display = 'none'; load(); } });
    };
    sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  document.getElementById('pr-register').addEventListener('click', function () {
    var refs = Array.prototype.map.call(document.querySelectorAll('#pr-notreg input:checked'), function (c) { return c.value; });
    if (!refs.length) { msg.textContent = 'Cochez au moins une pièce.'; return; }
    post({ action: 'register', refs: refs }).then(function (r) { msg.textContent = r.error || r.created + ' pièce(s) inscrite(s).'; load(); });
  });

  function links() {
    var f = document.getElementById('pr-export'), q = '&from=' + encodeURIComponent(f.from.value) + '&to=' + encodeURIComponent(f.to.value);
    document.getElementById('pr-print').href = root.dataset.export + q + '&format=html';
    document.getElementById('pr-csv').href = root.dataset.export + q + '&format=csv';
  }
  document.getElementById('pr-export').addEventListener('change', links); links();
  load();
})();
