(function () {
  var root = document.getElementById('aco-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf;
  var labelsBase = root.dataset.labels, reportBase = root.dataset.report, exportApi = root.dataset.export;
  var msg = document.getElementById('aco-msg');
  var form = document.getElementById('aco-form');
  var statusLabels = { scheduled: 'programmée', running: 'en cours', ended: 'terminée', canceled: 'annulée' };
  var sourceLabels = { visiteur: 'membres de la web app', chineur: 'abonnés aux alertes', prospect: 'prospects newsletter' };

  function el(tag, text, attrs) {
    var e = document.createElement(tag);
    if (text != null) e.textContent = text;
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
  }
  function money(cents) {
    return (Math.max(0, Number(cents) || 0) / 100).toFixed(2).replace('.', ',') + ' €';
  }
  function post(body) {
    return fetch(api, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function formData() {
    var d = {};
    new FormData(form).forEach(function (v, k) {
      if (k.slice(-2) === '[]') {
        k = k.slice(0, -2);
        (d[k] = d[k] || []).push(v);
      } else {
        d[k] = v;
      }
    });
    return d;
  }

  function showPreview(p) {
    var box = document.getElementById('aco-preview');
    var summary = document.getElementById('aco-preview-summary');
    var list = document.getElementById('aco-preview-warnings');
    var rows = document.getElementById('aco-preview-rows');
    var note = document.getElementById('aco-preview-note');
    box.hidden = false;
    list.textContent = '';
    rows.textContent = '';
    var pct = p.before_cents > 0 ? Math.round((1 - p.after_cents / p.before_cents) * 100) : 0;
    summary.textContent = p.discounted + ' pièce(s) seront réduites sur ' + p.eligible + ' pièce(s) concernée(s)'
      + (p.skipped > 0 ? ' (' + p.skipped + ' sans baisse possible)' : '')
      + ' · valeur ' + money(p.before_cents) + ' → ' + money(p.after_cents) + ' (−' + pct + ' %).';
    (p.warnings || []).forEach(function (w) { list.appendChild(el('li', w)); });
    p.sample.forEach(function (s) {
      var tr = el('tr');
      tr.appendChild(el('td', s.ref));
      tr.appendChild(el('td', s.name));
      tr.appendChild(el('td', s.old, { align: 'right' }));
      tr.appendChild(el('td', s.new, { align: 'right' }));
      rows.appendChild(tr);
    });
    note.textContent = p.discounted > p.sample.length ? 'Les ' + p.sample.length + ' premières pièces sont montrées ; les étiquettes listent tout.' : '';
  }

  function actionButton(text, action, id, done) {
    var b = el('button', text, { type: 'button', 'class': 'btn-small' });
    b.addEventListener('click', function () {
      if (action === 'delete' && !window.confirm('Supprimer définitivement cette opération et son historique de prix ?')) return;
      if (action === 'stop' && !window.confirm('Arrêter l\'opération et rétablir les prix d\'origine ?')) return;
      b.disabled = true;
      post({ action: action, id: id }).then(function (r) {
        msg.textContent = r.error || r.message;
        done();
      }).catch(function () {
        msg.textContent = 'Action impossible, réessayez.';
        b.disabled = false;
      });
    });
    return b;
  }

  function notifyButton(o) {
    var n = o.notices ? o.notices.pending : 0;
    var b = el('button', 'Prévenir' + (n > 0 ? ' (' + n + ')' : ''), {
      type: 'button', 'class': 'btn-small',
      title: n > 0 ? 'Envoyer l\'annonce aux visiteurs qui ont donné leur accord' : 'Aucune nouvelle adresse à prévenir'
    });
    if (!n) { b.disabled = true; return b; }
    b.addEventListener('click', function () {
      var by = o.notices.by_source || {};
      var parts = [];
      ['visiteur', 'chineur', 'prospect'].forEach(function (k) {
        if (by[k] > 0) parts.push('· ' + by[k] + ' ' + (sourceLabels[k] || k));
      });
      var ask = 'Envoyer l\'annonce à ' + n + ' adresse(s) ?' + (parts.length ? '\n' + parts.join('\n') : '')
        + '\n\nSeules les personnes ayant donné leur accord sont contactées ; chaque e-mail contient un lien de désinscription.';
      if (!window.confirm(ask)) return;
      b.disabled = true;
      post({ action: 'notify', id: o.id }).then(function (r) {
        msg.textContent = r.error || r.message;
        load();
      }).catch(function () {
        msg.textContent = 'Envoi impossible, réessayez.';
        b.disabled = false;
      });
    });
    return b;
  }

  function load() {
    return fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var tb = document.querySelector('#aco-table tbody');
        tb.textContent = '';
        if (d.error) { msg.textContent = d.error; return; }
        if (d.notice_sources) sourceLabels = d.notice_sources;
        if (!d.operations.length) {
          var empty = el('tr');
          empty.appendChild(el('td', 'Aucune opération pour le moment.', { colspan: 7 }));
          tb.appendChild(empty);
          return;
        }
        d.operations.forEach(function (o) {
          var tr = el('tr');
          var nameCell = el('td');
          nameCell.appendChild(el('strong', o.name));
          nameCell.appendChild(el('div', o.kind_label + (o.label && o.label !== o.kind_label ? ' · ' + o.label : ''), { 'class': 'hint' }));
          tr.appendChild(nameCell);
          tr.appendChild(el('td', o.period));
          tr.appendChild(el('td', o.discount_label));
          var stateCell = el('td', statusLabels[o.status] || o.status);
          if (o.notices && o.notices.sent > 0) stateCell.appendChild(el('div', o.notices.sent + ' prévenu(s)', { 'class': 'hint' }));
          tr.appendChild(stateCell);
          tr.appendChild(el('td', String(o.count), { align: 'right' }));
          tr.appendChild(el('td', o.count ? money(o.before_cents) + ' → ' + money(o.after_cents) : '—', { align: 'right' }));

          var actions = el('td');
          actions.style.whiteSpace = 'nowrap';
          if (o.status === 'scheduled') actions.appendChild(actionButton('Démarrer', 'start', o.id, load));
          if (o.status === 'scheduled' || o.status === 'running') {
            actions.appendChild(notifyButton(o));
            actions.appendChild(actionButton('Arrêter', 'stop', o.id, load));
          }
          if (o.count) {
            actions.appendChild(el('a', 'Étiquettes', { href: labelsBase + o.id, 'class': 'hint', style: 'margin-left:8px;' }));
            actions.appendChild(el('a', 'Bilan', { href: reportBase + o.id, 'class': 'hint', style: 'margin-left:8px;' }));
          }
          if (o.status === 'ended' || o.status === 'canceled') actions.appendChild(actionButton('Supprimer', 'delete', o.id, load));
          tr.appendChild(actions);
          tb.appendChild(tr);
        });
      })
      .catch(function () { msg.textContent = 'Chargement impossible, rechargez la page.'; });
  }

  document.getElementById('aco-preview-btn').addEventListener('click', function () {
    var d = formData();
    d.action = 'preview';
    msg.textContent = 'Calcul en cours…';
    post(d).then(function (r) {
      if (r.error) { msg.textContent = r.error; return; }
      msg.textContent = '';
      showPreview({
        discounted: r.preview.discounted, eligible: r.preview.eligible, skipped: r.preview.skipped,
        before_cents: r.preview.before_cents, after_cents: r.preview.after_cents, sample: r.preview.sample, warnings: r.warnings
      });
    }).catch(function () { msg.textContent = 'Aperçu impossible, réessayez.'; });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var d = formData();
    d.action = 'create';
    msg.textContent = 'Enregistrement…';
    post(d).then(function (r) {
      msg.textContent = r.error || r.message;
      if (!r.error) load();
    }).catch(function () { msg.textContent = 'Enregistrement impossible, réessayez.'; });
  });

  load();
})();
