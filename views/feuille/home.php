<?php
// Modèle « Feuille » — accueil : une page qui se lit. Sommaire des rayons, planche d'ouverture, chapitres numérotés (sélection, prix doux, maison) et lettre.
$spot = $heroFeatured ?? ($promo[0] ?? ($products[0] ?? null));
$plate = $spot ? shop_cover($spot) : null;
$selection = array_slice($featured ?: $products, 0, 3);
$storyImg = !empty($content['story_photo']) ? (string) $content['story_photo'] : (!empty($heroSlides[1]['path']) ? (string) $heroSlides[1]['path'] : ($spot ? shop_second_image($spot) : null));
$cats = array_values(array_filter(category_list(), static fn (array $c): bool => ($counts[$c['key']] ?? 0) > 0));
$promoItems = shop_sales_enabled() ? array_slice($promo, 0, 3) : [];
$promoLead = $promoItems[0] ?? null;
$itemWord = static fn (int $n): string => tenant_text($n === 1 ? 'item_singular' : 'item_plural');
$chapters = ['I.', 'II.', 'III.', 'IV.'];
$ci = 0;
$excerpt = static fn (array $p, int $max): string => mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', (string) $p['description'])), 0, $max, '…');
$priceOf = static function (array $p): string {
    if (!shop_sales_enabled()) return '';
    return has_promo_price($p)
        ? '<span class="price-old">' . h($p['price']) . '</span> <span class="price price-promo">' . h($p['promo_price']) . '</span>'
        : '<span class="price">' . h($p['price']) . '</span>';
};
?>
<article class="fl-intro">
  <div class="fl-intro-grid">
    <div class="fl-intro-head">
      <p class="fl-eyebrow"><?= h($content['hero_eyebrow']) ?></p>
      <h1><?= h($content['hero_title']) ?></h1>
      <p class="fl-lede"><?= h($content['hero_subtitle']) ?></p>
      <p class="fl-actions"><a class="fl-btn" href="/boutique.php">Ouvrir la collection</a><a class="fl-textlink" href="#histoire">Lire la maison</a></p>
      <p class="fl-share"><?= share_page_button((string) ($content['site_name'] ?: tenant('name')), (string) $content['hero_subtitle']) ?></p>
    </div>
    <aside class="fl-toc" aria-label="Sommaire des rayons">
      <p class="fl-toc-title">Au sommaire</p>
      <ol>
        <?php foreach ($cats as $c): ?>
          <li><a href="/boutique.php?cat=<?= urlencode($c['key']) ?>"><span><?= h($c['label']) ?></span><i aria-hidden="true"></i><b><?= (int) ($counts[$c['key']] ?? 0) ?></b></a></li>
        <?php endforeach; ?>
        <li class="fl-toc-all"><a href="/boutique.php"><span>Tout le catalogue</span><i aria-hidden="true"></i><b><?= count($products) ?></b></a></li>
      </ol>
      <p class="fl-toc-meta">Pièces uniques — <?= count($products) ?> <?= h($itemWord(count($products))) ?></p>
    </aside>
  </div>
</article>

<?php if ($plate): ?>
<figure class="fl-plate">
  <img src="/<?= h($plate) ?>" alt="">
  <?php if ($spot): ?><figcaption><?= h($spot['name']) ?> — <?= h(category_label($spot['cat'])) ?></figcaption><?php endif; ?>
</figure>
<?php endif; ?>

<?php if ($selection): ?>
<section class="fl-chapter" id="selection">
  <header class="fl-chapter-head">
    <span class="fl-chapter-num" aria-hidden="true"><?= $chapters[$ci++] ?></span>
    <h2>La sélection</h2>
    <a class="fl-textlink" href="/boutique.php">Tout voir →</a>
  </header>
  <div class="fl-rows">
    <?php foreach ($selection as $i => $p): $img = shop_cover($p); ?>
      <article class="fl-row">
        <a class="fl-row-media" href="/produit.php?ref=<?= urlencode($p['ref']) ?>" tabindex="-1" aria-hidden="true">
          <?php if ($img): ?><img src="/<?= h($img) ?>" alt="" loading="lazy"><?php else: ?><span class="fl-row-empty"><?= h(mb_substr($p['name'], 0, 40)) ?></span><?php endif; ?>
        </a>
        <div class="fl-row-body">
          <p class="fl-row-num"><?= sprintf('%02d', $i + 1) ?> — <?= h(category_label($p['cat'])) ?></p>
          <h3><a href="/produit.php?ref=<?= urlencode($p['ref']) ?>"><?= h($p['name']) ?></a></h3>
          <?php if (trim((string) $p['description']) !== ''): ?><p class="fl-row-text"><?= h($excerpt($p, 190)) ?></p><?php endif; ?>
          <p class="fl-row-foot"><?= $priceOf($p) ?><a class="fl-row-cta" href="/produit.php?ref=<?= urlencode($p['ref']) ?>">Voir la pièce →</a></p>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($promoLead): $ppImg = shop_cover($promoLead); ?>
