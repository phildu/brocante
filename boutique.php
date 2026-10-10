<?php
require_once __DIR__ . '/includes/functions.php';

$cat = $_GET['cat'] ?? 'tous';
$validKeys = array_column(category_list(), 'key');
if (!in_array($cat, $validKeys, true)) $cat = 'tous';
$q = trim((string)($_GET['q'] ?? ''));
// Filtre par nature (vêtements, déco…) et sous-catégorie, détectées par l'IA ; seules celles des pièces en vente sont proposées.
$pair = product_nature_resolve($_GET['nature'] ?? '', $_GET['sous'] ?? '');
$nature = $pair['nature'];
$sous = $nature !== '' ? $pair['sous_categorie'] : '';

$products = get_products($cat, false, $q);
$natureCounts = [];   // nature => [total, [sous-catégorie => total]]
foreach (db()->query("SELECT nature, sous_categorie, COUNT(*) AS n FROM products WHERE is_hidden = 0 AND stock > 0 AND nature IS NOT NULL AND nature != '' GROUP BY nature, sous_categorie")->fetchAll() as $row) {
    $natureCounts[$row['nature']][0] = ($natureCounts[$row['nature']][0] ?? 0) + (int) $row['n'];
    if ($row['sous_categorie']) $natureCounts[$row['nature']][1][$row['sous_categorie']] = (int) $row['n'];
}
if ($nature !== '') {
    $products = array_values(array_filter($products, static fn (array $p): bool => ($p['nature'] ?? '') === $nature && ($sous === '' || ($p['sous_categorie'] ?? '') === $sous)));
}
$banner = get_page_banner('boutique');

function boutique_url(string $cat, string $q, string $nature = '', string $sous = ''): string
{
    $params = [];
    if ($cat !== 'tous') $params['cat'] = $cat;
    if ($q !== '') $params['q'] = $q;
    if ($nature !== '') $params['nature'] = $nature;
    if ($nature !== '' && $sous !== '') $params['sous'] = $sous;
    return '/boutique.php' . ($params ? '?' . http_build_query($params) : '');
}

$activeNav = 'boutique';
$pageTitle = 'La boutique';
include __DIR__ . '/includes/header.php';

// Modèle à mise en page propre (views/<modèle>/shop.php) : mêmes données, autre structure.
if ($view = shop_view('shop')) {
    include $view;
    include __DIR__ . '/includes/footer.php';
    return;
}
?>

<section class="shop-head">
  <div class="wrap">
    <div class="banner-zone<?= $banner ? ' has-page-banner' : '' ?>">
      <?php if ($banner): ?><?php render_page_banner('boutique'); ?><?php endif; ?>
      <h1 class="shop-title">La boutique</h1>
    </div>
    <form method="get" action="/boutique.php" class="shop-search" role="search">
      <?php if ($cat !== 'tous'): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
      <?php if ($nature !== ''): ?><input type="hidden" name="nature" value="<?= h($nature) ?>"><?php if ($sous !== ''): ?><input type="hidden" name="sous" value="<?= h($sous) ?>"><?php endif; ?><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher un objet…" aria-label="Rechercher un objet">
      <button type="submit" aria-label="Lancer la recherche">→</button>
    </form>
    <div class="filters" role="group" aria-label="Filtrer par univers">
      <a class="pill" href="<?= h(boutique_url('tous', $q)) ?>" aria-pressed="<?= $cat === 'tous' ? 'true' : 'false' ?>">Tous</a>
      <?php foreach (category_list() as $c): ?>
        <a class="pill" href="<?= h(boutique_url($c['key'], $q, $nature, $sous)) ?>" aria-pressed="<?= $cat === $c['key'] ? 'true' : 'false' ?>"><?= h($c['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($natureCounts): ?>
      <form method="get" action="/boutique.php" class="nature-filter" aria-label="Filtrer par type de produit">
        <?php if ($cat !== 'tous'): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
        <select name="nature" onchange="this.form.sous && (this.form.sous.value = ''); this.form.submit()" aria-label="Type de produit">
          <option value="">Tous les types</option>
          <?php foreach (product_nature_options() as $nKey => $n): if (empty($natureCounts[$nKey])) continue; ?>
            <option value="<?= h($nKey) ?>"<?= $nature === $nKey ? ' selected' : '' ?>><?= h($n['label']) ?> (<?= (int) $natureCounts[$nKey][0] ?>)</option>
          <?php endforeach; ?>
        </select>
        <?php if ($nature !== '' && !empty($natureCounts[$nature][1])): ?>
          <select name="sous" onchange="this.form.submit()" aria-label="Sous-catégorie">
            <option value="">Toutes les sous-catégories</option>
            <?php foreach (product_nature_options()[$nature]['subs'] as $sKey => $sLabel): if (empty($natureCounts[$nature][1][$sKey])) continue; ?>
              <option value="<?= h($sKey) ?>"<?= $sous === $sKey ? ' selected' : '' ?>><?= h($sLabel) ?> (<?= (int) $natureCounts[$nature][1][$sKey] ?>)</option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <?php if ($nature !== ''): ?><a class="nature-clear" href="<?= h(boutique_url($cat, $q)) ?>">Tous les types</a><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</section>

<section class="tight shop-products">
  <div class="wrap">
    <?php if ($products): ?>
      <div class="grid-products">
        <?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?>
      </div>
    <?php else: ?>
      <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : ($nature !== '' ? 'Aucune pièce de ce type pour le moment.' : h(tenant_text('empty_category'))) ?></p>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
