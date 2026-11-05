<?php
require_once __DIR__ . '/../../includes/buvette-layout.php';
bv_admin_init();

$event = bv_active_event();
$self = '/admin/buvette/checklist.php';

switch (bv_post_action()) {
    case 'toggle': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($event) {
            db()->prepare('UPDATE bv_tasks SET done = 1 - done, done_at = CASE WHEN done = 0 THEN datetime(\'now\', \'localtime\') ELSE NULL END,
                done_by = CASE WHEN done = 0 THEN \'Responsable\' ELSE \'\' END WHERE id = ? AND event_id = ?')->execute([$id, $event['id']]);
        }
        bv_redirect($self . '#task-' . $id);
    }

    case 'add_task': {
        if (!$event) bv_redirect($self, 'Créez d\'abord un événement.', 'error');
        $label = trim((string) ($_POST['label'] ?? ''));
        $phase = (string) ($_POST['phase'] ?? 'avant');
        if (!isset(bv_task_phases()[$phase])) $phase = 'avant';
        if ($label === '') bv_redirect($self . '#ajouter', 'Décrivez la tâche.', 'error');
        $assignee = (int) ($_POST['assignee_id'] ?? 0) ?: null;
        $order = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM bv_tasks WHERE event_id = ' . (int) $event['id'])->fetchColumn();
        db()->prepare('INSERT INTO bv_tasks (event_id, phase, label, assignee_id, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$event['id'], $phase, mb_substr($label, 0, 300), $assignee, $order]);
        bv_redirect($self . '#phase-' . $phase, 'Tâche ajoutée.');
    }

    case 'assign_task': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($event) {
            db()->prepare('UPDATE bv_tasks SET assignee_id = ? WHERE id = ? AND event_id = ?')->execute([(int) ($_POST['assignee_id'] ?? 0) ?: null, $id, $event['id']]);
        }
        bv_redirect($self . '#task-' . $id);
    }

    case 'delete_task': {
        if ($event) db()->prepare('DELETE FROM bv_tasks WHERE id = ? AND event_id = ?')->execute([(int) ($_POST['id'] ?? 0), $event['id']]);
        bv_redirect($self, 'Tâche supprimée.');
    }

    case 'load_defaults': {
        if (!$event) bv_redirect($self, 'Créez d\'abord un événement.', 'error');
        $n = bv_load_default_checklist((int) $event['id']);
        bv_redirect($self, $n ? $n . ' tâche' . ($n > 1 ? 's' : '') . ' de la check-list type ajoutée' . ($n > 1 ? 's' : '') . '.' : 'La check-list type est déjà complète : rien à ajouter.');
    }

    case 'reset': {
        if ($event) db()->prepare('UPDATE bv_tasks SET done = 0, done_at = NULL, done_by = \'\' WHERE event_id = ?')->execute([$event['id']]);
        bv_redirect($self, 'Toutes les tâches sont décochées.');
    }
}

bv_admin_header('Check-list', 'checklist');

if (!$event): ?>
  <p class="bv-callout">Créez d'abord un événement depuis le <a href="/admin/buvette/index.php#nouvel-evenement">tableau de bord</a>.</p>
<?php
    bv_admin_footer();
    exit;
endif;

$eid = (int) $event['id'];
$phases = bv_tasks_by_phase($eid);
$prog = bv_task_progress($eid);
$volunteers = bv_volunteers_list(true);
?>
<p class="bv-hint">Tout ce qu'il ne faut pas oublier, de la demande en mairie au rangement. Les bénévoles voient la même liste sur leur téléphone et cochent au fur et à mesure. Les points réglementaires (autorisation de buvette, hygiène, allergènes) sont des rappels : vérifiez les règles exactes auprès de votre mairie.</p>

<div class="bv-stats">
  <div class="bv-stat">
    <div class="label">Avancement</div>
    <div class="value"><?= $prog['done'] ?> / <?= $prog['total'] ?></div>
    <div class="bv-progress" role="progressbar" aria-valuenow="<?= $prog['pct'] ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $prog['pct'] ?>%"></span></div>
  </div>
