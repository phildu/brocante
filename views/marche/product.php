<?php
// Modèle « Marché » — fiche : étal d'images à gauche (vignettes), encart d'achat compact à droite, description et fiche en dessous. Variables de produit.php : $product, $gallery, $related, $priceHtml, $buyHtml, $shareBtn.
$etat = product_condition($product['etat'] ?? '');
$nat = product_nature_labels($product['nature'] ?? '', $product['sous_categorie'] ?? '');
$images = array_values(array_filter($gallery, static fn (array $g): bool => $g['only'] !== 'mobile'));
$cover = shop_cover($product, $gallery);
$fc = get_content();
$details = [];
$details['Rayon'] = '<a href="/boutique.php?cat=' . urlencode($product['cat']) . '">' . h(category_label($product['cat'])) . '</a>';
if ($nat) $details['Type'] = '<a href="/boutique.php?nature=' . urlencode($product['nature']) . '">' . h($nat[0]) . '</a>' . ($nat[1] ? ' › ' . h($nat[1]) : '');
if (!empty($product['colors'])) $details['Couleurs'] = product_colors_html($product['colors']);
if (!empty($product['brand'])) $details['Marque'] = h($product['brand']);
if ($etat) $details['État'] = h($etat[0]) . ' <span class="etat-hint">— ' . h($etat[1]) . '</span>';
if (!empty($product['materials'])) $details['Matières'] = h($product['materials']);
if (!empty($product['size_text'])) $details['Dimensions'] = h($product['size_text']);
$details['Référence'] = 'N°' . h($product['ref']);
?>
<div class="buybar" id="buybar" aria-label="Achat rapide">
  <div class="wrap buybar-in">
    <?php if (shop_sales_enabled()): ?><span class="buybar-price"><?= $priceHtml ?></span><?php endif; ?>
    <div class="buybar-actions"><?= $buyHtml() ?></div>
  </div>
</div>

<section class="mk-pdp">
  <div class="wrap-wide">
    <p class="mk-kicker"><a href="/index.php">Accueil</a> › <a href="/boutique.php">Marché</a> › <a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>"><?= h(category_label($product['cat'])) ?></a></p>
    <div class="mk-pdp-grid">
      <div class="mk-pdp-gallery">
        <div class="mk-stage" id="mk-stage">
          <?php if ($images): ?>
            <?php foreach ($images as $i => $g): ?>
              <div class="mk-slide<?= $i === 0 ? ' is-active' : '' ?>">
                <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" controls muted loop playsinline></video>
                <?php else: ?><img src="/<?= h($g['src']) ?>" alt="<?= h($product['name']) ?> — <?= h($g['label']) ?>"><?php endif; ?>
                <?php if ($g['illustration']): ?><span class="mk-illu">Vue d'illustration générée par IA</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php elseif ($cover): ?>
            <div class="mk-slide is-active"><img src="/<?= h($cover) ?>" alt="<?= h($product['name']) ?>"></div>
          <?php else: ?>
            <div class="mk-slide is-active mk-empty"><?= h($product['name']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (count($images) > 1): ?>
          <div class="mk-thumbs">
            <?php foreach ($images as $i => $g): ?>
              <button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-i="<?= $i ?>" aria-label="Image <?= $i + 1 ?>">
                <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" muted></video><?php else: ?><img src="/<?= h($g['src']) ?>" alt=""><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="mk-pdp-desc">
          <h2>La pièce</h2>
          <p><?= nl2br(h($product['description'])) ?></p>
        </div>
      </div>

      <aside class="mk-pdp-side">
        <p class="mk-pdp-cat"><?= h(category_label($product['cat'])) ?></p>
        <h1><?= h($product['name']) ?></h1>
        <?php if (trim((string) $product['badge']) !== ''): ?><p><span class="badge"><?= h($product['badge']) ?></span></p><?php endif; ?>
        <?php if (shop_sales_enabled()): ?>
          <p class="mk-pdp-price"><?= $priceHtml ?></p>
          <?= product_price_extras_html($product) ?>
          <p class="mk-stock <?= in_stock($product) ? 'is-in' : 'is-out' ?>"><?= in_stock($product) ? 'Sur l\'étal — pièce unique' : 'Déjà vendue' ?></p>
        <?php endif; ?>
        <div class="mk-pdp-buy"><?= $buyHtml() ?></div>
        <div class="mk-pdp-share"><?= $shareBtn ?></div>
        <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><p class="mk-pdp-note"><?= h($fc['contact_delivery']) ?></p><?php endif; ?>
        <dl class="mk-details">
          <?php foreach ($details as $label => $value): ?><div><dt><?= h($label) ?></dt><dd><?= $value ?></dd></div><?php endforeach; ?>
        </dl>
      </aside>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="mk-related">
  <div class="wrap-wide">
    <header class="mk-sec-head"><div><p class="mk-kicker">Même rayon</p><h2>À voir aussi</h2></div><a class="mk-link" href="/boutique.php?cat=<?= urlencode($product['cat']) ?>">Tout le rayon →</a></header>
    <div class="mk-shop-grid"><?php foreach ($related as $i => $p) echo product_card_html($p, $i); ?></div>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var slides = document.querySelectorAll('.mk-slide'), thumbs = document.querySelectorAll('.mk-thumbs button');
  thumbs.forEach(function (t) {
    t.addEventListener('click', function () {
      var i = +t.dataset.i;
      slides.forEach(function (s, k) { s.classList.toggle('is-active', k === i); var v = s.querySelector('video'); if (v && k !== i) v.pause(); });
      thumbs.forEach(function (b) { b.classList.toggle('is-active', b === t); });
    });
  });
})();
</script>
