(function () {
  var root = document.getElementById('ms-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('ms-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      var box = document.getElementById('ms-list'); box.textContent = '';
      if (d.error) { msg.textContent = d.error; return; }
      if (!d.messages.length) box.appendChild(el('p', 'Aucun message pour le moment.', { 'class': 'hint' }));
      d.messages.forEach(function (m) {
        var c = el('div', null, { style: 'border:1px solid var(--line);padding:10px 12px;display:grid;gap:6px;' + (m.direction === 'out' ? 'margin-left:24px;background:var(--surface-2,#f3f1ea);' : '') });
        var head = (m.direction === 'out' ? 'Votre réponse à ' : '') + (m.name ? m.name + ' ' : '') + '<' + m.email + '>' + (m.product_name ? ' · ' + m.product_name : '') + ' · ' + m.created_at.slice(0, 16)
          + (m.direction === 'in' ? ' · ' + ({ 'new': 'nouveau', read: 'lu', answered: 'répondu' }[m.status] || m.status) : '');
        c.appendChild(el('strong', head, { style: 'font-size:.82rem;' + (m.status === 'new' ? 'color:#a4361b;' : '') }));
        c.appendChild(el('div', m.body, { style: 'white-space:pre-wrap;' }));
        if (m.direction === 'in') {
          var f = el('form', null, { style: 'display:grid;gap:6px;' }), t = el('textarea', null, { rows: 3, placeholder: 'Votre réponse (envoyée par e-mail)', style: 'width:100%;' });
          var b = el('button', 'Répondre', { type: 'submit', 'class': 'btn-small' });
          f.appendChild(t); f.appendChild(b);
          f.addEventListener('submit', function (ev) { ev.preventDefault(); post({ action: 'reply', id: m.id, body: t.value }).then(function (r) { msg.textContent = r.error || 'Réponse envoyée.'; if (!r.error) load(); }); });
          c.appendChild(f);
          if (m.status === 'new') post({ action: 'read', id: m.id });
        }
        box.appendChild(c);
      });
    });
  }
  document.getElementById('ms-settings').addEventListener('submit', function (e) {
    e.preventDefault();
    var d = { action: 'settings' };
    Array.prototype.forEach.call(e.target.elements, function (i) { if (!i.name) return; d[i.name] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value; });
    post(d).then(function (r) { document.getElementById('ms-settings-msg').textContent = r.error || 'Réglages enregistrés.'; });
  });
  load();
})();
