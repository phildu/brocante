<?php
// Modèle « Feuille » — carte : entrefilet horizontal (image à gauche, texte courant, prix et ajout en pied). Variables : $p, $i, $href, $gallery, $priceHtml, $action.
$cover = shop_cover($p, $gallery);
$text = trim((string) preg_replace('/\s+/u', ' ', (string) $p['description']));
?>
<article class="fl-card" data-cat="<?= h($p['cat']) ?>">
  <a class="fl-card-media" href="<?= h($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($cover): ?><img src="/<?= h($cover) ?>" alt="" loading="lazy"><?php else: ?><span class="fl-card-empty"><?= h(mb_substr($p['name'], 0, 30)) ?></span><?php endif; ?>
  </a>
  <div class="fl-card-body">
    <p class="fl-card-ref">N°<?= h($p['ref']) ?> — <?= h(category_label($p['cat'])) ?><?php if (shop_sales_enabled() && has_promo_price($p)): ?> · <em>prix revu</em><?php endif; ?></p>
    <h3><a href="<?= h($href) ?>"><?= h($p['name']) ?></a></h3>
    <?php if ($text !== ''): ?><p class="fl-card-desc"><?= h(mb_strimwidth($text, 0, 210, '…')) ?></p><?php endif; ?>
    <p class="fl-card-foot"><?php if (shop_sales_enabled()): ?><span class="fl-card-price"><?= $priceHtml ?></span><?php endif; ?><a class="fl-card-link" href="<?= h($href) ?>">Lire la fiche →</a></p>
  </div>
  <div class="fl-card-add"><?= shop_add_form($p, 'Ajouter', 'fl-add') ?></div>
</article>
