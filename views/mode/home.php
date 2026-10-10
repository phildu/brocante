<?php
// Modèle « Mode » — accueil façon lookbook : grande image plein écran avec le titre en bas à gauche, bandeau défilant, univers en grandes
// tuiles asymétriques, nouveautés sur une grille serrée, pièce en promo en pleine largeur, histoire sur deux colonnes, photos bord à bord.
// Variables de index.php : $content, $products, $featured, $promo, $heroSlides, $heroFeatured, $followPhotos.
$spot = $heroFeatured ?? ($promo[0] ?? ($products[0] ?? null));
$heroImg = !empty($heroSlides[0]['path']) ? (string) $heroSlides[0]['path'] : ($spot ? shop_cover($spot) : null);
$catPhoto = [];
foreach ($products as $p) {
    if (!isset($catPhoto[$p['cat']]) && ($img = shop_cover($p))) $catPhoto[$p['cat']] = $img;
}
$tiles = array_values(array_filter(category_list(), static fn (array $c): bool => ($counts[$c['key']] ?? 0) > 0));
$new = array_slice($products, 0, 8);
$ticker = array_values(array_filter(array_map(static fn (int $i): string => trim((string) $content["principle{$i}_title"]), [1, 2, 3])));
?>
<section class="md-hero">
  <?php if ($heroImg): ?><img class="md-hero-img" src="/<?= h($heroImg) ?>" alt=""><?php endif; ?>
  <div class="md-hero-shade"></div>
  <div class="wrap md-hero-copy">
    <p class="md-kicker"><?= h($content['hero_eyebrow']) ?></p>
    <h1><?= h($content['hero_title']) ?></h1>
    <p class="md-hero-sub"><?= h($content['hero_subtitle']) ?></p>
    <p class="md-hero-ctas"><a class="md-btn md-btn-light" href="/boutique.php">Découvrir la collection</a> <a class="md-link-light" href="#histoire">Notre histoire</a></p>
  </div>
</section>

<?php if ($ticker): ?>
<div class="md-ticker" aria-hidden="true"><div class="md-ticker-track">
  <?php for ($r = 0; $r < 6; $r++) foreach ($ticker as $t): ?><span><?= h($t) ?></span><i>✦</i><?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($tiles): ?>
<section class="md-cats">
  <div class="wrap-wide">
    <h2 class="md-h">Parcourir</h2>
    <div class="md-cat-grid md-n<?= min(count($tiles), 5) ?>">
      <?php foreach ($tiles as $c): ?>
        <a class="md-cat" href="/boutique.php?cat=<?= h($c['key']) ?>">
          <?php if (!empty($catPhoto[$c['key']])): ?><img src="/<?= h($catPhoto[$c['key']]) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="md-cat-label"><?= h($c['label']) ?><small><?= (int) $counts[$c['key']] ?> <?= h(tenant_text($counts[$c['key']] === 1 ? 'item_singular' : 'item_plural')) ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($new): ?>
<section class="md-new">
  <div class="wrap-wide">
    <header class="md-head-row"><h2 class="md-h">Nouveautés</h2><a href="/boutique.php">Tout voir →</a></header>
    <div class="md-grid"><?php foreach ($new as $i => $p) echo product_card_html($p, $i); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($promo): $pp = $promo[0]; $ppImg = shop_cover($pp); ?>
<section class="md-promo">
  <a class="md-promo-img" href="/produit.php?ref=<?= urlencode($pp['ref']) ?>"><?php if ($ppImg): ?><img src="/<?= h($ppImg) ?>" alt="<?= h($pp['name']) ?>" loading="lazy"><?php endif; ?></a>
  <div class="md-promo-copy">
    <p class="md-kicker">À prix doux</p>
    <h2><?= h($pp['name']) ?></h2>
    <p class="md-promo-price"><?= has_promo_price($pp) ? '<span class="price-old">' . h($pp['price']) . '</span> <span class="price price-promo">' . h($pp['promo_price']) . '</span>' : '<span class="price">' . h($pp['price']) . '</span>' ?></p>
    <a class="md-btn" href="/produit.php?ref=<?= urlencode($pp['ref']) ?>">Voir la pièce</a>
  </div>
</section>
<?php endif; ?>

<section class="md-story" id="histoire">
  <div class="md-story-grid">
    <?php $storyImg = !empty($content['story_photo']) ? (string) $content['story_photo'] : ($spot ? shop_cover($spot) : null); ?>
    <?php if ($storyImg): ?><div class="md-story-img"><img src="/<?= h($storyImg) ?>" alt="" loading="lazy"></div><?php endif; ?>
    <div class="md-story-copy">
      <p class="md-kicker"><?= h($content['story_eyebrow']) ?></p>
      <h2><?= h($content['story_title']) ?></h2>
      <p><?= nl2br(h($content['story_text'])) ?></p>
      <ul class="md-values">
        <?php for ($i = 1; $i <= 3; $i++): if (trim((string) $content["principle{$i}_title"]) === '') continue; ?>
          <li><strong><?= h($content["principle{$i}_title"]) ?></strong><span><?= h(shop_excerpt((string) $content["principle{$i}_text"], 90)) ?></span></li>
        <?php endfor; ?>
      </ul>
    </div>
  </div>
</section>

<?php if ($followPhotos): ?>
<section class="md-follow">
  <h2 class="md-h md-center"><?= h(tenant_text('follow_title')) ?></h2>
  <div class="md-follow-strip"><?php foreach ($followPhotos as $p): ?><img src="/<?= h($p['photo']) ?>" alt="" loading="lazy"><?php endforeach; ?></div>
</section>
<?php endif; ?>

<section class="md-contact" id="contact">
  <div class="wrap md-contact-in">
    <h2 class="md-h"><?= h(tenant_text('newsletter_title')) ?></h2>
    <p><?= h(tenant_text('newsletter_text')) ?></p>
    <form method="post" action="/newsletter.php" class="md-news md-news-big">
      <input type="email" name="email" required placeholder="Votre e-mail" aria-label="Adresse e-mail">
      <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
      <button type="submit">S'abonner</button>
    </form>
    <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
  </div>
</section>

<script>
(function () {
  // L'en-tête flotte sur la grande image, transparent, puis devient plein dès qu'on défile ou qu'on ouvre le menu.
  var head = document.getElementById('md-head');
  if (!head) return;
  function sync() { head.classList.toggle('is-solid', window.scrollY > 40 || document.getElementById('primary-nav').classList.contains('is-open')); }
  sync();
  window.addEventListener('scroll', sync, { passive: true });
  var burger = document.getElementById('burger-btn');
  if (burger) burger.addEventListener('click', function () { setTimeout(sync, 0); });
})();
</script>
