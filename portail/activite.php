<?php
// Activité de la plateforme : chiffres clés, points à traiter, courbes, santé du système, boutiques avec leurs ventes, paiements, et journal de tout ce qui
// se passe (inscriptions, paiements, créations et suppressions, y compris manuelles, réglages). Calculs dans includes/saas-events.php.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'note' && ($text = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 400)) !== '') {
        saas_event('note', ['note' => $text, 'actor' => 'exploitant']);
        portail_flash('Note ajoutée au journal.');
    }
    header('Location: /portail/activite.php' . (isset($_POST['back']) && preg_match('/^[a-z0-9=&_%.-]*$/i', (string) $_POST['back']) ? '?' . $_POST['back'] : '') . '#journal');
    exit;
}

$days = in_array((int) ($_GET['jours'] ?? 30), [7, 30, 90, 365], true) ? (int) ($_GET['jours'] ?? 30) : 30;
$group = isset(SAAS_EVENT_GROUPS[$_GET['groupe'] ?? '']) ? (string) $_GET['groupe'] : '';
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
$events = saas_events();
$shops = saas_shop_stats();
$dash = saas_dashboard($days, $events, $shops);

// Événements du journal, filtrés (le suivi technique des webhooks est masqué par défaut : il sert à la santé du système).
$rows = array_values(array_filter($events, static function (array $e) use ($group, $q): bool {
    $g = SAAS_EVENT_TYPES[$e['type']][1] ?? '';
    if ($group !== '' ? $g !== $group : $e['type'] === 'webhook') return false;
    if ($q === '') return true;
    return str_contains(mb_strtolower(implode(' ', [$e['email'] ?? '', $e['slug'] ?? '', $e['note'] ?? '', $e['plan'] ?? '', saas_event_label($e['type'])])), $q);
}));

// Exports CSV.
$export = (string) ($_GET['export'] ?? '');
if (in_array($export, ['journal', 'boutiques', 'paiements'], true)) {
    $lines = match ($export) {
        'journal' => [['Date', 'Type', 'Boutique', 'E-mail', 'Formule', 'Montant (€)', 'Auteur', 'Détail', 'Reconstitué'],
            array_map(static fn ($e) => [date('Y-m-d H:i:s', $e['ts']), saas_event_label($e['type']), $e['slug'] ?? '', $e['email'] ?? '', $e['plan'] ?? '',
                isset($e['amount']) ? number_format($e['amount'] / 100, 2, ',', '') : '', $e['actor'] ?? '', $e['note'] ?? '', !empty($e['reconstitue']) ? 'oui' : ''], $rows)],
        'paiements' => [['Date', 'Type', 'E-mail', 'Boutique', 'Formule', 'Montant (€)'],
            array_map(static fn ($e) => [date('Y-m-d H:i:s', $e['ts']), saas_event_label($e['type']), $e['email'] ?? '', $e['slug'] ?? '', $e['plan'] ?? '', number_format(($e['amount'] ?? 0) / 100, 2, ',', '')],
                array_values(array_filter($events, static fn ($e) => in_array($e['type'], ['payment_confirmed', 'payment_renewed', 'payment_failed', 'payment_refunded'], true))))],
        'boutiques' => [['Identifiant', 'Nom', 'Créée le', 'Formule', 'Paiement', 'Commandes payées', 'CA (€)', 'Commandes 30 j', 'CA 30 j (€)', 'Dernière commande', 'Pièces en vente', 'Contacts'],
            array_map(static fn ($s) => [$s['slug'], $s['name'], $s['created'] ? date('Y-m-d', $s['created']) : '', $s['plan']['name'] ?? '', $s['payment'] ?? '', $s['orders'], number_format($s['revenue'] / 100, 2, ',', ''),
                $s['orders30'], number_format($s['revenue30'] / 100, 2, ',', ''), $s['last_order'] ? date('Y-m-d', $s['last_order']) : '', $s['products'] ?? '', $s['accounts'] ?? ''], array_values($shops))],
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plateforme-' . $export . '-' . date('Ymd') . '.csv"');
    echo saas_csv($lines[0], $lines[1]);
    exit;
}

$perPage = 40;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pages = max(1, (int) ceil(count($rows) / $perPage));
$page = min($page, $pages);
$slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
$flash = portail_flash();
$origin = saas_origin();
$shopSlugs = array_keys($shops);
$url = static function (array $over = []) use ($days, $group, $q): string {
    $p = array_merge(['jours' => $days, 'groupe' => $group, 'q' => $q], $over);
    return '/portail/activite.php?' . http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null));
};
$delta = static fn (int $now, int $prev): string => $prev === 0 && $now === 0 ? '' : ($now === $prev ? '= période précédente' : ($now > $prev ? '▲ +' : '▼ −') . abs($now - $prev) . ' vs période précédente');

