<?php
// Modèle « Vitrine » — carte : grande image numérotée, légende, prix et ajout rapide. Variables : $p, $i, $href, $gallery, $priceHtml.
$cover = shop_cover($p, $gallery);
$second = shop_second_image($p, $gallery);
?>
<article class="vt-card" data-cat="<?= h($p['cat']) ?>">
  <a class="vt-card-media" href="<?= h($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($cover): ?><img src="/<?= h($cover) ?>" alt="" loading="lazy"><?php else: ?><span class="vt-card-empty"><?= h(mb_substr($p['name'], 0, 40)) ?></span><?php endif; ?>
    <?php if (shop_sales_enabled() && has_promo_price($p)): ?><span class="vt-ribbon">Promo</span><?php endif; ?>
    <span class="vt-card-num"><?= sprintf('%02d', $i + 1) ?></span>
  </a>
  <div class="vt-card-meta">
    <p class="vt-card-cat"><?= h(category_label($p['cat'])) ?></p>
    <h3><a href="<?= h($href) ?>"><?= h($p['name']) ?></a></h3>
    <?php if (shop_sales_enabled()): ?><p class="vt-card-price"><?= $priceHtml ?></p><?php endif; ?>
  </div>
  <div class="vt-card-add"><?= shop_add_form($p, 'Ajouter', 'vt-add') ?></div>
</article>
