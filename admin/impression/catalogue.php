<?php
require_once __DIR__ . '/../../includes/impression-layout.php';
pr_admin_init();

switch (pr_post_action()) {
    case 'add_product':
    case 'save_product': {
        $pid = ($_POST['action'] === 'save_product') ? (int) ($_POST['id'] ?? 0) : null;
        [$saved, $err] = pr_product_save($pid, $_POST);
        pr_redirect('/admin/impression/catalogue.php' . ($saved ? '#p' . $saved : ''), $err ?? ($pid ? 'Produit enregistré.' : 'Produit ajouté. Renseignez maintenant sa référence chez vos fournisseurs.'), $err ? 'error' : 'ok');
    }

    case 'delete_product': {
        $err = pr_product_delete((int) ($_POST['id'] ?? 0));
        pr_redirect('/admin/impression/catalogue.php', $err ?? 'Produit supprimé.', $err ? 'error' : 'ok');
    }

    case 'save_map': {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $err = pr_map_save($pid, (int) ($_POST['supplier_id'] ?? 0), (string) ($_POST['sku'] ?? ''), (string) ($_POST['options'] ?? ''), (string) ($_POST['note'] ?? ''));
        pr_redirect('/admin/impression/catalogue.php#p' . $pid, $err ?? 'Référence enregistrée.', $err ? 'error' : 'ok');
    }
}

$products = pr_products_list();
$connected = array_values(array_filter(pr_suppliers_list(true), fn($s) => $s['adapter'] !== ''));
$cats = pr_categories();

function pr_product_fields(?array $p): void
{
    $v = fn($k, $d = '') => h($p[$k] ?? $d);
    $cats = pr_categories();
    ?>
    <div class="bv-form-grid" style="margin-top:14px;">
      <div class="field span-2"><label>Nom du produit</label><input type="text" name="label" value="<?= $v('label') ?>" maxlength="120" required placeholder="Affiche A2 (420 × 594 mm)"></div>
      <div class="field"><label>Catégorie</label>
        <select name="category" class="bv-input"><?php foreach ($cats as $k => $l): ?><option value="<?= h($k) ?>"<?= ($p['category'] ?? 'affiche') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Quantité habituelle</label><input type="number" name="default_qty" class="bv-input" min="1" max="100000" value="<?= $v('default_qty', '1') ?>"></div>
      <div class="field"><label>Largeur finie (mm)</label><input type="number" name="width_mm" class="bv-input" min="0" max="5000" value="<?= $v('width_mm') ?>" placeholder="vide pour un goodie"></div>
      <div class="field"><label>Hauteur finie (mm)</label><input type="number" name="height_mm" class="bv-input" min="0" max="5000" value="<?= $v('height_mm') ?>"></div>
      <div class="field span-2"><label>Précisions (reprises dans les demandes de devis)</label><input type="text" name="description" value="<?= $v('description') ?>" maxlength="400"></div>
      <label class="bv-check"><input type="checkbox" name="is_active" value="1"<?= !$p || $p['is_active'] ? ' checked' : '' ?>> Proposé dans « Nouvelle commande »</label>
    </div>
    <?php
}

pr_admin_header('Catalogue & références', 'catalogue');
?>
<p class="bv-hint">Chaque fournisseur nomme ses produits à sa façon (<code>productUid</code> chez Gelato, <code>SKU</code> chez Prodigi…). Pour qu'un produit soit comparable par API, indiquez sa <strong>référence chez chaque fournisseur</strong> : copiez-la depuis leur catalogue. Sans référence, le fournisseur est simplement ignoré pour ce produit. Les dimensions servent à contrôler la résolution de votre fichier.</p>

