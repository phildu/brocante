<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/addons.php';
require_once __DIR__ . '/../includes/plugins.php';
require_once __DIR__ . '/../includes/appearance.php';
require_once __DIR__ . '/../includes/legal.php';
require_admin();
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page

$content = get_content();
$flash = flash_get();
$pdo = db();
$shopName = $content['site_name'] ?: tenant('name');

$tabs = [
    'resume' => 'Résumé',
    'ventes' => 'Ventes',
    'visites' => 'Visites',
    'ia' => 'Consommation IA',
    'modules' => ext_t('{Exts} & essais'),
    'todo' => 'Avant la mise en ligne',
];
// IA facultative : l'onglet de consommation disparaît pour une boutique dont l'IA est désactivée dans le portail.
if (!ai_shop_enabled()) unset($tabs['ia']);
$tab = isset($tabs[$_GET['s'] ?? '']) ? (string) $_GET['s'] : 'resume';

// ── Ventes ──────────────────────────────────────────────────────────────────
$paidStats = static function (int $days) use ($pdo): array {
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount_total), 0) AS total FROM orders WHERE status = 'paid' AND created_at >= datetime('now', ?)");
    $st->execute(['-' . $days . ' days']);
    $r = $st->fetch() ?: ['n' => 0, 'total' => 0];
    return ['n' => (int) $r['n'], 'total' => (int) $r['total']];
};
$sales7 = $paidStats(7);
$sales30 = $paidStats(30);
$sales90 = $paidStats(90);
$avg30 = $sales30['n'] > 0 ? intdiv($sales30['total'], $sales30['n']) : 0;
$toPrepare = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND fulfillment_status = 'a_preparer'")->fetchColumn();
$pendingCheckouts = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND created_at >= datetime('now', '-1 day')")->fetchColumn();
$recentOrders = $pdo->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 8')->fetchAll();
$prepareOrders = $pdo->query("SELECT * FROM orders WHERE status = 'paid' AND fulfillment_status = 'a_preparer' ORDER BY created_at ASC LIMIT 8")->fetchAll();
$onlineProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_hidden = 0 AND (stock > 0 OR keep_when_sold = 1)')->fetchColumn();
$totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

// ── Visites (module « Tableau de bord », mesure sans cookie) ────────────────
$visits = null;
try {
    $hasVisits = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'td_views'")->fetchColumn();
    $visitsEntitled = (bool) addon_entitlement('tableau-de-bord')['entitled'];
} catch (Throwable $e) {
    $hasVisits = false;
    $visitsEntitled = false;
}
if ($hasVisits && $visitsEntitled) {
    $visitSpan = static function (int $days) use ($pdo): array {
        $st = $pdo->prepare("SELECT COUNT(*) AS views, COUNT(DISTINCT day || vid) AS visitors FROM td_views WHERE day >= date('now', ?)");
        $st->execute(['-' . max(0, $days - 1) . ' days']);
        $r = $st->fetch() ?: ['views' => 0, 'visitors' => 0];
        return ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors']];
    };
    $visits = [
        'd7' => $visitSpan(7),
        'd30' => $visitSpan(30),
        'sources' => $pdo->query("SELECT source, COUNT(DISTINCT day || vid) AS visitors, COUNT(*) AS views FROM td_views WHERE day >= date('now', '-29 days') AND source <> 'interne' GROUP BY source ORDER BY visitors DESC LIMIT 5")->fetchAll(),
        'products' => $pdo->query("SELECT v.product_ref AS ref, COALESCE(p.name, v.product_ref) AS name, COUNT(*) AS views FROM td_views v LEFT JOIN products p ON p.ref = v.product_ref WHERE v.day >= date('now', '-29 days') AND v.product_ref <> '' GROUP BY v.product_ref ORDER BY views DESC LIMIT 5")->fetchAll(),
    ];
}

// ── Consommation IA ─────────────────────────────────────────────────────────
$aiQuota = ai_quota_state();
$aiMonth = ai_usage_spent(gmdate('Y-m-01 00:00:00'));
$aiToday = ai_usage_spent(gmdate('Y-m-d 00:00:00'));
$aiBalance = ai_balance();
$aiPct = $aiQuota && $aiQuota['quota'] > 0 ? min(100, (int) round($aiQuota['month_spent'] / $aiQuota['quota'] * 100)) : 0;

