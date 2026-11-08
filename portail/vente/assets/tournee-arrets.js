/* Modale « Choisir les arrêts » de la page Tournées : tri, filtres, cases à cocher, compteur.
   Les commerces arrivent en une fois (tournee-arrets.php) ; tri, filtres et pagination se font ici, sans rechargement.
   Les règles de filtrage reprennent celles de _tournee.php (fv_tournee_match) : à modifier des deux côtés. */
(function () {
  'use strict';
  var dlg = document.getElementById('fv-ta');
  if (!dlg) return;

  var PAGE = 100;
  var url = dlg.getAttribute('data-src');
  var shortest = dlg.getAttribute('data-shortest') === '1';
  var $ = function (sel) { return dlg.querySelector(sel); };
  var el = {
    form: $('[data-ta-filters]'), q: $('[name="q"]'), ville: $('[name="ville"]'), rue: $('[name="rue"]'),
    statut: $('[name="statut"]'), zone: $('[name="zone"]'), cat: $('[name="cat"]'), prio: $('[name="prio"]'),
    owner: $('[name="owner"]'), due: $('[name="due"]'), gps: $('[name="gps"]'), tous: $('[name="tous"]'), only: $('[name="only"]'),
    sort: $('[name="sort"]'), body: $('[data-ta-body]'), table: $('[data-ta-table]'), scroll: $('[data-ta-scroll]'),
    count: $('[data-ta-count]'), msg: $('[data-ta-msg]'), pager: $('[data-ta-pager]'), prev: $('[data-ta-prev]'), next: $('[data-ta-next]'), page: $('[data-ta-page]'),
    add: $('[data-ta-add]'), addN: $('[data-ta-add-n]'), all: $('[data-ta-all]'), allN: $('[data-ta-all-n]'),
    checkAll: $('[data-ta-check]'), uncheckAll: $('[data-ta-uncheck]'), reset: $('[data-ta-reset]'),
    cities: document.getElementById('fv-ta-cities'), streets: document.getElementById('fv-ta-streets'),
    filters: $('[data-ta-details]')
  };

  var rows = [], byId = {}, statuses = {}, statusOrder = {}, owners = {}, hasOwners = false, max = 500, today = '', week = '';
  var selected = {};            // id => true (survit aux filtres et aux pages)
  var filtered = [], page = 0, loaded = false, loading = false, opener = null;
  var sort = { key: 'c', dir: 1 };
  var collator = (window.Intl && Intl.Collator) ? new Intl.Collator('fr', { numeric: true, sensitivity: 'base' }) : null;

  /* Pré-sélection : les arrêts déjà dans la tournée */
  (dlg.getAttribute('data-selected') || '').split(',').forEach(function (id) { id = parseInt(id, 10); if (id > 0) selected[id] = true; });

  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fold(s) {
    s = String(s == null ? '' : s);
    try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { /* navigateur sans normalize */ }
    return s.toLowerCase().trim();
  }
  function cmp(a, b) { return collator ? collator.compare(a, b) : (a < b ? -1 : a > b ? 1 : 0); }
  function plural(n, one, many) { return n + ' ' + (n > 1 ? many : one); }
  function fmtDate(d) { return d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '—'; }
  function ids() { return Object.keys(selected).map(Number); }

  /* ── Filtres ── */
  function filters() {
    return {
      q: el.q.value.trim(), ville: el.ville.value.trim(), rue: el.rue.value.trim(), statut: el.statut.value, zone: el.zone.value,
      cat: el.cat.value, prio: parseInt(el.prio.value, 10) || 0, owner: el.owner ? parseInt(el.owner.value, 10) || 0 : 0,
      due: el.due.value, gps: el.gps.value, tous: el.tous.checked
    };
  }
  function prepare(r) {
    r._h = fold(r.b + ' ' + r.n + ' ' + r.k + ' ' + r.l);
    r._c = fold(r.c);
    r._s = fold(r.s);
    r._k = fold(r.k);
    return r;
  }
  function matcher(f) {
    var words = fold(f.q).split(/\s+/).filter(Boolean), ville = fold(f.ville), rue = fold(f.rue), cat = fold(f.cat);
    return function (r) {
      if (f.statut) { if (r.st !== f.statut) return false; }
      else if (!f.tous && (r.st === 'perdu' || r.st === 'boutique_creee')) return false;
      for (var i = 0; i < words.length; i++) if (r._h.indexOf(words[i]) < 0) return false;
      if (ville && r._c.indexOf(ville) < 0) return false;
      if (rue && r._s.indexOf(rue) < 0) return false;
      if (f.zone && r.z !== f.zone) return false;
      if (f.cat) { if (f.cat === '~vide' ? r._k !== '' : r._k !== cat) return false; }
      if (f.prio && r.pr !== f.prio) return false;
      if (f.owner && r.o !== f.owner) return false;
      if (f.due === 'today' && (!r.d || r.d > today)) return false;
      if (f.due === 'week' && (!r.d || r.d > week)) return false;
      if (f.due === 'none' && r.d) return false;
      if (f.gps === 'oui' && !r.g) return false;
      if (f.gps === 'non' && r.g) return false;
      return true;
    };
  }
  function params(f) {
    var out = [];
    ['q', 'ville', 'rue', 'statut', 'zone', 'cat', 'due', 'gps'].forEach(function (k) { if (f[k]) out.push(k + '=' + encodeURIComponent(f[k])); });
    if (f.prio) out.push('prio=' + f.prio);
    if (f.owner) out.push('owner=' + f.owner);
    if (f.tous) out.push('tous=1');
    return out;
  }

  /* ── Tri ── */
  var sorters = {
    b: function (a, b) { return cmp(a.b, b.b); },
    c: function (a, b) { return cmp(a.c, b.c); },
    s: function (a, b) { return cmp(a.s, b.s) || cmp(a.a, b.a); },
    st: function (a, b) { return (statusOrder[a.st] || 0) - (statusOrder[b.st] || 0); },
    z: function (a, b) { return cmp(a.z, b.z); },
    k: function (a, b) { return cmp(a.k, b.k); },
    pr: function (a, b) { return a.pr - b.pr; },
    o: function (a, b) { return cmp(owners[a.o] || '', owners[b.o] || ''); },
    d: function (a, b) { return (a.d || '9999') < (b.d || '9999') ? -1 : (a.d || '9999') > (b.d || '9999') ? 1 : 0; },
    g: function (a, b) { return a.g - b.g; }
  };
  function sortRows() {
    var main = sorters[sort.key] || sorters.c, dir = sort.dir;
    filtered.sort(function (a, b) {
      return dir * main(a, b) || cmp(a.c, b.c) || cmp(a.s, b.s) || cmp(a.a, b.a) || cmp(a.b, b.b);
    });
  }

  /* ── Affichage ── */
  function renderHead() {
    dlg.querySelectorAll('[data-sort]').forEach(function (btn) {
      var on = btn.getAttribute('data-sort') === sort.key, th = btn.closest('th');
      if (th) th.setAttribute('aria-sort', on ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none');
      var mark = btn.querySelector('.fv-ta-mark');
      if (mark) mark.textContent = on ? (sort.dir > 0 ? '▲' : '▼') : '';
    });
    var opt = el.sort.querySelector('option[value="' + sort.key + '"]');
    if (opt) el.sort.value = sort.key;
    var dirBtn = $('[data-ta-dir]');
    if (dirBtn) { dirBtn.textContent = sort.dir > 0 ? 'A → Z' : 'Z → A'; dirBtn.setAttribute('aria-label', 'Sens du tri : ' + (sort.dir > 0 ? 'croissant' : 'décroissant')); }
  }
  function renderBody() {
    var start = page * PAGE, slice = filtered.slice(start, start + PAGE), html = [];
    slice.forEach(function (r) {
      var on = !!selected[r.id];
      html.push(
        '<tr data-id="' + r.id + '" class="fv-ta-row' + (on ? ' is-on' : '') + '">' +
        '<td class="c-check"><input type="checkbox" data-ta-item value="' + r.id + '"' + (on ? ' checked' : '') + ' aria-label="Inclure ' + esc(r.b) + '"></td>' +
        '<td class="c-main"><b>' + esc(r.b) + '</b><span class="sub">' + esc([r.n, r.k].filter(Boolean).join(' · ')) + '</span></td>' +
        '<td class="c-city">' + esc(r.c || '—') + '</td>' +
        '<td class="c-street">' + esc(r.a || '—') + '</td>' +
        '<td class="c-status"><span class="fv-badge" data-s="' + esc(r.st) + '">' + esc(statuses[r.st] || r.st) + '</span></td>' +
        '<td class="c-zone">' + esc(r.z || '—') + '</td>' +
        '<td class="c-prio"><span title="Priorité ' + r.pr + '/3">' + new Array(r.pr + 1).join('●') + '</span></td>' +
        (hasOwners ? '<td class="c-owner">' + esc(owners[r.o] || '—') + '</td>' : '') +
        '<td class="c-due' + (r.d && r.d < today ? ' is-late' : '') + '">' + esc(fmtDate(r.d)) + '</td>' +
        '<td class="c-gps">' + (r.g ? '<span title="Position connue">✓</span>' : '<span class="sub" title="Position pas encore calculée">—</span>') + '</td>' +
        '</tr>');
    });
    el.body.innerHTML = html.join('');
    var pages = Math.max(1, Math.ceil(filtered.length / PAGE));
    el.pager.hidden = pages < 2;
    el.page.textContent = 'Page ' + (page + 1) + ' sur ' + pages;
    el.prev.disabled = page === 0;
    el.next.disabled = page >= pages - 1;
    el.msg.hidden = filtered.length > 0;
    el.table.hidden = filtered.length === 0;
  }
  function renderCounts() {
    var n = ids().length, shown = filtered.length;
    el.count.textContent = plural(n, 'commerce sélectionné', 'commerces sélectionnés') + ' · ' + plural(shown, 'résultat', 'résultats') + ' sur ' + rows.length;
    el.addN.textContent = n;
    el.add.disabled = n === 0 || n > max;
    el.add.title = n > max ? 'Au plus ' + max + ' arrêts : réduisez la sélection ou prenez « toute la base filtrée ».' : '';
    $('[data-ta-over]').hidden = n <= max;
    $('[data-ta-over]').textContent = 'Trop d’arrêts cochés (' + n + ', au plus ' + max + ') : décochez-en, ou utilisez « Toute la base filtrée ».';
    el.allN.textContent = shown;
    el.all.disabled = shown === 0;
    el.checkAll.disabled = shown === 0;
    el.uncheckAll.disabled = shown === 0;
  }
  function refreshStreets() {
    var ville = fold(el.ville.value), seen = {}, list = [];
    rows.forEach(function (r) {
      if (!r.s || (ville && r._c.indexOf(ville) < 0)) return;
      if (!seen[r._s]) { seen[r._s] = true; list.push(r.s); }
    });
    list.sort(cmp);
    el.streets.innerHTML = list.slice(0, 300).map(function (s) { return '<option value="' + esc(s) + '">'; }).join('');
  }

  function apply(resetPage) {
    var f = filters(), test = matcher(f), only = el.only.checked;
    filtered = rows.filter(function (r) { return test(r) && (!only || selected[r.id]); });
    sortRows();
    if (resetPage) page = 0;
    page = Math.min(page, Math.max(0, Math.ceil(filtered.length / PAGE) - 1));
    renderHead();
    renderBody();
    renderCounts();
  }

  /* ── Chargement ── */
  function fill(select, items, keep) {
    var first = select.options[0];
    select.innerHTML = '';
    select.appendChild(first);
    items.forEach(function (it) {
      var o = document.createElement('option');
      o.value = it[0]; o.textContent = it[1];
      select.appendChild(o);
    });
    if (keep) select.value = keep;
  }
  function setup(data) {
    rows = data.rows.map(prepare);
    rows.forEach(function (r) { byId[r.id] = r; });
    statuses = data.statuses || {};
    Object.keys(statuses).forEach(function (k, i) { statusOrder[k] = i; });
    today = data.today; week = data.week; max = data.max || 500;
    (data.owners || []).forEach(function (o) { owners[o.id] = o.name; });
    hasOwners = (data.owners || []).length > 0;
    dlg.classList.toggle('has-owners', hasOwners);
    // Les arrêts déjà dans la tournée mais plus visibles (autre périmètre) ne sont pas comptés
    Object.keys(selected).forEach(function (id) { if (!byId[id]) delete selected[id]; });

    fill(el.statut, Object.keys(statuses).map(function (k) { return [k, statuses[k]]; }));
    var zones = {}, cats = {}, cities = {};
    rows.forEach(function (r) {
      if (r.z) zones[r.z] = true;
      if (r._k && !cats[r._k]) cats[r._k] = r.k;
      if (r._c && !cities[r._c]) cities[r._c] = r.c;
    });
    fill(el.zone, Object.keys(zones).sort(cmp).map(function (z) { return [z, z]; }));
    var catList = Object.keys(cats).map(function (k) { return [cats[k], cats[k]]; }).sort(function (a, b) { return cmp(a[1], b[1]); });
    catList.push(['~vide', '(sans catégorie)']);
    fill(el.cat, catList);
    if (el.owner) fill(el.owner, (data.owners || []).map(function (o) { return [String(o.id), o.name]; }));
    el.cities.innerHTML = Object.keys(cities).map(function (k) { return cities[k]; }).sort(cmp).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');
    refreshStreets();
    loaded = true;
  }
  function load() {
    if (loaded || loading) return;
    loading = true;
    el.msg.hidden = false;
    el.msg.textContent = 'Chargement des commerces…';
    el.table.hidden = true;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (data) { setup(data); loading = false; el.msg.textContent = 'Aucun commerce ne correspond à ces filtres.'; apply(true); })
      .catch(function () {
        loading = false;
        el.msg.innerHTML = 'Impossible de charger la liste. <button type="button" class="fv-btn fv-btn-sm" data-ta-retry>Réessayer</button>';
      });
  }

  /* ── Ouverture et fermeture ── */
  function open(from) {
    opener = from || document.activeElement;
    if (typeof dlg.showModal === 'function') { if (!dlg.open) dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    document.documentElement.classList.add('fv-ta-lock');
    if (window.matchMedia && window.matchMedia('(max-width: 760px)').matches && !loaded) el.filters.removeAttribute('open');
    load();
    if (loaded) apply(false);
    window.setTimeout(function () { el.q.focus({ preventScroll: true }); }, 30);
  }
  function close() {
    if (typeof dlg.close === 'function') { if (dlg.open) dlg.close(); } else { dlg.removeAttribute('open'); onClosed(); }
  }
  function onClosed() {
    document.documentElement.classList.remove('fv-ta-lock');
    if (opener && opener.focus && document.contains(opener)) opener.focus();
    if (location.hash === '#arrets' && history.replaceState) history.replaceState(null, '', location.pathname + location.search);
  }
  dlg.addEventListener('close', onClosed);
  dlg.addEventListener('click', function (event) {
    if (event.target === dlg) close();                     // clic sur le fond
    if (event.target.closest('[data-ta-close]')) close();
    if (event.target.closest('[data-ta-retry]')) load();
  });
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-ta-open]');
    if (btn) { event.preventDefault(); open(btn); }
  });
  if (location.hash === '#arrets') open(null);

  /* ── Interactions ── */
  var timer = null;
  function again(resetPage) { if (loaded) apply(resetPage !== false); }
  el.form.addEventListener('submit', function (event) { event.preventDefault(); });
  el.form.addEventListener('input', function (event) {
    if (event.target === el.ville) refreshStreets();
    if (event.target.type === 'search' || event.target.type === 'text') {
      window.clearTimeout(timer);
      timer = window.setTimeout(again, 150);
    }
  });
  el.form.addEventListener('change', function (event) {
    if (event.target === el.sort) { sort.key = el.sort.value; again(); return; }
    again();
  });
  dlg.addEventListener('click', function (event) {
    var head = event.target.closest('[data-sort]');
    if (head) {
      var key = head.getAttribute('data-sort');
      sort = sort.key === key ? { key: key, dir: -sort.dir } : { key: key, dir: 1 };
      again();
    }
    if (event.target.closest('[data-ta-dir]')) { sort.dir = -sort.dir; again(); }
  });
  el.reset.addEventListener('click', function () {
    el.form.reset();
    el.sort.value = 'c'; sort = { key: 'c', dir: 1 };
    refreshStreets();
    again();
    el.q.focus();
  });
  el.prev.addEventListener('click', function () { page = Math.max(0, page - 1); renderBody(); el.scroll.scrollTop = 0; });
  el.next.addEventListener('click', function () { page++; renderBody(); el.scroll.scrollTop = 0; });

  function setRow(id, on) {
    if (on) selected[id] = true; else delete selected[id];
    var tr = el.body.querySelector('tr[data-id="' + id + '"]');
    if (tr) { tr.classList.toggle('is-on', on); var box = tr.querySelector('input'); if (box) box.checked = on; }
  }
  el.body.addEventListener('change', function (event) {
    var box = event.target.closest('[data-ta-item]');
    if (!box) return;
    setRow(parseInt(box.value, 10), box.checked);
    if (el.only.checked && !box.checked) { apply(false); return; }
    renderCounts();
  });
  el.body.addEventListener('click', function (event) {
    if (event.target.closest('a, input, button')) return;
    var tr = event.target.closest('tr[data-id]');
    if (!tr) return;
    var id = parseInt(tr.getAttribute('data-id'), 10);
    setRow(id, !selected[id]);
    if (el.only.checked && !selected[id]) { apply(false); return; }
    renderCounts();
  });
  el.checkAll.addEventListener('click', function () {
    filtered.forEach(function (r) { selected[r.id] = true; });
    renderBody(); renderCounts();
  });
  el.uncheckAll.addEventListener('click', function () {
    filtered.forEach(function (r) { delete selected[r.id]; });
    if (el.only.checked) { apply(true); return; }
    renderBody(); renderCounts();
  });

  /* ── Validation ── */
  el.add.addEventListener('click', function () {
    var list = ids();
    if (!list.length || list.length > max) return;
    list.sort(function (a, b) { return a - b; });
    location.href = 'tournees.php?ids=' + list.join(',') + (shortest ? '&ordre=court' : '') + '#tournee';
  });
  el.all.addEventListener('click', function () {
    var p = params(filters());
    location.href = 'tournees.php?tout=1' + (p.length ? '&' + p.join('&') : '') + (shortest ? '&ordre=court' : '') + '#tournee';
  });
})();
