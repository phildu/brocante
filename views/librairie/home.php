<?php
// Modèle « Librairie » — accueil : « à la une » en vedette, puis les rayons à gauche et des étagères (rangées défilantes de couvertures) à droite,
// le mot du libraire, les infos pratiques. Variables de index.php : $content, $products, $featured, $promo, $counts, $followPhotos.
$spot = $heroFeatured ?? ($promo[0] ?? ($products[0] ?? null));
$shelves = [];
if ($featured) $shelves[] = ['Coups de cœur', 'Choisis par la maison', array_slice($featured, 0, 12), '/boutique.php'];
if ($promo) $shelves[] = ['À prix doux', 'Les petits prix du moment', array_slice($promo, 0, 12), '/boutique.php'];
$shownCats = 0;
foreach (category_list() as $c) {
    if ($shownCats >= 3) break;
    $inCat = array_values(array_filter($products, static fn (array $p): bool => $p['cat'] === $c['key']));
    if (count($inCat) < 2) continue;
    $shelves[] = ['Rayon ' . mb_strtolower($c['label']), count($inCat) . ' ' . tenant_text(count($inCat) === 1 ? 'item_singular' : 'item_plural'), array_slice($inCat, 0, 12), '/boutique.php?cat=' . urlencode($c['key'])];
    $shownCats++;
}
if (!$shelves && $products) $shelves[] = ['Le catalogue', 'À découvrir', array_slice($products, 0, 12), '/boutique.php'];
?>
<section class="lb-hero">
  <div class="wrap lb-hero-grid">
    <div class="lb-hero-text">
      <p class="eyebrow"><?= h($content['hero_eyebrow']) ?></p>
      <h1><?= h($content['hero_title']) ?></h1>
      <p class="lede"><?= h($content['hero_subtitle']) ?></p>
      <form class="lb-hero-search" method="get" action="/boutique.php" role="search">
        <input type="search" name="q" placeholder="Un titre, un auteur, un mot-clé…" aria-label="Rechercher dans le catalogue">
        <button class="btn btn-primary" type="submit">Chercher</button>
      </form>
      <p class="lb-hero-links"><a href="/boutique.php">Parcourir tout le catalogue →</a></p>
    </div>
    <?php if ($spot): $spotCover = shop_cover($spot); ?>
      <article class="lb-spot">
        <p class="lb-spot-label">À la une</p>
        <a class="lb-spot-cover" href="/produit.php?ref=<?= urlencode($spot['ref']) ?>">
          <?php if ($spotCover): ?><img src="/<?= h($spotCover) ?>" alt="<?= h($spot['name']) ?>"><?php else: ?><span class="lb-cover-empty"><?= h($spot['name']) ?></span><?php endif; ?>
        </a>
        <div class="lb-spot-body">
          <p class="lb-cat"><?= h(category_label($spot['cat'])) ?></p>
          <h2><a href="/produit.php?ref=<?= urlencode($spot['ref']) ?>"><?= h($spot['name']) ?></a></h2>
          <p><?= h(shop_excerpt((string) $spot['description'], 300)) ?></p>
          <p class="lb-price"><?= has_promo_price($spot) ? '<span class="price-old">' . h($spot['price']) . '</span> <span class="price price-promo">' . h($spot['promo_price']) . '</span>' : '<span class="price">' . h($spot['price']) . '</span>' ?></p>
          <a class="btn btn-primary" href="/produit.php?ref=<?= urlencode($spot['ref']) ?>">Voir la fiche</a>
        </div>
      </article>
    <?php endif; ?>
  </div>
  <ul class="wrap lb-promises">
    <?php for ($i = 1; $i <= 3; $i++): if (trim((string) $content["principle{$i}_title"]) === '') continue; ?>
      <li><strong><?= h($content["principle{$i}_title"]) ?></strong><span><?= h(shop_excerpt((string) $content["principle{$i}_text"], 70)) ?></span></li>
    <?php endfor; ?>
  </ul>
</section>

<section class="lb-main">
  <div class="wrap lb-home-grid">
    <aside class="lb-rayons-list" aria-label="Les rayons">
      <h2>Les rayons</h2>
      <ul>
        <li><a href="/boutique.php">Tout le catalogue <span><?= count($products) ?></span></a></li>
        <?php foreach (category_list() as $c): ?>
          <li><a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?> <span><?= (int) ($counts[$c['key']] ?? 0) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </aside>
    <div class="lb-shelves">
      <?php if (!$shelves): ?><p class="empty-state">Le catalogue arrive bientôt.</p><?php endif; ?>
      <?php foreach ($shelves as [$title, $sub, $items, $more]): ?>
        <section class="lb-shelf">
          <header class="lb-shelf-head"><div><h2><?= h($title) ?></h2><p><?= h($sub) ?></p></div><a href="<?= h($more) ?>">Voir tout →</a></header>
          <div class="lb-row"><?php foreach ($items as $i => $p) echo product_card_html($p, $i); ?></div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="lb-word" id="histoire">
  <div class="wrap lb-word-grid">
    <div>
      <p class="eyebrow"><?= h($content['story_eyebrow']) ?></p>
      <h2><?= h($content['story_title']) ?></h2>
      <blockquote><?= nl2br(h($content['story_text'])) ?></blockquote>
    </div>
    <?php if (!empty($content['story_photo'])): ?>
      <figure class="lb-word-photo"><img src="/<?= h($content['story_photo']) ?>" alt=""><figcaption><?= h($content['story_photo_caption']) ?></figcaption></figure>
    <?php endif; ?>
  </div>
</section>

<section class="lb-contact" id="contact">
  <div class="wrap lb-contact-grid">
    <div><h2>Passer nous voir</h2>
      <ul class="info-list">
        <li><strong>Adresse</strong> <?= h($content['contact_address']) ?></li>
        <li><strong>Horaires</strong> <?= h($content['contact_hours']) ?></li>
        <li><strong>Livraison</strong> <?= h($content['contact_delivery']) ?></li>
      </ul></div>
    <div><h2><?= h(tenant_text('newsletter_title')) ?></h2><p><?= h(tenant_text('newsletter_text')) ?></p>
      <form method="post" action="/newsletter.php" class="lb-news">
        <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
        <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
        <button type="submit">S'abonner</button>
      </form>
      <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?></div>
  </div>
</section>