<section class="fl-chapter fl-chapter-promo">
  <header class="fl-chapter-head">
    <span class="fl-chapter-num" aria-hidden="true"><?= $chapters[$ci++] ?></span>
    <h2>À prix doux</h2>
  </header>
  <div class="fl-note">
    <?php if ($ppImg): ?>
      <a class="fl-note-media" href="/produit.php?ref=<?= urlencode($promoLead['ref']) ?>"><img src="/<?= h($ppImg) ?>" alt="<?= h($promoLead['name']) ?>" loading="lazy"></a>
    <?php endif; ?>
    <div class="fl-note-body">
      <p class="fl-note-lead">Quelques pièces dont le prix a été revu — parce qu'une belle chose qui reste dans le carton ne sert à personne.</p>
      <ul class="fl-promo-list">
        <?php foreach ($promoItems as $pi): ?>
          <li><a href="/produit.php?ref=<?= urlencode($pi['ref']) ?>"><span><?= h($pi['name']) ?></span><i aria-hidden="true"></i><span><?= $priceOf($pi) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <p><a class="fl-textlink" href="/boutique.php?tri=prix-asc">Parcourir par prix →</a></p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="fl-chapter" id="histoire">
  <header class="fl-chapter-head">
    <span class="fl-chapter-num" aria-hidden="true"><?= $chapters[$ci++] ?></span>
    <h2><?= h($content['story_title']) ?></h2>
  </header>
  <div class="fl-story">
    <div class="fl-story-text">
      <p class="fl-eyebrow"><?= h($content['story_eyebrow']) ?></p>
      <p class="fl-story-lede"><?= nl2br(h($content['story_text'])) ?></p>
      <p class="fl-sign">— <?= h($content['site_name'] ?: tenant('name')) ?></p>
    </div>
    <?php if ($storyImg): ?>
      <figure class="fl-story-media">
        <img src="/<?= h($storyImg) ?>" alt="" loading="lazy">
        <?php if (trim((string) $content['site_tagline']) !== ''): ?><figcaption><?= h($content['site_tagline']) ?></figcaption><?php endif; ?>
      </figure>
    <?php endif; ?>
  </div>
</section>

<section class="fl-chapter fl-chapter-contact" id="contact">
  <header class="fl-chapter-head">
    <span class="fl-chapter-num" aria-hidden="true"><?= $chapters[$ci++] ?></span>
    <h2>Nous trouver</h2>
  </header>
  <div class="fl-contact">
    <div class="fl-contact-card">
      <address><?= nl2br(h($content['contact_address'])) ?></address>
      <dl>
        <?php if (trim((string) $content['contact_hours']) !== ''): ?><div><dt>Horaires</dt><dd><?= h($content['contact_hours']) ?></dd></div><?php endif; ?>
        <?php if (trim((string) $content['contact_delivery']) !== ''): ?><div><dt>Livraison</dt><dd><?= h($content['contact_delivery']) ?></dd></div><?php endif; ?>
        <?php if (trim((string) ($content['pickup_label'] ?? '')) !== ''): ?><div><dt>Sur place</dt><dd><?= h($content['pickup_label']) ?></dd></div><?php endif; ?>
      </dl>
      <p><a class="fl-textlink" href="/boutique.php">Parcourir la collection →</a></p>
    </div>
    <div class="fl-letter">
      <p class="fl-eyebrow">La lettre</p>
      <h3><?= h(tenant_text('newsletter_title')) ?></h3>
      <p><?= h(tenant_text('newsletter_text')) ?></p>
      <form method="post" action="/newsletter.php" class="fl-news">
        <label for="fl-news-email">Votre adresse e-mail</label>
        <div class="fl-news-row">
          <input id="fl-news-email" type="email" name="email" required placeholder="votre@email.fr" autocomplete="email">
          <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
          <button type="submit">Recevoir</button>
        </div>
      </form>
      <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
    </div>
  </div>
</section>
