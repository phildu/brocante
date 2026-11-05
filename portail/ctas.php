<?php
// Laboratoire des appels à l'action du site public : tous les CTA, leur libellé, leurs variantes A/B,
// la répartition du trafic et les compteurs d'affichages / clics (data/saas/cta.db, voir includes/cta.php).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/cta.php';

$registry = cta_registry();
$days = in_array((int) ($_GET['jours'] ?? 30), [7, 30, 90, 365], true) ? (int) ($_GET['jours'] ?? 30) : 30;
$from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
$to = gmdate('Y-m-d');
$ctr = static fn (int $impressions, int $clicks): string => $impressions > 0
    ? str_replace('.', ',', (string) round($clicks / $impressions * 100, 1)) . ' %' : '—';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $slot = (string) ($_POST['slot'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!isset($registry[$slot])) {
        portail_flash('Appel à l\'action inconnu.', 'error');
    } else {
        $where = $registry[$slot]['where'];
        if ($action === 'save') {
            $res = cta_save_variants(
                $slot,
                (array) ($_POST['variant_id'] ?? []),
                (array) ($_POST['variant_label'] ?? []),
                (array) ($_POST['variant_weight'] ?? [])
            );
            portail_flash(
                $res['ok'] ? $where . ' : ' . $res['count'] . ' variante' . ($res['count'] > 1 ? 's' : '') . ' enregistrée' . ($res['count'] > 1 ? 's' : '') . '.' : $res['error'],
                $res['ok'] ? 'ok' : 'error'
            );
        } elseif ($action === 'reset') {
            $ok = cta_clear_variants($slot);
            portail_flash($ok ? $where . ' : retour au libellé d\'origine.' : 'Impossible d\'effacer les variantes.', $ok ? 'ok' : 'error');
        }
    }
    header('Location: /portail/ctas.php?jours=' . $days . '#slot-' . rawurlencode($slot));
    exit;
}

$stats = cta_stats($from, $to);
$statsMap = [];
foreach ($stats as $row) $statsMap[$row['slot'] . '|' . $row['variant']] = $row;
$variantCache = [];
foreach (array_keys($registry) as $slot) $variantCache[$slot] = cta_variants($slot);

$totImp = 0;
$totClk = 0;
foreach ($stats as $row) { $totImp += $row['impressions']; $totClk += $row['clicks']; }
$activeTests = 0;
foreach ($variantCache as $rows) {
    if (array_filter($rows, static fn (array $r): bool => (int) $r['weight'] > 0)) $activeTests++;
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cta-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Page', 'Emplacement', 'Variante', 'Poids', 'Affichages', 'Clics', 'Taux de clic (%)'], ';', '"', '');
    foreach ($stats as $row) {
        $meta = $registry[$row['slot']] ?? ['group' => '', 'where' => $row['slot']];
        [$label, $weight] = cta_portal_variant_label($row['slot'], $row['variant'], $meta, $variantCache[$row['slot']] ?? []);
        fputcsv($out, [$meta['group'], $meta['where'], $label, $weight, $row['impressions'], $row['clicks'], str_replace('.', ',', (string) $row['ctr'])], ';', '"', '');
    }
    exit;
}

/** Libellé et poids d'une variante pour les tableaux : libellé d'origine, variante courante ou historique. */
function cta_portal_variant_label(string $slot, string $variant, array $meta, array $variants): array
{
    foreach ($variants as $row) {
        if ($variant === 'v' . (int) $row['id']) return [(string) $row['label'], (string) (int) $row['weight']];
    }
    if ($variant === 'default') return [(string) $meta['label'], '—'];
    return ['Ancienne variante (' . $variant . ')', '—'];
}

