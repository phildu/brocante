<?php
// Vitrine de la plateforme : nom, slogan, contact, couleur, formules (affichées sur l'accueil et à l'inscription) et annuaire public
// (commerces visibles ou masqués, commerces d'ailleurs). Réglages enregistrés dans data/saas.json (includes/saas.php).
// Les limites écrites dans une formule (« jusqu'à 20 pièces »…) sont du texte affiché : elles ne sont pas appliquées par la plateforme.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';

$shops = tenant_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $cfg = saas_config();

    if ($action === 'save') {
        $visible = (array) ($_POST['listed'] ?? []);
        $cfg = array_merge($cfg, [
            'name' => trim((string) ($_POST['name'] ?? '')), 'tagline' => trim((string) ($_POST['tagline'] ?? '')),
            'contact' => trim((string) ($_POST['contact'] ?? '')), 'accent' => (string) ($_POST['accent'] ?? ''),
            'free_daily_cap' => max(0, min(1000, (int) ($_POST['free_daily_cap'] ?? 10))),
            'hidden' => array_values(array_diff(array_keys($shops), $visible)),
        ]);
        $ok = saas_config_save($cfg);
        portail_flash($ok ? 'Réglages enregistrés.' : "Impossible d'écrire data/saas.json (droits du dossier data).", $ok ? 'ok' : 'error');
    } elseif ($action === 'add_remote') {
        $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
        if (!gallery_remote_url_valid($url)) { portail_flash('Adresse non valide : une adresse http(s) publique, par exemple https://brocante.arrimage.com', 'error'); }
        elseif (in_array($url, array_column($cfg['remote'], 'url'), true)) { portail_flash('Ce commerce est déjà dans l\'annuaire.', 'error'); }
        elseif (!($feed = catalogue_remote($url))) { portail_flash("Cette adresse ne répond pas avec un catalogue ($url/catalogue.php) : le commerce doit être à jour de la plateforme.", 'error'); }
        else {
            $cfg['remote'][] = ['name' => trim((string) ($_POST['name'] ?? '')) ?: (string) ($feed['shop']['name'] ?? $url), 'url' => $url];
            saas_config_save($cfg);
            portail_flash('« ' . ($feed['shop']['name'] ?? $url) . ' » ajouté à l\'annuaire (' . count($feed['products']) . ' pièce(s) en ligne).');
        }
    } elseif ($action === 'remove_remote') {
        $i = (int) ($_POST['index'] ?? -1);
        unset($cfg['remote'][$i]);
        $cfg['remote'] = array_values($cfg['remote']);
        saas_config_save($cfg);
        portail_flash('Commerce retiré de l\'annuaire.');
    } elseif ($action === 'stripe_save') {
        $sk = trim((string) ($_POST['stripe_secret'] ?? ''));
        $wh = trim((string) ($_POST['stripe_webhook'] ?? ''));
        if ($sk === '' && $wh === '') portail_flash('Rien à enregistrer.');
        elseif ($sk !== '' && !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $sk)) portail_flash('Clé secrète non valide : elle commence par sk_test_ (essais) ou sk_live_ (réel).', 'error');
        elseif ($wh !== '' && !preg_match('/^whsec_[A-Za-z0-9]+$/', $wh)) portail_flash('Secret de signature non valide : il commence par whsec_.', 'error');
        elseif (!saas_stripe_save($sk, $wh)) portail_flash("Impossible d'écrire la clé (droits du dossier .secrets).", 'error');
        else portail_flash('Stripe enregistré. Cliquez sur « Tester la connexion » pour vérifier la clé.');
    } elseif ($action === 'stripe_test') {
        try {
            saas_stripe_request('GET', '/balance');
            portail_flash('Connexion à Stripe réussie (' . (saas_stripe_live() ? 'mode RÉEL : les paiements sont de vrais paiements' : 'mode test : aucun vrai paiement') . ').');
        } catch (Throwable $e) {
            portail_flash('Stripe répond : ' . $e->getMessage(), 'error');
        }
    } elseif ($action === 'stripe_clear') {
        @unlink(saas_stripe_file());
        portail_flash('Clés Stripe retirées : les formules payantes passent par validation manuelle.');
    } elseif ($action === 'refresh') {
        @unlink(saas_data_dir() . '/saas/cache.json');
        portail_flash('Annuaire actualisé : il relit les commerces à la prochaine visite.');
    }
    saas_audit_flash('Offres et annuaire');
    header('Location: /portail/offres.php');
    exit;
}

