<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/invendus-layout.php';
require_admin();
require_once __DIR__ . '/../includes/shop-modules.php';
shop_module_require('invendus-dons');   // plugin activé + accès payé, en essai, offert ou inclus

$o = unsold_offer_get((int) unsold_param($_GET, 'id'));
if (!$o) {
    flash_set('Proposition introuvable.', 'error');
    header('Location: /admin/invendus.php');
    exit;
}
$event = unsold_event_get((int) $o['event_id']);
$tour = $o['tour_id'] ? unsold_tour_get((int) $o['tour_id']) : null;
$self = '/admin/invendus-offer.php?id=' . (int) $o['id'];
$back = '/admin/invendus-event.php?id=' . (int) $o['event_id'] . '&tab=propositions';
$log = unsold_offer_log((int) $o['id']);
$publicUrl = unsold_abs_url('/dons-suivi.php?t=' . $o['token']);

// Transitions que l'admin peut déclencher depuis l'état courant (« donné » passe par le bordereau).
$moves = [];
foreach (unsold_transition_rules()[$o['status']] ?? [] as $to => $who) {
    if (in_array('admin', $who, true) && $to !== 'donated') $moves[] = $to;
}
$moveLabels = [
    'proposed' => 'Remettre en « proposé »', 'picked' => 'Marquer récupéré (en route)', 'at_point' => 'Marquer au point de collecte',
    'taken' => 'Marquer pris par un visiteur', 'withdrawn' => 'Retirer (vendu, repris, hors don)',
];

