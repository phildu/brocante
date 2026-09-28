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
 * bouton menu de la barre du haut.
 */
$adminNavItems = [
    'catalogue' => ['/admin/catalog.php', 'Catalogue', 'grid'],
    'batch' => ['/admin/batch-import.php', 'Import par lot', 'upload'],
    'commandes' => ['/admin/orders.php', 'Commandes', 'receipt'],
    'hero' => ['/admin/hero.php', 'Diaporama hero', 'image'],
    'slideshow' => ['/admin/slideshow.php', 'Diaporama boutique', 'play'],
    'banners' => ['/admin/banners.php', 'Bandeaux de page', 'banner'],
    'media' => ['/admin/media.php', 'Médiathèque', 'folder'],
    'shipping' => ['/admin/shipping.php', 'Frais de port', 'truck'],
    'apparence' => ['/admin/appearance.php', 'Apparence', 'palette'],
    'reglages' => ['/admin/index.php', 'Réglages du site', 'gear'],
];
$adminNav = $adminNav ?? '';

// Pictogrammes (traits 24×24, couleur du texte).
$adminIcons = [
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'upload' => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
    'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
    'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
    'play' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="m10 8 5 3-5 3z"/><path d="M8 21h8"/>',
    'banner' => '<rect x="3" y="4" width="18" height="7" rx="1"/><path d="M3 15h18M3 19h12"/>',
    'folder' => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    'truck' => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'palette' => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.9 1.5-1.9-.3-1 .4-2.1 1.5-2.1H18a3 3 0 0 0 3-3c0-6-4-11-9-11z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10.5" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
    'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
    'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    'collapse' => '<path d="m15 18-6-6 6-6"/>',
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
];
$adminIcon = static fn (string $name): string =>
    '<svg class="admin-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    . $adminIcons[$name] . '</svg>';
?>
<script>
  // Appliqué avant l'affichage du reste de la page, pour éviter un saut de mise en page.
  document.documentElement.classList.add('has-admin-sidebar');
  try { if (localStorage.getItem('adminSidebar') === 'collapsed') document.documentElement.classList.add('admin-sidebar-collapsed'); } catch (e) {}
</script>
<header class="admin-topbar">
  <button type="button" class="admin-topbar-btn" id="admin-sidebar-open" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Ouvrir le menu d'administration"><?= $adminIcon('menu') ?></button>
  <a class="wordmark" href="/admin/catalog.php"><img src="/<?= h(tenant('logo')) ?>" alt="<?= h($content['site_name'] ?? '') ?>" class="site-logo"></a>
</header>
<div class="admin-sidebar-overlay" id="admin-sidebar-overlay"></div>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Administration">
  <div class="admin-sidebar-head">
    <a class="admin-sidebar-logo" href="/index.php" title="Voir le site">
      <img class="logo-full" src="/<?= h(tenant('logo')) ?>" alt="<?= h($content['site_name'] ?? '') ?>">
      <img class="logo-mini" src="/<?= h(tenant('logo_macaron')) ?>" alt="">
    </a>
    <span class="admin-sidebar-tag">Espace boutique</span>
  </div>
  <nav class="admin-sidebar-nav" aria-label="Navigation administration">
    <?php foreach ($adminNavItems as $key => [$href, $label, $icon]): ?>
      <a href="<?= h($href) ?>" title="<?= h($label) ?>"<?= $adminNav === $key ? ' aria-current="page"' : '' ?>>
        <?= $adminIcon($icon) ?><span class="admin-label"><?= h($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="admin-sidebar-foot">
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
  });
  syncCollapse();

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
