<?php
if (!defined('INVENDUS_ADMIN')) { http_response_code(403); exit; }
/** @var array $event @var array $stats @var string $phase @var int $id @var string $return */

$stock = unsold_offers_list($id, ['status' => 'at_point']);
$recipients = unsold_recipients_list(true);
$donations = unsold_donations_list($id);
$stillOpen = $phase === 'collecting';
?>
<?php if ($stillOpen): ?>
  <div class="don-alert don-alert--warn" style="margin-bottom:20px;">
    Le point de collecte est <strong>encore ouvert</strong> : les visiteurs peuvent encore se servir. Fermez-le (onglet Aperçu) avant de remettre le reliquat.
  </div>
<?php endif; ?>

<div class="admin-block">
  <h2>Remettre le reliquat à une donnerie</h2>
  <p class="hint">
    Ce qui reste au point de collecte (<strong><?= count($stock) ?> objet<?= count($stock) > 1 ? 's' : '' ?></strong>)
    part vers une structure : recyclerie, Emmaüs, association… Décochez ce qui ne peut pas être donné
    (retirez-le depuis l'onglet « Point de collecte »). La remise génère un <strong>bordereau</strong> à imprimer et à faire signer.
  </p>

  <?php if (!$recipients): ?>
    <p class="empty-state">Aucune structure n'est enregistrée. <a href="/admin/invendus-structures.php" style="color:var(--accent);">Ajoutez une donnerie →</a></p>
  <?php elseif (!$stock): ?>
    <p class="empty-state">Rien à remettre : le point de collecte est vide.</p>
  <?php else: ?>
    <form method="post" action="/admin/invendus-action.php" id="donation-form" class="don-form">
      <?= shop_csrf_input() ?><input type="hidden" name="action" value="donation_create">
      <input type="hidden" name="return" value="<?= h($return) ?>"><input type="hidden" name="event_id" value="<?= $id ?>">

      <div class="field-row-2">
        <div class="field">
          <label for="rec-id">Structure bénéficiaire *</label>
          <select id="rec-id" name="recipient_id" required>
            <?php foreach ($recipients as $r): ?>
              <option value="<?= (int) $r['id'] ?>"><?= h($r['name']) ?><?= $r['accepts'] !== '' ? ' — accepte : ' . h(mb_strimwidth($r['accepts'], 0, 60, '…')) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="rec-by">Remis par (nom du bénévole)</label>
          <input type="text" id="rec-by" name="handed_by" maxlength="80">
        </div>
      </div>
      <div class="field"><label for="rec-note">Remarque (facultatif)</label><input type="text" id="rec-note" name="note" maxlength="400" placeholder="ex : enlèvement par camion à 19h"></div>

      <div class="don-table-wrap">
        <table class="don-table">
          <thead><tr><th><input type="checkbox" data-check-all="donation-form" aria-label="Tout cocher" checked></th><th>Objet</th><th>Catégorie</th><th>Volume</th></tr></thead>
          <tbody>
          <?php foreach ($stock as $o): $size = unsold_sizes()[$o['size']] ?? null; ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" checked aria-label="Remettre <?= h($o['title']) ?>"></td>
              <td><?= h($o['title']) ?><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?></td>
              <td class="don-small"><?= h(unsold_category_label($o['category'])) ?></td>
              <td class="don-small"><?= h($size['label'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:16px;">
        <button class="btn btn-primary" type="submit" onclick="return confirm('Remettre les objets cochés à cette structure ? Ils ne seront plus disponibles pour les visiteurs.');">Remettre et créer le bordereau</button>
      </p>
    </form>
  <?php endif; ?>
</div>

<h2 style="font-size:1.25rem;margin:30px 0 10px;">Remises effectuées</h2>
<div class="don-table-wrap">
  <table class="don-table">
    <thead><tr><th>Date</th><th>Structure</th><th>Objets</th><th>Remis par</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($donations as $d): ?>
      <tr>
        <td class="don-num"><?= h(unsold_fr_stamp($d['created_at'])) ?></td>
        <td><?= h($d['recipient_name']) ?></td>
        <td class="don-num"><?= (int) $d['offers_count'] ?></td>
        <td><?= h($d['handed_by']) ?></td>
        <td><a class="btn-small" href="/admin/invendus-bordereau.php?id=<?= (int) $d['id'] ?>" target="_blank">Bordereau</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$donations): ?>
      <tr><td colspan="5" class="empty-state">Aucune remise pour l'instant.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
