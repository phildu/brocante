<?php
// Statut d'une boutique (publiée / en test / en pause) et geste commercial : gratuité d'accès (totale ou jusqu'à une date)
// ou avantage de facturation discrétionnaire (avoir, remise). Enregistré dans data/shop-status/<slug>.json : le statut
// ferme la boutique au public (includes/header.php, catalogue.php). Un avoir ou une remise ne change pas l'accès et doit
// être appliqué dans Stripe s'il y a un abonnement.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';

$shops = tenant_list();
$slug = (string) ($_POST['slug'] ?? $_GET['slug'] ?? '');
if (!isset($shops[$slug])) {
    portail_flash('Commerce introuvable.', 'error');
    header('Location: /portail/');
    exit;
}
$shop = $shops[$slug];
$shopStripe = stripe_modes_status(tenant_file($shop, 'secrets_dir'));
$back = '/portail/boutique-statut.php?slug=' . rawurlencode($slug);
$shopUrl = portail_shop_url($slug, $shop);
$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $state = shop_state_load($slug);
    if ($action === 'statut') {
        $status = (string) ($_POST['state'] ?? '');
        if (!in_array($status, array_keys(SHOP_STATE_STATUSES), true)) {
            portail_flash('Statut inconnu.', 'error');
        } else {
            $state['status'] = $status;
            $ok = shop_state_save($slug, $state);
            portail_flash($ok
                ? ($status === 'published' ? 'Boutique publiée : elle est visible à son adresse publique.'
                    : ($status === 'test' ? 'Boutique en test : elle n’est plus visible que par son lien d’aperçu.'
                        : 'Boutique en pause : elle n’est plus visible publiquement.'))
                : 'Impossible d’enregistrer le statut de la boutique.', $ok ? 'ok' : 'error');
        }
    } elseif ($action === 'geste') {
        $kind = (string) ($_POST['gesture_kind'] ?? 'none');
        $note = mb_substr(trim((string) ($_POST['gesture_note'] ?? '')), 0, 300);
        $until = 0;
        $dateRaw = trim((string) ($_POST['gesture_until'] ?? ''));
        if ($dateRaw !== '') {
            $until = strtotime($dateRaw . ' 23:59:59') ?: 0;
            if (!$until || $until <= time()) {
                portail_flash('Indiquez une date de fin dans le futur (ou laissez la date vide).', 'error');
                header('Location: ' . $back);
                exit;
            }
        }
        $gesture = null;
        if ($kind === 'forever') {
            $gesture = ['kind' => 'forever', 'until' => 0, 'note' => $note, 'created' => time()];
        } elseif ($kind === 'until') {
            if ($until <= 0) {
                portail_flash('Indiquez une date de fin de gratuité dans le futur.', 'error');
                header('Location: ' . $back);
                exit;
            }
            $gesture = ['kind' => 'until', 'until' => $until, 'note' => $note, 'created' => time()];
        } elseif ($kind === 'credit') {
            $cents = (int) round(((float) str_replace(',', '.', (string) ($_POST['gesture_amount'] ?? ''))) * 100);
            if ($cents <= 0 || $cents > 1000000) {
                portail_flash('Indiquez un montant d’avoir entre 0,01 € et 10 000 €.', 'error');
                header('Location: ' . $back);
                exit;
            }
            $gesture = ['kind' => 'credit', 'amount_cents' => $cents, 'until' => $until, 'note' => $note, 'created' => time()];
        } elseif ($kind === 'discount') {
            $percent = (int) ($_POST['gesture_percent'] ?? 0);
            if ($percent < 1 || $percent > 100) {
                portail_flash('Indiquez une remise entre 1 % et 100 %.', 'error');
                header('Location: ' . $back);
                exit;
            }
            $gesture = ['kind' => 'discount', 'percent' => $percent, 'until' => $until, 'note' => $note, 'created' => time()];
        } elseif ($kind !== 'none') {
            portail_flash('Geste commercial inconnu.', 'error');
            header('Location: ' . $back);
            exit;
        }
        $state['gesture'] = $gesture;
        $ok = shop_state_save($slug, $state);
        $billing = in_array($kind, ['credit', 'discount'], true);
        portail_flash($ok
            ? ($gesture
                ? ($billing
                    ? 'Geste de facturation enregistré. Il ne modifie pas automatiquement Stripe : appliquez l’avoir ou la remise dans Stripe pour la même période.'
                    : 'Geste commercial enregistré : la boutique reste ouverte pendant la période offerte.')
                : 'Geste commercial retiré.')
            : 'Impossible d’enregistrer le geste commercial.', $ok ? 'ok' : 'error');
    } else {
        portail_flash('Action inconnue.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$state = shop_state_load($slug);
$live = shop_state_is_public($state);
$previewUrl = $shopUrl . '/' . ($live ? '' : '?apercu=' . rawurlencode(shop_preview_token($slug)));
$ges = $state['gesture'] ?? null;
$subId = '';
foreach (saas_requests() as $r) {
    if (($r['slug'] ?? '') === $slug && !empty($r['payment']['subscription'])) { $subId = (string) $r['payment']['subscription']; break; }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Statut &amp; geste commercial — <?= e($shop['name']) ?> — Portail</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Statut & geste commercial — ' . $shop['name'],
    'eyebrow' => 'Boutique',
    'desc' => 'Ouvrir, mettre en test ou en pause la boutique, et garder trace d’un accès offert.',
    'section' => 'Boutiques',
    'actions' => '<a class="btn" href="/portail/?voir=' . e(rawurlencode($slug)) . '">Retour au portail</a>'
        . '<a class="btn" href="/portail/?voir=' . e(rawurlencode($slug)) . '&amp;acces=' . e(rawurlencode($slug)) . '">Accès admin</a>'
        . '<a class="btn btn-primary" href="' . e($previewUrl) . '" target="_blank" rel="noopener">' . ($live ? 'Ouvrir la boutique ↗' : 'Aperçu ↗') . '</a>',
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="gwrap ga-page">
    <section class="gcard">
      <h2><?= e($shop['name']) ?>
        <span class="pill <?= $live ? 'done' : 'sent' ?>"><?= e(shop_state_label($state)) ?></span>
        <?php if (!empty($state['gesture'])): ?><span class="pill <?= shop_gesture_current($state) ? 'done' : 'sent' ?>"><?= e(shop_gesture_label($state)) ?></span><?php endif; ?>
      </h2>
      <p class="meta">Adresse publique : <a href="<?= e($previewUrl) ?>" target="_blank" rel="noopener"><code class="url"><?= e(preg_replace('#^https?://#', '', $shopUrl)) ?></code></a>
        — <?= $live ? 'visible de tous.' : 'seul le lien d’aperçu l’ouvre pour le moment.' ?></p>
      <?php if (!$live): ?>
        <p class="flash" data-kind="ok" role="status">Lien d’aperçu privé (à ne pas diffuser) : <code class="url"><?= e($previewUrl) ?></code></p>
      <?php endif; ?>
      <p class="meta">Dernière modification : <?= !empty($state['updated']) ? e(date('d/m/Y à H:i', (int) $state['updated'])) : 'jamais' ?>.</p>
    </section>

    <section class="gcard">
      <h2>Visibilité de la boutique</h2>
      <p class="meta"><strong>Publiée</strong> : ouverte à tous à son adresse. <strong>En test</strong> : retirée du public et des galeries, accessible seulement par le lien d’aperçu — utile pendant la mise en place. <strong>En pause</strong> : fermée au public avec un message d’indisponibilité ; rien n’est supprimé.</p>
      <form method="post" class="row" style="align-items:center;gap:6px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <input type="hidden" name="action" value="statut">
        <button class="btn small<?= $live ? ' btn-primary' : '' ?>" type="submit" name="state" value="published"<?= $live ? ' disabled' : '' ?>>Publiée — visible de tous</button>
        <button class="btn small<?= ($state['status'] ?? '') === 'test' ? ' btn-primary' : '' ?>" type="submit" name="state" value="test"<?= ($state['status'] ?? '') === 'test' ? ' disabled' : '' ?>>En test — lien d’aperçu seulement</button>
        <button class="btn small<?= ($state['status'] ?? '') === 'paused' ? ' btn-primary' : '' ?>" type="submit" name="state" value="paused"<?= ($state['status'] ?? '') === 'paused' ? ' disabled' : '' ?>>En pause — fermée au public</button>
      </form>
    </section>

    <section class="gcard" id="stripe">
      <h2>Stripe — encaissement de la boutique
        <span class="pill <?= $shopStripe['modes'][$shopStripe['mode']]['secret'] ? 'done' : 'sent' ?>">Mode <?= e(STRIPE_MODES[$shopStripe['mode']]) ?></span>
      </h2>
      <p class="meta"><strong>Mode test</strong> : les cartes de test de Stripe, aucun vrai paiement. <strong>Mode réel</strong> : la boutique encaisse ses clients pour de vrai. Les clés des deux modes sont conservées : la bascule ne demande pas de les recoller.
        Ici, Stripe paie <em>les clients de la boutique</em> ; l’abonnement de la boutique à la plateforme se règle avec les clés de la plateforme.</p>
      <p class="meta">Test : <?= $shopStripe['modes']['test']['secret'] ? 'clé ' . e($shopStripe['modes']['test']['secret_mask']) : 'sans clé' ?>
        · Réel : <?= $shopStripe['modes']['live']['secret'] ? 'clé ' . e($shopStripe['modes']['live']['secret_mask']) : 'sans clé' ?></p>
      <a class="btn btn-primary" href="/portail/stripe.php?boutique=<?= e(urlencode($slug)) ?>#cles">Basculer le mode / gérer les clés</a>
    </section>

    <section class="gcard">
      <h2>Geste commercial</h2>
      <p class="meta">Offrir un avantage à votre guise, comme un avoir dans une boutique : <strong>gratuité</strong> (totale ou jusqu’à une date), <strong>avoir</strong> en euros ou <strong>remise</strong> en pourcentage pour une période. La gratuité garde la boutique ouverte ; l’avoir et la remise sont seulement des gestes de facturation, mémorisés ici pour le suivi. <a href="/accueil/conditions.php#geste" target="_blank" rel="noopener">Voir la clause de geste commercial</a>.</p>
      <?php if ($ges): ?>
        <p class="flash" data-kind="<?= shop_gesture_current($state) ? 'ok' : 'error' ?>" role="status"><strong><?= e(shop_gesture_label($state)) ?></strong><?php if (($ges['note'] ?? '') !== ''): ?> — <?= e($ges['note']) ?><?php endif; ?>
          <?php if (!empty($ges['until']) && (int) $ges['until'] <= time()): ?> (terminé le <?= e(date('d/m/Y', (int) $ges['until'])) ?>)<?php endif; ?></p>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <input type="hidden" name="action" value="geste">
        <div class="row">
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="none"<?= !$ges ? ' checked' : '' ?>> Aucun geste</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="forever"<?= ($ges['kind'] ?? '') === 'forever' ? ' checked' : '' ?>> Gratuité totale</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="until"<?= ($ges['kind'] ?? '') === 'until' ? ' checked' : '' ?>> Gratuit jusqu’à une date</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="credit"<?= ($ges['kind'] ?? '') === 'credit' ? ' checked' : '' ?>> Avoir en euros</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="discount"<?= ($ges['kind'] ?? '') === 'discount' ? ' checked' : '' ?>> Remise en %</span></label>
        </div>
        <div class="row">
          <label class="f">Montant de l’avoir (€)<input type="text" name="gesture_amount" inputmode="decimal" value="<?= ($ges['kind'] ?? '') === 'credit' && !empty($ges['amount_cents']) ? e(number_format((int) $ges['amount_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="ex. 19,90"></label>
          <label class="f">Pourcentage de remise (%)<input type="number" name="gesture_percent" min="1" max="100" step="1" value="<?= ($ges['kind'] ?? '') === 'discount' && !empty($ges['percent']) ? (int) $ges['percent'] : '' ?>" placeholder="ex. 20"></label>
          <label class="f">Fin de validité (facultative, incluse)<input type="date" name="gesture_until" value="<?= !empty($ges['until']) ? e(date('Y-m-d', (int) $ges['until'])) : '' ?>"></label>
        </div>
        <label class="f">Note interne (facultative)<input type="text" name="gesture_note" maxlength="300" value="<?= e((string) ($ges['note'] ?? '')) ?>" placeholder="ex. dédommagement incident du 2 novembre, offre d’ouverture, parrainage…"></label>
        <p><button class="btn btn-primary" type="submit">Enregistrer le geste commercial</button></p>
        <p class="meta">L’avoir et la remise ne modifient pas automatiquement Stripe : appliquez le coupon ou l’avoir dans Stripe pour la même période. La gratuité non plus ne suspend pas un abonnement : pensez à le mettre en pause dans Stripe.</p>
        <?php if ($subId !== ''): ?>
          <p class="meta">Abonnement Stripe de cette boutique : <code class="url"><?= e($subId) ?></code>.</p>
        <?php endif; ?>
      </form>
    </section>

    <p class="meta">Le statut et le geste sont enregistrés dans <code>data/shop-status/<?= e($slug) ?>.json</code>, hors du code déployé : ils survivent aux mises à jour. Les boutiques en test ou en pause disparaissent aussi des galeries commerciales et de l’annuaire.</p>
  </div>
</div>
</body>
</html>
