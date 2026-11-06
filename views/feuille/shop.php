<?php
// Modèle « Feuille » — catalogue : une seule colonne de lectures, filtres repliés en tête. Variables de boutique.php : $products, $cat, $q, $nature, $sous, $natureCounts.
$sort = in_array($_GET['tri'] ?? '', ['prix-asc', 'prix-desc'], true) ? (string) $_GET['tri'] : '';
$products = shop_sort_products($products, $sort);
$allProducts = get_products();
$catCounts = [];
foreach ($allProducts as $ap) $catCounts[$ap['cat']] = ($catCounts[$ap['cat']] ?? 0) + 1;
$natures = product_nature_options();
$title = $cat !== 'tous' ? category_label($cat) : 'La collection';
$active = [];
if ($cat !== 'tous') $active[] = [category_label($cat), shop_url_with(['cat' => null])];
if ($q !== '') $active[] = ['« ' . $q . ' »', shop_url_with(['q' => null])];
if ($nature !== '') $active[] = [$natures[$nature]['label'] ?? $nature, shop_url_with(['nature' => null, 'sous' => null])];
if ($nature !== '' && $sous !== '') $active[] = [$natures[$nature]['subs'][$sous] ?? $sous, shop_url_with(['sous' => null])];
?>
<section class="fl-shop">
  <header class="fl-shop-head">
    <p class="fl-eyebrow"><a href="/index.php">Accueil</a> › <a href="/boutique.php">La collection</a><?= $cat !== 'tous' ? ' › ' . h($title) : '' ?></p>
    <h1><?= h($title) ?></h1>
    <p class="fl-shop-sub"><?= count($products) ?> <?= h(tenant_text(count($products) === 1 ? 'item_singular' : 'item_plural')) ?><?= $q !== '' ? ' pour « ' . h($q) . ' »' : '' ?> — chaque pièce est unique, une fois partie elle ne revient pas.</p>
    <p class="fl-share"><?= share_page_button($title . ' — ' . (get_content()['site_name'] ?: tenant('name')), count($products) . ' résultat(s)') ?></p>
  </header>

  <details class="fl-filters">
    <summary>Filtrer et trier<span><?= count($products) ?></span></summary>
    <div class="fl-filter-body">
      <?php if ($active): ?>
        <p class="fl-active"><?php foreach ($active as [$label, $remove]): ?><a href="<?= h($remove) ?>" title="Retirer ce filtre"><?= h($label) ?> ×</a><?php endforeach; ?><a class="fl-clear" href="/boutique.php">Tout effacer</a></p>
      <?php endif; ?>
      <form class="fl-search" method="get" action="/boutique.php" role="search">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Chercher un mot, une matière, une marque…" aria-label="Rechercher dans la collection">
        <button type="submit">Chercher</button>
      </form>
      <div class="fl-facets">
        <div>
          <p class="fl-facet-title">Rayons</p>
          <ul>
            <li><a href="<?= h(shop_url_with(['cat' => null])) ?>"<?= $cat === 'tous' ? ' aria-current="true"' : '' ?>>Toute la collection <span><?= count($allProducts) ?></span></a></li>
            <?php foreach (category_list() as $c): ?>
              <li><a href="<?= h(shop_url_with(['cat' => $c['key']])) ?>"<?= $cat === $c['key'] ? ' aria-current="true"' : '' ?>><?= h($c['label']) ?> <span><?= (int) ($catCounts[$c['key']] ?? 0) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php if ($natureCounts): ?>
          <div>
            <p class="fl-facet-title">Types</p>
            <ul>
              <?php foreach ($natures as $nKey => $n): if (empty($natureCounts[$nKey])) continue; ?>
                <li><a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => null])) ?>"<?= $nature === $nKey ? ' aria-current="true"' : '' ?>><?= h($n['label']) ?> <span><?= (int) $natureCounts[$nKey][0] ?></span></a>
                  <?php if ($nature === $nKey && !empty($natureCounts[$nKey][1])): ?>
                    <ul class="fl-sub">
                      <?php foreach ($n['subs'] as $sKey => $sLabel): if (empty($natureCounts[$nKey][1][$sKey])) continue; ?>
                        <li><a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => $sKey])) ?>"<?= $sous === $sKey ? ' aria-current="true"' : '' ?>><?= h($sLabel) ?> <span><?= (int) $natureCounts[$nKey][1][$sKey] ?></span></a></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <div>
          <p class="fl-facet-title">Ordre de lecture</p>
          <ul>
            <li><a href="<?= h(shop_url_with(['tri' => null])) ?>"<?= $sort === '' ? ' aria-current="true"' : '' ?>>Nouveautés d'abord</a></li>
            <li><a href="<?= h(shop_url_with(['tri' => 'prix-asc'])) ?>"<?= $sort === 'prix-asc' ? ' aria-current="true"' : '' ?>>Prix croissant</a></li>
            <li><a href="<?= h(shop_url_with(['tri' => 'prix-desc'])) ?>"<?= $sort === 'prix-desc' ? ' aria-current="true"' : '' ?>>Prix décroissant</a></li>
          </ul>
        </div>
      </div>
    </div>
  </details>

  <?php if ($products): ?>
    <div class="fl-list"><?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?></div>
  <?php else: ?>
    <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : h(tenant_text('empty_category')) ?></p>
    <?php if ($active): ?><p><a class="fl-btn" href="/boutique.php">Effacer les filtres</a></p><?php endif; ?>
  <?php endif; ?>
</section>
<?= plugin_render('boutique_bottom') ?>
<script>
  (function () {
    var d = document.querySelector('.fl-filters');
    if (d) d.open = document.querySelector('.fl-active') !== null;
  })();
</script>
