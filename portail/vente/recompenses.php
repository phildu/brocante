<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$myId = fv_effective_member_id();
$showTeam = $ctx['manager'];
$period = fv_period();
$from = $period['from'];
$pdo = fv_db();

$thresholds = fv_tier_thresholds();
$labels = fv_tier_labels();
$multipliers = fv_tier_multipliers();
$catalog = fv_reward_catalog();

$leaders = $showTeam ? fv_leaderboard($from) : [];
$myStats = $member ? fv_member_stats((int) $member['id'], $from) : null;
$lifetime = $myStats ? (int) $myStats['points_lifetime'] : 0;
$tier = $myStats ? $myStats['tier'] : fv_tier(0);

$teamPoints = 0;
$teamShops = 0;
$teamMrr = 0;
$teamCommission = 0;
foreach ($leaders as $leader) {
    $teamPoints += (int) $leader['points'];
    $teamShops += (int) $leader['shops'];
    $teamMrr += (int) $leader['amount_cents'];
    $teamCommission += (int) $leader['commission_cents'];
}

$breakdown = [];
if ($myId > 0) {
    $st = $pdo->prepare('SELECT kind, COUNT(*) AS n, COALESCE(SUM(points),0) AS pts FROM activities WHERE member_id = ? AND occurred_at >= ? GROUP BY kind ORDER BY pts DESC');
    $st->execute([$myId, $from . ' 00:00:00']);
    $breakdown = $st->fetchAll();
}
$recentPoints = [];
if ($myId > 0) {
    $st = $pdo->prepare('SELECT a.*, p.business AS prospect_business, p.name AS prospect_name FROM activities a LEFT JOIN prospects p ON p.id = a.prospect_id WHERE a.member_id = ? AND a.points > 0 ORDER BY a.occurred_at DESC, a.id DESC LIMIT 10');
    $st->execute([$myId]);
    $recentPoints = $st->fetchAll();
}

$baseCommercial = fv_setting_int('commission_commercial', 6000);
$baseAmbassadeur = fv_setting_int('commission_ambassadeur', 4000);
$rate = max(0, min(100, fv_setting_int('commission_taux', 0)));
$exampleShops = 2;
$exampleRevenue = $exampleShops * 1990;
$exampleBase = ($member && $member['role'] === 'ambassadeur' ? $baseAmbassadeur : $baseCommercial) * $exampleShops + (int) round($exampleRevenue * $rate / 100);
$exampleCommission = (int) round($exampleBase * $tier['multiplier']);

fv_layout_start('Récompenses', [
    'eyebrow' => 'Points, paliers et primes · ' . $period['label'],
    'desc' => 'Chaque appel, chaque rendez-vous et chaque boutique créée rapporte des points. Les points font monter de palier, et chaque palier augmente la commission.',
    'actions' => '<a class="fv-btn fv-btn-sm" href="codes.php">' . fv_icon('tag', 14) . ' Codes & filleuls</a>'
        . ($showTeam ? '<a class="fv-btn fv-btn-sm" href="reglages.php">' . fv_icon('settings', 14) . ' Barème</a>' : ''),
]);
?>

<div class="fv-toolbar">
  <?= fv_period_switch($period) ?>
  <span class="fv-sub" style="margin-left:auto;">Palier calculé sur les points cumulés, jamais remis à zéro</span>
</div>

