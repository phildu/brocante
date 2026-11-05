<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/charts.php';
require_admin();
analytics_ensure_schema();
analytics_seed_costs();

// Rafraîchissement discret du compteur « en ligne maintenant ».
if (isset($_GET['live'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['online' => dash_online_now()]);
    exit;
}

/* ---------------------------------------------------------------- période */

$MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$MONTHS_SHORT = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
$now = dash_now();
$view = ($_GET['view'] ?? 'month') === 'year' ? 'year' : 'month';
$y = (int) ($_GET['y'] ?? $now->format('Y'));
$m = (int) ($_GET['m'] ?? $now->format('n'));
if ($y < 2000 || $y > (int) $now->format('Y')) $y = (int) $now->format('Y');
if ($m < 1 || $m > 12) $m = (int) $now->format('n');

if ($view === 'year') {
    $start = new DateTimeImmutable("$y-01-01 00:00:00", dash_tz());
    $end = $start->modify('+1 year');
    $prevStart = $start->modify('-1 year');
    $nextStart = $end;
    $periodLabel = (string) $y;
    $prevLabel = 'l\'année précédente';
    $periodMonths = 12;
} else {
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $m), dash_tz());
    $end = $start->modify('+1 month');
    $prevStart = $start->modify('-1 month');
    $nextStart = $end;
    $periodLabel = $MONTHS[$m] . ' ' . $y;
    $prevLabel = 'le mois précédent';
    $periodMonths = 1;
}
$prevEnd = $start;
$isCurrent = $now >= $start && $now < $end;
$hasNext = $nextStart <= $now;
$qs = fn(DateTimeImmutable $d, string $v) => 'view=' . $v . '&y=' . $d->format('Y') . '&m=' . $d->format('n');
$selfUrl = '/admin/dashboard.php?' . $qs($start, $view);
$launch = dash_launch_date();

/* ---------------------------------------------------------------- données */

$eco = dash_economics($start, $end, ['actif']);
$sim = dash_economics($start, $end, ['actif', 'test']);
$prev = dash_economics($prevStart, $prevEnd, ['actif']);
// Période en cours : on compare aux MÊMES jours de la période précédente, pas à un mois entier.
$prevSame = $isCurrent ? dash_economics($prevStart, $prevStart->modify('+' . ($now->getTimestamp() - $start->getTimestamp()) . ' seconds'), ['actif']) : $prev;
$be = dash_breakeven($end, ['actif']);
$beSim = dash_breakeven($end, ['actif', 'test']);
$traffic = dash_traffic($start, $end);
$catalog = dash_catalog();
$online = $eco['online'];
$phys = $eco['physical'];

$eur = fn($c) => format_cents((int) round($c));
$pct = fn($v, $d = 0) => number_format($v * 100, $d, ',', ' ') . ' %';
$num = fn($v, $d = 0) => number_format((float) $v, $d, ',', ' ');
$sameLabel = $view === 'year' ? "les mêmes jours de l'an dernier" : 'les mêmes jours du mois dernier';
$delta = function ($cur, $old, ?string $label = null) use ($prevLabel) {
    $prevLabel = $label ?? $prevLabel;
    if ($old == 0 && $cur == 0) return '';
    if ($old == 0) return '<span class="dash-delta">nouveau vs ' . h($prevLabel) . '</span>';
    $d = ($cur - $old) / abs($old);
    $cls = $d > 0.0005 ? 'up' : ($d < -0.0005 ? 'down' : '');
    return '<span class="dash-delta ' . $cls . '">' . ($d > 0 ? '▲ +' : ($d < 0 ? '▼ ' : '')) . number_format($d * 100, 0, ',', ' ') . ' % vs ' . h($prevLabel) . '</span>';
};

// Frais ponctuels de la période (hors seuil mensuel récurrent).
$onceCosts = 0.0;
foreach ($eco['lines'] as $l) if ($l['type'] === 'fixed' && $l['detail'] === 'ponctuel') $onceCosts += $l['cents'];
$onceCostsSim = 0.0;
foreach ($sim['lines'] as $l) if ($l['type'] === 'fixed' && $l['detail'] === 'ponctuel') $onceCostsSim += $l['cents'];

// Seuil de rentabilité sur la période affichée.
$beTarget = $be['be_revenue'] !== null ? ($be['be_revenue'] * $periodMonths) + ($be['rate'] > 0 ? $onceCosts / $be['rate'] : 0) : null;
$beTargetSim = $beSim['be_revenue'] !== null ? ($beSim['be_revenue'] * $periodMonths) + ($beSim['rate'] > 0 ? $onceCostsSim / $beSim['rate'] : 0) : null;
$coverage = $beTarget ? $eco['revenue'] / $beTarget : null;

// Projection fin de période.
$elapsed = $isCurrent ? ($now->getTimestamp() - $start->getTimestamp()) / ($end->getTimestamp() - $start->getTimestamp()) : 1;
$projection = ($isCurrent && $elapsed >= 0.15 && $eco['revenue'] > 0) ? $eco['revenue'] / $elapsed : null;

// Objectif mensuel de chiffre d'affaires.
$goalCents = (int) (analytics_setting('monthly_goal_cents') ?: 0);
$goalPeriod = $goalCents * $periodMonths;

// Cumul depuis le lancement : amortissement.
$cumEnd = $now->modify('+1 day')->setTime(0, 0);
$cum = dash_economics($launch, $cumEnd, ['actif']);
$cumSim = dash_economics($launch, $cumEnd, ['actif', 'test']);
$last3Start = max($launch, $now->modify('first day of this month')->setTime(0, 0)->modify('-3 months'));
$last3End = $now->modify('first day of this month')->setTime(0, 0);
$last3Months = max(1, (int) round(($last3End->getTimestamp() - $last3Start->getTimestamp()) / (30.4 * 86400)));
$last3 = $last3End > $last3Start ? dash_economics($last3Start, $last3End, ['actif']) : null;
$monthlyResult = $last3 ? $last3['result'] / $last3Months : null;
$remaining = max(0, -$cum['result']);
$paybackMonths = ($remaining > 0 && $monthlyResult !== null && $monthlyResult > 0) ? ceil($remaining / $monthlyResult) : null;

/* ---------------------------------------------------------------- séries */

$chartA = $chartB = '';
$legendA = $legendB = [];
$visLabels = $visOnline = $visPhys = [];
$colRev = 'var(--accent)'; $colPhys = 'var(--sage)'; $colCost = 'var(--ink-soft)'; $colRes = 'var(--ink)'; $colSim = '#c28a1e';
$crossDay = null;

