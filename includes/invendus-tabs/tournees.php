<?php
if (!defined('INVENDUS_ADMIN')) { http_response_code(403); exit; }
/** @var array $event @var int $id @var string $return */

$tours = unsold_tours_list($id);
$openTours = array_filter($tours, fn($t) => $t['status'] !== 'done');
$showAll = !empty($_GET['all']);

// Lot « à affecter » : objets encore proposés et sans tournée. Par défaut, ceux à ramasser.
$pool = array_filter(
    unsold_offers_list($id, ['status' => 'proposed', 'tour' => 'none']),
    fn($o) => $showAll || $o['handover'] === 'pickup'
);
// Les invendus confirmés d'abord, puis l'ordre des stands : c'est l'ordre de passage naturel.
usort($pool, function ($a, $b) {
    $ca = $a['confirmed_at'] ? 0 : 1;
    $cb = $b['confirmed_at'] ? 0 : 1;
    return $ca <=> $cb ?: strnatcasecmp((string) $a['stand_ref'], (string) $b['stand_ref']);
});
$poolUnits = array_sum(array_map('unsold_offer_units', $pool));
?>
<div class="admin-block">
  <h2>Nouvelle tournée</h2>
  <p class="hint">Une tournée = un bénévole avec un véhicule (vélo cargo, remorque…). Sa <strong>capacité</strong> se compte en unités de volume :
    un objet petit = 1, moyen = 2, encombrant = 4. Un vélo cargo emporte en général 10 à 15 unités.</p>
  <?= unsold_admin_form_open('tour_save', $return, 'class="don-form"') ?>
    <input type="hidden" name="event_id" value="<?= $id ?>">
    <div class="field-row-2">
      <div class="field"><label>Bénévole *</label><input type="text" name="volunteer_name" maxlength="80" required></div>
      <div class="field"><label>Téléphone</label><input type="tel" name="volunteer_phone" maxlength="20"></div>
    </div>
    <div class="field-row-2">
      <div class="field"><label>Véhicule</label><input type="text" name="vehicle" value="Vélo cargo" maxlength="60"></div>
      <div class="field"><label>Capacité (unités)</label><input type="number" name="capacity" value="12" min="1" max="200"></div>
    </div>
    <div class="field"><label>Consigne pour le bénévole</label><textarea name="notes" rows="2" maxlength="500" placeholder="ex : commencer par l'allée B, tout déposer sous le préau"></textarea></div>
    <button class="btn btn-primary" type="submit">Créer la tournée</button>
  </form>
</div>

<?php foreach ($tours as $t):
    $items = unsold_tour_offers((int) $t['id']);
    $cap = max(1, (int) $t['capacity']);
    $over = $t['units_planned'] > $cap;
    $url = unsold_abs_url('/dons-tournee.php?t=' . $t['token']);
