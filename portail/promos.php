<?php
// Mises en avant : compteurs d'affichages et de clics des visuels promo, par galerie commerciale, par boutique et par article — la base de la facturation
// de ces mises en avant. Les compteurs sont ceux de ce déploiement (data/saas/promo.db, voir includes/promo.php). Export CSV.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/galleries.php';
require_once PORTAIL_ROOT . '/includes/promo.php';

$days = in_array((int) ($_GET['jours'] ?? 30), [7, 30, 90, 365], true) ? (int) ($_GET['jours'] ?? 30) : 30;
$from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
$to = gmdate('Y-m-d');
$galleries = [];
foreach (gallery_list() as $g) $galleries[$g['slug']] = $g;

$rows = promo_stats($from, $to, 'ref');   // shop, ref, placement
$place = static fn (string $p): string => $p === 'accueil' ? 'Page d\'accueil des boutiques' : (str_starts_with($p, 'galerie:') ? 'Galerie « ' . ($galleries[substr($p, 8)]['name'] ?? substr($p, 8)) . ' »' : $p);
$ctr = static fn (int $i, int $c): string => $i > 0 ? str_replace('.', ',', (string) round($c / $i * 100, 1)) . ' %' : '—';

$byPlacement = $byShop = [];
foreach ($rows as $r) {
    foreach ([[&$byPlacement, $r['placement']], [&$byShop, $r['shop']]] as [&$bucket, $key]) {
        $bucket[$key]['impressions'] = ($bucket[$key]['impressions'] ?? 0) + $r['impressions'];
        $bucket[$key]['clicks'] = ($bucket[$key]['clicks'] ?? 0) + $r['clicks'];
    }
    unset($bucket);
}
uasort($byPlacement, static fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
uasort($byShop, static fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
$totImp = array_sum(array_column($byPlacement, 'impressions'));
$totClk = array_sum(array_column($byPlacement, 'clicks'));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mises-en-avant-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Boutique', 'Article', 'Emplacement', 'Affichages', 'Clics', 'Taux de clic (%)'], ';', '"', '');
    foreach ($rows as $r) fputcsv($out, [$r['shop'], $r['ref'], $place($r['placement']), $r['impressions'], $r['clicks'], str_replace('.', ',', (string) $r['ctr'])], ';', '"', '');
    exit;
}
$flash = null;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Mises en avant — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .owrap { display: grid; gap: 16px; max-width: 1100px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.02rem; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
  .kpi { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; display: grid; gap: 2px; }
  .kpi .n { font-size: 1.9rem; font-weight: 700; line-height: 1.1; } .kpi .l { font-size: .82rem; color: var(--muted); font-weight: 600; }
  .periods { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
  .periods a { padding: 6px 14px; text-decoration: none; color: var(--ink); font-size: .86rem; font-weight: 600; } .periods a[aria-current="true"] { background: var(--ink); color: var(--panel); }
  table.t { width: 100%; border-collapse: collapse; font-size: .88rem; } .t th { text-align: left; font-size: .74rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 6px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 8px; border-bottom: 1px solid var(--line); } .t td.r, .t th.r { text-align: right; font-variant-numeric: tabular-nums; } .t tr:last-child td { border-bottom: 0; }
  .scroll { overflow-x: auto; } .muted { color: var(--muted); }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Mises en avant</h1><p>Affichages et clics des visuels promo, par galerie et par boutique : la base de leur facturation</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="/portail/">Portail des commerces</a><a class="btn" href="/portail/galeries.php">Galeries commerciales</a><a class="btn" href="/portail/activite.php">Activité</a></span>
  </header>

  <div class="owrap">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <span class="periods" role="group" aria-label="Période"><?php foreach ([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '12 mois'] as $d => $l): ?><a href="?jours=<?= $d ?>"<?= $days === $d ? ' aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></span>
      <a class="btn" href="?jours=<?= $days ?>&amp;export=csv">Exporter en CSV</a>
    </div>

    <section class="kpis" aria-label="Chiffres clés">
      <div class="kpi"><span class="l">Affichages</span><span class="n"><?= (int) $totImp ?></span></div>
      <div class="kpi"><span class="l">Clics vers les fiches</span><span class="n"><?= (int) $totClk ?></span></div>
      <div class="kpi"><span class="l">Taux de clic</span><span class="n"><?= e($ctr($totImp, $totClk)) ?></span></div>
    </section>

    <section class="ocard"><h2>Par emplacement <small class="muted">— galeries commerciales et pages d'accueil</small></h2>
      <div class="scroll"><table class="t"><thead><tr><th>Emplacement</th><th class="r">Affichages</th><th class="r">Clics</th><th class="r">Taux</th></tr></thead><tbody>
        <?php foreach ($byPlacement as $p => $c): ?><tr><td><?= e($place($p)) ?></td><td class="r"><?= (int) $c['impressions'] ?></td><td class="r"><?= (int) $c['clicks'] ?></td><td class="r"><?= e($ctr($c['impressions'], $c['clicks'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$byPlacement): ?><tr><td colspan="4" class="muted">Aucun affichage compté sur la période : les boutiques doivent créer des visuels promo (fiche de l'article en promo).</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section class="ocard"><h2>Par boutique</h2>
      <div class="scroll"><table class="t"><thead><tr><th>Boutique</th><th class="r">Affichages</th><th class="r">Clics</th><th class="r">Taux</th></tr></thead><tbody>
        <?php foreach ($byShop as $s => $c): ?><tr><td><?= e($s) ?></td><td class="r"><?= (int) $c['impressions'] ?></td><td class="r"><?= (int) $c['clicks'] ?></td><td class="r"><?= e($ctr($c['impressions'], $c['clicks'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>

    <section class="ocard"><h2>Détail par article et emplacement</h2>
      <div class="scroll"><table class="t"><thead><tr><th>Boutique</th><th>Article</th><th>Emplacement</th><th class="r">Affichages</th><th class="r">Clics</th><th class="r">Taux</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><?= e($r['shop']) ?></td><td>N°<?= e($r['ref']) ?></td><td><?= e($place($r['placement'])) ?></td><td class="r"><?= (int) $r['impressions'] ?></td><td class="r"><?= (int) $r['clicks'] ?></td><td class="r"><?= e($ctr($r['impressions'], $r['clicks'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
    <p class="muted" style="font-size:.85rem;">Un affichage est compté une fois par visite quand au moins la moitié du visuel est visible pendant une seconde ; les robots et les clics sans jeton valide sont exclus. Ces compteurs sont ceux de ce déploiement : une boutique hébergée ailleurs (flux catalogue distant) compte ses propres affichages sur son serveur.</p>
  </div>
</div>
</body>
</html>
