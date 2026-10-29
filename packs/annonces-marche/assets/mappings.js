(function () {
  var root = document.getElementById('am-map');
  if (!root) return;
  var api = root.dataset.api, csrf = root.dataset.csrf;
  var data = null, channel = 'leboncoin', field = 'etat';
  var $ = function (id) { return document.getElementById(id); };
  var statusEl = $('am-map-status');

  function say(msg, bad) { statusEl.textContent = msg || ''; statusEl.className = 'am-status' + (bad ? '' : ' am-ok'); }
  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text != null) e.textContent = text;
    return e;
  }
  function call(body) {
    return fetch(api, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible (' + r.status + ')' }; }); });
  }
  function load() {
    return fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { data = d; render(); })
      .catch(function () { say('Chargement impossible.', true); });
  }

  function fieldKey() { return field === 'autre' ? 'autre:' + ($('am-map-autrename').value || '') : field; }
  function rowsFor() {
    return data.mappings.filter(function (m) {
      return m.channel === channel && (field === 'autre' ? m.field.indexOf('autre:') === 0 : m.field === field);
    });
  }

  function fillSelects() {
    var cs = $('am-map-channel'), fs = $('am-map-field');
    if (!cs.options.length) {
      Object.keys(data.channels).forEach(function (k) { cs.appendChild(el('option', { value: k }, data.channels[k])); });
      Object.keys(data.fields).forEach(function (k) { fs.appendChild(el('option', { value: k }, data.fields[k].label)); });
    }
    cs.value = channel; fs.value = field;
  }

  function render() {
    fillSelects();
    var meta = data.fields[field];
    $('am-map-help').textContent = meta.help;
    $('am-map-th-source').textContent = field === 'autre' ? 'Champ du site' : 'Valeur boutique';
    var isEtat = field === 'etat', isAutre = field === 'autre';
    $('am-map-source').hidden = isEtat || isAutre; $('am-map-source-etat').hidden = !isEtat;
    $('am-map-source-wrap').hidden = isAutre; $('am-map-autre-wrap').hidden = !isAutre;
    var dl = $('am-map-sug'); dl.textContent = '';
    if (!isEtat && !isAutre) (data.suggestions[field] || []).forEach(function (v) { dl.appendChild(el('option', { value: v })); });
    var se = $('am-map-source-etat'); se.textContent = '';
    if (isEtat) data.suggestions.etat.forEach(function (o) { se.appendChild(el('option', { value: o.key }, o.label)); });

    var tb = $('am-map-table').tBodies[0]; tb.textContent = '';
    var rows = rowsFor();
    if (!rows.length) {
      var tr0 = el('tr'); var td0 = el('td', { colspan: 3, 'class': 'muted' }, 'Aucune correspondance : le bot applique ses règles intégrées.');
      tr0.appendChild(td0); tb.appendChild(tr0);
    }
    rows.forEach(function (m) {
      var tr = el('tr');
      var label = m.field.indexOf('autre:') === 0 ? m.field.slice(6) : (field === 'etat' ? etatLabel(m.source_norm) : m.source_label);
      tr.appendChild(el('td', {}, label));
      tr.appendChild(el('td', {}, m.target));
      var td = el('td'); var b = el('button', { type: 'button', 'class': 'btn-small' }, 'Supprimer');
      b.addEventListener('click', function () {
        call({ action: 'delete', id: m.id }).then(function (r) { if (r.error) say(r.error, true); else load(); });
      });
      td.appendChild(b); tr.appendChild(td); tb.appendChild(tr);
    });
    renderUnknown();
  }

  function etatLabel(key) {
    var f = data.suggestions.etat.filter(function (o) { return o.key === key; })[0];
    return f ? f.label : key;
  }

  function renderUnknown() {
    var box = $('am-map-unknown'); box.textContent = '';
    var open = data.unknown.filter(function (u) { return u.status !== 'ignored'; });
    var ignored = data.unknown.length - open.length;
    $('am-map-unknown-count').textContent = String(open.length);
    if (!open.length) box.appendChild(el('p', { 'class': 'hint' }, 'Rien à renseigner pour le moment.' + (ignored ? ' (' + ignored + ' ignoré(s))' : '')));
    open.forEach(function (u) {
      var card = el('div', { 'class': 'am-actions', style: 'border-top:1px solid var(--line);padding:8px 0;' });
      var info = el('div', { style: 'flex:1 1 260px;' });
      var typeLabel = (data.fields[u.field_type] || {}).label || u.field_type;
      info.appendChild(el('strong', {}, data.channels[u.channel] + ' · ' + u.field_label));
      info.appendChild(el('div', { 'class': 'hint' }, typeLabel + (u.source_value ? ' — valeur boutique : « ' + u.source_value + ' »' : '') + ' · vu ' + u.seen + ' fois' + (u.product_ref ? ' (pièce ' + u.product_ref + ')' : '')));
      var input = el('input', { type: 'text', placeholder: 'Valeur sur le site', style: 'min-width:200px;', list: 'am-unk-' + u.id });
      var dl = el('datalist', { id: 'am-unk-' + u.id });
      (u.options || []).forEach(function (o) { dl.appendChild(el('option', { value: o })); });
      var ok = el('button', { type: 'button', 'class': 'btn-small' }, 'Enregistrer');
      ok.addEventListener('click', function () {
        if (!input.value.trim()) { say('Indiquez la valeur du site.', true); return; }
        var f = u.field_type === 'autre' ? 'autre:' + u.field_label : u.field_type;
        call({ action: 'save', channel: u.channel, field: f, source: u.source_value, target: input.value }).then(function (r) {
          if (r.error) say(r.error, true); else { say('Correspondance enregistrée.'); load(); }
        });
      });
      var ig = el('button', { type: 'button', 'class': 'btn-small' }, 'Ignorer');
      ig.addEventListener('click', function () { call({ action: 'ignore', id: u.id }).then(load); });
      [info, input, dl, ok, ig].forEach(function (n) { card.appendChild(n); });
      box.appendChild(card);
    });
  }

  $('am-map-channel').addEventListener('change', function (e) { channel = e.target.value; render(); });
  $('am-map-field').addEventListener('change', function (e) { field = e.target.value; render(); });
  $('am-map-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var src = field === 'etat' ? $('am-map-source-etat').value : $('am-map-source').value;
    if (field === 'autre' && !$('am-map-autrename').value.trim()) { say('Indiquez le nom du champ sur le site.', true); return; }
    var label = field === 'etat' ? etatLabel(src) : '';
    call({ action: 'save', channel: channel, field: fieldKey(), source: field === 'autre' ? '*' : src, source_label: label, target: $('am-map-target').value }).then(function (r) {
      if (r.error) { say(r.error, true); return; }
      say('Correspondance enregistrée.');
      $('am-map-target').value = ''; $('am-map-source').value = '';
      load();
    });
  });
  load();
})();
