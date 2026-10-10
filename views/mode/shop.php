<?php
// Modèle « Mode » — boutique : titre et effectif, barre collée (univers en onglets, recherche, type, tri), grille serrée de grandes images.
// Variables de boutique.php : $products, $cat, $q, $nature, $sous, $natureCounts.
$sort = in_array($_GET['tri'] ?? '', ['prix-asc', 'prix-desc'], true) ? (string) $_GET['tri'] : '';
$products = shop_sort_products($products, $sort);
$natures = product_nature_options();
$title = $cat !== 'tous' ? category_label($cat) : 'Toute la collection';
?>
<section class="md-shop">
  <div class="wrap-wide">
    <header class="md-shop-head">
      <h1><?= h($title) ?></h1>
      <p><?= count($products) ?> <?= h(tenant_text(count($products) === 1 ? 'item_singular' : 'item_plural')) ?></p>
    </header>
  </div>
  <div class="md-bar2" id="md-bar2">
    <div class="wrap-wide md-bar2-in">
      <nav class="md-tabs" aria-label="Univers">
        <a href="<?= h(shop_url_with(['cat' => null])) ?>"<?= $cat === 'tous' ? ' aria-current="true"' : '' ?>>Tout</a>
        <?php foreach (category_list() as $c): ?><a href="<?= h(shop_url_with(['cat' => $c['key']])) ?>"<?= $cat === $c['key'] ? ' aria-current="true"' : '' ?>><?= h($c['label']) ?></a><?php endforeach; ?>
      </nav>
      <form class="md-controls" method="get" action="/boutique.php" role="search" id="recherche">
        <?php if ($cat !== 'tous'): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher" aria-label="Rechercher">
        <?php if ($natureCounts): ?>
          <select name="nature" aria-label="Type de pièce" onchange="this.form.submit()">
            <option value="">Tous les types</option>
            <?php foreach ($natures as $nKey => $n): if (empty($natureCounts[$nKey])) continue; ?><option value="<?= h($nKey) ?>"<?= $nature === $nKey ? ' selected' : '' ?>><?= h($n['label']) ?> (<?= (int) $natureCounts[$nKey][0] ?>)</option><?php endforeach; ?>
          </select>
          <?php if ($nature !== '' && !empty($natureCounts[$nature][1])): ?>
            <select name="sous" aria-label="Sous-catégorie" onchange="this.form.submit()">
              <option value="">Toutes</option>
              <?php foreach ($natures[$nature]['subs'] as $sKey => $sLabel): if (empty($natureCounts[$nature][1][$sKey])) continue; ?><option value="<?= h($sKey) ?>"<?= $sous === $sKey ? ' selected' : '' ?>><?= h($sLabel) ?></option><?php endforeach; ?>
            </select>
          <?php endif; ?>
        <?php endif; ?>
        <select name="tri" aria-label="Trier" onchange="this.form.submit()">
          <option value="">Sélection</option>
          <option value="prix-asc"<?= $sort === 'prix-asc' ? ' selected' : '' ?>>Prix croissant</option>
          <option value="prix-desc"<?= $sort === 'prix-desc' ? ' selected' : '' ?>>Prix décroissant</option>
        </select>
        <noscript><button type="submit">OK</button></noscript>
      </form>
    </div>
  </div>
  <div class="wrap-wide">
    <?php if ($products): ?>
      <div class="md-grid md-grid-shop"><?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?></div>
    <?php else: ?>
      <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : ($nature !== '' ? 'Aucune pièce de ce type pour le moment.' : h(tenant_text('empty_category'))) ?></p>
      <p><a class="md-btn" href="/boutique.php">Voir toute la collection</a></p>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var header = document.querySelector('header.site');
  function setH() { if (header) document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px'); }
  setH(); window.addEventListener('resize', setH); window.addEventListener('load', setH);
})();
</script>