<?php if ($myStats): ?>
<section class="fv-card fv-tier-card" data-t="<?= e($tier['key']) ?>">
  <div class="fv-card-head">
    <div>
      <h2>Palier <?= e($tier['label']) ?> <span class="fv-badge" data-t="<?= e($tier['key']) ?>">×<?= number_format($tier['multiplier'], 2, ',', '') ?></span></h2>
      <p><?= (int) round($tier['multiplier'] * 100) - 100 ?> % de bonus sur la commission<?= $tier['next_label'] ? ' · prochain palier : ' . e($tier['next_label']) : ' · palier maximum atteint' ?></p>
    </div>
    <span class="fv-sub"><?= (int) $lifetime ?> pts cumulés</span>
  </div>
  <div class="fv-card-body">
    <div class="fv-progress<?= $tier['key'] === 'platine' ? ' is-ok' : '' ?>"><span style="width:<?= (int) $tier['progress'] ?>%"></span></div>
    <?php if ($tier['next_label']): ?><p class="fv-sub" style="margin-top:-4px;">Encore <?= max(0, (int) $tier['next_min'] - $lifetime) ?> pts pour passer <?= e($tier['next_label']) ?>.</p><?php endif; ?>
    <div class="fv-kpis">
      <div class="fv-kpi"><span class="n"><?= (int) $myStats['points'] ?></span><span class="l">Points · <?= e($period['label']) ?></span><span class="s"><?= (int) $myStats['calls'] ?> appel<?= (int) $myStats['calls'] > 1 ? 's' : '' ?> · <?= (int) $myStats['visits'] ?> visite<?= (int) $myStats['visits'] > 1 ? 's' : '' ?></span></div>
      <div class="fv-kpi"><span class="n"><?= (int) $myStats['shops'] ?></span><span class="l">Boutiques créées</span><span class="s"><?= (int) $myStats['rdvs'] ?> rendez-vous réalisés</span></div>
      <div class="fv-kpi"><span class="n"><?= e(fv_money((int) $myStats['amount_cents'])) ?></span><span class="l">Revenu mensuel apporté</span><span class="s">commission incluse</span></div>
      <div class="fv-kpi is-accent"><span class="n"><?= e(fv_money((int) $myStats['commission_cents'])) ?></span><span class="l">Commission estimée</span><span class="s">base + bonus palier <?= e($tier['label']) ?></span></div>
    </div>
  </div>
</section>
<?php elseif ($showTeam): ?>
<div class="fv-kpis">
  <div class="fv-kpi"><span class="n"><?= count($leaders) ?></span><span class="l">Membres actifs</span><span class="s">commerciaux et ambassadeurs</span></div>
  <div class="fv-kpi"><span class="n"><?= $teamPoints ?></span><span class="l">Points · <?= e($period['label']) ?></span><span class="s">toutes activités confondues</span></div>
  <div class="fv-kpi is-accent"><span class="n"><?= $teamShops ?></span><span class="l">Boutiques créées</span><span class="s"><?= e(fv_money($teamMrr)) ?> de revenu mensuel</span></div>
  <div class="fv-kpi"><span class="n"><?= e(fv_money($teamCommission)) ?></span><span class="l">Commissions estimées</span><span class="s">sur la période et les paliers actuels</span></div>
</div>
<?php endif; ?>

