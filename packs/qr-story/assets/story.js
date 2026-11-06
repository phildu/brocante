(function () {
  var root = document.getElementById('qs-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf, msg = document.getElementById('qs-msg');
  function post(body) {
    return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function el(t, text, a) { var e = document.createElement(t); if (text != null) e.textContent = text; Object.keys(a || {}).forEach(function (k) { e.setAttribute(k, a[k]); }); return e; }
  var q = document.getElementById('qs-q'), timer;
  function load() {
    fetch(api + '&q=' + encodeURIComponent(q.value), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      document.getElementById('qs-signatory').value = d.signatory || '';
      var tb = document.querySelector('#qs-table tbody'); tb.textContent = '';
      d.products.forEach(function (p) {
        var tr = el('tr'), td0 = el('td'), c = el('input', null, { type: 'checkbox', value: p.ref, 'data-story': p.token ? '1' : '0' });
        td0.appendChild(c); tr.appendChild(td0);
        tr.appendChild(el('td', p.ref + ' — ' + p.name));
        tr.appendChild(el('td', p.token ? (p.epoch || 'histoire enregistrée') : '—'));
        var tdP = el('td');
        if (p.token && +p.published) tdP.appendChild(el('a', 'publiée', { href: p.url, target: '_blank', rel: 'noopener' })); else tdP.appendChild(el('span', p.token ? 'brouillon' : '—'));
        tr.appendChild(tdP);
        var td = el('td'), b = el('button', 'Raconter / modifier', { type: 'button', 'class': 'btn-small' });
        b.addEventListener('click', function () { edit(p); });
        td.appendChild(b); tr.appendChild(td); tb.appendChild(tr);
      });
    });
  }
  function edit(p) {
    var sec = document.getElementById('qs-edit'), f = document.getElementById('qs-form');
    sec.style.display = 'grid';
    document.getElementById('qs-edit-title').textContent = p.ref + ' — ' + p.name;
    f.epoch.value = p.epoch || ''; f.origin.value = p.origin || (p.token ? '' : String(p.description || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 600));
    f.expertise.value = p.expertise || ''; f.expert_name.value = p.expert_name || ''; f.published.checked = !!+p.published;
    f.onsubmit = function (ev) {
      ev.preventDefault();
      post({ action: 'save', ref: p.ref, epoch: f.epoch.value, origin: f.origin.value, expertise: f.expertise.value, expert_name: f.expert_name.value, published: f.published.checked ? 1 : 0 })
        .then(function (r) { msg.textContent = r.error || 'Histoire enregistrée.'; if (!r.error) { sec.style.display = 'none'; load(); } });
    };
    sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function checked() { return Array.prototype.map.call(document.querySelectorAll('#qs-table tbody input:checked'), function (c) { return c.value; }); }
  function printKind(kind) {
    var refs = checked();
    if (!refs.length) { msg.textContent = 'Cochez au moins une pièce (avec une histoire enregistrée).'; return; }
    window.open(root.dataset.print + '&kind=' + kind + '&refs=' + encodeURIComponent(refs.join(',')), '_blank', 'noopener');
  }
  document.getElementById('qs-print-labels').addEventListener('click', function () { printKind('labels'); });
  document.getElementById('qs-print-certs').addEventListener('click', function () { printKind('certificate'); });
  document.getElementById('qs-save-settings').addEventListener('click', function () {
    post({ action: 'settings', signatory: document.getElementById('qs-signatory').value }).then(function (r) { msg.textContent = r.error || 'Signataire enregistré.'; });
  });
  q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 300); });
  load();
})();
