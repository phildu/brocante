<?php
require_once __DIR__ . '/includes/tenant.php';

// Déploiement portail : la racine du domaine du portail ouvre l'accueil de la
// plateforme. Le domaine propre d'un commerce (site_url sans chemin, ex.
// brocante.arrimage.com) ouvre sa boutique, comme sous /<identifiant>/.
if (tenant_from_path() === '' && tenant_from_host() === '' && tenant_from_domain() === ''
    && is_file(__DIR__ . '/portail/index.php')) {
    require __DIR__ . '/accueil/index.php';
    return;
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hero.php';
require_once __DIR__ . '/includes/recommendations.php';

$content = get_content();
$products = get_products();
$featured = featured_products();
$promo = promo_products();
$heroSlides = hero_slides_list();

// Le hero mélange des rôles fixes (accueil, promo du moment, coup de cœur) et
// les photos d'ambiance curées en back office. Selon le type actif (modèle ou
// réglage Apparence), includes/hero.php en fait une pile de polaroïds, une
// image plein écran, un scindé, un carrousel ou une mosaïque.
$heroPromo = $promo[0] ?? null;
$heroFeatured = null;
foreach ($featured as $fp) {
    if (!$heroPromo || $fp['ref'] !== $heroPromo['ref']) { $heroFeatured = $fp; break; }
}

$heroFrameItems = [['type' => 'welcome']];
if ($heroPromo) $heroFrameItems[] = ['type' => 'promo', 'data' => $heroPromo];
if ($heroFeatured) $heroFrameItems[] = ['type' => 'featured', 'data' => $heroFeatured];
foreach ($heroSlides as $s) $heroFrameItems[] = ['type' => 'ambiance', 'data' => $s];

$heroBanner = get_page_banner('accueil');
$histoireBanner = get_page_banner('histoire');
$contactBanner = get_page_banner('contact');

$counts = [];
foreach ($products as $p) {
    $counts[$p['cat']] = ($counts[$p['cat']] ?? 0) + 1;
}
$followPhotos = array_values(array_filter($products, fn($p) => !empty($p['photo'])));
$followPhotos = array_slice($followPhotos, 0, 6);

$activeNav = 'accueil';
$pageTitle = null;
include __DIR__ . '/includes/header.php';
?>
<?php // Le lien de suivi mémorisé par /suivi.php (appli installée) reste à portée de main sur l'accueil. ?>
<script>
(function () {
  try {
    var t = localStorage.getItem('brocs_track');
    if (!t || t.indexOf('/suivi.php?') === -1) return;
    var a = document.createElement('a');
    a.href = t;
    a.className = 'btn btn-ghost';
    a.textContent = 'Suivre ma commande';
    a.style.cssText = 'display:block;max-width:1080px;margin:14px auto 0;padding:10px 16px;';
    document.querySelector('main').prepend(a);
  } catch (e) {}
})();
</script>
<?php
// Modèle à mise en page propre (views/<modèle>/home.php) : il remplace l'accueil d'origine, avec les mêmes données.
if ($view = shop_view('home')) {
    include $view;
    echo rec_for_you_section_html(array_merge(array_column($promo, 'ref'), array_column($featured, 'ref')));
    include __DIR__ . '/includes/footer.php';
    return;
}
?>

<div class="welcome-splash" id="welcome-splash">
  <img src="/<?= h(logo_url('vertical')) ?>" alt="<?= h($content['site_name']) ?>" class="welcome-splash-logo">
  <p class="welcome-splash-text">Bienvenue</p>
</div>

<?= shop_hero_html(shop_hero_type(), $heroFrameItems, $content, (bool) $heroBanner) ?>

<?= promo_section_html() ?>

<section class="tight" id="histoire">
  <div class="wrap story-grid">
    <div>
      <div class="banner-zone<?= $histoireBanner ? ' has-page-banner' : '' ?>">
        <?php if ($histoireBanner): ?><?php render_page_banner('histoire'); ?><?php endif; ?>
        <p class="eyebrow"><?= h($content['story_eyebrow']) ?></p>
        <h2 style="font-size:1.9rem;margin:12px 0 16px;"><?= h($content['story_title']) ?></h2>
      </div>
      <p class="lede"><?= h($content['story_text']) ?></p>
      <?php if (!empty($content['story_photo'])): ?>
        <div class="story-photo">
          <img src="/<?= h($content['story_photo']) ?>" alt="">
          <p><?= h($content['story_photo_caption']) ?></p>
        </div>
      <?php endif; ?>
      <p style="margin-top:22px;"><a class="btn btn-ghost" href="/notre-histoire.php">Lire notre histoire</a></p>
    </div>
    <div class="principles">
      <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="principle">
          <span class="num">0<?= $i ?></span>
          <div>
            <h3><?= h($content["principle{$i}_title"]) ?></h3>
            <p><?= h($content["principle{$i}_text"]) ?></p>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="section-head">
      <p class="eyebrow"><?= h(tenant_text('categories_eyebrow')) ?></p>
      <h2 style="font-size:1.9rem;"><?= h(tenant_text('categories_title')) ?></h2>
    </div>
  </div>
  <div class="wrap">
    <div class="cat-grid">
      <?php foreach (category_list() as $c): $n = $counts[$c['key']] ?? 0; ?>
        <a class="cat-tile" href="/boutique.php?cat=<?= h($c['key']) ?>">
          <svg viewBox="0 0 64 64"><use href="#<?= h($c['icon']) ?>"/></svg>
          <span class="name"><?= h($c['label']) ?></span>
          <span class="count"><?= $n ?> <?= h(tenant_text($n === 1 ? 'item_singular' : 'item_plural')) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($promo): ?>
<section class="tight">
  <div class="wrap">
    <div class="section-head">
      <p class="eyebrow">En ce moment</p>
      <h2 style="font-size:1.9rem;">En promo</h2>
    </div>
    <div class="grid-products">
      <?php foreach ($promo as $i => $p) echo product_card_html($p, $i); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="tight">
  <div class="wrap">
    <div class="section-head">
      <p class="eyebrow">Cette semaine</p>
      <h2 style="font-size:1.9rem;">Coups de cœur du moment</h2>
    </div>
    <div class="grid-products">
      <?php foreach ($featured as $i => $p) echo product_card_html($p, $i); ?>
    </div>
    <div class="featured-foot">
      <a class="btn btn-ghost" href="/boutique.php">Voir toute la boutique →</a>
    </div>
  </div>
</section>

<?= rec_for_you_section_html(array_merge(array_column($promo, 'ref'), array_column($featured, 'ref'))) ?>

<?php if ($followPhotos): ?>
<section class="follow tight">
  <div class="wrap">
    <p class="eyebrow"><?= h(tenant_text('follow_eyebrow')) ?></p>
    <h2 style="font-size:1.6rem;margin-top:10px;"><?= h(tenant_text('follow_title')) ?></h2>
    <div class="follow-grid">
      <?php foreach ($followPhotos as $p): ?>
        <div class="follow-tile"><img src="/<?= h($p['photo']) ?>" alt=""></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section id="contact">
  <?php if ($contactBanner): ?>
    <div class="wrap">
      <div class="banner-zone has-page-banner">
        <?php render_page_banner('contact'); ?>
        <p class="eyebrow">Nous trouver</p>
        <h2>Contact</h2>
      </div>
    </div>
  <?php endif; ?>
  <div class="wrap contact-grid">
    <div>
      <p class="eyebrow">Restez informé·e</p>
      <h2 style="font-size:1.9rem;margin:12px 0 10px;"><?= h(tenant_text('newsletter_title')) ?></h2>
      <p class="lede"><?= h(tenant_text('newsletter_text')) ?></p>
      <form method="post" action="/newsletter.php">
        <div class="field-row">
          <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
          <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
          <button class="btn btn-primary" type="submit">S'abonner</button>
        </div>
        <?php if (!empty($_GET['inscrit'])): ?>
          <p class="form-note">Inscription enregistrée — merci !</p>
        <?php endif; ?>
      </form>
    </div>
    <div>
      <p class="eyebrow">Visiter l'atelier</p>
      <ul class="info-list">
        <li><strong>Adresse</strong> <?= h($content['contact_address']) ?></li>
        <li><strong>Horaires</strong> <?= h($content['contact_hours']) ?></li>
        <li><strong>Livraison</strong> <?= h($content['contact_delivery']) ?></li>
      </ul>
      <?= shop_locate_actions_html(['class' => 'shop-locate-contact-actions']) ?>
    </div>
  </div>
</section>

<?= plugin_render('visiteur_link') ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
