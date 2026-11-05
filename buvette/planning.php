<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
bv_vol_init();

$event = bv_active_event();
$me = bv_me();
$shifts = $event ? bv_shifts((int) $event['id']) : [];

bv_vol_header('Le planning', 'planning');
?>
<?php if ($event): ?>
  <p class="bv-muted" style="margin:-6px 0 16px;"><?= h($event['name']) ?><?= $event['event_date'] ? ' · ' . h(bv_fr_date($event['event_date'])) : '' ?></p>
<?php endif; ?>
<?php if (!$shifts): ?><p class="bvv-card">Le planning n'est pas encore établi.</p><?php endif; ?>
<?php foreach ($shifts as $s):
    $mine = $me && isset($s['volunteers'][(int) $me['id']]);
    ?>
  <div class="bvv-shift <?= $mine ? 'is-mine' : '' ?>">
    <div class="time"><?= h($s['start_time']) ?>–<?= h($s['end_time']) ?><?= $mine ? ' · <strong style="color:var(--accent);">c\'est vous</strong>' : '' ?></div>
    <div class="role"><?= h($s['role']) ?></div>
    <div class="who">
      <?php if ($s['volunteers']): ?>
        <?= h(implode(', ', $s['volunteers'])) ?>
        <?php if ($s['missing'] > 0): ?><span class="bv-badge warn">il manque <?= (int) $s['missing'] ?> personne<?= $s['missing'] > 1 ? 's' : '' ?></span><?php endif; ?>
      <?php else: ?><span class="bv-badge warn">à pourvoir · <?= (int) $s['needed'] ?> personne<?= $s['needed'] > 1 ? 's' : '' ?></span><?php endif; ?>
    </div>
    <?php if ($s['notes']): ?><div class="bv-muted" style="font-size:0.85rem;margin-top:4px;"><?= h($s['notes']) ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (array_filter($shifts, fn($s) => $s['missing'] > 0)): ?>
  <p class="bvv-note">Un créneau est encore à pourvoir ? Dites-le au responsable de la buvette.</p>
<?php endif; ?>
<?php bv_vol_footer();
