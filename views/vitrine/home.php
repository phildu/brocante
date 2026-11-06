<?php
// Modèle « Vitrine » — accueil : diptyque d'ouverture, index typographique des rayons, sélection en grandes cartes, manifeste plein cadre, contact.
$spot = $heroFeatured ?? ($promo[0] ?? ($products[0] ?? null));
$img1 = !empty($heroSlides[0]['path']) ? (string) $heroSlides[0]['path'] : ($spot ? shop_cover($spot) : null);
$img2 = !empty($heroSlides[1]['path']) ? (string) $heroSlides[1]['path'] : ($spot ? shop_second_image($spot) : null);
$selection = array_slice($featured ?: $products, 0, 4);
$promoItem = $promo[0] ?? null;
$storyImg = !empty($content['story_photo']) ? (string) $content['story_photo'] : $img2;
$cats = array_values(array_filter(category_list(), static fn (array $c): bool => ($counts[$c['key']] ?? 0) > 0));
$itemWord = static fn (int $n): string => tenant_text($n === 1 ? 'item_singular' : 'item_plural');
?>
<section class="vt-hero">
  <div class="vt-hero-copy">
    <p class="vt-kicker"><?= h($content['hero_eyebrow']) ?></p>
    <h1><?= h($content['hero_title']) ?></h1>
    <p class="vt-lede"><?= h($content['hero_subtitle']) ?></p>
    <p class="vt-hero-links"><a class="vt-btn" href="/boutique.php">Entrer dans la collection</a><a class="vt-link" href="/notre-histoire.php">La maison</a></p>
    <p class="vt-share"><?= share_page_button((string) ($content['site_name'] ?: tenant('name')), (string) $content['hero_subtitle']) ?></p>
  </div>
  <div class="vt-hero-media">
    <?php if ($img1): ?><figure class="vt-frame vt-frame-a"><img src="/<?= h($img1) ?>" alt=""></figure><?php endif; ?>
    <?php if ($img2): ?><figure class="vt-frame vt-frame-b"><img src="/<?= h($img2) ?>" alt="" loading="lazy"></figure><?php endif; ?>
    <p class="vt-media-note">Pièces uniques — <?= count($products) ?> <?= h($itemWord(count($products))) ?></p>
  </div>
</section>

<section class="vt-index">
  <div class="vt-sec-head">
    <p class="vt-kicker">Index</p>
    <h2>La collection, rayon par rayon</h2>
    <a class="vt-link" href="/boutique.php">Tout voir →</a>
  </div>
  <ol class="vt-index-list">
    <?php foreach ($cats as $i => $c): ?>
      <li>
        <a href="/boutique.php?cat=<?= urlencode($c['key']) ?>">
          <span class="vt-num"><?= sprintf('%02d', $i + 1) ?></span>
          <span class="vt-cat"><?= h($c['label']) ?></span>
          <span class="vt-count"><?= (int) ($counts[$c['key']] ?? 0) ?> <?= h($itemWord((int) ($counts[$c['key']] ?? 0))) ?></span>
          <span class="vt-arrow" aria-hidden="true">↗</span>
        </a>
      </li>
    <?php endforeach; ?>
    <li class="vt-index-all">
      <a href="/boutique.php">
        <span class="vt-num"><?= sprintf('%02d', count($cats) + 1) ?></span>
        <span class="vt-cat">Tout voir</span>
        <span class="vt-count"><?= count($products) ?> <?= h($itemWord(count($products))) ?></span>
        <span class="vt-arrow" aria-hidden="true">↗</span>
      </a>
    </li>
  </ol>
</section>

<?php if ($selection): ?>
<section class="vt-selection">
  <div class="vt-sec-head">
    <p class="vt-kicker">Sélection</p>
    <h2>Pièces à l'affiche</h2>
    <a class="vt-link" href="/boutique.php">Toute la collection →</a>
  </div>
  <div class="vt-grid"><?php foreach ($selection as $i => $p) echo product_card_html($p, $i); ?></div>
</section>
<?php endif; ?>

<?php if ($promoItem && shop_sales_enabled()): $ppImg = shop_cover($promoItem); ?>
<section class="vt-promo">
  <?php if ($ppImg): ?>
    <a class="vt-promo-media" href="/produit.php?ref=<?= urlencode($promoItem['ref']) ?>"><img src="/<?= h($ppImg) ?>" alt="<?= h($promoItem['name']) ?>" loading="lazy"></a>
  <?php endif; ?>
  <div class="vt-promo-copy">
    <p class="vt-kicker">À prix doux</p>
    <h2><?= h($promoItem['name']) ?></h2>
    <p class="vt-promo-price"><?= has_promo_price($promoItem) ? '<span class="price-old">' . h($promoItem['price']) . '</span> <span class="price price-promo">' . h($promoItem['promo_price']) . '</span>' : '<span class="price">' . h($promoItem['price']) . '</span>' ?></p>
    <a class="vt-btn" href="/produit.php?ref=<?= urlencode($promoItem['ref']) ?>">Voir la pièce</a>
  </div>
</section>
<?php endif; ?>

<section class="vt-manifeste" id="histoire">
  <?php if ($storyImg): ?><img class="vt-manifeste-bg" src="/<?= h($storyImg) ?>" alt="" loading="lazy"><?php endif; ?>
  <div class="vt-manifeste-copy">
    <p class="vt-kicker"><?= h($content['story_eyebrow']) ?></p>
    <h2><?= h($content['story_title']) ?></h2>
    <blockquote><?= nl2br(h($content['story_text'])) ?></blockquote>
    <p><a class="vt-link on-dark" href="#contact">Nous rendre visite</a></p>
  </div>
</section>

<section class="vt-contact" id="contact">
  <div class="vt-contact-grid">
    <div>
      <p class="vt-kicker">Nous trouver</p>
      <h2><?= h($content['contact_address']) ?></h2>
      <dl class="vt-info">
        <div><dt>Horaires</dt><dd><?= h($content['contact_hours']) ?></dd></div>
        <div><dt>Livraison</dt><dd><?= h($content['contact_delivery']) ?></dd></div>
      </dl>
      <?= shop_locate_actions_html(['class' => 'shop-locate-contact-actions']) ?>
    </div>
    <div class="vt-letter">
      <p class="vt-kicker">La lettre</p>
      <h2><?= h(tenant_text('newsletter_title')) ?></h2>
      <p><?= h(tenant_text('newsletter_text')) ?></p>
      <form method="post" action="/newsletter.php" class="vt-news">
        <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
        <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
        <button type="submit">S'abonner</button>
      </form>
      <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
    </div>
  </div>
</section>
