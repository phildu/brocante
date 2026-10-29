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
?>
<script>
  // Appliqué avant l'affichage du reste de la page, pour éviter un saut de mise en page.
  document.documentElement.classList.add('has-admin-sidebar');
  try { if (localStorage.getItem('adminSidebar') === 'collapsed') document.documentElement.classList.add('admin-sidebar-collapsed'); } catch (e) {}
</script>
<?= plugin_admin_assets_html(admin_role()) ?>
<header class="admin-topbar">
  <button type="button" class="admin-topbar-btn" id="admin-sidebar-open" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Ouvrir le menu d'administration"><?= $adminIcon('menu') ?></button>
  <a class="wordmark" href="/admin/dashboard.php"><img src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($content['site_name'] ?? '') ?>" class="site-logo"></a>
</header>
<div class="admin-sidebar-overlay" id="admin-sidebar-overlay"></div>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Administration">
  <div class="admin-sidebar-head">
    <a class="admin-sidebar-logo" href="/index.php" title="Voir le site">
      <img class="logo-full" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($content['site_name'] ?? '') ?>">
      <img class="logo-mini" src="/<?= h(logo_url('square')) ?>" alt="">
    </a>
    <span class="admin-sidebar-tag">Espace boutique</span>
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
