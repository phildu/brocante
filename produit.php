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

// Prix et boutons d'achat, rendus deux fois : dans la fiche, et dans la barre collée en haut sur smartphone.
$priceHtml = has_promo_price($product)
    ? '<span class="price-old">' . h($product['price']) . '</span> <span class="price price-promo">' . h($product['promo_price']) . '</span>'
    : '<span class="price">' . h($product['price']) . '</span>';
$buyHtml = static function () use ($product): string {
    if (!in_stock($product)) {
        return '<span class="badge" style="border-color:var(--ink-soft);color:var(--ink-soft);">Vendue</span>';
    }
    if (!is_fixed_price(effective_price($product))) {
        return '<a class="btn btn-primary" href="/index.php#contact">Nous contacter</a>';
    }
    // « Ajouter au panier » reste sur la fiche ; « Commander » ajoute puis ouvre le panier (le dernier `redirect` envoyé l'emporte).
    return '<form method="post" action="/cart-add.php" class="buy-form">'
        . '<input type="hidden" name="ref" value="' . h($product['ref']) . '">'
        . '<input type="hidden" name="redirect" value="/produit.php?ref=' . h($product['ref']) . '">'
        . '<button class="btn btn-primary" type="submit">Ajouter au panier</button>'
        . '<button class="btn btn-ghost" type="submit" name="redirect" value="/cart.php">Commander</button>'
        . '</form>';
};
?>

<div class="buybar" id="buybar" aria-label="Achat rapide">
  <div class="wrap buybar-in">
    <span class="buybar-price"><?= $priceHtml ?></span>
    <span class="buybar-actions"><?= $buyHtml() ?></span>
  </div>
</div>

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

      <?php $etat = product_condition($product['etat'] ?? ''); $nature = product_nature_labels($product['nature'] ?? '', $product['sous_categorie'] ?? ''); ?>
      <?php if ($nature || $etat || !empty($product['materials']) || !empty($product['size_text']) || !empty($product['weight_text'])): ?>
        <ul class="info-list" style="margin-bottom:24px;">
          <?php if ($nature): ?><li><strong>Type</strong> <a href="/boutique.php?nature=<?= urlencode($product['nature']) ?>" class="nature-link"><?= h($nature[0]) ?></a><?php if ($nature[1]): ?> › <a href="/boutique.php?nature=<?= urlencode($product['nature']) ?>&amp;sous=<?= urlencode($product['sous_categorie']) ?>" class="nature-link"><?= h($nature[1]) ?></a><?php endif; ?></li><?php endif; ?>
          <?php if ($etat): ?><li><strong>État</strong> <?= h($etat[0]) ?> <span class="etat-hint">— <?= h($etat[1]) ?></span></li><?php endif; ?>
          <?php if (!empty($product['materials'])): ?><li><strong>Matières</strong> <?= h($product['materials']) ?></li><?php endif; ?>
          <?php if (!empty($product['size_text'])): ?><li><strong>Taille</strong> <?= h($product['size_text']) ?></li><?php endif; ?>
          <?php if (!empty($product['weight_text'])): ?><li><strong>Poids</strong> <?= h($product['weight_text']) ?></li><?php endif; ?>
        </ul>
      <?php endif; ?>

      <div class="card-foot" style="margin-top:auto;">
        <span class="price-group"><?= $priceHtml ?></span>
        <?= $buyHtml() ?>
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
  // La barre d'achat se colle juste sous l'entête collée : on en mesure la hauteur (elle change avec la police, le logo).
  var header = document.querySelector('header.site');
  function setHeaderHeight() { if (header) document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px'); }
  setHeaderHeight();
  window.addEventListener('resize', setHeaderHeight);
  window.addEventListener('load', setHeaderHeight);
})();
(function () {
  var root = document.querySelector('.product-gallery');
  if (!root) return;
  var allSlides = Array.prototype.slice.call(root.querySelectorAll('.pg-slide'));
  var allThumbs = Array.prototype.slice.call(root.querySelectorAll('.pg-thumb'));
  // 3:2 sur ordinateur, 9:16 sur smartphone : seuls les visuels du format de
  // l'écran (classes pg-only-*, voir product_gallery()) font partie du défilement.
  var narrow = window.matchMedia('(max-width: 780px)');
  var slides = [], current = 0;

  // Défilement automatique : 4,5 s par photo, 7 s par vidéo (lue en sourdine). Il s'arrête au survol, au doigt,
  // au clavier, quand la galerie n'est pas à l'écran ou l'onglet masqué, et reprend 12 s après la dernière
  // action manuelle ; il est coupé pour qui demande moins d'animations.
  var PHOTO_MS = 4500, VIDEO_MS = 7000, RESUME_MS = 12000;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var autoTimer = null, resumeTimer = null, hovering = false, onScreen = true, manualPause = false;

  function autoStop() { clearTimeout(autoTimer); autoTimer = null; }
  function autoSchedule() {
    autoStop();
    if (reduceMotion || manualPause || hovering || !onScreen || document.hidden || slides.length < 2) return;
    var ms = slides[current].querySelector('video') ? VIDEO_MS : PHOTO_MS;
    autoTimer = setTimeout(function () { show(current + 1); }, ms);
  }
  // Action manuelle (flèche, miniature, balayage, clavier) : le défilement attend avant de reprendre.
  function manual() {
    manualPause = true; autoStop(); clearTimeout(resumeTimer);
    resumeTimer = setTimeout(function () { manualPause = false; autoSchedule(); }, RESUME_MS);
  }

  function show(i) {
    if (!slides.length) return;
    current = (i + slides.length) % slides.length;
    var active = slides[current];
    allSlides.forEach(function (s) { s.classList.toggle('is-active', s === active); });
    allThumbs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.goto === active.dataset.slide); });
    // Une vidéo ne tourne que lorsqu'elle est affichée.
    allSlides.forEach(function (s) {
      var v = s.querySelector('video');
      if (!v) return;
      if (s === active) { var p = v.play(); if (p && p.catch) p.catch(function () {}); } else v.pause();
    });
    autoSchedule();
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

  root.querySelector('.pg-prev')?.addEventListener('click', function () { manual(); show(current - 1); });
  root.querySelector('.pg-next')?.addEventListener('click', function () { manual(); show(current + 1); });
  allThumbs.forEach(function (t) {
    t.addEventListener('click', function () {
      var idx = slides.findIndex(function (s) { return s.dataset.slide === t.dataset.goto; });
      if (idx >= 0) { manual(); show(idx); }
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') { manual(); show(current - 1); }
    if (e.key === 'ArrowRight') { manual(); show(current + 1); }
  });
  // Pause au survol de la galerie, quand elle sort de l'écran, quand l'onglet est masqué.
  root.addEventListener('mouseenter', function () { hovering = true; autoStop(); });
  root.addEventListener('mouseleave', function () { hovering = false; autoSchedule(); });
  document.addEventListener('visibilitychange', function () { if (document.hidden) autoStop(); else autoSchedule(); });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      onScreen = entries[0].isIntersecting;
      if (onScreen) autoSchedule(); else autoStop();
    }, { threshold: 0.3 }).observe(root);
  }

  var touchStartX = null;
  var main = root.querySelector('.pg-main');
  main.addEventListener('touchstart', function (e) { touchStartX = e.touches[0].clientX; manual(); }, { passive: true });
  main.addEventListener('touchend', function (e) {
    if (touchStartX === null) return;
    var dx = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
    touchStartX = null;
  }, { passive: true });
})();
</script>
