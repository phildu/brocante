<?php
/**
 * Navigation admin partagée par toutes les pages du back-office — avant,
 * chaque page dupliquait sa propre barre à la main et elles avaient fini
 * par diverger (liens manquants ou dans un ordre différent d'une page à
 * l'autre). $adminNav (définie par la page avant cet include) surligne
 * l'entrée courante ; $content doit déjà être chargé (get_content()).
 *
 * Barre latérale repliable : « Réduire le menu » ne garde que les icônes
 * (choix mémorisé dans le navigateur) ; sur mobile, elle s'ouvre avec le
 * bouton menu de la barre du haut. Les entrées sont rangées en catégories
 * repliables : seule celle de la page courante est ouverte, pour ne pas
 * présenter trente liens d'un coup. Les catégories et leur ordre sont communs
 * à toutes les boutiques et se règlent dans le portail (portail/menu.php ;
 * aperçu dans Réglages → Menu latéral, admin/menu.php).
 */
require_once __DIR__ . '/admin-menu.php';
require_once __DIR__ . '/feedback.php';

// Entrées disponibles (pages de la plateforme et des plugins actifs) puis
// application des catégories et de l'ordre enregistrés par le portail.
$adminNavItems = admin_menu_items(admin_role());
$adminNav = $adminNav ?? '';
$adminNavLayout = admin_menu_layout($adminNavItems);
$adminNavActiveGroup = '';
foreach ($adminNavLayout as $groupRow) {
    if (isset($groupRow['items'][$adminNav])) {
        $adminNavActiveGroup = $groupRow['key'];
        break;
    }
}

// Pictogrammes (traits 24×24, couleur du texte) — définis avec le menu partagé
// pour que le portail puisse aussi les proposer à la création d'un item.
$adminIcons = admin_menu_icons();
// Une icône inconnue (déclarée par un plugin) prend la pièce de puzzle.
$adminIcon = static fn (string $name): string => admin_menu_icon_svg($name);
// Loupe : elle n'est pas au catalogue des icônes du menu (aucune entrée ne la porte).
$adminSearchIcon = '<svg class="admin-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>';

// Recherche (⌘K) : les entrées du menu servent de résultats immédiats, le texte
// des pages et les fiches de la boutique arrivent d'admin/recherche.php.
// Un rôle qui ne peut pas ouvrir cet endpoint (photographe) n'a pas la palette.
$adminPaletteOk = admin_role_can_access(admin_role(), '/admin/recherche.php');
$adminPaletteItems = [];
if ($adminPaletteOk) {
    foreach ($adminNavLayout as $groupRow) {
        foreach ($groupRow['items'] as $item) {
            $adminPaletteItems[] = ['group' => $groupRow['label'], 'label' => $item[1], 'hint' => '', 'href' => $item[0]];
        }
    }
}
?>
<script>
  // Appliqué avant l'affichage du reste de la page, pour éviter un saut de mise en page.
  document.documentElement.classList.add('has-admin-sidebar');
  try { if (localStorage.getItem('adminSidebar') === 'collapsed') document.documentElement.classList.add('admin-sidebar-collapsed'); } catch (e) {}
</script>
<?= plugin_admin_assets_html(admin_role()) ?>
<header class="admin-topbar">
  <button type="button" class="admin-topbar-btn" id="admin-sidebar-open" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Ouvrir le menu d'administration"><?= $adminIcon('menu') ?></button>
  <?php if ($adminPaletteOk): ?>
    <button type="button" class="admin-topbar-btn" data-palette-open aria-label="Rechercher (Ctrl ou Cmd + K)"><?= $adminSearchIcon ?></button>
  <?php endif; ?>
  <a class="wordmark" href="<?= h(admin_home_for_role(admin_role())) ?>"><img src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($content['site_name'] ?? '') ?>" class="site-logo"></a>