?>
  <div class="don-tour">
    <div class="don-row">
      <h3><?= h($t['volunteer_name']) ?> <span class="don-muted don-small">— <?= h($t['vehicle']) ?></span></h3>
      <span class="don-badge"><?= h(unsold_tour_status_labels()[$t['status']] ?? $t['status']) ?></span>
      <?php if ($t['volunteer_phone'] !== ''): ?><a class="don-small" href="tel:<?= h(preg_replace('/[^+0-9]/', '', $t['volunteer_phone'])) ?>"><?= h($t['volunteer_phone']) ?></a><?php endif; ?>
    </div>

    <div class="don-meter<?= $over ? ' don-meter--over' : '' ?>" style="margin:12px 0 4px;" role="img" aria-label="Charge prévue <?= (int) $t['units_planned'] ?> sur <?= $cap ?>">
      <i style="width:<?= min(100, round($t['units_planned'] / $cap * 100)) ?>%"></i><b style="width:<?= min(100, round($t['units_picked'] / $cap * 100)) ?>%"></b>
    </div>
    <p class="don-small don-muted" style="margin:0 0 12px;">
      <?= (int) $t['items'] ?> objet<?= $t['items'] > 1 ? 's' : '' ?> · <?= (int) $t['stops'] ?> arrêt<?= $t['stops'] > 1 ? 's' : '' ?> ·
      prévu <?= (int) $t['units_planned'] ?> / <?= $cap ?> unités · chargé <?= (int) $t['units_picked'] ?>
      <?= $over ? ' — <strong style="color:var(--accent);">dépasse la capacité : prévoir deux voyages ou une autre tournée</strong>' : '' ?>
    </p>

    <?php unsold_copy_field('Lien secret du bénévole', $url, 'À envoyer par SMS : la page s\'ouvre sur téléphone, sans mot de passe.'); ?>

    <div class="don-row" style="margin-bottom:12px;">
      <?php if ($t['status'] === 'planned'): ?>
        <?= unsold_admin_form_open('tour_start', $return) ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-small" type="submit">Démarrer</button></form>
      <?php endif; ?>
      <?php if ($t['status'] !== 'done'): ?>
        <?= unsold_admin_form_open('tour_finish', $return) ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button class="btn-small don-ok" type="submit" onclick="return confirm('Terminer la tournée ? Ce qui est chargé est déposé au point ; les arrêts non visités retournent dans le lot à affecter.');">Terminer (déposé au point)</button></form>
        <?= unsold_admin_form_open('tour_delete', $return) ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button class="admin-delete" type="submit" onclick="return confirm('Supprimer cette tournée ? Ses propositions redeviennent à affecter.');">Supprimer</button></form>
      <?php else: ?>
        <?= unsold_admin_form_open('tour_reopen', $return) ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-small" type="submit">Rouvrir</button></form>
      <?php endif; ?>
    </div>

    <?php if ($items): ?>
      <form method="post" action="/admin/invendus-action.php" id="tour-form-<?= (int) $t['id'] ?>">
        <?= shop_csrf_input() ?><input type="hidden" name="action" value="offer_bulk"><input type="hidden" name="bulk" value="unassign">
        <input type="hidden" name="return" value="<?= h($return) ?>"><input type="hidden" name="event_id" value="<?= $id ?>">
        <div class="don-table-wrap">
          <table class="don-table">
            <thead><tr><th><input type="checkbox" data-check-all="tour-form-<?= (int) $t['id'] ?>" aria-label="Tout cocher"></th><th>Stand</th><th>Objet</th><th>Donateur</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($items as $o): $size = unsold_sizes()[$o['size']] ?? null; ?>
              <tr>
                <td><?php if ($o['status'] === 'proposed'): ?><input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" aria-label="Sélectionner <?= h($o['title']) ?>"><?php endif; ?></td>
                <td class="don-num"><?= h($o['stand_ref'] ?: '—') ?></td>
                <td><a href="/admin/invendus-offer.php?id=<?= (int) $o['id'] ?>"><?= h($o['title']) ?></a> <span class="don-muted don-small">(<?= h($size['label'] ?? '') ?>)</span></td>
                <td class="don-small"><?= h($o['donor_name']) ?> <span class="don-muted"><?= h($o['donor_phone']) ?></span></td>
                <td><?= unsold_status_badge($o['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p style="margin:10px 0 0;"><button class="btn-small" type="submit">Détacher les objets cochés de cette tournée</button></p>
      </form>
    <?php else: ?>
      <p class="empty-state" style="margin:0;">Aucun objet affecté — choisissez-en dans le lot « à affecter » ci-dessous.</p>
    <?php endif; ?>

    <details style="margin-top:14px;">
      <summary style="cursor:pointer;" class="don-small">Modifier la tournée</summary>
      <?= unsold_admin_form_open('tour_save', $return, 'class="don-form" style="margin-top:12px;"') ?>
        <input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <div class="field-row-2">
          <div class="field"><label>Bénévole *</label><input type="text" name="volunteer_name" value="<?= h($t['volunteer_name']) ?>" maxlength="80" required></div>
          <div class="field"><label>Téléphone</label><input type="tel" name="volunteer_phone" value="<?= h($t['volunteer_phone']) ?>" maxlength="20"></div>
        </div>
        <div class="field-row-2">
          <div class="field"><label>Véhicule</label><input type="text" name="vehicle" value="<?= h($t['vehicle']) ?>" maxlength="60"></div>
          <div class="field"><label>Capacité (unités)</label><input type="number" name="capacity" value="<?= (int) $t['capacity'] ?>" min="1" max="200"></div>
        </div>
        <div class="field"><label>Consigne</label><textarea name="notes" rows="2" maxlength="500"><?= h($t['notes']) ?></textarea></div>
        <button class="btn-small" type="submit">Enregistrer</button>
      </form>
    </details>
  </div>
<?php endforeach; ?>

<div class="admin-block" style="margin-top:28px;">
  <h2>À affecter <span class="don-muted" style="font-size:0.9rem;font-weight:400;">— <?= count($pool) ?> objet<?= count($pool) > 1 ? 's' : '' ?>, <?= (int) $poolUnits ?> unités</span></h2>
  <p class="hint">
    Objets <?= $showAll ? 'proposés (tous modes de remise)' : 'à ramasser à leur stand' ?> qui n'ont pas encore de tournée, invendus confirmés en premier puis par emplacement.
    <?php if ($showAll): ?><a href="<?= h('/admin/invendus-event.php?id=' . $id . '&tab=tournees') ?>" style="color:var(--accent);">N'afficher que les ramassages</a>
    <?php else: ?><a href="<?= h('/admin/invendus-event.php?id=' . $id . '&tab=tournees&all=1') ?>" style="color:var(--accent);">Afficher aussi les objets apportés par leur propriétaire</a><?php endif; ?>
  </p>
  <?php if (!$pool): ?>
    <p class="empty-state" style="margin:0;">Rien à affecter pour l'instant.</p>
  <?php else: ?>
    <form method="post" action="/admin/invendus-action.php" id="pool-form">
      <?= shop_csrf_input() ?><input type="hidden" name="action" value="offer_bulk"><input type="hidden" name="bulk" value="assign">
      <input type="hidden" name="return" value="<?= h($return) ?>"><input type="hidden" name="event_id" value="<?= $id ?>">
      <div class="don-table-wrap">
        <table class="don-table">
          <thead><tr><th><input type="checkbox" data-check-all="pool-form" aria-label="Tout cocher"></th><th>Stand</th><th>Objet</th><th>Volume</th><th>Donateur</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pool as $o): $size = unsold_sizes()[$o['size']] ?? null; ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" aria-label="Sélectionner <?= h($o['title']) ?>"></td>
              <td class="don-num"><?= h($o['stand_ref'] ?: '—') ?></td>
              <td><a href="/admin/invendus-offer.php?id=<?= (int) $o['id'] ?>"><?= h($o['title']) ?></a><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?></td>
              <td class="don-small"><?= h($size['label'] ?? '') ?></td>
              <td class="don-small"><?= h($o['donor_name']) ?></td>
              <td><?= $o['confirmed_at'] ? '<span class="don-small" style="color:var(--sage);">confirmé</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($openTours): ?>
      <div class="don-bulk don-row don-inline">
        <strong class="don-small">Affecter les objets cochés à :</strong>
        <select name="tour_id" required>
          <?php foreach ($openTours as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['volunteer_name']) ?> (<?= (int) $t['units_planned'] ?>/<?= (int) $t['capacity'] ?>)</option><?php endforeach; ?>
        </select>
        <button class="btn-small don-ok" type="submit">Affecter</button>
      </div>
      <?php else: ?>
      <p class="don-muted don-small" style="margin:12px 0 0;">Créez d'abord une tournée (formulaire ci-dessus) pour pouvoir y affecter ces objets.</p>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>
