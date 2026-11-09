<?php
// Journal des dépenses de la boutique : tickets et achats, factures fournisseurs (à payer / payées) et notes de frais des collaborateurs.
// Calculs et règles : includes/expenses.php ; enregistrements : admin/depenses-action.php ; justificatifs : admin/depense-fichier.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/charts.php';
require_once __DIR__ . '/../includes/expenses.php';
require_admin();
ext_page();

$pdo = db();
expense_ensure_schema($pdo);
$content = get_content();
$flash = flash_get();

/* ---------------------------------------------------------------- vue */

$now = dash_now();
$today = $now->format('Y-m-d');
$tabs = ['journal' => 'Journal', 'factures' => 'Factures', 'notes' => 'Notes de frais'];
$tab = array_key_exists($_GET['s'] ?? '', $tabs) ? (string) $_GET['s'] : 'journal';
$p = (string) ($_GET['p'] ?? '');
[$from, $to, $periodLabel] = expense_period($p, $now);
$pKey = $p === 'tout' ? 'tout' : (preg_match('/^\d{4}$/', $p) && $from === $p . '-01-01' ? $p : substr($from, 0, 7));
if ($p === 'tout') { $from = $to = ''; }
$fKind = array_key_exists($_GET['kind'] ?? '', expense_kinds()) ? (string) $_GET['kind'] : '';
$fCat = array_key_exists($_GET['cat'] ?? '', expense_categories()) ? (string) $_GET['cat'] : '';
$fQ = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

$url = static function (array $over = []) use ($tab, $pKey, $fKind, $fCat, $fQ): string {
    $q = array_filter(array_merge(['s' => $tab === 'journal' ? '' : $tab, 'p' => $pKey, 'kind' => $fKind, 'cat' => $fCat, 'q' => $fQ], $over), static fn ($v): bool => $v !== '' && $v !== null);
    return '/admin/depenses.php' . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
};
$selfUrl = $url();
$return = static fn (string $anchor = ''): string => '<input type="hidden" name="return" value="' . h($selfUrl . ($anchor !== '' ? '#' . $anchor : '')) . '">' . shop_csrf_input();

// Période précédente / suivante (mois ou année).
$isYear = preg_match('/^\d{4}$/', $pKey) === 1;
$prevKey = $nextKey = '';
if ($pKey !== 'tout') {
    $start = new DateTimeImmutable($from);
    $prevKey = $start->modify($isYear ? '-1 year' : '-1 month')->format($isYear ? 'Y' : 'Y-m');
    $nextStart = $start->modify($isYear ? '+1 year' : '+1 month');
    if ($nextStart <= $now) $nextKey = $nextStart->format($isYear ? 'Y' : 'Y-m');
}

$sum = expense_summary($pdo, $from, $to, $today);
$eur = static fn (int|float $c): string => format_cents((int) round($c));
$session = admin_session();
$blank = static fn (string $kind, int $reportId = 0): array => ['id' => 0, 'kind' => $kind, 'spent_on' => $today, 'label' => '', 'supplier' => '', 'category' => 'autre',
    'amount_cents' => 0, 'vat_rate' => 20, 'payment_method' => '', 'invoice_no' => '', 'due_on' => null, 'paid_on' => null, 'report_id' => $reportId, 'receipt' => '', 'note' => ''];
$editable = static fn (?string $status): bool => $status === null || in_array($status, ['brouillon', 'refusee'], true);
$receiptLink = static fn (array $r): string => $r['receipt'] !== ''
    ? ' <a class="dash-link" href="/admin/depense-fichier.php?id=' . (int) $r['id'] . '" target="_blank" rel="noopener">Justificatif</a>' : '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dépenses &amp; notes de frais — <?= h($content['site_name']) ?></title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/dashboard.css">
