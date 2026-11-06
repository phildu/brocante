<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/invendus-layout.php';
require_admin();
require_once __DIR__ . '/../includes/shop-modules.php';
shop_module_require('invendus-dons');   // plugin activé + accès payé, en essai, offert ou inclus

$events = unsold_events_list();

unsold_admin_head('Invendus & dons');
?>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Zéro gaspillage</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Invendus &amp; dons</h1>
    <p class="hint don-muted" style="max-width:70ch;">
      Une <strong>édition</strong> correspond à un vide-grenier. Les exposants y déposent leurs propositions de dons ;
      à l'heure que vous fixez, le <strong>point de collecte</strong> ouvre. Les objets y arrivent (apportés, ou ramassés
      lors d'une <strong>tournée</strong> à vélo cargo), les visiteurs se servent gratuitement, puis le
      <strong>reliquat</strong> part vers une donnerie avec un bordereau de remise.
    </p>

    <?php unsold_flash_html(); ?>

    <div class="don-row" style="margin:18px 0;">
      <a class="btn-small" href="/admin/invendus-structures.php">Donneries (structures qui reçoivent le reliquat)</a>
      <a class="btn-small" href="/dons.php" target="_blank">Voir la page publique</a>
    </div>

    <div class="don-table-wrap">
      <table class="don-table">
        <thead><tr><th>Édition</th><th>Date</th><th>Point de collecte</th><th>État</th><th>Propositions</th><th>Au point</th><th>Sauvés</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($events as $e):
            $st = unsold_event_stats((int) $e['id']);
        ?>
          <tr>
            <td><strong><?= h($e['name']) ?></strong></td>
            <td class="don-num"><?= h(unsold_fr_date($e['event_date'])) ?></td>
            <td class="don-num"><?= h(unsold_fr_time($e['collect_start'])) ?> – <?= h(unsold_fr_time($e['collect_end'])) ?></td>
            <td><?= unsold_phase_badge(unsold_event_phase($e)) ?></td>
            <td class="don-num"><?= (int) $st['total'] ?></td>
            <td class="don-num"><?= (int) $st['counts']['at_point'] ?></td>
            <td class="don-num"><?= (int) $st['saved'] ?></td>
            <td><a class="btn-small" href="/admin/invendus-event.php?id=<?= (int) $e['id'] ?>">Gérer</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?>
          <tr><td colspan="8" class="empty-state">Aucune édition pour l'instant — créez la première ci-dessous.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-block" style="margin-top:32px;">
      <h2>Nouvelle édition</h2>
      <p class="hint">Vous pourrez tout modifier ensuite. Laissez en « Brouillon » tant que vous préparez ; la passer en « Publiée » ouvre les propositions au public.</p>
      <?php unsold_render_event_form(null, '/admin/invendus.php'); ?>
    </div>
  </div>
</section>
<?php unsold_admin_foot(); ?>