/** Lignes du tableau : variantes courantes (même sans compteur) + variantes historiques encore comptées. */
function cta_portal_stats_rows(string $slot, array $meta, array $variants, array $statsMap): array
{
    $rows = [];
    foreach ($statsMap as $key => $row) {
        if ($row['slot'] !== $slot) continue;
        [$label, $weight] = cta_portal_variant_label($slot, $row['variant'], $meta, $variants);
        $rows[$row['variant']] = ['variant' => $row['variant'], 'label' => $label, 'weight' => $weight,
            'impressions' => $row['impressions'], 'clicks' => $row['clicks'], 'ctr' => $row['ctr'],
            'current' => $row['variant'] === 'default' ? !$variants : false];
    }
    foreach ($variants as $variant) {
        $key = 'v' . (int) $variant['id'];
        if (!isset($rows[$key])) $rows[$key] = ['variant' => $key, 'label' => (string) $variant['label'],
            'weight' => (string) (int) $variant['weight'], 'impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'current' => true];
        else $rows[$key]['current'] = true;
    }
    if (!$variants && !isset($rows['default'])) {
        $rows['default'] = ['variant' => 'default', 'label' => (string) $meta['label'], 'weight' => '—',
            'impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'current' => true];
    }
    uasort($rows, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions'] ?: strcmp($a['label'], $b['label']));
    return array_values($rows);
}

/** Meilleure variante courante, seulement quand deux variantes ont au moins 50 affichages. */
function cta_portal_winner(string $slot, array $variants, array $statsMap): ?array
{
    if (count($variants) < 2) return null;
    $eligible = [];
    foreach ($variants as $variant) {
        $row = $statsMap[$slot . '|v' . (int) $variant['id']] ?? null;
        if ($row && $row['impressions'] >= 50) $eligible[] = $row + ['label' => (string) $variant['label']];
    }
    if (count($eligible) < 2) return null;
    usort($eligible, static fn (array $a, array $b): int => $b['ctr'] <=> $a['ctr'] ?: $b['impressions'] <=> $a['impressions']);
    return $eligible[0]['ctr'] > 0 ? $eligible[0] : null;
}

$groups = [];
foreach ($registry as $slot => $meta) $groups[$meta['group']][$slot] = $meta;
$flash = portail_flash();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Appels à l'action — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .ctawrap { display: grid; gap: 16px; max-width: 1100px; margin: 0 auto; padding: 0 16px 56px; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; }
  .kpi { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; display: grid; gap: 2px; }
  .kpi .n { font-size: 1.9rem; font-weight: 700; line-height: 1.1; } .kpi .l { font-size: .82rem; color: var(--muted); font-weight: 600; }
  .ghead { display: flex; align-items: baseline; gap: 10px; margin: 10px 0 0; }
  .ghead h2 { margin: 0; font-size: 1.12rem; } .ghead span { color: var(--muted); font-size: .85rem; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .chead { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
  .chead h3 { margin: 0; font-size: 1rem; } .chead p { margin: 3px 0 0; }
  .chead code { font-family: var(--mono, ui-monospace, monospace); font-size: .78rem; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; padding: 1px 5px; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; } .muted { color: var(--muted); }
  .pill { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: .73rem; font-weight: 700; background: var(--accent-soft); color: var(--accent); white-space: nowrap; }
  .pill.idle { background: var(--bg); color: var(--muted); border: 1px solid var(--line); }
  .pill.good { background: #e4f3e8; color: #256b3a; }
  .periods { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
  .periods a { padding: 6px 14px; text-decoration: none; color: var(--ink); font-size: .86rem; font-weight: 600; } .periods a[aria-current="true"] { background: var(--ink); color: var(--panel); }
  .scroll { overflow-x: auto; }
  table.t { width: 100%; border-collapse: collapse; font-size: .87rem; } .t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 5px 7px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 7px; border-bottom: 1px solid var(--line); vertical-align: middle; } .t td.r, .t th.r { text-align: right; font-variant-numeric: tabular-nums; } .t tr:last-child td { border-bottom: 0; }
  .t .lab { font-weight: 600; }
  .t .w { color: var(--muted); font-size: .8rem; }
  .empty { color: var(--muted); font-size: .86rem; }
  details.edit { border-top: 1px solid var(--line); padding-top: 10px; }
  details.edit summary { cursor: pointer; font-weight: 700; font-size: .9rem; }
  .vrows { display: grid; gap: 8px; margin: 10px 0; }
  .vrow { display: grid; grid-template-columns: minmax(0, 1fr) 92px 34px; gap: 8px; align-items: center; }
  .vrow input[type="text"], .vrow input[type="number"] { padding: 8px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; min-width: 0; width: 100%; }
  .vrow input[type="number"] { text-align: right; }
  .vrow button { border: 1px solid var(--line); background: var(--bg); color: var(--muted); border-radius: 8px; width: 34px; height: 34px; cursor: pointer; font-size: 1rem; line-height: 1; }
  .vrow button:hover { color: var(--danger, #b42318); border-color: currentColor; }
  .vrow .head { font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 4px; }
  .btn-link { background: none; border: 0; color: var(--danger, #b42318); font: inherit; font-size: .84rem; cursor: pointer; padding: 0; text-decoration: underline; }
  .note { font-size: .83rem; color: var(--muted); margin: 0; }
  .whead { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
  @media (max-width: 620px) { .vrow { grid-template-columns: minmax(0, 1fr) 76px 34px; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Appels à l\'action',
    'eyebrow' => 'Boutiques',
    'desc' => 'Tous les boutons du site public : changez leur libellé, testez des alternatives et lisez les affichages et les clics.',
    'actions' => '<a class="btn" href="/" target="_blank" rel="noopener">Voir le site</a><a class="btn" href="/portail/activite.php">Activité</a>',
]); ?>

  <div class="ctawrap">
    <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

    <div class="whead">
      <span class="periods" role="group" aria-label="Période"><?php foreach ([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '12 mois'] as $d => $l): ?><a href="?jours=<?= $d ?>"<?= $days === $d ? ' aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></span>
      <a class="btn" href="?jours=<?= $days ?>&amp;export=csv">Exporter en CSV</a>
    </div>

    <section class="kpis" aria-label="Chiffres clés">
      <div class="kpi"><span class="l">Affichages</span><span class="n"><?= (int) $totImp ?></span></div>
      <div class="kpi"><span class="l">Clics</span><span class="n"><?= (int) $totClk ?></span></div>
      <div class="kpi"><span class="l">Taux de clic</span><span class="n"><?= e($ctr($totImp, $totClk)) ?></span></div>
      <div class="kpi"><span class="l">Tests en cours</span><span class="n"><?= (int) $activeTests ?></span></div>
    </section>

    <p class="note">Un affichage est compté une fois par visite et par variante, quand le bouton est visible à moitié pendant une seconde ; un clic est compté une fois par visite. Les robots sont exclus. Chaque page publique porte le suivi (<code>/assets/cta-track.js</code>) ; une variante de poids 0 reste dans l'historique mais n'est plus affichée.</p>

    <?php foreach ($groups as $group => $slots): ?>
      <div class="ghead"><h2><?= e($group) ?></h2><span><?= count($slots) ?> appel<?= count($slots) > 1 ? 's' : '' ?> à l'action</span></div>
      <?php foreach ($slots as $slot => $meta):
          $variants = $variantCache[$slot];
          $active = array_values(array_filter($variants, static fn (array $r): bool => (int) $r['weight'] > 0));
          $rows = cta_portal_stats_rows($slot, $meta, $variants, $statsMap);
          $winner = cta_portal_winner($slot, $active, $statsMap);
          $showDefaultRow = !empty($meta['dynamic']) && !$variants; ?>
        <section class="ocard" id="slot-<?= e($slot) ?>" aria-label="<?= e($meta['where']) ?>">
          <div class="chead">
            <div>
              <h3><?= e($meta['where']) ?> <code><?= e($slot) ?></code></h3>
              <p class="hint">Libellé d'origine : « <b><?= e($meta['label']) ?></b> »<?php if (!empty($meta['default_note'])): ?> — <?= e($meta['default_note']) ?><?php endif; ?> · <a href="<?= e($meta['page']) ?>" target="_blank" rel="noopener">Voir la page ↗</a> · destination <code><?= e($meta['url']) ?></code></p>
            </div>
            <span class="pill<?= $active ? '' : ' idle' ?>"><?= $active ? 'Test en cours · ' . count($active) . ' variante' . (count($active) > 1 ? 's' : '') : 'Libellé d\'origine' ?></span>
          </div>

          <?php if ($winner): ?>
            <p class="pill good" style="justify-self:start">Meilleur taux : « <?= e($winner['label']) ?> » — <?= e($ctr($winner['impressions'], $winner['clicks'])) ?> sur <?= (int) $winner['impressions'] ?> affichages</p>
          <?php endif; ?>

          <div class="scroll">
            <table class="t">
              <thead><tr><th>Variante</th><th class="r">Poids</th><th class="r">Affichages</th><th class="r">Clics</th><th class="r">Taux</th></tr></thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><span class="lab"><?= e($row['label']) ?></span><?= $row['current'] ? '' : ' <span class="w">(historique)</span>' ?><?= $showDefaultRow && $row['variant'] === 'default' ? ' <span class="w">(dynamique)</span>' : '' ?></td>
                    <td class="r"><?= e($row['weight']) ?></td>
                    <td class="r"><?= (int) $row['impressions'] ?></td>
                    <td class="r"><?= (int) $row['clicks'] ?></td>
                    <td class="r"><?= e($row['impressions'] > 0 || $row['clicks'] > 0 ? $ctr($row['impressions'], $row['clicks']) : '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <details class="edit">
            <summary>Modifier les libellés et tester des alternatives</summary>
            <form method="post" autocomplete="off" style="margin-top:10px">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="slot" value="<?= e($slot) ?>">
              <div class="vrows">
                <div class="vrow head"><span>Libellé</span><span style="text-align:right">Poids</span><span></span></div>
                <?php
                $editRows = $variants;
                if (!$editRows) {
                    $editRows = [
                        ['id' => 0, 'label' => (string) $meta['label'], 'weight' => 50],
                        ['id' => 0, 'label' => '', 'weight' => 50],
                    ];
                } elseif (count($editRows) < CTA_MAX_VARIANTS) {
                    $editRows[] = ['id' => 0, 'label' => '', 'weight' => 50];
                }
                foreach ($editRows as $editRow): ?>
                  <div class="vrow">
                    <input type="hidden" name="variant_id[]" value="<?= (int) $editRow['id'] ?>">
                    <input type="text" name="variant_label[]" maxlength="<?= CTA_MAX_LABEL ?>" value="<?= e($editRow['label']) ?>" placeholder="<?= $variants ? 'Nouvelle variante' : 'Libellé de la variante' ?>" aria-label="Libellé de la variante">
                    <input type="number" name="variant_weight[]" min="0" max="<?= CTA_MAX_WEIGHT ?>" step="1" value="<?= (int) $editRow['weight'] ?>" aria-label="Poids relatif (0 = en pause)">
                    <button type="button" data-remove-variant aria-label="Retirer cette variante" title="Retirer">×</button>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="hint">Le poids est relatif : 50 / 50 partage à parts égales ; 80 / 20 envoie 80 % des visiteurs sur la première. Poids 0 : la variante reste dans l'historique mais n'est plus affichée. Une ligne au libellé vide est supprimée à l'enregistrement.</p>
              <div class="actions">
                <button type="submit" class="btn btn-primary">Enregistrer</button>
                <button type="button" class="btn" data-add-variant>+ Ajouter une variante</button>
              </div>
            </form>
            <?php if ($variants): ?>
              <form method="post" style="margin-top:8px" onsubmit="return confirm('Revenir au libellé d\'origine ? Les compteurs restent dans l\'historique.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="slot" value="<?= e($slot) ?>">
                <button type="submit" class="btn-link">Revenir au libellé d'origine</button>
              </form>
            <?php endif; ?>
          </details>
        </section>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>
<template id="vrow-template">
  <div class="vrow">
    <input type="hidden" name="variant_id[]" value="0">
    <input type="text" name="variant_label[]" maxlength="<?= CTA_MAX_LABEL ?>" placeholder="Nouvelle variante" aria-label="Libellé de la variante">
    <input type="number" name="variant_weight[]" min="0" max="<?= CTA_MAX_WEIGHT ?>" step="1" value="50" aria-label="Poids relatif (0 = en pause)">
    <button type="button" data-remove-variant aria-label="Retirer cette variante" title="Retirer">×</button>
  </div>
</template>
<script>
document.addEventListener('click', function (event) {
  var add = event.target.closest('[data-add-variant]');
  if (add) {
    var form = add.closest('form'), zone = form && form.querySelector('.vrows'), tpl = document.getElementById('vrow-template');
    if (zone && tpl && zone.querySelectorAll('input[name="variant_label[]"]').length < <?= CTA_MAX_VARIANTS ?>) { var row = tpl.content.firstElementChild.cloneNode(true); zone.appendChild(row); var input = row.querySelector('input[type="text"]'); if (input) input.focus(); }
    return;
  }
  var remove = event.target.closest('[data-remove-variant]');
  if (remove) {
    var row = remove.closest('.vrow'), zone = row && row.parentNode;
    if (zone && zone.querySelectorAll('.vrow').length > 2) row.remove();
  }
});
</script>
</body>
</html>