unsold_admin_head($o['title']);
?>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow"><a href="<?= h($back) ?>" style="text-decoration:none;">← <?= h($event['name'] ?? 'Propositions') ?></a></p>
    <div class="don-row" style="margin:12px 0 0;">
      <h1 style="font-size:1.7rem;"><?= h($o['title']) ?></h1>
      <?= unsold_status_badge($o['status']) ?>
      <?php if ($o['confirmed_at'] && $o['status'] === 'proposed'): ?><span class="don-badge don-badge--at_point">invendu confirmé</span><?php endif; ?>
    </div>
    <p class="don-muted" style="margin:6px 0 0;">
      Reçue le <?= h(unsold_fr_stamp($o['created_at'])) ?>
      <?php if ($tour): ?> · tournée de <strong><?= h($tour['volunteer_name']) ?></strong><?php endif; ?>
      <?php if ($o['donation_id']): ?> · <a href="/admin/invendus-bordereau.php?id=<?= (int) $o['donation_id'] ?>" target="_blank" style="color:var(--accent);">voir le bordereau</a><?php endif; ?>
    </p>

    <?php unsold_flash_html(); ?>

    <div class="admin-shell" style="gap:28px;margin-top:20px;">

      <div class="admin-block">
        <h2>Statut</h2>
        <div class="don-row" style="margin-top:10px;">
          <?php foreach ($moves as $to): ?>
            <?= unsold_admin_form_open('offer_status', $self) ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="to" value="<?= h($to) ?>">
              <button class="btn-small<?= $to === 'withdrawn' ? '' : ' don-ok' ?>" type="submit"><?= h($moveLabels[$to] ?? $to) ?></button></form>
          <?php endforeach; ?>
          <?php if ($o['status'] === 'proposed'): ?>
            <?= unsold_admin_form_open('offer_bulk', $self) ?><input type="hidden" name="event_id" value="<?= (int) $o['event_id'] ?>"><input type="hidden" name="ids[]" value="<?= (int) $o['id'] ?>"><input type="hidden" name="bulk" value="<?= $o['confirmed_at'] ? 'unconfirm' : 'confirm' ?>">
              <button class="btn-small" type="submit"><?= $o['confirmed_at'] ? 'Annuler la confirmation' : 'Confirmer « c\'est un invendu »' ?></button></form>
          <?php endif; ?>
          <?php if (!$moves && $o['status'] !== 'proposed'): ?><span class="don-muted">Aucun changement possible depuis cet état.</span><?php endif; ?>
        </div>
        <?php if ($o['status'] === 'donated'): ?>
          <p class="hint" style="margin-top:12px;">Remis à une donnerie. L'annuler remet l'objet « au point de collecte » (l'inscription au bordereau est retirée).</p>
        <?php endif; ?>
      </div>

      <div class="admin-block">
        <h2>Modifier</h2>
        <?= unsold_admin_form_open('offer_update', $self, 'class="don-form" enctype="multipart/form-data" style="margin-top:12px;"') ?>
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <div class="field-row-2">
            <div class="field"><label>Nom du donateur</label><input type="text" name="donor_name" value="<?= h($o['donor_name']) ?>" maxlength="80" required></div>
            <div class="field"><label>N° d'emplacement</label><input type="text" name="stand_ref" value="<?= h($o['stand_ref']) ?>" maxlength="20"></div>
          </div>
          <div class="field-row-2">
            <div class="field"><label>Téléphone</label><input type="tel" name="donor_phone" value="<?= h($o['donor_phone']) ?>" maxlength="20"></div>
            <div class="field"><label>E-mail</label><input type="email" name="donor_email" value="<?= h($o['donor_email']) ?>" maxlength="120"></div>
          </div>
          <div class="field"><label>Objet</label><input type="text" name="title" value="<?= h($o['title']) ?>" maxlength="100" required></div>
          <div class="field-row-2">
            <div class="field"><label>Catégorie</label><select name="category"><?php foreach (unsold_categories() as $k => $l): ?><option value="<?= h($k) ?>"<?= $o['category'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Volume</label><select name="size"><?php foreach (unsold_sizes() as $k => $s): ?><option value="<?= h($k) ?>"<?= $o['size'] === $k ? ' selected' : '' ?>><?= h($s['label']) ?> — <?= h($s['hint']) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="field-row-2">
            <div class="field"><label>Nombre de pièces</label><input type="number" name="quantity" value="<?= (int) $o['quantity'] ?>" min="1" max="999"></div>
            <div class="field"><label>Remise</label><select name="handover"><option value="self"<?= $o['handover'] === 'self' ? ' selected' : '' ?>>Apporté au point par le donateur</option><option value="pickup"<?= $o['handover'] === 'pickup' ? ' selected' : '' ?>>À ramasser à son stand</option></select></div>
          </div>
          <div class="field"><label>Précision pour retrouver l'objet</label><input type="text" name="pickup_note" value="<?= h($o['pickup_note']) ?>" maxlength="200"></div>
          <div class="field"><label>Détails</label><textarea name="description" maxlength="600" rows="3"><?= h($o['description']) ?></textarea></div>
          <div class="field"><label>Note interne (jamais publique)</label><textarea name="admin_note" maxlength="500" rows="2"><?= h($o['admin_note']) ?></textarea></div>
          <div class="field">
            <label>Photo</label>
            <?php if (!empty($o['photo'])): ?>
              <img src="/<?= h($o['photo']) ?>" alt="" style="max-width:220px;border:1px solid var(--line);">
              <label class="don-small" style="text-transform:none;letter-spacing:0;"><input type="checkbox" name="remove_photo" value="1"> Supprimer cette photo</label>
            <?php endif; ?>
            <input type="file" name="photo" accept="image/*">
          </div>
          <button class="btn btn-primary" type="submit">Enregistrer</button>
        </form>
      </div>

      <div class="admin-block">
        <h2>Lien de suivi du donateur</h2>
        <p class="hint">Partagé par toutes les propositions faites depuis le même appareil. Utile si le donateur a perdu son lien.</p>
        <?php unsold_copy_field('Lien de suivi', $publicUrl); ?>
      </div>

      <div class="admin-block">
        <h2>Historique</h2>
        <div class="don-table-wrap" style="margin-top:12px;">
          <table class="don-table">
            <thead><tr><th>Quand</th><th>Qui</th><th>Changement</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ($log as $l): ?>
              <tr>
                <td class="don-num"><?= h(unsold_fr_stamp($l['at'])) ?></td>
                <td class="don-small"><?= h(['admin' => 'Organisation', 'tour' => 'Bénévole de tournée', 'desk' => 'Bénévole du point', 'donor' => 'Donateur'][$l['actor']] ?? $l['actor']) ?></td>
                <td class="don-small"><?= $l['from_status'] ? h(unsold_statuses()[$l['from_status']] ?? $l['from_status']) . ' → ' : '' ?><?= h(unsold_statuses()[$l['to_status']] ?? $l['to_status']) ?></td>
                <td class="don-small don-muted"><?= h($l['note']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="admin-block">
        <h2>Suppression</h2>
        <?= unsold_admin_form_open('offer_delete', $self) ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <button class="admin-delete" type="submit" onclick="return confirm('Supprimer définitivement cette proposition et son historique ?');">Supprimer la proposition</button>
        </form>
      </div>
    </div>
  </div>
</section>
<?php unsold_admin_scripts(); unsold_admin_foot(); ?>
