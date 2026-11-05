<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

function bv_recipe_url(int $itemId): string
{
    return '/admin/buvette/recettes.php?item=' . $itemId . '#item-' . $itemId;
}

switch (bv_post_action()) {
    case 'set_line': {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $supplyId = (int) ($_POST['supply_id'] ?? 0);
        $qty = bv_parse_float((string) ($_POST['qty'] ?? ''));
        if (!bv_item_get($itemId)) bv_redirect('/admin/buvette/recettes.php', 'Article introuvable.', 'error');
        $supply = db()->prepare('SELECT * FROM bv_supplies WHERE id = ?');
        $supply->execute([$supplyId]);
        $supply = $supply->fetch();
        if (!$supply) bv_redirect(bv_recipe_url($itemId), 'Choisissez une fourniture dans la liste.', 'error');
        if ($qty === null || $qty <= 0) bv_redirect(bv_recipe_url($itemId), 'Quantité invalide (exemple : 0,25 ou 7).', 'error');
        // Une même fourniture ne figure qu'une fois par recette : la ressaisir met la quantité à jour.
        db()->prepare('INSERT OR REPLACE INTO bv_recipe_lines (item_id, supply_id, qty) VALUES (?, ?, ?)')->execute([$itemId, $supplyId, $qty]);
        bv_redirect(bv_recipe_url($itemId), $supply['name'] . ' : ' . bv_qty($qty) . ' ' . $supply['unit'] . ' par portion.');
    }

    case 'delete_line': {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        db()->prepare('DELETE FROM bv_recipe_lines WHERE id = ? AND item_id = ?')->execute([(int) ($_POST['id'] ?? 0), $itemId]);
        bv_redirect(bv_recipe_url($itemId), 'Ligne retirée de la recette.');
    }

    case 'save_steps': {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if (!bv_item_get($itemId)) bv_redirect('/admin/buvette/recettes.php', 'Article introuvable.', 'error');
        db()->prepare('UPDATE bv_items SET steps = ?, prep_minutes = ? WHERE id = ?')
            ->execute([mb_substr(trim((string) ($_POST['steps'] ?? '')), 0, 4000), max(0, (int) ($_POST['prep_minutes'] ?? 0)), $itemId]);
        bv_redirect(bv_recipe_url($itemId), 'Préparation enregistrée.');
    }
}

$items = bv_items_list(false);
$suppliesByKind = bv_supplies_by_kind();
$openId = (int) ($_GET['item'] ?? 0);

bv_admin_header('Recettes', 'recettes');
?>
<p class="bv-hint">Pour chaque article de la carte : ce qu'il faut <strong>par portion</strong> (dans l'unité de la fourniture) et comment le préparer. Ces quantités alimentent le prévisionnel, la liste de courses et le coût de revient ; les bénévoles y retrouvent la préparation depuis leur téléphone.</p>

<?php if (!$items): ?>
  <p class="bv-callout">Aucun article à la carte : <a href="/admin/buvette/carte.php">ajoutez d'abord vos articles</a>.</p>
<?php elseif (!$suppliesByKind): ?>
  <p class="bv-callout">Aucune fourniture enregistrée : <a href="/admin/buvette/fournitures.php">ajoutez d'abord vos ingrédients et consommables</a> pour pouvoir composer les recettes.</p>
<?php endif; ?>

