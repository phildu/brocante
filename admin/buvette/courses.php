<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

$event = bv_active_event();

switch (bv_post_action()) {
    case 'set_manual': {
        if (!$event) bv_redirect('/admin/buvette/courses.php', 'Créez d\'abord un événement.', 'error');
        $supplyId = (int) ($_POST['supply_id'] ?? 0);
        $qty = bv_parse_float((string) ($_POST['qty'] ?? ''));
        $exists = db()->prepare('SELECT COUNT(*) FROM bv_supplies WHERE id = ?');
        $exists->execute([$supplyId]);
        if (!(int) $exists->fetchColumn()) bv_redirect('/admin/buvette/courses.php#besoins', 'Choisissez une fourniture dans la liste.', 'error');
        if ($qty === null) bv_redirect('/admin/buvette/courses.php#besoins', 'Quantité invalide.', 'error');
        if ($qty <= 0) {
            db()->prepare('DELETE FROM bv_event_supplies WHERE event_id = ? AND supply_id = ?')->execute([$event['id'], $supplyId]);
            bv_redirect('/admin/buvette/courses.php#besoins', 'Besoin retiré.');
        }
        db()->prepare('INSERT OR REPLACE INTO bv_event_supplies (event_id, supply_id, qty) VALUES (?, ?, ?)')->execute([$event['id'], $supplyId, $qty]);
        bv_redirect('/admin/buvette/courses.php#besoins', 'Besoin enregistré.');
    }

    case 'delete_manual': {
        if ($event) db()->prepare('DELETE FROM bv_event_supplies WHERE event_id = ? AND supply_id = ?')->execute([$event['id'], (int) ($_POST['supply_id'] ?? 0)]);
        bv_redirect('/admin/buvette/courses.php#besoins', 'Besoin retiré.');
    }
}

bv_admin_header('Liste de courses', 'courses');

if (!$event): ?>
  <p class="bv-callout">Créez d'abord un événement depuis le <a href="/admin/buvette/index.php#nouvel-evenement">tableau de bord</a>.</p>
<?php
    bv_admin_footer();
    exit;
endif;

$eid = (int) $event['id'];
$needs = bv_event_needs($eid);
$manual = bv_event_manual_supplies($eid);
$allSupplies = bv_supplies_by_kind();
$byKind = [];
foreach ($needs as $n) $byKind[$n['kind']][] = $n;
$budget = 0;
foreach ($needs as $n) $budget += $n['cost_cents'];
?>
<p class="bv-print-only" style="font-size:1.1rem;margin-bottom:10px;"><strong><?= h($event['name']) ?></strong> — <?= h(bv_fr_date($event['event_date'])) ?></p>
<p class="bv-hint bv-noprint">Calculée à partir du <a href="/admin/buvette/previsionnel.php" style="color:var(--accent);">prévisionnel</a> et des recettes, avec <strong><?= (int) $event['margin_pct'] ?> %</strong> de marge de sécurité, moins ce que vous avez déjà en <a href="/admin/buvette/fournitures.php" style="color:var(--accent);">stock</a>. Les achats sont arrondis au lot entier.</p>

<?php if (!$needs): ?>
  <p class="bv-callout">Rien à acheter pour l'instant : saisissez des quantités dans le <a href="/admin/buvette/previsionnel.php">prévisionnel</a> (et des recettes pour chaque article), ou ajoutez des besoins à la main plus bas.</p>
