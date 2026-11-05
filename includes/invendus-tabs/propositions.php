<?php
if (!defined('INVENDUS_ADMIN')) { http_response_code(403); exit; }
/** @var array $event @var int $id @var string $return */

$filters = [
    'status' => unsold_param($_GET, 'f_status'),
    'handover' => unsold_param($_GET, 'f_handover'),
    'tour' => unsold_param($_GET, 'f_tour'),
    'category' => unsold_param($_GET, 'f_cat'),
    'q' => unsold_param($_GET, 'f_q'),
    'confirmed' => unsold_param($_GET, 'f_confirmed'),
];
$offers = unsold_offers_list($id, $filters);
$tours = array_filter(unsold_tours_list($id), fn($t) => $t['status'] !== 'done');
$hasFilter = (bool) array_filter($filters);
$limit = 300;
$shown = array_slice($offers, 0, $limit);
?>
<form method="get" action="/admin/invendus-event.php" class="don-filters don-inline">
  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="propositions">
  <input type="search" name="f_q" value="<?= h($filters['q']) ?>" placeholder="Nom, objet, emplacement…" aria-label="Rechercher">
  <select name="f_status" aria-label="Statut">
    <option value="">Tous les statuts</option>
    <?php foreach (unsold_statuses() as $k => $l): ?><option value="<?= h($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
  </select>
  <select name="f_handover" aria-label="Mode de remise">
    <option value="">Toute remise</option>
    <option value="self"<?= $filters['handover'] === 'self' ? ' selected' : '' ?>>Apporté par le donateur</option>
    <option value="pickup"<?= $filters['handover'] === 'pickup' ? ' selected' : '' ?>>À ramasser</option>
  </select>
  <select name="f_tour" aria-label="Tournée">
    <option value="">Toute tournée</option>
    <option value="none"<?= $filters['tour'] === 'none' ? ' selected' : '' ?>>Sans tournée</option>
    <?php foreach (unsold_tours_list($id) as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $filters['tour'] === (string) $t['id'] ? ' selected' : '' ?>><?= h($t['volunteer_name']) ?></option><?php endforeach; ?>
  </select>
  <select name="f_cat" aria-label="Catégorie">
    <option value="">Toute catégorie</option>
    <?php foreach (unsold_categories() as $k => $l): ?><option value="<?= h($k) ?>"<?= $filters['category'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
  </select>
  <label class="don-small"><input type="checkbox" name="f_confirmed" value="1"<?= $filters['confirmed'] ? ' checked' : '' ?>> Confirmés seulement</label>
  <button class="btn-small" type="submit">Filtrer</button>
  <?php if ($hasFilter): ?><a class="btn-small" href="<?= h('/admin/invendus-event.php?id=' . $id . '&tab=propositions') ?>">Réinitialiser</a><?php endif; ?>
</form>

<details class="admin-block" style="margin-bottom:22px;">
  <summary style="cursor:pointer;font-weight:700;">Ajouter une proposition (saisie au stand, par téléphone…)</summary>
  <?= unsold_admin_form_open('offer_add', $return, 'class="don-form" enctype="multipart/form-data" style="margin-top:16px;"') ?>
    <input type="hidden" name="event_id" value="<?= $id ?>">
    <div class="field-row-2">
      <div class="field"><label>Nom du donateur *</label><input type="text" name="donor_name" maxlength="80" required></div>
      <div class="field"><label>N° d'emplacement</label><input type="text" name="stand_ref" maxlength="20"></div>
    </div>
    <div class="field-row-2">
      <div class="field"><label>Téléphone</label><input type="tel" name="donor_phone" maxlength="20"></div>
      <div class="field"><label>E-mail</label><input type="email" name="donor_email" maxlength="120"></div>
    </div>
    <div class="field"><label>Objet *</label><input type="text" name="title" maxlength="100" required></div>
    <div class="field-row-2">
      <div class="field"><label>Catégorie</label><select name="category"><?php foreach (unsold_categories() as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Volume</label><select name="size"><?php foreach (unsold_sizes() as $k => $s): ?><option value="<?= h($k) ?>"<?= $k === 'M' ? ' selected' : '' ?>><?= h($s['label']) ?> — <?= h($s['hint']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field-row-2">
      <div class="field"><label>Nombre de pièces</label><input type="number" name="quantity" value="1" min="1" max="999"></div>
      <div class="field"><label>Remise</label><select name="handover"><option value="self">Apporté au point par le donateur</option><option value="pickup">À ramasser à son stand</option></select></div>
    </div>
    <div class="field"><label>Précision pour retrouver l'objet</label><input type="text" name="pickup_note" maxlength="200"></div>
    <div class="field"><label>Détails</label><textarea name="description" maxlength="600" rows="2"></textarea></div>
    <div class="field"><label>Photo</label><input type="file" name="photo" accept="image/*"></div>
    <button class="btn btn-primary" type="submit">Ajouter</button>
  </form>
</details>

<p class="don-muted don-small" style="margin:0 0 10px;">
  <?= count($offers) ?> proposition<?= count($offers) > 1 ? 's' : '' ?><?= $hasFilter ? ' (filtrées)' : '' ?><?= count($offers) > $limit ? ' — les ' . $limit . ' plus récentes sont affichées, affinez avec les filtres' : '' ?>.
</p>

<form method="post" action="/admin/invendus-action.php" id="bulk-form">
  <?= csrf_input() ?>
  <input type="hidden" name="action" value="offer_bulk">
  <input type="hidden" name="return" value="<?= h($return . ($hasFilter ? '&' . http_build_query(array_filter(['f_q' => $filters['q'], 'f_status' => $filters['status'], 'f_handover' => $filters['handover'], 'f_tour' => $filters['tour'], 'f_cat' => $filters['category'], 'f_confirmed' => $filters['confirmed']])) : '')) ?>">
  <input type="hidden" name="event_id" value="<?= $id ?>">

  <div class="don-table-wrap">
    <table class="don-table">
      <thead>
        <tr>
          <th><input type="checkbox" data-check-all="bulk-form" aria-label="Tout cocher"></th>
          <th></th><th>Objet</th><th>Donateur</th><th>Remise</th><th>Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($shown as $o): $size = unsold_sizes()[$o['size']] ?? null; ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" aria-label="Sélectionner <?= h($o['title']) ?>"></td>
          <td><?php if (!empty($o['photo'])): ?><img class="don-thumb" src="/<?= h($o['photo']) ?>" alt="" loading="lazy"><?php endif; ?></td>
          <td>
            <a href="/admin/invendus-offer.php?id=<?= (int) $o['id'] ?>"><strong><?= h($o['title']) ?></strong></a><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?><br>
            <span class="don-muted don-small"><?= h(unsold_category_label($o['category'])) ?><?= $size ? ' · ' . h($size['label']) : '' ?></span>
          </td>
          <td>
            <?= h($o['donor_name']) ?><?= $o['stand_ref'] !== '' ? ' <span class="don-badge">' . h($o['stand_ref']) . '</span>' : '' ?><br>
            <span class="don-muted don-small"><?= h(trim($o['donor_phone'] . ' ' . $o['donor_email'])) ?></span>
          </td>
          <td class="don-small">
            <?= $o['handover'] === 'pickup' ? 'À ramasser' : 'Apporté' ?>
            <?php if ($o['tour_name']): ?><br><span class="don-muted">tournée <?= h($o['tour_name']) ?></span><?php endif; ?>
          </td>
          <td><?= unsold_status_badge($o['status']) ?><?= $o['confirmed_at'] && $o['status'] === 'proposed' ? '<br><span class="don-small" style="color:var(--sage);">confirmé</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shown): ?>
        <tr><td colspan="6" class="empty-state"><?= $hasFilter ? 'Aucune proposition ne correspond à ces filtres.' : 'Aucune proposition pour l\'instant. Diffusez le lien du formulaire aux exposants (onglet Aperçu).' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($shown): ?>
  <div class="don-bulk don-bulk--sticky don-row don-inline">
    <strong class="don-small">Pour la sélection :</strong>
    <select name="bulk" aria-label="Action">
      <option value="">Choisir une action…</option>
      <option value="confirm">Confirmer « c'est un invendu »</option>
      <option value="unconfirm">Annuler la confirmation</option>
      <option value="to_point">Marquer reçu au point de collecte</option>
      <option value="assign">Affecter à la tournée…</option>
      <option value="unassign">Détacher de sa tournée</option>
      <option value="withdraw">Retirer (vendu, repris, hors don)</option>
      <option value="restore">Restaurer (si retiré)</option>
      <option value="delete">Supprimer définitivement</option>
    </select>
    <select name="tour_id" aria-label="Tournée">
      <option value="">— tournée —</option>
      <?php foreach ($tours as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['volunteer_name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn-small don-ok" type="submit" onclick="if (this.form.bulk.value === 'delete') return confirm('Supprimer définitivement les propositions cochées ?');">Appliquer</button>
  </div>
  <?php endif; ?>
</form>
