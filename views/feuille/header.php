<?php
// Modèle « Feuille » — en-tête : bandeau minimal, menu plein écran en sommaire numéroté, rayons et informations en pied de sommaire.
$accountLink = oauth_enabled_providers('customer') || customer_session();
$flContent = get_content();
$flAddress = trim((string) ($flContent['contact_address'] ?? ''));
$flHours = trim((string) ($flContent['contact_hours'] ?? ''));
?>
<header class="site fl-head">
  <div class="fl-bar">
    <a class="fl-mark" href="/index.php">
      <span class="fl-mark-name"><?= h($siteName) ?></span>
      <?php if ($siteTagline !== ''): ?><span class="fl-mark-tag"><?= h($siteTagline) ?></span><?php endif; ?>
    </a>
    <div class="fl-tools">
      <?php if ($accountLink): ?><a class="fl-account" href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Compte</a><?php endif; ?>
      <?php if (shop_sales_enabled()): ?><a class="fl-cart" href="/cart.php">Panier<?= $cartCount ? '<b>' . (int) $cartCount . '</b>' : '' ?></a><?php endif; ?>
      <button type="button" class="burger-btn fl-burger" id="burger-btn" aria-label="Ouvrir le sommaire" aria-expanded="false" aria-controls="primary-nav">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <nav class="primary fl-nav" id="primary-nav" aria-label="Sommaire">
    <div class="fl-nav-in">
      <div class="fl-nav-main">
        <p class="fl-nav-kicker">Sommaire</p>
        <a class="fl-nav-big" href="/index.php"<?= $activeNav === 'accueil' ? ' aria-current="page"' : '' ?>><span>I.</span> Accueil</a>
        <a class="fl-nav-big" href="/boutique.php"<?= $activeNav === 'boutique' ? ' aria-current="page"' : '' ?>><span>II.</span> La collection</a>
        <a class="fl-nav-big" href="/index.php#histoire"><span>III.</span> La maison</a>
        <a class="fl-nav-big" href="/index.php#contact"><span>IV.</span> Nous trouver</a>
      </div>
      <div class="fl-nav-cats">
        <p class="fl-nav-kicker">Rayons</p>
        <?php foreach (category_list() as $c): ?>
          <a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="fl-nav-foot">
        <?php if ($flAddress !== ''): ?><p><?= h($flAddress) ?></p><?php endif; ?>
        <?php if ($flHours !== ''): ?><p><?= h($flHours) ?></p><?php endif; ?>
        <p class="fl-nav-links">
          <?php if ($accountLink): ?><a href="/compte.php"<?= $activeNav === 'compte' ? ' aria-current="page"' : '' ?>>Mon compte</a><?php endif; ?>
          <?php if (shop_sales_enabled()): ?><a href="/cart.php">Panier<?= $cartCount ? ' (' . (int) $cartCount . ')' : '' ?></a><?php endif; ?>
          <a href="<?= is_admin_logged_in() ? '/admin/dashboard.php' : '/admin/login.php' ?>"><?= is_admin_logged_in() ? 'Administration' : 'Connexion' ?></a>
        </p>
      </div>
    </div>
  </nav>
</header>
