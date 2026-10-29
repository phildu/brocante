(function () {
  var root = document.getElementById('ac-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('ac-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  var labels = { pending: 'à confirmer', active: 'active' };
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      var tb = document.querySelector('#ac-table tbody'); tb.textContent = '';
      if (d.error) { msg.textContent = d.error; return; }
      if (!d.alerts.length) { var tr0 = el('tr'); tr0.appendChild(el('td', 'Aucune alerte pour le moment.', { colspan: 6 })); tb.appendChild(tr0); }
      d.alerts.forEach(function (a) {
        var tr = el('tr');
        tr.appendChild(el('td', a.email)); tr.appendChild(el('td', a.keywords));
        tr.appendChild(el('td', a.max_price_cents ? (a.max_price_cents / 100).toFixed(2).replace('.', ',') + ' €' : '—', { align: 'right' }));
        tr.appendChild(el('td', labels[a.status] || a.status)); tr.appendChild(el('td', String(a.sent), { align: 'right' }));
        var td = el('td'), b = el('button', 'Supprimer', { type: 'button', 'class': 'btn-small' });
        b.addEventListener('click', function () { post({ action: 'delete', id: a.id }).then(load); });
        td.appendChild(b); tr.appendChild(td); tb.appendChild(tr);
      });
    });
  }
  document.getElementById('ac-scan').addEventListener('click', function () {
    msg.textContent = 'Vérification…';
    post({ action: 'scan' }).then(function (r) { msg.textContent = r.error || r.message; load(); });
  });
  load();
})();
