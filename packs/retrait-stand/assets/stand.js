(function () {
  var root = document.getElementById('rt-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('rt-msg'), scanMsg = document.getElementById('rt-scan-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  var labels = { pending: 'paiement en cours', authorized: 'billet valide', collecting: 'encaissement…', collected: 'retiré et payé', released: 'annulé', expired: 'expiré', canceled: 'abandonné' };

  function showResult(p) {
    var box = document.getElementById('rt-result'); box.textContent = ''; box.style.display = 'grid';
    box.appendChild(el('strong', p.code + ' — ' + (p.product_name || p.product_ref), { style: 'font-size:1.1rem;' }));
    box.appendChild(el('div', p.name + ' <' + p.email + '>' + (p.phone ? ' · ' + p.phone : '') + ' · ' + eur(+p.amount_cents)));
    var valid = p.status === 'authorized' && p.expires_at > Date.now() / 1000;
    box.appendChild(el('div', valid ? 'Billet valide jusqu’au ' + new Date(p.expires_at * 1000).toLocaleDateString('fr-FR') : 'État : ' + (labels[p.status] || p.status) + (p.status === 'authorized' ? ' (périmé)' : ''), { style: 'font-weight:600;color:' + (valid ? '#1e6b3a' : '#a4361b') }));
    if (valid) {
      var row = el('div', null, { style: 'display:flex;gap:8px;flex-wrap:wrap;' });
      var ok = el('button', 'Encaisser ' + eur(+p.amount_cents) + ' et remettre la pièce', { type: 'button', 'class': 'btn-small' });
      ok.addEventListener('click', function () { ok.disabled = true; post({ action: 'collect', id: p.id }).then(function (r) { scanMsg.textContent = r.error || 'Encaissé : pièce vendue, reçu émis.'; box.style.display = 'none'; load(); }); });
      var no = el('button', 'Annuler le billet (lever le blocage)', { type: 'button', 'class': 'btn-small' });
      no.addEventListener('click', function () { if (confirm('Lever le blocage sur la carte et libérer la pièce ?')) post({ action: 'release', id: p.id }).then(function (r) { scanMsg.textContent = r.error || 'Billet annulé.'; box.style.display = 'none'; load(); }); });
      row.appendChild(ok); row.appendChild(no); box.appendChild(row);
    }
  }
  function find(code) {
    scanMsg.textContent = 'Recherche…';
    fetch(api + '&find=' + encodeURIComponent(code), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { scanMsg.textContent = d.error; return; }
      scanMsg.textContent = ''; showResult(d.pickup);
    });
  }
  document.getElementById('rt-find').addEventListener('click', function () { var v = document.getElementById('rt-code').value; if (v.trim()) find(v); });
  document.getElementById('rt-code').addEventListener('keydown', function (e) { if (e.key === 'Enter') document.getElementById('rt-find').click(); });

  var stream = null, timer = null;
  function stopCam() { if (timer) clearInterval(timer); if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; timer = null; document.getElementById('rt-video').style.display = 'none'; }
  document.getElementById('rt-cam').addEventListener('click', function () {
    if (stream) { stopCam(); return; }
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { scanMsg.textContent = 'Ce navigateur ne sait pas lire les QR codes : saisissez le code du billet (6 caractères), ou utilisez Chrome sur Android.'; return; }
    var det = new BarcodeDetector({ formats: ['qr_code'] }), v = document.getElementById('rt-video');
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s; v.srcObject = s; v.style.display = 'block'; v.play();
      timer = setInterval(function () {
        det.detect(v).then(function (codes) { if (codes.length) { stopCam(); find(codes[0].rawValue); } }).catch(function () {});
      }, 400);
    }).catch(function () { scanMsg.textContent = 'Caméra inaccessible (autorisation refusée ?). Saisissez le code du billet.'; });
  });

  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      document.getElementById('rt-stripe').textContent = d.stripe ? 'Paiement en ligne : Stripe de la boutique configuré.' : 'Stripe n’est pas configuré sur cette boutique (Administration → Réglages Stripe) : le bouton « Retirer sur le stand » reste masqué.';
      var tb = document.querySelector('#rt-table tbody'); tb.textContent = '';
      if (!d.pickups.length) { var t0 = el('tr'); t0.appendChild(el('td', 'Aucun billet.', { colspan: 6 })); tb.appendChild(t0); }
      d.pickups.forEach(function (p) {
        var tr = el('tr');
        tr.appendChild(el('td', p.code, { style: 'font-family:monospace;' })); tr.appendChild(el('td', p.product_ref + ' — ' + (p.product_name || '')));
        tr.appendChild(el('td', p.name + ' <' + p.email + '>')); tr.appendChild(el('td', eur(+p.amount_cents), { align: 'right' }));
        tr.appendChild(el('td', (labels[p.status] || p.status) + (p.note ? ' — ' + p.note : '')));
        var td = el('td');
        if (p.status === 'authorized') { var b = el('button', 'Ouvrir', { type: 'button', 'class': 'btn-small' }); b.addEventListener('click', function () { showResult(p); window.scrollTo({ top: 0, behavior: 'smooth' }); }); td.appendChild(b); }
        tr.appendChild(td); tb.appendChild(tr);
      });
    });
  }
  document.getElementById('rt-settings').addEventListener('submit', function (e) {
    e.preventDefault();
    var d = { action: 'settings' };
    Array.prototype.forEach.call(e.target.elements, function (i) { if (!i.name) return; d[i.name] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value; });
    post(d).then(function (r) { document.getElementById('rt-settings-msg').textContent = r.error || 'Réglages enregistrés.'; });
  });
  var params = new URLSearchParams(location.search); if (params.get('code')) find(params.get('code'));
  load();
})();