if ($view === 'year') {
    $labels = $revM = $costM = $resM = $resSimM = $cumRes = $cumResSim = [];
    $run = $runSim = 0.0;
    $phByMonth = [];
    foreach ($phys['rows'] as $r) { $k = substr($r['day'], 0, 7); $phByMonth[$k] = ($phByMonth[$k] ?? 0) + (int) $r['visitors']; }
    for ($mm = 1; $mm <= 12; $mm++) {
        $ms = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $mm), dash_tz());
        $labels[] = $MONTHS_SHORT[$mm];
        if ($ms > $now) {
            $revM[] = $costM[] = 0; $resM[] = $resSimM[] = null; $cumRes[] = null; $cumResSim[] = null;
            $visOnline[] = 0; $visPhys[] = 0;
            continue;
        }
        $e1 = dash_economics($ms, $ms->modify('+1 month'), ['actif']);
        $e2 = dash_economics($ms, $ms->modify('+1 month'), ['actif', 'test']);
        $revM[] = $e1['revenue']; $costM[] = $e1['costs'] + $e1['cogs']; $resM[] = $e1['result']; $resSimM[] = $e2['result'];
        $run += $e1['result']; $runSim += $e2['result'];
        $cumRes[] = $run; $cumResSim[] = $runSim;
        $key = sprintf('%04d-%02d', $y, $mm);
        $visOnline[] = (int) ($traffic['by_month'][$key] ?? 0);
        $visPhys[] = (int) ($phByMonth[$key] ?? 0);
    }
    $visLabels = $labels;
    $seriesA = [
        ['name' => "Chiffre d'affaires", 'type' => 'bar', 'values' => $revM, 'color' => $colRev],
        ['name' => 'Achats + frais', 'type' => 'bar', 'values' => $costM, 'color' => $colCost],
        ['name' => 'Résultat net', 'type' => 'line', 'values' => $resM, 'color' => $colRes],
    ];
    $chartA = dash_chart($labels, $seriesA, [], null, "Chiffre d'affaires, charges et résultat par mois en $y");
    $legendA = $seriesA;
    $seriesB = [
        ['name' => 'Résultat cumulé (réel)', 'type' => 'line', 'values' => $cumRes, 'color' => $colRes],
        ['name' => 'Résultat cumulé (options payantes)', 'type' => 'line', 'values' => $cumResSim, 'color' => $colSim, 'dashed' => true],
    ];
    $chartB = dash_chart($labels, $seriesB, [], null, "Résultat cumulé depuis janvier $y");
    $legendB = $seriesB;
} else {
    $days = (int) $start->format('t');
    $labels = $revOn = $revPh = $cumRev = $visOnline = $visPhys = [];
    $dayRev = array_fill(1, $days, ['on' => 0, 'ph' => 0]);
    foreach (dash_paid_orders() as $o) {
        if ($o['local'] >= $start && $o['local'] < $end) $dayRev[(int) $o['local']->format('j')]['on'] += (int) $o['amount_total'];
    }
    $phByDay = [];
    foreach ($phys['rows'] as $r) { $d = (int) substr($r['day'], 8, 2); $dayRev[$d]['ph'] += (int) $r['sales_cents']; $phByDay[$d] = (int) $r['visitors']; }
    $run = 0.0;
    for ($d = 1; $d <= $days; $d++) {
        $labels[] = (string) $d;
        $future = $start->modify('+' . ($d - 1) . ' days') > $now;
        $revOn[] = $dayRev[$d]['on']; $revPh[] = $dayRev[$d]['ph'];
        $run += $dayRev[$d]['on'] + $dayRev[$d]['ph'];
        $cumRev[] = $future ? null : $run;
        if ($crossDay === null && $beTarget && $run >= $beTarget) $crossDay = $d;
        $key = $start->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
        $visOnline[] = (int) ($traffic['by_day'][$key] ?? 0);
        $visPhys[] = (int) ($phByDay[$d] ?? 0);
    }
    $visLabels = $labels;
    $seriesA = [
        ['name' => 'Ventes en ligne', 'type' => 'bar', 'values' => $revOn, 'color' => $colRev, 'stack' => 'ca'],
        ['name' => 'Ventes en boutique', 'type' => 'bar', 'values' => $revPh, 'color' => $colPhys, 'stack' => 'ca'],
    ];
    $chartA = dash_chart($labels, $seriesA, [], null, "Chiffre d'affaires par jour en $periodLabel", 2);
    $legendA = $seriesA;
    // Les jours à venir restent à null : la courbe s'arrête à aujourd'hui.
    $seriesB = [['name' => "Chiffre d'affaires cumulé", 'type' => 'line', 'values' => $cumRev, 'color' => $colRev]];
    $hl = [];
    if ($beTarget) $hl[] = ['value' => $beTarget, 'label' => 'Seuil de rentabilité', 'color' => 'var(--sage)'];
    if ($goalPeriod) $hl[] = ['value' => (float) $goalPeriod, 'label' => 'Objectif', 'color' => $colSim];
    $chartB = dash_chart($labels, $seriesB, $hl, null, "Chiffre d'affaires cumulé du mois face au seuil de rentabilité", 2);
    $legendB = $seriesB;
}
$visSeries = [
    ['name' => 'Visiteurs en ligne', 'type' => 'bar', 'values' => $visOnline, 'color' => $colRev],
    ['name' => 'Visiteurs en boutique', 'type' => 'bar', 'values' => $visPhys, 'color' => $colPhys],
];
$chartVis = dash_chart($visLabels, $visSeries, [], fn($v) => number_format($v, 0, ',', ' '), 'Visiteurs en ligne et en boutique', $view === 'month' ? 2 : 1);

/* ---------------------------------------------------------------- helpers vue */

$visitors = (int) $traffic['visitors'];
$onlineNow = dash_online_now();
$allVisitors = $visitors + (int) $phys['visitors'];
$allOrders = $eco['orders'];
$avgBasket = $eco['orders'] > 0 ? $eco['revenue'] / $eco['orders'] : null;
$marginRate = ($eco['revenue'] > 0) ? $eco['gross_margin'] / $eco['revenue'] : null;
$netRate = ($eco['revenue'] > 0) ? $eco['result'] / $eco['revenue'] : null;
$extraCost = $sim['costs'] - $eco['costs'];
$flash = flash_get();
$content = get_content();
$trackingSince = $traffic['since'] ? dash_from_utc($traffic['since']) : null;
$apiFirst = db()->query('SELECT MIN(ts) FROM api_usage')->fetchColumn();
$apiSince = $apiFirst ? dash_from_utc($apiFirst) : null;
$channelLabels = analytics_channels() + ['inconnu' => 'Non renseigné (avant le suivi)'];
$pageLabels = ['/index.php' => 'Accueil', '/boutique.php' => 'La boutique', '/produit.php' => 'Fiche d\'une pièce', '/cart.php' => 'Panier',
               '/checkout.php' => 'Paiement', '/checkout-success.php' => 'Confirmation de commande', '/slideshow.php' => 'Diaporama', '/' => 'Accueil'];
