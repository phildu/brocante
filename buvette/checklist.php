<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
bv_vol_init();

$event = bv_active_event();

if (bv_post_action() === 'toggle' && $event) {
    $id = (int) ($_POST['id'] ?? 0);
    $me = bv_me();
    $who = $me ? $me['name'] : 'Un bénévole';
    db()->prepare('UPDATE bv_tasks SET done = 1 - done,
        done_at = CASE WHEN done = 0 THEN datetime(\'now\', \'localtime\') ELSE NULL END,
        done_by = CASE WHEN done = 0 THEN ? ELSE \'\' END WHERE id = ? AND event_id = ?')->execute([$who, $id, $event['id']]);
    header('Location: /buvette/checklist.php#task-' . $id);
    exit;
}

$phases = $event ? bv_tasks_by_phase((int) $event['id']) : [];
$prog = $event ? bv_task_progress((int) $event['id']) : ['done' => 0, 'total' => 0, 'pct' => 0];

bv_vol_header('La check-list', 'checklist');
?>
<?php if (!$phases): ?><p class="bvv-card">La check-list n'est pas encore prête.</p><?php else: ?>
  <div class="bvv-card">
    <strong><?= $prog['done'] ?> sur <?= $prog['total'] ?></strong> faites
    <div class="bv-progress" role="progressbar" aria-valuenow="<?= $prog['pct'] ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $prog['pct'] ?>%"></span></div>
  </div>
<?php endif; ?>
<?php foreach ($phases as $phase => $tasks):
    $done = count(array_filter($tasks, fn($t) => $t['done']));
    $allDone = $done === count($tasks);
    ?>
  <details <?= $allDone ? '' : 'open' ?>>
    <summary class="bvv-phase-title" style="cursor:pointer;list-style:none;">
      <h2 style="font-size:1.05rem;"><?= h(bv_task_phases()[$phase]) ?></h2>
      <span class="bv-badge <?= $allDone ? 'ok' : '' ?>"><?= $done ?>/<?= count($tasks) ?></span>
    </summary>
    <?php foreach ($tasks as $t): ?>
      <div class="bvv-task <?= $t['done'] ? 'is-done' : '' ?>" id="task-<?= (int) $t['id'] ?>">
        <?= bv_form_open('toggle', ['id' => $t['id']]) ?>
          <button class="tick" type="submit" aria-pressed="<?= $t['done'] ? 'true' : 'false' ?>" aria-label="<?= $t['done'] ? 'Décocher' : 'Cocher' ?> : <?= h($t['label']) ?>"><?= $t['done'] ? '✓' : '' ?></button>
        </form>
        <div class="label">
          <?= h($t['label']) ?>
          <?php if ($t['assignee_name']): ?><div class="meta">→ <?= h($t['assignee_name']) ?></div><?php endif; ?>
          <?php if ($t['done'] && $t['done_at']): ?><div class="meta">fait<?= $t['done_by'] ? ' par ' . h($t['done_by']) : '' ?> à <?= h(date('H:i', strtotime($t['done_at']))) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </details>
<?php endforeach; ?>
<?php bv_vol_footer();