<?php else: ?>
  <div class="bv-actions bv-noprint" style="margin:0 0 10px;">
    <button class="btn btn-primary" type="button" onclick="window.print()">Imprimer la liste</button>
  </div>
  <div class="bv-card">
    <div class="bv-table-wrap">
      <table class="bv-table">
        <thead><tr><th style="width:34px;"></th><th>Fourniture</th><th class="num">Besoin</th><th class="num">En stock</th><th class="num">À prévoir</th><th>Lots</th><th class="num">Coût</th></tr></thead>
        <tbody>
        <?php foreach (bv_supply_kinds() as $kind => $kindLabel): if (empty($byKind[$kind])) continue; ?>
          <tr class="group-row"><td colspan="7"><?= h($kindLabel) ?></td></tr>
          <?php foreach ($byKind[$kind] as $n):
            $isGear = $kind === 'materiel';
            $enough = $n['to_buy'] <= 0;
            ?>
            <tr>
              <td><?php if (!$enough): ?><input type="checkbox" aria-label="Déjà fait : <?= h($n['name']) ?>" style="width:18px;height:18px;accent-color:var(--accent);"><?php endif; ?></td>
              <td>
                <?= h($n['name']) ?>
                <?php if ($n['supplier']): ?><div class="bv-muted" style="font-size:0.78rem;"><?= h($n['supplier']) ?></div><?php endif; ?>
              </td>
              <td class="num" title="Recettes : <?= h(bv_qty($n['need_recipe'])) ?> · à la main : <?= h(bv_qty($n['need_manual'])) ?>"><?= h(bv_need_qty($n['need_total'])) ?> <?= h($n['unit']) ?></td>
              <td class="num bv-muted"><?= h(bv_qty($n['stock'])) ?></td>
              <td class="num"><?php if ($enough): ?><span class="bv-badge ok">suffisant</span><?php else: ?><strong><?= h(bv_need_qty($n['to_buy'])) ?> <?= h($n['unit']) ?></strong><?php endif; ?></td>
              <td><?php if (!$enough): ?>
                  <?php if ($isGear && (int) $n['pack_price_cents'] === 0): ?>à trouver / emprunter
                  <?php else: ?><strong><?= (int) $n['packs'] ?></strong> × <?= h($n['pack_label'] ?: 'lot') ?> <span class="bv-muted">(<?= h(bv_qty($n['pack_qty'])) ?> <?= h($n['unit']) ?>)</span><?php endif; ?>
                <?php endif; ?></td>
              <td class="num"><?= $n['cost_cents'] > 0 ? h(bv_money($n['cost_cents'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="6">Budget courses</td><td class="num"><?= h(bv_money($budget)) ?></td></tr></tfoot>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="bv-card bv-noprint" id="besoins">
  <h2>Besoins supplémentaires pour cet événement</h2>
  <p class="bv-hint">Ce que les recettes ne couvrent pas : tables, glacières, rallonges, sacs poubelle… Indiquez la quantité totale nécessaire ; mettez 0 pour retirer.</p>
  <?php if ($manual): ?>
    <div class="bv-table-wrap">
      <table class="bv-table">
        <tbody>
        <?php foreach ($allSupplies as $kind => $list): foreach ($list as $s): if (!isset($manual[(int) $s['id']])) continue; ?>
          <tr>
            <td><?= h($s['name']) ?></td>
            <td>
              <?= bv_form_open('set_manual', ['supply_id' => $s['id']], '', 'bv-inline') ?>
                <input class="bv-input" style="width:90px;" type="text" inputmode="decimal" name="qty" value="<?= h(bv_qty($manual[(int) $s['id']])) ?>" aria-label="Quantité de <?= h($s['name']) ?>"> <?= h($s['unit']) ?>
                <button class="btn-small" type="submit">OK</button>
              </form>
            </td>
            <td class="bv-right"><?= bv_form_open('delete_manual', ['supply_id' => $s['id']], '', 'bv-inline') ?><button class="admin-delete" type="submit">Retirer</button></form></td>
          </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php if ($allSupplies): ?>
    <?= bv_form_open('set_manual') ?>
      <div class="bv-form-grid" style="margin-top:14px;">
        <div class="field span-2"><label>Fourniture</label>
          <select name="supply_id" required>
            <option value="">— choisir —</option>
            <?php foreach ($allSupplies as $kind => $list): ?>
              <optgroup label="<?= h(bv_supply_kinds()[$kind]) ?>"><?php foreach ($list as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['unit']) ?>)</option><?php endforeach; ?></optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Quantité totale</label><input type="text" inputmode="decimal" name="qty" required placeholder="ex : 4"></div>
        <div class="field"><button class="btn-small" type="submit">Ajouter / modifier</button></div>
      </div>
    </form>
  <?php else: ?>
    <p class="bv-muted">Aucune fourniture enregistrée : <a href="/admin/buvette/fournitures.php" style="color:var(--accent);">ajoutez-en d'abord</a>.</p>
  <?php endif; ?>
</div>
<?php
bv_admin_footer();
