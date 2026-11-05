<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

/** Champs d'une fourniture depuis le POST ; retourne [valeurs, erreur]. */
function bv_supply_fields_from_post(): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') return [null, 'Le nom de la fourniture est obligatoire.'];
    $kind = (string) ($_POST['kind'] ?? 'ingredient');
    if (!isset(bv_supply_kinds()[$kind])) $kind = 'ingredient';
    $unit = trim((string) ($_POST['unit'] ?? ''));
    if ($unit === '') $unit = 'pièce';
    $packQty = bv_parse_float((string) ($_POST['pack_qty'] ?? '1'));
    if ($packQty === null || $packQty <= 0) return [null, 'Quantité par lot invalide pour « ' . $name . ' » (elle doit être supérieure à 0).'];
    $price = trim((string) ($_POST['pack_price'] ?? '')) === '' ? 0 : bv_parse_cents((string) $_POST['pack_price']);
    if ($price === null) return [null, 'Prix du lot invalide pour « ' . $name . ' » (exemple : 10,90).'];
    $stock = trim((string) ($_POST['stock'] ?? '')) === '' ? 0.0 : bv_parse_float((string) $_POST['stock']);
    if ($stock === null) return [null, 'Stock invalide pour « ' . $name . ' ».'];
    return [[
        'name' => mb_substr($name, 0, 100),
        'kind' => $kind,
        'unit' => mb_substr($unit, 0, 20),
        'pack_qty' => $packQty,
        'pack_label' => mb_substr(trim((string) ($_POST['pack_label'] ?? '')), 0, 60),
        'price' => $price,
        'stock' => $stock,
        'supplier' => mb_substr(trim((string) ($_POST['supplier'] ?? '')), 0, 100),
        'note' => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300),
    ], null];
}

