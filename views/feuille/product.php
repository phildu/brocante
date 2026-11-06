<?php
// Modèle « Feuille » — fiche : récit d'article (grande image, texte courant, planches), achat dans la marge. Variables de produit.php : $product, $gallery, $related, $priceHtml, $buyHtml, $shareBtn.
$etat = product_condition($product['etat'] ?? '');
$nat = product_nature_labels($product['nature'] ?? '', $product['sous_categorie'] ?? '');
$images = array_values(array_filter($gallery, static fn (array $g): bool => $g['only'] !== 'mobile'));
$cover = shop_cover($product, $gallery);
$first = $images[0] ?? null;
if ($first && $cover && $first['src'] === $cover) array_shift($images);
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
  <div class="buybar-in">
    <?php if (shop_sales_enabled()): ?><span class="buybar-price"><?= $priceHtml ?></span><?php endif; ?>
    <div class="buybar-actions"><?= $buyHtml() ?></div>
  </div>
</div>

<article class="fl-pdp">
  <p class="fl-eyebrow"><a href="/index.php">Accueil</a> › <a href="/boutique.php">La collection</a> › <a href="/boutique.php?cat=<?= urlencode($product['cat']) ?>"><?= h(category_label($product['cat'])) ?></a></p>
  <header class="fl-pdp-head">
    <p class="fl-pdp-cat"><?= h(category_label($product['cat'])) ?><?php if (trim((string) $product['badge']) !== ''): ?> · <?= h($product['badge']) ?><?php endif; ?></p>
    <h1><?= h($product['name']) ?></h1>
    <p class="fl-pdp-meta">N°<?= h($product['ref']) ?><?php if ($etat): ?> — <?= h($etat[0]) ?><?php endif; ?><?php if (!empty($product['size_text'])): ?> — <?= h($product['size_text']) ?><?php endif; ?></p>
  </header>

  <div class="fl-pdp-body">
    <div class="fl-pdp-main">
      <?php if ($first && $first['type'] === 'video'): ?>
        <figure class="fl-pdp-cover"><video src="/<?= h($first['src']) ?>" controls muted loop playsinline></video></figure>
      <?php elseif ($cover): ?>
        <figure class="fl-pdp-cover"><img src="/<?= h($cover) ?>" alt="<?= h($product['name']) ?>"></figure>
      <?php endif; ?>

      <div class="fl-pdp-text">
        <p class="fl-dropcap"><?= nl2br(h($product['description'])) ?></p>
      </div>

      <?php foreach ($images as $g): ?>
        <figure class="fl-pdp-plate">
          <?php if ($g['type'] === 'video'): ?><video src="/<?= h($g['src']) ?>" controls muted loop playsinline></video>
          <?php else: ?><img src="/<?= h($g['src']) ?>" alt="<?= h($product['name']) ?> — <?= h($g['label']) ?>" loading="lazy"><?php endif; ?>
          <figcaption><?= h($g['label']) ?><?php if ($g['illustration']): ?> · vue d'illustration générée par IA<?php endif; ?></figcaption>
        </figure>
      <?php endforeach; ?>

      <dl class="fl-pdp-details">
        <?php foreach ($details as $label => $value): ?><div><dt><?= h($label) ?></dt><dd><?= $value ?></dd></div><?php endforeach; ?>
      </dl>
    </div>

    <aside class="fl-pdp-aside" aria-label="Achat">
      <?php if (shop_sales_enabled()): ?>
        <p class="fl-pdp-price"><?= $priceHtml ?></p>
        <?= product_price_extras_html($product) ?>
        <p class="fl-stock <?= in_stock($product) ? 'is-in' : 'is-out' ?>"><?= in_stock($product) ? 'Disponible — pièce unique' : 'Vendue' ?></p>
        <div class="fl-pdp-buy"><?= $buyHtml() ?></div>
        <div class="fl-pdp-share"><?= $shareBtn ?></div>
        <?= $qrHtml ?>
      <?php endif; ?>
      <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== '' || trim((string) ($fc['pickup_label'] ?? '')) !== ''): ?>
        <dl class="fl-pdp-notes">
          <?php if (trim((string) ($fc['contact_hours'] ?? '')) !== ''): ?><div><dt>Horaires</dt><dd><?= h($fc['contact_hours']) ?></dd></div><?php endif; ?>
          <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><div><dt>Livraison</dt><dd><?= h($fc['contact_delivery']) ?></dd></div><?php endif; ?>
          <?php if (trim((string) ($fc['pickup_label'] ?? '')) !== ''): ?><div><dt>Sur place</dt><dd><?= h($fc['pickup_label']) ?></dd></div><?php endif; ?>
        </dl>
      <?php endif; ?>
      <p class="fl-pdp-back"><a class="fl-textlink" href="/boutique.php?cat=<?= urlencode($product['cat']) ?>">← Tout le rayon <?= h(category_label($product['cat'])) ?></a></p>
    </aside>
  </div>
</article>

<?php if ($related): ?>
<section class="fl-related">
  <header class="fl-chapter-head">
    <span class="fl-chapter-num" aria-hidden="true">Suite</span>
    <h2>Dans le même rayon</h2>
    <a class="fl-textlink" href="/boutique.php?cat=<?= urlencode($product['cat']) ?>">Tout voir →</a>
  </header>
  <div class="fl-list"><?php foreach ($related as $i => $p) echo product_card_html($p, $i); ?></div>
</section>
<?php endif; ?>
