<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$scopeId = $ctx['member_id'];
$period = fv_period();
$from = $period['from'];
$pdo = fv_db();

$params = [];
$scopeSql = '';
if ($scopeId > 0) {
    $scopeSql = ' AND member_id = ?';
    $params[] = $scopeId;
}

$stat = $pdo->prepare('SELECT COALESCE(SUM(points),0) AS points,
    COUNT(CASE WHEN kind = "creation" THEN 1 END) AS shops,
    COUNT(CASE WHEN kind = "rdv" AND outcome = "realise" THEN 1 END) AS rdvs,
    COUNT(CASE WHEN kind = "appel" THEN 1 END) AS calls,
    COUNT(CASE WHEN kind = "visite" THEN 1 END) AS visits,
    COUNT(DISTINCT CASE WHEN kind IN ("appel","visite","email") THEN prospect_id END) AS contacted
    FROM activities WHERE occurred_at >= ?' . $scopeSql);
$stat->execute(array_merge([$from . ' 00:00:00'], $params));
$stats = $stat->fetch() ?: [];
$stats = array_map('intval', $stats + ['points' => 0, 'shops' => 0, 'rdvs' => 0, 'calls' => 0, 'visits' => 0, 'contacted' => 0]);

$red = $pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM redemptions WHERE created_at >= ?' . ($scopeId > 0 ? ' AND member_id = ?' : ''));
$red->execute(array_merge([$from . ' 00:00:00'], $params));
$mrr = (int) $red->fetchColumn();

$statusCounts = fv_prospect_count_by_status($scopeId);
$pipeline = 0;
foreach ($statusCounts as $key => $count) {
    if (!in_array($key, ['perdu', 'boutique_creee'], true)) $pipeline += $count;
}
$conversion = $stats['contacted'] > 0 ? (int) round($stats['shops'] / $stats['contacted'] * 100) : 0;
$todo = fv_todo_prospects($scopeId, 10);

$recentSql = 'SELECT a.*, p.name AS prospect_name, p.business AS prospect_business, m.name AS member_name
    FROM activities a LEFT JOIN prospects p ON p.id = a.prospect_id LEFT JOIN members m ON m.id = a.member_id
    WHERE a.occurred_at >= ?' . ($scopeId > 0 ? ' AND a.member_id = ?' : '') . '
    ORDER BY a.occurred_at DESC, a.id DESC LIMIT 12';
$recent = $pdo->prepare($recentSql);
$recent->execute(array_merge([$from . ' 00:00:00'], $params));
$recent = $recent->fetchAll();

$leaders = $ctx['manager'] ? fv_leaderboard($from) : [];
$myStats = $member ? fv_member_stats((int) $member['id'], $from) : null;
$referral = $member ? fv_referral_code((int) $member['id']) : null;
$mrrTotal = 0;
$commissionTotal = 0;
foreach ($leaders as $leader) {
    $mrrTotal += (int) $leader['amount_cents'];
    $commissionTotal += (int) $leader['commission_cents'];
}
$tier = $myStats ? $myStats['tier'] : null;
$goal = $member ? (int) $member['goal_month'] : 0;
$goalPct = $goal > 0 ? min(100, (int) round($stats['shops'] / $goal * 100)) : 0;

fv_layout_start('Tableau de bord', [
    'eyebrow' => 'Pilotage · ' . $period['label'],
    'desc' => $ctx['manager']
        ? 'Vue consolidée de l’équipe de vente : pipeline, rendez-vous, boutiques créées, CA apporté et primes estimées.'
        : 'Votre activité, vos rendez-vous et vos résultats. Tout ce qui compte pour la semaine est ici.',
    'actions' => '<a class="fv-btn fv-btn-sm" href="appels.php">' . fv_icon('phone', 15) . ' Mode appel</a><a class="fv-btn fv-btn-sm fv-btn-primary" href="prospects.php?nouveau=1">' . fv_icon('plus', 15) . ' Nouveau prospect</a>',
]);
?>

<div class="fv-toolbar">
  <?= fv_period_switch($period) ?>
  <span class="fv-sub" style="margin-left:auto;">Données locales de l’espace vente · <?= e(date('d/m/Y')) ?></span>
</div>

<div class="fv-kpis">
  <div class="fv-kpi <?= count($todo) ? 'is-warn' : '' ?>">
    <span class="n"><?= count($todo) ?></span>
    <span class="l">Actions dues</span>
    <span class="s"><?= count($todo) ? 'À traiter aujourd’hui ou en retard' : 'Rien en retard, continuez' ?></span>
  </div>
  <div class="fv-kpi">
    <span class="n"><?= $pipeline ?></span>
    <span class="l">Prospects en cours</span>
    <span class="s"><?= $statusCounts['rdv'] ?> RDV · <?= $statusCounts['interesse'] ?> intéressés</span>
  </div>
  <div class="fv-kpi is-accent">
    <span class="n"><?= $stats['shops'] ?></span>
    <span class="l">Boutiques créées</span>
    <span class="s"><?= $conversion ?> % de transformation · <?= $period['label'] ?></span>
  </div>
  <div class="fv-kpi">
    <span class="n"><?= $ctx['manager'] ? fv_money((int) $mrrTotal) : fv_money((int) $myStats['amount_cents']) ?></span>
    <span class="l">CA mensuel apporté</span>
    <span class="s"><?= $ctx['manager'] ? 'Primes estimées : ' . fv_money($commissionTotal) : 'Prime estimée : ' . fv_money((int) $myStats['commission_cents']) ?></span>
  </div>
</div>

<?php if ($member && $referral): ?>
  <div class="fv-card">
    <div class="fv-card-pad" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;">
      <div class="fv-code-hero">
        <span class="fv-eyebrow">Votre code de parrainage</span>
        <div class="fv-bigcode"><?= e($referral['code']) ?></div>
        <span class="fv-sub"><?= e(fv_code_value_label($referral)) ?> pour chaque commerçant qui crée sa boutique avec ce code · <?= (int) $referral['uses'] ?> utilisation<?= (int) $referral['uses'] > 1 ? 's' : '' ?></span>
      </div>
      <div class="fv-actions">
        <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($referral['code']) ?>"><?= fv_icon('copy', 14) ?> Copier</button>
        <a class="fv-btn fv-btn-sm fv-btn-primary" href="kits.php"><?= fv_icon('image', 14) ?> Partager</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="fv-grid fv-grid-side">
  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head">
        <div><h2>À faire maintenant</h2><p>Relances et rendez-vous arrivés à échéance.</p></div>
        <a class="fv-btn fv-btn-sm" href="tournees.php">Préparer une tournée</a>
      </div>
      <div class="fv-card-body" style="padding-top:8px;">
        <?php if (!$todo): ?>
          <div class="fv-empty"><b>Aucune action en retard</b>Ajoutez une prochaine action à chaque prospect pour ne rien laisser filer.</div>
        <?php else: ?>
          <div class="fv-list">
            <?php foreach ($todo as $p): ?>
              <div class="fv-list-item">
                <span class="fv-badge" data-s="<?= e($p['status']) ?>"><?= e(fv_status_label($p['status'])) ?></span>
                <div class="t">
                  <b><?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?></b>
                  <span><?= e(trim($p['name'] . ($p['city'] !== '' ? ' · ' . $p['city'] : ''))) ?><?= $p['owner_name'] ? ' · ' . e($p['owner_name']) : '' ?></span>
                </div>
                <div class="r">
                  <span style="color:<?= $p['next_action_at'] < fv_today() ? 'var(--fv-warn)' : 'inherit' ?>"><?= e(fv_date($p['next_action_at'])) ?></span>
                  <div class="fv-actions" style="justify-content:flex-end;margin-top:3px;">
                    <?php if ($p['phone'] !== ''): ?><a class="fv-btn fv-btn-sm" href="appels.php?id=<?= (int) $p['id'] ?>">Appeler</a><?php endif; ?>
                    <a class="fv-btn fv-btn-sm" href="prospects.php?id=<?= (int) $p['id'] ?>">Fiche</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head">
        <div><h2>Pipeline</h2><p>Répartition actuelle des prospects<?= $scopeId > 0 ? '' : ' de l’équipe' ?>.</p></div>
        <a class="fv-btn fv-btn-sm" href="prospects.php">Tous les prospects</a>
      </div>
      <div class="fv-card-body">
        <?php $max = max(1, max($statusCounts)); foreach ($statusCounts as $status => $count): ?>
          <div class="fv-funnel-row" data-s="<?= e($status) ?>">
            <span><?= e(fv_status_label($status)) ?></span>
            <span class="track"><span class="fill" style="width:<?= (int) round($count / $max * 100) ?>%"></span></span>
            <span class="n"><?= $count ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <div class="fv-grid" style="gap:14px;">
    <?php if ($ctx['manager']): ?>
      <section class="fv-card">
        <div class="fv-card-head">
          <div><h2>Classement</h2><p>Points sur la période · <?= e($period['label']) ?></p></div>
          <a class="fv-btn fv-btn-sm" href="recompenses.php">Récompenses</a>
        </div>
        <div class="fv-card-body" style="padding:0;">
          <?php if (!$leaders): ?>
            <div class="fv-empty"><b>Pas encore de membre</b>Créez un commercial ou un ambassadeur pour lancer le suivi.</div>
          <?php else: foreach (array_slice($leaders, 0, 5) as $i => $leader): ?>
            <div class="fv-rank">
              <span class="pos"><?= $i + 1 ?></span>
              <span class="fv-avatar" aria-hidden="true"><?= e(fv_initials($leader['name'])) ?></span>
              <div class="who"><b><?= e($leader['name']) ?></b><span><?= e(fv_role_label($leader['role'])) ?> · <?= (int) $leader['shops'] ?> boutique<?= (int) $leader['shops'] > 1 ? 's' : '' ?></span></div>
              <div class="stats"><b><?= (int) $leader['points'] ?> pts</b><br><?= fv_money((int) $leader['commission_cents']) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div class="fv-card-pad" style="border-top:1px solid var(--fv-line);display:flex;justify-content:space-between;font-size:.8rem;">
          <span class="fv-sub">Équipe : <?= count($leaders) ?> membre<?= count($leaders) > 1 ? 's' : '' ?></span>
          <a href="equipe.php">Gérer l’équipe →</a>
        </div>
      </section>
    <?php elseif ($member && $tier): ?>
      <section class="fv-card fv-tier-card" data-t="<?= e($tier['key']) ?>">
        <div class="fv-card-head"><div><h2>Votre progression</h2><p>Palier <?= e($tier['label']) ?> · <?= (int) round($tier['multiplier'] * 100) - 100 ?> % de bonus commission</p></div></div>
        <div class="fv-card-body">
          <div style="display:flex;justify-content:space-between;align-items:baseline;">
            <span style="font-family:var(--fv-serif);font-size:2rem;font-weight:700;"><?= (int) $myStats['points_lifetime'] ?> <small style="font-family:var(--fv-font);font-size:.75rem;color:var(--fv-muted);">pts cumulés</small></span>
            <?php if ($tier['next_label']): ?><span class="fv-sub"><?= $tier['next_min'] - $myStats['points_lifetime'] ?> pts avant <?= e($tier['next_label']) ?></span><?php endif; ?>
          </div>
          <div class="fv-progress"><span style="width:<?= (int) $tier['progress'] ?>%"></span></div>
          <div class="fv-kpis" style="grid-template-columns:1fr 1fr;margin-top:2px;">
            <div class="fv-kpi" style="padding:10px;"><span class="n" style="font-size:1.3rem;"><?= (int) $myStats['shops'] ?></span><span class="l">Boutiques</span></div>
            <div class="fv-kpi" style="padding:10px;"><span class="n" style="font-size:1.3rem;"><?= fv_money((int) $myStats['commission_cents']) ?></span><span class="l">Prime estimée</span></div>
          </div>
          <?php if ($goal > 0): ?>
            <div>
              <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:4px;"><span class="fv-sub">Objectif mensuel</span><b><?= $stats['shops'] ?> / <?= $goal ?> boutiques</b></div>
              <div class="fv-progress is-ok"><span style="width:<?= $goalPct ?>%"></span></div>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Dernières activités</h2><p>Appels, visites, rendez-vous et créations.</p></div></div>
      <div class="fv-card-body" style="padding-top:6px;">
        <?php if (!$recent): ?>
          <div class="fv-empty"><b>Pas encore d’activité</b>Un appel, une visite, une note : tout se retrouve ici.</div>
        <?php else: ?>
          <div class="fv-tl">
            <?php foreach ($recent as $a): ?>
              <div class="fv-tl-item" data-k="<?= e($a['kind']) ?>">
                <span class="fv-tl-dot" aria-hidden="true"></span>
                <div class="fv-tl-body">
                  <b><?= e(fv_kind_label($a['kind'])) ?><?= $a['outcome'] !== '' ? ' · ' . e(fv_outcome_label($a['kind'], $a['outcome'])) : '' ?></b>
                  <span class="when"><?= e(fv_datetime($a['occurred_at'])) ?><?= $a['member_name'] ? ' · ' . e($a['member_name']) : '' ?></span>
                  <?php if ($a['prospect_id'] > 0): ?><p><a class="fv-rowlink" href="prospects.php?id=<?= (int) $a['prospect_id'] ?>"><?= e($a['prospect_business'] !== '' ? $a['prospect_business'] : $a['prospect_name']) ?></a><?php if ($a['notes'] !== ''): ?> — <?= e($a['notes']) ?><?php endif; ?></p><?php endif; ?>
                  <?php if ((int) $a['points'] > 0): ?><span class="pts">+<?= (int) $a['points'] ?> pts</span><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php fv_layout_end(); ?>
