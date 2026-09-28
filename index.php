<?php
require_once __DIR__ . '/includes/functions.php';

$content = get_content();
$products = get_products();
$featured = featured_products();
$promo = promo_products();
$heroSlides = hero_slides_list();

// La pile de polaroïds du hero mélange des rôles fixes (accueil, promo du
// moment, coup de cœur) et les photos d'ambiance curées en back office —
// voir includes/footer.php pour la rotation d'affichage et le clic "ramener
// au premier plan". Les 3 premières cartes de la liste occupent les 3
// positions visibles (p1/p2/p3) ; les suivantes attendent leur tour.
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

<div class="welcome-splash" id="welcome-splash">
  <img src="/assets/logo.png" alt="<?= h($content['site_name']) ?>" class="welcome-splash-logo">
  <p class="welcome-splash-text">Bienvenue</p>
</div>

<section class="hero<?= $heroBanner ? ' has-page-banner' : '' ?>">
  <?php if ($heroBanner): ?><?php render_page_banner('accueil'); ?><?php endif; ?>
  <div class="wrap hero-grid">
    <div>
      <p class="eyebrow"><?= h($content['hero_eyebrow']) ?></p>
      <h1><?= h($content['hero_title']) ?></h1>
      <p class="lede"><?= h($content['hero_subtitle']) ?></p>
      <div class="hero-ctas">
        <a class="btn btn-primary" href="/boutique.php">Voir la boutique</a>
        <a class="btn btn-ghost" href="#histoire">Notre histoire</a>
      </div>
    </div>
    <div class="hero-scene hero-polaroid-scene">
      <?php foreach ($heroFrameItems as $fi => $item):
        $posClass = ['p1', 'p2', 'p3'][$fi] ?? 'is-waiting';
        $extraClass = $item['type'] === 'promo' ? ' polaroid-promo' : '';
        $dwell = $item['type'] === 'promo' ? 7000 : 4200;
      ?>
        <div class="polaroid-frame<?= $extraClass ?> <?= $posClass ?>" data-dwell="<?= $dwell ?>">
          <?php if ($item['type'] === 'welcome'): ?>
            <div class="polaroid-welcome-inner">
              <span class="welcome-macaron"><img src="/assets/logo-macaron.png" alt="<?= h($content['site_name']) ?>"></span>
              <p class="welcome-caption">Bienvenue</p>
            </div>
          <?php elseif ($item['type'] === 'promo'): $pr = $item['data']; ?>
            <a href="/produit.php?ref=<?= urlencode($pr['ref']) ?>" class="hero-slide hero-slide-promo is-active">
              <?php if (!empty($pr['photo'])): ?><img src="/<?= h($pr['photo']) ?>" alt="<?= h($pr['name']) ?>"><?php endif; ?>
              <span class="hero-promo-tag">
                <?php if (has_promo_price($pr)): ?>
                  <span class="price-old"><?= h($pr['price']) ?></span> <?= h($pr['promo_price']) ?>
                <?php else: ?>
                  Promo
                <?php endif; ?>
                · <?= h($pr['name']) ?>
              </span>
            </a>
          <?php elseif ($item['type'] === 'featured'): $fp = $item['data']; ?>
            <a href="/produit.php?ref=<?= urlencode($fp['ref']) ?>" class="hero-slide is-active">
              <?php if (!empty($fp['photo'])): ?><img src="/<?= h($fp['photo']) ?>" alt="<?= h($fp['name']) ?>"><?php endif; ?>
              <span class="hero-featured-tag">Coup de cœur · <?= h($fp['name']) ?></span>
            </a>
          <?php else: $s = $item['data']; ?>
            <div class="hero-slide is-active">
              <img src="/<?= h($s['path']) ?>" alt="<?= h($s['caption']) ?>">
              <?php if ($s['caption'] !== ''): ?><span class="hero-slide-caption"><?= h($s['caption']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

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
      <p class="eyebrow">Explorer par univers</p>
      <h2 style="font-size:1.9rem;">Cinq univers, une même armoire</h2>
    </div>
  </div>
  <div class="wrap">
    <div class="cat-grid">
      <?php foreach (category_list() as $c): $n = $counts[$c['key']] ?? 0; ?>
        <a class="cat-tile" href="/boutique.php?cat=<?= h($c['key']) ?>">
          <svg viewBox="0 0 64 64"><use href="#<?= h($c['icon']) ?>"/></svg>
          <span class="name"><?= h($c['label']) ?></span>
          <span class="count"><?= $n ?> <?= $n === 1 ? 'pièce' : 'pièces' ?></span>
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

<?php if ($followPhotos): ?>
<section class="follow tight">
  <div class="wrap">
    <p class="eyebrow">Au fil des trouvailles</p>
    <h2 style="font-size:1.6rem;margin-top:10px;">Suivez l'armoire au quotidien</h2>
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
      <h2 style="font-size:1.9rem;margin:12px 0 10px;">Les nouvelles trouvailles, avant tout le monde</h2>
      <p class="lede">Un e-mail par mois, quand une nouvelle tournée de brocante rentre à l'atelier. Pas plus.</p>
      <form method="post" action="/newsletter.php">
        <div class="field-row">
          <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
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
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
