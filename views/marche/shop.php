<?php
// Modèle « Marché » — catalogue : rayons et filtres en pastilles repliables, grille dense de cartes. Variables de boutique.php : $products, $cat, $q, $nature, $sous, $natureCounts.
$sort = in_array($_GET['tri'] ?? '', ['prix-asc', 'prix-desc'], true) ? (string) $_GET['tri'] : '';
$products = shop_sort_products($products, $sort);
$allProducts = get_products();
$catCounts = [];
foreach ($allProducts as $ap) $catCounts[$ap['cat']] = ($catCounts[$ap['cat']] ?? 0) + 1;
$natures = product_nature_options();
$title = $cat !== 'tous' ? category_label($cat) : 'Tout le marché';
$active = [];
if ($cat !== 'tous') $active[] = [category_label($cat), shop_url_with(['cat' => null])];
if ($q !== '') $active[] = ['« ' . $q . ' »', shop_url_with(['q' => null])];
if ($nature !== '') $active[] = [$natures[$nature]['label'] ?? $nature, shop_url_with(['nature' => null, 'sous' => null])];
if ($nature !== '' && $sous !== '') $active[] = [$natures[$nature]['subs'][$sous] ?? $sous, shop_url_with(['sous' => null])];
$itemWord = static fn (int $n): string => tenant_text($n === 1 ? 'item_singular' : 'item_plural');
?>
<section class="mk-shop">
  <div class="wrap-wide">
    <header class="mk-shop-head">
      <p class="mk-kicker"><a href="/index.php">Accueil</a> › <a href="/boutique.php">Marché</a><?= $cat !== 'tous' ? ' › ' . h($title) : '' ?></p>
      <h1><?= h($title) ?> <small><?= count($products) ?> <?= h($itemWord(count($products))) ?></small></h1>
    </header>

    <details class="mk-filters">
      <summary>Rayons, types et tri<?= $active ? ' (' . count($active) . ')' : '' ?></summary>
      <div class="mk-filters-body">
        <?php if ($active): ?>
          <p class="mk-active"><?php foreach ($active as [$label, $remove]): ?><a href="<?= h($remove) ?>"><?= h($label) ?> ×</a><?php endforeach; ?><a class="mk-clear" href="/boutique.php">Tout effacer</a></p>
        <?php endif; ?>
        <div class="mk-filter-group">
          <h2>Rayons</h2>
          <p class="mk-chiprow">
            <a href="<?= h(shop_url_with(['cat' => null])) ?>"<?= $cat === 'tous' ? ' aria-current="true"' : '' ?>>Tout <span><?= count($allProducts) ?></span></a>
            <?php foreach (category_list() as $c): ?>
              <a href="<?= h(shop_url_with(['cat' => $c['key']])) ?>"<?= $cat === $c['key'] ? ' aria-current="true"' : '' ?>><?= h($c['label']) ?> <span><?= (int) ($catCounts[$c['key']] ?? 0) ?></span></a>
            <?php endforeach; ?>
          </p>
        </div>
        <?php if ($natureCounts): ?>
          <div class="mk-filter-group">
            <h2>Types</h2>
            <p class="mk-chiprow">
              <?php foreach ($natures as $nKey => $n): if (empty($natureCounts[$nKey])) continue; ?>
                <a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => null])) ?>"<?= $nature === $nKey ? ' aria-current="true"' : '' ?>><?= h($n['label']) ?> <span><?= (int) $natureCounts[$nKey][0] ?></span></a>
                <?php if ($nature === $nKey && !empty($natureCounts[$nKey][1])): ?>
                  <?php foreach ($n['subs'] as $sKey => $sLabel): if (empty($natureCounts[$nKey][1][$sKey])) continue; ?>
                    <a class="mk-subchip" href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => $sKey])) ?>"<?= $sous === $sKey ? ' aria-current="true"' : '' ?>><?= h($sLabel) ?> <span><?= (int) $natureCounts[$nKey][1][$sKey] ?></span></a>
                  <?php endforeach; ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </p>
          </div>
        <?php endif; ?>
        <div class="mk-filter-group">
          <h2>Tri</h2>
          <p class="mk-chiprow">
            <a href="<?= h(shop_url_with(['tri' => null])) ?>"<?= $sort === '' ? ' aria-current="true"' : '' ?>>Sélection de la maison</a>
            <a href="<?= h(shop_url_with(['tri' => 'prix-asc'])) ?>"<?= $sort === 'prix-asc' ? ' aria-current="true"' : '' ?>>Prix croissant</a>
            <a href="<?= h(shop_url_with(['tri' => 'prix-desc'])) ?>"<?= $sort === 'prix-desc' ? ' aria-current="true"' : '' ?>>Prix décroissant</a>
          </p>
        </div>
      </div>
    </details>

    <div class="mk-toolbar">
      <span><?= $q !== '' ? 'Pour « ' . h($q) . ' » — ' : '' ?><?= count($products) ?> sur l'étal</span>
      <?= share_page_button($title . ' — ' . (get_content()['site_name'] ?: tenant('name')), count($products) . ' résultat(s)') ?>
    </div>

    <?php if ($products): ?>
      <div class="mk-shop-grid"><?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?></div>
    <?php else: ?>
      <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : h(tenant_text('empty_category')) ?></p>
      <?php if ($active): ?><p><a class="mk-btn" href="/boutique.php">Effacer les filtres</a></p><?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<script>
  (function () {
    var d = document.querySelector('.mk-filters');
    if (d) d.open = window.matchMedia('(min-width: 760px)').matches || document.querySelector('.mk-active') !== null;
  })();
</script>
