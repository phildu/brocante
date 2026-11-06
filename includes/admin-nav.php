<?php
/**
 * Navigation admin partagée par toutes les pages du back-office — avant,
 * chaque page dupliquait sa propre barre à la main et elles avaient fini
 * par diverger (liens manquants ou dans un ordre différent d'une page à
 * l'autre). $adminNav (définie par la page avant cet include) surligne
 * l'entrée courante ; $content doit déjà être chargé (get_content()).
 */
// Le menu ne montre que les pages que la personne a le droit de consulter
// (liste et droits : auth_nav_items() dans includes/auth.php).
$adminNavItems = array_filter(auth_nav_items(), fn($item) => auth_can_any(...$item[2]));
$adminUser = auth_user();
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
      <a href="/admin/account.php"<?= $adminNav === 'compte' ? ' aria-current="page"' : '' ?>>Mon compte<?= $adminUser && !$adminUser['legacy'] ? ' (' . h($adminUser['name']) . ')' : '' ?></a>
      <a href="/admin/logout.php">Se déconnecter</a>
    </nav>
  </div>
</header>
