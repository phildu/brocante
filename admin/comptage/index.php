<?php
require_once __DIR__ . '/../../includes/comptage-layout.php';
require_once __DIR__ . '/../../includes/charts.php';
cp_admin_init();

$events = cp_events_list();
$active = cp_active_event();
$eventId = (int) ($_GET['event'] ?? ($_POST['event'] ?? 0));
$event = $eventId ? cp_event_get($eventId) : ($active ?: ($events[0] ?? null));
$bucket = (int) ($_GET['bucket'] ?? 30);
if (!in_array($bucket, [15, 30, 60], true)) $bucket = 30;

$self = static fn(array $extra = []) => '/admin/comptage/index.php?' . http_build_query(array_merge(['event' => $event['id'] ?? 0, 'bucket' => $bucket], $extra));

if ($event) {
    switch (cp_post_action()) {
        case 'correction': {
            $gate = (int) ($_POST['gate_id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'out' ? -1 : 1;
            $qty = (int) ($_POST['qty'] ?? 0);
            if (cp_correction((int) $event['id'], $gate, $dir, $qty)) {
                cp_redirect($self(), 'Correction enregistrée : ' . ($dir > 0 ? '+' : '−') . cp_n($qty) . ' ' . ($dir > 0 ? 'entrée' : 'sortie') . ($qty > 1 ? 's' : '') . '.');
            }
            cp_redirect($self(), 'Correction invalide : choisissez un poste et un nombre entre 1 et 100 000.', 'error');
        }
        case 'reset': {
            $n = cp_reset((int) $event['id']);
            cp_redirect($self(), 'Compteurs remis à zéro (' . cp_n($n) . ' appui' . ($n > 1 ? 's' : '') . ' effacé' . ($n > 1 ? 's' : '') . ').');
        }
    }
}

cp_admin_header('Comptage de foule', 'resultats');

if (!$event): ?>
  <div class="bv-callout">Aucun événement pour l'instant — <a href="/admin/comptage/evenements.php">créez-en un</a> pour commencer à compter les visiteurs.</div>
<?php
    cp_admin_footer();
    exit;
endif;

$eid = (int) $event['id'];
$totals = cp_totals($eid);
$stats = cp_stats($eid);
$timeline = cp_timeline($eid, $bucket);
$gauge = cp_gauge($stats['present'], (int) $event['capacity']);
$trackExits = (bool) $event['track_exits'];

$busiest = null;
foreach ($timeline as $row) {
    if ($busiest === null || $row['in'] > $busiest['in']) $busiest = $row;
}
?>
<form method="get" class="cp-toolbar bv-noprint">
  <label>Événement
    <select name="event" onchange="this.form.submit()">
      <?php foreach ($events as $e): ?>
        <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $eid ? 'selected' : '' ?>><?= h($e['name']) ?><?= $e['event_date'] ? ' — ' . h(date('d/m/Y', strtotime($e['event_date']))) : '' ?><?= $e['is_active'] ? ' (en cours)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Tranches de
    <select name="bucket" onchange="this.form.submit()">
      <?php foreach ([15, 30, 60] as $b): ?><option value="<?= $b ?>" <?= $b === $bucket ? 'selected' : '' ?>><?= $b ?> min</option><?php endforeach; ?>
    </select>
  </label>
  <label class="cp-live"><input type="checkbox" id="auto"> Actualiser toutes les 30 s</label>
  <a class="btn-small" href="/admin/comptage/export.php?event=<?= $eid ?>&amp;what=timeline&amp;bucket=<?= $bucket ?>">Exporter la courbe (CSV)</a>
  <a class="btn-small" href="/admin/comptage/export.php?event=<?= $eid ?>&amp;what=taps">Exporter le détail (CSV)</a>
</form>

<?php if (!$event['is_active']): ?>
  <p class="bv-callout">Cet événement n'est pas celui que les bénévoles comptent actuellement. <a href="/admin/comptage/evenements.php#evt-<?= $eid ?>">Le rendre actif</a>.</p>
<?php endif; ?>

<div class="bv-stats">
  <div class="bv-stat">
    <div class="label">Visiteurs entrés</div>
    <div class="value"><?= cp_n($stats['visitors']) ?></div>
    <div class="sub"><?= $stats['taps'] ? 'sur ' . cp_n($stats['taps']) . ' appui' . ($stats['taps'] > 1 ? 's' : '') : 'aucun appui pour l\'instant' ?></div>
  </div>
  <?php if ($trackExits): ?>
  <div class="bv-stat <?= $gauge && $gauge['level'] === 'full' ? 'is-bad' : '' ?>">
    <div class="label">Sur place maintenant</div>
    <div class="value"><?= cp_n($stats['present']) ?></div>
    <div class="sub"><?= $gauge ? $gauge['pct'] . ' % de la capacité (' . cp_n($event['capacity']) . ')' : cp_n($stats['exits']) . ' sortie' . ($stats['exits'] > 1 ? 's' : '') . ' comptée' . ($stats['exits'] > 1 ? 's' : '') ?></div>
  </div>
  <div class="bv-stat">
    <div class="label">Pic de présence</div>
    <div class="value"><?= cp_n($stats['peak']) ?></div>
    <div class="sub"><?= $stats['peak_at'] ? 'à ' . h(cp_hm($stats['peak_at'])) : '—' ?></div>
  </div>
  <?php endif; ?>
  <div class="bv-stat">
    <div class="label">Tranche la plus chargée</div>
    <div class="value"><?= $busiest && $busiest['in'] > 0 ? h($busiest['label']) : '—' ?></div>
    <div class="sub"><?= $busiest && $busiest['in'] > 0 ? cp_n($busiest['in']) . ' entrées en ' . $bucket . ' min' : 'pas encore de données' ?></div>
  </div>
  <div class="bv-stat">
    <div class="label">Premier → dernier appui</div>
    <div class="value"><?= $stats['first'] ? h(cp_hm($stats['first'])) . ' → ' . h(cp_hm($stats['last'])) : '—' ?></div>
    <div class="sub"><?= $stats['first'] ? cp_duration($stats['last'] - $stats['first']) . ' de comptage' : '&nbsp;' ?></div>
  </div>
</div>

<div class="cp-chart">
  <h2>La journée, tranche par tranche</h2>
  <?php if ($timeline):
      $series = [['name' => 'Entrées', 'type' => 'bar', 'values' => array_column($timeline, 'in'), 'color' => 'var(--accent)']];
      if ($trackExits) {
          $series[] = ['name' => 'Sorties', 'type' => 'bar', 'values' => array_column($timeline, 'out'), 'color' => 'var(--accent-2)'];
          $series[] = ['name' => 'Personnes sur place', 'type' => 'line', 'values' => array_column($timeline, 'present'), 'color' => 'var(--sage)'];
      }
      $hlines = $event['capacity'] > 0 && $trackExits ? [['value' => (float) $event['capacity'], 'label' => 'Capacité', 'color' => 'var(--ink-soft)']] : [];
      $fmtN = static fn($v) => cp_n(round((float) $v));
      echo dash_chart(array_column($timeline, 'label'), $series, $hlines, $fmtN, 'Entrées, sorties et présence par tranche de ' . $bucket . ' minutes', max(1, (int) ceil(count($timeline) / 12)));
      echo dash_legend(array_map(fn($s) => ['name' => $s['name'], 'color' => $s['color']], $series));
  else: ?>
    <p class="dash-empty big">Rien à afficher : dès que les bénévoles appuient sur le compteur, la courbe se dessine ici.</p>
  <?php endif; ?>
</div>

<div class="bv-card">
  <h2>Par poste de comptage</h2>
  <div class="bv-table-wrap">
    <table class="bv-table">
      <thead><tr><th>Poste</th><th class="num">Entrées</th><?php if ($trackExits): ?><th class="num">Sorties</th><?php endif; ?><th class="num">Part des entrées</th></tr></thead>
      <tbody>
      <?php foreach ($totals['gates'] as $g): ?>
        <tr>
          <td><?= h($g['name']) ?></td>
          <td class="num"><?= cp_n($g['in']) ?></td>
          <?php if ($trackExits): ?><td class="num"><?= cp_n($g['out']) ?></td><?php endif; ?>
          <td class="num"><?= $totals['in'] > 0 ? round($g['in'] / $totals['in'] * 100) . ' %' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><td class="num"><?= cp_n($totals['in']) ?></td><?php if ($trackExits): ?><td class="num"><?= cp_n($totals['out']) ?></td><?php endif; ?><td class="num"></td></tr></tfoot>
    </table>
  </div>
</div>

<details class="bv-fold bv-noprint">
  <summary>Corriger un comptage ou tout remettre à zéro</summary>
  <div class="bv-fold-body">
    <p class="bv-hint" style="margin-top:12px;">Un bénévole a oublié son compteur une heure ? Ajoutez ou retirez des personnes à la main. La correction est datée de maintenant et marquée « correction » dans l'export détaillé.</p>
    <?= cp_form_open('correction', ['event' => $eid], '', 'cp-inline') ?>
      <label>Poste
        <select name="gate_id"><?php foreach ($totals['gates'] as $g): ?><option value="<?= (int) $g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select>
      </label>
      <label>Sens
        <select name="dir"><option value="in">Entrées (+)</option><?php if ($trackExits): ?><option value="out">Sorties (+)</option><?php endif; ?></select>
      </label>
      <label>Nombre de personnes
        <input type="number" name="qty" min="1" max="100000" value="10" required>
      </label>
      <button class="btn-small" type="submit">Ajouter</button>
    </form>
    <hr style="border:none;border-top:1px solid var(--line);margin:20px 0;">
    <?= cp_form_open('reset', ['event' => $eid], 'Remettre à zéro tous les compteurs de « ' . $event['name'] . ' » ? Cette action est définitive (pensez à exporter avant).', 'cp-inline') ?>
      <button class="btn-small bv-btn-danger" type="submit">Remettre les compteurs à zéro</button>
      <span class="bv-hint" style="margin:0;">Utile après un essai avant le jour J. Les postes sont conservés.</span>
    </form>
  </div>
</details>

<script>
(function () {
  var box = document.getElementById('auto'), KEY = 'cp:admin:auto';
  try { box.checked = localStorage.getItem(KEY) === '1'; } catch (e) {}
  box.addEventListener('change', function () { try { localStorage.setItem(KEY, box.checked ? '1' : '0'); } catch (e) {} });
  setInterval(function () {
    var open = document.querySelector('details[open]');
    var typing = document.activeElement && /INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName);
    if (box.checked && !open && !typing && document.visibilityState === 'visible') location.reload();
  }, 30000);
})();
</script>
<?php cp_admin_footer();
