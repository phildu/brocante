(function () {
  var root = document.getElementById('ra-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('ra-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  function acompte(r) { return r && r.mode === 'acompte'; }
  function labelOf(r) {
    var a = acompte(r);
    return { pending: 'paiement en cours', active: 'active', honored: 'vendue (solde réglé)', expired: 'échue', forfeited: a ? 'acompte conservé' : 'arrhes conservées', refunded: a ? 'acompte remboursé' : 'arrhes remboursées', canceled: 'annulée' }[r.status] || r.status;
  }
  function act(label, action, id, confirmText) {
    var b = el('button', label, { type: 'button', 'class': 'btn-small', style: 'margin:2px;' });
    b.addEventListener('click', function () {
      if (confirmText && !confirm(confirmText)) return;
      post({ action: action, id: id }).then(function (r) { msg.textContent = r.error || 'Fait.'; load(); });
    });
    return b;
  }
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      document.getElementById('ra-stripe').textContent = d.stripe ? 'Paiement en ligne : Stripe de la boutique configuré.' : 'Stripe n’est pas configuré sur cette boutique (Administration → Réglages Stripe) : le bouton « Réserver » reste masqué.';
      var tb = document.querySelector('#ra-table tbody'); tb.textContent = '';
      if (!d.reservations.length) { var t0 = el('tr'); t0.appendChild(el('td', 'Aucune réservation.', { colspan: 6 })); tb.appendChild(t0); }
      d.reservations.forEach(function (r) {
        var tr = el('tr');
        tr.appendChild(el('td', r.product_ref + ' — ' + (r.product_name || '')));
        tr.appendChild(el('td', r.name + ' <' + r.email + '>' + (r.phone ? ' · ' + r.phone : '')));
        tr.appendChild(el('td', eur(+r.deposit_cents) + ' / ' + eur(+r.price_cents), { align: 'right' }));
        tr.appendChild(el('td', (labelOf(r) + (acompte(r) ? ' · acompte' : '')) + (r.note ? ' — ' + r.note : '')));
        tr.appendChild(el('td', r.expires_at > 0 && (r.status === 'active' || r.status === 'expired') ? new Date(r.expires_at * 1000).toLocaleDateString('fr-FR') : '—'));
        var td = el('td');
        if (r.status === 'active' || r.status === 'expired') {
          td.appendChild(act('Solde réglé : vendue', 'honor', r.id, 'Marquer la pièce vendue (solde réglé par le client) ?'));
          td.appendChild(act(acompte(r) ? 'Client absent : garder l’acompte' : 'Client absent : garder les arrhes', 'forfeit', r.id, acompte(r) ? 'Libérer la pièce et conserver l’acompte (le solde reste dû par le client) ?' : 'Libérer la pièce et conserver les arrhes ?'));
        }
        if (r.status === 'active' || r.status === 'expired' || r.status === 'forfeited') td.appendChild(act(acompte(r) ? 'Rembourser l’acompte' : 'Rembourser les arrhes', 'refund', r.id, 'Rembourser ' + (acompte(r) ? 'l’acompte' : 'les arrhes') + ' au client par Stripe ? (la pièce est libérée)'));
        tr.appendChild(td); tb.appendChild(tr);
      });
    });
  }
  document.getElementById('ra-settings').addEventListener('submit', function (e) {
    e.preventDefault();
    var d = { action: 'settings' };
    Array.prototype.forEach.call(e.target.elements, function (i) { if (!i.name) return; d[i.name] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value; });
    post(d).then(function (r) { document.getElementById('ra-settings-msg').textContent = r.error || 'Réglages enregistrés.'; });
  });
  load();
})();
