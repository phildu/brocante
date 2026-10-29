<?php
// Modèle « Vitrine » — en-tête : rail vertical fixe (monogramme, navigation numérotée, panier), barre haute et menu plein écran sur écran étroit.
$accountLink = oauth_enabled_providers('customer') || customer_session();
?>
<header class="site vt-head">
  <div class="vt-rail">
    <a class="vt-mark" href="/index.php" aria-label="<?= h($siteName) ?> — accueil">
      <img src="/<?= h(logo_url('square')) ?>" alt="<?= h($siteName) ?>">
    </a>
    <nav class="primary vt-nav" id="primary-nav" aria-label="Navigation principale">
      <a href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>><small>01</small><span>Accueil</span></a>
      <a href="/boutique.php"<?= $activeNav === 'boutique' ? ' aria-current="page"' : '' ?>><small>02</small><span>Collection</span></a>
      <a href="/index.php#histoire"><small>03</small><span>Maison</span></a>
      <a href="/index.php#contact"><small>04</small><span>Contact</span></a>
      <span class="vt-nav-extra">
        <?php if ($accountLink): ?><a href="/compte.php">Mon compte</a><?php endif; ?>
        <?php if (shop_sales_enabled()): ?><a href="/cart.php">Panier<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a><?php endif; ?>
        <a href="<?= is_admin_logged_in() ? '/admin/dashboard.php' : '/admin/login.php' ?>"><?= is_admin_logged_in() ? 'Administration' : 'Connexion' ?></a>
      </span>
    </nav>
    <div class="vt-tools">
      <?php if (shop_sales_enabled()): ?><a class="vt-cart" href="/cart.php">Panier<?= $cartCount ? '<b>' . (int) $cartCount . '</b>' : '' ?></a><?php endif; ?>
      <?php if ($accountLink): ?><a class="vt-account" href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Compte</a><?php endif; ?>
      <button type="button" class="burger-btn vt-burger" id="burger-btn" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="primary-nav">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>