<?php foreach ($items as $it):
    $lines = bv_recipe_lines((int) $it['id']);
    $cost = array_sum(array_map(fn($l) => (float) $l['line_cost'], $lines));
    ?>
  <details class="bv-fold <?= $it['is_active'] ? '' : 'is-inactive' ?>" id="item-<?= (int) $it['id'] ?>" <?= $openId === (int) $it['id'] ? 'open' : '' ?>>
    <summary>
      <span class="sum-main">
        <span class="sum-name"><?= h($it['name']) ?></span>
        <?php if ($lines): ?>
          <span class="sum-meta"><?= count($lines) ?> ligne<?= count($lines) > 1 ? 's' : '' ?> · revient à <?= h(bv_money($cost)) ?> pour <?= h(bv_money($it['price_cents'])) ?></span>
        <?php else: ?><span class="bv-badge">recette à saisir</span><?php endif; ?>
      </span>
      <?php if (!$it['is_active']): ?><span class="bv-badge">masqué</span><?php endif; ?>
      <?php if ($it['steps'] === '' ): ?><span class="bv-muted" style="font-size:0.78rem;">pas de préparation</span><?php endif; ?>
    </summary>
    <div class="bv-fold-body">
      <h3 style="font-size:1rem;margin:16px 0 6px;">Ingrédients et fournitures — pour 1 portion</h3>
      <?php if ($lines): ?>
        <div class="bv-table-wrap">
          <table class="bv-table">
            <thead><tr><th>Fourniture</th><th>Quantité</th><th class="num">Coût</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
              <tr>
                <td><?= h($l['name']) ?></td>
                <td>
                  <?= bv_form_open('set_line', ['item_id' => $it['id'], 'supply_id' => $l['supply_id']], '', 'bv-inline') ?>
                    <input class="bv-input" style="width:90px;" type="text" inputmode="decimal" name="qty" value="<?= h(bv_qty($l['qty'])) ?>" aria-label="Quantité de <?= h($l['name']) ?>"> <?= h($l['unit']) ?>
                    <button class="btn-small" type="submit">OK</button>
                  </form>
                </td>
                <td class="num"><?= h(bv_money($l['line_cost'])) ?></td>
                <td class="bv-right">
                  <?= bv_form_open('delete_line', ['item_id' => $it['id'], 'id' => $l['id']], '', 'bv-inline') ?><button class="admin-delete" type="submit" aria-label="Retirer <?= h($l['name']) ?>">Retirer</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2">Coût de revient d'une portion</td><td class="num"><?= h(bv_money($cost)) ?></td><td></td></tr></tfoot>
          </table>
        </div>
      <?php else: ?>
        <p class="bv-muted" style="margin:0 0 8px;">Aucune ligne pour l'instant. Un article offert ou apporté par les bénévoles (gâteaux maison) peut rester sans ingrédient : son coût est alors compté à 0.</p>
      <?php endif; ?>

      <?php if ($suppliesByKind): ?>
        <?= bv_form_open('set_line', ['item_id' => $it['id']]) ?>
          <div class="bv-form-grid" style="margin-top:14px;">
            <div class="field span-2"><label>Ajouter une fourniture</label>
              <select name="supply_id" required>
                <option value="">— choisir —</option>
                <?php foreach ($suppliesByKind as $kind => $list): ?>
                  <optgroup label="<?= h(bv_supply_kinds()[$kind]) ?>">
                    <?php foreach ($list as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['unit']) ?>)</option><?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Quantité par portion</label><input type="text" inputmode="decimal" name="qty" placeholder="ex : 0,5" required></div>
            <div class="field"><button class="btn-small" type="submit">Ajouter</button></div>
          </div>
        </form>
      <?php endif; ?>

      <?= bv_form_open('save_steps', ['item_id' => $it['id']]) ?>
        <h3 style="font-size:1rem;margin:22px 0 6px;">Préparation</h3>
        <p class="bv-hint" style="margin-bottom:8px;">Une étape par ligne : c'est ce que verront les bénévoles.</p>
        <div class="field"><textarea class="bv-input" name="steps" rows="5" maxlength="4000" placeholder="1. Lancer le percolateur…"><?= h($it['steps']) ?></textarea></div>
        <div class="bv-form-grid">
          <div class="field"><label>Temps de préparation (min)</label><input type="number" name="prep_minutes" min="0" value="<?= (int) $it['prep_minutes'] ?>"></div>
          <div class="field"><button class="btn btn-primary" type="submit">Enregistrer la préparation</button></div>
        </div>
      </form>
    </div>
  </details>
<?php endforeach; ?>
<?php
bv_admin_footer();
