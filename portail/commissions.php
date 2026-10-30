<?php
// Commissions (réservé à l'exploitant) : taux de la plateforme et des galeries, comptes Stripe Connect, relevés et versements.
// Logique : includes/commissions.php. Les ventes sont lues dans la base de chaque boutique de ce serveur ; les abonnements dans le journal du portail.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/commissions.php';

if (defined('GA_LIMITED')) { header('Location: /portail/galerie-tableau.php'); exit; }

$origin = saas_origin();
$self = '/portail/commissions.php';
$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from']) ? (string) $_GET['from'] : date('Y-m-01');
$to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to']) ? (string) $_GET['to'] : date('Y-m-d');
$fromTs = (int) strtotime($from . ' 00:00:00');
$toTs = (int) strtotime($to . ' 23:59:59');
$shops = tenant_list();
$galleries = gallery_list();
$gNames = array_column($galleries, 'name', 'slug');

if (($_GET['csv'] ?? '') !== '') {
    $g = strtolower((string) $_GET['csv']);
    cm_sync();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commissions-' . ($g === 'all' ? 'toutes' : $g) . '-' . $from . '_' . $to . '.csv"');
    echo cm_csv($fromTs, $toTs, $g === 'all' ? '' : $g);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $back = $self . '?from=' . $from . '&to=' . $to;
    switch ($action) {
        case 'config':
            $c = cm_config();
            foreach (array_keys(CM_RATE_KEYS) as $k) $c['rates'][$k] = cm_pct($_POST['rates'][$k] ?? 0);
            $c['galleries'] = [];
            foreach ((array) ($_POST['g'] ?? []) as $slug => $o) {
                $row = [];
                foreach (['sales_gallery_pct', 'subs_gallery_pct'] as $k) if (($o[$k] ?? '') !== '') $row[$k] = cm_pct($o[$k]);
                if ($row && gallery_load((string) $slug)) $c['galleries'][(string) $slug] = $row;
            }
            $c['shop_gallery'] = [];
            foreach ((array) ($_POST['sg'] ?? []) as $shop => $g) if (isset($shops[$shop]) && $g !== '__auto') $c['shop_gallery'][$shop] = $g === '__none' ? '' : (string) $g;
            $c['payout_min_cents'] = (int) round((float) str_replace(',', '.', (string) ($_POST['payout_min'] ?? 20)) * 100);
            $c['connect'] = !empty($_POST['connect']);
            if (!empty($_POST['since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['since'])) $c['since'] = (int) strtotime($_POST['since'] . ' 00:00:00');
            portail_flash(cm_config_save($c) ? 'Réglages enregistrés. Les commissions comptent à partir du ' . date('d/m/Y', cm_config()['since'] ?: time()) . '.' : "Impossible d'écrire data/saas/commissions.json (droits du dossier data).", 'ok');
            break;
        case 'account_create':
            $err = cm_account_create((string) ($_POST['kind'] ?? ''), (string) ($_POST['slug'] ?? ''), trim((string) ($_POST['email'] ?? '')));
            portail_flash($err ?? 'Compte Stripe créé : envoyez au titulaire le lien d\'inscription (bouton « Lien d\'inscription »).', $err ? 'error' : 'ok');
            break;
        case 'account_link':
            [$url, $err] = cm_account_link((string) ($_POST['key'] ?? ''), $origin . $self);
            if ($url) { header('Location: ' . $url, true, 303); exit; }
            portail_flash($err, 'error');
            break;
        case 'account_refresh':
            $err = cm_account_refresh((string) ($_POST['key'] ?? ''));
            portail_flash($err ?? 'État du compte actualisé.', $err ? 'error' : 'ok');
            break;
        case 'payout':
            [$amount, $err] = cm_payout(strtolower((string) ($_POST['gallery'] ?? '')), ($_POST['method'] ?? '') === 'stripe' ? 'stripe' : 'manual', (string) ($_POST['note'] ?? ''), $toTs);
            portail_flash($err ?? 'Versement de ' . number_format($amount / 100, 2, ',', ' ') . ' € enregistré.', $err ? 'error' : 'ok');
            break;
    }
    saas_audit_flash('Commissions');
    header('Location: ' . $back);
    exit;
}

$newLines = cm_sync();
$c = cm_config();
$sum = cm_summary($fromTs, $toTs);
$connect = cm_connect();
$flash = portail_flash();
$lines = array_slice(array_reverse(cm_lines()), 0, 40);
$paidIds = cm_paid_ids();
$eur = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';
$stripeOk = saas_stripe_configured();
$stLabel = ['ready' => 'Prêt', 'pending' => 'À finaliser'];
$ownerEmail = static function (string $kind, string $slug) use ($galleries): string {
    if ($kind === 'gallery') foreach ($galleries as $g) if ($g['slug'] === $slug && $g['admins']) return $g['admins'][0]['email'];
    return '';
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Commissions — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 1040px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; } .gcard h2 { margin: 0; font-size: 1.05rem; } .gcard h3 { margin: 6px 0 0; font-size: .95rem; }
  .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .gcard input[type=number], .gcard input[type=text], .gcard input[type=email], .gcard input[type=date], .gcard select { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; max-width: 100%; }
  .gcard label.f { display: grid; gap: 3px; font-size: .78rem; font-weight: 600; }
  table.t { width: 100%; border-collapse: collapse; font-size: .9rem; } table.t th, table.t td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; } table.t th { font-size: .75rem; opacity: .7; }
  .num { text-align: right !important; white-space: nowrap; } .warn { border: 1px solid #e0c58a; background: #fff7e0; color: #5a4300; border-radius: 10px; padding: 12px 14px; font-size: .88rem; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; } .kpi { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; background: var(--bg); } .kpi b { display: block; font-size: 1.35rem; } .kpi span { font-size: .78rem; opacity: .75; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Commissions',
    'eyebrow' => 'Revenus',
    'desc' => 'Plateforme et galeries : taux, comptes Stripe Connect, relevés et versements.',
    'actions' => '<a class="btn" href="/portail/galeries.php">Galeries</a><a class="btn" href="/portail/offres-galerie.php">Types de galerie</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="gwrap">
    <div class="warn"><strong>À savoir avant d'activer Stripe Connect.</strong> Il faut l'activer dans votre tableau de bord Stripe (Connect → Premiers pas), compléter le profil de plateforme, puis faire valider chaque compte (identité, IBAN) par son titulaire. Stripe facture ses propres frais (comptes connectés, virements) : vérifiez sa grille. En « Connect », c'est <strong>vous</strong> qui encaissez : remboursements, litiges et TVA sur vos commissions (facturez-les) vous incombent. Faites valider le montage par votre expert-comptable. Sans Connect, le suivi fonctionne seul : vous facturez et reversez hors Stripe.</div>
    <p class="meta">Stripe de la plateforme : <?= $stripeOk ? '<strong>configuré</strong> (' . (saas_stripe_live() ? 'mode réel' : 'mode test') . ')' : '<strong>non configuré</strong> (Portail → clés Stripe)' ?> · Commission automatique à l'encaissement (Connect) : <strong><?= $c['connect'] ? 'activée' : 'désactivée (suivi seulement)' ?></strong> · Commissions comptées depuis le <strong><?= $c['since'] ? e(date('d/m/Y', $c['since'])) : '— (enregistrez un taux)' ?></strong></p>

    <form class="gcard" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="config">
      <h2>1. Taux</h2>
      <div class="row">
        <?php foreach (CM_RATE_KEYS as $k => $label): ?><label class="f"><?= e($label) ?> (%)<input type="number" step="0.1" min="0" max="<?= CM_MAX_PCT ?>" name="rates[<?= e($k) ?>]" value="<?= e((string) $c['rates'][$k]) ?>" style="width:120px"></label><?php endforeach; ?>
      </div>
      <p class="meta">Ventes : commission sur le montant des pièces après remise, hors frais de port. Abonnements : sur le paiement de l'abonnement du commerce inscrit via la galerie. Maximum <?= (int) CM_MAX_PCT ?> %.</p>
      <h3>Taux propres à une galerie <small class="meta">(vide = taux général)</small></h3>
      <?php if ($galleries): ?><table class="t"><thead><tr><th>Galerie</th><th>Ventes de ses commerces (%)</th><th>Abonnements recrutés (%)</th></tr></thead><tbody>
        <?php foreach ($galleries as $g): $o = $c['galleries'][$g['slug']] ?? []; ?>
          <tr><td><?= e($g['name']) ?> <small class="meta"><?= e($g['slug']) ?></small></td>
            <td><input type="number" step="0.1" min="0" max="<?= CM_MAX_PCT ?>" name="g[<?= e($g['slug']) ?>][sales_gallery_pct]" value="<?= isset($o['sales_gallery_pct']) ? e((string) $o['sales_gallery_pct']) : '' ?>" placeholder="<?= e((string) $c['rates']['sales_gallery_pct']) ?>" style="width:100px"></td>
            <td><input type="number" step="0.1" min="0" max="<?= CM_MAX_PCT ?>" name="g[<?= e($g['slug']) ?>][subs_gallery_pct]" value="<?= isset($o['subs_gallery_pct']) ? e((string) $o['subs_gallery_pct']) : '' ?>" placeholder="<?= e((string) $c['rates']['subs_gallery_pct']) ?>" style="width:100px"></td></tr>
        <?php endforeach; ?></tbody></table><?php else: ?><p class="meta">Aucune galerie.</p><?php endif; ?>
      <h3>Galerie de rattachement des commerces <small class="meta">(celle qui touche la commission sur ses ventes)</small></h3>
      <table class="t"><tbody>
        <?php foreach ($shops as $slug => $shop): $auto = cm_shop_gallery($slug, ['shop_gallery' => []] + $c); $cur = array_key_exists($slug, $c['shop_gallery']) ? ($c['shop_gallery'][$slug] === '' ? '__none' : $c['shop_gallery'][$slug]) : '__auto'; ?>
          <tr><td><?= e($shop['name']) ?> <small class="meta"><?= e($slug) ?></small></td>
            <td><select name="sg[<?= e($slug) ?>]"><option value="__auto"<?= $cur === '__auto' ? ' selected' : '' ?>>Automatique<?= $auto !== '' ? ' (' . e($gNames[$auto] ?? $auto) . ')' : ' (aucune)' ?></option><option value="__none"<?= $cur === '__none' ? ' selected' : '' ?>>Aucune galerie</option>
              <?php foreach ($galleries as $g): ?><option value="<?= e($g['slug']) ?>"<?= $cur === $g['slug'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></td></tr>
        <?php endforeach; ?></tbody></table>
      <div class="row">
        <label class="f">Versement minimum par Stripe (€)<input type="number" step="1" min="1" name="payout_min" value="<?= e((string) ($c['payout_min_cents'] / 100)) ?>" style="width:110px"></label>
        <label class="f">Commissions comptées à partir du<input type="date" name="since" value="<?= $c['since'] ? e(date('Y-m-d', $c['since'])) : '' ?>"></label>
        <label class="f" style="align-self:end;"><span><input type="checkbox" name="connect" value="1"<?= $c['connect'] ? ' checked' : '' ?>> Prélever la commission à l'encaissement (Stripe Connect)</span></label>
      </div>
      <p><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>

    <section class="gcard"><h2>2. Comptes Stripe</h2>
      <p class="meta">Une <strong>galerie</strong> a besoin d'un compte pour recevoir ses versements par Stripe ; un <strong>commerce</strong> en a besoin pour que sa commission soit prélevée à l'encaissement (seulement les commerces hébergés sur ce serveur). Le titulaire reçoit de Stripe un formulaire d'inscription (identité, IBAN).</p>
      <table class="t"><thead><tr><th>Bénéficiaire</th><th>État</th><th>E-mail du titulaire</th><th></th></tr></thead><tbody>
      <?php foreach ([['gallery', $galleries], ['shop', array_map(static fn ($s, $k) => ['slug' => $k, 'name' => $s['name']], $shops, array_keys($shops))]] as [$kind, $list]): foreach ($list as $it): $key = $kind . ':' . $it['slug']; $a = $connect[$key] ?? null; ?>
        <tr><td><?= $kind === 'gallery' ? 'Galerie' : 'Commerce' ?> — <strong><?= e($it['name']) ?></strong></td>
          <td><?= $a ? e($stLabel[$a['status']] ?? $a['status']) . ' <small class="meta">' . e($a['account']) . '</small>' : '<span class="meta">pas de compte</span>' ?></td>
          <td><?php if ($a): ?><?= e($a['email']) ?><?php else: ?>
            <form method="post" class="row" id="f-<?= e(md5($key)) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="account_create"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="slug" value="<?= e($it['slug']) ?>">
            <input type="email" name="email" required placeholder="titulaire@exemple.fr" value="<?= e($ownerEmail($kind, $it['slug'])) ?>"><button class="btn small" type="submit" <?= $stripeOk ? '' : 'disabled' ?>>Créer le compte</button></form><?php endif; ?></td>
          <td><?php if ($a): ?><form method="post" class="row" style="gap:4px;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="key" value="<?= e($key) ?>">
            <button class="btn small" type="submit" name="action" value="account_link" formtarget="_blank">Lien d'inscription</button><button class="btn small" type="submit" name="action" value="account_refresh">Actualiser</button></form><?php endif; ?></td></tr>
      <?php endforeach; endforeach; ?></tbody></table>
    </section>

    <section class="gcard"><h2>3. Relevés et versements</h2>
      <form method="get" class="row"><label class="f">Du<input type="date" name="from" value="<?= e($from) ?>"></label><label class="f">au<input type="date" name="to" value="<?= e($to) ?>"></label><button class="btn small" type="submit" style="align-self:end;">Afficher</button>
        <a class="btn small" style="align-self:end;" href="?csv=all&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>">Exporter tout (CSV)</a></form>
      <?php if ($newLines): ?><p class="meta"><?= (int) $newLines ?> nouvelle(s) ligne(s) ajoutée(s) au grand livre à l'instant.</p><?php endif; ?>
      <div class="kpis">
        <div class="kpi"><b><?= e($eur($sum['platform']['collected'])) ?></b><span>commission plateforme prélevée (Stripe)</span></div>
        <div class="kpi"><b><?= e($eur($sum['platform']['to_invoice'])) ?></b><span>commission plateforme à facturer aux commerces</span></div>
        <div class="kpi"><b><?= e($eur(array_sum(array_column($sum['galleries'], 'due')))) ?></b><span>dû aux galeries (toutes périodes)</span></div>
      </div>
      <table class="t"><thead><tr><th>Galerie</th><th class="num">Ventes</th><th class="num">Abonnements</th><th class="num">Déjà versé</th><th class="num">Dû</th><th>Verser jusqu'au <?= e(date('d/m/Y', $toTs)) ?></th></tr></thead><tbody>
      <?php foreach ($sum['galleries'] as $slug => $g): $canStripe = $g['due_collected'] > 0 && cm_connect_ready('gallery:' . $slug); ?>
        <tr><td><?= e($gNames[$slug] ?? $slug) ?><br><a href="?csv=<?= e($slug) ?>&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>" style="font-size:.78rem;">relevé CSV</a></td>
          <td class="num"><?= e($eur($g['sales'])) ?></td><td class="num"><?= e($eur($g['subs'])) ?></td><td class="num"><?= e($eur($g['paid'])) ?></td>
          <td class="num"><strong><?= e($eur($g['due'])) ?></strong><br><small class="meta"><?= e($eur($g['due_collected'])) ?> encaissé · <?= e($eur($g['due_tracked'])) ?> à facturer</small></td>
          <td><?php if ($g['due'] > 0): ?>
            <form method="post" class="row" style="gap:4px;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payout"><input type="hidden" name="gallery" value="<?= e($slug) ?>">
              <button class="btn small btn-primary" type="submit" name="method" value="stripe" <?= $canStripe ? '' : 'disabled title="Compte Stripe de la galerie non prêt, ou rien d\'encaissé"' ?>>Verser par Stripe</button>
              <input type="text" name="note" placeholder="réf. virement" style="width:130px"><button class="btn small" type="submit" name="method" value="manual" onclick="return confirm('Marquer ces commissions comme versées hors Stripe ?')">Déjà viré</button></form>
            <?php else: ?><span class="meta">Rien à verser</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$sum['galleries']): ?><tr><td colspan="6" class="meta">Aucune commission de galerie pour le moment. Elles apparaissent après la date de départ, dès qu'un taux est enregistré et qu'une vente ou un abonnement est payé.</td></tr><?php endif; ?>
      </tbody></table>
      <?php if ($sum['shops']): ?><h3>Ventes par commerce</h3><table class="t"><thead><tr><th>Commerce</th><th class="num">Commandes</th><th class="num">Base</th><th class="num">Plateforme</th><th class="num">Galerie</th><th>Mode</th></tr></thead><tbody>
        <?php foreach ($sum['shops'] as $slug => $s): ?><tr><td><?= e($slug) ?></td><td class="num"><?= (int) $s['count'] ?></td><td class="num"><?= e($eur($s['base'])) ?></td><td class="num"><?= e($eur($s['platform'])) ?></td><td class="num"><?= e($eur($s['gallery'])) ?></td><td><?= $s['collected'] === $s['count'] ? 'prélevée (Connect)' : ($s['collected'] === 0 ? 'à facturer' : 'mixte') ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </section>

    <section class="gcard"><h2>Journal récent</h2>
      <table class="t"><thead><tr><th>Date</th><th>Type</th><th>Commerce</th><th>Galerie</th><th class="num">Base</th><th class="num">Plateforme</th><th class="num">Galerie</th><th>État</th></tr></thead><tbody>
      <?php foreach ($lines as $l): ?><tr><td><?= e(date('d/m H:i', $l['ts'])) ?></td><td><?= e(['sale' => 'Vente', 'subscription' => 'Abonnement', 'refund' => 'Remboursement'][$l['kind']] ?? $l['kind']) ?></td><td><?= e($l['shop']) ?></td><td><?= e($l['gallery']) ?></td>
        <td class="num"><?= e($eur($l['base'])) ?></td><td class="num"><?= e($eur($l['platform'])) ?></td><td class="num"><?= e($eur($l['gallery_cents'])) ?></td><td><?= isset($paidIds[$l['id']]) ? 'versée' : ($l['collected'] ? 'encaissée' : 'à facturer') ?></td></tr><?php endforeach; ?>
      <?php if (!$lines): ?><tr><td colspan="8" class="meta">Aucune ligne.</td></tr><?php endif; ?></tbody></table>
    </section>
  </div>
</div>
</body>
</html>
