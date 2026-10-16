<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saas.php';
require_admin();

$content = get_content();
$flash = flash_get();
$pricing = ai_pricing();
$balance = ai_balance();
$csrf = admin_csrf_token();

$today = ai_usage_spent(gmdate('Y-m-d 00:00:00'));
$week = ai_usage_spent(gmdate('Y-m-d H:i:s', time() - 7 * 86400));
$month = ai_usage_spent(gmdate('Y-m-01 00:00:00'));
$total = ai_usage_spent();
$calls = (int) db()->query('SELECT COUNT(*) FROM ai_usage')->fetchColumn();
$firstCall = (string) db()->query('SELECT MIN(at) FROM ai_usage')->fetchColumn();

$byKind = [];
foreach (db()->query('SELECT kind, COUNT(*) AS n, SUM(cost_usd) AS usd, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout FROM ai_usage GROUP BY kind')->fetchAll() as $r) $byKind[$r['kind']] = $r;
$monthKind = [];
foreach (db()->query("SELECT kind, COUNT(*) AS n, SUM(cost_usd) AS usd FROM ai_usage WHERE at >= '" . gmdate('Y-m-01 00:00:00') . "' GROUP BY kind")->fetchAll() as $r) $monthKind[$r['kind']] = $r;
$byMonth = db()->query("SELECT substr(at, 1, 7) AS m, COUNT(*) AS n, SUM(cost_usd) AS usd FROM ai_usage GROUP BY m ORDER BY m DESC LIMIT 6")->fetchAll();
$topPieces = db()->query("SELECT u.ref, COUNT(*) AS n, SUM(u.cost_usd) AS usd, p.name FROM ai_usage u LEFT JOIN products p ON p.ref = u.ref WHERE u.ref IS NOT NULL GROUP BY u.ref ORDER BY usd DESC LIMIT 10")->fetchAll();
$recent = db()->query('SELECT u.*, p.name AS product_name FROM ai_usage u LEFT JOIN products p ON p.ref = u.ref ORDER BY u.id DESC LIMIT 25')->fetchAll();
$origins = [
    'gallery-action.php' => 'Galerie (génération)', 'quick-add-action.php' => 'Nouvelle pièce / Studio', 'mobile-variants.php' => 'Version 9:16',
    'batch-import-action.php' => 'Import par lot', 'product-ai.php' => 'Boutons « ↻ IA » des fiches', 'price-research.php' => 'Prix du marché',
    'media-describe.php' => 'Médiathèque (description)', 'enhance-image.php' => 'Amélioration de détail', 'universes-action.php' => 'Univers', 'video-action.php' => 'Galerie (vidéo IA)',
];