$flash = portail_flash();
$cfg = saas_config();
$origin = saas_origin();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Offres et annuaire — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .owrap { display: grid; gap: 16px; max-width: 920px; margin: 0 auto; padding: 0 16px 48px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .ocard h2 { margin: 0; font-size: 1.05rem; }
  .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
  .ocard label.f { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; flex: 1; min-width: 180px; }
  .ocard input[type=text], .ocard input[type=email], .ocard input[type=url], .ocard textarea { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .ocard textarea { min-height: 96px; }
  .plan-edit { border: 1px solid var(--line); border-radius: 8px; padding: 12px; display: grid; gap: 8px; }
  .member { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; } .member .name { flex: 1; } .member small { opacity: .7; }
  .swatch-in { width: 44px; height: 34px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Offres et annuaire</h1><p>La vitrine publique de la plateforme : accueil, formules et annuaire des commerces</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="<?= e($origin) ?>/" target="_blank" rel="noopener">Voir l'accueil</a><a class="btn" href="/portail/">Portail des commerces</a></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <form class="ocard" method="post" autocomplete="off" id="reglages">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <h2>La plateforme</h2>
      <div class="row">
        <label class="f">Nom<input type="text" name="name" required maxlength="40" value="<?= e($cfg['name']) ?>"></label>
        <label class="f">E-mail de contact (reçoit les demandes d'inscription)<input type="email" name="contact" maxlength="80" value="<?= e($cfg['contact']) ?>" placeholder="contact@votre-domaine.fr"></label>
        <label class="f" style="flex:none;min-width:0;">Couleur<input class="swatch-in" type="color" name="accent" value="<?= e($cfg['accent']) ?>"></label>
      </div>
      <label class="f">Slogan<input type="text" name="tagline" maxlength="160" value="<?= e($cfg['tagline']) ?>"></label>

      <label class="f" style="max-width:360px">Plafond de boutiques gratuites par 24 h (0 = aucun plafond)<input type="text" name="free_daily_cap" inputmode="numeric" pattern="[0-9]{1,4}" value="<?= (int) $cfg['free_daily_cap'] ?>"></label>
      <p class="meta" style="margin:-4px 0 0">Les boutiques se créent sans validation de votre part : ce plafond limite les créations gratuites en masse (par défaut 10 par jour). Les formules payantes ne sont pas concernées.</p>

      <h2 style="margin-top:8px">Tarifs</h2>
      <p class="meta">Les formules, les prix mensuels et annuels, les tranches par nombre d'articles et les tarifs propres à chaque galerie se règlent dans <a href="/portail/tarifs.php"><strong>Tarifs</strong></a>.</p>

      <h2 style="margin-top:8px">Annuaire : commerces de ce serveur</h2>
      <p class="meta">Cochez ceux qui apparaissent dans l'annuaire public et sur l'accueil.</p>
      <?php foreach ($shops as $slug => $shop): ?>
        <label class="member"><input type="checkbox" name="listed[]" value="<?= e($slug) ?>"<?= in_array($slug, $cfg['hidden'], true) ? '' : ' checked' ?>>
          <span class="name"><strong><?= e($shop['name']) ?></strong> <small>— <?= e($slug) ?></small></span></label>
      <?php endforeach; ?>
      <p><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>

    <section class="ocard" id="stripe">
      <h2>Paiement des formules (Stripe)</h2>
      <?php $sc = saas_stripe_config(); ?>
      <p class="meta">Le client d'une formule payante règle par carte sur Stripe (abonnement mensuel) ; sa boutique est générée dès que le paiement est confirmé. Ce sont <strong>les clés Stripe de la plateforme</strong> (votre compte), sans rapport avec celles de chaque commerce. Sans clé, les formules payantes passent par votre validation manuelle.</p>
      <p><span class="pill <?= $sc['secret_key'] !== '' ? 'done' : 'sent' ?>"><?= $sc['secret_key'] === '' ? 'Non configuré' : (saas_stripe_live() ? 'Configuré — mode RÉEL' : 'Configuré — mode test') ?></span>
        <?php if ($sc['secret_key'] !== ''): ?><span class="meta">clé <?= e(substr($sc['secret_key'], 0, 8)) ?>…<?= e(substr($sc['secret_key'], -4)) ?> · secret de signature : <?= $sc['webhook_secret'] !== '' ? 'enregistré' : 'manquant' ?></span><?php endif; ?></p>
      <form method="post" autocomplete="off" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="stripe_save">
        <label class="f"><span>Clé secrète (<code>sk_test_…</code> ou <code>sk_live_…</code>)</span><input type="password" name="stripe_secret" placeholder="<?= $sc['secret_key'] !== '' ? 'déjà enregistrée — laisser vide pour la garder' : 'sk_test_…' ?>"></label>
        <label class="f"><span>Secret de signature du webhook (<code>whsec_…</code>)</span><input type="password" name="stripe_webhook" placeholder="<?= $sc['webhook_secret'] !== '' ? 'déjà enregistré' : 'whsec_…' ?>"></label>
        <button class="btn btn-primary" type="submit">Enregistrer</button>
      </form>
      <p class="meta">Dans le tableau de bord Stripe → Développeurs → Webhooks, ajoutez le point de terminaison <code><?= e($origin) ?>/inscription/webhook.php</code> avec les événements <code>checkout.session.completed</code> et <code>customer.subscription.deleted</code>, puis collez ici son secret de signature. (Le webhook est un filet : la boutique se génère déjà au retour du paiement.)</p>
      <?php if ($sc['secret_key'] !== ''): ?>
        <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn small" type="submit" name="action" value="stripe_test">Tester la connexion</button>
          <button class="btn small danger" type="submit" name="action" value="stripe_clear" onclick="return confirm('Retirer les clés Stripe de la plateforme ?');">Retirer les clés</button></form>
      <?php endif; ?>
    </section>

    <section class="ocard">
      <h2>Annuaire : commerces d'ailleurs</h2>
      <p class="meta">Des commerces hébergés ailleurs (par exemple le Petit Chalet) : leur catalogue est lu à <code>/catalogue.php</code>.</p>
      <?php foreach ($cfg['remote'] as $i => $r): ?>
        <form method="post" class="member"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_remote"><input type="hidden" name="index" value="<?= (int) $i ?>">
          <span class="name"><strong><?= e($r['name'] ?: $r['url']) ?></strong> <small><code><?= e($r['url']) ?></code></small></span><button class="btn small danger" type="submit">Retirer</button></form>
      <?php endforeach; ?>
      <form method="post" class="row" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_remote">
        <label class="f">Adresse de la boutique<input type="url" name="url" required placeholder="https://brocante.arrimage.com"></label>
        <label class="f">Nom (facultatif)<input type="text" name="name" maxlength="80"></label>
        <button class="btn" type="submit">Tester et ajouter</button>
      </form>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refresh"><button class="btn small" type="submit">Actualiser l'annuaire maintenant</button> <span class="meta">relu toutes les 10 minutes</span></form>
    </section>
  </div>
</div>
</body>
</html>
