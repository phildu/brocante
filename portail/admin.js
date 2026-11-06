// Interactions de l'administration du portail : tiroir mobile, thème, palette ⌘K,
// filtre des boutiques et menus « Gérer ». Aucune dépendance.

(function () {
  'use strict';

  var root = document.documentElement;

  /* ── Thème clair / sombre ─────────────────────────────────────── */
  function currentTheme() {
    var set = root.dataset.theme;
    if (set === 'light' || set === 'dark') return set;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function paintThemeButton(button) {
    var dark = currentTheme() === 'dark';
    button.setAttribute('aria-pressed', String(dark));
    button.title = dark ? 'Passer au thème clair' : 'Passer au thème sombre';
  }
  document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
    paintThemeButton(button);
    button.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      try { localStorage.setItem('portail-theme', next); } catch (error) { /* stockage indisponible */ }
      document.querySelectorAll('[data-theme-toggle]').forEach(paintThemeButton);
    });
  });

  /* ── Tiroir de navigation (mobile) ────────────────────────────── */
  var nav = document.getElementById('pnav');
  var veil = document.querySelector('[data-nav-close]');
  var burger = document.querySelector('[data-nav-open]');

  function setDrawer(open) {
    if (!nav) return;
    nav.classList.toggle('is-open', open);
    if (veil) veil.hidden = !open;
    if (burger) burger.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
  }
  if (burger) burger.addEventListener('click', function () { setDrawer(!nav.classList.contains('is-open')); });
  document.querySelectorAll('[data-nav-close]').forEach(function (element) {
    element.addEventListener('click', function () { setDrawer(false); });
  });
  if (nav) {
    nav.addEventListener('click', function (event) {
      if (event.target.closest('a')) setDrawer(false);
    });
  }
  window.addEventListener('resize', function () {
    if (window.innerWidth > 1100 && nav && nav.classList.contains('is-open')) setDrawer(false);
  });

  /* ── Filtre des boutiques (accueil) ───────────────────────────── */
  var shopFilter = document.querySelector('[data-shop-filter]');
  if (shopFilter) {
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-shop-card]'));
    var empty = document.querySelector('[data-shop-empty]');
    var counter = document.querySelector('[data-shop-count]');
    function normalize(text) {
      return (text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }
    function filterShops() {
      var query = normalize(shopFilter.value);
      var shown = 0;
      cards.forEach(function (card) {
        var hit = query === '' || normalize(card.dataset.search || '').indexOf(query) !== -1;
        card.hidden = !hit;
        if (hit) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
      if (counter) counter.textContent = shown + (shown === 1 ? ' boutique' : ' boutiques');
    }
    shopFilter.addEventListener('input', filterShops);
    filterShops();
  }

  /* ── Menus « Gérer » : un seul ouvert, fermeture au clic extérieur ── */
  document.querySelectorAll('details.shop-more').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (!details.open) return;
      document.querySelectorAll('details.shop-more[open]').forEach(function (other) {
        if (other !== details) other.open = false;
      });
    });
  });
  document.addEventListener('click', function (event) {
    document.querySelectorAll('details.shop-more[open]').forEach(function (details) {
      if (!details.contains(event.target)) details.open = false;
    });
  });

  /* ── Recherche globale (palette) ──────────────────────────────── */
  var dialog = document.getElementById('psearch');
  var source = document.getElementById('psearch-data');
  var input = document.getElementById('psearch-input');
  var results = document.getElementById('psearch-results');
  if (!dialog || !source || !input || !results) return;

  var baseItems = [];
  try { baseItems = JSON.parse(source.textContent || '[]'); } catch (error) { baseItems = []; }

  function normalizeText(text) {
    return (text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }
  function prepare(item) {
    item.search = normalizeText(item.label + ' ' + item.hint + ' ' + item.group);
    return item;
  }
  baseItems.forEach(prepare);

  var remoteItems = [];
  var remoteNeedle = null;
  var remotePending = false;
  var remoteTimer = 0;
  var remoteSeq = 0;

  var selected = 0;
  var rendered = [];

  function mergeItems(first, second) {
    var seen = Object.create(null);
    var out = [];
    first.concat(second).forEach(function (item) {
      var key = item.href + '|' + item.label;
      if (seen[key]) {
        if ((item.hint || '').length > (seen[key].hint || '').length) seen[key].hint = item.hint;
        return;
      }
      seen[key] = item;
      out.push(item);
    });
    return out;
  }

  function groupItems(list) {
    var order = [];
    var groups = Object.create(null);
    list.forEach(function (item) {
      if (!groups[item.group]) { groups[item.group] = []; order.push(item.group); }
      groups[item.group].push(item);
    });
    return order.map(function (name) { return [name, groups[name]]; });
  }

  function render(query) {
    var needle = normalizeText(query);
    var staticMatches = needle === ''
      ? baseItems
      : baseItems.filter(function (item) { return item.search.indexOf(needle) !== -1; });
    var contentMatches = needle !== '' && remoteNeedle === needle ? remoteItems : [];
    var matches = mergeItems(staticMatches, contentMatches).slice(0, 40);
    rendered = matches;
    selected = 0;
    results.innerHTML = '';
    if (!matches.length) {
      var empty = document.createElement('p');
      empty.className = 'palette-empty';
      empty.textContent = needle !== '' && remotePending
        ? 'Recherche dans le contenu…'
        : 'Aucun résultat pour « ' + query + ' ».';
      results.appendChild(empty);
      return;
    }
    var index = 0;
    groupItems(matches).forEach(function (pair) {
      var group = document.createElement('p');
      group.className = 'palette-group';
      group.textContent = pair[0];
      results.appendChild(group);
      pair[1].forEach(function (item) {
        var link = document.createElement('a');
        link.className = 'palette-item';
        link.href = item.href;
        link.setAttribute('role', 'option');
        link.setAttribute('aria-selected', String(index === selected));
        link.dataset.index = String(index);
        var mark = document.createElement('span');
        mark.className = 'pitem-dot';
        var label = document.createElement('span');
        label.className = 'pitem-label';
        label.textContent = item.label;
        var hint = document.createElement('small');
        hint.textContent = item.hint || '';
        link.appendChild(mark);
        link.appendChild(label);
        link.appendChild(hint);
        results.appendChild(link);
        index++;
      });
    });
    highlight();
  }

  function fetchRemote(query) {
    var needle = normalizeText(query);
    if (needle.length < 2) {
      remoteSeq++;
      remotePending = false;
      remoteItems = [];
      remoteNeedle = null;
      return;
    }
    var seq = ++remoteSeq;
    remotePending = true;
    fetch('/portail/recherche.php?q=' + encodeURIComponent(query), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (response) { return response.ok ? response.json() : { items: [] }; })
      .then(function (data) {
        if (seq !== remoteSeq) return;
        remotePending = false;
        remoteItems = Array.isArray(data.items) ? data.items.map(prepare) : [];
        remoteNeedle = needle;
        if (normalizeText(input.value) === needle) render(input.value);
      })
      .catch(function () {
        if (seq !== remoteSeq) return;
        remotePending = false;
        remoteItems = [];
        remoteNeedle = needle;
        render(input.value);
      });
  }


  function highlight() {
    var links = results.querySelectorAll('.palette-item');
    links.forEach(function (link, index) {
      var on = index === selected;
      link.setAttribute('aria-selected', String(on));
      if (on) {
        link.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  function openPalette() {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    window.clearTimeout(remoteTimer);
    remoteSeq++;
    remotePending = false;
    remoteItems = [];
    remoteNeedle = null;
    input.value = '';
    render('');
    window.setTimeout(function () { input.focus(); }, 20);
  }
  function closePalette() {
    window.clearTimeout(remoteTimer);
    remoteSeq++;
    remotePending = false;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  document.querySelectorAll('[data-palette-open]').forEach(function (button) {
    button.addEventListener('click', openPalette);
  });
  document.addEventListener('keydown', function (event) {
    var key = event.key.toLowerCase();
    if ((event.metaKey || event.ctrlKey) && key === 'k') {
      event.preventDefault();
      if (dialog.open) closePalette(); else openPalette();
    }
    if (!dialog.open) return;
    if (event.key === 'Escape') { closePalette(); return; }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (!rendered.length) return;
      selected = Math.min(selected + 1, rendered.length - 1);
      highlight();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (!rendered.length) return;
      selected = Math.max(selected - 1, 0);
      highlight();
    } else if (event.key === 'Enter') {
      var item = rendered[selected];
      if (item) { event.preventDefault(); window.location.href = item.href; }
    }
  });
  input.addEventListener('input', function () {
    var query = input.value;
    if (normalizeText(query).length >= 2) {
      remotePending = true;
      remoteNeedle = null;
    } else {
      remotePending = false;
    }
    render(query);
    window.clearTimeout(remoteTimer);
    remoteTimer = window.setTimeout(function () { fetchRemote(query); }, 180);
  });
  results.addEventListener('click', function (event) {
    var link = event.target.closest('.palette-item');
    if (link) { event.preventDefault(); window.location.href = link.href; }
  });
  results.addEventListener('mousemove', function (event) {
    var link = event.target.closest('.palette-item');
    if (!link) return;
    var index = Number(link.dataset.index);
    if (!isNaN(index) && index !== selected) { selected = index; highlight(); }
  });
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) closePalette();
  });
})();
