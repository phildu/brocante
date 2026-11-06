<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
bv_vol_init();

$id = (int) ($_GET['id'] ?? 0);
$item = $id ? bv_item_get($id) : null;
if ($item && !$item['is_active']) $item = null;

if ($item):
    $lines = bv_recipe_lines((int) $item['id']);
    // « 1. Couper… » -> « Couper… » : la liste ordonnée numérote déjà.
    $steps = array_values(array_filter(array_map(
        fn($l) => trim(preg_replace('/^\s*\d+\s*[\.\)\-]\s*/u', '', $l)),
        preg_split('/\R/u', (string) $item['steps'])
    ), fn($l) => $l !== ''));
    bv_vol_header($item['name'], 'recettes');
    ?>
  <p style="margin:-6px 0 14px;"><a href="/buvette/recettes.php" style="color:var(--accent);font-weight:600;">← Toutes les recettes</a></p>
  <div class="bvv-card">
    <p style="margin:0;"><strong><?= h(bv_money($item['price_cents'])) ?></strong>
      <?php if ((int) $item['prep_minutes'] > 0): ?> · ⏱ <?= (int) $item['prep_minutes'] ?> min<?php endif; ?></p>
    <div style="margin-top:8px;"><?= bv_allergen_chips($item) ?></div>
  </div>

  <h2 class="bvv-menu-cat">Pour 1 portion</h2>
  <?php if ($lines): ?>
    <ul class="bvv-ingredients">
      <?php foreach ($lines as $l): ?><li><span><?= h($l['name']) ?></span><strong class="bv-num"><?= h(bv_qty($l['qty'])) ?> <?= h($l['unit']) ?></strong></li><?php endforeach; ?>
    </ul>
  <?php else: ?><p class="bv-muted">Pas d'ingrédients renseignés.</p><?php endif; ?>

  <h2 class="bvv-menu-cat">Préparation</h2>
  <?php if ($steps): ?>
    <ol class="bvv-steps"><?php foreach ($steps as $st): ?><li><?= h($st) ?></li><?php endforeach; ?></ol>
  <?php else: ?><p class="bv-muted">Pas de préparation détaillée pour cet article.</p><?php endif; ?>
<?php
    bv_vol_footer();
    exit;
endif;

$groups = bv_items_by_category(true);
bv_vol_header('Les recettes', 'recettes');
?>
<?php if (!$groups): ?><p class="bvv-card">Aucune recette pour l'instant.</p><?php endif; ?>
<?php foreach ($groups as $cat => $items): ?>
  <h2 class="bvv-menu-cat"><?= h(bv_item_categories()[$cat]) ?></h2>
  <?php foreach ($items as $it): ?>
    <a class="bvv-recipe-link" href="/buvette/recettes.php?id=<?= (int) $it['id'] ?>">
      <span><span class="name"><?= h($it['name']) ?></span><?php if ((int) $it['prep_minutes'] > 0): ?><br><span class="meta">⏱ <?= (int) $it['prep_minutes'] ?> min</span><?php endif; ?></span>
      <span aria-hidden="true">›</span>
    </a>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php bv_vol_footer();
