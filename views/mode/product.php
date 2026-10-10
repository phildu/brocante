<?php
// Modèle « Mode » — fiche : les images s'empilent à gauche (la première en grand, les autres par deux ; défilement horizontal sur smartphone), les informations
// restent collées à droite avec la taille en évidence, le bouton pleine largeur et des volets (description, détails, livraison).
// Variables de produit.php : $product, $gallery, $related, $priceHtml, $buyHtml.
$etat = product_condition($product['etat'] ?? '');
$nat = product_nature_labels($product['nature'] ?? '', $product['sous_categorie'] ?? '');
$media = array_values(array_filter($gallery, static fn (array $g): bool => $g['only'] !== 'mobile'));
$cover = shop_cover($product, $gallery);
$fc = get_content();
$size = trim((string) ($product['size_text'] ?? ''));
?>
<div class="buybar" id="buybar" aria-label="Achat rapide">
  <div class="wrap buybar-in">
    <span class="buybar-price"><?= $priceHtml ?></span>
    <span class="buybar-actions"><?= $buyHtml() ?></span>
  </div>
</div>

<section class="md-pdp">
  <div class="md-pdp-grid">
    <div class="md-pdp-media">
      <?php if ($media): ?>
        <?php foreach ($media as $g): ?>
          <figure>
            <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" autoplay muted loop playsinline controls></video>
            <?php else: ?><?= responsive_image_html($g['src'], $g['src_mobile'] ?? null, $product['name'] . ' — ' . $g['label']) ?><?php endif; ?>
            <?php if ($g['illustration']): ?><figcaption>Vue d'illustration générée par IA — pas une photo de cette pièce précise</figcaption><?php endif; ?>
          </figure>
        <?php endforeach; ?>
      <?php elseif ($cover): ?>
        <figure><img src="/<?= h($cover) ?>" alt="<?= h($product['name']) ?>"></figure>
      <?php else: ?>
        <figure class="md-pdp-empty"><span><?= h($product['name']) ?></span></figure>
      <?php endif; ?>
    </div>

    <div class="md-pdp-info">
      <p class="md-crumbs"><a href="/boutique.php">Boutique</a> / <a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>"><?= h(category_label($product['cat'])) ?></a></p>
      <h1><?= h($product['name']) ?></h1>
      <p class="md-pdp-price"><?= $priceHtml ?></p>
      <?php if (trim((string) $product['badge']) !== ''): ?><p class="md-pdp-badge"><?= h($product['badge']) ?></p><?php endif; ?>

      <?php if ($size !== '' || $etat): ?>
        <dl class="md-keys">
          <?php if ($size !== ''): ?><div><dt>Taille</dt><dd><span class="md-pill"><?= h($size) ?></span></dd></div><?php endif; ?>
          <?php if ($etat): ?><div><dt>État</dt><dd><span class="md-pill"><?= h($etat[0]) ?></span></dd></div><?php endif; ?>
        </dl>
      <?php endif; ?>

      <div class="md-pdp-buy"><?= $buyHtml() ?></div>
      <p class="md-unique"><?= in_stock($product) ? 'Pièce unique : une fois partie, elle ne reviendra pas.' : '' ?></p>

      <details class="md-acc" open><summary>Description</summary><div><?= nl2br(h($product['description'])) ?></div></details>
      <details class="md-acc"><summary>Détails</summary>
        <div><ul class="md-details">
          <?php if ($nat): ?><li><b>Type</b> <?= h($nat[0]) ?><?= $nat[1] ? ' › ' . h($nat[1]) : '' ?></li><?php endif; ?>
          <?php if ($etat): ?><li><b>État</b> <?= h($etat[0]) ?> — <?= h($etat[1]) ?></li><?php endif; ?>
          <?php if (!empty($product['materials'])): ?><li><b>Matières</b> <?= h($product['materials']) ?></li><?php endif; ?>
          <?php if ($size !== ''): ?><li><b>Taille</b> <?= h($size) ?></li><?php endif; ?>
          <?php if (!empty($product['weight_text'])): ?><li><b>Poids</b> <?= h($product['weight_text']) ?></li><?php endif; ?>
          <?php if (!empty($product['barcode'])): ?><li><b><?= h(product_barcode_label($product['barcode'])) ?></b> <?= h($product['barcode']) ?></li><?php endif; ?>
          <li><b>Référence</b> N°<?= h($product['ref']) ?></li>
        </ul></div></details>
      <details class="md-acc"><summary>Livraison et retrait</summary>
        <div><?= h((string) ($fc['contact_delivery'] ?? '')) ?><?= !empty($fc['pickup_label']) ? '<br>' . h($fc['pickup_label']) : '' ?>
          <br><a href="/index.php#contact">Une question ? Écrivez-nous.</a></div></details>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="md-new md-related">
  <div class="wrap-wide">
    <header class="md-head-row"><h2 class="md-h">Vous aimerez aussi</h2><a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>">Tout voir →</a></header>
    <div class="md-grid"><?php foreach ($related as $i => $p) echo product_card_html($p, $i); ?></div>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var header = document.querySelector('header.site');
  function setH() { if (header) document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px'); }
  setH(); window.addEventListener('resize', setH); window.addEventListener('load', setH);
})();
</script>
