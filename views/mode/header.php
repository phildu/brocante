<?php
// Modèle « Mode » — en-tête : bandeau d'annonce, navigation à gauche, nom de la boutique centré, outils à droite. Sur l'accueil il flotte sur la grande
// image, transparent, et devient plein au défilement (script de views/mode/home.php).
$accountLink = oauth_enabled_providers('customer') || customer_session();
$over = $activeNav === 'accueil';
?>
<header class="site md-head<?= $over ? ' md-over' : '' ?>" id="md-head">
  <?php if (trim((string) ($siteContent['contact_delivery'] ?? '')) !== ''): ?>
    <p class="md-announce"><?= h($siteContent['contact_delivery']) ?></p>
  <?php endif; ?>
  <div class="wrap md-bar">
    <button type="button" class="burger-btn" id="burger-btn" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="primary-nav">
      <span></span><span></span><span></span>
    </button>
    <nav class="primary md-nav" id="primary-nav" aria-label="Navigation principale">
      <a href="/boutique.php"<?= $activeNav === 'boutique' && empty($_GET['cat']) ? ' aria-current="page"' : '' ?>>Boutique</a>
      <?php foreach (array_slice(category_list(), 0, 2) as $c): ?>
        <a href="/boutique.php?cat=<?= h($c['key']) ?>"<?= $activeNav === 'boutique' && ($_GET['cat'] ?? '') === $c['key'] ? ' aria-current="page"' : '' ?>><?= h($c['label']) ?></a>
      <?php endforeach; ?>
      <span class="md-mobile-only">
        <a href="/index.php#histoire">Histoire</a>
        <a href="/index.php#contact">Contact</a>
        <?php if ($accountLink): ?><a href="/compte.php">Mon compte</a><?php endif; ?>
        <a href="/cart.php">Panier<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a>
        <a href="<?= is_admin_logged_in() ? '/admin/catalog.php' : '/admin/login.php' ?>"><?= is_admin_logged_in() ? 'Administration' : 'Connexion' ?></a>
      </span>
    </nav>
    <a class="md-logo" href="/index.php" aria-label="<?= h($siteName) ?> — accueil"><?= h($siteName) ?></a>
    <div class="md-tools">
      <a href="/boutique.php#recherche" class="md-tool-search">Rechercher</a>
      <?php if ($accountLink): ?><a href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Compte</a><?php endif; ?>
      <a href="/cart.php">Panier<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a>
    </div>
  </div>
</header>
