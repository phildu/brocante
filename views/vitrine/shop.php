<?php
// Modèle « Vitrine » — catalogue : sommaire de filtres collé à gauche, résultats en grandes cartes. Variables de boutique.php : $products, $cat, $q, $nature, $sous, $natureCounts.
$sort = in_array($_GET['tri'] ?? '', ['prix-asc', 'prix-desc'], true) ? (string) $_GET['tri'] : '';
$products = shop_sort_products($products, $sort);
$allProducts = get_products();
$catCounts = [];
foreach ($allProducts as $ap) $catCounts[$ap['cat']] = ($catCounts[$ap['cat']] ?? 0) + 1;
$natures = product_nature_options();
$title = $cat !== 'tous' ? category_label($cat) : 'Toute la collection';
$active = [];
if ($cat !== 'tous') $active[] = [category_label($cat), shop_url_with(['cat' => null])];
if ($q !== '') $active[] = ['« ' . $q . ' »', shop_url_with(['q' => null])];
if ($nature !== '') $active[] = [$natures[$nature]['label'] ?? $nature, shop_url_with(['nature' => null, 'sous' => null])];
if ($nature !== '' && $sous !== '') $active[] = [$natures[$nature]['subs'][$sous] ?? $sous, shop_url_with(['sous' => null])];
?>
<section class="vt-shop">
  <div class="wrap">
    <header class="vt-shop-head">
      <div>
        <p class="vt-kicker"><a href="/index.php">Accueil</a> › <a href="/boutique.php">Collection</a><?= $cat !== 'tous' ? ' › ' . h($title) : '' ?></p>
        <h1><?= h($title) ?></h1>
      </div>
      <form class="vt-search" method="get" action="/boutique.php" role="search">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Chercher une pièce…" aria-label="Rechercher dans la collection">
        <button type="submit" aria-label="Rechercher">→</button>
      </form>
    </header>

    <div class="vt-shop-grid">
      <aside class="vt-filters" aria-label="Filtrer et trier">
        <details open>
          <summary>Filtrer <span><?= count($products) ?></span></summary>
          <?php if ($active): ?>
            <p class="vt-active"><?php foreach ($active as [$label, $remove]): ?><a href="<?= h($remove) ?>" title="Retirer ce filtre"><?= h($label) ?> ×</a><?php endforeach; ?><a class="vt-clear" href="/boutique.php">Tout effacer</a></p>
          <?php endif; ?>
          <h2>Rayons</h2>
          <ul class="vt-facet">
            <li><a href="<?= h(shop_url_with(['cat' => null])) ?>"<?= $cat === 'tous' ? ' aria-current="true"' : '' ?>>Toute la collection <span><?= count($allProducts) ?></span></a></li>
            <?php foreach (category_list() as $c): ?>
              <li><a href="<?= h(shop_url_with(['cat' => $c['key']])) ?>"<?= $cat === $c['key'] ? ' aria-current="true"' : '' ?>><?= h($c['label']) ?> <span><?= (int) ($catCounts[$c['key']] ?? 0) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
          <?php if ($natureCounts): ?>
            <h2>Types</h2>
            <ul class="vt-facet">
              <?php foreach ($natures as $nKey => $n): if (empty($natureCounts[$nKey])) continue; ?>
                <li><a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => null])) ?>"<?= $nature === $nKey ? ' aria-current="true"' : '' ?>><?= h($n['label']) ?> <span><?= (int) $natureCounts[$nKey][0] ?></span></a>
                  <?php if ($nature === $nKey && !empty($natureCounts[$nKey][1])): ?>
                    <ul class="vt-sub">
                      <?php foreach ($n['subs'] as $sKey => $sLabel): if (empty($natureCounts[$nKey][1][$sKey])) continue; ?>
                        <li><a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => $sKey])) ?>"<?= $sous === $sKey ? ' aria-current="true"' : '' ?>><?= h($sLabel) ?> <span><?= (int) $natureCounts[$nKey][1][$sKey] ?></span></a></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <h2>Tri</h2>
          <ul class="vt-facet">
            <li><a href="<?= h(shop_url_with(['tri' => null])) ?>"<?= $sort === '' ? ' aria-current="true"' : '' ?>>Sélection de la maison</a></li>
            <li><a href="<?= h(shop_url_with(['tri' => 'prix-asc'])) ?>"<?= $sort === 'prix-asc' ? ' aria-current="true"' : '' ?>>Prix croissant</a></li>
            <li><a href="<?= h(shop_url_with(['tri' => 'prix-desc'])) ?>"<?= $sort === 'prix-desc' ? ' aria-current="true"' : '' ?>>Prix décroissant</a></li>
          </ul>
        </details>
      </aside>

      <div class="vt-results">
        <div class="vt-toolbar">
          <span><?= count($products) ?> <?= h(tenant_text(count($products) === 1 ? 'item_singular' : 'item_plural')) ?><?= $q !== '' ? ' pour « ' . h($q) . ' »' : '' ?></span>
          <?= share_page_button($title . ' — ' . (get_content()['site_name'] ?: tenant('name')), count($products) . ' résultat(s)') ?>
        </div>
        <?php if ($products): ?>
          <div class="vt-grid"><?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?></div>
        <?php else: ?>
          <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : h(tenant_text('empty_category')) ?></p>
          <?php if ($active): ?><p><a class="vt-btn" href="/boutique.php">Effacer les filtres</a></p><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script>
  (function () {
    var d = document.querySelector('.vt-filters details');
    if (d) d.open = window.matchMedia('(min-width: 900px)').matches || document.querySelector('.vt-active') !== null;
  })();
</script>
