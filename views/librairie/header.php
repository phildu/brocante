<?php
// Modèle « Librairie » — en-tête : bandeau d'information, recherche au centre (c'est le geste n°1 d'un lecteur), outils à droite, puis la barre des rayons.
// Variables fournies par includes/header.php : $siteName, $siteTagline, $siteContent, $cartCount, $activeNav.
$accountLink = oauth_enabled_providers('customer') || customer_session();
?>
<header class="site lb-head">
  <?php if (trim((string) ($siteContent['contact_delivery'] ?? '')) !== ''): ?>
    <p class="lb-strip"><?= h($siteContent['contact_delivery']) ?></p>
  <?php endif; ?>
  <div class="wrap lb-bar">
    <a class="wordmark" href="/index.php">
      <img src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>" class="site-logo">
    </a>
    <form class="lb-search" method="get" action="/boutique.php" role="search">
      <input type="search" name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" placeholder="Un titre, un auteur, un mot-clé…" aria-label="Rechercher dans le catalogue">
      <button type="submit">Rechercher</button>
    </form>
    <div class="lb-tools">
      <?php if ($accountLink): ?><a href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Mon compte</a><?php endif; ?>
      <a href="/cart.php" class="lb-cart">Panier<?= $cartCount ? ' <b>' . (int) $cartCount . '</b>' : '' ?></a>
      <?php if (is_admin_logged_in()): ?><a href="/admin/catalog.php">Administration</a><?php else: ?><a href="/admin/login.php">Connexion</a><?php endif; ?>
    </div>
    <button type="button" class="burger-btn" id="burger-btn" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="primary-nav">
      <span></span><span></span><span></span>
    </button>
  </div>
  <nav class="primary lb-rayons" id="primary-nav" aria-label="Rayons et navigation">
    <a href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>>Accueil</a>
    <a href="/boutique.php"<?= $activeNav === 'boutique' && empty($_GET['cat']) ? ' aria-current="page"' : '' ?>>Tout le catalogue</a>
    <?php foreach (category_list() as $c): ?>
      <a href="/boutique.php?cat=<?= h($c['key']) ?>"<?= $activeNav === 'boutique' && ($_GET['cat'] ?? '') === $c['key'] ? ' aria-current="page"' : '' ?>><?= h($c['label']) ?></a>
    <?php endforeach; ?>
    <a href="/index.php#histoire" class="lb-rest">Le mot du libraire</a>
    <a href="/index.php#contact" class="lb-rest">Contact</a>
    <span class="lb-mobile-only">
      <?php if ($accountLink): ?><a href="/compte.php">Mon compte</a><?php endif; ?>
      <a href="/cart.php">Panier<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a>
      <a href="<?= is_admin_logged_in() ? '/admin/catalog.php' : '/admin/login.php' ?>"><?= is_admin_logged_in() ? 'Administration' : 'Connexion' ?></a>
    </span>
  </nav>
</header>
