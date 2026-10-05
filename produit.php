<?php
require_once __DIR__ . '/includes/functions.php';

$ref = (string) ($_GET['ref'] ?? '');
$product = get_product($ref);

if (!$product) {
    http_response_code(404);
    $activeNav = 'boutique';
    $pageTitle = 'Pièce introuvable';
    include __DIR__ . '/includes/header.php';
    echo '<section class="tight"><div class="wrap"><p class="empty-state">Cette pièce n\'existe plus ou a été vendue. <a href="/boutique.php" style="color:var(--accent);">Retour à la boutique →</a></p></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$gallery = product_gallery($product);
$related = array_filter(get_products($product['cat']), fn($p) => $p['ref'] !== $product['ref']);
$related = array_slice(array_values($related), 0, 3);

$activeNav = 'boutique';
$pageTitle = $product['name'];
include __DIR__ . '/includes/header.php';
?>

<section class="tight">
  <div class="wrap">
    <p class="eyebrow"><a href="/boutique.php" style="color:inherit;text-decoration:none;">← La boutique</a> / <?= h(category_label($product['cat'])) ?></p>
  </div>

  <div class="wrap product-detail">
    <div class="product-gallery">
      <?php if ($gallery): ?>
        <div class="pg-main">
          <?php foreach ($gallery as $i => $g): ?>
            <div class="pg-slide<?= $i === 0 ? ' is-active' : '' ?><?= $g['only'] ? ' pg-only-' . $g['only'] : '' ?>" data-slide="<?= $i ?>">
              <?php if ($g['type'] === 'video'): ?>
                <video src="/<?= h($g['src']) ?>" controls muted loop playsinline></video>
              <?php else: ?>
                <?= responsive_image_html($g['src'], $g['src_mobile'] ?? null, $product['name'] . ' — ' . $g['label']) ?>
              <?php endif; ?>
              <?php if ($g['illustration']): ?>
                <span class="pg-illustration-tag">Vue d'illustration générée par IA — pas une photo de cette pièce précise</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (count($gallery) > 1): ?>
            <button type="button" class="pg-arrow pg-prev" aria-label="Image précédente">‹</button>
            <button type="button" class="pg-arrow pg-next" aria-label="Image suivante">›</button>
          <?php endif; ?>
        </div>
        <?php if (count($gallery) > 1): ?>
          <div class="pg-thumbs">
            <?php foreach ($gallery as $i => $g): ?>
              <button type="button" class="pg-thumb<?= $i === 0 ? ' is-active' : '' ?><?= $g['only'] ? ' pg-only-' . $g['only'] : '' ?>" data-goto="<?= $i ?>">
                <?php if ($g['type'] === 'video'): ?>
                  <video src="/<?= h($g['src']) ?>" muted></video>
                <?php else: ?>
                  <img src="/<?= h($g['src']) ?>" alt="">
                <?php endif; ?>
                <span><?= h($g['label']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="pg-main"><div class="pg-slide is-active"><?= product_media_html($product) ?></div></div>
      <?php endif; ?>
    </div>

    <div class="product-info">
      <p class="ref">Réf. N°<?= h($product['ref']) ?></p>
      <h1 style="font-size:clamp(1.6rem,3vw,2.2rem);margin:8px 0 14px;"><?= h($product['name']) ?></h1>
      <span class="badge" style="margin-bottom:16px;display:inline-block;"><?= h($product['badge']) ?></span>
      <p class="lede" style="margin-bottom:24px;"><?= nl2br(h($product['description'])) ?></p>

      <?php if (!empty($product['materials']) || !empty($product['size_text']) || !empty($product['weight_text'])): ?>
        <ul class="info-list" style="margin-bottom:24px;">
          <?php if (!empty($product['materials'])): ?><li><strong>Matières</strong> <?= h($product['materials']) ?></li><?php endif; ?>
          <?php if (!empty($product['size_text'])): ?><li><strong>Taille</strong> <?= h($product['size_text']) ?></li><?php endif; ?>
          <?php if (!empty($product['weight_text'])): ?><li><strong>Poids</strong> <?= h($product['weight_text']) ?></li><?php endif; ?>
        </ul>
      <?php endif; ?>

      <div class="card-foot" style="margin-top:auto;">
        <?php if (has_promo_price($product)): ?>
          <span>
            <span class="price-old" style="font-size:1rem;"><?= h($product['price']) ?></span>
            <span class="price price-promo" style="font-size:1.4rem;"><?= h($product['promo_price']) ?></span>
          </span>
        <?php else: ?>
          <span class="price" style="font-size:1.4rem;"><?= h($product['price']) ?></span>
        <?php endif; ?>
        <?php if (!in_stock($product)): ?>
          <span class="badge" style="border-color:var(--ink-soft);color:var(--ink-soft);">Vendue</span>
        <?php elseif (is_fixed_price(effective_price($product))): ?>
          <form method="post" action="/cart-add.php">
            <input type="hidden" name="ref" value="<?= h($product['ref']) ?>">
            <input type="hidden" name="redirect" value="/produit.php?ref=<?= h($product['ref']) ?>">
            <button class="btn btn-primary" type="submit">Ajouter au panier</button>
          </form>
        <?php else: ?>
          <a class="btn btn-primary" href="/index.php#contact">Nous contacter</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="tight">
  <div class="wrap">
    <div class="section-head">
      <p class="eyebrow">Dans le même univers</p>
      <h2 style="font-size:1.5rem;">Autres pièces en <?= h(mb_strtolower(category_label($product['cat']))) ?></h2>
    </div>
    <div class="grid-products">
      <?php foreach ($related as $i => $p) echo product_card_html($p, $i); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
(function () {
  var root = document.querySelector('.product-gallery');
  if (!root) return;
  var allSlides = Array.prototype.slice.call(root.querySelectorAll('.pg-slide'));
  var allThumbs = Array.prototype.slice.call(root.querySelectorAll('.pg-thumb'));
  // 3:2 sur ordinateur, 9:16 sur smartphone : seuls les visuels du format de
  // l'écran (classes pg-only-*, voir product_gallery()) font partie du défilement.
  var narrow = window.matchMedia('(max-width: 780px)');
  var slides = [], current = 0;

  function show(i) {
    if (!slides.length) return;
    current = (i + slides.length) % slides.length;
    var active = slides[current];
    allSlides.forEach(function (s) { s.classList.toggle('is-active', s === active); });
    allThumbs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.goto === active.dataset.slide); });
  }
  function refresh() {
    var hidden = narrow.matches ? 'pg-only-desktop' : 'pg-only-mobile';
    slides = allSlides.filter(function (s) { return !s.classList.contains(hidden); });
    var multi = slides.length > 1;
    root.querySelectorAll('.pg-arrow, .pg-thumbs').forEach(function (el) { el.style.display = multi ? '' : 'none'; });
    show(0);
  }
  refresh();
  (narrow.addEventListener ? narrow.addEventListener('change', refresh) : narrow.addListener(refresh));
  if (allSlides.length < 2) return;

  root.querySelector('.pg-prev')?.addEventListener('click', function () { show(current - 1); });
  root.querySelector('.pg-next')?.addEventListener('click', function () { show(current + 1); });
  allThumbs.forEach(function (t) {
    t.addEventListener('click', function () {
      var idx = slides.findIndex(function (s) { return s.dataset.slide === t.dataset.goto; });
      if (idx >= 0) show(idx);
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') show(current - 1);
    if (e.key === 'ArrowRight') show(current + 1);
  });

  var touchStartX = null;
  var main = root.querySelector('.pg-main');
  main.addEventListener('touchstart', function (e) { touchStartX = e.touches[0].clientX; }, { passive: true });
  main.addEventListener('touchend', function (e) {
    if (touchStartX === null) return;
    var dx = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
    touchStartX = null;
  }, { passive: true });
})();
</script>