</header>
<div class="admin-sidebar-overlay" id="admin-sidebar-overlay"></div>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Administration">
  <div class="admin-sidebar-head">
    <a class="admin-sidebar-logo" href="/index.php" title="Voir le site">
      <img class="logo-full" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($content['site_name'] ?? '') ?>">
      <img class="logo-mini" src="/<?= h(logo_url('square')) ?>" alt="">
    </a>
    <span class="admin-sidebar-tag">Espace boutique</span>
    <?php if ($adminPaletteOk): ?>
      <button type="button" class="admin-search" data-palette-open title="Rechercher une page, une pièce, une commande (Ctrl ou Cmd + K)">
        <?= $adminSearchIcon ?><span class="admin-label">Rechercher</span><kbd class="admin-search-kbd">⌘K</kbd>
      </button>
    <?php endif; ?>
  </div>
  <nav class="admin-sidebar-nav" aria-label="Navigation administration">
    <?php $firstGroup = true; foreach ($adminNavLayout as $groupRow): ?>
      <?php if (!$groupRow['items']) continue; ?>
      <?php $groupOpen = $groupRow['key'] === $adminNavActiveGroup || ($adminNavActiveGroup === '' && $firstGroup); $firstGroup = false; ?>
      <details class="admin-nav-group"<?= $groupOpen ? ' open' : '' ?>>
        <summary class="admin-nav-title"><span><?= h($groupRow['label']) ?></span></summary>
        <div class="admin-nav-items">
          <?php foreach ($groupRow['items'] as $key => [$href, $label, $icon]): ?>
            <a href="<?= h($href) ?>" title="<?= h($label) ?>"<?= $adminNav === $key ? ' aria-current="page"' : '' ?>>
              <?= $adminIcon($icon) ?><span class="admin-label"><?= h($label) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </nav>
  <div class="admin-sidebar-foot">
    <?php $adminWho = admin_session(); ?>
    <?php if ($adminWho && ai_shop_enabled() && admin_role_can_access($adminWho['role'], '/admin/ai-usage.php')): $aiBalance = ai_balance(); ?>
      <a class="admin-ai-chip" href="/admin/ai-usage.php" title="Coût estimé de l'IA : voir le détail"><?php $aiQuota = ai_quota_state(); ?><span class="admin-label"><?= $aiQuota ? ($aiQuota['blocked'] ? 'IA suspendue : quota épuisé' : 'IA restante : ' . h(ai_format_eur($aiQuota['available']))) : ($aiBalance ? 'Solde IA : ' . h(ai_format_eur($aiBalance['remaining'])) : 'IA ce mois : ' . h(ai_format_eur(ai_usage_spent(gmdate('Y-m-01 00:00:00'))))) ?></span></a>
    <?php endif; ?>
    <?php if ($adminWho): ?>
      <span class="admin-whoami admin-label"><?= h($adminWho['name'] !== '' ? $adminWho['name'] : 'Compte principal') ?> · <?= h(account_role_label($adminWho['role'])) ?></span>
    <?php endif; ?>
    <a href="/index.php" target="_blank" title="Voir le site"><?= $adminIcon('external') ?><span class="admin-label">Voir le site</span></a>
    <a href="/admin/logout.php" title="Se déconnecter"><?= $adminIcon('logout') ?><span class="admin-label">Se déconnecter</span></a>
    <button type="button" class="admin-collapse" id="admin-sidebar-collapse" aria-pressed="false" title="Réduire le menu">
      <?= $adminIcon('collapse') ?><span class="admin-label">Réduire le menu</span>
    </button>
  </div>
