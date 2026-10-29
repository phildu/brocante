(function () {
  var root = document.getElementById('ev-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('ev-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  var form = document.getElementById('ev-settings');

  function tierRow(t) {
    var row = el('div', null, { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;' });
    var p = el('input', null, { type: 'number', min: 10, max: 100000, placeholder: 'points', 'data-k': 'points', style: 'width:110px;', 'aria-label': 'Points du palier' });
    var e = el('input', null, { type: 'text', inputmode: 'decimal', placeholder: 'remise en €', 'data-k': 'euros', style: 'width:110px;', 'aria-label': 'Remise en euros' });
    if (t) { p.value = t.points; e.value = (t.cents / 100).toString().replace('.', ','); }
    row.appendChild(p); row.appendChild(el('span', 'points =')); row.appendChild(e); row.appendChild(el('span', '€ de remise'));
    return row;
  }
  function fill(d) {
    var s = d.settings;
    form.program_name.value = s.program_name; form.points_per_euro.value = String(s.points_per_euro).replace('.', ','); form.welcome_points.value = s.welcome_points; form.enabled.checked = s.enabled === '1';
    var tw = document.getElementById('ev-tiers'); tw.textContent = '';
    var tiers = d.tiers.slice(); while (tiers.length < 5) tiers.push(null);
    tiers.forEach(function (t) { tw.appendChild(tierRow(t)); });
    var st = d.stats, box = document.getElementById('ev-stats'); box.textContent = '';
    [[st.members, 'membres'], [st.points_out, 'points en circulation'], [st.rewards_active, 'récompenses à utiliser'], [st.rewards_used, 'récompenses utilisées (' + eur(st.rewards_cents) + ')']].forEach(function (x) {
      var k = el('div', null, { style: 'border:1px solid var(--line);padding:8px 12px;min-width:120px;' }); k.appendChild(el('strong', String(x[0]), { style: 'display:block;font-size:1.3rem;' })); k.appendChild(el('span', x[1], { 'class': 'hint' })); box.appendChild(k);
    });
    var tb = document.querySelector('#ev-table tbody'); tb.textContent = '';
    if (!d.members.length) { var t0 = el('tr'); t0.appendChild(el('td', 'Aucun membre pour le moment.', { colspan: 6 })); tb.appendChild(t0); }
    d.members.forEach(function (m) {
      var tr = el('tr');
      tr.appendChild(el('td', m.email + (m.name ? ' (' + m.name + ')' : '') + (+m.welcomed ? '' : ' — non vérifiée')));
      tr.appendChild(el('td', (m.created_at || '').slice(0, 10)));
      tr.appendChild(el('td', String(m.points), { align: 'right' })); tr.appendChild(el('td', String(m.earned), { align: 'right' }));
      var td = el('td'), pts = el('input', null, { type: 'number', placeholder: '+/- pts', style: 'width:80px;', 'aria-label': 'Points à ajouter ou retirer' }), note = el('input', null, { type: 'text', placeholder: 'motif', maxlength: 100, style: 'width:120px;', 'aria-label': 'Motif' });
      var ok = el('button', 'OK', { type: 'button', 'class': 'btn-small' });
      ok.addEventListener('click', function () { post({ action: 'adjust', id: m.id, points: pts.value, note: note.value }).then(function (r) { msg.textContent = r.error || 'Points ajustés.'; if (!r.error) fill(r); }); });
      td.appendChild(pts); td.appendChild(note); td.appendChild(ok); tr.appendChild(td);
      var del = el('button', 'Effacer', { type: 'button', 'class': 'btn-small' });
      del.addEventListener('click', function () { if (confirm('Effacer ' + m.email + ', ses points et son historique ?')) post({ action: 'delete', id: m.id }).then(function (r) { msg.textContent = r.error || 'Membre effacé.'; if (!r.error) fill(r); }); });
      var tdd = el('td'); tdd.appendChild(del); tr.appendChild(tdd); tb.appendChild(tr);
    });
  }
  function load() {
    fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      fill(d);
      var a = document.getElementById('ev-link'); if (a && d.app_url) { a.href = d.app_url; a.textContent = d.app_url.replace(/^https?:\/\//, ''); }
      var on = [], sec = d.sections || {};
      if (sec.alertes) on.push('alertes chineur'); if (sec.billets) on.push('réservations et billets'); if (sec.scan) on.push('scan des QR codes');
      document.getElementById('ev-sections').textContent = on.length ? 'Rubriques actuellement proposées aux visiteurs, en plus de la fidélité : ' + on.join(', ') + '.' : 'Aucune autre rubrique pour le moment : activez Alerte chineur, Réservation, Retrait sur stand ou QR Code Story pour les voir apparaître.';
      document.getElementById('ev-csv').href = api + (api.indexOf('?') >= 0 ? '&' : '?') + 'export=csv';
    });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var tiers = [].map.call(document.querySelectorAll('#ev-tiers > div'), function (r) { return { points: r.querySelector('[data-k=points]').value, euros: r.querySelector('[data-k=euros]').value }; });
    post({ action: 'settings', program_name: form.program_name.value, points_per_euro: form.points_per_euro.value, welcome_points: form.welcome_points.value, enabled: form.enabled.checked ? '1' : '0', tiers: tiers })
      .then(function (r) { document.getElementById('ev-settings-msg').textContent = r.error || 'Réglages enregistrés.'; if (!r.error) fill(r); });
  });
  load();
})();
