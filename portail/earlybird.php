<?php
// Pionniers (early bird) : campagnes de tarifs préférentiels par galerie ou pour toute la plateforme.
// Le seuil critique, les paliers de rang et les avantages se règlent ici ; les places sont comptées sur les
// demandes d'inscription actives (voir includes/earlybird.php). Aucun prix n'est modifié : la remise est
// appliquée et figée dans la demande au moment du choix de la formule.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';

const EB_ROWS = 3;   // lignes de palier vides proposées en plus des paliers existants

$galleries = gallery_list();
$scope = (string) ($_REQUEST['scope'] ?? 'default');
if ($scope !== 'default' && !isset($galleries[$scope])) $scope = 'default';
$key = $scope === 'default' ? 'default' : $scope;

/** Montant en centimes → « 12,50 » (sans €), '' si null. */
function eb_admin_field(?int $cents): string
{
    if ($cents === null) return '';
    return $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, ',', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $scope = (string) ($_POST['scope'] ?? 'default');
    if ($scope !== 'default' && !isset($galleries[$scope])) $scope = 'default';
    $key = $scope === 'default' ? 'default' : $scope;
    $back = '/portail/earlybird.php?scope=' . rawurlencode($scope);
    if ($action === 'save') {
        if ($scope !== 'default' && empty($_POST['own'])) {
            $ok = eb_delete($scope);
            saas_event('config_changed', ['note' => 'Campagne pionniers de la galerie ' . $scope . ' retirée (retour à la campagne par défaut)', 'actor' => 'exploitant']);
            portail_flash($ok ? 'Cette galerie reprend la campagne pionniers par défaut.' : "Impossible d'écrire data/saas/earlybird.json (droits du dossier data).", $ok ? 'ok' : 'error');
            header('Location: ' . $back);
            exit;
        }
        $tiers = [];
        foreach ((array) ($_POST['tier_label'] ?? []) as $i => $label) {
            $tiers[] = [
                'label' => (string) $label,
                'up_to' => (string) ($_POST['tier_up_to'][$i] ?? ''),
                'discount_pct' => (string) ($_POST['tier_discount'][$i] ?? ''),
                'perk' => (string) ($_POST['tier_perk'][$i] ?? ''),
            ];
        }
        $campaign = [
            'enabled' => !empty($_POST['enabled']),
            'show_public' => !empty($_POST['show_public']),
            'title' => (string) ($_POST['title'] ?? ''),
            'pitch' => (string) ($_POST['pitch'] ?? ''),
            'target' => (int) ($_POST['target'] ?? 0),
            'baseline' => (int) ($_POST['baseline'] ?? 0),
            'ends_at' => (string) ($_POST['ends_at'] ?? ''),
            'lock_months' => (int) ($_POST['lock_months'] ?? 0),
            'tiers' => $tiers,
        ];
        $norm = eb_campaign_normalize($campaign);
        if (!$norm['tiers']) {
            portail_flash('Ajoutez au moins un palier : sans palier, aucune remise ne peut être accordée.', 'error');
            header('Location: ' . $back);
            exit;
        }
        $ok = eb_save($key, $norm);
        saas_event('config_changed', ['note' => 'Campagne pionniers ' . ($scope === 'default' ? 'par défaut' : 'de la galerie ' . $scope) . ' enregistrée (objectif ' . $norm['target'] . ', ' . count($norm['tiers']) . ' palier(s), ' . ($norm['enabled'] ? 'active' : 'inactive') . ')', 'actor' => 'exploitant']);
        portail_flash($ok ? 'Campagne pionniers enregistrée.' : "Impossible d'écrire data/saas/earlybird.json (droits du dossier data).", $ok ? 'ok' : 'error');
    }
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$exists = eb_campaign_exists($key);
$campaign = eb_campaign($scope === 'default' ? '' : $scope);
$progress = eb_progress($campaign);
$seats = eb_seats($campaign['key']);
$nextTier = $progress['open'] ? eb_tier_at($campaign, $progress['next_rank']) : null;
$tiers = $campaign['tiers'];
while (count($tiers) < count($campaign['tiers']) + EB_ROWS) $tiers[] = ['up_to' => null, 'label' => '', 'discount_pct' => 0, 'perk' => ''];
$signup = saas_origin() . '/inscription/' . ($scope !== 'default' ? '?galerie=' . rawurlencode($scope) : '');
$statusLabels = ['draft' => 'Brouillon', 'awaiting_payment' => 'Paiement en attente', 'paid' => 'Payée', 'pending' => 'À créer', 'approved' => 'Boutique créée', 'rejected' => 'Refusée', 'cancelled' => 'Annulée'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pionniers — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .ebwrap { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 16px; max-width: 1180px; margin: 0 auto; padding: 0 16px 56px; align-items: start; }
  @media (max-width: 880px) { .ebwrap { grid-template-columns: 1fr; } }
  .scopes { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 8px; display: grid; gap: 2px; position: sticky; top: 12px; }
  .scopes a { display: grid; gap: 1px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: var(--ink); font-weight: 600; font-size: .92rem; } .scopes a small { font-weight: 400; color: var(--muted); }
  .scopes a[aria-current="page"] { background: var(--accent-soft); } .scopes h3 { margin: 8px 10px 4px; font-size: .74rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; margin-bottom: 14px; }
  .ocard h2 { margin: 0; font-size: 1.05rem; } .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; }
  .row { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
  .f { display: grid; gap: 4px; font-size: .82rem; font-weight: 600; }
  .f input, .f select, .f textarea { padding: 8px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; min-width: 0; }
  .f textarea { min-height: 74px; }
  .grid-t { width: 100%; border-collapse: collapse; font-size: .88rem; } .grid-t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 4px 6px; white-space: nowrap; }
  .grid-t td { padding: 5px 6px; vertical-align: top; border-top: 1px solid var(--line); }
  .grid-t input { padding: 7px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .grid-t td.n { width: 104px; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; } .muted { color: var(--muted); }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
  .kpi { border: 1px solid var(--line); background: var(--bg); border-radius: 10px; padding: 12px 14px; display: grid; gap: 2px; }
  .kpi b { font-size: 1.5rem; line-height: 1; } .kpi span { color: var(--muted); font-size: .78rem; }
  .bar { height: 10px; border-radius: 999px; background: var(--bg); border: 1px solid var(--line); overflow: hidden; }
  .bar span { display: block; height: 100%; background: var(--accent); }
  .pill { display: inline-block; border-radius: 999px; padding: 1px 8px; font-size: .72rem; font-weight: 700; background: var(--accent-soft); color: var(--accent); }
  .pill.ok { background: #e4f3e8; color: #256b3a; }
  .notice-warn { color: var(--warn); font-weight: 600; font-size: .86rem; }
  .seat-t { width: 100%; border-collapse: collapse; font-size: .86rem; } .seat-t th { text-align: left; font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 4px 6px; }
  .seat-t td { padding: 6px; border-top: 1px solid var(--line); vertical-align: top; } .seat-t td.num { white-space: nowrap; }
  .preview { background: var(--bg); border: 1px dashed var(--line); border-radius: 10px; padding: 14px; overflow-x: auto; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Pionniers',
    'eyebrow' => 'Revenus',
    'desc' => 'Tarifs préférentiels par palier pour atteindre le seuil critique de structures.',
    'actions' => '<a class="btn" href="/portail/tarifs.php">Tarifs</a><a class="btn" href="/portail/inscriptions.php">Inscriptions</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="ebwrap">
    <nav class="scopes" aria-label="Campagnes pionniers">
      <h3>Plateforme</h3>
      <?php $pDefault = eb_progress(eb_campaign('')); ?>
      <a href="/portail/earlybird.php?scope=default"<?= $scope === 'default' ? ' aria-current="page"' : '' ?>>Campagne par défaut<small>Inscription sans galerie · <?= (int) $pDefault['enrolled'] ?>/<?= (int) $pDefault['target'] ?></small></a>
      <h3>Galeries commerciales</h3>
      <?php foreach ($galleries as $slug => $g): $has = eb_campaign_exists($slug); $p = eb_progress(eb_campaign($slug)); ?>
        <a href="/portail/earlybird.php?scope=<?= e(rawurlencode($slug)) ?>"<?= $scope === $slug ? ' aria-current="page"' : '' ?>><?= e($g['name']) ?>
          <small><?= $has ? 'Campagne propre' : 'Campagne par défaut' ?> · <?= (int) $p['enrolled'] ?>/<?= (int) $p['target'] ?><?= !empty($g['paused']) ? ' · en pause' : (!$g['published'] ? ' · non publiée' : '') ?></small></a>
      <?php endforeach; ?>
      <?php if (!$galleries): ?><p class="hint" style="padding:6px 10px">Aucune galerie : créez-en dans « Galeries ».</p><?php endif; ?>
    </nav>

    <main>
      <section class="ocard" aria-label="Progression">
        <div class="kpis">
          <div class="kpi"><b><?= (int) $progress['enrolled'] ?></b><span>structures pionnières</span></div>
          <div class="kpi"><b><?= (int) $progress['left'] ?></b><span>place<?= $progress['left'] > 1 ? 's' : '' ?> avant le seuil</span></div>
          <div class="kpi"><b><?= (int) $progress['pct'] ?> %</b><span>de l'objectif de <?= (int) $progress['target'] ?></span></div>
          <div class="kpi"><b><?= $progress['open'] ? 'Ouverte' : ($progress['closed'] ? 'Objectif atteint' : ($progress['expired'] ? 'Terminée' : 'Inactive')) ?></b><span>état de la campagne</span></div>
        </div>
        <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $progress['target'] ?>" aria-valuenow="<?= (int) $progress['enrolled'] ?>"><span style="width:<?= (int) $progress['pct'] ?>%"></span></div>
        <?php if ($progress['open'] && $nextTier): ?>
          <p class="hint">Prochain inscrit : <span class="pill"><?= e($nextTier['label']) ?> −<?= (int) $nextTier['discount_pct'] ?> %</span> · rang <?= (int) $progress['next_rank'] ?>. La remise est figée dans sa demande et le prix remisé part chez Stripe.</p>
        <?php elseif ($progress['closed']): ?>
          <p class="hint">Le seuil est atteint : plus aucune place pionnière n'est accordée. Les structures déjà inscrites conservent leur tarif.</p>
        <?php elseif ($progress['expired']): ?>
          <p class="notice-warn">La date de fin est passée : la campagne n'accorde plus de remise.</p>
        <?php endif; ?>
        <?php if ($campaign['baseline'] > 0): ?><p class="hint"><?= (int) $campaign['baseline'] ?> structure<?= $campaign['baseline'] > 1 ? 's' : '' ?> comptée<?= $campaign['baseline'] > 1 ? 's' : '' ?> d'avance (« déjà présentes »), incluse<?= $campaign['baseline'] > 1 ? 's' : '' ?> dans la progression.</p><?php endif; ?>
      </section>

      <form class="ocard" method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="scope" value="<?= e($scope) ?>">
        <h2>Campagne <?= $scope === 'default' ? 'par défaut' : 'de la galerie « ' . e($galleries[$scope]['name']) . ' »' ?> <small><?= $exists ? 'enregistrée' : ($scope === 'default' ? 'non enregistrée — valeurs par défaut' : 'héritée de la campagne par défaut') ?></small></h2>
        <?php if ($scope !== 'default'): ?>
          <label class="row" style="gap:8px"><input type="checkbox" name="own" value="1" style="width:auto"<?= $exists ? ' checked' : '' ?>> <strong>Cette galerie a sa propre campagne</strong>
            <span class="hint">Décoché : elle applique la campagne par défaut (ci-dessous, à titre d'information).</span></label>
        <?php endif; ?>
        <div class="row">
          <label class="row" style="gap:8px;width:auto"><input type="checkbox" name="enabled" value="1" style="width:auto"<?= $campaign['enabled'] ? ' checked' : '' ?>> <strong>Campagne active</strong></label>
          <label class="row" style="gap:8px;width:auto"><input type="checkbox" name="show_public" value="1" style="width:auto"<?= $campaign['show_public'] ? ' checked' : '' ?>> Afficher la progression en public</label>
        </div>
        <div class="row">
          <label class="f" style="flex:1 1 220px">Libellé de la campagne<input type="text" name="title" maxlength="40" value="<?= e($campaign['title']) ?>" placeholder="Pionniers"></label>
          <label class="f" style="width:130px">Seuil critique<input type="number" name="target" min="1" max="100000" value="<?= (int) $campaign['target'] ?>"></label>
          <label class="f" style="width:170px">Déjà présentes<input type="number" name="baseline" min="0" max="100000" value="<?= (int) $campaign['baseline'] ?>"><span class="hint" style="font-weight:400">structures comptées d'avance</span></label>
          <label class="f" style="width:170px">Fin (facultative)<input type="date" name="ends_at" value="<?= e($campaign['ends_at']) ?>"></label>
          <label class="f" style="width:170px">Tarif garanti<input type="number" name="lock_months" min="0" max="120" value="<?= (int) $campaign['lock_months'] ?>"><span class="hint" style="font-weight:400">mois (0 = à vie)</span></label>
        </div>
        <label class="f">Texte public<textarea name="pitch" maxlength="600"><?= e($campaign['pitch']) ?></textarea></label>

        <h2>Paliers <small>par rang d'inscription : le premier palier dont « jusqu'à » couvre le rang s'applique</small></h2>
        <div style="overflow-x:auto"><table class="grid-t">
          <thead><tr><th>Jusqu'au rang</th><th>Libellé</th><th>Remise (%)</th><th>Avantage affiché</th></tr></thead>
          <tbody>
          <?php foreach ($tiers as $i => $t): ?>
            <tr>
              <td class="n"><input type="number" min="1" name="tier_up_to[<?= $i ?>]" value="<?= e((string) ($t['up_to'] ?? '')) ?>" placeholder="illimité"></td>
              <td><input type="text" name="tier_label[<?= $i ?>]" maxlength="40" value="<?= e($t['label']) ?>" placeholder="Fondateur, Pionnier…"></td>
              <td class="n"><input type="number" min="0" max="90" name="tier_discount[<?= $i ?>]" value="<?= (int) $t['discount_pct'] ?>"></td>
              <td><input type="text" name="tier_perk[<?= $i ?>]" maxlength="160" value="<?= e($t['perk']) ?>" placeholder="Badge, accompagnement, priorité…"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="hint">Une ligne sans libellé et sans remise est ignorée. Laissez « jusqu'au rang » vide pour le dernier palier (sans limite). Les rangs se comptent dans l'ordre d'inscription, « déjà présentes » compris.</p>
        <p><button class="btn btn-primary" type="submit">Enregistrer la campagne</button></p>
      </form>

      <section class="ocard">
        <h2>Structures pionnières <small><?= count($seats) ?> place<?= count($seats) > 1 ? 's' : '' ?> occupée<?= count($seats) > 1 ? 's' : '' ?></small></h2>
        <?php if ($campaign['baseline'] > 0): ?><p class="hint">Les <?= (int) $campaign['baseline'] ?> structure<?= $campaign['baseline'] > 1 ? 's' : '' ?> « déjà présente<?= $campaign['baseline'] > 1 ? 's' : '' ?> » comptent dans la progression sans ligne ci-dessous : elles n'ont pas de demande d'inscription.</p><?php endif; ?>
        <?php if ($seats): ?>
          <div style="overflow-x:auto"><table class="seat-t">
            <thead><tr><th>Rang</th><th>Structure</th><th>Offre</th><th>Remise</th><th>État</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($seats as $r): $o = saas_req_offer($r); $eb = (array) ($r['earlybird'] ?? []); ?>
              <tr>
                <td class="num"><b><?= (int) ($eb['rank'] ?? 0) ?></b></td>
                <td><strong><?= e((string) ($r['shop_name'] ?: ($r['slug'] ?: $r['email']))) ?></strong><br><small class="muted"><?= e((string) ($r['email'] ?? '')) ?><?= ($o['gallery'] ?? '') !== '' ? ' · ' . e((string) $o['gallery']) : '' ?></small></td>
                <td><?= e((string) $o['name']) ?><br><small class="muted"><?= e(saas_price_text((int) ($o['cents'] ?? 0), (string) ($o['billing'] ?? 'month'))) ?></small></td>
                <td><span class="pill"><?= e((string) ($eb['tier_label'] ?? '')) ?> −<?= (int) ($eb['discount_pct'] ?? 0) ?> %</span></td>
                <td><?= e($statusLabels[(string) ($r['status'] ?? '')] ?? (string) ($r['status'] ?? '')) ?></td>
                <td class="num"><?= e(date('d/m/Y', (int) ($r['created'] ?? time()))) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <p class="hint">Les brouillons et paiements en attente comptent 24 h, comme dans l'inscription : ils libèrent leur place ensuite s'ils n'aboutissent pas.</p>
        <?php else: ?>
          <p class="muted">Aucune place occupée pour le moment.</p>
        <?php endif; ?>
      </section>

      <section class="ocard">
        <h2>Ce que verront les structures</h2>
        <div class="row"><a class="btn small" href="<?= e($signup) ?>" target="_blank" rel="noopener">Ouvrir la page d'inscription ↗</a><span class="hint"><?= e($signup) ?></span></div>
        <?php if ($campaign['enabled'] && $campaign['show_public']): ?>
          <div class="preview"><?= eb_widget_html($scope === 'default' ? '' : $scope) ?></div>
        <?php else: ?>
          <p class="hint">La campagne est <?= $campaign['enabled'] ? 'masquée du public' : 'inactive' ?> : le bandeau n'apparaît pas sur l'accueil, la galerie ni l'inscription.</p>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>
</body>
</html>
