<?php
// Modèle « Vitrine » — fiche : images empilées à gauche, récit et achat à droite, fiche détaillée en pied. Variables de produit.php : $product, $gallery, $related, $priceHtml, $buyHtml, $shareBtn.
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
if (!empty($product['weight_text'])) $details['Poids'] = h($product['weight_text']);
$details['Référence'] = 'N°' . h($product['ref']);
?>
<div class="buybar" id="buybar" aria-label="Achat rapide">
  <div class="wrap buybar-in">
    <?php if (shop_sales_enabled()): ?><span class="buybar-price"><?= $priceHtml ?></span><?php endif; ?>
    <div class="buybar-actions"><?= $buyHtml() ?></div>
  </div>
</div>

<section class="vt-pdp">
  <div class="wrap">
    <p class="vt-kicker"><a href="/index.php">Accueil</a> › <a href="/boutique.php">Collection</a> › <a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>"><?= h(category_label($product['cat'])) ?></a></p>
    <div class="vt-pdp-grid">
      <div class="vt-pdp-media">
        <?php if ($images): ?>
          <?php foreach ($images as $i => $g): ?>
            <figure class="vt-pdp-frame<?= $i === 0 ? ' is-first' : '' ?>">
              <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" controls muted loop playsinline></video>
              <?php else: ?><img src="/<?= h($g['src']) ?>" alt="<?= h($product['name']) ?> — <?= h($g['label']) ?>"><?php endif; ?>
              <?php if ($g['illustration']): ?><figcaption>Vue d'illustration générée par IA</figcaption><?php endif; ?>
            </figure>
          <?php endforeach; ?>
        <?php elseif ($cover): ?>
          <figure class="vt-pdp-frame is-first"><img src="/<?= h($cover) ?>" alt="<?= h($product['name']) ?>"></figure>
        <?php else: ?>
          <div class="vt-pdp-frame is-first vt-pdp-empty"><?= h($product['name']) ?></div>
        <?php endif; ?>
      </div>

      <div class="vt-pdp-info">
        <p class="vt-card-cat"><?= h(category_label($product['cat'])) ?></p>
        <h1><?= h($product['name']) ?></h1>
        <?php if (trim((string) $product['badge']) !== ''): ?><p><span class="badge"><?= h($product['badge']) ?></span></p><?php endif; ?>
        <?php if (shop_sales_enabled()): ?>
          <p class="vt-pdp-price"><?= $priceHtml ?></p>
          <?= product_price_extras_html($product) ?>
          <p class="vt-stock <?= in_stock($product) ? 'is-in' : 'is-out' ?>"><?= in_stock($product) ? 'Disponible — pièce unique' : 'Vendue' ?></p>
        <?php endif; ?>
        <div class="vt-pdp-desc"><?= nl2br(h($product['description'])) ?></div>
        <div class="vt-pdp-buy"><?= $buyHtml() ?></div>
        <div class="vt-pdp-share"><?= $shareBtn ?></div>
        <?= $qrHtml ?>
        <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== '' || trim((string) ($fc['pickup_label'] ?? '')) !== ''): ?>
          <ul class="vt-assure">
            <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><li><b>Livraison</b> <?= h($fc['contact_delivery']) ?></li><?php endif; ?>
            <?php if (trim((string) ($fc['pickup_label'] ?? '')) !== ''): ?><li><b>Sur place</b> <?= h($fc['pickup_label']) ?></li><?php endif; ?>
          </ul>
        <?php endif; ?>
        <dl class="vt-details">
          <?php foreach ($details as $label => $value): ?><div><dt><?= h($label) ?></dt><dd><?= $value ?></dd></div><?php endforeach; ?>
        </dl>
      </div>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="vt-related">
  <div class="vt-sec-head">
    <p class="vt-kicker">Même rayon</p>
    <h2><?= h(category_label($product['cat'])) ?></h2>
    <a class="vt-link" href="/boutique.php?cat=<?= urlencode($product['cat']) ?>">Tout voir →</a>
  </div>
  <div class="vt-grid"><?php foreach ($related as $i => $p) echo product_card_html($p, $i); ?></div>
</section>
<?php endif; ?>
