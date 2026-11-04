<?php
// Bascule Stripe test / réel de la plateforme et de chaque boutique.
// La plateforme encaisse les formules, les abonnements des galeries, les crédits IA et les noms de domaine ; chaque boutique
// encaisse ses propres clients avec ses propres clés. Les deux jeux de clés (test et réel) sont conservés : la bascule
// recopie simplement la paire choisie dans les fichiers actifs lus par le paiement et les webhooks.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/stripe-mode.php';

$shops = tenant_list();
$platform = saas_stripe_modes_status();
$shopSlug = strtolower((string) ($_GET['boutique'] ?? ''));
if (!isset($shops[$shopSlug])) $shopSlug = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $postSlug = strtolower((string) ($_POST['slug'] ?? ''));
    $back = '/portail/stripe.php' . ($postSlug !== '' && isset($shops[$postSlug]) ? '?boutique=' . urlencode($postSlug) : '');
    $mode = (string) ($_POST['mode'] ?? '');
    switch ($action) {
        case 'platform_save':
            $sk = trim((string) ($_POST['stripe_secret'] ?? ''));
            $wh = trim((string) ($_POST['stripe_webhook'] ?? ''));
            if (!isset(STRIPE_MODES[$mode])) { portail_flash('Mode Stripe inconnu.', 'error'); break; }
            if ($sk === '' && $wh === '') { portail_flash('Rien à enregistrer.', 'error'); break; }
            if ($sk !== '' && !preg_match('/^(?:sk|rk)_' . $mode . '_[A-Za-z0-9]+$/', $sk)) { portail_flash('Clé secrète non valide : pour le mode ' . STRIPE_MODES[$mode] . ', elle commence par sk_' . $mode . '_ ou rk_' . $mode . '_.', 'error'); break; }
            if ($wh !== '' && !preg_match('/^whsec_[A-Za-z0-9]+$/', $wh)) { portail_flash('Secret de signature non valide : il commence par whsec_.', 'error'); break; }
            $ok = saas_stripe_save_mode($mode, $sk, $wh);
            portail_flash($ok ? 'Clés du mode ' . STRIPE_MODES[$mode] . ' enregistrées pour la plateforme.' : "Impossible d'écrire les clés (droits du dossier .secrets).", $ok ? 'ok' : 'error');
            break;
        case 'platform_mode':
            [$ok, $message] = saas_stripe_mode_set($mode);
            portail_flash($message, $ok ? 'ok' : 'error');
            break;
        case 'platform_test':
            try {
                saas_stripe_request('GET', '/balance');
                portail_flash('Connexion à Stripe réussie (' . (saas_stripe_live() ? 'mode réel : les paiements sont de vrais paiements' : 'mode test : aucun vrai paiement') . ').');
            } catch (Throwable $ex) {
                portail_flash('Stripe répond : ' . $ex->getMessage(), 'error');
            }
            break;
        case 'shop_save':
            if (!isset($shops[$postSlug])) { portail_flash('Boutique introuvable.', 'error'); break; }
            if (!isset(STRIPE_MODES[$mode])) { portail_flash('Mode Stripe inconnu.', 'error'); break; }
            $secret = trim((string) ($_POST['stripe_secret'] ?? ''));
            $publishable = trim((string) ($_POST['stripe_publishable'] ?? ''));
            $webhook = trim((string) ($_POST['stripe_webhook'] ?? ''));
            if (str_starts_with($secret, 'pk_') && (str_starts_with($publishable, 'sk_') || str_starts_with($publishable, 'rk_'))) {
                [$secret, $publishable] = [$publishable, $secret];
            }
            [$ok, $message] = stripe_mode_save($mode, ['secret' => $secret, 'publishable' => $publishable, 'webhook' => $webhook], tenant_file($shops[$postSlug], 'secrets_dir'));
            portail_flash('« ' . $shops[$postSlug]['name'] . ' » — ' . $message, $ok ? 'ok' : 'error');
            break;
        case 'shop_mode':
            if (!isset($shops[$postSlug])) { portail_flash('Boutique introuvable.', 'error'); break; }
            [$ok, $message] = stripe_mode_activate($mode, tenant_file($shops[$postSlug], 'secrets_dir'));
            portail_flash('« ' . $shops[$postSlug]['name'] . ' » — ' . $message, $ok ? 'ok' : 'error');
            break;
        default:
            portail_flash('Action inconnue.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$editShop = $shopSlug !== '' ? $shops[$shopSlug] : null;
$editShopStatus = $editShop ? stripe_modes_status(tenant_file($editShop, 'secrets_dir')) : null;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Stripe : mode test / réel — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .stripe-mode { font-size: .82rem; }
  .stripe-mode .pill { white-space: nowrap; }
  .shop-stripe { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--line); }
  .shop-stripe:first-of-type { border-top: 0; }
  .shop-stripe .who { min-width: 220px; flex: 1; }
  .shop-stripe .who small { display: block; opacity: .75; }
  .shop-stripe .actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .key-pair { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin: 10px 0; }
  .key-pair summary { cursor: pointer; font-weight: 700; }
  .mode-banner { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Stripe : mode test / réel',
    'eyebrow' => 'Boutiques',
    'desc' => 'Basculer les paiements de la plateforme ou d’une boutique entre test et réel, sans recoller les clés.',
    'section' => 'Boutiques',
    'actions' => '<a class="btn" href="/portail/">← Portail des commerces</a>'
        . '<a class="btn" href="/portail/offres.php#stripe">Offres et annuaire</a>'
        . '<a class="btn" href="/portail/galeries.php">Galeries</a>',
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="gwrap ga-page">
    <section class="gcard" id="plateforme">
      <h2>Plateforme <span class="pill <?= $platform['modes'][$platform['mode']]['configured'] ? 'done' : 'sent' ?>">Mode <?= e(STRIPE_MODES[$platform['mode']]) ?></span></h2>
      <p class="meta">Les clés de la plateforme encaissent les formules, les abonnements des galeries, les crédits IA et les noms de domaine. Elles n’ont rien à voir avec les clés des boutiques ci-dessous. <strong>Mode test</strong> : cartes de test, aucun vrai paiement. <strong>Mode réel</strong> : les clients paient vraiment.</p>
      <div class="mode-banner stripe-mode">
        <?php foreach (STRIPE_MODES as $mode => $label): $st = $platform['modes'][$mode]; ?>
          <span class="pill <?= $platform['mode'] === $mode ? 'done' : '' ?>"><?= e($label) ?> : <?= $st['configured'] ? 'clé ' . e($st['secret_mask']) : 'sans clé' ?><?= $st['webhook'] ? ' · webhook ok' : ' · webhook manquant' ?></span>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="platform_mode"><input type="hidden" name="mode" value="<?= e($mode) ?>">
            <button class="btn small<?= $platform['mode'] === $mode ? ' btn-primary' : '' ?>" type="submit"<?= $platform['mode'] === $mode || !$st['configured'] ? ' disabled' : '' ?>>Basculer en mode <?= e(strtolower($label)) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
      <?php foreach (STRIPE_MODES as $mode => $label): $st = $platform['modes'][$mode]; ?>
        <details class="key-pair"<?= $platform['mode'] === $mode ? ' open' : '' ?>>
          <summary>Clés du mode <?= e(strtolower($label)) ?> — <?= $st['configured'] ? 'enregistrées (' . e($st['secret_mask']) . ')' : 'à renseigner' ?></summary>
          <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="platform_save"><input type="hidden" name="mode" value="<?= e($mode) ?>">
            <div class="row">
              <label class="f">Clé secrète (<code>sk_…</code> ou <code>rk_…</code>)<input type="password" name="stripe_secret" placeholder="<?= $st['configured'] ? 'déjà enregistrée — laisser vide pour la garder' : 'sk_' . e($mode) . '_… / rk_' . e($mode) . '_…' ?>"></label>
              <label class="f">Secret de signature du webhook (<code>whsec_…</code>)<input type="password" name="stripe_webhook" placeholder="<?= $st['webhook'] ? 'déjà enregistré' : 'whsec_…' ?>"></label>
              <button class="btn btn-primary" type="submit">Enregistrer les clés <?= e(strtolower($label)) ?></button>
            </div>
          </form>
          <p class="meta">Webhook de la plateforme : <code><?= e($origin) ?>/inscription/webhook.php</code></p>
        </details>
      <?php endforeach; ?>
      <?php if ($platform['modes'][$platform['mode']]['configured']): ?>
        <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn small" type="submit" name="action" value="platform_test">Tester la connexion (mode <?= e(strtolower(STRIPE_MODES[$platform['mode']])) ?>)</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="gcard" id="boutiques">
      <h2>Boutiques</h2>
      <p class="meta">Chaque boutique a ses propres clés Stripe. Les deux modes sont conservés : une bascule ne change que la paire active. Pour enregistrer les clés d’un mode, ouvrez « Clés » sur la boutique.</p>
      <?php foreach ($shops as $slug => $shop): $st = stripe_modes_status(tenant_file($shop, 'secrets_dir')); $mode = $st['mode']; ?>
        <div class="shop-stripe">
          <div class="who">
            <strong><?= e($shop['name']) ?></strong>
            <small><?= e($slug) ?> · <span class="pill <?= $st['modes'][$mode]['secret'] ? 'done' : 'sent' ?>">Mode <?= e(STRIPE_MODES[$mode]) ?></span>
              · test : <?= $st['modes']['test']['secret'] ? 'clé ' . e($st['modes']['test']['secret_mask']) : 'sans clé' ?>
              · réel : <?= $st['modes']['live']['secret'] ? 'clé ' . e($st['modes']['live']['secret_mask']) : 'sans clé' ?></small>
          </div>
          <div class="actions">
            <?php foreach (STRIPE_MODES as $target => $label): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="shop_mode"><input type="hidden" name="slug" value="<?= e($slug) ?>"><input type="hidden" name="mode" value="<?= e($target) ?>">
                <button class="btn small<?= $mode === $target ? ' btn-primary' : '' ?>" type="submit"<?= $mode === $target || !$st['modes'][$target]['secret'] ? ' disabled' : '' ?>>Basculer en <?= e(strtolower($label)) ?></button>
              </form>
            <?php endforeach; ?>
            <a class="btn small" href="/portail/stripe.php?boutique=<?= e(urlencode($slug)) ?>#cles">Clés</a>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <?php if ($editShop): ?>
      <section class="gcard" id="cles">
        <h2>Clés Stripe — <?= e($editShop['name']) ?> <span class="pill <?= $editShopStatus['modes'][$editShopStatus['mode']]['secret'] ? 'done' : 'sent' ?>">Mode <?= e(STRIPE_MODES[$editShopStatus['mode']]) ?></span></h2>
        <p class="meta">Clés du compte Stripe de cette boutique. <a href="/portail/stripe.php">Revenir à la liste</a> · webhook de la boutique : <code><?= e(rtrim(portail_shop_url($shopSlug, $editShop), '/')) ?>/stripe-webhook.php</code></p>
        <?php foreach (STRIPE_MODES as $mode => $label): $st = $editShopStatus['modes'][$mode]; ?>
          <details class="key-pair"<?= $editShopStatus['mode'] === $mode ? ' open' : '' ?>>
            <summary>Clés du mode <?= e(strtolower($label)) ?> — <?= $st['secret'] ? 'enregistrées (' . e($st['secret_mask']) . ')' : 'à renseigner' ?><?= $st['webhook'] ? ' · webhook ok' : '' ?></summary>
            <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="shop_save"><input type="hidden" name="slug" value="<?= e($shopSlug) ?>"><input type="hidden" name="mode" value="<?= e($mode) ?>">
              <div class="row">
                <label class="f">Clé secrète (<code>sk_…</code> ou <code>rk_…</code>)<input type="password" name="stripe_secret" autocomplete="off" placeholder="<?= $st['secret'] ? 'déjà enregistrée — laisser vide pour la garder' : 'sk_' . e($mode) . '_… / rk_' . e($mode) . '_…' ?>"></label>
                <label class="f">Clé publiable (<code>pk_<?= e($mode) ?>_…</code>)<input type="text" name="stripe_publishable" autocomplete="off" placeholder="<?= $st['publishable'] ? 'déjà enregistrée — laisser vide pour la garder' : 'pk_' . e($mode) . '_…' ?>"></label>
              </div>
              <label class="f">Secret de signature du webhook (<code>whsec_…</code>)<input type="password" name="stripe_webhook" autocomplete="off" placeholder="<?= $st['webhook'] ? 'déjà enregistré' : 'whsec_…' ?>"></label>
              <p><button class="btn btn-primary" type="submit">Enregistrer les clés <?= e(strtolower($label)) ?></button></p>
            </form>
          </details>
        <?php endforeach; ?>
        <p class="meta">Le mode actif décide des paiements encaissés par la boutique : test = cartes de test, réel = vrais paiements. Les commandes en attente d’un mode restent vérifiables après la bascule.</p>
      </section>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
