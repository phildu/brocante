<?php
require_once __DIR__ . '/../includes/buvette-layout.php';
bv_vol_init();

if (bv_post_action() === 'set_me') {
    $id = (int) ($_POST['volunteer_id'] ?? 0);
    if ($id > 0) {
        $chk = db()->prepare('SELECT COUNT(*) FROM bv_volunteers WHERE id = ? AND is_active = 1');
        $chk->execute([$id]);
        if ($chk->fetchColumn()) $_SESSION['bv_me'] = $id;
    } else {
        unset($_SESSION['bv_me']);
    }
    header('Location: /buvette/index.php');
    exit;
}

$event = bv_active_event();
$me = bv_me();
$volunteers = bv_volunteers_list(true);
$myShifts = [];
$prog = null;
if ($event) {
    $prog = bv_task_progress((int) $event['id']);
    if ($me) {
        foreach (bv_shifts((int) $event['id']) as $s) {
            if (isset($s['volunteers'][(int) $me['id']])) $myShifts[] = $s;
        }
    }
}

bv_vol_header('La buvette', 'accueil');
?>
<?php if ($event): ?>
  <div class="bvv-hero">
    <p class="eyebrow">Prochain événement</p>
    <p class="when" style="margin:4px 0;"><?= h($event['name']) ?></p>
    <p style="margin:0;">
      <?php if ($event['event_date']): ?><?= h(bv_fr_date($event['event_date'])) ?> · <span class="count"><?= h(bv_countdown_label($event['event_date'])) ?></span><?php endif; ?>
      <?php if ($event['place']): ?><br><?= h($event['place']) ?><?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="bvv-hero"><p style="margin:0;">Aucun événement n'est encore prévu. Revenez bientôt !</p></div>
<?php endif; ?>

<div class="bvv-card">
  <?= bv_form_open('set_me') ?>
    <label for="who" style="font-weight:600;display:block;margin-bottom:8px;">Je suis…</label>
    <div style="display:flex;gap:8px;">
      <select id="who" name="volunteer_id" class="bvv-select" onchange="this.form.submit()">
        <option value="0">— choisir mon prénom —</option>
        <?php foreach ($volunteers as $v): ?><option value="<?= (int) $v['id'] ?>" <?= $me && (int) $me['id'] === (int) $v['id'] ? 'selected' : '' ?>><?= h($v['name']) ?></option><?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-primary" type="submit">OK</button></noscript>
    </div>
  </form>
  <?php if ($me): ?>
    <div style="margin-top:14px;">
      <strong>Mes créneaux</strong>
      <?php if ($myShifts): foreach ($myShifts as $s): ?>
        <div style="padding:8px 0;border-top:1px solid var(--line);margin-top:8px;"><span style="font-family:var(--font-mono);"><?= h($s['start_time']) ?>–<?= h($s['end_time']) ?></span> · <strong><?= h($s['role']) ?></strong>
          <?php if ($s['notes']): ?><div class="bv-muted" style="font-size:0.85rem;"><?= h($s['notes']) ?></div><?php endif; ?></div>
      <?php endforeach; else: ?>
        <p class="bv-muted" style="margin:6px 0 0;">Vous n'avez pas encore de créneau — voyez avec le responsable.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="bvv-tiles">
  <a class="bvv-tile" href="/buvette/carte.php"><span class="ico" aria-hidden="true">☕</span><span class="t">La carte</span><span class="s">Prix et allergènes</span></a>
  <a class="bvv-tile" href="/buvette/recettes.php"><span class="ico" aria-hidden="true">🍳</span><span class="t">Les recettes</span><span class="s">Quantités et préparation</span></a>
  <a class="bvv-tile" href="/buvette/planning.php"><span class="ico" aria-hidden="true">🗓️</span><span class="t">Le planning</span><span class="s">Qui fait quoi, quand</span></a>
  <a class="bvv-tile" href="/buvette/checklist.php"><span class="ico" aria-hidden="true">✅</span><span class="t">La check-list</span><span class="s"><?= $prog && $prog['total'] ? $prog['done'] . ' / ' . $prog['total'] . ' faites' : 'À cocher le jour J' ?></span></a>
</div>
<?php bv_vol_footer();
