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
    foreach (['running', 'ended', 'scheduled'] as $wanted) {
        foreach (aco_ops() as $candidate) {
            if ($candidate['status'] === $wanted) { $op = $candidate; break 2; }
        }
    }
}
$back = '/admin/plugin.php?' . http_build_query(['p' => $plugin['id'], 'v' => 'index']);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<p class="eyebrow no-print">Module · <?= h($plugin['name']) ?></p>
<h1 class="no-print" style="font-size:1.8rem;margin:12px 0;">Bilan de l'opération</h1>

<?php if (!$op): ?>
  <p class="hint">Aucune opération à chiffrer pour le moment. <a href="<?= $h($back) ?>">← Retour aux actions commerciales</a></p>
<?php else:
    $totals = aco_totals($op);
    if ($op['status'] === 'scheduled' || $totals['count'] === 0) {
        $analysis = aco_analyze($op);
        $count = $analysis['discounted'];
        $old = $analysis['before_cents'];
        $new = $analysis['after_cents'];
    } else {
        $count = $totals['count'];
        $old = $totals['old_cents'];
        $new = $totals['new_cents'];
    }
    $pct = $old > 0 ? (int) round((1 - $new / $old) * 100) : 0;
    $report = aco_sales_report($op);
    $share = $report['revenue_cents'] > 0 ? (int) round($report['op_revenue_cents'] * 100 / $report['revenue_cents']) : 0;
    $openStats = ['sent' => 0, 'opened' => 0, 'opens' => 0, 'last_open' => 0];
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail-tracking.php';
        $openStats = mail_open_context_summary('aco:' . (int) $op['id']);
    } catch (Throwable $e) {
    }
    $statusLabels = ['scheduled' => 'programmée', 'running' => 'en cours', 'ended' => 'terminée', 'canceled' => 'annulée'];
    $label = trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']);
    $stopped = (int) ($op['stopped_at'] ?? 0);
    $stoppedText = $stopped > 0 && $stopped < (int) $op['ends_at'] ? ' · arrêtée le ' . date('d/m/Y H:i', $stopped) : '';
    $labelsUrl = '/admin/plugin.php?' . http_build_query(['p' => $plugin['id'], 'v' => 'etiquettes', 'id' => (int) $op['id']]);
    $csvUrl = $pluginApi('export') . '&id=' . (int) $op['id'];
