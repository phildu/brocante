<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/invendus-layout.php';
require_admin();

$id = (int) unsold_param($_GET, 'id');
$event = unsold_event_get($id);
if (!$event) {
    flash_set('Édition introuvable.', 'error');
    header('Location: /admin/invendus.php');
    exit;
}

$tabs = [
    'apercu' => 'Aperçu',
    'propositions' => 'Propositions',
    'tournees' => 'Tournées',
    'point' => 'Point de collecte',
    'reliquat' => 'Reliquat',
];
$tab = unsold_param($_GET, 'tab');
if (!isset($tabs[$tab])) $tab = 'apercu';

$phase = unsold_event_phase($event);
$stats = unsold_event_stats($id);
$self = '/admin/invendus-event.php?id=' . $id;
$return = $self . '&tab=' . $tab;

// Pastilles de comptage dans les onglets.
$tabCounts = [
    'propositions' => $stats['counts']['proposed'],
    'tournees' => $stats['awaiting_pickup'],
    'point' => $stats['counts']['at_point'],
    'reliquat' => $stats['counts']['at_point'],
];

define('INVENDUS_ADMIN', true);
unsold_admin_head($event['name']);
?>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow"><a href="/admin/invendus.php" style="text-decoration:none;">← Invendus &amp; dons</a></p>
    <div class="don-row" style="margin:12px 0 0;">
      <h1 style="font-size:1.8rem;"><?= h($event['name']) ?></h1>
      <?= unsold_phase_badge($phase) ?>
    </div>
    <p class="don-muted" style="margin:6px 0 0;">
      <?= h(unsold_fr_date($event['event_date'])) ?> · <?= h($event['point_name']) ?> de <?= h(unsold_fr_time($event['collect_start'])) ?> à <?= h(unsold_fr_time($event['collect_end'])) ?>
    </p>

    <nav class="don-tabs" aria-label="Sections de l'édition">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= h($self . '&tab=' . $key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
          <?= h($label) ?><?php if (!empty($tabCounts[$key])): ?><span class="don-count"><?= (int) $tabCounts[$key] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php unsold_flash_html(); ?>

    <?php include __DIR__ . '/../includes/invendus-tabs/' . $tab . '.php'; ?>
  </div>
</section>
<?php unsold_admin_scripts(); unsold_admin_foot(); ?>