// ── Modules & essais ────────────────────────────────────────────────────────
$modules = [];
foreach (array_keys(addon_defaults()) as $moduleId) {
    try {
        $ent = addon_entitlement($moduleId);
        $row = addon_row($moduleId);
    } catch (Throwable $e) {
        continue;
    }
    if (($ent['status'] ?? 'inactive') === 'inactive') continue;
    $daysLeft = null;
    if ($row['status'] === 'trial' && (int) $row['trial_until'] > 0) {
        $daysLeft = max(0, (int) ceil(((int) $row['trial_until'] - time()) / 86400));
    }
    $modules[] = ['name' => $ent['name'], 'label' => $ent['label'], 'status' => $ent['status'], 'days' => $daysLeft, 'entitled' => $ent['entitled']];
}
$trials = array_values(array_filter($modules, static fn (array $m): bool => $m['status'] === 'trial'));

// ── À faire avant la mise en ligne ──────────────────────────────────────────
$identity = legal_identity();
$legalMissing = legal_missing_fields();
$legalState = legal_status();
$hasAddress = trim((string) ($content['contact_address'] ?? '')) !== '' && !preg_match('/^(à compléter|a completer|\[)/iu', trim((string) $content['contact_address']));
$todo = [
    ['label' => 'Identité et coordonnées de la boutique', 'done' => trim((string) ($content['site_name'] ?? '')) !== '' && trim((string) ($content['site_tagline'] ?? '')) !== '' && $hasAddress,
        'hint' => 'Nom, signature et adresse affichés sur le site et dans les documents légaux.', 'url' => '/admin/index.php?s=general', 'cta' => 'Compléter'],
    ['label' => 'Logo de la boutique', 'done' => logos_saved() !== [],
        'hint' => 'Un logo horizontal (en-tête) et un carré (icône, partage).', 'url' => '/admin/appearance.php', 'cta' => 'Choisir un logo'],
    ['label' => 'Au moins une pièce en ligne', 'done' => $onlineProducts > 0,
        'hint' => 'Le catalogue doit contenir une pièce visible et disponible.', 'url' => '/admin/quick-add.php', 'cta' => 'Ajouter une pièce'],
    ['label' => 'Diaporama d’accueil', 'done' => count(hero_slides_list()) > 0,
        'hint' => 'Les photos qui défilent en haut de la page d’accueil.', 'url' => '/admin/hero.php', 'cta' => 'Ajouter des photos'],
    ['label' => 'Paiement en ligne prêt', 'done' => !shop_sales_enabled() || shop_checkout_ready(),
        'hint' => !shop_sales_enabled() ? 'La vente en ligne est désactivée pour cette boutique.' : 'Clé Stripe (ou compte Stripe Connect) configurée.', 'url' => '/admin/index.php?s=paiement', 'cta' => 'Vérifier'],
    ['label' => 'Pages légales complétées et validées', 'done' => $legalState['validated'] && $legalMissing === [],
        'hint' => $legalMissing !== [] ? 'Champs manquants : ' . implode(', ', $legalMissing) . '.' : ($legalState['validated'] ? 'Mentions, CGV et confidentialité à jour.' : 'Relisez puis validez les textes.'), 'url' => '/admin/legal.php', 'cta' => 'Ouvrir'],
];
$todoDone = count(array_filter($todo, static fn (array $t): bool => $t['done']));
$todoRemaining = array_values(array_filter($todo, static fn (array $t): bool => !$t['done']));

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tableau de bord — <?= h($shopName) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .dash-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid var(--line); margin: 18px 0 24px; }
  .dash-tabs a { padding: 10px 14px; text-decoration: none; color: var(--ink-soft); border-bottom: 2px solid transparent; margin-bottom: -1px; font-size: .9rem; font-weight: 600; }
  .dash-tabs a[aria-current="page"] { color: var(--ink); border-bottom-color: var(--accent); }
  .dash-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(158px, 1fr)); gap: 12px; }
  .dash-kpi { background: var(--surface); border: 1px solid var(--line); padding: 14px 16px; }
  .dash-kpi .k { font-family: var(--font-mono); font-size: .66rem; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-soft); }
  .dash-kpi .v { font-family: var(--font-mono); font-size: 1.45rem; margin-top: 6px; font-variant-numeric: tabular-nums; }
  .dash-kpi .s { font-size: .78rem; color: var(--ink-soft); margin-top: 4px; line-height: 1.35; }
  .dash-kpi.is-alert { border-color: #b3261e; }
  .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-top: 16px; }
  .dash-card { background: var(--surface); border: 1px solid var(--line); padding: 16px 18px; }
  .dash-card h2 { font-size: 1.05rem; margin: 0 0 10px; }
  .dash-bar { height: 8px; background: var(--surface-2); border: 1px solid var(--line); margin: 10px 0 8px; overflow: hidden; }
  .dash-bar span { display: block; height: 100%; background: var(--accent); }
  .dash-bar.is-low span { background: #b3261e; }
  .dash-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
  .dash-table th, .dash-table td { padding: 7px 8px; border-bottom: 1px solid var(--line); text-align: left; }
  .dash-table th { font-family: var(--font-mono); font-size: .66rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 400; }
  .dash-table td.num, .dash-table th.num { text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
  .dash-todo { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .dash-todo li { display: grid; grid-template-columns: 22px 1fr auto; gap: 10px; align-items: start; font-size: .9rem; }
  .dash-todo .mark { font-weight: 700; line-height: 1.4; text-align: center; }
  .dash-todo .is-done .mark { color: var(--sage, #2f6b4f); }
  .dash-todo .is-open .mark { color: var(--accent); }
  .dash-todo .hint { display: block; color: var(--ink-soft); font-size: .8rem; margin-top: 2px; }
  .dash-todo a { font-size: .8rem; color: var(--accent); white-space: nowrap; }
  .dash-note { font-size: .82rem; color: var(--ink-soft); }
  .dash-empty { border: 1px dashed var(--line); padding: 18px; color: var(--ink-soft); font-size: .9rem; }
  .dash-tag { display: inline-block; padding: 1px 7px; border: 1px solid var(--line); border-radius: 999px; font-family: var(--font-mono); font-size: .68rem; color: var(--ink-soft); }
  .dash-tag.is-live { border-color: var(--sage, #2f6b4f); color: var(--sage, #2f6b4f); }
  .dash-tag.is-warn { border-color: var(--accent); color: var(--accent); }
  @media (max-width: 700px) { .dash-table { display: block; max-width: 100%; overflow-x: auto; } }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'dashboard'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique · <?= h(business_presence_label(shop_presence_mode())) ?></p>
    <h1 style="font-size:2rem;margin:12px 0 6px;">Tableau de bord</h1>
    <p class="dash-note" style="margin:0 0 4px;">Bonjour — voici l’état de <?= h($shopName) ?> aujourd’hui.</p>

    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p><?php endif; ?>

    <nav class="dash-tabs" aria-label="Sections du tableau de bord">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="/admin/dashboard.php?s=<?= h($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?><?php if ($key === 'todo' && $todoRemaining): ?> <span class="dash-tag is-warn"><?= count($todoRemaining) ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'resume'): ?>
      <div class="dash-kpis">
        <div class="dash-kpi">
          <div class="k">Ventes · 30 jours</div>
          <div class="v"><?= h(format_cents($sales30['total'])) ?></div>
          <div class="s"><?= (int) $sales30['n'] ?> commande<?= $sales30['n'] !== 1 ? 's' : '' ?><?= $sales30['n'] ? ' · panier moyen ' . h(format_cents($avg30)) : '' ?></div>
        </div>
        <div class="dash-kpi<?= $toPrepare > 0 ? ' is-alert' : '' ?>">
          <div class="k">À préparer</div>
          <div class="v"><?= (int) $toPrepare ?></div>
          <div class="s">commande<?= $toPrepare !== 1 ? 's' : '' ?> payée<?= $toPrepare !== 1 ? 's' : '' ?> à expédier ou remettre</div>
        </div>
        <div class="dash-kpi">
          <div class="k">Visites · 30 jours</div>
          <div class="v"><?= $visits ? (int) $visits['d30']['visitors'] : '—' ?></div>
          <div class="s"><?= $visits ? 'visiteurs uniques · ' . (int) $visits['d30']['views'] . ' pages vues' : 'mesure non activée' ?></div>
        </div>
        <?php if (ai_shop_enabled()): ?>
        <div class="dash-kpi<?= $aiQuota && $aiQuota['blocked'] ? ' is-alert' : '' ?>">
          <div class="k"><?= $aiQuota ? 'IA restante' : ($aiBalance ? 'Solde IA' : 'IA ce mois') ?></div>
          <div class="v"><?= $aiQuota ? ai_format_eur($aiQuota['available']) : ($aiBalance ? ai_format_eur($aiBalance['remaining']) : ai_format_eur($aiMonth)) ?></div>
          <div class="s"><?= $aiQuota ? h(ai_format_eur($aiQuota['month_spent'])) . ' consommés ce mois' : ($aiBalance ? 'solde déclaré' : 'consommé ce mois') ?></div>
        </div>
        <?php endif; ?>
        <div class="dash-kpi">
          <div class="k">Pièces en ligne</div>
          <div class="v"><?= (int) $onlineProducts ?></div>
          <div class="s">sur <?= (int) $totalProducts ?> pièce<?= $totalProducts > 1 ? 's' : '' ?> au catalogue</div>
        </div>
        <div class="dash-kpi">
          <div class="k">Essais en cours</div>
          <div class="v"><?= count($trials) ?></div>
          <div class="s"><?php
            $soon = array_filter($trials, static fn (array $m): bool => $m['days'] !== null && $m['days'] <= 3);
            echo $trials ? ($soon ? 'dont ' . count($soon) . ' se terminant sous 3 jours' : 'module' . (count($trials) > 1 ? 's' : '') . ' payant' . (count($trials) > 1 ? 's' : '') . ' en essai') : 'aucun';
          ?></div>
        </div>
      </div>

      <div class="dash-grid">
        <div class="dash-card">
          <h2>À faire avant la mise en ligne</h2>
          <div class="dash-bar<?= $todoDone === 0 ? ' is-low' : '' ?>"><span style="width:<?= (int) round($todoDone / max(1, count($todo)) * 100) ?>%"></span></div>
          <p class="dash-note"><?= (int) $todoDone ?> / <?= count($todo) ?> étapes terminées</p>
          <?php if ($todoRemaining): ?>
            <ul class="dash-todo">
              <?php foreach (array_slice($todoRemaining, 0, 3) as $item): ?>
                <li class="is-open"><span class="mark">○</span><span><?= h($item['label']) ?><span class="hint"><?= h($item['hint']) ?></span></span><a href="<?= h($item['url']) ?>"><?= h($item['cta']) ?> →</a></li>
              <?php endforeach; ?>
            </ul>
            <p style="margin:12px 0 0;"><a class="btn-small" href="/admin/dashboard.php?s=todo">Voir la liste complète →</a></p>
          <?php else: ?>
            <p class="dash-note" style="margin:0;">Tout est prêt : la boutique peut être mise en ligne.</p>
          <?php endif; ?>
        </div>

        <div class="dash-card">
          <h2>Dernières commandes</h2>
          <?php if (!$recentOrders): ?>
            <p class="dash-empty">Aucune commande pour l’instant.</p>
          <?php else: ?>
            <table class="dash-table">
              <thead><tr><th>Date</th><th>Statut</th><th class="num">Montant</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($recentOrders, 0, 5) as $o): ?>
                  <tr>
                    <td><?= h(date('d/m/Y', strtotime($o['created_at']))) ?></td>
                    <td><?= h($o['status'] === 'paid' ? 'Payée' : 'En attente') ?></td>
                    <td class="num"><?= h(format_cents((int) $o['amount_total'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p style="margin:12px 0 0;"><a class="btn-small" href="/admin/orders.php">Toutes les commandes →</a></p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($prepareOrders): ?>
        <div class="dash-card" style="margin-top:16px;">
          <h2>Commandes à préparer</h2>
          <table class="dash-table">
            <thead><tr><th>Date</th><th>Client</th><th class="num">Montant</th></tr></thead>
            <tbody>
              <?php foreach ($prepareOrders as $o): ?>
                <tr><td><?= h(date('d/m/Y', strtotime($o['created_at']))) ?></td><td><?= h($o['email'] ?: '—') ?></td><td class="num"><?= h(format_cents((int) $o['amount_total'])) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p style="margin:12px 0 0;"><a class="btn-small" href="/admin/orders.php">Préparer les commandes →</a></p>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'ventes'): ?>
      <div class="dash-kpis">
        <?php foreach ([['7 jours', $sales7], ['30 jours', $sales30], ['90 jours', $sales90]] as [$label, $s]): ?>
          <div class="dash-kpi">
            <div class="k"><?= h($label) ?></div>
            <div class="v"><?= h(format_cents($s['total'])) ?></div>
            <div class="s"><?= (int) $s['n'] ?> commande<?= $s['n'] !== 1 ? 's' : '' ?> payée<?= $s['n'] !== 1 ? 's' : '' ?><?= $s['n'] ? ' · panier moyen ' . h(format_cents(intdiv($s['total'], $s['n']))) : '' ?></div>
          </div>
        <?php endforeach; ?>
        <div class="dash-kpi<?= $toPrepare > 0 ? ' is-alert' : '' ?>">
          <div class="k">À préparer</div>
          <div class="v"><?= (int) $toPrepare ?></div>
          <div class="s">commandes payées non expédiées</div>
        </div>
        <div class="dash-kpi">
          <div class="k">Paniers en cours</div>
          <div class="v"><?= (int) $pendingCheckouts ?></div>
          <div class="s">paiements commencés sur 24 h</div>
        </div>
      </div>

      <?php if ($prepareOrders): ?>
        <div class="dash-card" style="margin-top:16px;">
          <h2>À préparer maintenant</h2>
          <table class="dash-table">
            <thead><tr><th>Date</th><th>Client</th><th>Adresse</th><th class="num">Montant</th></tr></thead>
            <tbody>
              <?php foreach ($prepareOrders as $o): ?>
                <tr>
                  <td><?= h(date('d/m/Y', strtotime($o['created_at']))) ?></td>
                  <td><?= h($o['email'] ?: '—') ?></td>
                  <td><?= h(mb_strimwidth((string) ($o['shipping_address'] ?? ''), 0, 60, '…')) ?: 'Retrait' ?></td>
                  <td class="num"><?= h(format_cents((int) $o['amount_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p style="margin:12px 0 0;"><a class="btn-small" href="/admin/orders.php">Gérer les commandes →</a></p>
        </div>
      <?php endif; ?>

      <div class="dash-card" style="margin-top:16px;">
        <h2>Toutes les dernières commandes</h2>
        <?php if (!$recentOrders): ?>
          <p class="dash-empty">Aucune commande pour l’instant.</p>
        <?php else: ?>
          <table class="dash-table">
            <thead><tr><th>Date</th><th>Client</th><th>Statut</th><th>Livraison</th><th class="num">Montant</th></tr></thead>
            <tbody>
              <?php foreach ($recentOrders as $o): ?>
                <tr>
                  <td><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
                  <td><?= h($o['email'] ?: '—') ?></td>
                  <td><?= h($o['status'] === 'paid' ? 'Payée' : 'En attente') ?></td>
                  <td><?= h($o['status'] === 'paid' ? fulfillment_label((string) $o['fulfillment_status']) : '—') ?></td>
                  <td class="num"><?= h(format_cents((int) $o['amount_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <p class="dash-note" style="margin:12px 0 0;">Les paniers en cours sont des paiements commencés mais non terminés : ils ne comptent pas dans le chiffre d’affaires.</p>
      </div>

    <?php elseif ($tab === 'visites'): ?>
      <?php if ($visits): ?>
        <div class="dash-kpis">
          <div class="dash-kpi"><div class="k">Visiteurs · 7 jours</div><div class="v"><?= (int) $visits['d7']['visitors'] ?></div><div class="s"><?= (int) $visits['d7']['views'] ?> pages vues</div></div>
          <div class="dash-kpi"><div class="k">Visiteurs · 30 jours</div><div class="v"><?= (int) $visits['d30']['visitors'] ?></div><div class="s"><?= (int) $visits['d30']['views'] ?> pages vues</div></div>
        </div>
        <div class="dash-grid">
          <div class="dash-card">
            <h2>D’où viennent les visiteurs</h2>
            <?php if (!$visits['sources']): ?><p class="dash-empty">Pas encore de visite externe enregistrée.</p><?php else: ?>
              <table class="dash-table">
                <thead><tr><th>Source</th><th class="num">Visiteurs</th><th class="num">Pages</th></tr></thead>
                <tbody><?php foreach ($visits['sources'] as $s): ?><tr><td><?= h($s['source']) ?></td><td class="num"><?= (int) $s['visitors'] ?></td><td class="num"><?= (int) $s['views'] ?></td></tr><?php endforeach; ?></tbody>
              </table>
            <?php endif; ?>
          </div>
          <div class="dash-card">
            <h2>Pièces les plus vues</h2>
            <?php if (!$visits['products']): ?><p class="dash-empty">Pas encore de fiche consultée.</p><?php else: ?>
              <table class="dash-table">
                <thead><tr><th>Pièce</th><th class="num">Vues</th></tr></thead>
                <tbody><?php foreach ($visits['products'] as $p): ?><tr><td><?= h($p['name']) ?></td><td class="num"><?= (int) $p['views'] ?></td></tr><?php endforeach; ?></tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
        <p style="margin:16px 0 0;"><a class="btn-small" href="<?= h(plugin_page_url('tableau-de-bord', 'index')) ?>">Voir le détail des visites →</a></p>
        <p class="dash-note" style="margin:8px 0 0;">Mesure sans cookie : l’adresse IP et le navigateur ne sont jamais conservés, et vos propres visites (connecté à l’administration) ne comptent pas.</p>
      <?php else: ?>
        <div class="dash-empty">
          <p style="margin:0 0 10px;">Le suivi des visites n’est pas encore actif. Il s’active avec {le ext} <strong>Tableau de bord</strong> (essai gratuit), qui mesure aussi les pièces les plus vues et les liens de campagne.</p>
          <a class="btn btn-primary" href="/admin/plugins.php">Activer le suivi des visites →</a>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'ia'): ?>
      <div class="dash-kpis">
        <div class="dash-kpi">
          <div class="k">Dépensé aujourd’hui</div>
          <div class="v"><?= ai_format_eur($aiToday) ?></div>
          <div class="s">estimation au barème en vigueur</div>
        </div>
        <div class="dash-kpi">
          <div class="k">Dépensé ce mois</div>
          <div class="v"><?= ai_format_eur($aiMonth) ?></div>
          <div class="s"><?= h(gmdate('m/Y')) ?></div>
        </div>
        <div class="dash-kpi<?= $aiQuota && $aiQuota['blocked'] ? ' is-alert' : '' ?>">
          <div class="k"><?= $aiQuota ? 'Quota restant' : ($aiBalance ? 'Solde déclaré' : 'Crédit disponible') ?></div>
          <div class="v"><?= $aiQuota ? ai_format_eur($aiQuota['available']) : ($aiBalance ? ai_format_eur($aiBalance['remaining']) : '—') ?></div>
          <div class="s"><?= $aiQuota ? 'sur ' . h(ai_format_eur($aiQuota['quota'])) . ' inclus ce mois' : 'aucun crédit déclaré' ?></div>
        </div>
      </div>

      <?php if ($aiQuota): ?>
        <div class="dash-card" style="margin-top:16px;<?= $aiQuota['blocked'] ? 'border-color:#b3261e;' : '' ?>">
          <h2>Quota d’IA gratuite</h2>
          <div class="dash-bar<?= $aiPct >= 90 ? ' is-low' : '' ?>"><span style="width:<?= (int) $aiPct ?>%"></span></div>
          <p class="dash-note" style="margin:0;"><?= h(ai_format_eur($aiQuota['month_spent'])) ?> consommés sur <?= h(ai_format_eur($aiQuota['quota'])) ?> (<?= (int) $aiPct ?> %).<?= $aiQuota['credit_left'] > 0 ? ' Crédit racheté restant : ' . h(ai_format_eur($aiQuota['credit_left'])) . '.' : '' ?></p>
          <?php if ($aiQuota['blocked']): ?><p class="publish-status" data-kind="error" style="margin:12px 0 0;">Quota épuisé : les générations IA sont suspendues jusqu’au mois prochain ou jusqu’à un rachat de crédit.</p><?php endif; ?>
        </div>
      <?php elseif ($aiBalance): ?>
        <div class="dash-card" style="margin-top:16px;">
          <h2>Crédit prépayé</h2>
          <div class="dash-bar<?= $aiBalance['remaining'] <= 0 ? ' is-low' : '' ?>"><span style="width:<?= (int) min(100, round(max(0, $aiBalance['remaining']) / max(0.01, $aiBalance['amount']) * 100)) ?>%"></span></div>
          <p class="dash-note" style="margin:0;"><?= h(ai_format_eur($aiBalance['spent'])) ?> dépensés sur <?= h(ai_format_eur($aiBalance['amount'])) ?> déclarés.</p>
        </div>
      <?php endif; ?>

      <p style="margin:16px 0 0;"><a class="btn-small" href="/admin/ai-usage.php">Voir le détail par appel et par pièce →</a></p>

    <?php elseif ($tab === 'modules'): ?>
      <?php if (!$modules): ?>
        <div class="dash-empty">
          <p style="margin:0 0 10px;">Aucun{e} {ext} payant{e} {?activé|activée} pour l’instant.</p>
          <a class="btn btn-primary" href="/admin/plugins.php">Découvrir {les exts} →</a>
        </div>
      <?php else: ?>
        <div class="dash-card">
          <h2>{Exts} et essais</h2>
          <table class="dash-table">
            <thead><tr><th>{Ext}</th><th>État</th><th>Temps restant</th></tr></thead>
            <tbody>
              <?php foreach ($modules as $m): ?>
                <tr>
                  <td><?= h($m['name']) ?></td>
                  <td><span class="dash-tag<?= $m['entitled'] ? ' is-live' : ($m['status'] === 'trial' ? ' is-warn' : '') ?>"><?= h($m['label']) ?></span><?= $m['entitled'] ? '' : ' <span class="dash-note">· accès suspendu</span>' ?></td>
                  <td><?php
                    if ($m['status'] === 'trial' && $m['days'] !== null) {
                        echo $m['days'] <= 0 ? 'Se termine aujourd’hui' : (int) $m['days'] . ' jour' . ($m['days'] > 1 ? 's' : '') . ' restant' . ($m['days'] > 1 ? 's' : '');
                    } elseif ($m['status'] === 'active') {
                        echo 'Abonnement en cours';
                    } elseif ($m['status'] === 'expired') {
                        echo 'Essai terminé';
                    } else {
                        echo '—';
                    }
                  ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p style="margin:14px 0 0;"><a class="btn-small" href="/admin/plugins.php">Gérer {les exts} et abonnements →</a></p>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="dash-card">
        <h2>Avant la mise en ligne</h2>
        <div class="dash-bar<?= $todoDone === count($todo) ? '' : ' is-low' ?>"><span style="width:<?= (int) round($todoDone / max(1, count($todo)) * 100) ?>%"></span></div>
        <p class="dash-note"><?= (int) $todoDone ?> / <?= count($todo) ?> étapes terminées<?= $todoRemaining ? ' — ' . count($todoRemaining) . ' à finir' : '' ?>.</p>
        <ul class="dash-todo" style="margin-top:14px;">
          <?php foreach ($todo as $item): ?>
            <li class="<?= $item['done'] ? 'is-done' : 'is-open' ?>">
              <span class="mark"><?= $item['done'] ? '✓' : '○' ?></span>
              <span><?= h($item['label']) ?><span class="hint"><?= h($item['hint']) ?></span></span>
              <?php if (!$item['done']): ?><a href="<?= h($item['url']) ?>"><?= h($item['cta']) ?> →</a><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <p style="margin:16px 0 0;"><a class="btn-small" href="/index.php" target="_blank" rel="noopener">Voir la boutique en ligne →</a></p>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
