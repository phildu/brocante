(function () {
  var root = document.getElementById('aco-camp');
  if (!root || root.dataset.entitled !== '1') return;
  var api = root.dataset.api, csrf = root.dataset.csrf;
  var sel = document.getElementById('ac-op');
  var msg = document.getElementById('ac-msg');
  var summary = document.getElementById('ac-summary');
  var tbody = document.querySelector('#ac-table tbody');
  var empty = document.getElementById('ac-empty');
  var formBox = document.getElementById('ac-form-box');
  var form = document.getElementById('ac-form');
  var state = { op: null, data: null };
  var opStatus = { scheduled: 'programmée', running: 'en cours', ended: 'terminée', canceled: 'annulée' };

  function el(tag, text, attrs) {
    var e = document.createElement(tag);
    if (text != null) e.textContent = text;
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
  }
  function post(body) {
    body.operation = state.op;
    return fetch(api, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); });
  }
  function get(query) {
    return fetch(api + (query ? '&' + query : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); });
  }
  function when(ts) {
    return new Date(ts * 1000).toLocaleString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }
  function offsetText(s) {
    var m = Math.abs(s.offset_min);
    if (m === 0) return (s.anchor === 'end' ? 'À la fin' : 'Au début');
    var d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60), mi = m % 60, parts = [];
    if (d) parts.push(d + ' j');
    if (h) parts.push(h + ' h');
    if (mi) parts.push(mi + ' min');
    return parts.join(' ') + (s.offset_min < 0 ? ' avant' : ' après') + (s.anchor === 'end' ? ' la fin' : ' le début');
  }
  function say(text, error) {
    msg.textContent = text || '';
    msg.style.color = error ? '#b00020' : '';
  }

  function loadOps(selectId) {
    return get('').then(function (d) {
      sel.textContent = '';
      var ops = d.operations || [];
      if (!ops.length) {
        summary.textContent = "Aucune opération : créez d'abord une braderie, une vente flash, une foire ou des soldes.";
        document.getElementById('ac-generate').disabled = true;
        document.getElementById('ac-add').disabled = true;
        return;
      }
      ops.forEach(function (o) {
        var t = o.name + ' — ' + o.period + ' (' + (opStatus[o.status] || o.status) + (o.steps ? ', ' + o.steps + ' étape(s)' : '') + ')';
        sel.appendChild(el('option', t, { value: o.id }));
      });
      var pick = selectId || Number(root.dataset.op) || 0;
      if (!ops.some(function (o) { return o.id === pick; })) {
        var live = ops.filter(function (o) { return o.status === 'scheduled' || o.status === 'running'; })[0];
        pick = (live || ops[0]).id;
      }
      sel.value = String(pick);
      state.op = pick;
      return loadSteps();
    });
  }

  function actionBtn(label, fn) {
    var b = el('button', label, { type: 'button', 'class': 'btn-small', style: 'margin-right:6px;' });
    b.addEventListener('click', fn);
    return b;
  }
  function run(body, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return;
    say('En cours…');
    post(body).then(function (r) {
      say(r.error || r.message || 'Fait.', !!r.error);
      loadSteps();
    });
  }

  function loadSteps() {
    return get('operation=' + state.op).then(function (d) {
      if (d.error) { say(d.error, true); return; }
      state.data = d;
      var o = d.operation;
      var open = o.status === 'scheduled' || o.status === 'running';
      summary.textContent = o.kind_label + ' · ' + o.period + ' · ' + o.discount_label + ' · ' + (opStatus[o.status] || o.status)
        + (open ? '' : ' — plus aucun envoi possible pour cette opération.');
      document.getElementById('ac-generate').disabled = !open || d.steps.length > 0;
      document.getElementById('ac-add').disabled = !open;
      tbody.textContent = '';
      empty.hidden = d.steps.length > 0;
      d.steps.forEach(function (s) { tbody.appendChild(stepRow(s, d, open)); });
    });
  }

  function stepRow(s, d, open) {
    var tr = el('tr');
    tr.style.borderTop = '1px solid var(--line)';
    tr.style.verticalAlign = 'top';
    var c1 = el('td');
    c1.appendChild(el('strong', s.title));
    c1.appendChild(el('div', s.preview_subject, { 'class': 'hint' }));
    var details = el('details');
    details.appendChild(el('summary', 'Voir le message', { 'class': 'hint' }));
    var pre = el('pre', s.preview_body, { 'class': 'hint' });
    pre.style.cssText = 'white-space:pre-wrap;margin:6px 0 0;font:inherit;max-width:46ch;';
    details.appendChild(pre);
    c1.appendChild(details);
    tr.appendChild(c1);

    var c2 = el('td');
    c2.appendChild(el('div', when(s.when)));
    c2.appendChild(el('div', offsetText(s), { 'class': 'hint' }));
    tr.appendChild(c2);

    var c3 = el('td');
    s.channel_list.forEach(function (ch) {
      var line = el('div', d.channel_labels[ch] || ch);
      var st = s.stats.channels[ch];
      if (st) {
        var bits = [];
        if (st.sent) bits.push(st.sent + ' envoyé(s)');
        if (st.failed) bits.push(st.failed + ' échec(s)');
        if (st.eligible && open) bits.push(st.eligible + ' en attente');
        if (!st.ready) bits.push('non configuré');
        if (bits.length) line.appendChild(el('span', ' · ' + bits.join(' · '), { 'class': 'hint' }));
      }
      c3.appendChild(line);
    });
    if (s.stats.opened) c3.appendChild(el('div', s.stats.opened + ' e-mail(s) ouvert(s)', { 'class': 'hint' }));
    tr.appendChild(c3);

    var c4 = el('td');
    var label = d.status_labels[s.status] || s.status;
    if (Number(s.enabled) === 0 && (s.status === 'planned' || s.status === 'skipped')) label = 'en pause';
    c4.appendChild(el('div', label));
    if (s.detail) c4.appendChild(el('div', s.detail, { 'class': 'hint' }));
    if (s.status === 'to_publish') {
      var box = el('div');
      var ta = el('textarea', s.preview_body.split('\n\nVous recevez')[0], { rows: '4', readonly: 'readonly', style: 'width:100%;margin-top:6px;' });
      box.appendChild(ta);
      var copy = actionBtn('Copier le texte', function () {
        ta.select();
        if (navigator.clipboard) navigator.clipboard.writeText(ta.value);
        else document.execCommand('copy');
      });
      box.appendChild(copy);
      if (d.social_url) box.appendChild(el('a', 'Ouvrir la publication', { href: d.social_url, 'class': 'hint' }));
      c4.appendChild(box);
    }
    tr.appendChild(c4);

    var c5 = el('td');
    c5.style.whiteSpace = 'nowrap';
    var started = s.status === 'done' || s.status === 'sending' || s.status === 'to_publish';
    if (open && s.status !== 'done') {
      c5.appendChild(actionBtn(s.status === 'sending' ? 'Continuer' : 'Envoyer maintenant', function () {
        run({ action: 'send', id: s.id }, 'Envoyer « ' + s.title + ' » tout de suite aux contacts consentants ?');
      }));
    }
    if (open && s.stats.failed > 0) c5.appendChild(actionBtn('Relancer les échecs', function () { run({ action: 'retry', id: s.id }); }));
    if (s.status === 'to_publish') c5.appendChild(actionBtn('Marquer publié', function () { run({ action: 'published', id: s.id }); }));
    if (open && !started) {
      c5.appendChild(actionBtn('Modifier', function () { editStep(s); }));
      c5.appendChild(actionBtn(Number(s.enabled) ? 'Pause' : 'Activer', function () { run({ action: 'toggle', id: s.id }); }));
    }
    if (open && s.status !== 'sending') {
      c5.appendChild(actionBtn('Supprimer', function () {
        run({ action: 'delete', id: s.id }, 'Supprimer l\'étape « ' + s.title + ' » ?');
      }));
    }
    tr.appendChild(c5);
    return tr;
  }

  function buildChannels(selected) {
    var box = document.getElementById('ac-channels');
    box.textContent = '';
    Object.keys(state.data.channel_labels).forEach(function (k) {
      var l = el('label', null, { style: 'display:flex;gap:6px;align-items:center;font-weight:400;' });
      var cb = el('input', null, { type: 'checkbox', name: 'channels[]', value: k });
      cb.checked = selected.indexOf(k) !== -1;
      l.appendChild(cb);
      l.appendChild(document.createTextNode(' ' + state.data.channel_labels[k]));
      box.appendChild(l);
    });
    var f = state.data.fields, txt = [];
    Object.keys(f).forEach(function (k) { txt.push(k + ' = ' + f[k]); });
    document.getElementById('ac-fields').textContent = 'Champs utilisables dans l\'objet et le message : ' + txt.join(' · ') + '.';
  }
  function openForm(title, s) {
    document.getElementById('ac-form-title').textContent = title;
    var m = s ? Math.abs(s.offset_min) : 1440;
    form.elements.id.value = s ? s.id : 0;
    form.elements.title.value = s ? s.title : '';
    form.elements.days.value = Math.floor(m / 1440);
    form.elements.hours.value = Math.floor((m % 1440) / 60);
    form.elements.minutes.value = m % 60;
    form.elements.direction.value = s && s.offset_min > 0 ? 'after' : 'before';
    form.elements.anchor.value = s ? s.anchor : 'start';
    form.elements.subject.value = s ? s.subject : '';
    form.elements.body.value = s ? s.body : '';
    form.elements.enabled.checked = s ? Number(s.enabled) === 1 : true;
    buildChannels(s ? s.channel_list : ['mail', 'inapp']);
    formBox.hidden = false;
    formBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function editStep(s) { openForm('Modifier l\'étape', s); }

  sel.addEventListener('change', function () { state.op = Number(sel.value); formBox.hidden = true; say(''); loadSteps(); });
  document.getElementById('ac-add').addEventListener('click', function () { openForm('Nouvelle étape', null); });
  document.getElementById('ac-cancel').addEventListener('click', function () { formBox.hidden = true; });
  document.getElementById('ac-generate').addEventListener('click', function () { run({ action: 'generate' }); });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var d = { action: 'save', channels: [] };
    new FormData(form).forEach(function (v, k) {
      if (k === 'channels[]') d.channels.push(v); else d[k] = v;
    });
    d.enabled = form.elements.enabled.checked ? 1 : 0;
    say('Enregistrement…');
    post(d).then(function (r) {
      say(r.error || r.message, !!r.error);
      if (!r.error) { formBox.hidden = true; loadSteps(); }
    });
  });

  loadOps().catch(function () { say('Chargement impossible, rechargez la page.', true); });
})();
