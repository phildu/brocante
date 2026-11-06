<?php
/**
 * Navigation admin partagée par toutes les pages du back-office — avant,
 * chaque page dupliquait sa propre barre à la main et elles avaient fini
 * par diverger (liens manquants ou dans un ordre différent d'une page à
 * l'autre). $adminNav (définie par la page avant cet include) surligne
 * l'entrée courante ; $content doit déjà être chargé (get_content()).
 */
$adminNavItems = [
    'dashboard' => ['/admin/dashboard.php', 'Tableau de bord'],
    'catalogue' => ['/admin/catalog.php', 'Catalogue'],
    'batch' => ['/admin/batch-import.php', 'Import par lot'],
    'connecteurs' => ['/admin/connectors.php', 'Boutiques connectées'],
    'commandes' => ['/admin/orders.php', 'Commandes'],
    'buvette' => ['/admin/buvette/index.php', 'Buvette'],
    'invendus' => ['/admin/invendus.php', 'Invendus & dons'],
    'impression' => ['/admin/impression/index.php', 'Impression'],
    'comptage' => ['/admin/comptage/index.php', 'Comptage de foule'],
    'hero' => ['/admin/hero.php', 'Diaporama hero'],
    'slideshow' => ['/admin/slideshow.php', 'Diaporama boutique'],
    'banners' => ['/admin/banners.php', 'Bandeaux de page'],
    'media' => ['/admin/media.php', 'Médiathèque'],
    'shipping' => ['/admin/shipping.php', 'Frais de port'],
    'reglages' => ['/admin/index.php', 'Réglages du site'],
];
$adminNav = $adminNav ?? '';
?>
<header class="site">
  <div class="wrap site-bar">
    <a class="wordmark" href="/index.php">
      <img src="/assets/logo.png" alt="<?= h($content['site_name'] ?? '') ?>" class="site-logo">
      <span class="tag">Espace boutique</span>
    </a>
    <nav class="primary" aria-label="Navigation administration">
      <?php foreach ($adminNavItems as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $adminNav === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="/index.php" target="_blank">Voir le site</a>
      <a href="/admin/logout.php">Se déconnecter</a>
    </nav>
  </div>
</header>
