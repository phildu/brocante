(function () {
  var root = document.getElementById('vf-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('vf-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function fmt(ts) { var d = new Date(ts * 1000); return d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
  var labels = { scheduled: 'programmée', running: 'en cours', ended: 'terminée', canceled: 'annulée' };
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      var tb = document.querySelector('#vf-table tbody'); tb.textContent = '';
      if (d.error) { msg.textContent = d.error; return; }
      if (!d.sales.length) { var t0 = el('tr'); t0.appendChild(el('td', 'Aucune vente flash.', { colspan: 6 })); tb.appendChild(t0); }
      d.sales.forEach(function (s) {
        var tr = el('tr');
        tr.appendChild(el('td', s.name));
        tr.appendChild(el('td', fmt(s.starts_at) + ' → ' + fmt(s.ends_at)));
        tr.appendChild(el('td', '-' + s.step_percent + ' % / ' + s.step_minutes + ' min (max -' + s.max_percent + ' %)'));
        tr.appendChild(el('td', (labels[s.status] || s.status) + (s.status === 'running' ? ' : -' + s.percent_now + ' %' : '')));
        tr.appendChild(el('td', String(s.in_scope), { align: 'right' }));
        var td = el('td');
        if (s.status === 'scheduled' || s.status === 'running') {
          var b = el('button', 'Annuler', { type: 'button', 'class': 'btn-small' });
          b.addEventListener('click', function () { post({ action: 'cancel', id: s.id }).then(function (r) { msg.textContent = r.error || r.message; load(); }); });
          td.appendChild(b);
        }
        tr.appendChild(td); tb.appendChild(tr);
      });
    });
  }
  document.getElementById('vf-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var d = { action: 'create' };
    Array.prototype.forEach.call(e.target.elements, function (i) { if (i.name) d[i.name] = i.value; });
    post(d).then(function (r) { msg.textContent = r.error || r.message; if (!r.error) load(); });
  });
  load();
})();
