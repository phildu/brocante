<?php
// Modèle « Librairie » — catalogue : filtres à gauche (rayons et types avec leurs effectifs, filtres actifs effaçables, repliés en « Filtrer » sur
// smartphone), barre de résultats avec tri et choix grille / liste, puis les couvertures. Variables de boutique.php : $products, $cat, $q, $nature, $sous, $natureCounts.
$sort = in_array($_GET['tri'] ?? '', ['prix-asc', 'prix-desc'], true) ? (string) $_GET['tri'] : '';
$vue = ($_GET['vue'] ?? '') === 'liste' ? 'liste' : 'grille';
$products = shop_sort_products($products, $sort);
$allProducts = get_products();
$catCounts = [];
foreach ($allProducts as $ap) $catCounts[$ap['cat']] = ($catCounts[$ap['cat']] ?? 0) + 1;
$natures = product_nature_options();
$title = $cat !== 'tous' ? category_label($cat) : 'Tout le catalogue';
$active = [];   // filtres actifs : [libellé, adresse qui le retire]
if ($cat !== 'tous') $active[] = [category_label($cat), shop_url_with(['cat' => null])];
if ($nature !== '') $active[] = [$natures[$nature]['label'] ?? $nature, shop_url_with(['nature' => null, 'sous' => null])];
if ($nature !== '' && $sous !== '') $active[] = [$natures[$nature]['subs'][$sous] ?? $sous, shop_url_with(['sous' => null])];
if ($q !== '') $active[] = ['« ' . $q . ' »', shop_url_with(['q' => null])];
?>
<section class="lb-shop">
  <div class="wrap">
    <nav class="lb-crumbs" aria-label="Fil d'Ariane"><a href="/index.php">Accueil</a> › <a href="/boutique.php">Catalogue</a><?= $cat !== 'tous' ? ' › ' . h($title) : '' ?></nav>
    <h1><?= h($title) ?> <small><?= count($products) ?> <?= h(tenant_text(count($products) === 1 ? 'item_singular' : 'item_plural')) ?></small></h1>

    <div class="lb-shop-grid">
      <details class="lb-filters" id="lb-filters" open>
        <summary>Filtrer et trier<?= $active ? ' (' . count($active) . ')' : '' ?></summary>
        <?php if ($active): ?>
          <div class="lb-active">
            <?php foreach ($active as [$label, $remove]): ?><a class="lb-chip" href="<?= h($remove) ?>" title="Retirer ce filtre"><?= h($label) ?> ×</a><?php endforeach; ?>
            <a class="lb-clear" href="/boutique.php">Tout effacer</a>
          </div>
        <?php endif; ?>
        <h2>Rayons</h2>
        <ul class="lb-facet">
          <li><a href="<?= h(shop_url_with(['cat' => null])) ?>"<?= $cat === 'tous' ? ' aria-current="true"' : '' ?>>Tous <span><?= count($allProducts) ?></span></a></li>
          <?php foreach (category_list() as $c): ?>
            <li><a href="<?= h(shop_url_with(['cat' => $c['key']])) ?>"<?= $cat === $c['key'] ? ' aria-current="true"' : '' ?>><?= h($c['label']) ?> <span><?= (int) ($catCounts[$c['key']] ?? 0) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($natureCounts): ?>
          <h2>Types</h2>
          <ul class="lb-facet">
            <?php foreach ($natures as $nKey => $n): if (empty($natureCounts[$nKey])) continue; ?>
              <li><a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => null])) ?>"<?= $nature === $nKey ? ' aria-current="true"' : '' ?>><?= h($n['label']) ?> <span><?= (int) $natureCounts[$nKey][0] ?></span></a>
                <?php if ($nature === $nKey && !empty($natureCounts[$nKey][1])): ?>
                  <ul class="lb-facet lb-sub">
                    <?php foreach ($n['subs'] as $sKey => $sLabel): if (empty($natureCounts[$nKey][1][$sKey])) continue; ?>
                      <li><a href="<?= h(shop_url_with(['nature' => $nKey, 'sous' => $sKey])) ?>"<?= $sous === $sKey ? ' aria-current="true"' : '' ?>><?= h($sLabel) ?> <span><?= (int) $natureCounts[$nKey][1][$sKey] ?></span></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <h2>Trier par prix</h2>
        <ul class="lb-facet">
          <li><a href="<?= h(shop_url_with(['tri' => null])) ?>"<?= $sort === '' ? ' aria-current="true"' : '' ?>>Sélection de la maison</a></li>
          <li><a href="<?= h(shop_url_with(['tri' => 'prix-asc'])) ?>"<?= $sort === 'prix-asc' ? ' aria-current="true"' : '' ?>>Prix croissant</a></li>
          <li><a href="<?= h(shop_url_with(['tri' => 'prix-desc'])) ?>"<?= $sort === 'prix-desc' ? ' aria-current="true"' : '' ?>>Prix décroissant</a></li>
        </ul>
      </details>

      <div class="lb-results">
        <div class="lb-toolbar">
          <span><?= count($products) ?> résultat<?= count($products) > 1 ? 's' : '' ?><?= $q !== '' ? ' pour « ' . h($q) . ' »' : '' ?></span>
          <span class="lb-views" role="group" aria-label="Affichage">
            <a href="<?= h(shop_url_with(['vue' => null])) ?>"<?= $vue === 'grille' ? ' aria-current="true"' : '' ?>>Grille</a>
            <a href="<?= h(shop_url_with(['vue' => 'liste'])) ?>"<?= $vue === 'liste' ? ' aria-current="true"' : '' ?>>Liste</a>
          </span>
        </div>
        <?php if ($products): ?>
          <div class="lb-grid<?= $vue === 'liste' ? ' is-list' : '' ?>">
            <?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?>
          </div>
        <?php else: ?>
          <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : ($nature !== '' ? 'Aucune pièce de ce type pour le moment.' : h(tenant_text('empty_category'))) ?></p>
          <?php if ($active): ?><p><a class="btn btn-ghost" href="/boutique.php">Effacer les filtres</a></p><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<script>
  // Les filtres sont ouverts sur ordinateur, repliés sous « Filtrer et trier » sur smartphone.
  (function () {
    var d = document.getElementById('lb-filters');
    if (d) d.open = window.matchMedia('(min-width: 861px)').matches || d.querySelector('.lb-active') !== null;
  })();
</script>
