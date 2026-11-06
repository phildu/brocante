(function () {
  var root = document.getElementById('aco-page');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf;
  var labelsBase = root.dataset.labels, reportBase = root.dataset.report, exportApi = root.dataset.export;
  var contactsApi = root.dataset.contacts;
  var msg = document.getElementById('aco-msg');
  var contactsMsg = document.getElementById('aco-contacts-msg');
  var form = document.getElementById('aco-form');
  var statusLabels = { scheduled: 'programmée', running: 'en cours', ended: 'terminée', canceled: 'annulée' };
  var sourceLabels = { visiteur: 'membres de la web app', chineur: 'abonnés aux alertes', prospect: 'prospects newsletter' };
  var channelLabels = { mail: 'E-mail', inapp: 'Dans l\'app', sms: 'SMS', whatsapp: 'WhatsApp' };
  var channelOrder = ['mail', 'inapp', 'sms', 'whatsapp'];
  var batch = 40;

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
    var ch = (o.notices && o.notices.channels) || {};
    var total = channelOrder.reduce(function (s, k) {
      var info = ch[k] || {};
      return s + (info.ready !== false ? (info.eligible || 0) : 0);
    }, 0);
    var b = el('button', 'Prévenir' + (total > 0 ? ' (' + total + ')' : ''), {
      type: 'button', 'class': 'btn-small',
      title: total > 0 ? 'Choisir les canaux et envoyer l\'annonce' : 'Aucune nouvelle adresse à prévenir'
    });
    if (!total) { b.disabled = true; return b; }
    b.addEventListener('click', function () { openChannels(o); });
    return b;
  }

  function openChannels(o) {
    var ch = (o.notices && o.notices.channels) || {};
    var overlay = el('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(20,16,12,.55);z-index:120;display:flex;align-items:center;justify-content:center;padding:20px;';
    var box = el('div');
    box.style.cssText = 'background:var(--bg,#fff);color:var(--ink,#222);max-width:460px;width:100%;padding:20px;border-radius:4px;box-shadow:0 24px 60px rgba(0,0,0,.35);display:grid;gap:10px;';
    box.appendChild(el('h3', 'Prévenir les visiteurs', { style: 'margin:0;font-size:1.05rem;' }));
    box.appendChild(el('p', 'Chaque canal ne joint que les personnes qui l\'ont accepté. Les envois partent par lots de ' + batch + '.', { 'class': 'hint', style: 'margin:0;' }));

    var boxes = {};
    channelOrder.forEach(function (key) {
      var info = ch[key] || {};
      var ready = info.ready !== false;
      var n = info.eligible || 0;
      var row = el('label');
      row.style.cssText = 'display:flex;gap:10px;align-items:center;font-weight:400;';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      if (!ready || !n) cb.disabled = true;
      if (ready && n && (key === 'mail' || key === 'inapp')) cb.checked = true;
      var text = (channelLabels[key] || key) + ' — '
        + (!ready ? 'à configurer' : (n ? n + ' en attente' : 'aucune adresse en attente'));
      if (key === 'inapp' && ready && n) text += ' (personnes reconnues sur le site)';
      row.append(cb, el('span', text));
      boxes[key] = cb;
      box.appendChild(row);
    });

    var actions = el('div', null, { style: 'display:flex;gap:10px;justify-content:flex-end;margin-top:4px;' });
    var cancel = el('button', 'Annuler', { type: 'button', 'class': 'btn-small' });
    var go = el('button', 'Envoyer', { type: 'button', 'class': 'btn-small' });
    cancel.addEventListener('click', function () { overlay.remove(); });
    go.addEventListener('click', function () {
      var picked = channelOrder.filter(function (k) { return boxes[k].checked; });
      if (!picked.length) { msg.textContent = 'Choisissez au moins un canal.'; return; }
      go.disabled = true;
      post({ action: 'notify', id: o.id, channels: picked }).then(function (r) {
        overlay.remove();
        msg.textContent = r.error || r.message;
        if (r.error) go.disabled = false; else load();
      }).catch(function () {
        go.disabled = false;
        msg.textContent = 'Envoi impossible, réessayez.';
      });
    });
    actions.append(cancel, go);
    box.appendChild(actions);
    overlay.appendChild(box);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
  }

  function loadContacts() {
    var table = document.querySelector('#aco-contacts');
    if (!table || !contactsApi) return;
    fetch(contactsApi, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); })
      .then(function (d) {
        var tb = table.querySelector('tbody');
        tb.textContent = '';
        if (d.error) { contactsMsg.textContent = d.error; return; }
        if (d.channels) channelLabels = d.channels;
        var rows = d.contacts || [];
        if (!rows.length) {
          var empty = el('tr');
          empty.appendChild(el('td', 'Aucun contact pour le moment.', { colspan: 7 }));
          tb.appendChild(empty);
          return;
        }
        rows.forEach(function (c) { tb.appendChild(contactRow(c)); });
      })
      .catch(function () { contactsMsg.textContent = 'Liste des contacts impossible à charger.'; });
  }

  function contactRow(c) {
    var tr = el('tr');
    var who = el('td');
    who.appendChild(el('div', c.email));
    if (c.name) who.appendChild(el('div', c.name, { 'class': 'hint' }));
    if (c.stopped) who.appendChild(el('div', 'désinscrit', { 'class': 'hint', style: 'color:#b3261e;' }));
    tr.appendChild(who);
    tr.appendChild(el('td', c.sources.length ? c.sources.join(', ') : '—', { 'class': 'hint' }));

    var phoneCell = el('td');
    var phone = document.createElement('input');
    phone.type = 'tel';
    phone.value = c.phone || '';
    phone.placeholder = '06 …';
    phone.style.cssText = 'width:140px;';
    phone.autocomplete = 'off';
    phoneCell.appendChild(phone);
    tr.appendChild(phoneCell);

    var boxes = {};
    ['inapp', 'sms', 'wa'].forEach(function (k) {
      var td = el('td', null, { align: 'center' });
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = !!c[k];
      if (k === 'inapp' && !c.identifiable) {
        cb.disabled = true;
        td.title = 'Pas de compte sur le site : l\'annonce dans l\'app ne peut pas être montrée.';
      }
      boxes[k] = cb;
      td.appendChild(cb);
      tr.appendChild(td);
    });

    var saveCell = el('td');
    var save = el('button', 'Enregistrer', { type: 'button', 'class': 'btn-small' });
    save.addEventListener('click', function () {
      save.disabled = true;
      postTo(contactsApi, { action: 'save', email: c.email, phone: phone.value, inapp: boxes.inapp.checked, sms: boxes.sms.checked, wa: boxes.wa.checked })
        .then(function (r) {
          contactsMsg.textContent = (r.error || r.message) + ' (' + c.email + ')';
          save.disabled = false;
          if (!r.error) c.phone = phone.value;
        })
        .catch(function () { contactsMsg.textContent = 'Enregistrement impossible, réessayez.'; save.disabled = false; });
    });
    saveCell.appendChild(save);
    tr.appendChild(saveCell);
    return tr;
  }

  function postTo(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }

  function load() {
    return fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var tb = document.querySelector('#aco-table tbody');
        tb.textContent = '';
        if (d.error) { msg.textContent = d.error; return; }
        if (d.notice_sources) sourceLabels = d.notice_sources;
        if (d.channel_labels) channelLabels = d.channel_labels;
        if (d.notice_batch) batch = d.notice_batch;
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
          if (o.notices && o.notices.channels) {
            var reached = Math.max.apply(null, channelOrder.map(function (k) {
              return (o.notices.channels[k] || {}).already || 0;
            }).concat([0]));
            if (reached > 0) stateCell.appendChild(el('div', reached + ' prévenu(s)', { 'class': 'hint' }));
            if ((o.notices.opened || 0) > 0) {
              stateCell.appendChild(el('div', o.notices.opened + ' e-mail(s) ouvert(s)', { 'class': 'hint' }));
            }
          }
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

  // Vente flash : la fin se cale à 24 h après le début (modifiable).
  var kindSelect = form.querySelector('[name="kind"]');
  if (kindSelect) kindSelect.addEventListener('change', function () {
    if (kindSelect.value !== 'flash') return;
    var startField = form.querySelector('[name="starts_at"]');
    var endField = form.querySelector('[name="ends_at"]');
    var start = new Date(startField.value);
    if (isNaN(start.getTime())) return;
    var end = new Date(start.getTime() + 24 * 3600 * 1000);
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    endField.value = end.getFullYear() + '-' + pad(end.getMonth() + 1) + '-' + pad(end.getDate())
      + 'T' + pad(end.getHours()) + ':' + pad(end.getMinutes());
  });

  load();
  loadContacts();
})();
