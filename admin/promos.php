<?php
// Mises en avant : les articles en promo, leur visuel (photo + encart CTA) et les compteurs d'affichages et de clics par emplacement
// (page d'accueil de la boutique, galeries commerciales). Voir includes/promo.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$days = in_array((int) ($_GET['jours'] ?? 30), [7, 30, 90, 365], true) ? (int) ($_GET['jours'] ?? 30) : 30;
$from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
$to = gmdate('Y-m-d');
$shop = tenant_slug();
$rows = promo_stats($from, $to, 'ref', ['shop' => $shop]);

// Compteurs par article : total, accueil, galeries (une ligne par galerie).
$byRef = [];
foreach ($rows as $r) {
    $b = &$byRef[$r['ref']];
    $b ??= ['impressions' => 0, 'clicks' => 0, 'places' => []];
    $b['impressions'] += $r['impressions'];
    $b['clicks'] += $r['clicks'];
    $b['places'][$r['placement']] = ['impressions' => $r['impressions'], 'clicks' => $r['clicks'], 'ctr' => $r['ctr']];
    unset($b);
}
$products = db()->query("SELECT * FROM products WHERE badge = 'Promo' ORDER BY sort_order, ref")->fetchAll();
$totImp = array_sum(array_column($byRef, 'impressions'));
$totClk = array_sum(array_column($byRef, 'clicks'));
$placeLabel = static fn (string $p): string => $p === 'accueil' ? 'Page d\'accueil' : (str_starts_with($p, 'galerie:') ? 'Galerie « ' . substr($p, 8) . ' »' : $p);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mises-en-avant-' . $shop . '-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Article', 'Emplacement', 'Affichages', 'Clics', 'Taux de clic (%)'], ';', '"', '');
    foreach ($rows as $r) fputcsv($out, [$r['ref'], $placeLabel($r['placement']), $r['impressions'], $r['clicks'], str_replace('.', ',', (string) $r['ctr'])], ';', '"', '');
    exit;
}
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mises en avant — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .pm-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 16px 0; }
  .pm-kpi { background: var(--surface); border: 1px solid var(--line); padding: 14px 16px; }
  .pm-kpi b { display: block; font-size: 1.8rem; line-height: 1.1; }
  .pm-kpi span { font-size: .8rem; color: var(--ink-soft); }
  .pm-periods { display: inline-flex; border: 1px solid var(--line); }
  .pm-periods a { padding: 6px 14px; text-decoration: none; color: var(--ink); font-size: .86rem; font-weight: 600; }
  .pm-periods a[aria-current="true"] { background: var(--ink); color: var(--bg); }
  table.pm { width: 100%; border-collapse: collapse; font-size: .9rem; margin-top: 12px; }
  .pm th, .pm td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
  .pm th { font-size: .74rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); }
  .pm td.r, .pm th.r { text-align: right; font-variant-numeric: tabular-nums; }
  .pm img { width: 90px; height: 60px; object-fit: cover; display: block; border: 1px solid var(--line); }
  .pm .tag { display: inline-block; padding: 1px 8px; border: 1px solid var(--line); font-size: .74rem; font-weight: 700; }
  .pm .tag.ok { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); }
  .pm .tag.warn { color: #b3261e; border-color: #b3261e; }
  .pm small { color: var(--ink-soft); display: block; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'promos'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Promotions</p>
    <h1 style="font-size:1.8rem;margin:12px 0 8px;">Mises en avant</h1>
    <p class="hint">Chaque article en promo peut avoir un <strong>visuel promo</strong> (sa photo avec un encart : remise, prix, bouton). Il s'affiche sur la <strong>page d'accueil</strong> de la boutique et dans les <strong>promos des galeries commerciales</strong> dont la boutique fait partie. Les <strong>affichages</strong> (le visuel est réellement vu) et les <strong>clics</strong> sont comptés par emplacement : ce sont les compteurs de la mise en avant. Le visuel se crée depuis la fiche de l'article (catalogue, mention « Promo »).</p>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></p><?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px;">
      <span class="pm-periods" role="group" aria-label="Période">
        <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'] as $d => $l): ?><a href="?jours=<?= $d ?>"<?= $days === $d ? ' aria-current="true"' : '' ?>><?= h($l) ?></a><?php endforeach; ?>
      </span>
      <a class="btn-small" href="?jours=<?= $days ?>&amp;export=csv" style="text-decoration:none;">Exporter en CSV</a>
    </div>

    <div class="pm-kpis">
      <div class="pm-kpi"><b><?= (int) $totImp ?></b><span>affichages de visuels promo</span></div>
      <div class="pm-kpi"><b><?= (int) $totClk ?></b><span>clics vers les fiches</span></div>
      <div class="pm-kpi"><b><?= $totImp > 0 ? str_replace('.', ',', (string) round($totClk / $totImp * 100, 1)) . ' %' : '—' ?></b><span>taux de clic</span></div>
    </div>

    <?php if (!$products): ?>
      <p class="hint">Aucun article n'est en promo. Dans le catalogue, donnez la mention « Promo » à une pièce (bouton « Mettre en promo »), puis créez son visuel.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="pm">
      <thead><tr><th></th><th>Article</th><th>Visuel</th><th class="r">Affichages</th><th class="r">Clics</th><th class="r">Taux</th><th>Détail par emplacement</th></tr></thead>
      <tbody>
        <?php foreach ($products as $p):
          $v = promo_visual_get($p['ref']);
          $fresh = promo_visual_fresh($p, $v);
          $c = $byRef[$p['ref']] ?? ['impressions' => 0, 'clicks' => 0, 'places' => []];
        ?>
          <tr>
            <td><?= $v ? '<img src="/' . h($v['path']) . '" alt="">' : '' ?></td>
            <td><strong><?= h($p['name']) ?></strong><small>Réf. N°<?= h($p['ref']) ?> · <?= h(has_promo_price($p) ? $p['promo_price'] . ' au lieu de ' . $p['price'] : 'promo sans prix promo') ?><?= $p['is_hidden'] || $p['stock'] <= 0 ? ' · hors boutique' : '' ?></small></td>
            <td><?php if ($fresh): ?><span class="tag ok">À jour</span><?php elseif ($v): ?><span class="tag warn">Périmé</span><small>le prix a changé</small><?php else: ?><span class="tag">Aucun</span><?php endif; ?>
              <small><a href="/admin/catalog.php#produit-<?= h($p['ref']) ?>"><?= $v ? 'Refaire' : 'Créer le visuel' ?></a></small></td>
            <td class="r"><?= (int) $c['impressions'] ?></td>
            <td class="r"><?= (int) $c['clicks'] ?></td>
            <td class="r"><?= $c['impressions'] > 0 ? str_replace('.', ',', (string) round($c['clicks'] / $c['impressions'] * 100, 1)) . ' %' : '—' ?></td>
            <td><?php foreach ($c['places'] as $place => $pl): ?><small><?= h($placeLabel($place)) ?> : <?= (int) $pl['impressions'] ?> aff. · <?= (int) $pl['clicks'] ?> clic<?= $pl['clicks'] > 1 ? 's' : '' ?></small><?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="hint" style="margin-top:14px;">Un affichage est compté une fois par visite, quand au moins la moitié du visuel est visible pendant une seconde ; les robots sont exclus. Un clic est compté quand le visiteur ouvre la fiche depuis le visuel.</p>
  </div>
</section>
</main>
<script src="/assets/lightbox.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/lightbox.js') ?>" defer></script>
</body>
</html>
