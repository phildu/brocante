<?php
require_once __DIR__ . '/includes/functions.php';

$cat = $_GET['cat'] ?? 'tous';
$validKeys = array_column(category_list(), 'key');
if (!in_array($cat, $validKeys, true)) $cat = 'tous';
$q = trim((string)($_GET['q'] ?? ''));

$products = get_products($cat, false, $q);
$banner = get_page_banner('boutique');

function boutique_url(string $cat, string $q): string
{
    $params = [];
    if ($cat !== 'tous') $params['cat'] = $cat;
    if ($q !== '') $params['q'] = $q;
    return '/boutique.php' . ($params ? '?' . http_build_query($params) : '');
}

$activeNav = 'boutique';
$pageTitle = 'La boutique';
include __DIR__ . '/includes/header.php';
?>

<section class="shop-head">
  <div class="wrap">
    <div class="banner-zone<?= $banner ? ' has-page-banner' : '' ?>">
      <?php if ($banner): ?><?php render_page_banner('boutique'); ?><?php endif; ?>
      <h1 class="shop-title">La boutique</h1>
    </div>
    <form method="get" action="/boutique.php" class="shop-search" role="search">
      <?php if ($cat !== 'tous'): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher un objet…" aria-label="Rechercher un objet">
      <button type="submit" aria-label="Lancer la recherche">→</button>
    </form>
    <div class="filters" role="group" aria-label="Filtrer par univers">
      <a class="pill" href="<?= h(boutique_url('tous', $q)) ?>" aria-pressed="<?= $cat === 'tous' ? 'true' : 'false' ?>">Tous</a>
      <?php foreach (category_list() as $c): ?>
        <a class="pill" href="<?= h(boutique_url($c['key'], $q)) ?>" aria-pressed="<?= $cat === $c['key'] ? 'true' : 'false' ?>"><?= h($c['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="tight shop-products">
  <div class="wrap">
    <?php if ($products): ?>
      <div class="grid-products">
        <?php foreach ($products as $i => $p) echo product_card_html($p, $i); ?>
      </div>
    <?php else: ?>
      <p class="empty-state"><?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : 'Aucune pièce dans cet univers pour le moment — repassez après notre prochaine tournée de brocante.' ?></p>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