switch (bv_post_action()) {
    case 'add_supply': {
        [$f, $err] = bv_supply_fields_from_post();
        if ($err) bv_redirect('/admin/buvette/fournitures.php#ajouter', $err, 'error');
        db()->prepare('INSERT INTO bv_supplies (name, kind, unit, pack_qty, pack_label, pack_price_cents, stock, supplier, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$f['name'], $f['kind'], $f['unit'], $f['pack_qty'], $f['pack_label'], $f['price'], $f['stock'], $f['supplier'], $f['note']]);
        bv_redirect('/admin/buvette/fournitures.php', '« ' . $f['name'] . ' » ajouté aux fournitures.');
    }

    case 'update_supply': {
        $id = (int) ($_POST['id'] ?? 0);
        [$f, $err] = bv_supply_fields_from_post();
        if ($err) bv_redirect('/admin/buvette/fournitures.php', $err, 'error');
        db()->prepare('UPDATE bv_supplies SET name = ?, kind = ?, unit = ?, pack_qty = ?, pack_label = ?, pack_price_cents = ?, stock = ?, supplier = ?, note = ? WHERE id = ?')
            ->execute([$f['name'], $f['kind'], $f['unit'], $f['pack_qty'], $f['pack_label'], $f['price'], $f['stock'], $f['supplier'], $f['note'], $id]);
        bv_redirect('/admin/buvette/fournitures.php', '« ' . $f['name'] . ' » enregistré.');
    }

    case 'delete_supply': {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM bv_supplies WHERE id = ?')->execute([$id]);
        bv_redirect('/admin/buvette/fournitures.php', 'Fourniture supprimée (et retirée des recettes qui l\'utilisaient).');
    }
}

$groups = bv_supplies_by_kind();
$usage = [];
foreach (db()->query('SELECT supply_id, COUNT(*) AS n FROM bv_recipe_lines GROUP BY supply_id')->fetchAll() as $r) {
    $usage[(int) $r['supply_id']] = (int) $r['n'];
}

/** Champs du formulaire fourniture, partagés entre l'ajout et la modification. */
function bv_supply_form_fields(?array $s): void
{
    $v = fn($k, $d = '') => h($s[$k] ?? $d);
    ?>
    <div class="bv-form-grid" style="margin-top:14px;">
      <div class="field span-2"><label>Nom</label><input type="text" name="name" value="<?= $v('name') ?>" required maxlength="100" placeholder="Café moulu"></div>
      <div class="field"><label>Type</label>
        <select name="kind"><?php foreach (bv_supply_kinds() as $k => $label): ?><option value="<?= h($k) ?>" <?= ($s['kind'] ?? 'ingredient') === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Unité de mesure</label><input type="text" name="unit" list="bv-units" value="<?= $v('unit', 'pièce') ?>" maxlength="20" required></div>
      <div class="field"><label>Quantité par lot</label><input type="text" inputmode="decimal" name="pack_qty" value="<?= $s ? h(bv_qty($s['pack_qty'])) : '1' ?>" required></div>
      <div class="field"><label>Nom du lot</label><input type="text" name="pack_label" value="<?= $v('pack_label') ?>" maxlength="60" placeholder="paquet de 1 kg"></div>
      <div class="field"><label>Prix du lot (€)</label><input type="text" inputmode="decimal" name="pack_price" value="<?= $s ? h(number_format($s['pack_price_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="0 si gratuit / prêté"></div>
      <div class="field"><label>En stock (même unité)</label><input type="text" inputmode="decimal" name="stock" value="<?= $s ? h(bv_qty($s['stock'])) : '0' ?>"></div>
      <div class="field"><label>Fournisseur / magasin</label><input type="text" name="supplier" value="<?= $v('supplier') ?>" maxlength="100"></div>
      <div class="field span-2"><label>Note</label><input type="text" name="note" value="<?= $v('note') ?>" maxlength="300"></div>
    </div>
    <?php
}

bv_admin_header('Fournitures', 'fournitures');
?>
<p class="bv-hint">Tout ce qu'il faut pour tenir la buvette : produits et ingrédients, consommables (gobelets, serviettes) et matériel. On saisit ce qu'on <strong>achète</strong> (un lot, avec son prix) ; le prix à l'unité s'en déduit. Exemple : « Café moulu » — unité g — lot de 1000 g à 10,90 €.</p>
<datalist id="bv-units"><option value="g"><option value="kg"><option value="cl"><option value="L"><option value="pièce"><option value="tranche"><option value="sachet"></datalist>

<?php if (!$groups): ?>
  <p class="bv-callout">Aucune fourniture. Ajoutez la première ci-dessous, ou <a href="/admin/buvette/index.php">chargez la buvette d'exemple</a>.</p>
<?php endif; ?>

<?php foreach ($groups as $kind => $list): ?>
  <h2 class="bv-cat-title"><?= h(bv_supply_kinds()[$kind]) ?></h2>
  <?php foreach ($list as $s):
    $n = $usage[(int) $s['id']] ?? 0;
    ?>
    <details class="bv-fold">
      <summary>
        <span class="sum-main">
          <span class="sum-name"><?= h($s['name']) ?></span>
          <span class="sum-meta">
            <?php if ((int) $s['pack_price_cents'] > 0): ?>
              <?= h(bv_money($s['pack_price_cents'])) ?> le <?= h($s['pack_label'] ?: 'lot') ?> (<?= h(bv_qty($s['pack_qty'])) ?> <?= h($s['unit']) ?>)
            <?php else: ?>gratuit / prêté<?php endif; ?>
          </span>
        </span>
        <span class="bv-muted bv-num" style="font-size:0.85rem;">stock : <?= h(bv_qty($s['stock'])) ?> <?= h($s['unit']) ?></span>
        <?php if ($n): ?><span class="bv-badge"><?= $n ?> recette<?= $n > 1 ? 's' : '' ?></span><?php endif; ?>
      </summary>
      <div class="bv-fold-body">
        <?= bv_form_open('update_supply', ['id' => $s['id']]) ?>
          <?php bv_supply_form_fields($s); ?>
          <div class="bv-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
        </form>
        <?= bv_form_open('delete_supply', ['id' => $s['id']], 'Supprimer « ' . $s['name'] . ' » ?' . ($n ? ' Elle sera retirée des ' . $n . ' recette(s) qui l\'utilisent.' : ''), 'bv-inline') ?>
          <div class="bv-actions"><button class="btn-small bv-btn-danger" type="submit">Supprimer cette fourniture</button></div>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
<?php endforeach; ?>

<div class="bv-card" id="ajouter" style="margin-top:26px;">
  <h2>Ajouter une fourniture</h2>
  <?= bv_form_open('add_supply') ?>
    <?php bv_supply_form_fields(null); ?>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</div>
<?php
bv_admin_footer();
