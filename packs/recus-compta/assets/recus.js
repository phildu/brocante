(function () {
  var root = document.getElementById('rc-page');
  if (!root || root.dataset.entitled !== '1') return;
  var csrf = root.dataset.csrf;
  function post(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible (' + r.status + ')' }; }); });
  }
  function el(tag, text, attrs) { var e = document.createElement(tag); if (text != null) e.textContent = text; Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); }); return e; }

  document.getElementById('rc-settings').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = ev.target, d = {};
    Array.prototype.forEach.call(f.elements, function (i) { if (!i.name) return; d[i.name] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value; });
    post(root.dataset.apiSettings, d).then(function (r) { document.getElementById('rc-settings-msg').textContent = r.error || 'Réglages enregistrés.'; });
  });

  document.getElementById('rc-export').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-fmt]'); if (!b) return;
    var f = document.getElementById('rc-export'), msg = document.getElementById('rc-export-msg');
    var url = root.dataset.apiExport + '&format=' + b.dataset.fmt + '&from=' + encodeURIComponent(f.from.value) + '&to=' + encodeURIComponent(f.to.value);
    fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Export impossible.'); });
      var name = (/filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '') || [])[1] || 'export.txt';
      return r.blob().then(function (blob) { var a = el('a', null, { href: URL.createObjectURL(blob), download: name }); document.body.appendChild(a); a.click(); a.remove(); msg.textContent = name + ' téléchargé.'; });
    }).catch(function (e) { msg.textContent = e.message; });
  });

  function loadOrders() {
    fetch(root.dataset.apiReceipts, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      var tb = document.querySelector('#rc-orders tbody'); tb.textContent = '';
      if (d.error) { document.getElementById('rc-orders-msg').textContent = d.error; return; }
      document.getElementById('rc-orders-msg').textContent = d.orders.length ? '' : 'Aucune commande payée pour le moment.';
      d.orders.forEach(function (o) {
        var tr = el('tr');
        [String(o.created_at).slice(0, 10), '#' + o.id, o.email || '—'].forEach(function (t) { tr.appendChild(el('td', t)); });
        tr.appendChild(el('td', (o.amount_total / 100).toFixed(2).replace('.', ',') + ' €', { align: 'right' }));
        var tdR = el('td');
        if (o.number) { tdR.appendChild(el('a', o.number, { href: o.url, target: '_blank', rel: 'noopener' })); if (o.emailed_at) tdR.appendChild(el('small', ' · envoyé')); }
        else tdR.appendChild(el('span', '—'));
        tr.appendChild(tdR);
        var tdA = el('td'), b = el('button', o.number ? 'Renvoyer' : 'Générer et envoyer', { type: 'button', 'class': 'btn-small' });
        b.addEventListener('click', function () {
          post(root.dataset.apiReceipts, { order_id: o.id, action: 'send' }).then(function (r) { document.getElementById('rc-orders-msg').textContent = r.error || r.message; loadOrders(); });
        });
        tdA.appendChild(b); tr.appendChild(tdA); tb.appendChild(tr);
      });
    });
  }
  loadOrders();
})();