<div class="fv-grid fv-grid-side">
  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head">
        <div><h2>Les paliers</h2><p>Le palier se calcule sur les points cumulés depuis le premier jour.</p></div>
        <?php if ($member): ?><span class="fv-badge" data-t="<?= e($tier['key']) ?>">Vous : <?= e($tier['label']) ?></span><?php endif; ?>
      </div>
      <div class="fv-card-body">
        <div class="fv-ladder">
          <?php foreach ($thresholds as $key => $min): ?>
            <?php $bonus = (int) round(($multipliers[$key] - 1) * 100); ?>
            <div class="fv-step<?= $member && $tier['key'] === $key ? ' is-current' : '' ?>">
              <b><?= e($labels[$key]) ?></b>
              <span><?= (int) $min ?> pts<?= $key === 'bronze' ? ' et +' : '' ?></span>
              <span><?= $bonus > 0 ? '+' . $bonus . ' % commission' : 'commission de base' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="fv-hint">Le bonus s’applique à la commission complète : prime par boutique, pourcentage du revenu mensuel et éventuelles primes de campagne.</p>
      </div>
    </section>

    <?php if ($showTeam): ?>
      <section class="fv-card">
        <div class="fv-card-head">
          <div><h2>Classement de l’équipe <small class="fv-sub"><?= e($period['label']) ?></small></h2><p>Points de la période, palier cumulé et commission estimée.</p></div>
          <a class="fv-btn fv-btn-sm" href="equipe.php">Gérer l’équipe</a>
        </div>
        <div class="fv-table-wrap">
          <?php if (!$leaders): ?>
            <div class="fv-empty"><b>Aucun membre actif</b>Créez un commercial ou un ambassadeur depuis « Équipe &amp; rôles ».</div>
          <?php else: ?>
            <table class="fv-table">
              <thead><tr><th>Membre</th><th>Palier</th><th>Points période</th><th>Boutiques</th><th class="right">Commission</th></tr></thead>
              <tbody>
                <?php foreach ($leaders as $i => $leader): $calls = (int) $leader['calls']; $visits = (int) $leader['visits']; ?>
                  <tr>
                    <td>
                      <b><?= $i + 1 ?>. <?= e($leader['name']) ?></b>
                      <span class="sub"><?= e(fv_role_label($leader['role'])) ?><?= (int) $leader['goal_month'] > 0 ? ' · objectif ' . (int) $leader['goal_month'] . ' / mois' : '' ?></span>
                    </td>
                    <td><span class="fv-badge" data-t="<?= e($leader['tier']['key']) ?>"><?= e($leader['tier']['label']) ?></span><span class="sub"><?= (int) $leader['points_lifetime'] ?> pts</span></td>
                    <td><span class="fv-mono"><?= (int) $leader['points'] ?></span><span class="sub"><?= $calls ?> appel<?= $calls > 1 ? 's' : '' ?> · <?= $visits ?> visite<?= $visits > 1 ? 's' : '' ?></span></td>
                    <td><span class="fv-mono"><?= (int) $leader['shops'] ?></span><span class="sub"><?= (int) $leader['rdvs'] ?> RDV</span></td>
                    <td class="right"><b class="fv-mono"><?= e(fv_money((int) $leader['commission_cents'])) ?></b><span class="sub"><?= e(fv_money((int) $leader['amount_cents'])) ?> / mois</span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
    <?php else: ?>
      <section class="fv-card">
        <div class="fv-card-head"><div><h2>D’où viennent vos points</h2><p>Détail de la période · <?= e($period['label']) ?></p></div></div>
        <div class="fv-card-body">
          <?php if (!$breakdown): ?>
            <div class="fv-empty"><b>Pas encore de points sur la période</b>Consignez un appel ou une visite pour lancer le compteur.</div>
          <?php else: ?>
            <?php $maxPts = max(1, max(array_map(static fn ($row) => (int) $row['pts'], $breakdown))); ?>
            <?php foreach ($breakdown as $row): ?>
              <div class="fv-funnel-row">
                <span><?= e(fv_kind_label($row['kind'])) ?> <small class="fv-sub">×<?= (int) $row['n'] ?></small></span>
                <span class="track"><span class="fill" style="width:<?= (int) round((int) $row['pts'] / $maxPts * 100) ?>%"></span></span>
                <span class="n"><?= (int) $row['pts'] ?> pts</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>

      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Derniers points gagnés</h2></div><a class="fv-btn fv-btn-sm" href="prospects.php">Prospects</a></div>
        <div class="fv-card-body" style="padding-top:4px;">
          <?php if (!$recentPoints): ?>
            <div class="fv-empty"><b>Rien pour le moment</b>Les points apparaissent dès le premier échange consigné.</div>
          <?php else: ?>
            <div class="fv-list">
              <?php foreach ($recentPoints as $a): ?>
                <div class="fv-list-item">
                  <span class="fv-badge" data-s="interesse">+<?= (int) $a['points'] ?> pts</span>
                  <div class="t">
                    <b><?= e(fv_kind_label($a['kind'])) ?><?= $a['outcome'] !== '' ? ' · ' . e(fv_outcome_label($a['kind'], $a['outcome'])) : '' ?></b>
                    <span><?= e(fv_datetime($a['occurred_at'])) ?><?php if ((int) $a['prospect_id'] > 0): ?> · <?= e(!empty($a['prospect_business']) ? $a['prospect_business'] : $a['prospect_name']) ?><?php endif; ?></span>
                  </div>
                  <div class="r"><?= $a['notes'] !== '' ? e(mb_strimwidth($a['notes'], 0, 60, '…')) : '' ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Catalogue des récompenses</h2><p>Ce que chaque palier débloque.</p></div></div>
      <div class="fv-card-body" style="gap:10px;">
        <?php if (!$catalog): ?>
          <div class="fv-empty"><b>Catalogue vide</b>La direction peut le renseigner dans les réglages.</div>
        <?php else: ?>
          <?php foreach ($catalog as $reward): ?>
            <?php $catalogTier = null; foreach ($labels as $key => $label) { if ($reward['title'] !== '' && mb_strtolower($reward['title']) === mb_strtolower($label)) { $catalogTier = $key; break; } } ?>
            <div style="border:1px solid var(--fv-line);border-radius:9px;padding:10px 12px;">
              <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
                <b><?= e($reward['title'] !== '' ? $reward['title'] : 'Récompense') ?></b>
                <?php if ($catalogTier): ?><span class="fv-badge" data-t="<?= e($catalogTier) ?>"><?= e($thresholds[$catalogTier]) ?> pts</span><?php endif; ?>
              </div>
              <p class="fv-sub" style="margin-top:4px;"><?= e($reward['desc']) ?></p>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Comment gagner des points</h2><p>Chaque échange consigné compte, sans exception.</p></div></div>
      <div class="fv-table-wrap">
        <table class="fv-table">
          <thead><tr><th>Action</th><th class="right">Points</th></tr></thead>
          <tbody>
            <tr><td>Appel passé</td><td class="right fv-mono"><?= fv_setting_int('points_appel', 2) ?></td></tr>
            <tr><td>Appel qualifié <span class="sub">intéressé, RDV, refus argumenté, faux numéro</span></td><td class="right fv-mono"><?= fv_setting_int('points_appel_qualifie', 5) ?></td></tr>
            <tr><td>Rendez-vous pris</td><td class="right fv-mono"><?= fv_setting_int('points_rdv', 15) ?></td></tr>
            <tr><td>Boutique créée</td><td class="right fv-mono"><?= fv_setting_int('points_boutique', 100) ?></td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Votre commission</h2><p>Deux étages : une prime par boutique, puis un pourcentage du revenu mensuel.</p></div></div>
      <div class="fv-card-body fv-hint">
        <p><b>Commercial</b> — <?= e(fv_money($baseCommercial)) ?> par boutique créée.</p>
        <p><b>Ambassadeur · commerçant référent</b> — <?= e(fv_money($baseAmbassadeur)) ?> par boutique créée.</p>
        <p><b>Complément</b> — <?= $rate ?> % du revenu mensuel apporté par les boutiques filleules.</p>
        <p><b>Bonus de palier</b> — ×<?= number_format($tier['multiplier'], 2, ',', '') ?> sur l’ensemble.</p>
        <?php if ($member): ?>
          <div class="fv-readonly">Exemple : <?= $exampleShops ?> boutiques à 19,90 €/mois → <?= e(fv_money($exampleCommission)) ?> de commission (palier <?= e($tier['label']) ?>).</div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($member): ?>
      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Faire grandir le palier</h2></div></div>
        <div class="fv-card-body fv-hint">
          <p>Les points sont cumulés : une boutique créée vaut <?= fv_setting_int('points_boutique', 100) ?> pts, un rendez-vous <?= fv_setting_int('points_rdv', 15) ?>, un appel qualifié <?= fv_setting_int('points_appel_qualifie', 5) ?>.</p>
          <p>Le palier change dès le seuil atteint, sans attendre la fin du mois. La commission est recalculée avec le nouveau coefficient.</p>
          <p><a href="prospects.php">Ouvrir le carnet de prospects →</a></p>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<?php fv_layout_end(); ?>
