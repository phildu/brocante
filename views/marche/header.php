<?php
// Modèle « Marché » — en-tête : bandeau défilant, barre collante avec recherche, puis rayons en pastilles qui défilent horizontalement.
$accountLink = oauth_enabled_providers('customer') || customer_session();
$stripText = trim((string) ($siteContent['contact_delivery'] ?? '')) ?: (string) $siteTagline;
?>
<header class="site mk-head">
  <?php if ($stripText !== ''): ?>
    <div class="mk-ticker" aria-hidden="true"><div class="mk-ticker-track">
      <?php for ($i = 0; $i < 8; $i++): ?><span><?= h($stripText) ?></span><i>✦</i><?php endfor; ?>
    </div></div>
  <?php endif; ?>
  <div class="wrap-wide mk-bar">
    <a class="mk-logo" href="/index.php" aria-label="<?= h($siteName) ?> — accueil">
      <img src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>">
    </a>
    <form class="mk-search" method="get" action="/boutique.php" role="search">
      <input type="search" name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" placeholder="Chercher dans le marché…" aria-label="Rechercher dans le catalogue">
      <button type="submit">Chercher</button>
    </form>
    <div class="mk-tools">
      <?php if ($accountLink): ?><a href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Compte</a><?php endif; ?>
      <?php if (shop_sales_enabled()): ?><a class="mk-cart" href="/cart.php">Panier<?= $cartCount ? ' <b>' . (int) $cartCount . '</b>' : '' ?></a><?php endif; ?>
      <a href="<?= is_admin_logged_in() ? '/admin/dashboard.php' : '/admin/login.php' ?>"><?= is_admin_logged_in() ? 'Admin' : 'Connexion' ?></a>
    </div>
  </div>
  <nav class="primary mk-chips" id="primary-nav" aria-label="Rayons et navigation">
    <a href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>>Accueil</a>
    <a href="/boutique.php"<?= $activeNav === 'boutique' && empty($_GET['cat']) ? ' aria-current="page"' : '' ?>>Tout le marché</a>
    <?php foreach (category_list() as $c): ?>
      <a href="/boutique.php?cat=<?= h($c['key']) ?>"<?= $activeNav === 'boutique' && ($_GET['cat'] ?? '') === $c['key'] ? ' aria-current="page"' : '' ?>><?= h($c['label']) ?></a>
    <?php endforeach; ?>
    <a class="mk-chip-rest" href="/notre-histoire.php"<?= $activeNav === 'histoire' ? ' aria-current="page"' : '' ?>>Le marché</a>
    <a class="mk-chip-rest" href="/visite.php"<?= $activeNav === 'visite' ? ' aria-current="page"' : '' ?>>Visite virtuelle</a>
    <a class="mk-chip-rest" href="/nous-trouver.php"<?= $activeNav === 'nous-trouver' ? ' aria-current="page"' : '' ?>>Contact</a>
    <span class="mk-mobile-tools">
      <?php if (shop_sales_enabled()): ?><a href="/cart.php">Panier<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a><?php endif; ?>
      <?php if ($accountLink): ?><a href="/compte.php">Mon compte</a><?php endif; ?>
      <a href="<?= is_admin_logged_in() ? '/admin/dashboard.php' : '/admin/login.php' ?>"><?= is_admin_logged_in() ? 'Administration' : 'Connexion' ?></a>
    </span>
  </nav>
</header>