/** Histogramme SVG d'une série jour → valeur. */
$bars = static function (array $series, string $color, callable $fmt): string {
    $max = max(1, max($series));
    $n = count($series);
    $w = 620; $h = 96; $gap = 2; $bw = max(2, ($w - $gap * ($n - 1)) / $n);
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + 16) . '" preserveAspectRatio="none" role="img">';
    $i = 0;
    foreach ($series as $d => $v) {
        $bh = $v > 0 ? max(2, $v / $max * $h) : 1;
        $svg .= '<rect x="' . round($i * ($bw + $gap), 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" fill="' . ($v > 0 ? $color : 'currentColor') . '" fill-opacity="' . ($v > 0 ? 1 : .18) . '"><title>' . date('d/m', strtotime($d)) . ' : ' . e($fmt($v)) . '</title></rect>';
        $i++;
    }
    return $svg . '<text x="0" y="' . ($h + 13) . '" font-size="10" fill="currentColor" fill-opacity=".6">' . date('d/m', strtotime(array_key_first($series))) . '</text><text x="' . $w . '" y="' . ($h + 13) . '" font-size="10" text-anchor="end" fill="currentColor" fill-opacity=".6">' . date('d/m', strtotime(array_key_last($series))) . '</text></svg>';
};
$sevColor = ['ok' => 'ok', 'warn' => 'warn', 'bad' => 'bad', 'info' => 'info'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Activité — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .owrap { display: grid; gap: 16px; max-width: 1180px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.02rem; }
  .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
  .kpi { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; display: grid; gap: 2px; align-content: start; }
  .kpi .n { font-size: 1.9rem; font-weight: 700; line-height: 1.1; letter-spacing: -.02em; }
  .kpi .l { font-size: .82rem; color: var(--muted); font-weight: 600; }
  .kpi .s { font-size: .78rem; color: var(--muted); }
  .kpi.bad .n { color: #b3261e; } .kpi.ok .n { color: var(--ok); }
  .periods { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
  .periods a { padding: 6px 14px; text-decoration: none; color: var(--ink); font-size: .86rem; font-weight: 600; } .periods a[aria-current="true"] { background: var(--ink); color: var(--panel); }
  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; } @media (max-width: 900px) { .two { grid-template-columns: 1fr; } }
  .chart { width: 100%; height: 130px; color: var(--ink); display: block; }
  .todo { display: grid; gap: 8px; } .todo > div { display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; padding: 9px 12px; border-radius: 8px; background: var(--panel-2); border-left: 4px solid var(--warn); }
  .todo > div.bad { border-left-color: #b3261e; } .todo b { font-weight: 700; } .todo a { margin-left: auto; font-weight: 600; font-size: .88rem; }
  .health { display: grid; gap: 6px; } .health > div { display: grid; grid-template-columns: 22px 150px 1fr; gap: 8px; align-items: baseline; font-size: .9rem; } .health .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; background: var(--ok); } .health .warn .dot { background: var(--warn); } .health .bad .dot { background: #b3261e; } .health span.d { color: var(--muted); }
  table.t { width: 100%; border-collapse: collapse; font-size: .88rem; } .t th { text-align: left; font-size: .74rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 6px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top; } .t td.r, .t th.r { text-align: right; font-variant-numeric: tabular-nums; } .t tr:last-child td { border-bottom: 0; }
  .scroll { overflow-x: auto; }
  .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; white-space: nowrap; background: var(--panel-2); border: 1px solid var(--line); }
  .tag.ok { background: var(--ok-soft); color: var(--ok); border-color: transparent; } .tag.warn { color: var(--warn); } .tag.bad { color: #b3261e; border-color: currentColor; } .tag.info { color: var(--muted); }
  .muted { color: var(--muted); } .mono { font-family: var(--font-mono); font-size: .8rem; }
  .filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; } .filters a.chip { padding: 4px 12px; border: 1px solid var(--line); border-radius: 999px; text-decoration: none; font-size: .84rem; color: var(--ink); } .filters a.chip[aria-current="true"] { background: var(--ink); color: var(--panel); border-color: var(--ink); }
  .filters input[type=search], .note-form input[type=text] { padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .note-form { display: flex; gap: 8px; } .note-form input { flex: 1; min-width: 0; }
  .pager { display: flex; gap: 10px; align-items: center; justify-content: center; font-size: .88rem; }
  .bar-row { display: flex; align-items: center; gap: 10px; font-size: .88rem; } .bar-row i { display: block; height: 10px; background: var(--accent); border-radius: 5px; min-width: 4px; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Activité</h1><p>Suivi de la plateforme : inscriptions, paiements, boutiques créées et supprimées, ventes</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn" href="/portail/">Portail des commerces</a><a class="btn" href="/portail/inscriptions.php">Inscriptions</a><a class="btn" href="/portail/nouveau.php">Nouveau commerce</a>
    </span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <span class="muted">Chiffres de la période : <strong><?= $days === 365 ? '12 derniers mois' : $days . ' derniers jours' ?></strong></span>
      <span class="periods" role="group" aria-label="Période">
        <?php foreach ([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '12 mois'] as $d => $l): ?><a href="<?= e($url(['jours' => $d, 'page' => ''])) ?>"<?= $days === $d ? ' aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
      </span>
    </div>

    <section class="kpis" aria-label="Chiffres clés">
      <div class="kpi"><span class="l">Boutiques en ligne</span><span class="n"><?= (int) $dash['shops_total'] ?></span><span class="s"><?= (int) $dash['created'] ?> créée<?= $dash['created'] > 1 ? 's' : '' ?> sur la période<?= $delta((int) $dash['created'], (int) $dash['created_prev']) !== '' ? ' · ' . e($delta((int) $dash['created'], (int) $dash['created_prev'])) : '' ?></span></div>
      <div class="kpi"><span class="l">Inscriptions → paiements → boutiques</span><span class="n"><?= (int) $dash['started'] ?> → <?= (int) $dash['paid'] ?> → <?= (int) $dash['created'] ?></span>
        <span class="s"><?= $dash['started'] > 0 ? round($dash['created'] / $dash['started'] * 100) . ' % d\'inscriptions menées jusqu\'à la boutique' : 'Aucune inscription sur la période' ?></span></div>
      <div class="kpi ok"><span class="l">Encaissé par la plateforme</span><span class="n"><?= e(saas_money((int) $dash['plat_revenue'])) ?></span><span class="s"><?= (int) $dash['paid'] ?> paiement<?= $dash['paid'] > 1 ? 's' : '' ?> · <?= (int) $dash['renewals'] ?> renouvellement<?= $dash['renewals'] > 1 ? 's' : '' ?><?= $dash['refunds'] ? ' · ' . e(saas_money((int) $dash['refunds'])) . ' remboursés' : '' ?></span></div>
      <div class="kpi"><span class="l">Abonnements actifs</span><span class="n"><?= (int) $dash['subscriptions'] ?></span><span class="s">≈ <?= e(saas_money((int) $dash['mrr'])) ?> / mois (prix des formules)</span></div>
      <div class="kpi<?= $dash['canceled'] + $dash['deleted'] > 0 ? ' bad' : '' ?>"><span class="l">Résiliations · suppressions</span><span class="n"><?= (int) $dash['canceled'] ?> · <?= (int) $dash['deleted'] ?></span><span class="s">sur la période<?= $dash['failed_payments'] ? ' · ' . (int) $dash['failed_payments'] . ' paiement(s) refusé(s)' : '' ?></span></div>
      <div class="kpi"><span class="l">Ventes des boutiques</span><span class="n"><?= e(saas_money((int) $dash['shop_revenue'])) ?></span><span class="s"><?= (int) $dash['shop_orders'] ?> commande<?= $dash['shop_orders'] > 1 ? 's' : '' ?> sur 30 j · <?= e(saas_money((int) $dash['shop_revenue_all'])) ?> au total (<?= (int) $dash['shop_orders_all'] ?>)</span></div>
    </section>

    <?php $todo = []; ?>
    <?php
    foreach ($dash['paid_no_shop'] as $r) $todo[] = ['', '<b>' . e($r['email']) . '</b> a payé' . (isset($r['payment']['paid_at']) ? ' le ' . date('d/m H:i', $r['payment']['paid_at']) : '') . ' mais n\'a pas encore créé sa boutique.', '/portail/inscriptions.php', 'Renvoyer le lien'];
    foreach ($dash['to_collect'] as $r) $todo[] = ['', '<b>' . e($r['shop_name'] ?: $r['email']) . '</b> : formule ' . e(saas_plan_of($r)['name']) . ' (' . e(saas_money((int) saas_plan_of($r)['cents'])) . '/mois) à régler avec le client.', '/portail/inscriptions.php', 'Voir'];
    foreach ($dash['failed'] as $r) $todo[] = ['bad', 'Création en échec pour <b>' . e($r['email']) . '</b> : ' . e((string) $r['error']), '/portail/inscriptions.php', 'Traiter'];
    foreach ($shops as $s) if (($s['payment'] ?? '') === 'canceled') $todo[] = ['bad', 'Abonnement résilié, boutique <b>' . e($s['name']) . '</b> toujours en ligne.', '/portail/?voir=' . rawurlencode($s['slug']), 'Décider'];
    foreach ($events as $ev) if ($ev['type'] === 'payment_failed' && $ev['ts'] > time() - 14 * 86400) $todo[] = ['bad', 'Paiement refusé pour <b>' . e($ev['email'] ?? '') . '</b> le ' . date('d/m', $ev['ts']) . '.', '/portail/activite.php?groupe=paiement#journal', 'Voir'];
    ?>
    <section class="ocard" id="atraiter">
      <h2>À traiter <small><?= count($todo) ?></small></h2>
      <?php if (!$todo): ?><p class="muted" style="margin:0">Rien en attente : aucun paiement sans boutique, aucune création en échec, aucun encaissement à régler.</p>
      <?php else: ?><div class="todo"><?php foreach ($todo as [$cls, $msg, $href, $label]): ?><div class="<?= e($cls) ?>"><span><?= $msg ?></span><a href="<?= e($href) ?>"><?= e($label) ?> →</a></div><?php endforeach; ?></div><?php endif; ?>
    </section>

    <div class="two">
      <section class="ocard"><h2>Boutiques créées <small>par jour</small></h2><?= $bars($dash['series_created'], 'var(--ok)', static fn ($v) => $v . ' boutique(s)') ?></section>
      <section class="ocard"><h2>Encaissé par la plateforme <small>par jour</small></h2><?= $bars($dash['series_revenue'], 'var(--accent)', static fn ($v) => saas_money((int) $v)) ?></section>
    </div>
    <div class="two">
      <section class="ocard"><h2>Inscriptions commencées <small>par jour</small></h2><?= $bars($dash['series_started'], 'var(--warn)', static fn ($v) => $v . ' inscription(s)') ?></section>
      <section class="ocard"><h2>Formules des boutiques créées en self-service</h2>
        <?php if (!$dash['plans']) : ?><p class="muted" style="margin:0">Aucune boutique créée par ce parcours pour l'instant.</p><?php endif; ?>
        <?php $maxPlan = max(1, ...array_values($dash['plans'] ?: [1])); foreach ($dash['plans'] as $name => $n): ?>
          <div class="bar-row"><span style="width:130px"><?= e($name) ?></span><i style="width:<?= round($n / $maxPlan * 60) ?>%"></i><b><?= (int) $n ?></b></div>
        <?php endforeach; ?>
      </section>
    </div>

    <section class="ocard">
      <h2>Santé du système</h2>
      <div class="health"><?php foreach (saas_health($events) as [$label, $state, $detail]): ?><div class="<?= e($state) ?>"><span class="dot" aria-hidden="true"></span><strong><?= e($label) ?></strong><span class="d"><?= e($detail) ?></span></div><?php endforeach; ?></div>
      <p class="muted" style="margin:6px 0 0;font-size:.82rem">Pour suivre renouvellements, échecs de paiement et remboursements, ajoutez ces événements au webhook Stripe (<code><?= e($origin) ?>/inscription/webhook.php</code>) : <code>invoice.paid</code>, <code>invoice.payment_failed</code>, <code>charge.refunded</code> (en plus de <code>checkout.session.completed</code> et <code>customer.subscription.deleted</code>).</p>
    </section>

    <section class="ocard">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Boutiques <small><?= count($shops) ?></small></h2><a class="btn small" href="<?= e($url(['export' => 'boutiques'])) ?>">Exporter (CSV)</a></div>
      <div class="scroll"><table class="t">
        <thead><tr><th>Boutique</th><th>Créée</th><th>Formule</th><th>Paiement</th><th class="r">Pièces</th><th class="r">Commandes</th><th class="r">CA</th><th class="r">30 j</th><th>Dernière commande</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($shops as $s):
            $stale = $s['db'] && $s['last_order'] !== null && $s['last_order'] < time() - 30 * 86400;
            $payTag = match ($s['payment'] ?? null) { 'paid' => ['ok', 'Abonné'], 'canceled' => ['bad', 'Résilié'], 'manual' => ['warn', 'À régler'], 'free' => ['info', 'Gratuit'], null => ['info', 'hors parcours'], default => ['info', (string) $s['payment']] }; ?>
          <tr>
            <td><strong><?= e($s['name']) ?></strong><br><span class="muted mono"><?= e($s['slug']) ?></span><?= !empty($s['auth']) ? ' <span class="tag info">' . e(OAUTH_PROVIDERS[$s['auth']]['label'] ?? $s['auth']) . '</span>' : '' ?></td>
            <td><?= $s['created'] ? e(date('d/m/Y', $s['created'])) : '—' ?></td>
            <td><?= e($s['plan']['name'] ?? '—') ?></td>
            <td><span class="tag <?= e($payTag[0]) ?>"><?= e($payTag[1]) ?></span></td>
            <td class="r"><?= $s['products'] ?? '—' ?><?= $s['limit'] > 0 ? ' / ' . (int) $s['limit'] . ((int) $s['products'] >= $s['limit'] ? ' <span class="tag warn" title="Limite de l\'offre atteinte : occasion de proposer la tranche supérieure">pleine</span>' : '') : '' ?></td>
            <td class="r"><?= (int) $s['orders'] ?></td>
            <td class="r"><?= e(saas_money((int) $s['revenue'])) ?></td>
            <td class="r"><?= (int) $s['orders30'] ?> · <?= e(saas_money((int) $s['revenue30'])) ?></td>
            <td><?= $s['last_order'] ? e(date('d/m/Y', $s['last_order'])) . ($stale ? ' <span class="tag warn">inactive</span>' : '') : '<span class="muted">aucune</span>' ?></td>
            <td><a href="/portail/?voir=<?= e(rawurlencode($s['slug'])) ?>">Gérer</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <?php $pay = array_slice(array_values(array_filter($events, static fn ($e) => in_array($e['type'], ['payment_confirmed', 'payment_renewed', 'payment_failed', 'payment_refunded'], true))), 0, 12); ?>
    <section class="ocard">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Derniers paiements</h2><a class="btn small" href="<?= e($url(['export' => 'paiements'])) ?>">Exporter tous les paiements (CSV)</a></div>
      <?php if (!$pay): ?><p class="muted" style="margin:0">Aucun paiement enregistré pour l'instant.</p><?php else: ?>
      <div class="scroll"><table class="t"><thead><tr><th>Date</th><th>Événement</th><th>Client</th><th>Formule</th><th class="r">Montant</th></tr></thead><tbody>
        <?php foreach ($pay as $e): ?><tr><td><?= e(date('d/m/Y H:i', $e['ts'])) ?></td><td><span class="tag <?= e(SAAS_EVENT_TYPES[$e['type']][2]) ?>"><?= e(saas_event_label($e['type'])) ?></span></td><td><?= e($e['email'] ?? '') ?></td><td><?= e($e['plan'] ?? '') ?></td><td class="r"><?= isset($e['amount']) ? e(saas_money((int) $e['amount'])) : '—' ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>

    <section class="ocard" id="journal">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Journal <small><?= count($rows) ?> événement<?= count($rows) > 1 ? 's' : '' ?></small></h2><a class="btn small" href="<?= e($url(['export' => 'journal'])) ?>">Exporter (CSV)</a></div>
      <div class="filters">
        <a class="chip" href="<?= e($url(['groupe' => '', 'page' => ''])) ?>#journal"<?= $group === '' ? ' aria-current="true"' : '' ?>>Tout</a>
        <?php foreach (SAAS_EVENT_GROUPS as $g => $l): ?><a class="chip" href="<?= e($url(['groupe' => $g, 'page' => ''])) ?>#journal"<?= $group === $g ? ' aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
        <form method="get" action="/portail/activite.php#journal" style="margin-left:auto;display:flex;gap:6px"><input type="hidden" name="jours" value="<?= (int) $days ?>"><input type="hidden" name="groupe" value="<?= e($group) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="E-mail, boutique, mot…" aria-label="Rechercher dans le journal"><button class="btn small" type="submit">Chercher</button></form>
      </div>
      <?php if (!$slice): ?><p class="muted" style="margin:0">Aucun événement<?= $q !== '' || $group !== '' ? ' avec ces filtres' : '' ?>.</p><?php else: ?>
      <div class="scroll"><table class="t"><thead><tr><th>Date</th><th>Événement</th><th>Détail</th><th>Auteur</th></tr></thead><tbody>
        <?php foreach ($slice as $ev):
            $meta = SAAS_EVENT_TYPES[$ev['type']] ?? [$ev['type'], '', 'info'];
            $parts = [];
            if (!empty($ev['slug'])) $parts[] = in_array($ev['slug'], $shopSlugs, true) ? '<a href="/portail/?voir=' . e(rawurlencode($ev['slug'])) . '"><strong>' . e($ev['slug']) . '</strong></a>' : '<strong>' . e($ev['slug']) . '</strong>';
            if (!empty($ev['email'])) $parts[] = e($ev['email']);
            if (!empty($ev['plan'])) $parts[] = 'formule ' . e($ev['plan']);
            if (isset($ev['amount']) && $ev['amount'] > 0) $parts[] = '<strong>' . e(saas_money((int) $ev['amount'])) . '</strong>';
            if (!empty($ev['trash'])) $parts[] = '<span class="mono">' . e($ev['trash']) . '</span>';
            if (!empty($ev['note'])) $parts[] = '<span class="muted">' . e($ev['note']) . '</span>'; ?>
          <tr><td style="white-space:nowrap"><?= e(date('d/m/Y H:i', $ev['ts'])) ?></td>
            <td><span class="tag <?= e($sevColor[$meta[2]] ?? 'info') ?>"><?= e($meta[0]) ?></span><?= !empty($ev['reconstitue']) ? ' <span class="tag info" title="Reconstitué d\'après les demandes et la corbeille : le journal n\'existait pas encore">reconstitué</span>' : '' ?></td>
            <td><?= implode(' · ', $parts) ?></td><td class="muted"><?= e($ev['actor'] ?? '') ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($pages > 1): ?><div class="pager"><?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>#journal">← Plus récents</a><?php endif; ?><span>Page <?= $page ?> / <?= $pages ?></span><?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>#journal">Plus anciens →</a><?php endif; ?></div><?php endif; ?>
      <?php endif; ?>
      <form class="note-form" method="post" action="/portail/activite.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="note">
        <input type="text" name="note" maxlength="400" placeholder="Ajouter une note au journal (ex. « remboursement fait à la main pour X »)" aria-label="Note"><button class="btn" type="submit">Ajouter</button></form>
    </section>
  </div>
</div>
</body>
</html>
