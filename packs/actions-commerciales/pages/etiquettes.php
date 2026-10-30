<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

$ent = addon_entitlement(ACO_MODULE);
if (!$ent['entitled']) {
    echo addon_panel_html(ACO_MODULE, $pluginApi('access'), $pluginCsrf);
    echo addon_locked_html(ACO_MODULE);
    return;
}

$id = (int) ($_GET['id'] ?? 0);
$op = $id > 0 ? aco_op($id) : null;
if (!$op && $id === 0) {
    foreach (['running', 'scheduled', 'ended'] as $wanted) {
        foreach (aco_ops() as $candidate) {
            if ($candidate['status'] === $wanted) { $op = $candidate; break 2; }
        }
    }
}
$back = '/admin/plugin.php?' . http_build_query(['p' => $plugin['id'], 'v' => 'index']);
$report = '/admin/plugin.php?' . http_build_query(['p' => $plugin['id'], 'v' => 'bilan', 'id' => $op['id'] ?? 0]);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<p class="eyebrow no-print">Module · <?= h($plugin['name']) ?></p>
<h1 class="no-print" style="font-size:1.8rem;margin:12px 0;">Étiquettes de l'opération</h1>

<?php if (!$op): ?>
  <p class="hint">Aucune opération à étiqueter pour le moment. <a href="<?= $h($back) ?>">← Retour aux actions commerciales</a></p>
<?php else:
    $labels = [];
    if ($op['status'] === 'scheduled') {
        foreach (aco_scope($op) as $p) {
            $old = aco_current_cents($p);
            $new = aco_new_cents($p, $op);
            if ($new === null) continue;
            $labels[] = ['ref' => (string) $p['ref'], 'name' => (string) $p['name'], 'old' => $old, 'new' => $new];
        }
    } else {
        $st = db()->prepare('SELECT i.product_ref, i.old_cents, i.new_cents, p.name FROM aco_items i LEFT JOIN products p ON p.ref = i.product_ref
            WHERE i.operation_id = ? ORDER BY COALESCE(p.sort_order, 999), i.product_ref');
        $st->execute([(int) $op['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $labels[] = ['ref' => (string) $r['product_ref'], 'name' => (string) ($r['name'] ?? $r['product_ref']),
                'old' => (int) $r['old_cents'], 'new' => (int) $r['new_cents']];
        }
    }
    $label = trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']);
    $note = $op['status'] === 'scheduled'
        ? 'Aperçu : ces étiquettes correspondent aux prix appliqués au démarrage de l\'opération.'
        : ($op['status'] === 'running' ? 'Prix en vigueur pendant l\'opération.' : 'Derniers prix pratiqués pendant l\'opération.');
?>
<style>
  .aco-sheet-head { display:flex; gap:8px 16px; flex-wrap:wrap; align-items:baseline; border-bottom:2px solid #111; padding-bottom:6px; margin:18px 0 10px; }
  .aco-sheet-head .pill { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; border:1px solid #111; padding:2px 8px; }
  .aco-labels { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:4mm; }
  .aco-label { border:1px dashed #aaa; padding:3mm; min-height:35mm; display:flex; flex-direction:column; justify-content:space-between; gap:2mm; break-inside:avoid; page-break-inside:avoid; }
  .aco-label-top { display:flex; justify-content:space-between; gap:4px; font-size:7pt; text-transform:uppercase; letter-spacing:.04em; }
  .aco-label-name { font-size:9.5pt; font-weight:600; line-height:1.15; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .aco-label-prices { display:flex; align-items:baseline; gap:8px; }
  .aco-label-old { font-size:8.5pt; text-decoration:line-through; color:#555; }
  .aco-label-new { font-size:16pt; font-weight:800; line-height:1; }
  @media (max-width:700px) { .aco-labels { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
  @page { size: A4 portrait; margin: 8mm; }
  @media print {
    html.has-admin-sidebar body { padding-left:0 !important; }
    .admin-topbar, .admin-sidebar, .admin-sidebar-overlay, .no-print { display:none !important; }
    main, main section, section.tight, .wrap { padding:0 !important; margin:0 !important; max-width:none !important; }
    body { background:#fff !important; }
    .aco-sheet-head { margin-top:0; }
    .aco-labels { gap:3mm; }
    .aco-label { border:none; outline:0.2mm dashed #bbb; outline-offset:-0.2mm; }
  }
</style>

<div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 8px;">
  <a href="<?= $h($back) ?>" class="btn-small">← Opérations</a>
  <a href="<?= $h($report) ?>" class="btn-small">Bilan</a>
  <button type="button" class="btn-small" id="aco-print">Imprimer / PDF</button>
  <span class="hint"><?= count($labels) ?> étiquette(s) · <?= $h(aco_period_short($op)) ?><?= $op['place'] !== '' ? ' · ' . $h($op['place']) : '' ?></span>
</div>
<p class="hint no-print" style="margin:0 0 14px;"><?= $h($note) ?> Dans la fenêtre d'impression, choisissez « 100 % » / « taille réelle », marges « aucune » ou « par défaut ».</p>

<header class="aco-sheet-head">
  <span class="pill"><?= $h($label) ?></span>
  <strong style="font-size:1.05rem;"><?= $h($op['name']) ?></strong>
  <span class="hint"><?= $h(aco_period_short($op)) ?><?= $op['place'] !== '' ? ' · ' . $h($op['place']) : '' ?> · <?= $h(aco_discount_text($op)) ?></span>
</header>

<?php if (!$labels): ?>
  <p class="hint">Aucune pièce réduite dans cette opération.</p>
<?php else: ?>
<div class="aco-labels">
  <?php foreach ($labels as $l): ?>
  <div class="aco-label">
    <div class="aco-label-top"><span><?= $h($label) ?></span><span>Réf. <?= $h($l['ref']) ?></span></div>
    <div class="aco-label-name"><?= $h($l['name']) ?></div>
    <div class="aco-label-prices">
      <span class="aco-label-old"><?= $h(aco_format($l['old'])) ?></span>
      <span class="aco-label-new"><?= $h(aco_format($l['new'])) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
(function () { var b = document.getElementById('aco-print'); if (b) b.addEventListener('click', function () { window.print(); }); })();
</script>
<?php endif; ?>