<p class="pr-warn">Une seule référence est préchargée (SKU Prodigi d'une affiche 40 × 60 cm, tiré de leur documentation). <strong>Toutes les autres sont à renseigner</strong> — ce module ne devine aucun identifiant produit. Une référence erronée est détectée dès « Comparer les fournisseurs » : le fournisseur la refuse.</p>

<?php $byCat = []; foreach ($products as $p) $byCat[$p['category']][] = $p; ?>
<?php foreach ($cats as $cat => $catLabel): if (empty($byCat[$cat])) continue; ?>
  <h2 class="bv-cat-title"><?= h($catLabel) ?></h2>
  <?php foreach ($byCat[$cat] as $p): $maps = pr_maps_for_product((int) $p['id']); ?>
    <details class="bv-fold<?= $p['is_active'] ? '' : ' is-inactive' ?>" id="p<?= (int) $p['id'] ?>"<?= (int) ($_GET['open'] ?? 0) === (int) $p['id'] ? ' open' : '' ?>>
      <summary>
        <span class="sum-main">
          <span class="sum-name"><?= h($p['label']) ?></span>
          <span class="sum-meta"><?= $p['width_mm'] ? (int) $p['width_mm'] . ' × ' . (int) $p['height_mm'] . ' mm' : 'sans format' ?> · qté habituelle <?= (int) $p['default_qty'] ?></span>
        </span>
        <?php foreach ($connected as $s): ?>
          <span class="bv-badge<?= isset($maps[(int) $s['id']]) ? ' ok' : '' ?>" title="<?= isset($maps[(int) $s['id']]) ? 'Référence renseignée' : 'Référence manquante' ?>"><?= h($s['name']) ?> <?= isset($maps[(int) $s['id']]) ? '✓' : '—' ?></span>
        <?php endforeach; ?>
        <?php if (!$p['is_active']): ?><span class="bv-badge">masqué</span><?php endif; ?>
      </summary>
      <div class="bv-fold-body">
        <h3 style="font-size:1rem;margin:14px 0 4px;">Références chez les fournisseurs connectés</h3>
        <?php foreach ($connected as $s): $m = $maps[(int) $s['id']] ?? null; ?>
          <?= pr_form_open('save_map', ['product_id' => $p['id'], 'supplier_id' => $s['id']]) ?>
            <div class="bv-form-grid" style="margin-top:10px;">
              <div class="field"><label><?= h($s['name']) ?> — <?= $s['adapter'] === 'gelato' ? 'productUid' : 'SKU' ?></label>
                <input type="text" name="sku" value="<?= h($m['sku'] ?? '') ?>" maxlength="200" placeholder="<?= $s['adapter'] === 'gelato' ? 'ex : cards_pf_…' : 'ex : GLOBAL-FAP-16x24' ?>"></div>
              <?php if ($s['adapter'] === 'prodigi'): ?>
                <div class="field"><label>Attributs (JSON, si le produit en demande)</label>
                  <input type="text" name="options" value="<?= h($m['options'] ?? '') ?>" placeholder='{"color":"black"}'></div>
              <?php else: ?><input type="hidden" name="options" value="<?= h($m['options'] ?? '') ?>"><?php endif; ?>
              <div class="field"><label>Note</label><input type="text" name="note" value="<?= h($m['note'] ?? '') ?>" maxlength="300"></div>
              <div class="bv-actions" style="margin:0;"><button class="btn-small" type="submit"><?= $m ? 'Enregistrer' : 'Ajouter' ?></button></div>
            </div>
            <?php if (pr_safe_url($s['catalog_url']) !== ''): ?><p class="bv-hint" style="margin:4px 0 0;"><a href="<?= h(pr_safe_url($s['catalog_url'])) ?>" target="_blank" rel="noopener noreferrer">Catalogue / documentation <?= h($s['name']) ?></a> · laisser vide puis enregistrer = supprimer la référence</p><?php endif; ?>
          </form>
        <?php endforeach; ?>

        <h3 style="font-size:1rem;margin:22px 0 0;">Modifier le produit</h3>
        <?= pr_form_open('save_product', ['id' => $p['id']]) ?>
          <?php pr_product_fields($p); ?>
          <div class="bv-actions"><button class="btn-small" type="submit">Enregistrer le produit</button></div>
        </form>
        <?= pr_form_open('delete_product', ['id' => $p['id']], 'Supprimer « ' . $p['label'] . ' » du catalogue ?', 'bv-inline') ?>
          <div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer ce produit</button></div></form>
      </div>
    </details>
  <?php endforeach; ?>
<?php endforeach; ?>

<div class="bv-card" id="ajouter" style="margin-top:26px;">
  <h2>Ajouter un produit</h2>
  <?= pr_form_open('add_product') ?>
    <?php pr_product_fields(null); ?>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter au catalogue</button></div>
  </form>
</div>
<?php
pr_admin_footer();
