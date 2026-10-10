<?php
// Modèle « Librairie » — carte d'un article : couverture en portrait (2:3), rayon, titre, résumé, prix. Variables : $p, $i, $href, $gallery, $priceHtml.
$cover = shop_cover($p, $gallery);
?>
<article class="lb-card" data-cat="<?= h($p['cat']) ?>">
  <a class="lb-cover" href="<?= h($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($cover): ?><img src="/<?= h($cover) ?>" alt="" loading="lazy"><?php else: ?><span class="lb-cover-empty"><?= h(mb_substr($p['name'], 0, 40)) ?></span><?php endif; ?>
    <?php if (has_promo_price($p)): ?><span class="lb-ribbon">Promo</span><?php endif; ?>
  </a>
  <div class="lb-meta">
    <p class="lb-cat"><?= h(category_label($p['cat'])) ?></p>
    <h3><a href="<?= h($href) ?>"><?= h($p['name']) ?></a></h3>
    <p class="lb-desc"><?= h(shop_excerpt((string) $p['description'], 220)) ?></p>
    <p class="lb-price"><?= $priceHtml ?></p>
    <div class="lb-card-actions"><?= shop_add_form($p, 'Ajouter', 'lb-add') ?></div>
  </div>
</article>