<style>
  .exp-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin: 0 0 16px; }
  .exp-filters .field { margin: 0; min-width: 150px; }
  .exp-filters .field input, .exp-filters .field select { width: 100%; }
  .exp-warn { color: #b3261e; font-weight: 600; }
  .exp-sub { display: block; font-size: .8rem; opacity: .75; margin-top: 2px; }
  .exp-lines { margin: 12px 0; }
  .exp-actions-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
  .exp-actions-bar form { display: inline-flex; gap: 6px; align-items: center; }
  .exp-actions-bar input[type="text"] { padding: 6px 8px; border: 1px solid var(--line); background: var(--bg); color: inherit; font: inherit; min-width: 220px; }
  .exp-report > summary { cursor: pointer; font-weight: 600; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
  .exp-report { border: 1px solid var(--line); background: var(--bg); padding: 12px 14px; margin-bottom: 10px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'depenses'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight dash">
  <div class="wrap">

    <div class="dash-head">
      <div>
        <p class="eyebrow">Espace boutique</p>
        <h1>Dépenses &amp; notes de frais</h1>
        <p class="dash-sub">Journal des achats et tickets, factures fournisseurs à payer et notes de frais de l'équipe, avec leurs justificatifs.</p>
      </div>
      <div class="dash-controls">
        <div class="period-nav">
          <?php if ($pKey !== 'tout'): ?>
            <a class="btn-small" href="<?= h($url(['p' => $prevKey])) ?>" aria-label="Période précédente">←</a>
            <strong><?= h($periodLabel) ?></strong>
            <?php if ($nextKey !== ''): ?><a class="btn-small" href="<?= h($url(['p' => $nextKey])) ?>" aria-label="Période suivante">→</a><?php else: ?><span class="btn-small is-off" aria-hidden="true">→</span><?php endif; ?>
          <?php else: ?>
            <strong><?= h($periodLabel) ?></strong>
          <?php endif; ?>
        </div>
        <div class="seg" role="group" aria-label="Étendue">
          <a href="<?= h($url(['p' => $now->format('Y-m')])) ?>" aria-pressed="<?= $pKey === $now->format('Y-m') ? 'true' : 'false' ?>">Ce mois</a>
          <a href="<?= h($url(['p' => $now->format('Y')])) ?>" aria-pressed="<?= $pKey === $now->format('Y') ? 'true' : 'false' ?>">Cette année</a>
          <a href="<?= h($url(['p' => 'tout'])) ?>" aria-pressed="<?= $pKey === 'tout' ? 'true' : 'false' ?>">Tout</a>
        </div>
      </div>
    </div>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="kpi-grid">
      <article class="kpi">
        <h3>Dépenses — <?= h($periodLabel) ?></h3>
        <p class="kpi-v"><?= $eur($sum['ttc']) ?></p>
        <p class="kpi-s"><?= $eur($sum['ht']) ?> HT · <?= (int) $sum['count'] ?> ligne<?= $sum['count'] > 1 ? 's' : '' ?></p>
      </article>
      <article class="kpi">
        <h3>TVA contenue</h3>
        <p class="kpi-v"><?= $eur($sum['vat']) ?></p>
        <p class="kpi-s">à vérifier avec votre comptable avant toute déduction</p>
      </article>
      <article class="kpi">
        <h3>Factures à payer</h3>
        <p class="kpi-v"><?= $eur($sum['to_pay']['cents']) ?></p>
        <p class="kpi-s"><?php if ($sum['to_pay']['overdue_count']): ?><span class="exp-warn"><?= (int) $sum['to_pay']['overdue_count'] ?> en retard (<?= $eur($sum['to_pay']['overdue_cents']) ?>)</span><?php else: ?><?= (int) $sum['to_pay']['count'] ?> facture<?= $sum['to_pay']['count'] > 1 ? 's' : '' ?> en attente<?php endif; ?></p>
      </article>
      <article class="kpi">
        <h3>Notes de frais</h3>
        <p class="kpi-v"><?= (int) $sum['reports']['to_review'] ?> à valider</p>
        <p class="kpi-s"><?= $eur($sum['reports']['to_reimburse_cents']) ?> à rembourser (<?= (int) $sum['reports']['to_reimburse'] ?> note<?= $sum['reports']['to_reimburse'] > 1 ? 's' : '' ?>)</p>
      </article>
    </div>

    <div class="dash-head" style="margin-top:28px;">
      <div class="seg" role="group" aria-label="Section">
        <?php foreach ($tabs as $k => $lab): ?>
          <a href="<?= h($url(['s' => $k === 'journal' ? '' : $k, 'kind' => '', 'cat' => '', 'q' => ''])) ?>" aria-pressed="<?= $tab === $k ? 'true' : 'false' ?>"><?= h($lab) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($tab === 'journal'): ?>
        <a class="btn-small" href="/admin/depenses-export.php?<?= h(http_build_query(array_filter(['p' => $pKey, 'kind' => $fKind, 'cat' => $fCat, 'q' => $fQ]), '', '&', PHP_QUERY_RFC3986)) ?>">Exporter en CSV (comptable)</a>
      <?php endif; ?>
    </div>

<?php if ($tab === 'journal'):
    $rows = expense_list($pdo, ['from' => $from, 'to' => $to, 'kind' => $fKind, 'category' => $fCat, 'q' => $fQ]);
?>
    <section class="dash-block" id="journal">
      <h2>Journal — <?= h($periodLabel) ?></h2>
      <form method="get" action="/admin/depenses.php" class="exp-filters">
        <input type="hidden" name="p" value="<?= h($pKey) ?>">
        <div class="field"><label for="f-kind">Type</label><select id="f-kind" name="kind"><option value="">Tous</option><?php foreach (expense_kinds() as $k => $lab): ?><option value="<?= h($k) ?>"<?= $fKind === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="f-cat">Catégorie</label><select id="f-cat" name="cat"><option value="">Toutes</option><?php foreach (expense_categories() as $k => $lab): ?><option value="<?= h($k) ?>"<?= $fCat === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="f-q">Recherche</label><input type="search" id="f-q" name="q" value="<?= h($fQ) ?>" placeholder="libellé, fournisseur, n° de facture"></div>
        <button class="btn-small" type="submit">Filtrer</button>
        <?php if ($fKind !== '' || $fCat !== '' || $fQ !== ''): ?><a class="dash-link" href="<?= h($url(['kind' => '', 'cat' => '', 'q' => ''])) ?>">Effacer</a><?php endif; ?>
      </form>

      <?php if (!$rows): ?>
        <p class="dash-empty">Aucune ligne sur cette période<?= ($fKind !== '' || $fCat !== '' || $fQ !== '') ? ' avec ces filtres' : '' ?>. Ajoutez un achat, un ticket ou une facture ci-dessous.</p>
      <?php else: ?>
        <div class="cost-list">
          <?php foreach ($rows as $r):
              $canEdit = $r['kind'] !== 'frais' || $editable($r['report_status']);
              $waiting = $r['report_id'] && !in_array($r['report_status'], ['validee', 'remboursee'], true);
              $overdue = expense_is_overdue($r, $today);
          ?>
            <div class="cost-row<?= $waiting ? ' is-test' : '' ?>">
              <div class="cost-main">
                <strong><?= h($r['label']) ?></strong>
                <span class="chip chip-real"><?= h(expense_kinds()[$r['kind']] ?? $r['kind']) ?></span>
                <?php if ($r['kind'] === 'facture'): ?>
                  <?php if ($r['paid_on']): ?><span class="chip chip-real">Payée le <?= h(date('d/m/Y', strtotime((string) $r['paid_on']))) ?></span>
                  <?php else: ?><span class="chip chip-test<?= $overdue ? ' exp-warn' : '' ?>"><?= $overdue ? 'En retard' : 'À payer' ?></span><?php endif; ?>
                <?php elseif ($r['report_id']): ?>
                  <span class="chip chip-test"><?= h(expense_report_statuses()[$r['report_status']] ?? '') ?></span>
                <?php endif; ?>
                <span class="cost-amount"><?= $eur((int) $r['amount_cents']) ?> TTC<?= (int) $r['vat_cents'] > 0 ? ' · dont ' . $eur((int) $r['vat_cents']) . ' de TVA' : '' ?></span>
                <small class="cost-note">
                  <?= h(date('d/m/Y', strtotime((string) $r['spent_on']))) ?> · <?= h(expense_categories()[$r['category']] ?? $r['category']) ?>
                  <?= $r['supplier'] !== '' ? ' · ' . h($r['supplier']) : '' ?>
                  <?= $r['invoice_no'] !== '' ? ' · n° ' . h($r['invoice_no']) : '' ?>
                  <?= $r['report_id'] ? ' · note « ' . h($r['report_title']) . ' » (' . h($r['report_person']) . ')' : '' ?>
                  <?= $r['note'] !== '' ? ' — ' . h($r['note']) : '' ?>
                  <?= $receiptLink($r) ?>
                </small>
              </div>
              <?php if ($canEdit): ?>
                <div class="cost-actions">
                  <form method="post" action="/admin/depenses-action.php" onsubmit="return confirm('Supprimer cette ligne et son justificatif ?');"><?= $return('journal') ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="admin-delete" type="submit">Supprimer</button></form>
                </div>
                <details class="cost-edit">
                  <summary>Modifier</summary>
                  <?php
                    $exp = $r; $kinds = $r['kind'] === 'frais' ? ['frais' => expense_kinds()['frais']] : array_diff_key(expense_kinds(), ['frais' => 1]);
                    $reports = []; $anchor = 'journal'; include __DIR__ . '/../includes/expense-form.php';
                  ?>
                </details>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($waiting = array_filter($rows, static fn (array $r): bool => $r['report_id'] && !in_array($r['report_status'], ['validee', 'remboursee'], true))): ?>
          <p class="hint"><?= count($waiting) ?> ligne<?= count($waiting) > 1 ? 's' : '' ?> de note de frais (bordure en pointillés) ne compte<?= count($waiting) > 1 ? 'nt' : '' ?> pas encore dans les totaux : la note doit d'abord être validée.</p>
        <?php endif; ?>
      <?php endif; ?>

      <details class="cost-edit add" id="ajouter"<?= $flash && $flash['kind'] === 'error' ? ' open' : '' ?>>
        <summary class="add-product-btn">+ Ajouter une dépense ou une facture</summary>
        <?php $exp = $blank('depense'); $kinds = array_diff_key(expense_kinds(), ['frais' => 1]); $reports = []; $anchor = 'journal'; include __DIR__ . '/../includes/expense-form.php'; ?>
        <p class="hint">Un ticket ou un achat est réglé le jour même ; une facture reste « à payer » tant que vous ne saisissez pas sa date de paiement. Les frais avancés par un collaborateur se saisissent dans l'onglet « Notes de frais ».</p>
      </details>

      <?php if ($sum['by_category']): ?>
        <h3 style="margin-top:28px;">Répartition par catégorie — <?= h($periodLabel) ?></h3>
        <?= dash_hbars(array_map(static fn ($k, $v) => ['label' => expense_categories()[$k] ?? $k, 'value' => $v], array_keys($sum['by_category']), $sum['by_category']), static fn ($v) => $eur($v), 'var(--ink-soft)') ?>
      <?php endif; ?>
      <p class="hint">Ne comptent que les lignes réellement engagées : les lignes d'une note de frais non validée sont écartées. Les frais récurrents (hébergement, abonnements…) restent gérés dans « Rentabilité » : ce journal garde la trace des pièces comptables, sans modifier votre résultat.</p>
    </section>

<?php elseif ($tab === 'factures'):
    $unpaid = expense_list($pdo, ['unpaid' => true]);
    usort($unpaid, static fn (array $a, array $b): int => strcmp((string) ($a['due_on'] ?: '9999-12-31'), (string) ($b['due_on'] ?: '9999-12-31')));
    $paid = array_filter(expense_list($pdo, ['from' => $from, 'to' => $to, 'kind' => 'facture', 'q' => $fQ]), static fn (array $r): bool => $r['paid_on'] !== null);
?>
    <section class="dash-block" id="factures">
      <h2>Factures à payer</h2>
      <?php if (!$unpaid): ?>
        <p class="dash-empty">Aucune facture en attente de paiement.</p>
      <?php else: ?>
        <div class="cost-list">
          <?php foreach ($unpaid as $r): $overdue = expense_is_overdue($r, $today); ?>
            <div class="cost-row">
              <div class="cost-main">
                <strong><?= h($r['supplier'] !== '' ? $r['supplier'] : $r['label']) ?></strong>
                <span class="chip chip-test<?= $overdue ? ' exp-warn' : '' ?>"><?= $overdue ? 'En retard' : 'À payer' ?></span>
                <span class="cost-amount"><?= $eur((int) $r['amount_cents']) ?> TTC</span>
                <small class="cost-note">
                  <?= h($r['label']) ?> · facture du <?= h(date('d/m/Y', strtotime((string) $r['spent_on']))) ?>
                  <?= $r['invoice_no'] !== '' ? ' · n° ' . h($r['invoice_no']) : '' ?>
                  <?= $r['due_on'] ? ' · échéance ' . h(date('d/m/Y', strtotime((string) $r['due_on']))) : ' · sans échéance' ?>
                  <?= $receiptLink($r) ?>
                </small>
              </div>
              <div class="cost-actions">
                <form method="post" action="/admin/depenses-action.php"><?= $return('factures') ?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="date" name="paid_on" value="<?= h($today) ?>" aria-label="Date de paiement"><button class="btn-small" type="submit">Marquer payée</button></form>
              </div>
              <details class="cost-edit">
                <summary>Modifier</summary>
                <?php $exp = $r; $kinds = ['facture' => expense_kinds()['facture']]; $reports = []; $anchor = 'factures'; include __DIR__ . '/../includes/expense-form.php'; ?>
              </details>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <details class="cost-edit add" id="ajouter"<?= $flash && $flash['kind'] === 'error' ? ' open' : '' ?>>
        <summary class="add-product-btn">+ Ajouter une facture fournisseur</summary>
        <?php $exp = $blank('facture'); $kinds = ['facture' => expense_kinds()['facture']]; $reports = []; $anchor = 'factures'; include __DIR__ . '/../includes/expense-form.php'; ?>
      </details>

      <h3 style="margin-top:28px;">Factures payées — <?= h($periodLabel) ?></h3>
      <?php if (!$paid): ?>
        <p class="dash-empty">Aucune facture payée sur cette période.</p>
      <?php else: ?>
        <table class="dash-table">
          <thead><tr><th>Facture</th><th>Date</th><th>Payée le</th><th class="r">TTC</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($paid as $r): ?>
              <tr>
                <th scope="row"><?= h($r['supplier'] !== '' ? $r['supplier'] : $r['label']) ?><small><?= h($r['label']) ?><?= $r['invoice_no'] !== '' ? ' · n° ' . h($r['invoice_no']) : '' ?></small></th>
                <td><?= h(date('d/m/Y', strtotime((string) $r['spent_on']))) ?></td>
                <td><?= h(date('d/m/Y', strtotime((string) $r['paid_on']))) ?></td>
                <td class="r"><?= $eur((int) $r['amount_cents']) ?><?= $receiptLink($r) ?></td>
                <td><form method="post" action="/admin/depenses-action.php" onsubmit="return confirm('Remettre cette facture à payer ?');"><?= $return('factures') ?><input type="hidden" name="action" value="paid"><input type="hidden" name="paid" value="0"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn-small" type="submit">Remettre à payer</button></form></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <p class="hint">Une facture compte dans les dépenses à sa date de facture, payée ou non. La saisie d'un même numéro pour un même fournisseur déclenche une alerte de doublon.</p>
    </section>

<?php else:
    $reports = expense_reports_list($pdo);
    $openAnchor = (string) ($_GET['n'] ?? '');
?>
    <section class="dash-block" id="notes">
      <h2>Notes de frais</h2>
      <p class="hint">Une note regroupe les frais avancés par une personne (déplacement, repas, fournitures…). Elle suit le parcours <strong>brouillon → à valider → validée → remboursée</strong> ; seules les notes validées comptent dans les dépenses.</p>

      <?php if (!$reports): ?>
        <p class="dash-empty">Aucune note de frais pour l'instant.</p>
      <?php endif; ?>

      <?php foreach ($reports as $rep):
          $lines = expense_list($pdo, ['report_id' => (int) $rep['id']]);
          $canEditRep = $editable((string) $rep['status']);
          $anchorId = 'note-' . (int) $rep['id'];
          $reportKinds = ['frais' => expense_kinds()['frais']];
      ?>
        <details class="exp-report" id="<?= h($anchorId) ?>"<?= in_array($rep['status'], ['soumise', 'validee'], true) || $openAnchor === (string) $rep['id'] || $canEditRep ? ' open' : '' ?>>
          <summary>
            <span><?= h($rep['title']) ?></span>
            <span class="chip <?= in_array($rep['status'], ['validee', 'remboursee'], true) ? 'chip-real' : 'chip-test' ?>"><?= h(expense_report_statuses()[$rep['status']] ?? $rep['status']) ?></span>
            <span class="cost-amount"><?= $eur((int) $rep['total_cents']) ?> · <?= h($rep['person']) ?> · <?= (int) $rep['lines'] ?> ligne<?= $rep['lines'] > 1 ? 's' : '' ?></span>
          </summary>

          <?php if ($rep['status'] === 'refusee' && $rep['decision_note'] !== ''): ?><p class="hint warn">Refusée : <?= h($rep['decision_note']) ?></p><?php endif; ?>
          <?php if ($rep['reimbursed_at']): ?><p class="hint">Remboursée le <?= h(date('d/m/Y', strtotime((string) $rep['reimbursed_at']))) ?>.</p><?php endif; ?>

          <?php if ($lines): ?>
            <div class="cost-list exp-lines">
              <?php foreach ($lines as $r): ?>
                <div class="cost-row">
                  <div class="cost-main">
                    <strong><?= h($r['label']) ?></strong>
                    <span class="cost-amount"><?= $eur((int) $r['amount_cents']) ?> TTC<?= (int) $r['vat_cents'] > 0 ? ' · dont ' . $eur((int) $r['vat_cents']) . ' de TVA' : '' ?></span>
                    <small class="cost-note"><?= h(date('d/m/Y', strtotime((string) $r['spent_on']))) ?> · <?= h(expense_categories()[$r['category']] ?? $r['category']) ?><?= $r['supplier'] !== '' ? ' · ' . h($r['supplier']) : '' ?><?= $r['note'] !== '' ? ' — ' . h($r['note']) : '' ?><?= $receiptLink($r) ?></small>
                  </div>
                  <?php if ($canEditRep): ?>
                    <div class="cost-actions">
                      <form method="post" action="/admin/depenses-action.php" onsubmit="return confirm('Supprimer cette ligne ?');"><?= $return($anchorId) ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="admin-delete" type="submit">Supprimer</button></form>
                    </div>
                    <details class="cost-edit">
                      <summary>Modifier</summary>
                      <?php $exp = $r; $kinds = $reportKinds; $anchor = $anchorId; include __DIR__ . '/../includes/expense-form.php'; ?>
                    </details>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="dash-empty">Aucune ligne : ajoutez vos frais avec leur justificatif.</p>
          <?php endif; ?>

          <?php if ($canEditRep): ?>
            <details class="cost-edit add">
              <summary class="add-product-btn">+ Ajouter une ligne de frais</summary>
              <?php $exp = $blank('frais', (int) $rep['id']); $kinds = $reportKinds; $anchor = $anchorId; include __DIR__ . '/../includes/expense-form.php'; ?>
            </details>
          <?php endif; ?>

          <div class="exp-actions-bar">
            <?php
              $post = static function (string $to, string $label, bool $primary = false, string $confirm = '') use ($rep, $return, $anchorId): string {
                  return '<form method="post" action="/admin/depenses-action.php"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ');"' : '') . '>'
                      . $return($anchorId) . '<input type="hidden" name="action" value="report_transition"><input type="hidden" name="id" value="' . (int) $rep['id'] . '"><input type="hidden" name="to" value="' . h($to) . '">'
                      . '<button class="' . ($primary ? 'btn btn-primary' : 'btn-small') . '" type="submit">' . h($label) . '</button></form>';
              };
              if ($rep['status'] === 'brouillon') echo $post('soumise', 'Soumettre pour validation', true);
              if ($rep['status'] === 'soumise') {
                  echo $post('validee', 'Valider la note', true);
                  echo '<form method="post" action="/admin/depenses-action.php">' . $return($anchorId) . '<input type="hidden" name="action" value="report_transition"><input type="hidden" name="id" value="' . (int) $rep['id'] . '"><input type="hidden" name="to" value="refusee">'
                      . '<input type="text" name="decision_note" maxlength="300" placeholder="Motif du refus" aria-label="Motif du refus" required><button class="btn-small" type="submit">Refuser</button></form>';
                  echo $post('brouillon', 'Rappeler en brouillon');
              }
              if ($rep['status'] === 'validee') echo $post('remboursee', 'Marquer remboursée (' . $eur((int) $rep['total_cents']) . ')', true, 'Confirmer que ' . $rep['person'] . ' a bien été remboursé·e ?');
              if ($rep['status'] === 'refusee') echo $post('brouillon', 'Reprendre en brouillon');
              if ($canEditRep): ?>
              <form method="post" action="/admin/depenses-action.php" onsubmit="return confirm('Supprimer cette note et toutes ses lignes ?');"><?= $return('notes') ?><input type="hidden" name="action" value="report_delete"><input type="hidden" name="id" value="<?= (int) $rep['id'] ?>"><button class="admin-delete" type="submit">Supprimer la note</button></form>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>

      <details class="cost-edit add" id="nouvelle-note"<?= !$reports ? ' open' : '' ?>>
        <summary class="add-product-btn">+ Nouvelle note de frais</summary>
        <form method="post" action="/admin/depenses-action.php" class="cost-form">
          <?= $return('notes') ?>
          <input type="hidden" name="action" value="report_create">
          <div class="field-row-2">
            <div class="field"><label for="rp-title">Titre</label><input type="text" id="rp-title" name="title" required maxlength="120" placeholder="ex : Salon de Lille, novembre"></div>
            <div class="field"><label for="rp-person">Personne</label><input type="text" id="rp-person" name="person" required maxlength="80" value="<?= h($session['name'] ?? '') ?>" placeholder="qui a avancé les frais"></div>
          </div>
          <button class="btn btn-primary" type="submit">Créer la note</button>
        </form>
      </details>
    </section>
<?php endif; ?>

  </div>
</section>
</main>
<script>
// Les champs propres aux factures (n°, échéance, paiement) ne s'affichent que pour le type « Facture fournisseur ».
document.querySelectorAll('form.cost-form select[name="kind"]').forEach(function (select) {
  var form = select.form;
  function sync() {
    form.querySelectorAll('[data-only="facture"]').forEach(function (row) { row.hidden = select.value !== 'facture'; });
  }
  select.addEventListener('change', sync);
  sync();
});
</script>
</body>
</html>
