<?php
// Impact CO₂ & eau de toute la plateforme (réservé à l'exploitant) : somme des bilans de chaque boutique de ce serveur, par canal
// (places de marché, site, points de vente) et par boutique. Calcul et hypothèses : includes/impact.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/functions.php';
require_once PORTAIL_ROOT . '/includes/impact.php';

if (defined('GA_LIMITED')) { header('Location: /portail/galerie-tableau.php'); exit; }

$periods = [0 => 'Depuis le début', 365 => '12 mois', 90 => '90 jours', 30 => '30 jours'];
$days = isset($periods[(int) ($_GET['p'] ?? 0)]) ? (int) $_GET['p'] : 0;
$bilan = impact_report_all($days);
$all = $bilan['all'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Impact CO₂ &amp; eau — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 1040px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; }
  .gcard h2 { margin: 0; font-size: 1.05rem; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
  .kpi { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; }
  .kpi .k { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; opacity: .75; }
  .kpi .v { font-size: 1.5rem; font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; }
  .kpi .s { font-size: .8rem; opacity: .75; margin-top: 4px; }
  .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
  table.t { width: 100%; border-collapse: collapse; font-size: .9rem; } table.t th, table.t td { text-align: left; padding: 8px; border-bottom: 1px solid var(--line); }
  table.t th { font-size: .75rem; opacity: .75; font-weight: 600; } table.t .num { text-align: right; font-variant-numeric: tabular-nums; }
  .periods { display: flex; flex-wrap: wrap; gap: 8px; }
  .periods a { padding: 4px 12px; border: 1px solid var(--line); border-radius: 999px; font-size: .82rem; text-decoration: none; color: inherit; }
  .periods a[aria-current="page"] { border-color: var(--accent); color: var(--accent); font-weight: 700; }
  .note { font-size: .82rem; line-height: 1.5; opacity: .8; margin: 0; }
  @media (max-width: 700px) { .gcard { overflow-x: auto; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Impact CO₂ & eau',
    'eyebrow' => 'Revenus',
    'desc' => 'CO₂ et eau évités par le réemploi sur toute la plateforme : somme des boutiques, par place de marché, site et point de vente.',
]); ?>
  <div class="gwrap">
    <nav class="periods" aria-label="Période">
      <?php foreach ($periods as $d => $label): ?>
        <a href="/portail/impact.php?p=<?= (int) $d ?>"<?= $days === $d ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="kpis">
      <div class="kpi"><div class="k">CO₂ évité</div><div class="v"><?= e(impact_fmt_co2($all['total']['co2'])) ?></div><div class="s">≈ <?= e(number_format($all['km'], 0, ',', ' ')) ?> km en voiture</div></div>
      <div class="kpi"><div class="k">Eau économisée</div><div class="v"><?= e(impact_fmt_water($all['total']['water'])) ?></div><div class="s">≈ <?= e(number_format($all['showers'], 0, ',', ' ')) ?> douches</div></div>
      <div class="kpi"><div class="k">Pièces remises en circulation</div><div class="v"><?= (int) $all['total']['pieces'] ?></div><div class="s"><?= count(array_filter($bilan['shops'], static fn (array $s): bool => isset($s['report']))) ?> boutique(s) lue(s)</div></div>
      <div class="kpi"><div class="k">Coût numérique (IA)</div><div class="v"><?= e(impact_fmt_co2($all['digital']['co2'])) ?></div><div class="s"><?= (int) $all['digital']['calls'] ?> appel(s) · <?= e(impact_fmt_water($all['digital']['water'])) ?> d’eau</div></div>
    </div>

    <div class="grid2">
      <?php foreach ($all['groups'] as $g): ?>
        <section class="gcard">
          <h2><?= e($g['label']) ?> · <?= e(impact_fmt_co2($g['total']['co2'])) ?></h2>
          <table class="t">
            <thead><tr><th>Canal</th><th class="num">Pièces</th><th class="num">CO₂ évité</th><th class="num">Eau</th></tr></thead>
            <tbody>
              <?php foreach ($g['rows'] as $channel => $row): ?>
                <tr><td><?= e(impact_channel_label((string) $channel)) ?></td><td class="num"><?= (int) $row['pieces'] ?></td><td class="num"><?= e(impact_fmt_co2($row['co2'])) ?></td><td class="num"><?= e(impact_fmt_water($row['water'])) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php endforeach; ?>
    </div>

    <section class="gcard">
      <h2>Par boutique</h2>
      <table class="t">
        <thead><tr><th>Boutique</th><th class="num">Pièces</th><th class="num">CO₂ évité</th><th class="num">Eau</th></tr></thead>
        <tbody>
          <?php foreach ($bilan['shops'] as $slug => $shop): ?>
            <tr>
              <td><strong><?= e($shop['name']) ?></strong><br><small class="meta"><?= e($slug) ?></small></td>
              <?php if (isset($shop['report'])): $t = $shop['report']['total']; ?>
                <td class="num"><?= (int) $t['pieces'] ?></td><td class="num"><?= e(impact_fmt_co2($t['co2'])) ?></td><td class="num"><?= e(impact_fmt_water($t['water'])) ?></td>
              <?php else: ?>
                <td colspan="3"><span class="meta"><?= e($shop['error']) ?></span></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <?php if ($all['families']): ?>
      <section class="gcard">
        <h2>Par famille d’objets</h2>
        <table class="t">
          <thead><tr><th>Famille</th><th class="num">Pièces</th><th class="num">CO₂ évité</th><th class="num">Eau</th></tr></thead>
          <tbody>
            <?php foreach ($all['families'] as $family => $row): ?>
              <tr><td><?= e(impact_profiles()[$family]['label'] ?? $family) ?></td><td class="num"><?= (int) $row['pieces'] ?></td><td class="num"><?= e(impact_fmt_co2($row['co2'])) ?></td><td class="num"><?= e(impact_fmt_water($row['water'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <p class="note">Bilan net : <strong><?= e(impact_fmt_co2($all['net']['co2'])) ?></strong> de CO₂ et <strong><?= e(impact_fmt_water($all['net']['water'])) ?></strong> d’eau, une fois retiré l’usage de l’IA.
      <strong>Ordres de grandeur, pas une mesure</strong> : chaque pièce vendue d’occasion est supposée éviter 90 % de l’impact d’une pièce neuve, estimée d’après son poids et sa famille d’objets. L’hébergement, les trajets des clients et l’emballage ne sont pas comptés. Seules les boutiques de ce serveur sont lues.</p>
  </div>
</div>
</body>
</html>
