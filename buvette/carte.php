<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
bv_vol_init();

$groups = bv_items_by_category(true);
bv_vol_header('La carte', 'carte');
?>
<?php if (!$groups): ?>
  <p class="bvv-card">La carte n'est pas encore définie.</p>
<?php endif; ?>
<?php foreach ($groups as $cat => $items): ?>
  <h2 class="bvv-menu-cat"><?= h(bv_item_categories()[$cat]) ?></h2>
  <?php foreach ($items as $it): ?>
    <div class="bvv-menu-item">
      <div>
        <div class="name"><?= h($it['name']) ?></div>
        <?php if ($it['description']): ?><div class="desc"><?= h($it['description']) ?></div><?php endif; ?>
        <div style="margin-top:4px;"><?= bv_allergen_chips($it) ?></div>
      </div>
      <div class="price"><?= h(bv_money($it['price_cents'])) ?></div>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php if ($groups): ?>
  <p class="bvv-note">En cas de doute sur un allergène, ne devinez pas : demandez au responsable de la buvette avant de répondre au client.</p>
<?php endif; ?>
<?php bv_vol_footer();
