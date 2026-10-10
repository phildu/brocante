<?php
// Modèle « Librairie » — fiche : couverture en portrait à gauche, grand résumé et fiche détaillée au centre, encart d'achat collé à droite.
// Variables de produit.php : $product, $gallery, $related, $priceHtml, $buyHtml.
$etat = product_condition($product['etat'] ?? '');
$nat = product_nature_labels($product['nature'] ?? '', $product['sous_categorie'] ?? '');
$images = array_values(array_filter($gallery, static fn (array $g): bool => $g['only'] !== 'mobile'));
$cover = shop_cover($product, $gallery);
$fc = get_content();
$details = [];
$details['Rayon'] = '<a href="/boutique.php?cat=' . urlencode($product['cat']) . '">' . h(category_label($product['cat'])) . '</a>';
if ($nat) $details['Type'] = '<a href="/boutique.php?nature=' . urlencode($product['nature']) . '">' . h($nat[0]) . '</a>' . ($nat[1] ? ' › ' . h($nat[1]) : '');
if ($etat) $details['État'] = h($etat[0]) . ' <span class="etat-hint">— ' . h($etat[1]) . '</span>';
if (!empty($product['materials'])) $details['Matières'] = h($product['materials']);
if (!empty($product['size_text'])) $details['Dimensions'] = h($product['size_text']);
if (!empty($product['weight_text'])) $details['Poids'] = h($product['weight_text']);
$details['Référence'] = 'N°' . h($product['ref']);
?>
<div class="buybar" id="buybar" aria-label="Achat rapide">
  <div class="wrap buybar-in">
    <span class="buybar-price"><?= $priceHtml ?></span>
    <span class="buybar-actions"><?= $buyHtml() ?></span>
  </div>
</div>

<section class="lb-pdp">
  <div class="wrap">
    <nav class="lb-crumbs" aria-label="Fil d'Ariane"><a href="/index.php">Accueil</a> › <a href="/boutique.php">Catalogue</a> › <a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>"><?= h(category_label($product['cat'])) ?></a></nav>
    <div class="lb-pdp-grid">
      <div class="lb-pdp-cover">
        <div class="lb-pdp-stage" id="lb-stage">
          <?php if ($images): ?>
            <?php foreach ($images as $i => $g): ?>
              <div class="lb-pdp-slide<?= $i === 0 ? ' is-active' : '' ?>">
                <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" controls muted loop playsinline></video>
                <?php else: ?><img src="/<?= h($g['src']) ?>" alt="<?= h($product['name']) ?> — <?= h($g['label']) ?>"><?php endif; ?>
                <?php if ($g['illustration']): ?><span class="lb-illu">Vue d'illustration générée par IA</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php elseif ($cover): ?>
            <div class="lb-pdp-slide is-active"><img src="/<?= h($cover) ?>" alt="<?= h($product['name']) ?>"></div>
          <?php else: ?>
            <div class="lb-pdp-slide is-active"><span class="lb-cover-empty"><?= h($product['name']) ?></span></div>
          <?php endif; ?>
        </div>
        <?php if (count($images) > 1): ?>
          <div class="lb-pdp-thumbs">
            <?php foreach ($images as $i => $g): ?>
              <button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-i="<?= $i ?>" aria-label="Image <?= $i + 1 ?>">
                <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" muted></video><?php else: ?><img src="/<?= h($g['src']) ?>" alt=""><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="lb-pdp-main">
        <p class="lb-cat"><?= h(category_label($product['cat'])) ?></p>
        <h1><?= h($product['name']) ?></h1>
        <?php if (trim((string) $product['badge']) !== ''): ?><p><span class="badge"><?= h($product['badge']) ?></span></p><?php endif; ?>
        <h2 class="lb-h2">Résumé</h2>
        <div class="lb-summary"><?= nl2br(h($product['description'])) ?></div>
        <h2 class="lb-h2">Fiche détaillée</h2>
        <dl class="lb-details">
          <?php foreach ($details as $label => $value): ?><div><dt><?= h($label) ?></dt><dd><?= $value ?></dd></div><?php endforeach; ?>
        </dl>
      </div>

      <aside class="lb-buybox" aria-label="Acheter">
        <p class="lb-buy-price"><?= $priceHtml ?></p>
        <p class="lb-stock <?= in_stock($product) ? 'is-in' : 'is-out' ?>"><?= in_stock($product) ? 'Disponible — pièce unique' : 'Vendue' ?></p>
        <div class="lb-buy-actions"><?= $buyHtml() ?></div>
        <ul class="lb-assure">
          <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><li><b>Livraison</b> <?= h($fc['contact_delivery']) ?></li><?php endif; ?>
          <?php if (trim((string) ($fc['pickup_label'] ?? '')) !== ''): ?><li><b>Sur place</b> <?= h($fc['pickup_label']) ?></li><?php endif; ?>
          <li><b>Une question ?</b> <a href="/index.php#contact">Écrivez-nous</a></li>
        </ul>
      </aside>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="lb-main lb-related">
  <div class="wrap">
    <section class="lb-shelf">
      <header class="lb-shelf-head"><div><h2>Dans le même rayon</h2><p><?= h(category_label($product['cat'])) ?></p></div><a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>">Voir tout →</a></header>
      <div class="lb-row"><?php foreach ($related as $i => $p) echo product_card_html($p, $i); ?></div>
    </section>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var header = document.querySelector('header.site');
  function setH() { if (header) document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px'); }
  setH(); window.addEventListener('resize', setH); window.addEventListener('load', setH);
  // Vignettes : un clic affiche l'image (ou la vidéo) correspondante.
  var slides = document.querySelectorAll('.lb-pdp-slide'), thumbs = document.querySelectorAll('.lb-pdp-thumbs button');
  thumbs.forEach(function (t) {
    t.addEventListener('click', function () {
      var i = +t.dataset.i;
      slides.forEach(function (s, k) { s.classList.toggle('is-active', k === i); var v = s.querySelector('video'); if (v && k !== i) v.pause(); });
      thumbs.forEach(function (b) { b.classList.toggle('is-active', b === t); });
    });
  });
})();
</script>