$deviceLabels = ['mobile' => 'Mobile', 'ordinateur' => 'Ordinateur', 'tablette' => 'Tablette'];
$return = fn(string $anchor = '') => '<input type="hidden" name="return" value="' . h($selfUrl . ($anchor ? '#' . $anchor : '')) . '">' . csrf_input();
$costsAll = shop_costs_all();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tableau de bord — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/dashboard.css">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'dashboard'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight dash">
  <div class="wrap">

    <div class="dash-head">
      <div>
        <p class="eyebrow">Espace boutique</p>
        <h1>Tableau de bord</h1>
        <p class="dash-sub">Frais, chiffre d'affaires, rentabilité et fréquentation de la boutique en ligne et du local.</p>
      </div>
      <div class="dash-controls">
        <div class="seg" role="group" aria-label="Type de vue">
          <a href="?<?= h($qs($start, 'month')) ?>" aria-pressed="<?= $view === 'month' ? 'true' : 'false' ?>">Mensuelle</a>
          <a href="?<?= h($qs($start, 'year')) ?>" aria-pressed="<?= $view === 'year' ? 'true' : 'false' ?>">Annuelle</a>
        </div>
        <div class="period-nav">
          <a class="btn-small" href="?<?= h($qs($prevStart, $view)) ?>" aria-label="Période précédente">←</a>
          <strong><?= h($periodLabel) ?></strong>
          <?php if ($hasNext): ?>
            <a class="btn-small" href="?<?= h($qs($nextStart, $view)) ?>" aria-label="Période suivante">→</a>
          <?php else: ?>
            <span class="btn-small is-off" aria-hidden="true">→</span>
          <?php endif; ?>
          <?php if (!$isCurrent): ?><a class="dash-link" href="?view=<?= $view ?>">Aujourd'hui</a><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <nav class="dash-toc" aria-label="Sections du tableau de bord">
      <a href="#rentabilite">Rentabilité</a><a href="#evolution">Évolution</a><a href="#frais">Frais</a><a href="#trafic">En ligne</a><a href="#physique">En boutique</a><a href="#catalogue">Catalogue &amp; galerie</a><a href="#methode">Méthode</a>
    </nav>

    <!-- ===================== Chiffres clés ===================== -->
    <div class="kpi-grid">
      <article class="kpi">
        <h3>Chiffre d'affaires encaissé</h3>
        <p class="kpi-v"><?= $eur($eco['revenue']) ?></p>
        <p class="kpi-s"><?= $eur($online['revenue']) ?> en ligne · <?= $eur($phys['sales_cents']) ?> en boutique</p>
        <?= $isCurrent ? $delta($eco['revenue'], $prevSame['revenue'], $sameLabel) : $delta($eco['revenue'], $prev['revenue']) ?>
      </article>
      <article class="kpi">
        <h3>Commandes &amp; ventes</h3>
        <p class="kpi-v"><?= $num($eco['orders']) ?></p>
        <p class="kpi-s"><?= $avgBasket !== null ? 'Panier moyen ' . $eur($avgBasket) : 'Aucune vente sur la période' ?></p>
        <?= $isCurrent ? $delta($eco['orders'], $prevSame['orders'], $sameLabel) : $delta($eco['orders'], $prev['orders']) ?>
      </article>
      <article class="kpi">
        <h3>Marge brute</h3>
        <p class="kpi-v"><?= $eco['revenue'] > 0 ? $eur($eco['gross_margin']) : '—' ?></p>
        <p class="kpi-s">
          <?php if ($marginRate !== null && $eco['cogs_coverage'] !== null && $eco['cogs_coverage'] > 0): ?>
            <?= $pct($marginRate) ?> du CA · prix d'achat connu sur <?= $pct($eco['cogs_coverage']) ?> des ventes
          <?php elseif ($eco['revenue'] > 0): ?>
            Renseignez le prix d'achat des pièces pour la calculer
          <?php else: ?>CA − prix d'achat des pièces<?php endif; ?>
        </p>
      </article>
      <article class="kpi">
        <h3>Frais réels de la boutique</h3>
        <p class="kpi-v"><?= $eur($eco['costs']) ?></p>
        <p class="kpi-s"><?= $eur($eco['fixed']) ?> fixes · <?= $eur($eco['variable']) ?> variables</p>
        <?= $isCurrent ? $delta($eco['costs'], $prevSame['costs'], $sameLabel) : $delta($eco['costs'], $prev['costs']) ?>
      </article>
      <article class="kpi <?= $eco['result'] >= 0 ? 'is-pos' : 'is-neg' ?>">
        <h3>Résultat net (réel)</h3>
        <p class="kpi-v"><?= $eur($eco['result']) ?></p>
        <p class="kpi-s"><?= $netRate !== null ? $pct($netRate) . ' du CA' : 'CA − achats − frais' ?></p>
      </article>
      <article class="kpi kpi-sim <?= $sim['result'] >= 0 ? 'is-pos' : 'is-neg' ?>">
        <h3>Résultat si les options de test deviennent payantes</h3>
        <p class="kpi-v"><?= $eur($sim['result']) ?></p>
        <p class="kpi-s"><?= $extraCost > 0.5 ? '−' . $eur($extraCost) . ' de frais en plus' : 'Aucun surcoût simulé sur la période' ?></p>
      </article>
      <article class="kpi">
        <h3>Visiteurs en ligne</h3>
        <p class="kpi-v"><?= $num($visitors) ?></p>
        <p class="kpi-s"><span class="live-dot"></span><strong id="live-online"><?= $onlineNow ?></strong> sur le site maintenant</p>
      </article>
      <article class="kpi">
        <h3>Visiteurs en boutique</h3>
        <p class="kpi-v"><?= $num($phys['visitors']) ?></p>
        <p class="kpi-s"><?= $phys['visitors'] > 0 && $phys['sales_count'] > 0 ? $pct($phys['sales_count'] / $phys['visitors']) . ' ont acheté' : ($phys['days'] ? 'Saisie sur ' . $phys['days'] . ' jour' . ($phys['days'] > 1 ? 's' : '') : 'Aucune saisie — voir « En boutique »') ?></p>
      </article>
    </div>

    <!-- ===================== Rentabilité ===================== -->
    <section class="dash-block" id="rentabilite">
      <h2>Rentabilité &amp; point de bascule</h2>
      <p class="hint">Le point de bascule est le chiffre d'affaires à partir duquel les ventes couvrent tous les frais fixes. Il se calcule avec la marge réellement dégagée sur vos 12 derniers mois<?= $be['basis'] === 'catalogue' ? ' — faute de ventes, il s\'appuie ici sur les prix et prix d\'achat du catalogue' : '' ?>.</p>

      <?php if ($beTarget): ?>
        <?php $pc = min(1.0, $coverage); ?>
        <div class="gauge" role="img" aria-label="Frais fixes couverts à <?= round($coverage * 100) ?> %">
          <div class="gauge-bar">
            <span class="gauge-fill <?= $coverage >= 1 ? 'is-ok' : '' ?>" style="width:<?= round($pc * 100, 1) ?>%"></span>
          </div>
          <div class="gauge-legend">
            <span><strong><?= $eur($eco['revenue']) ?></strong> encaissés</span>
            <span>Seuil : <strong><?= $eur($beTarget) ?></strong><?= $periodMonths > 1 ? ' sur l\'année' : ' ce mois-ci' ?></span>
          </div>
        </div>
        <p class="verdict <?= $coverage >= 1 ? 'is-pos' : 'is-neg' ?>">
          <?php if ($coverage >= 1): ?>
            Point de bascule dépassé : les frais fixes sont couverts<?= $crossDay ? ' depuis le ' . $crossDay . ' ' . h($MONTHS[$m]) : '' ?>.
            Chaque vente supplémentaire dégage environ <?= $be['rate'] !== null ? $pct($be['rate']) : '—' ?> de bénéfice.
          <?php else: ?>
            Il manque <?= $eur($beTarget - $eco['revenue']) ?> de chiffre d'affaires (<?= $be['avg_order'] ? 'environ ' . ceil(($beTarget - $eco['revenue']) / $be['avg_order']) . ' commande' . (ceil(($beTarget - $eco['revenue']) / $be['avg_order']) > 1 ? 's' : '') . ' de plus' : '' ?>) pour couvrir les frais fixes de la période.
          <?php endif; ?>
          <?php if ($projection !== null && $beTarget): ?>
            Au rythme actuel, la période finirait à <strong><?= $eur($projection) ?></strong> (<?= $projection >= $beTarget ? 'au-dessus' : 'sous' ?> le seuil).
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="dash-empty big">Le point de bascule ne peut pas encore être calculé :
          <?php if ($be['basis'] === null): ?>aucune vente ni pièce avec un prix dans le catalogue.
          <?php elseif ($be['rate'] !== null && $be['rate'] <= 0): ?>la marge est nulle ou négative après achats et frais variables — vendre davantage n'améliorerait pas le résultat. Vérifiez les prix d'achat et les frais par commande.
          <?php else: ?>aucun frais fixe renseigné.<?php endif; ?>
        </p>
      <?php endif; ?>

      <div class="two-col">
        <table class="dash-table" aria-label="Seuil de rentabilité réel et simulé">
          <thead><tr><th></th><th class="r">Réel</th><th class="r">Si payant</th></tr></thead>
          <tbody>
            <tr><th scope="row">Frais fixes par mois</th><td class="r"><?= $eur($be['fixed_monthly']) ?></td><td class="r"><?= $eur($beSim['fixed_monthly']) ?></td></tr>
            <tr><th scope="row">Marge sur chaque euro encaissé</th><td class="r"><?= $be['rate'] !== null ? $pct($be['rate'], 1) : '—' ?></td><td class="r"><?= $beSim['rate'] !== null ? $pct($beSim['rate'], 1) : '—' ?></td></tr>
            <tr><th scope="row">Panier moyen</th><td class="r"><?= $be['avg_order'] ? $eur($be['avg_order']) : '—' ?></td><td class="r"><?= $beSim['avg_order'] ? $eur($beSim['avg_order']) : '—' ?></td></tr>
            <tr class="strong"><th scope="row">Seuil de rentabilité mensuel</th><td class="r"><?= $be['be_revenue'] !== null ? $eur($be['be_revenue']) : '—' ?></td><td class="r"><?= $beSim['be_revenue'] !== null ? $eur($beSim['be_revenue']) : '—' ?></td></tr>
            <tr><th scope="row">…soit en commandes par mois</th><td class="r"><?= $be['be_orders'] !== null ? ceil($be['be_orders']) : '—' ?></td><td class="r"><?= $beSim['be_orders'] !== null ? ceil($beSim['be_orders']) : '—' ?></td></tr>
            <tr><th scope="row">Résultat de la période</th><td class="r <?= $eco['result'] >= 0 ? 'pos' : 'neg' ?>"><?= $eur($eco['result']) ?></td><td class="r <?= $sim['result'] >= 0 ? 'pos' : 'neg' ?>"><?= $eur($sim['result']) ?></td></tr>
          </tbody>
        </table>

        <div class="metric-list">
          <h3>Autres indicateurs</h3>
          <dl>
            <dt>Marge nette</dt><dd><?= $netRate !== null ? $pct($netRate, 1) : '—' ?> <small>résultat ÷ CA</small></dd>
            <dt>CA par visiteur (en ligne)</dt><dd><?= $visitors > 0 ? $eur($online['revenue'] / $visitors) : '—' ?></dd>
            <dt>Frais par visiteur (tous canaux)</dt><dd><?= $allVisitors > 0 ? $eur($eco['costs'] / $allVisitors) : '—' ?></dd>
            <dt>Frais par commande</dt><dd><?= $eco['orders'] > 0 ? $eur($eco['costs'] / $eco['orders']) : '—' ?></dd>
            <dt>Part des frais dans le CA</dt><dd><?= $eco['revenue'] > 0 ? $pct($eco['costs'] / $eco['revenue'], 1) : '—' ?></dd>
            <?php if ($goalPeriod): ?>
              <dt>Objectif de CA <?= $periodMonths > 1 ? 'annuel' : 'mensuel' ?></dt><dd><?= $eur($goalPeriod) ?> <small><?= $pct($eco['revenue'] / $goalPeriod) ?> atteint</small></dd>
            <?php endif; ?>
          </dl>
          <?php if ($be['coverage'] !== null && $be['coverage'] < 0.8): ?>
            <p class="hint warn">⚠ Prix d'achat connu pour seulement <?= $pct($be['coverage']) ?> du CA : la marge — donc le point de bascule — est probablement surestimée. Renseignez les prix d'achat dans le catalogue.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="payback">
        <h3>Amortissement depuis le lancement (<?= h($launch->format('d/m/Y')) ?>)</h3>
        <div class="payback-grid">
          <div><span>CA cumulé</span><strong><?= $eur($cum['revenue']) ?></strong></div>
          <div><span>Achats + frais cumulés</span><strong><?= $eur($cum['cogs'] + $cum['costs']) ?></strong></div>
          <div class="<?= $cum['result'] >= 0 ? 'pos' : 'neg' ?>"><span>Résultat cumulé</span><strong><?= $eur($cum['result']) ?></strong></div>
          <div class="<?= $cumSim['result'] >= 0 ? 'pos' : 'neg' ?>"><span>…avec options payantes</span><strong><?= $eur($cumSim['result']) ?></strong></div>
        </div>
        <p class="hint" style="margin:10px 0 0;">
          <?php if ($cum['result'] >= 0): ?>Les investissements et frais engagés depuis le lancement sont déjà amortis.
          <?php elseif ($paybackMonths !== null): ?>Reste à amortir : <strong><?= $eur($remaining) ?></strong>. À la moyenne des <?= $last3Months ?> derniers mois (<?= $eur($monthlyResult) ?>/mois), comptez environ <strong><?= $paybackMonths ?> mois</strong>.
          <?php else: ?>Reste à amortir : <strong><?= $eur($remaining) ?></strong>. Le résultat mensuel récent n'est pas positif, l'échéance ne peut pas être estimée.<?php endif; ?>
        </p>
      </div>
    </section>

    <!-- ===================== Évolution ===================== -->
    <section class="dash-block" id="evolution">
      <h2><?= $view === 'year' ? "Évolution sur l'année $y" : 'Évolution en ' . h($periodLabel) ?></h2>
      <div class="chart-card">
        <h3><?= $view === 'year' ? "Chiffre d'affaires, charges et résultat par mois" : "Chiffre d'affaires par jour" ?></h3>
        <?= $chartA ?>
        <?= dash_legend($legendA) ?>
      </div>
      <div class="chart-card">
        <h3><?= $view === 'year' ? 'Résultat cumulé depuis janvier' : "Chiffre d'affaires cumulé et seuil de rentabilité" ?></h3>
        <?= $chartB ?>
        <?= dash_legend(array_merge($legendB, $view === 'month' && $beTarget ? [['name' => 'Seuil de rentabilité', 'color' => 'var(--sage)', 'dashed' => true]] : [], $view === 'month' && $goalPeriod ? [['name' => 'Objectif', 'color' => $colSim, 'dashed' => true]] : [])) ?>
      </div>
    </section>

    <!-- ===================== Frais ===================== -->
    <section class="dash-block" id="frais">
      <h2>Frais de la boutique</h2>
      <div class="two-col">
        <div>
          <h3>Frais réels engendrés — <?= h($periodLabel) ?></h3>
          <?php $realLines = array_values(array_filter($eco['lines'], fn($l) => $l['cents'] > 0.5 || ($l['type'] === 'fixed' && $l['detail'] !== 'ponctuel'))); ?>
          <?php if ($realLines): ?>
            <table class="dash-table">
              <thead><tr><th>Poste</th><th>Détail</th><th class="r">Montant</th></tr></thead>
              <tbody>
                <?php foreach ($realLines as $l): ?>
                  <tr><th scope="row"><?= h($l['label']) ?><small><?= h(cost_categories()[$l['category']] ?? '') ?></small></th><td><?= h($l['detail']) ?></td><td class="r"><?= $eur($l['cents']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="strong"><th scope="row" colspan="2">Total des frais</th><td class="r"><?= $eur($eco['costs']) ?></td></tr>
                <?php if ($eco['cogs'] > 0): ?><tr><th scope="row" colspan="2">+ Prix d'achat des pièces vendues<small>hors frais</small></th><td class="r"><?= $eur($eco['cogs']) ?></td></tr><?php endif; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p class="dash-empty">Aucun frais réel enregistré. Ajoutez votre hébergement, votre nom de domaine, etc. dans « Gérer les frais » ci-dessous.</p>
          <?php endif; ?>
          <?php $zeroReal = array_filter($costsAll, fn($c) => $c['status'] === 'actif' && (float) $c['amount'] == 0.0); if ($zeroReal): ?>
            <p class="hint warn">⚠ <?= count($zeroReal) ?> frais réel<?= count($zeroReal) > 1 ? 's sont' : ' est' ?> encore à 0 € (<?= h(implode(', ', array_map(fn($c) => $c['label'], $zeroReal))) ?>) — renseignez les montants réels pour fiabiliser le résultat.</p>
          <?php endif; ?>
        </div>

        <div>
          <h3>Simulation : si les options de test deviennent payantes</h3>
          <?php $simLines = array_values(array_filter($sim['lines'], fn($l) => $l['status'] === 'test')); ?>
          <?php if ($simLines): ?>
            <table class="dash-table sim">
              <thead><tr><th>Option actuellement gratuite / en test</th><th>Usage</th><th class="r">Coût simulé</th></tr></thead>
              <tbody>
                <?php foreach ($simLines as $l): ?>
                  <tr><th scope="row"><?= h($l['label']) ?><small><?= h(cost_categories()[$l['category']] ?? '') ?></small></th><td><?= h($l['detail']) ?></td><td class="r"><?= $eur($l['cents']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="strong"><th scope="row" colspan="2">Surcoût total simulé</th><td class="r"><?= $eur($extraCost) ?></td></tr>
              </tbody>
            </table>
            <div class="sim-sum">
              <div><span>Résultat réel</span><strong class="<?= $eco['result'] >= 0 ? 'pos' : 'neg' ?>"><?= $eur($eco['result']) ?></strong></div>
              <div><span>Résultat simulé</span><strong class="<?= $sim['result'] >= 0 ? 'pos' : 'neg' ?>"><?= $eur($sim['result']) ?></strong></div>
              <div><span>Surcoût par commande</span><strong><?= $eco['online']['orders'] > 0 ? $eur($extraCost / $eco['online']['orders']) : '—' ?></strong></div>
            </div>
            <p class="hint">Les montants unitaires sont des estimations des tarifs publics, à ajuster dans « Gérer les frais ». Les appels aux API IA sont comptés depuis le <?= $apiSince ? h($apiSince->format('d/m/Y')) : 'première utilisation' ?>.</p>
          <?php else: ?>
            <p class="dash-empty">Aucune option de test enregistrée. Ajoutez-en une avec le statut « Test / gratuit aujourd'hui » pour voir ce qu'elle coûterait.</p>
          <?php endif; ?>
        </div>
      </div>

      <?php
        $catTotals = [];
        foreach ($eco['lines'] as $l) { if ($l['cents'] > 0.5) $catTotals[$l['category']] = ($catTotals[$l['category']] ?? 0) + $l['cents']; }
        arsort($catTotals);
      ?>
      <?php if ($catTotals): ?>
        <h3 style="margin-top:28px;">Répartition des frais réels</h3>
        <?= dash_hbars(array_map(fn($k, $v) => ['label' => cost_categories()[$k] ?? $k, 'value' => $v], array_keys($catTotals), $catTotals), fn($v) => $eur($v), 'var(--ink-soft)') ?>
      <?php endif; ?>

      <details class="manage" id="frais-gerer"<?= $flash && isset($_GET['frais']) ? ' open' : '' ?>>
        <summary>Gérer les frais et les options de test</summary>
        <p class="hint">« Réel » : vous payez vraiment. « Test / gratuit aujourd'hui » : option gratuite ou en mode test, dont le coût est seulement simulé. Quand vous passez une option en payant (ex : Stripe en mode live), cliquez sur « Passer en réel ».</p>
        <div class="cost-list">
          <?php foreach ($costsAll as $c): ?>
            <div class="cost-row <?= $c['status'] === 'test' ? 'is-test' : '' ?>">
              <div class="cost-main">
                <strong><?= h($c['label']) ?></strong>
                <span class="chip <?= $c['status'] === 'test' ? 'chip-test' : 'chip-real' ?>"><?= $c['status'] === 'test' ? 'Test / gratuit aujourd\'hui' : 'Réel' ?></span>
                <span class="cost-amount">
                  <?php
                    $unitLabel = cost_usage_units()[$c['unit'] ?? ''] ?? '';
                    $a = rtrim(rtrim(number_format((float) $c['amount'], 3, ',', ' '), '0'), ',');
                    echo h(match ($c['kind']) {
                      'fixed' => $a . ' € ' . ['monthly' => '/ mois', 'yearly' => '/ an', 'once' => '(ponctuel)'][$c['period']],
                      'per_order' => $a . ' € par commande en ligne',
                      'per_order_pct' => $a . ' % du montant encaissé',
                      default => $a . ' € par ' . mb_strtolower($unitLabel),
                    });
                  ?>
                </span>
                <?php if ($c['note']): ?><small class="cost-note"><?= h($c['note']) ?></small><?php endif; ?>
              </div>
              <div class="cost-actions">
                <form method="post" action="/admin/dashboard-action.php"><?= $return('frais-gerer') ?><input type="hidden" name="action" value="cost_toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn-small" type="submit"><?= $c['status'] === 'test' ? 'Passer en réel' : 'Remettre en test' ?></button></form>
                <form method="post" action="/admin/dashboard-action.php" onsubmit="return confirm('Supprimer cette ligne de frais ?');"><?= $return('frais-gerer') ?><input type="hidden" name="action" value="cost_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="admin-delete" type="submit">Supprimer</button></form>
              </div>
              <details class="cost-edit">
                <summary>Modifier</summary>
                <?php $cost = $c; include __DIR__ . '/../includes/dashboard-cost-form.php'; ?>
              </details>
            </div>
          <?php endforeach; ?>
        </div>
        <details class="cost-edit add">
          <summary class="add-product-btn">+ Ajouter un frais ou une option</summary>
          <?php $cost = ['id' => 0, 'label' => '', 'category' => 'autre', 'kind' => 'fixed', 'amount' => '', 'period' => 'monthly', 'unit' => '', 'status' => 'actif', 'start_date' => '', 'end_date' => '', 'note' => '']; include __DIR__ . '/../includes/dashboard-cost-form.php'; ?>
        </details>
      </details>
    </section>

    <!-- ===================== Trafic en ligne ===================== -->
    <section class="dash-block" id="trafic">
      <h2>Visiteurs en ligne</h2>
      <?php if (!$trackingSince): ?>
        <p class="dash-empty big">Le suivi des visites est actif mais n'a encore enregistré aucune visite. Les chiffres apparaîtront dès que le site public sera consulté (vos propres visites en tant qu'administrateur connecté ne sont pas comptées).</p>
      <?php else: ?>
        <p class="hint">Suivi anonyme, sans cookie, depuis le <?= h($trackingSince->format('d/m/Y')) ?>. Un visiteur = une personne par jour.</p>
      <?php endif; ?>

      <div class="stat-row">
        <div class="stat"><span>Sur le site maintenant</span><strong><span class="live-dot"></span><span id="live-online-2"><?= $onlineNow ?></span></strong><small>5 dernières minutes</small></div>
        <div class="stat"><span>Visiteurs</span><strong><?= $num($visitors) ?></strong><small><?= h($periodLabel) ?></small></div>
        <div class="stat"><span>Pages vues</span><strong><?= $num($traffic['pageviews']) ?></strong><small><?= $visitors ? $num($traffic['pageviews'] / $visitors, 1) . ' par visiteur' : '' ?></small></div>
        <div class="stat"><span>Taux de conversion</span><strong><?= $visitors ? $pct($online['orders'] / $visitors, 1) : '—' ?></strong><small><?= $num($online['orders']) ?> commande<?= $online['orders'] > 1 ? 's' : '' ?> en ligne</small></div>
      </div>

      <div class="two-col">
        <div>
          <h3>Provenance des visiteurs</h3>
          <?php
            $chRows = [];
            foreach ($traffic['channels'] as $k => $n) $chRows[] = ['label' => $channelLabels[$k] ?? $k, 'value' => $n, 'extra' => $visitors ? $pct($n / $visitors) : ''];
          ?>
          <?= dash_hbars($chRows) ?>
          <?php if ($traffic['referrers']): ?>
            <h4>Principaux sites d'origine</h4>
            <ul class="plain-list"><?php foreach ($traffic['referrers'] as $r): ?><li><span><?= h($r['ref_host']) ?> <small><?= h($channelLabels[$r['channel']] ?? '') ?></small></span><strong><?= (int) $r['n'] ?></strong></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if ($traffic['campaigns']): ?>
            <h4>Campagnes (utm_campaign)</h4>
            <ul class="plain-list"><?php foreach ($traffic['campaigns'] as $r): ?><li><span><?= h($r['utm_campaign']) ?> <small><?= h($r['src']) ?></small></span><strong><?= (int) $r['n'] ?></strong></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if ($traffic['countries']): ?>
            <h4>Pays</h4>
            <?= dash_hbars(array_map(fn($k, $n) => ['label' => $k, 'value' => $n], array_keys($traffic['countries']), $traffic['countries'])) ?>
          <?php else: ?>
            <p class="hint">Le pays n'est connu que si le site passe derrière un service qui l'indique (Cloudflare, par exemple) — sinon seule la provenance (site d'origine) est mesurable sans stocker d'adresse IP.</p>
          <?php endif; ?>
        </div>

        <div>
          <h3>Parcours d'achat</h3>
          <?php
            $steps = [
              ['Visiteurs', $visitors],
              ['Ont ouvert une fiche pièce', min($traffic['viewed_product'], $visitors)],
              ['Ont ajouté au panier', min($traffic['added_cart'], $visitors)],
              ['Sont allés au paiement', min($traffic['checkout'], $visitors)],
              ['Ont payé (commandes en ligne)', $online['orders']],
            ];
            $base = max(1, $steps[0][1]);
          ?>
          <ol class="funnel">
            <?php foreach ($steps as $i => [$lab, $val]): ?>
              <li><span class="f-lab"><?= h($lab) ?></span><span class="f-track"><span class="f-fill" style="width:<?= round(max($val > 0 ? 2 : 0, $val / $base * 100), 1) ?>%"></span></span><span class="f-val"><?= $num($val) ?><?= $i > 0 && $visitors ? ' <small>' . $pct($val / $base, 1) . '</small>' : '' ?></span></li>
            <?php endforeach; ?>
          </ol>
          <?php
            $cartAbandon = $traffic['added_cart'] > 0 ? 1 - min(1, $online['orders'] / $traffic['added_cart']) : null;
          ?>
          <?php if ($cartAbandon !== null): ?><p class="hint">Abandon de panier estimé : <strong><?= $pct($cartAbandon) ?></strong> des visiteurs ayant rempli leur panier n'ont pas payé.</p><?php endif; ?>

          <h4>Appareils</h4>
          <?= dash_hbars(array_map(fn($k, $n) => ['label' => $deviceLabels[$k] ?? $k, 'value' => $n, 'extra' => $visitors ? $pct($n / $visitors) : ''], array_keys($traffic['devices']), $traffic['devices']), null, 'var(--sage)') ?>
        </div>
      </div>

      <?php if ($traffic['channels'] || $online['by_channel']): ?>
        <h3 style="margin-top:28px;">Quels canaux rapportent des ventes ?</h3>
        <table class="dash-table">
          <thead><tr><th>Canal</th><th class="r">Visiteurs</th><th class="r">Commandes</th><th class="r">Conversion</th><th class="r">CA</th></tr></thead>
          <tbody>
            <?php
              $keys = array_unique(array_merge(array_keys($traffic['channels']), array_keys($online['by_channel'])));
              usort($keys, fn($a, $b) => ($online['by_channel'][$b]['revenue'] ?? 0) <=> ($online['by_channel'][$a]['revenue'] ?? 0) ?: ($traffic['channels'][$b] ?? 0) <=> ($traffic['channels'][$a] ?? 0));
              foreach ($keys as $k): $v = $traffic['channels'][$k] ?? 0; $o = $online['by_channel'][$k]['orders'] ?? 0; $r = $online['by_channel'][$k]['revenue'] ?? 0; ?>
              <tr><th scope="row"><?= h($channelLabels[$k] ?? $k) ?></th><td class="r"><?= $num($v) ?></td><td class="r"><?= $num($o) ?></td><td class="r"><?= $v > 0 && $k !== 'inconnu' ? $pct($o / $v, 1) : '—' ?></td><td class="r"><?= $eur($r) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="two-col" style="margin-top:28px;">
        <div>
          <h3>Pages les plus vues</h3>
          <?= dash_hbars(array_map(fn($r) => ['label' => $pageLabels[$r['path']] ?? $r['path'], 'value' => $r['n']], $traffic['pages']), null, 'var(--ink-soft)') ?>
        </div>
        <div>
          <h3>Pièces les plus consultées</h3>
          <?php
            $pmap = dash_product_map();
            $tp = array_map(fn($r) => ['label' => ($pmap[$r['ref']]['name'] ?? ('Réf. ' . $r['ref'])), 'value' => $r['n'], 'extra' => isset($pmap[$r['ref']]) && (int) $pmap[$r['ref']]['stock'] <= 0 ? 'vendue' : ''], $traffic['top_products']);
          ?>
          <?= dash_hbars($tp, null, 'var(--ink-soft)') ?>
        </div>
      </div>

      <?php if ($traffic['pageviews'] > 0):
        $hrs = []; for ($hh = 0; $hh < 24; $hh++) $hrs[] = (int) ($traffic['hours'][$hh] ?? 0);
      ?>
        <h3 style="margin-top:28px;">Heures de visite</h3>
        <div class="chart-card flat">
          <?= dash_chart(array_map(fn($h) => $h . 'h', range(0, 23)), [['name' => 'Pages vues', 'type' => 'bar', 'values' => $hrs, 'color' => 'var(--accent)']], [], fn($v) => number_format($v, 0, ',', ' '), 'Pages vues selon l\'heure de la journée', 2) ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- ===================== En boutique ===================== -->
    <section class="dash-block" id="physique">
      <h2>Visiteurs en boutique</h2>
      <p class="hint">La fréquentation du local ne se mesure pas toute seule : saisissez-la ici (compteur rapide ou récapitulatif du soir) pour la comparer au trafic en ligne et calculer le taux de transformation.</p>

      <div class="stat-row">
        <div class="stat"><span>Visiteurs</span><strong><?= $num($phys['visitors']) ?></strong><small><?= h($periodLabel) ?></small></div>
        <div class="stat"><span>Ventes</span><strong><?= $num($phys['sales_count']) ?></strong><small><?= $eur($phys['sales_cents']) ?></small></div>
        <div class="stat"><span>Taux de transformation</span><strong><?= $phys['visitors'] > 0 && $phys['sales_count'] > 0 ? $pct($phys['sales_count'] / $phys['visitors'], 1) : '—' ?></strong><small>ventes ÷ visiteurs</small></div>
        <div class="stat"><span>Panier moyen</span><strong><?= $phys['sales_count'] > 0 ? $eur($phys['sales_cents'] / $phys['sales_count']) : '—' ?></strong><small><?= $phys['visitors'] > 0 && $phys['sales_cents'] > 0 ? $eur($phys['sales_cents'] / $phys['visitors']) . ' par visiteur' : '' ?></small></div>
      </div>

      <div class="two-col">
        <div>
          <h3>Compteur rapide — aujourd'hui</h3>
          <?php $todayRow = db()->prepare('SELECT visitors FROM physical_visits WHERE day = ?'); $todayRow->execute([$now->format('Y-m-d')]); $todayN = (int) $todayRow->fetchColumn(); ?>
          <div class="counter">
            <form method="post" action="/admin/dashboard-action.php"><?= $return('physique') ?><input type="hidden" name="action" value="physical_inc"><input type="hidden" name="delta" value="-1"><button class="btn-small" type="submit" aria-label="Retirer un visiteur">−1</button></form>
            <strong aria-live="polite"><?= $todayN ?></strong>
            <form method="post" action="/admin/dashboard-action.php"><?= $return('physique') ?><input type="hidden" name="action" value="physical_inc"><input type="hidden" name="delta" value="1"><button class="btn btn-primary" type="submit">+1 visiteur</button></form>
          </div>

          <h3 style="margin-top:24px;">Saisir ou corriger une journée</h3>
          <form method="post" action="/admin/dashboard-action.php" class="phys-form">
            <?= $return('physique') ?><input type="hidden" name="action" value="physical_save">
            <div class="field-row-2">
              <div class="field"><label for="p-day">Date</label><input type="date" id="p-day" name="day" value="<?= h($now->format('Y-m-d')) ?>" max="<?= h($now->format('Y-m-d')) ?>" required></div>
              <div class="field"><label for="p-vis">Visiteurs</label><input type="number" id="p-vis" name="visitors" min="0" step="1" value="<?= $todayN ?>"></div>
            </div>
            <div class="field-row-2">
              <div class="field"><label for="p-sc">Nombre de ventes</label><input type="number" id="p-sc" name="sales_count" min="0" step="1" value="0"></div>
              <div class="field"><label for="p-sa">Montant encaissé (€)</label><input type="text" id="p-sa" name="sales_amount" inputmode="decimal" placeholder="ex : 85,00"></div>
            </div>
            <div class="field-row-2">
              <div class="field"><label for="p-co">Prix d'achat des pièces vendues (€, facultatif)</label><input type="text" id="p-co" name="cogs_amount" inputmode="decimal" placeholder="pour la marge"></div>
              <div class="field"><label for="p-no">Note (météo, marché, événement…)</label><input type="text" id="p-no" name="note" maxlength="120"></div>
            </div>
            <button class="btn btn-primary" type="submit">Enregistrer la journée</button>
          </form>
        </div>

        <div>
          <h3>Journées saisies — <?= h($periodLabel) ?></h3>
          <?php if ($phys['rows']): ?>
            <table class="dash-table">
              <thead><tr><th>Jour</th><th class="r">Visiteurs</th><th class="r">Ventes</th><th class="r">CA</th><th></th></tr></thead>
              <tbody>
                <?php foreach (array_reverse($phys['rows']) as $r): ?>
                  <tr>
                    <th scope="row"><?= h(date('d/m', strtotime($r['day']))) ?><?= $r['note'] ? '<small>' . h($r['note']) . '</small>' : '' ?></th>
                    <td class="r"><?= (int) $r['visitors'] ?></td><td class="r"><?= (int) $r['sales_count'] ?></td><td class="r"><?= $eur($r['sales_cents']) ?></td>
                    <td class="r"><form method="post" action="/admin/dashboard-action.php" onsubmit="return confirm('Supprimer cette journée ?');"><?= $return('physique') ?><input type="hidden" name="action" value="physical_delete"><input type="hidden" name="day" value="<?= h($r['day']) ?>"><button class="admin-delete" type="submit" aria-label="Supprimer le <?= h($r['day']) ?>">×</button></form></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p class="dash-empty">Aucune journée saisie sur cette période.</p>
          <?php endif; ?>

          <?php
            $wd = ['Lundi' => [0, 0], 'Mardi' => [0, 0], 'Mercredi' => [0, 0], 'Jeudi' => [0, 0], 'Vendredi' => [0, 0], 'Samedi' => [0, 0], 'Dimanche' => [0, 0]];
            $names = array_keys($wd);
            foreach (db()->query('SELECT day, visitors FROM physical_visits WHERE visitors > 0')->fetchAll() as $r) {
              $k = $names[(int) date('N', strtotime($r['day'])) - 1]; $wd[$k][0] += (int) $r['visitors']; $wd[$k][1]++;
            }
            $wdRows = []; foreach ($wd as $k => [$s, $c]) if ($c) $wdRows[] = ['label' => $k, 'value' => $s / $c, 'extra' => $c . ' j'];
          ?>
          <?php if ($wdRows): ?><h4>Visiteurs moyens par jour de la semaine</h4><?= dash_hbars($wdRows, fn($v) => number_format($v, 1, ',', ' '), 'var(--sage)') ?><?php endif; ?>
        </div>
      </div>

      <div class="chart-card" style="margin-top:28px;">
        <h3>En ligne et en boutique : où viennent les visiteurs ?</h3>
        <?= $chartVis ?>
        <?= dash_legend($visSeries) ?>
        <?php if ($allVisitors > 0): ?>
          <p class="hint" style="margin-top:10px;">Sur <?= h($periodLabel) ?> : <strong><?= $num($allVisitors) ?></strong> visiteurs au total, dont <?= $pct($visitors / $allVisitors) ?> en ligne et <?= $pct($phys['visitors'] / $allVisitors) ?> en boutique. Chiffre d'affaires par visiteur, tous canaux : <strong><?= $eur($eco['revenue'] / $allVisitors) ?></strong>.</p>
        <?php endif; ?>
      </div>
    </section>

    <!-- ===================== Catalogue & galerie ===================== -->
    <section class="dash-block" id="catalogue">
      <h2>Catalogue &amp; galerie</h2>
      <div class="stat-row">
        <div class="stat"><span>Pièces en vente</span><strong><?= $num($catalog['on_sale']) ?></strong><small><?= $catalog['hidden'] ?> masquée<?= $catalog['hidden'] > 1 ? 's' : '' ?> · <?= $catalog['sold'] ?> vendue<?= $catalog['sold'] > 1 ? 's' : '' ?></small></div>
        <div class="stat"><span>Valeur du stock (prix de vente)</span><strong><?= $eur($catalog['stock_value']) ?></strong><small><?= $catalog['stock_cost_known'] ? 'Achat : ' . $eur($catalog['stock_cost']) . ' · marge potentielle ' . $eur($catalog['stock_value_cost_known'] - $catalog['stock_cost']) : 'Prix d\'achat non renseignés' ?></small></div>
        <div class="stat"><span>Taux d'écoulement</span><strong><?= $catalog['total'] ? $pct($catalog['sold'] / $catalog['total']) : '—' ?></strong><small>pièces vendues ÷ pièces mises en ligne</small></div>
        <div class="stat"><span>Délai moyen de vente</span><strong><?= $catalog['avg_days_to_sell'] !== null ? $num($catalog['avg_days_to_sell']) . ' j' : '—' ?></strong><small>mise en ligne → vente</small></div>
      </div>

      <div class="two-col">
        <div>
          <h3>Meilleures ventes — <?= h($periodLabel) ?></h3>
          <?php
            $top = $online['products'];
            uasort($top, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
            $top = array_slice($top, 0, 6);
          ?>
          <?php if ($top): ?>
            <table class="dash-table">
              <thead><tr><th>Pièce</th><th class="r">Qté</th><th class="r">CA</th><th class="r">Marge</th></tr></thead>
              <tbody>
                <?php foreach ($top as $t): ?>
                  <tr><th scope="row"><?= h($t['name']) ?><small><?= h(category_label($t['cat'])) ?></small></th><td class="r"><?= (int) $t['qty'] ?></td><td class="r"><?= $eur($t['revenue']) ?></td><td class="r"><?= $t['cogs_known_rev'] > 0 ? $eur($t['cogs_known_rev'] - $t['cogs']) : '—' ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?><p class="dash-empty">Aucune vente en ligne sur la période.</p><?php endif; ?>
        </div>
        <div>
          <h3>Chiffre d'affaires par univers</h3>
          <?php
            $catRev = [];
            foreach ($online['products'] as $t) $catRev[$t['cat']] = ($catRev[$t['cat']] ?? 0) + $t['revenue'];
            arsort($catRev);
          ?>
          <?= dash_hbars(array_map(fn($k, $v) => ['label' => category_label($k), 'value' => $v], array_keys($catRev), $catRev), fn($v) => $eur($v)) ?>
        </div>
      </div>

      <h3 style="margin-top:28px;">État de la galerie photo</h3>
      <div class="stat-row">
        <div class="stat <?= $catalog['no_photo'] ? 'warn' : '' ?>"><span>Pièces sans photo</span><strong><?= $catalog['no_photo'] ?></strong><small><a href="/admin/catalog.php">Ouvrir le catalogue</a></small></div>
        <div class="stat"><span>Pièces avec moins de 3 photos</span><strong><?= $catalog['few_photos'] ?></strong><small>les fiches très illustrées convertissent mieux</small></div>
        <div class="stat"><span>Photos par pièce</span><strong><?= $num($catalog['photos_avg'], 1) ?></strong><small>moyenne sur les pièces en vente</small></div>
        <div class="stat"><span>Vues générées par IA</span><strong><?= $num($catalog['ai_images']) ?></strong><small>coût simulé ci-dessus dans « Frais »</small></div>
      </div>
      <?php if ($catalog['no_cost'] > 0): ?>
        <p class="hint warn">⚠ <?= $catalog['no_cost'] ?> pièce<?= $catalog['no_cost'] > 1 ? 's' : '' ?> en vente n'<?= $catalog['no_cost'] > 1 ? 'ont' : 'a' ?> pas de prix d'achat : leur marge n'est pas comptée. Renseignez-<?= $catalog['no_cost'] > 1 ? 'les' : 'le' ?> dans la fiche de chaque pièce (« Prix d'achat »).</p>
      <?php endif; ?>
    </section>

    <!-- ===================== Réglages ===================== -->
    <section class="dash-block" id="reglages-dashboard">
      <h2>Réglages du tableau de bord</h2>
      <form method="post" action="/admin/dashboard-action.php" class="phys-form" style="max-width:640px;">
        <?= $return('reglages-dashboard') ?><input type="hidden" name="action" value="settings_save">
        <div class="field-row-2">
          <div class="field"><label for="s-launch">Date de lancement de la boutique</label><input type="date" id="s-launch" name="launch_date" value="<?= h(analytics_setting('launch_date') ?: '') ?>"><small class="hint">Laissée vide : première activité détectée (<?= h($launch->format('d/m/Y')) ?>).</small></div>
          <div class="field"><label for="s-goal">Objectif de CA mensuel (€)</label><input type="text" id="s-goal" name="monthly_goal" inputmode="decimal" value="<?= $goalCents ? h(number_format($goalCents / 100, 2, ',', '')) : '' ?>" placeholder="facultatif"></div>
        </div>
        <button class="btn btn-primary" type="submit">Enregistrer</button>
      </form>
    </section>

    <!-- ===================== Méthode ===================== -->
    <section class="dash-block" id="methode">
      <h2>Comment lire ces chiffres</h2>
      <ul class="method">
        <li><strong>Chiffre d'affaires</strong> : montants encaissés (TTC, port inclus) des commandes en ligne payées via Stripe, plus les ventes saisies en boutique.</li>
        <li><strong>Résultat net</strong> = chiffre d'affaires − prix d'achat des pièces vendues − frais. Le port facturé au client est dans le CA : saisissez le port réellement payé aux transporteurs comme frais « par commande » pour ne pas le surestimer. La TVA et les cotisations ne sont pas déduites.</li>
        <li><strong>Frais réels</strong> : lignes au statut « Réel » (hébergement, domaine, frais de paiement une fois Stripe en mode live…). <strong>Options de test</strong> : ce que coûterait chaque service gratuit s'il devenait payant, calculé sur votre usage réel (nombre de commandes, d'images générées…).</li>
        <li><strong>Visiteurs en ligne</strong> : suivi maison, sans cookie ni adresse IP conservée ; robots, navigateurs « Do Not Track » et visites de l'administrateur exclus. Un visiteur est compté une fois par jour. Les données de visite sont supprimées au bout de <?= ANALYTICS_RETENTION_MONTHS ?> mois. Prévoyez de le mentionner dans votre politique de confidentialité.</li>
        <li><strong>Heures et jours</strong> : affichés à l'heure de Paris.</li>
      </ul>
    </section>

  </div>
</section>
</main>
<script>
(function () {
  // Compteur « en ligne maintenant » rafraîchi toutes les 30 s tant que l'onglet est visible.
  var ids = ['live-online', 'live-online-2'];
  function tick() {
    if (document.hidden) return;
    fetch('/admin/dashboard.php?live=1', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) ids.forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = d.online; }); })
      .catch(function () {});
  }
  setInterval(tick, 30000);
  // Rouvre la section « Gérer les frais » si on y est ancré après une action.
  if (location.hash === '#frais-gerer') { var d = document.getElementById('frais-gerer'); if (d) d.open = true; }
})();
</script>
</body>
</html>
