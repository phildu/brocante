(function () {
  'use strict';

  var root = document.getElementById('ml-page');
  if (!root || root.dataset.entitled !== '1') return;

  var api = root.dataset.api;
  var csrf = root.dataset.csrf;
  var preview = root.dataset.preview || '/index.php';
  var select = document.getElementById('ml-lang');
  var runBtn = document.getElementById('ml-run');
  var stopBtn = document.getElementById('ml-stop');
  var progress = document.getElementById('ml-progress');
  var statusEl = document.getElementById('ml-status');
  var logEl = document.getElementById('ml-log');
  var estimateEl = document.getElementById('ml-estimate');
  var tbody = document.querySelector('#ml-table tbody');
  var state = { languages: [], catalog: {}, usd_eur: 0.92, pages: 0, batch: 40, ai_gate: null };
  var stopped = false;

  function log(line) {
    logEl.textContent += (logEl.textContent ? '\n' : '') + line;
    logEl.scrollTop = logEl.scrollHeight;
  }

  function fmtEur(usd) {
    var value = (Number(usd) || 0) * (Number(state.usd_eur) || 0.92);
    return value > 0 && value < 0.005 ? '< 0,01 €' : value.toFixed(2).replace('.', ',') + ' €';
  }

  function cell(text) {
    var td = document.createElement('td');
    td.textContent = text;
    return td;
  }

  function button(label, handler) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn-small';
    b.textContent = label;
    b.addEventListener('click', handler);
    return b;
  }

  async function request(action, payload) {
    var body = Object.assign({ action: action }, payload || {});
    var res = await fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    });
    var data = null;
    try { data = await res.json(); } catch (e) { throw new Error('Réponse illisible (HTTP ' + res.status + ').'); }
    if (!res.ok || data.error || data.ok === false) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  }

  async function load() {
    var res = await fetch(api, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    var data = await res.json();
    if (data.error) { statusEl.textContent = data.error; return; }
    state = data;
    render();
  }

  function render() {
    var current = select.value;
    select.textContent = '';
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— choisir une langue —';
    select.appendChild(placeholder);
    var existing = {};
    state.languages.forEach(function (l) { existing[l.code] = l; });
    Object.keys(state.catalog).sort(function (a, b) {
      return state.catalog[a].localeCompare(state.catalog[b], 'fr');
    }).forEach(function (code) {
      var option = document.createElement('option');
      option.value = code;
      option.textContent = state.catalog[code] + ' (' + code.toUpperCase() + ')' + (existing[code] ? ' — déjà générée' : '');
      select.appendChild(option);
    });
    if (current) select.value = current;

    runBtn.disabled = !!state.ai_gate;
    estimateEl.textContent = state.ai_gate
      ? state.ai_gate
      : state.pages + ' page(s) à parcourir, lots de ' + state.batch + ' textes. Le coût IA réel s\'affiche au fil de la génération et se déduit de votre crédit IA.';

    renderTable();
  }

  function renderTable() {
    tbody.textContent = '';
    if (!state.languages.length) {
      var empty = document.createElement('tr');
      var td = cell('Aucune version générée pour le moment.');
      td.colSpan = 6;
      td.className = 'hint';
      empty.appendChild(td);
      tbody.appendChild(empty);
      return;
    }
    state.languages.forEach(function (l) {
      var tr = document.createElement('tr');

      tr.appendChild(cell(l.label + ' (' + l.code.toUpperCase() + ')'));

      var stateLabel = l.status === 'published' ? 'Publiée' : 'Brouillon';
      if (l.work) stateLabel = 'En cours — ' + l.work.pages_done + '/' + (l.work.total_pages || state.pages) + ' page(s), ' + l.work.queued + ' texte(s) en attente';
      tr.appendChild(cell(stateLabel));

      tr.appendChild(cell(String(l.strings)));
      tr.appendChild(cell(fmtEur(l.cost)));
      tr.appendChild(cell(l.generated_at ? l.generated_at.replace(' ', ' à ') : '—'));

      var actions = document.createElement('td');
      actions.style.whiteSpace = 'nowrap';
      if (l.has_file) {
        var view = document.createElement('a');
        view.className = 'btn-small';
        view.href = preview + '?lang=' + encodeURIComponent(l.code);
        view.target = '_blank';
        view.rel = 'noopener';
        view.textContent = 'Voir';
        view.style.marginRight = '6px';
        actions.appendChild(view);
      }
      if (l.work) {
        actions.appendChild(button('Reprendre', function () {
          select.value = l.code;
          generate();
        }));
      }
      if (l.has_file) {
        actions.appendChild(button(l.status === 'published' ? 'Dépublier' : 'Publier', function () {
          act(l.status === 'published' ? 'unpublish' : 'publish', l.code, l.label);
        }));
      }
      actions.appendChild(button('Supprimer', function () {
        act('delete', l.code, l.label);
      }));
      tr.appendChild(actions);
      tbody.appendChild(tr);
    });
  }

  async function act(action, code, label) {
    if (action === 'delete' && !confirm('Supprimer définitivement la version ' + label + ' (' + code.toUpperCase() + ') ?')) return;
    try {
      var result = await request(action, { code: code });
      statusEl.textContent = result.message || 'Fait.';
      await load();
    } catch (e) {
      statusEl.textContent = e.message;
    }
  }

  async function drain(code) {
    var guard = 0;
    while (true) {
      if (stopped) throw new Error('Arrêt demandé. Cliquez sur « Générer » pour reprendre.');
      var tr = await request('translate', { code: code });
      statusEl.textContent = 'Traduction… ' + tr.progress.strings + ' texte(s) figé(s), ' + tr.remaining
        + ' en attente — ' + fmtEur(tr.progress.cost) + ' consommés.';
      if (!tr.remaining) return;
      if (++guard > 500) throw new Error('La traduction ne progresse plus : arrêtez puis reprenez la génération.');
    }
  }

  async function generate() {
    var code = select.value;
    if (!code) { statusEl.textContent = 'Choisissez une langue.'; return; }
    var row = null;
    state.languages.forEach(function (l) { if (l.code === code) row = l; });

    var restart = true;
    if (row && row.work) {
      if (confirm('Une génération est en cours pour cette langue. Reprendre là où elle s\'est arrêtée ?\n\n« Annuler » recommence depuis le début.')) restart = false;
    } else if (row && row.has_file) {
      if (!confirm('Une version existe déjà pour cette langue. La nouvelle traduction remplacera ses textes (elle reste en ligne pendant la génération). Continuer ?')) return;
    }

    stopped = false;
    runBtn.disabled = true;
    stopBtn.hidden = false;
    logEl.textContent = '';
    statusEl.textContent = 'Préparation…';

    try {
      var start = await request('start', { code: code, restart: restart });
      var total = start.total_pages;
      var from = restart ? 0 : (start.progress.pages_done || 0);
      progress.max = Math.max(1, total);
      progress.value = from;
      log((start.fresh ? 'Nouvelle génération' : 'Reprise') + ' — ' + total + ' page(s), langue ' + code.toUpperCase() + '.');

      for (var i = from; i < total; i++) {
        if (stopped) throw new Error('Arrêt demandé. Cliquez sur « Générer » pour reprendre.');
        var ex = await request('extract', { code: code, index: i });
        progress.value = i + 1;
        log((i + 1) + '/' + total + ' — ' + ex.page + (ex.fetched ? ' : ' + ex.new + ' nouveau(x) texte(s).' : ' : ' + ex.error));
        if (!ex.fetched) continue;
        statusEl.textContent = 'Lecture de la page « ' + ex.page + ' »…';
        await drain(code);
      }
      await drain(code);
      var finish = await request('finish', { code: code });
      log(finish.strings + ' texte(s) écrits en dur (' + finish.chars + ' caractères).');
      statusEl.textContent = finish.message;
      await load();
    } catch (e) {
      statusEl.textContent = e.message || 'Erreur.';
      log('Erreur : ' + statusEl.textContent);
      await load();
    } finally {
      runBtn.disabled = false;
      stopBtn.hidden = true;
    }
  }

  runBtn.addEventListener('click', generate);
  stopBtn.addEventListener('click', function () { stopped = true; });
  load().catch(function (e) { statusEl.textContent = e.message || 'Chargement impossible.'; });
})();
