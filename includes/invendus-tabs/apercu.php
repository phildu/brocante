<?php
if (!defined('INVENDUS_ADMIN')) { http_response_code(403); exit; }
/** @var array $event @var array $stats @var string $phase @var int $id @var string $return */

$c = $stats['counts'];
$visible = $event['status'] === 'published';
?>
<div class="don-stats">
  <div class="don-stat"><b><?= (int) ($stats['total'] - $c['withdrawn']) ?></b><span>Propositions actives</span></div>
  <div class="don-stat"><b><?= (int) $stats['confirmed'] ?></b><span>Invendus confirmés</span></div>
  <div class="don-stat"><b><?= (int) $stats['awaiting_pickup'] ?></b><span>Ramassages à affecter</span></div>
  <div class="don-stat"><b><?= (int) $c['picked'] ?></b><span>En route</span></div>
  <div class="don-stat"><b><?= (int) $c['at_point'] ?></b><span>Au point</span></div>
  <div class="don-stat"><b><?= (int) $c['taken'] ?></b><span>Pris par des visiteurs</span></div>
  <div class="don-stat"><b><?= (int) $c['donated'] ?></b><span>Remis à une donnerie</span></div>
  <div class="don-stat don-stat--hl"><b><?= (int) $stats['saved'] ?></b><span>Objets sauvés du rebut</span></div>
</div>

<div class="admin-shell" style="gap:28px;">

  <div class="admin-block">
    <h2>Déroulé de la journée</h2>
    <p class="hint">
      Le point ouvre tout seul à l'heure prévue (<?= h(unsold_fr_time($event['collect_start'])) ?>). Si le vide-grenier
      se termine plus tôt, ouvrez-le à la main ; fermez-le quand la collecte est finie, puis remettez le reliquat.
    </p>
    <div class="don-row">
      <?php if ($phase === 'proposals'): ?>
        <?= unsold_admin_form_open('event_open_now', $return) ?><input type="hidden" name="event_id" value="<?= $id ?>">
          <button class="btn btn-primary" type="submit" onclick="return confirm('Ouvrir le point de collecte maintenant ? Les visiteurs verront les objets arrivés.');">Ouvrir le point maintenant</button></form>
      <?php elseif ($phase === 'collecting'): ?>
        <?= unsold_admin_form_open('event_end_now', $return) ?><input type="hidden" name="event_id" value="<?= $id ?>">
          <button class="btn btn-primary" type="submit" onclick="return confirm('Fermer le point de collecte maintenant ? Les visiteurs ne verront plus la liste.');">Fermer le point maintenant</button></form>
      <?php elseif ($phase === 'ended'): ?>
        <a class="btn btn-primary" href="<?= h('/admin/invendus-event.php?id=' . $id . '&tab=reliquat') ?>">Remettre le reliquat à une donnerie</a>
        <?= unsold_admin_form_open('event_close', $return) ?><input type="hidden" name="event_id" value="<?= $id ?>">
          <button class="btn btn-ghost" type="submit">Clôturer l'édition</button></form>
      <?php elseif ($phase === 'closed'): ?>
        <?= unsold_admin_form_open('event_reopen', $return) ?><input type="hidden" name="event_id" value="<?= $id ?>">
          <button class="btn btn-ghost" type="submit">Rouvrir l'édition</button></form>
      <?php else: ?>
        <span class="don-muted">Brouillon : publiez l'édition (formulaire ci-dessous, champ « Visibilité ») pour ouvrir les propositions au public.</span>
      <?php endif; ?>
      <a class="btn-small" href="/admin/invendus-export.php?event=<?= $id ?>">Exporter en CSV</a>
    </div>
  </div>

  <div class="admin-block">
    <h2>Liens</h2>
    <p class="hint">À transmettre selon le rôle. Les liens « secrets » donnent accès sans mot de passe : ne les publiez pas.</p>
    <?php
      unsold_copy_field('Page publique (visiteurs)', unsold_abs_url('/dons.php?event=' . $id), $visible ? 'Visible par tout le monde.' : 'Invisible tant que l\'édition est en brouillon.');
      unsold_copy_field('Formulaire de proposition (exposants)', unsold_abs_url('/dons-proposer.php?event=' . $id), 'À diffuser aux exposants : mail d\'inscription, affiche avec QR code, réseaux sociaux.');
      unsold_copy_field('Lien secret : bénévoles du point de collecte', unsold_abs_url('/dons-point.php?t=' . $event['desk_token']), 'Réception des objets, « pris » par les visiteurs, dépôts spontanés — sur téléphone.');
    ?>
  </div>

  <div class="admin-block">
    <h2>Modifier l'édition</h2>
    <?php unsold_render_event_form($event, $return); ?>
  </div>

  <div class="admin-block">
    <h2>Zone dangereuse</h2>
    <p class="hint">Supprime l'édition avec toutes ses propositions, tournées et remises. Possible seulement pour une édition en « Brouillon » ou « Clôturée ».</p>
    <?= unsold_admin_form_open('event_delete', $return) ?><input type="hidden" name="event_id" value="<?= $id ?>">
      <button type="submit" class="admin-delete" onclick="return confirm('Supprimer définitivement cette édition et tout ce qu\'elle contient ?');">Supprimer l'édition</button>
    </form>
  </div>
</div>