</aside>
<script>
(function () {
  var root = document.documentElement;
  var sidebar = document.getElementById('admin-sidebar');
  var overlay = document.getElementById('admin-sidebar-overlay');
  var openBtn = document.getElementById('admin-sidebar-open');
  var collapseBtn = document.getElementById('admin-sidebar-collapse');
  // Replié (icônes seules), les titres de catégorie n'ont pas leur place : tous les groupes s'ouvrent ; on rétablit l'état au dépliage.
  var navGroups = Array.prototype.slice.call(document.querySelectorAll('.admin-nav-group'));
  var groupState = navGroups.map(function (g) { return g.open; });

  function syncGroups(collapsed) {
    if (collapsed) {
      groupState = navGroups.map(function (g) { return g.open; });
      navGroups.forEach(function (g) { g.open = true; });
    } else {
      navGroups.forEach(function (g, i) { g.open = groupState[i]; });
    }
  }

  function syncCollapse() {
    var collapsed = root.classList.contains('admin-sidebar-collapsed');
    var label = collapsed ? 'Déplier le menu' : 'Réduire le menu';
    collapseBtn.setAttribute('aria-pressed', String(collapsed));
    collapseBtn.title = label;
    collapseBtn.querySelector('.admin-label').textContent = label;
  }
  collapseBtn.addEventListener('click', function () {
    var collapsed = root.classList.toggle('admin-sidebar-collapsed');
    try { localStorage.setItem('adminSidebar', collapsed ? 'collapsed' : 'open'); } catch (e) {}
    syncCollapse();
    syncGroups(collapsed);
  });
  syncCollapse();
  syncGroups(root.classList.contains('admin-sidebar-collapsed'));

  // Mobile : panneau coulissant.
  function setOpen(open) {
    sidebar.classList.toggle('is-open', open);
    overlay.classList.toggle('is-open', open);
    openBtn.setAttribute('aria-expanded', String(open));
  }
  openBtn.addEventListener('click', function () { setOpen(!sidebar.classList.contains('is-open')); });
  overlay.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
})();
</script>
<script>
// Ratio et taille en pixels (ex. « 3:2 · 1800×1200 ») sur les vignettes des
// sélecteurs d'images, de la médiathèque et des galeries — y compris celles
// ajoutées plus tard par script (import par lot, prise de photos).
(function () {
  var HOSTS = '.media-thumb, .media-list-thumb, .gm-thumb-mobile, .media-pick-item, .gm-thumb, .banner-thumb, .bi-photo-item, .bi-thumbs, .qa-visuals figure';
  var RATIOS = [[1, 1], [5, 4], [4, 5], [4, 3], [3, 4], [3, 2], [2, 3], [16, 9], [9, 16], [2, 1], [1, 2], [21, 9]];

  function ratioLabel(w, h) {
    var r = w / h, best = null, gap = 1;
    RATIOS.forEach(function (x) {
      var e = Math.abs(r / (x[0] / x[1]) - 1);
      if (e < gap) { gap = e; best = x; }
    });
    if (gap <= 0.02) return best[0] + ':' + best[1];
    return r >= 1 ? r.toFixed(2).replace('.', ',') + ':1' : '1:' + (1 / r).toFixed(2).replace('.', ',');
  }

  function show(el) {
    var w = el.naturalWidth || el.videoWidth, h = el.naturalHeight || el.videoHeight;
    if (!w || !h) return;
    var host = el.parentElement;
    // Parent partagé avec d'autres éléments (libellé, grille…) : l'image reçoit
    // son propre cadre, pour que l'étiquette reste posée sur elle.
    var others = Array.prototype.filter.call(host.children, function (c) { return c !== el && !c.classList.contains('media-dims-badge'); });
    if (others.length) {
      var wrap = document.createElement('span');
      wrap.className = 'media-dims-wrap';
      host.insertBefore(wrap, el);
      wrap.appendChild(el);
      host = wrap;
    } else if (getComputedStyle(host).position === 'static') {
      host.style.position = 'relative';
    }
    var badge = host.querySelector(':scope > .media-dims-badge') || host.appendChild(document.createElement('span'));
    badge.className = 'media-dims-badge';
    var ratio = ratioLabel(w, h), size = w + '×' + h;
    badge.title = ratio + ' — ' + size + ' px';
    badge.innerHTML = '';
    var b = document.createElement('b');
    b.textContent = ratio;
    badge.appendChild(b);
    if (el.clientWidth === 0 || el.clientWidth >= 100) badge.appendChild(document.createTextNode(' · ' + size));
  }

  function bind(el) {
    if (el.dataset.dimsBound) return;
    el.dataset.dimsBound = '1';
    el.addEventListener(el.tagName === 'VIDEO' ? 'loadedmetadata' : 'load', function () { show(el); });
    if ((el.tagName === 'IMG' && el.complete) || el.readyState >= 1) show(el);
  }

  function scan(root) {
    if (!root.querySelectorAll) return;
    if (root.matches && root.matches('img, video') && root.closest(HOSTS)) bind(root);
    root.querySelectorAll(HOSTS).forEach(function (h) { h.querySelectorAll('img, video').forEach(bind); });
    if (root.closest && root.closest(HOSTS)) root.querySelectorAll('img, video').forEach(bind);
  }

  function start() {
    scan(document);
    new MutationObserver(function (list) {
      list.forEach(function (m) { m.addedNodes.forEach(scan); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
<div class="mobile-variants-status" id="mobile-variants-status" hidden></div>
<script>
// Versions smartphone (9:16) en attente : générées une par une, chacune dans
// sa propre requête (admin/mobile-variants.php), pendant qu'on continue à
// travailler. Reprend automatiquement à la page suivante si on quitte celle-ci.
(function () {
  var box = document.getElementById('mobile-variants-status');
  var running = false;
  function post(data) {
    var body = new FormData();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch('/admin/mobile-variants.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'pas de réponse du serveur' }; });
  }
  function show(text, kind) {
    box.hidden = false;
    box.dataset.kind = kind || '';
    box.textContent = text;
  }
  function run() {
    if (running) return;
    running = true;
    post({ action: 'list' }).then(function (r) {
      var ids = (r && r.ok && r.ids) || [];
      if (!ids.length) { running = false; return; }
      var done = 0, failed = 0;
      (function next(i) {
        if (i >= ids.length) {
          running = false;
          show(failed
            ? 'Formats : ' + done + ' prêt(s), ' + failed + ' en échec (réessai automatique, 3 essais au plus).'
            : '✓ Formats 3:2 / 9:16 prêts — actualisez la page pour les voir.', failed ? 'error' : 'ok');
          setTimeout(function () { box.hidden = true; }, 8000);
          return;
        }
        show('Génération des formats 3:2 / 9:16… ' + (i + 1) + ' / ' + ids.length);
        post({ action: 'run', id: ids[i] }).then(function (res) {
          if (res && res.ok) done++; else failed++;
          if (res && res.next) ids.push(ids[i]); // deuxième format du même visuel
          next(i + 1);
        });
      })(0);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
</script>
<?php if ($adminPaletteOk): ?>
<link rel="stylesheet" href="/assets/admin-palette.css">
<dialog class="palette" id="psearch" aria-label="Recherche dans la boutique">
  <div class="palette-inner">
    <div class="palette-search">
      <?= $adminSearchIcon ?>
      <input id="psearch-input" type="search" placeholder="Rechercher une page, une pièce, une commande…" autocomplete="off" spellcheck="false" aria-label="Recherche">
      <kbd>Échap</kbd>
    </div>
    <div class="palette-results" id="psearch-results"></div>
    <div class="palette-foot"><span>↑ ↓ naviguer</span><span>↵ ouvrir</span><span>Échap fermer</span></div>
  </div>
</dialog>
<script type="application/json" id="psearch-data"><?= json_encode($adminPaletteItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
// Palette ⌘K : les entrées du menu répondent tout de suite, le texte des pages
// et les fiches (pièces, commandes, médias…) arrivent d'admin/recherche.php
// après 180 ms de frappe ; une réponse périmée est ignorée (remoteSeq).
(function () {
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
    fetch('/admin/recherche.php?q=' + encodeURIComponent(query), {
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
      if (on) link.scrollIntoView({ block: 'nearest' });
    });
  }

  function resetRemote() {
    window.clearTimeout(remoteTimer);
    remoteSeq++;
    remotePending = false;
    remoteItems = [];
    remoteNeedle = null;
  }
  function openPalette() {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    resetRemote();
    input.value = '';
    render('');
    window.setTimeout(function () { input.focus(); }, 20);
  }
  function closePalette() {
    resetRemote();
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
      return;
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
</script>
<?php endif; ?>
<?= feedback_widget_html() ?>
<?= ai_rating_widget_html() ?>
