<?php
// Tableau de bord d'une galerie : fréquentation de la page publique, promos, activité des commerces membres, prospects.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/promo.php';
require_once PORTAIL_ROOT . '/includes/commissions.php';

$days = (int) ($_GET['d'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
$from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
$stats = ga_stats($gaSlug, $days);
$catalogue = gallery_catalogue($gaGallery, $gaOrigin);
$promo = promo_stats($from, gmdate('Y-m-d'), 'placement', ['placement' => 'galerie:' . $gaSlug]);
$promoImp = array_sum(array_column($promo, 'impressions'));
$promoClk = array_sum(array_column($promo, 'clicks'));
$promoTop = promo_stats($from, gmdate('Y-m-d'), 'ref', ['placement' => 'galerie:' . $gaSlug]);
$signups = ga_signups($gaSlug);
$pros = ga_prospects($gaSlug);
$subs = ga_subs($gaSlug);
$subsOk = count(array_filter($subs, static fn ($s) => !empty($s['confirmed'])));
$byStatus = array_count_values(array_column($pros, 'status'));

$cmConfig = cm_config();
$cmOn = $cmConfig['since'] > 0 && (cm_rate($gaSlug, 'sales_gallery_pct') > 0 || cm_rate($gaSlug, 'subs_gallery_pct') > 0);
if ($cmOn) {
    cm_sync();
    $cmAll = cm_summary(strtotime($from . ' 00:00:00'), time())['galleries'][$gaSlug] ?? ['sales' => 0, 'subs' => 0, 'period' => 0, 'due' => 0, 'due_collected' => 0, 'due_tracked' => 0, 'paid' => 0];
}
ga_head('Tableau de bord');
$max = max(1, ...array_column($stats['series'], 'views'));
$n = count($stats['series']);
$bw = 900 / $n;
?>
    <div class="row"><?php foreach ([7, 30, 90, 365] as $d): ?><a class="btn small<?= $d === $days ? ' btn-primary' : '' ?>" href="?<?= $limited ? '' : 'g=' . e($gaSlug) . '&amp;' ?>d=<?= $d ?>"><?= $d === 365 ? '12 mois' : $d . ' jours' ?></a><?php endforeach; ?></div>

    <div class="kpis">
      <div class="kpi"><b><?= (int) $stats['visitors'] ?></b><span>visiteurs</span></div>
      <div class="kpi"><b><?= (int) $stats['views'] ?></b><span>pages vues</span></div>
      <div class="kpi"><b><?= count($catalogue['shops']) ?></b><span>commerces affichés</span></div>
      <div class="kpi"><b><?= count($catalogue['products']) ?></b><span>pièces en ligne</span></div>
      <div class="kpi"><b><?= (int) $promoImp ?></b><span>affichages de promos</span></div>
      <div class="kpi"><b><?= (int) $promoClk ?></b><span>clics sur les promos</span></div>
    </div>
    <?php if ($catalogue['skipped']): ?><p class="flash" data-kind="error">Commerces injoignables, absents du catalogue : <?= e(implode(', ', $catalogue['skipped'])) ?>.</p><?php endif; ?>

    <section class="gcard"><h2>Fréquentation de la page publique</h2>
      <?php if ((int) $stats['views'] === 0): ?>
        <div class="chart-empty">
          <strong>Aucune visite sur <?= $days === 365 ? 'les 12 derniers mois' : 'les ' . $days . ' derniers jours' ?></strong>
          <span><?= (!empty($gaGallery['published']) && empty($gaGallery['paused'])) ? 'Les pages vues apparaîtront ici dès les premières visites.' : 'La galerie n’est pas publiée : sa page publique ne reçoit pas encore de visiteurs.' ?></span>
        </div>
      <?php else: ?>
      <div class="chart">
        <svg viewBox="0 0 900 170" role="img" aria-label="Pages vues par jour">
          <line x1="0" y1="160" x2="900" y2="160" stroke="currentColor" opacity=".16"/>
          <line x1="0" y1="85" x2="900" y2="85" stroke="currentColor" opacity=".08" stroke-dasharray="3 4"/>
          <?php foreach ($stats['series'] as $i => $s): $h = (int) round($s['views'] / $max * 150); ?>
            <rect x="<?= round($i * $bw + 1, 1) ?>" y="<?= 160 - $h ?>" width="<?= max(1, round($bw - 2, 1)) ?>" height="<?= $h ?>" rx="<?= $bw > 8 ? 2 : 0 ?>" fill="currentColor" opacity=".62"><title><?= e($s['day']) ?> : <?= (int) $s['views'] ?> pages vues, <?= (int) $s['visitors'] ?> visiteurs</title></rect>
          <?php endforeach; ?>
        </svg>
      </div>
      <?php endif; ?>
      <p class="meta">Mesure sans cookie : un visiteur n'est qu'une empreinte qui change chaque jour (ni adresse IP ni navigateur conservés). Les visites des administrateurs et des robots ne comptent pas. Les chiffres démarrent à la mise en service de cette page.</p>
    </section>

    <section class="gcard"><h2>Commerces membres</h2>
      <table class="t"><thead><tr><th>Commerce</th><th class="num">Pièces en ligne</th><th class="num">Commandes (<?= $days ?> j)</th><th class="num">Chiffre d'affaires</th></tr></thead><tbody>
      <?php foreach ($gaGallery['members'] as $m):
          $key = $m['type'] === 'local' ? $m['slug'] : 'r' . substr(md5($m['url']), 0, 8);
          $shop = $catalogue['shops'][$key] ?? null;
          $act = $m['type'] === 'local' ? ga_shop_activity($m['slug'], $from) : null; ?>
        <tr><td><?= e($shop['name'] ?? ($m['name'] ?? $key)) ?> <small class="meta"><?= $m['type'] === 'local' ? '' : '(autre serveur)' ?><?= $shop === null ? ' — injoignable' : '' ?></small></td>
          <td class="num"><?= $shop ? (int) $shop['count'] : '—' ?></td>
          <td class="num"><?= $act ? $act[0] : '—' ?></td><td class="num"><?= $act ? e(ga_eur($act[1])) : '—' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$gaGallery['members']): ?><tr><td colspan="4" class="meta">Aucun commerce dans la galerie pour le moment.</td></tr><?php endif; ?>
      </tbody></table>
      <p class="meta">Commandes et chiffre d'affaires : boutiques de ce serveur seulement (celles d'un autre serveur ne partagent que leur catalogue).</p>
    </section>

    <?php if ($promoTop): ?>
    <section class="gcard"><h2>Promos les plus vues</h2>
      <table class="t"><thead><tr><th>Pièce</th><th>Commerce</th><th class="num">Affichages</th><th class="num">Clics</th><th class="num">Taux</th></tr></thead><tbody>
      <?php foreach (array_slice($promoTop, 0, 8) as $p): ?><tr><td><?= e($p['ref']) ?></td><td><?= e($p['shop']) ?></td><td class="num"><?= (int) $p['impressions'] ?></td><td class="num"><?= (int) $p['clicks'] ?></td><td class="num"><?= e((string) $p['ctr']) ?> %</td></tr><?php endforeach; ?>
      </tbody></table>
    </section>
    <?php endif; ?>

    <?php if ($cmOn): ?>
    <section class="gcard"><h2>Mes commissions</h2>
      <div class="kpis">
        <div class="kpi"><b><?= e(ga_eur($cmAll['period'])) ?></b><span>commissions des <?= $days ?> derniers jours</span></div>
        <div class="kpi"><b><?= e(ga_eur($cmAll['due'])) ?></b><span>à recevoir (toutes périodes)</span></div>
        <div class="kpi"><b><?= e(ga_eur($cmAll['paid'])) ?></b><span>déjà versé</span></div>
      </div>
      <p class="meta">Taux : <?= e((string) cm_rate($gaSlug, 'sales_gallery_pct')) ?> % des ventes de vos commerces (pièces hors frais de port), <?= e((string) cm_rate($gaSlug, 'subs_gallery_pct')) ?> % des abonnements des commerces inscrits via votre galerie. Versements décidés par l'exploitant de la plateforme.</p>
    </section>
    <?php endif; ?>

    <section class="gcard"><h2>Prospects</h2>
      <div class="kpis">
        <div class="kpi"><b><?= count($signups) ?></b><span>inscriptions en cours</span></div>
        <div class="kpi"><b><?= count($pros) ?></b><span>commerces à recruter (<?= (int) ($byStatus['inscrit'] ?? 0) ?> inscrits)</span></div>
        <div class="kpi"><b><?= $subsOk ?></b><span>visiteurs abonnés (<?= count($subs) - $subsOk ?> à confirmer)</span></div>
      </div>
      <p><a class="btn btn-primary" href="/portail/galerie-prospects.php<?= $limited ? '' : '?g=' . e($gaSlug) ?>">Gérer et relancer</a></p>
    </section>
<?php ga_foot();