$estimates = [
    ['Mise en situation, autre angle ou objet complété (3:2 + 9:16)', ['image' => 2]],
    ['Détourage' . (FAL_API_KEY ? ' (fal.ai)' : " (repli par image générée : fal.ai n'est pas configuré)"), ai_cutout_counts()],
    ['Pièce complète au Studio (détourage, 2 visuels, fiche)', ai_estimate_piece_counts()],
    ['Un bouton « ↻ IA » sur un champ ou « Tout (re)générer »', ['text' => 1]],
    ['Recherche du prix du marché (web)', ['search' => 1]],
    ['Vidéo IA Veo Fast de 6 s (720p)', ['usd' => veo_cost_usd('fast', 6)]],
    ['Vidéo IA Wan 2.2 (SiliconFlow), une vidéo', ['usd' => veo_cost_usd(SF_MODEL_KEY, 0)]],
    ['Vidéo IA Wan 2.2 5B (Modal), une vidéo — prélevée sur le crédit Modal', ['usd' => veo_cost_usd(MODAL_MODEL_KEY, 0)]],
];
$eur = static fn ($usd): string => ai_format_eur(ai_eur((float) $usd));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consommation IA — <?= h($content['site_name']) ?></title>
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
  .au-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin-top: 10px; }
  .au-form label { display: flex; flex-direction: column; gap: 4px; font-size: 0.8rem; color: var(--ink-soft); }
  .au-form input { background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font-family: var(--font-mono); font-size: 0.9rem; width: 130px; }
  .au-note { font-size: 0.82rem; color: var(--ink-soft); }
  .au-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  @media (max-width: 900px) { .au-grid2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'ia'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Consommation de l'IA</h1>
    <p class="hint">Chaque génération d'image, appel de texte ou de vision, recherche web et détourage est consigné avec son <strong>coût estimé</strong>, d'après les tarifs ci-dessous. C'est une estimation : le solde réel de votre compte Google ou fal.ai n'est lisible par aucune API — consultez <a href="https://aistudio.google.com/" target="_blank" rel="noopener">Google AI Studio</a> (facturation) et <a href="https://fal.ai/dashboard" target="_blank" rel="noopener">fal.ai</a> pour les chiffres exacts.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php
      // IA incluse dans la formule et crédit racheté (includes/ai-plans.php) : seulement si la plateforme a affecté un quota à cette boutique.
      $quota = ai_quota_state();
      if ($quota):
        $packs = saas_ai_packs();
        $credits = saas_ai_credits(tenant_slug());
        $usedPct = $quota['quota'] > 0 ? max(0, min(100, $quota['month_spent'] / $quota['quota'] * 100)) : 100;
        $creditLeft = max(0.0, $quota['credit_left']);
    ?>
    <div class="admin-block au-quota" style="margin:18px 0;<?= $quota['blocked'] ? 'border-color:#b3261e;' : '' ?>">
      <h2 style="font-size:1.1rem;">IA incluse dans votre formule</h2>
      <?php if ($quota['blocked']): ?>
        <p class="publish-status" data-kind="error" role="alert" style="margin:8px 0;"><strong>Les générations IA sont suspendues.</strong> Le quota gratuit de ce mois est épuisé et vous n'avez plus de crédit. Rachetez un pack ci-dessous pour reprendre tout de suite, ou attendez le 1er du mois prochain.</p>
      <?php endif; ?>
      <div class="au-bar" role="img" aria-label="<?= (int) $usedPct ?> % du quota gratuit du mois consommé"><span style="width:<?= (int) $usedPct ?>%;<?= $usedPct >= 100 ? 'background:#b3261e;' : '' ?>"></span></div>
      <p class="au-note" style="margin:6px 0 0;">
        <?php if ($quota['quota'] > 0): ?>
          Ce mois-ci : <strong><?= h(ai_format_eur($quota['month_spent'])) ?></strong> consommés sur <strong><?= h(ai_format_eur($quota['quota'])) ?></strong> d'IA gratuite. Il vous reste <strong><?= h(ai_format_eur($quota['free_left'])) ?></strong>,
          soit <?= h(saas_ai_estimate_text($quota['free_left'])) ?> <span class="muted">(au choix : un budget partagé entre textes, images et vidéos)</span>.
        <?php else: ?>
          Votre formule n'inclut pas d'IA : vous utilisez du crédit que vous rachetez ci-dessous.
        <?php endif; ?>
      </p>
      <p class="au-note" style="margin:6px 0 0;">Crédit racheté restant : <strong><?= h(ai_format_eur($creditLeft)) ?></strong><?= $creditLeft > 0 ? ' (' . h(saas_ai_estimate_text($creditLeft)) . ')' : '' ?>.
        Il ne s'utilise que lorsque le quota gratuit du mois est épuisé, et n'expire pas.</p>
      <?php if ($packs): ?>
        <h3 style="font-size:1rem;margin:14px 0 6px;">Racheter du crédit IA</h3>
        <?php if (saas_stripe_configured()): ?>
          <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php foreach ($packs as $pk): ?>
              <form method="post" action="/admin/ai-credit-action.php" style="border:1px solid var(--line);padding:10px 12px;min-width:200px;flex:1 1 200px;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="pack" value="<?= h($pk['key']) ?>">
                <strong><?= h($pk['name']) ?></strong>
                <p class="au-note" style="margin:4px 0 8px;"><?= h(ai_format_eur($pk['credit_eur'])) ?> de crédit<br><?= h(saas_ai_estimate_text((float) $pk['credit_eur'])) ?></p>
                <button type="submit" class="btn btn-primary" style="width:100%;">Acheter — <?= h(saas_money((int) $pk['price_cents'])) ?></button>
              </form>
            <?php endforeach; ?>
          </div>
          <p class="au-note" style="margin-top:8px;">Paiement unique et sécurisé par Stripe ; le crédit est ajouté dès la confirmation.</p>
        <?php else: ?>
          <p class="au-note">Le paiement en ligne n'est pas disponible sur ce serveur : contactez l'exploitant de la plateforme pour acheter du crédit IA.</p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($credits['purchases']): ?>
        <details style="margin-top:10px;"><summary class="au-note" style="cursor:pointer;">Historique des crédits (<?= count($credits['purchases']) ?>)</summary>
          <ul class="au-note" style="margin:6px 0 0;padding-left:18px;">
            <?php foreach (array_reverse(array_slice($credits['purchases'], -8)) as $pu): ?>
              <li><?= h(date('d/m/Y', (int) $pu['at'])) ?> — <?= h(ai_format_eur((float) $pu['credit_eur'])) ?><?= ($pu['source'] ?? '') === 'offert' ? ' offerts' : ' (' . h($pu['pack'] ?: 'pack') . ($pu['price_cents'] ? ', ' . h(saas_money((int) $pu['price_cents'])) : '') . ')' ?></li>
            <?php endforeach; ?>
          </ul></details>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="au-cards">
      <div class="au-card"><div class="k">Aujourd'hui</div><div class="v"><?= h(ai_format_eur($today)) ?></div></div>
      <div class="au-card"><div class="k">7 derniers jours</div><div class="v"><?= h(ai_format_eur($week)) ?></div></div>
      <div class="au-card"><div class="k">Ce mois-ci</div><div class="v"><?= h(ai_format_eur($month)) ?></div></div>
      <div class="au-card"><div class="k">Total évalué</div><div class="v"><?= h(ai_format_eur($total)) ?></div></div>
      <div class="au-card au-balance"><div class="k">Solde estimé</div><div class="v"><?= $balance ? h(ai_format_eur($balance['remaining'])) : '—' ?></div></div>
    </div>
    <p class="au-note"><?= $calls ?> appel<?= $calls > 1 ? 's' : '' ?> consigné<?= $calls > 1 ? 's' : '' ?><?= $firstCall ? ' depuis le ' . h(date('d/m/Y', strtotime($firstCall . ' UTC'))) : '' ?>. Les appels faits avant la mise en service de ce suivi n'y figurent pas.</p>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Solde de crédit</h2>
      <?php if ($balance): ?>
        <?php $pct = $balance['amount'] > 0 ? max(0, min(100, $balance['remaining'] / $balance['amount'] * 100)) : 0; ?>
        <p style="margin:6px 0;">Crédit déclaré <strong><?= h(ai_format_eur($balance['amount'])) ?></strong> le <?= h(date('d/m/Y à H:i', strtotime($balance['since'] . ' UTC'))) ?> — dépensé depuis <strong><?= h(ai_format_eur($balance['spent'])) ?></strong> — reste <strong><?= h(ai_format_eur($balance['remaining'])) ?></strong> (<?= (int) round($pct) ?> %).</p>
        <div class="au-bar<?= $pct < 15 ? ' is-low' : '' ?>"><span style="width:<?= (int) $pct ?>%"></span></div>
        <?php if ($balance['remaining'] <= 0): ?><p class="publish-status" data-kind="error" style="margin:8px 0;">Crédit épuisé d'après les estimations : vérifiez votre compte avant de relancer des générations.</p>
        <?php elseif ($pct < 15): ?><p class="publish-status" style="margin:8px 0;">Il reste moins de 15 % du crédit : pensez à le recharger.</p><?php endif; ?>
      <?php else: ?>
        <p class="hint" style="margin:6px 0;">Vous avez prépayé du crédit (Google Cloud, fal.ai) ? Indiquez-en le montant : l'estimation du solde en sera déduite au fil des générations.</p>
      <?php endif; ?>
      <form method="post" action="/admin/ai-usage-action.php" class="au-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="budget">
        <label>Crédit disponible (€)<input type="text" name="amount" inputmode="decimal" placeholder="10" value="<?= $balance ? h(number_format($balance['remaining'] > 0 ? $balance['remaining'] : 0, 2, ',', '')) : '' ?>"></label>
        <button type="submit" class="btn-small"><?= $balance ? 'Recaler le solde sur ce montant' : 'Enregistrer le crédit' ?></button>
      </form>
      <?php if ($balance): ?>
        <form method="post" action="/admin/ai-usage-action.php" style="margin-top:8px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="budget_clear">
          <button type="submit" class="btn-small">Retirer le crédit</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="au-grid2" style="margin-top:20px;">
      <div class="admin-block">
        <h2 style="font-size:1.1rem;">Par type d'appel</h2>
        <table class="au-table">
          <thead><tr><th>Type</th><th class="num">Appels</th><th class="num">Ce mois</th><th class="num">Total</th></tr></thead>
          <tbody>
            <?php foreach (AI_KINDS as $kind => $label): $r = $byKind[$kind] ?? null; ?>
              <tr>
                <td><?= h($label) ?></td>
                <td class="num"><?= (int) ($r['n'] ?? 0) ?></td>
                <td class="num"><?= h($eur($monthKind[$kind]['usd'] ?? 0)) ?></td>
                <td class="num"><?= h($eur($r['usd'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="admin-block">
        <h2 style="font-size:1.1rem;">Par mois</h2>
        <?php if ($byMonth): ?>
          <table class="au-table">
            <thead><tr><th>Mois</th><th class="num">Appels</th><th class="num">Coût estimé</th></tr></thead>
            <tbody><?php foreach ($byMonth as $r): ?>
              <tr><td><?= h(strftime_fr(strtotime($r['m'] . '-01'))) ?></td><td class="num"><?= (int) $r['n'] ?></td><td class="num"><?= h($eur($r['usd'])) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php else: ?><p class="au-note">Rien pour l'instant : la première génération apparaîtra ici.</p><?php endif; ?>
      </div>
    </div>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Coût estimé d'une opération</h2>
      <p class="au-note" style="margin:4px 0 0;">Avec les tarifs actuels — c'est aussi ce qui est annoncé à côté des boutons de génération.</p>
      <table class="au-table">
        <tbody><?php foreach ($estimates as [$label, $counts]): ?>
          <tr><td><?= h($label) ?></td><td class="num"><?= h(ai_estimate_label($counts)) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>

    <?php if ($topPieces): ?>
      <div class="admin-block" style="margin-top:20px;">
        <h2 style="font-size:1.1rem;">Pièces qui ont le plus coûté</h2>
        <table class="au-table">
          <thead><tr><th>Pièce</th><th class="num">Appels</th><th class="num">Coût estimé</th></tr></thead>
          <tbody><?php foreach ($topPieces as $r): ?>
            <tr><td>Réf. <?= h($r['ref']) ?> — <?= h($r['name'] ?? '(supprimée)') ?></td><td class="num"><?= (int) $r['n'] ?></td><td class="num"><?= h($eur($r['usd'])) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Derniers appels</h2>
      <?php if ($recent): ?>
        <div style="overflow-x:auto;"><table class="au-table">
          <thead><tr><th>Date</th><th>Type</th><th>Origine</th><th>Pièce</th><th class="num">Coût estimé</th></tr></thead>
          <tbody><?php foreach ($recent as $r): ?>
            <tr>
              <td><?= h(date('d/m H:i', strtotime($r['at'] . ' UTC'))) ?></td>
              <td><?= h(AI_KINDS[$r['kind']] ?? $r['kind']) ?></td>
              <td><?= h($origins[$r['script']] ?? $r['script']) ?></td>
              <td><?= $r['ref'] ? 'Réf. ' . h($r['ref']) . ($r['product_name'] ? ' — ' . h(mb_strimwidth($r['product_name'], 0, 32, '…')) : '') : '—' ?></td>
              <td class="num"><?= h($eur($r['cost_usd'])) ?></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php else: ?><p class="au-note">Aucun appel consigné pour l'instant.</p><?php endif; ?>
    </div>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Tarifs utilisés</h2>
      <p class="au-note" style="margin:4px 0 0;">Tarifs publics (en dollars) à la date de cette version — à vérifier sur <a href="https://ai.google.dev/gemini-api/docs/pricing" target="_blank" rel="noopener">ai.google.dev/pricing</a> et <a href="https://fal.ai/pricing" target="_blank" rel="noopener">fal.ai/pricing</a>. Le texte est facturé d'après le nombre réel de tokens renvoyé par l'API ; l'image, à l'unité. Les offres gratuites (quotas) ne sont pas déduites : l'estimation est celle d'un compte payant.</p>
      <form method="post" action="/admin/ai-usage-action.php" class="au-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="pricing">
        <label>Image générée ($ / image)<input type="text" name="image" value="<?= h((string) $pricing['image']) ?>"></label>
        <label>Texte : entrée ($ / M tokens)<input type="text" name="text_in" value="<?= h((string) $pricing['text_in']) ?>"></label>
        <label>Texte : sortie ($ / M tokens)<input type="text" name="text_out" value="<?= h((string) $pricing['text_out']) ?>"></label>
        <label>Recherche web ($ / requête)<input type="text" name="search" value="<?= h((string) $pricing['search']) ?>"></label>
        <label>Détourage fal.ai ($ / image)<input type="text" name="cutout" value="<?= h((string) $pricing['cutout']) ?>"></label>
        <label>Vidéo Veo Lite ($ / seconde)<input type="text" name="veo_lite" value="<?= h((string) $pricing['veo_lite']) ?>"></label>
        <label>Vidéo Veo Fast ($ / seconde)<input type="text" name="veo_fast" value="<?= h((string) $pricing['veo_fast']) ?>"></label>
        <label>Vidéo Veo Standard ($ / seconde)<input type="text" name="veo_std" value="<?= h((string) $pricing['veo_std']) ?>"></label>
        <label>Vidéo Wan 2.2 SiliconFlow ($ / vidéo)<input type="text" name="wan22" value="<?= h((string) $pricing['wan22']) ?>"></label>
        <label>Vidéo Wan 2.2 sur Modal ($ / vidéo, estimé)<input type="text" name="modal_video" value="<?= h((string) $pricing['modal_video']) ?>"></label>
        <label>Dollar → euro<input type="text" name="usd_eur" value="<?= h((string) $pricing['usd_eur']) ?>"></label>
        <button type="submit" class="btn-small">Enregistrer les tarifs</button>
      </form>
      <form method="post" action="/admin/ai-usage-action.php" style="margin-top:8px;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="pricing_reset">
        <button type="submit" class="btn-small">Rétablir les tarifs par défaut</button>
      </form>
    </div>
  </div>
</section>
</main>
</body>
</html>