?>
<style>
  .aco-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin:14px 0 18px; }
  .aco-kpi { border:1px solid var(--line); padding:12px 14px; }
  .aco-kpi .v { font-size:1.45rem; font-weight:800; line-height:1.1; }
  .aco-kpi .l { font-size:.8rem; color:var(--ink-soft); }
  .aco-bilan-table { width:100%; border-collapse:collapse; font-size:.9rem; }
  .aco-bilan-table th, .aco-bilan-table td { border-bottom:1px solid var(--line); padding:6px 8px; text-align:left; }
  .aco-bilan-table th { font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
  .aco-tag { font-size:.72rem; border:1px solid var(--line); padding:1px 6px; white-space:nowrap; }
  @page { size: A4 portrait; margin: 10mm; }
  @media print {
    html.has-admin-sidebar body { padding-left:0 !important; }
    .admin-topbar, .admin-sidebar, .admin-sidebar-overlay, .no-print { display:none !important; }
    main, main section, section.tight, .wrap { padding:0 !important; margin:0 !important; max-width:none !important; }
    body { background:#fff !important; }
    .aco-kpis { grid-template-columns:repeat(3, 1fr); }
    .aco-kpi, .aco-bilan-table th, .aco-bilan-table td { border-color:#bbb; }
    h2 { break-after:avoid; }
  }
</style>

<div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 8px;">
  <a href="<?= $h($back) ?>" class="btn-small">← Opérations</a>
  <a href="<?= $h($labelsUrl) ?>" class="btn-small">Étiquettes</a>
  <a href="<?= $h($csvUrl) ?>" class="btn-small">Télécharger le CSV</a>
  <button type="button" class="btn-small" id="aco-print">Imprimer / PDF</button>
</div>

<header style="border-bottom:2px solid var(--ink);padding-bottom:8px;margin:0 0 6px;">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:baseline;">
    <span style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;border:1px solid var(--ink);padding:2px 8px;"><?= $h($label) ?></span>
    <strong style="font-size:1.2rem;"><?= $h($op['name']) ?></strong>
    <span class="hint"><?= $h($statusLabels[$op['status']] ?? $op['status']) ?> · <?= $h(aco_period_short($op)) ?><?= $h($stoppedText) ?><?= $op['place'] !== '' ? ' · ' . $h($op['place']) : '' ?> · <?= $h(aco_discount_text($op)) ?></span>
  </div>
</header>

<?php if ($op['status'] === 'scheduled'): ?>
  <p class="hint">Opération programmée : les montants ci-dessous sont une projection ; les ventes apparaîtront au fil de la période.</p>
<?php endif; ?>

<div class="aco-kpis">
  <div class="aco-kpi"><div class="v"><?= (int) $report['orders_count'] ?></div><div class="l">commande(s) payée(s) pendant la période</div></div>
  <div class="aco-kpi"><div class="v"><?= $h(aco_format((int) $report['revenue_cents'])) ?></div><div class="l">chiffre d'affaires, toute la boutique</div></div>
  <div class="aco-kpi"><div class="v"><?= $h(aco_format((int) $report['op_revenue_cents'])) ?></div><div class="l">dont pièces de l'opération (<?= (int) $report['op_units'] ?> pièce(s) · <?= $share ?> %)</div></div>
  <div class="aco-kpi"><div class="v"><?= (int) $count ?></div><div class="l">pièce(s) réduite(s)</div></div>
  <div class="aco-kpi"><div class="v"><?= $h(aco_format($old)) ?></div><div class="l">valeur avant l'opération</div></div>
  <div class="aco-kpi"><div class="v"><?= $h(aco_format($new)) ?></div><div class="l">valeur au prix de l'opération (−<?= $pct ?> %)</div></div>
  <?php if ((int) $openStats['sent'] > 0): ?>
    <div class="aco-kpi"><div class="v"><?= (int) $openStats['opened'] ?></div><div class="l">annonce(s) ouverte(s) sur <?= (int) $openStats['sent'] ?> envoyée(s) · <?= (int) $openStats['opens'] ?> ouverture(s)</div></div>
  <?php endif; ?>
</div>

<h2 style="font-size:1.05rem;margin:18px 0 6px;">Ventes de pièces de l'opération, par catégorie</h2>
<?php if (!$report['by_cat']): ?>
  <p class="hint">Aucune vente de pièce de l'opération sur la période.</p>
<?php else: ?>
<div style="overflow-x:auto;"><table class="aco-bilan-table">
  <thead><tr><th>Catégorie</th><th align="right">Pièces</th><th align="right">Montant</th></tr></thead>
  <tbody>
  <?php foreach ($report['by_cat'] as $cat => $row): ?>
    <tr><td><?= $h($cat) ?></td><td align="right"><?= (int) $row['qty'] ?></td><td align="right"><?= $h(aco_format((int) $row['total_cents'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h2 style="font-size:1.05rem;margin:18px 0 6px;">Pièces vendues</h2>
<?php if (!$report['by_ref']): ?>
  <p class="hint">Aucune vente sur la période.</p>
<?php else: ?>
<div style="overflow-x:auto;"><table class="aco-bilan-table">
  <thead><tr><th>Réf.</th><th>Pièce</th><th align="right">Quantité</th><th align="right">Montant</th><th>Opération</th></tr></thead>
  <tbody>
  <?php foreach ($report['by_ref'] as $ref => $row): ?>
    <tr>
      <td><?= $h($ref) ?></td>
      <td><?= $h($row['name']) ?></td>
      <td align="right"><?= (int) $row['qty'] ?></td>
      <td align="right"><?= $h(aco_format((int) $row['total_cents'])) ?></td>
      <td><?= $row['in_op'] ? '<span class="aco-tag">oui</span>' : '<span class="hint">non</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<h2 style="font-size:1.05rem;margin:18px 0 6px;">Commandes payées</h2>
<?php if (!$report['orders']): ?>
  <p class="hint">Aucune commande payée sur la période.</p>
<?php else: ?>
<div style="overflow-x:auto;"><table class="aco-bilan-table">
  <thead><tr><th>Date</th><th>Commande</th><th>Canal</th><th align="right">Pièces de l'opération</th><th align="right">Total</th></tr></thead>
  <tbody>
  <?php foreach ($report['orders'] as $o):
      $units = 0;
      foreach ($o['lines'] as $l) if ($l['in_op']) $units += $l['qty'];
      $at = strtotime($o['at'] . ' UTC');
  ?>
    <tr>
      <td><?= $h($at ? date('d/m/Y H:i', $at) : $o['at']) ?></td>
      <td>#<?= (int) $o['id'] ?><?= $o['email'] !== '' ? '<div class="hint">' . $h($o['email']) . '</div>' : '' ?></td>
      <td><?= $h($o['method']) ?></td>
      <td align="right"><?= (int) $units ?></td>
      <td align="right"><?= $h(aco_format((int) $o['total_cents'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<p class="hint" style="margin-top:14px;">Le chiffre d'affaires couvre toutes les commandes payées entre le début et la fin de l'opération, y compris les ventes en boutique ; la colonne « Opération » isole les pièces réduites.</p>
<script>
(function () { var b = document.getElementById('aco-print'); if (b) b.addEventListener('click', function () { window.print(); }); })();
</script>
<?php endif; ?>