</div>

<div class="bv-actions bv-noprint" style="margin:0 0 20px;">
  <button class="btn btn-ghost" type="button" onclick="window.print()">Imprimer</button>
  <?= bv_form_open('load_defaults', [], '', 'bv-inline') ?><button class="btn-small" type="submit"><?= $prog['total'] ? 'Compléter avec la check-list type' : 'Charger la check-list type' ?></button></form>
  <?php if ($prog['done']): ?>
    <?= bv_form_open('reset', [], 'Décocher toutes les tâches ?', 'bv-inline') ?><button class="btn-small bv-btn-danger" type="submit">Tout décocher</button></form>
  <?php endif; ?>
</div>

<?php if (!$phases): ?>
  <p class="bv-callout">La check-list est vide. Chargez la check-list type ci-dessus, ou ajoutez vos propres tâches plus bas.</p>
<?php endif; ?>

<?php foreach ($phases as $phase => $tasks):
    $pd = count(array_filter($tasks, fn($t) => $t['done']));
    ?>
  <section class="bv-phase" id="phase-<?= h($phase) ?>">
    <h2><?= h(bv_task_phases()[$phase]) ?> <span class="bv-badge <?= $pd === count($tasks) ? 'ok' : '' ?>"><?= $pd ?>/<?= count($tasks) ?></span></h2>
    <?php foreach ($tasks as $t): ?>
      <div class="bv-task <?= $t['done'] ? 'is-done' : '' ?>" id="task-<?= (int) $t['id'] ?>">
        <?= bv_form_open('toggle', ['id' => $t['id']]) ?><button class="tick" type="submit" aria-label="<?= $t['done'] ? 'Décocher' : 'Cocher' ?> : <?= h($t['label']) ?>" aria-pressed="<?= $t['done'] ? 'true' : 'false' ?>"><?= $t['done'] ? '✓' : '' ?></button></form>
        <div class="label">
          <?= h($t['label']) ?>
          <?php if ($t['done'] && $t['done_at']): ?><div class="meta">fait<?= $t['done_by'] ? ' par ' . h($t['done_by']) : '' ?> le <?= h(date('d/m à H:i', strtotime($t['done_at']))) ?></div><?php endif; ?>
        </div>
        <div class="tools bv-noprint">
          <?= bv_form_open('assign_task', ['id' => $t['id']], '', 'bv-inline') ?>
            <select class="bv-input" name="assignee_id" onchange="this.form.submit()" aria-label="Responsable de la tâche : <?= h($t['label']) ?>">
              <option value="0">— personne —</option>
              <?php foreach ($volunteers as $v): ?><option value="<?= (int) $v['id'] ?>" <?= (int) $t['assignee_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= h($v['name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn-small" type="submit">OK</button></noscript>
          </form>
          <?= bv_form_open('delete_task', ['id' => $t['id']], 'Supprimer cette tâche ?', 'bv-inline') ?><button class="admin-delete" type="submit" aria-label="Supprimer la tâche">✕</button></form>
        </div>
        <?php if ($t['assignee_name']): ?><span class="bv-print-only">(<?= h($t['assignee_name']) ?>)</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<div class="bv-card bv-noprint" id="ajouter">
  <h2>Ajouter une tâche</h2>
  <?= bv_form_open('add_task') ?>
    <div class="bv-form-grid" style="margin-top:12px;">
      <div class="field span-2"><label>Tâche</label><input type="text" name="label" required maxlength="300" placeholder="Récupérer les tables chez la mairie"></div>
      <div class="field"><label>Quand ?</label>
        <select name="phase"><?php foreach (bv_task_phases() as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Responsable</label>
        <select name="assignee_id"><option value="0">— personne —</option><?php foreach ($volunteers as $v): ?><option value="<?= (int) $v['id'] ?>"><?= h($v['name']) ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="bv-actions"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</div>
<?php
bv_admin_footer();
