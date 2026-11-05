<?php
if (!defined('INVENDUS_ADMIN')) { http_response_code(403); exit; }
/** @var array $event @var array $stats @var string $phase @var int $id @var string $return */

$stock = unsold_offers_list($id, ['status' => 'at_point']);
$deskUrl = unsold_abs_url('/dons-point.php?t=' . $event['desk_token']);
?>
<div class="don-stats">
  <div class="don-stat"><b><?= (int) $stats['counts']['at_point'] ?></b><span>Au point maintenant</span></div>
  <div class="don-stat"><b><?= (int) $stats['units_at_point'] ?></b><span>Unités de volume</span></div>
  <div class="don-stat"><b><?= (int) $stats['counts']['picked'] ?></b><span>En route (vélo cargo)</span></div>
  <div class="don-stat"><b><?= (int) $stats['counts']['proposed'] ?></b><span>Pas encore arrivés</span></div>
  <div class="don-stat don-stat--hl"><b><?= (int) $stats['counts']['taken'] ?></b><span>Déjà pris</span></div>
</div>

<div class="admin-block" style="margin-bottom:22px;">
  <h2>Pour les bénévoles du point</h2>
  <p class="hint">Donnez ce lien aux personnes qui tiennent le point de collecte : sur téléphone, elles réceptionnent les objets, marquent « pris » ceux que les visiteurs emportent et enregistrent les dépôts spontanés.</p>
  <?php unsold_copy_field('Lien secret du point de collecte', $deskUrl); ?>
  <a class="btn-small" href="<?= h($deskUrl) ?>" target="_blank">Ouvrir la page des bénévoles</a>
</div>

<h2 style="font-size:1.25rem;margin-bottom:10px;">Au point de collecte</h2>
<div class="don-table-wrap">
  <table class="don-table">
    <thead><tr><th></th><th>Objet</th><th>Catégorie</th><th>Volume</th><th>Arrivé</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($stock as $o): $size = unsold_sizes()[$o['size']] ?? null; ?>
      <tr>
        <td><?php if (!empty($o['photo'])): ?><img class="don-thumb" src="/<?= h($o['photo']) ?>" alt="" loading="lazy"><?php endif; ?></td>
        <td><a href="/admin/invendus-offer.php?id=<?= (int) $o['id'] ?>"><strong><?= h($o['title']) ?></strong></a><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?></td>
        <td class="don-small"><?= h(unsold_category_label($o['category'])) ?></td>
        <td class="don-small"><?= h($size['label'] ?? '') ?></td>
        <td class="don-num don-small"><?= h(unsold_fr_stamp($o['updated_at'])) ?></td>
        <td>
          <div class="don-row">
            <?= unsold_admin_form_open('offer_status', $return) ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="to" value="taken">
              <button class="btn-small don-ok" type="submit">Pris</button></form>
            <?= unsold_admin_form_open('offer_status', $return) ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="to" value="withdrawn">
              <button class="btn-small" type="submit" onclick="return confirm('Retirer cet objet du point ?');">Retirer</button></form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$stock): ?>
      <tr><td colspan="6" class="empty-state">Rien au point de collecte pour l'instant.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
