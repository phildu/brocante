<?php
// Modèle « Mode » — carte : grande image 3:4 sans cadre, seconde image au survol, étiquette promo, nom, taille, prix, ajout rapide. Variables : $p, $i, $href, $gallery, $priceHtml.
$cover = shop_cover($p, $gallery);
$second = shop_second_image($p, $gallery);
$size = trim((string) ($p['size_text'] ?? ''));
?>
<article class="md-card" data-cat="<?= h($p['cat']) ?>">
  <a class="md-media" href="<?= h($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($cover): ?>
      <img class="md-img-a" src="/<?= h($cover) ?>" alt="" loading="lazy">
      <?php if ($second): ?><img class="md-img-b" src="/<?= h($second) ?>" alt="" loading="lazy"><?php endif; ?>
    <?php else: ?><span class="md-media-empty"><?= h(mb_substr($p['name'], 0, 40)) ?></span><?php endif; ?>
    <?php if (has_promo_price($p)): ?><span class="md-tag">Promo</span><?php elseif (trim((string) $p['badge']) !== ''): ?><span class="md-tag md-tag-soft"><?= h($p['badge']) ?></span><?php endif; ?>
  </a>
  <div class="md-info">
    <h3><a href="<?= h($href) ?>"><?= h($p['name']) ?></a></h3>
    <p class="md-sub"><?= h($size !== '' ? $size : category_label($p['cat'])) ?></p>
    <p class="md-price"><?= $priceHtml ?></p>
  </div>
  <div class="md-quick"><?= shop_add_form($p, 'Ajouter au panier', 'md-add') ?></div>
</article>
