<?php
// Crédits SMS de la boutique : solde, packs à racheter (paiement Stripe unique),
// historique des achats et des envois. Voir includes/sms-credits.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/sms.php';
require_admin();
$smsCredits = sms_credit_account(tenant_slug());
$smsPricing = saas_sms_pricing();
$smsPacks = $smsCredits['enabled'] ? saas_sms_packs() : [];
$smsUsedPct = $smsCredits['purchased'] > 0 ? min(100, (int) round($smsCredits['used'] / $smsCredits['purchased'] * 100)) : 0;
$smsLeftValue = round($smsCredits['left'] * $smsPricing['unit_cents'] / 100, 2);
$smsFlash = flash_get();
$csrf = admin_csrf_token();
$content = get_content();
$smsFrom = sms_provider_label() !== '' ? (sms_provider_name() === 'ovh' ? sms_ovh_config()['from'] : (sms_provider_name() === 'twilio' ? sms_twilio_config()['from'] : '')) : '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crédits SMS — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .au-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 18px 0; }
  .au-card { background: var(--surface); border: 1px solid var(--line); padding: 14px 16px; }
  .au-card .k { font-family: var(--font-mono); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-soft); }
  .au-card .v { font-family: var(--font-mono); font-size: 1.5rem; margin-top: 6px; font-variant-numeric: tabular-nums; }
  .au-balance { border-color: var(--accent); }
  .au-bar { height: 8px; background: var(--surface-2); border: 1px solid var(--line); margin: 10px 0 6px; overflow: hidden; }
  .au-bar span { display: block; height: 100%; background: var(--accent); }
  .au-bar.is-low span { background: #b3261e; }
  .au-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: 8px; }
  .au-table th, .au-table td { padding: 7px 10px; border-bottom: 1px solid var(--line); text-align: left; }
  .au-table th { font-family: var(--font-mono); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-soft); font-weight: 400; }
  .au-table td.num, .au-table th.num { text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
  .au-note { font-size: 0.82rem; color: var(--ink-soft); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'sms'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Crédits SMS</h1>
    <p class="hint">Chaque SMS envoyé depuis votre boutique — code de connexion, annonces des actions commerciales, alertes de l'assistance vendeur et validation de disponibilité — est décompté de votre crédit. Un texte long compte plusieurs SMS : 160 caractères sans accents (153 par partie au-delà de la première), 70 caractères avec accents (67 par partie).</p>
    <p class="hint">Les identifiants du fournisseur se règlent dans <a href="/admin/index.php?s=general#sms-settings">Réglages du site → SMS</a>.</p>

    <?php if ($smsFlash): ?>
      <p class="publish-status" data-kind="<?= h($smsFlash['kind']) ?>" style="margin:16px 0;"><?= h($smsFlash['message']) ?></p>
    <?php endif; ?>

    <?php if (!$smsCredits['enabled']): ?>
      <div class="admin-block" style="margin:18px 0;">
        <h2 style="font-size:1.1rem;">SMS sans limite</h2>
        <p class="hint" style="margin:6px 0;">Votre boutique utilise le compte SMS de la plateforme : ses envois ne sont pas décomptés et aucun crédit n'est nécessaire. L'exploitant peut activer un crédit SMS depuis le portail ; les packs de rachat apparaîtront alors ici.</p>
        <p class="au-note">Fournisseur effectif : <strong><?= sms_provider_label() !== '' ? h(sms_provider_label()) : 'aucun' ?></strong><?= $smsFrom !== '' ? ' — expéditeur « ' . h($smsFrom) . ' »' : '' ?>.</p>
      </div>
    <?php else: ?>
      <div class="admin-block au-quota" style="margin:18px 0;<?= $smsCredits['left'] <= 0 ? 'border-color:#b3261e;' : '' ?>">
        <h2 style="font-size:1.1rem;">Solde de crédit SMS</h2>
        <?php if ($smsCredits['left'] <= 0): ?>
          <p class="publish-status" data-kind="error" role="alert" style="margin:8px 0;"><strong>Les SMS sont suspendus :</strong> votre crédit est épuisé. Rachetez un pack ci-dessous pour reprendre tout de suite ; le code de connexion par SMS reste alors indisponible.</p>
        <?php endif; ?>
        <div class="au-cards">
          <div class="au-card"><div class="k">Crédit acheté</div><div class="v"><?= number_format($smsCredits['purchased'], 0, ',', ' ') ?> SMS</div></div>
          <div class="au-card"><div class="k">Envoyés</div><div class="v"><?= number_format($smsCredits['used'], 0, ',', ' ') ?> SMS</div></div>
          <div class="au-card au-balance"><div class="k">Restant</div><div class="v"><?= number_format($smsCredits['left'], 0, ',', ' ') ?> SMS</div><div class="au-note" style="margin-top:4px;"><?= h(number_format($smsLeftValue, 2, ',', ' ')) ?> € de crédit</div></div>
        </div>
        <div class="au-bar<?= $smsUsedPct >= 85 ? ' is-low' : '' ?>" role="img" aria-label="<?= $smsUsedPct ?> % du crédit consommé"><span style="width:<?= $smsUsedPct ?>%"></span></div>
        <p class="au-note" style="margin:6px 0 0;">Le crédit n'expire pas. Il est décompté à chaque envoi réussi, sans limite de durée.</p>

        <?php if ($smsPacks): ?>
          <h3 style="font-size:1rem;margin:14px 0 6px;">Racheter du crédit SMS</h3>
          <?php if (saas_stripe_configured()): ?>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">
              <?php foreach ($smsPacks as $pk): $per = (int) round($pk['price_cents'] / max(1, (int) $pk['sms'])); ?>
                <form method="post" action="/admin/sms-credit-action.php" style="border:1px solid var(--line);padding:10px 12px;min-width:200px;flex:1 1 200px;">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="pack" value="<?= h($pk['key']) ?>">
                  <strong><?= h($pk['name']) ?></strong>
                  <p class="au-note" style="margin:4px 0 8px;"><?= number_format((int) $pk['sms'], 0, ',', ' ') ?> SMS<br><?= h(saas_money($per)) ?> le SMS</p>
                  <button type="submit" class="btn btn-primary" style="width:100%;">Acheter — <?= h(saas_money((int) $pk['price_cents'])) ?></button>
                </form>
              <?php endforeach; ?>
            </div>
            <p class="au-note" style="margin-top:8px;">Paiement unique et sécurisé par Stripe ; le crédit est ajouté dès la confirmation.</p>
          <?php else: ?>
            <p class="au-note">Le paiement en ligne n'est pas disponible sur ce serveur : contactez l'exploitant de la plateforme pour acheter du crédit SMS.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($smsCredits['purchases']): ?>
        <div class="admin-block" style="margin-top:20px;">
          <h2 style="font-size:1.1rem;">Achats et crédits reçus</h2>
          <table class="au-table">
            <thead><tr><th>Date</th><th>Origine</th><th class="num">SMS</th><th class="num">Payé</th></tr></thead>
            <tbody><?php foreach (array_reverse($smsCredits['purchases']) as $pu): ?>
              <tr>
                <td><?= h(date('d/m/Y', (int) ($pu['at'] ?? 0))) ?></td>
                <td><?= ($pu['source'] ?? '') === 'offert' ? 'Offert par la plateforme' : (($pu['source'] ?? '') === 'exploitant' ? 'Ajouté par l\'exploitant' : h(($pu['pack'] ?? '') !== '' ? 'Pack ' . $pu['pack'] : 'Paiement Stripe')) ?></td>
                <td class="num"><?= number_format((int) ($pu['sms'] ?? 0), 0, ',', ' ') ?></td>
                <td class="num"><?= (int) ($pu['price_cents'] ?? 0) > 0 ? h(saas_money((int) $pu['price_cents'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($smsCredits['sends']): ?>
        <div class="admin-block" style="margin-top:20px;">
          <h2 style="font-size:1.1rem;">Derniers envois</h2>
          <table class="au-table">
            <thead><tr><th>Date</th><th>Numéro</th><th class="num">SMS</th><th class="num">Coût</th></tr></thead>
            <tbody><?php foreach (array_reverse(array_slice($smsCredits['sends'], -25)) as $se): ?>
              <tr>
                <td><?= h(date('d/m/Y H:i', (int) ($se['at'] ?? 0))) ?></td>
                <td class="au-note"><?= h((string) ($se['to'] ?? '')) ?><?= ($se['purpose'] ?? '') !== '' ? ' · ' . h((string) $se['purpose']) : '' ?></td>
                <td class="num"><?= (int) ($se['segments'] ?? 0) ?></td>
                <td class="num"><?= h(saas_money((int) ($se['price_cents'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table>
          <p class="au-note" style="margin-top:6px;">Les 300 derniers envois sont conservés. Les numéros sont masqués.</p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
