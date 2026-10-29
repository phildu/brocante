<?php
// Modèle « Marché » — carte : photo carrée, étiquette, nom, rayon et prix, ajout rapide. Variables : $p, $i, $href, $gallery, $priceHtml.
$cover = shop_cover($p, $gallery);
$size = trim((string) ($p['size_text'] ?? ''));
?>
<article class="mk-card" data-cat="<?= h($p['cat']) ?>">
  <a class="mk-card-media" href="<?= h($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($cover): ?><img src="/<?= h($cover) ?>" alt="" loading="lazy"><?php else: ?><span class="mk-card-empty"><?= h(mb_substr($p['name'], 0, 36)) ?></span><?php endif; ?>
    <?php if (shop_sales_enabled() && has_promo_price($p)): ?><span class="mk-card-flag">Promo</span><?php elseif (trim((string) $p['badge']) !== ''): ?><span class="mk-card-flag mk-flag-soft"><?= h($p['badge']) ?></span><?php endif; ?>
  </a>
  <div class="mk-card-info">
    <h3><a href="<?= h($href) ?>"><?= h($p['name']) ?></a></h3>
    <p class="mk-card-meta"><?= h(category_label($p['cat'])) ?><?= $size !== '' ? ' · ' . h($size) : '' ?></p>
    <?php if (shop_sales_enabled()): ?><p class="mk-card-price"><?= $priceHtml ?></p><?php endif; ?>
  </div>
  <div class="mk-card-add"><?= shop_add_form($p, '+', 'mk-add') ?></div>
</article>
