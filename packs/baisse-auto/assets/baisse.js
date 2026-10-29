(function () {
  var root = document.getElementById('bp-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('bp-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      var tb = document.querySelector('#bp-due tbody'); tb.textContent = '';
      if (!d.due.length) { var t0 = el('tr'); t0.appendChild(el('td', 'Aucune baisse due pour le moment.', { colspan: 6 })); tb.appendChild(t0); }
      d.due.forEach(function (x) {
        var tr = el('tr'), c = el('td'), cb = el('input', null, { type: 'checkbox', value: x.ref, checked: 'checked' });
        c.appendChild(cb); tr.appendChild(c);
        tr.appendChild(el('td', x.ref + ' — ' + x.name)); tr.appendChild(el('td', x.age_days + ' j', { align: 'right' }));
        tr.appendChild(el('td', eur(x.current_cents), { align: 'right' }));
        tr.appendChild(el('td', eur(x.new_cents) + (x.floor_cents && x.new_cents === x.floor_cents ? ' (plancher)' : ''), { align: 'right' }));
        tr.appendChild(el('td', '−' + x.percent + ' %', { align: 'right' })); tb.appendChild(tr);
      });
      var lb = document.querySelector('#bp-log tbody'); lb.textContent = '';
      if (!d.log.length) { var l0 = el('tr'); l0.appendChild(el('td', 'Aucune baisse pour le moment.', { colspan: 6 })); lb.appendChild(l0); }
      d.log.forEach(function (l) {
        var tr = el('tr');
        tr.appendChild(el('td', l.at.slice(0, 16))); tr.appendChild(el('td', l.product_ref + ' — ' + (l.name || '')));
        tr.appendChild(el('td', l.old_price, { align: 'right' })); tr.appendChild(el('td', l.new_price, { align: 'right' }));
        tr.appendChild(el('td', (l.mode === 'auto' ? 'automatique' : 'validée') + (+l.undone ? ' — annulée' : '')));
        var td = el('td');
        if (!+l.undone) { var b = el('button', 'Annuler', { type: 'button', 'class': 'btn-small' }); b.addEventListener('click', function () { post({ action: 'undo', id: l.id }).then(function (r) { msg.textContent = r.error || 'Baisse annulée.'; load(); }); }); td.appendChild(b); }
        tr.appendChild(td); lb.appendChild(tr);
      });
    });
  }
  document.getElementById('bp-apply-all').addEventListener('click', function () {
    var refs = Array.prototype.map.call(document.querySelectorAll('#bp-due tbody input:checked'), function (c) { return c.value; });
    if (!refs.length) { msg.textContent = 'Cochez au moins une pièce.'; return; }
    post({ action: 'apply', refs: refs }).then(function (r) { msg.textContent = r.error || (r.applied + ' baisse(s) appliquée(s).' + (r.errors && r.errors.length ? ' ' + r.errors.join(' ; ') : '')); load(); });
  });
  document.getElementById('bp-settings').addEventListener('submit', function (e) {
    e.preventDefault();
    var d = { action: 'settings' };
    Array.prototype.forEach.call(e.target.elements, function (i) { if (i.name) d[i.name] = i.value; });
    post(d).then(function (r) { document.getElementById('bp-settings-msg').textContent = r.error || 'Règles enregistrées.'; load(); });
  });
  load();
})();
