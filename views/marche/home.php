<?php
// Modèle « Marché » — accueil : ouverture en collage, puis une seule mosaïque continue (rayons, pièces, promo, récit, appel à parcourir), infos pratiques.
$spot = $heroFeatured ?? ($promo[0] ?? ($products[0] ?? null));
$heroImgs = [];
foreach ($heroSlides as $s) {
    if (!empty($s['path'])) $heroImgs[] = (string) $s['path'];
    if (count($heroImgs) >= 3) break;
}
if (!$heroImgs) {
    foreach ($featured ?: $products as $fp) {
        if ($img = shop_cover($fp)) $heroImgs[] = $img;
        if (count($heroImgs) >= 3) break;
    }
}
$selection = array_slice($featured ?: $products, 0, 6);
$promoItem = $promo[0] ?? null;
$storyImg = !empty($content['story_photo']) ? (string) $content['story_photo'] : ($heroImgs[1] ?? ($spot ? shop_cover($spot) : null));
$cats = array_values(array_filter(category_list(), static fn (array $c): bool => ($counts[$c['key']] ?? 0) > 0));
$itemWord = static fn (int $n): string => tenant_text($n === 1 ? 'item_singular' : 'item_plural');
?>
<section class="mk-hero">
  <div class="wrap-wide mk-hero-grid">
    <div class="mk-hero-copy">
      <p class="mk-kicker"><?= h($content['hero_eyebrow']) ?></p>
      <h1><?= h($content['hero_title']) ?></h1>
      <p class="mk-lede"><?= h($content['hero_subtitle']) ?></p>
      <p class="mk-hero-ctas"><a class="mk-btn" href="/boutique.php">Faire un tour au marché</a><a class="mk-link" href="#rayons">Voir les rayons</a></p>
      <p class="mk-share"><?= share_page_button((string) ($content['site_name'] ?: tenant('name')), (string) $content['hero_subtitle']) ?></p>
    </div>
    <div class="mk-hero-collage">
      <?php foreach (array_slice($heroImgs, 0, 3) as $i => $img): ?>
        <figure class="mk-shot mk-shot-<?= $i + 1 ?>"><img src="/<?= h($img) ?>" alt=""<?= $i > 0 ? ' loading="lazy"' : '' ?>></figure>
      <?php endforeach; ?>
      <span class="mk-hero-badge"><?= count($products) ?> <?= h($itemWord(count($products))) ?> sur l'étal</span>
    </div>
  </div>
</section>

<section class="mk-market" id="rayons">
  <div class="wrap-wide">
    <header class="mk-sec-head">
      <div><p class="mk-kicker">Rayons</p><h2>Le marché</h2></div>
      <a class="mk-link" href="/boutique.php">Tout voir →</a>
    </header>
    <div class="mk-mosaic">
      <?php foreach ($cats as $i => $c): ?>
        <a class="mk-tile mk-tile-cat" href="/boutique.php?cat=<?= urlencode($c['key']) ?>">
          <span class="mk-tile-kicker">Rayon <?= sprintf('%02d', $i + 1) ?></span>
          <strong><?= h($c['label']) ?></strong>
          <small><?= (int) ($counts[$c['key']] ?? 0) ?> <?= h($itemWord((int) ($counts[$c['key']] ?? 0))) ?></small>
          <span class="mk-tile-arrow" aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>

      <?php foreach ($selection as $i => $p): ?>
        <div class="mk-tile mk-tile-product"><?= product_card_html($p, $i) ?></div>
      <?php endforeach; ?>

      <?php if ($promoItem): $ppImg = shop_cover($promoItem); ?>
        <a class="mk-tile mk-tile-promo" href="/produit.php?ref=<?= urlencode($promoItem['ref']) ?>">
          <?php if ($ppImg): ?><img src="/<?= h($ppImg) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="mk-promo-copy"><small>À prix doux</small><strong><?= h($promoItem['name']) ?></strong><span><?= has_promo_price($promoItem) ? '<span class="price-old">' . h($promoItem['price']) . '</span> <span class="price price-promo">' . h($promoItem['promo_price']) . '</span>' : '<span class="price">' . h($promoItem['price']) . '</span>' ?></span></span>
        </a>
      <?php endif; ?>

      <article class="mk-tile mk-tile-story" id="histoire">
        <?php if ($storyImg): ?><img src="/<?= h($storyImg) ?>" alt="" loading="lazy"><?php endif; ?>
        <div class="mk-story-copy">
          <p class="mk-kicker"><?= h($content['story_eyebrow']) ?></p>
          <h2><?= h($content['story_title']) ?></h2>
          <p><?= h(shop_excerpt((string) $content['story_text'], 260)) ?></p>
          <a class="mk-link on-dark" href="#contact">Nous trouver</a>
        </div>
      </article>

      <a class="mk-tile mk-tile-cta" href="/boutique.php">
        <span class="mk-tile-kicker">Tout est là</span>
        <strong>Parcourir le marché</strong>
        <small><?= count($products) ?> <?= h($itemWord(count($products))) ?> en rayon</small>
        <span class="mk-tile-arrow" aria-hidden="true">→</span>
      </a>
    </div>
  </div>
</section>

<section class="mk-info" id="contact">
  <div class="wrap-wide mk-info-grid">
    <div>
      <p class="mk-kicker">Passer nous voir</p>
      <h2><?= h($content['contact_address']) ?></h2>
      <ul class="mk-info-list">
        <li><b>Horaires</b><span><?= h($content['contact_hours']) ?></span></li>
        <li><b>Livraison</b><span><?= h($content['contact_delivery']) ?></span></li>
      </ul>
    </div>
    <div class="mk-newsletter">
      <p class="mk-kicker">La lettre</p>
      <h2><?= h(tenant_text('newsletter_title')) ?></h2>
      <p><?= h(tenant_text('newsletter_text')) ?></p>
      <form method="post" action="/newsletter.php" class="mk-news">
        <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
        <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
        <button type="submit">S'abonner</button>
      </form>
      <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
    </div>
  </div>
</section>
