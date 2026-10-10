<?php
// Création de boutique — ÉTAPE 1 : la formule et l'adresse e-mail. Ensuite :
//   formule payante (et Stripe configuré)  → Stripe Checkout (abonnement mensuel), puis retour sur merci.php qui confirme le paiement et ouvre l'étape 3 ;
//   formule gratuite, ou paiement en ligne indisponible → étape 3 tout de suite.
// ÉTAPE 3 (creer.php) : nom, adresse, design et compte de la boutique — elle GÉNÈRE la boutique, sans validation de l'exploitant, et mène le client sur sa boutique.
// Protections : jeton CSRF, champ piège, 5 demandes par heure et par adresse IP. Rien n'est créé avant l'étape 3.
require_once __DIR__ . '/../includes/saas.php';

session_start();
if (empty($_SESSION['saas_csrf'])) $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));

$cfg = saas_config();
$plans = $cfg['plans'];
$plansByKey = array_column($plans, null, 'key');
$origin = saas_origin();
$stripeOn = saas_stripe_configured();
$errors = [];
if (!empty($_SESSION['inscription_flash'])) {   // message laissé par la connexion sociale (oauth/finish.php)
    $errors['form'] = (string) $_SESSION['inscription_flash'];
    unset($_SESSION['inscription_flash']);
}
$resumable = null;
$v = ['email' => '', 'plan' => (string) ($_GET['formule'] ?? ($plans[1]['key'] ?? $plans[0]['key']))];

// Retour d'un paiement annulé : la demande reste réservée 24 h et peut être reprise.
if (isset($_GET['annule']) && ($r = saas_request_load((string) $_GET['annule'])) && ($r['status'] ?? '') === 'awaiting_payment' && saas_request_active($r)) {
    $resumable = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['email'] = trim((string) ($_POST['email'] ?? ''));
    $v['plan'] = trim((string) ($_POST['plan'] ?? $v['plan']));
    if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
    } elseif (isset($_POST['resume'])) {
        // Reprendre le paiement d'une demande annulée.
        $r = saas_request_load((string) $_POST['resume']);
        if ($r && ($r['status'] ?? '') === 'awaiting_payment' && saas_request_active($r) && isset($plansByKey[$r['plan']]) && saas_plan_payable($plansByKey[$r['plan']])) {
            header('Location: ' . saas_payment_url($r, $plansByKey[$r['plan']], $origin), true, 303);
            exit;
        }
        $errors['form'] = 'Cette demande ne peut plus être reprise : refaites-la ci-dessous.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        // Champ piège rempli : un robot. On fait comme si c'était accepté.
        header('Location: /inscription/?recu=1', true, 303);
        exit;
    } elseif (saas_rate_limited()) {
        $errors['form'] = 'Trop de demandes depuis votre connexion : réessayez dans une heure.';
    } elseif ((saas_plan_cents($plansByKey[$v['plan']] ?? []) ?? 0) === 0 && saas_free_cap_reached()) {
        $errors['form'] = "Les créations de boutiques gratuites sont complètes pour aujourd'hui : revenez demain, ou choisissez une formule payante.";
    } else {
        $res = saas_request_start($_POST);
        if ($res['ok']) {
            $req = $res['request'];
            $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . saas_after_start_url($req, $origin), true, 303);   // → Stripe (ou étape 3 si la formule est gratuite / Stripe en panne)
            exit;
        }
        $errors = $res['errors'];
    }
}

$err = static fn (string $k): string => isset($errors[$k]) ? '<span class="err" role="alert">' . saas_e($errors[$k]) . '</span>' : '';

saas_page_start('Créer ma boutique — ' . $cfg['name'], 'Choisissez votre formule, réglez en ligne, puis créez votre boutique : nom, design, compte.', 'inscription');
?>
<div class="wrap">
  <div class="page-title"><p class="eyebrow">Création de boutique</p><h1>Créer ma boutique</h1>
    <ol class="stepper" aria-label="Étapes"><li class="is-current" aria-current="step"><b>1</b> Ma formule</li><li><b>2</b> Paiement</li><li><b>3</b> Ma boutique</li></ol>
    <p class="lede"><?= $stripeOn ? 'Choisissez votre formule et réglez en ligne. Vous créerez ensuite votre boutique : son nom, son design et votre compte.' : 'Choisissez votre formule. Vous créerez ensuite votre boutique : son nom, son design et votre compte.' ?></p></div>

  <?php if (isset($_GET['recu'])): ?>
    <div class="form-card"><div class="notice ok" role="status"><strong>Demande reçue, merci !</strong></div><p><a class="btn" href="/">Retour à l'accueil</a></p></div>
  <?php else: ?>
    <?php if ($resumable): ?>
      <div class="form-card" style="margin-bottom:20px">
        <div class="notice" role="status"><strong>Paiement non terminé.</strong> Votre demande est gardée 24 heures.</div>
        <form method="post" action="/inscription/"><input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>"><input type="hidden" name="resume" value="<?= saas_e($resumable['id']) ?>">
          <button class="btn" type="submit">Reprendre le paiement</button></form>
      </div>
    <?php endif; ?>
    <form class="form-card" method="post" action="/inscription/" novalidate>
      <?php if (isset($errors['form'])): ?><div class="notice error" role="alert"><?= saas_e($errors['form']) ?></div><?php elseif ($errors): ?><div class="notice error" role="alert"><strong>La création n'a pas pu aboutir :</strong><ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($errors as $m): ?><li><?= saas_e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= saas_e($_SESSION['saas_csrf']) ?>">
      <div class="hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="form-grid">
        <fieldset class="field full<?= isset($errors['plan']) ? ' has-error' : '' ?>" style="border:0;padding:0;margin:0"><span style="font-size:.82rem;font-weight:600">Votre formule</span>
          <div class="plan-pick tall">
            <?php foreach ($plans as $p): ?>
              <label><input type="radio" name="plan" value="<?= saas_e($p['key']) ?>"<?= $v['plan'] === $p['key'] ? ' checked' : '' ?>>
                <strong><?= saas_e($p['name']) ?> — <?= saas_e($p['price']) ?> <?= saas_e($p['period']) ?></strong>
                <?php foreach (array_slice($p['features'], 0, 3) as $f): ?><span>✓ <?= saas_e($f) ?></span><?php endforeach; ?></label>
            <?php endforeach; ?>
          </div><?= $err('plan') ?><?php if ($stripeOn): ?><small>Formule payante : paiement par carte (Stripe), abonnement mensuel résiliable. Formule gratuite : aucun paiement.</small><?php endif; ?></fieldset>

        <label class="field full<?= isset($errors['email']) ? ' has-error' : '' ?>"><span>Votre e-mail (ce sera votre identifiant d'administration)</span>
          <input type="email" name="email" value="<?= saas_e($v['email']) ?>" required maxlength="60" autocomplete="email"><?= $err('email') ?></label>

        <label class="field full<?= isset($errors['terms']) ? ' has-error' : '' ?>" style="grid-template-columns:auto 1fr;align-items:start;gap:10px">
          <input type="checkbox" name="terms" value="1" style="width:auto;margin-top:4px"<?= !empty($_POST['terms']) ? ' checked' : '' ?>>
          <span style="font-weight:400">J'accepte d'être contacté(e) pour la création de ma boutique et que mes informations servent à ce seul usage.<?= $err('terms') ?></span></label>
      </div>
      <p style="margin:22px 0 0"><button class="btn" type="submit"><?= $stripeOn ? 'Continuer' : 'Continuer vers ma boutique' ?></button></p>
      <?php if ($social = oauth_buttons_html('signup', [], false)): ?>
        <div class="oauth-sep"><span>ou</span></div>
        <?= $social ?>
        <p class="oauth-note">Avec un réseau social, votre adresse e-mail est reprise de votre compte et vous n'avez pas de mot de passe à créer.</p>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>
<?php if ($errors): ?><script>(function(){var f=document.querySelector('.has-error input,.has-error textarea,.has-error select');if(f){f.scrollIntoView({block:'center'});f.focus({preventScroll:true});}})();</script><?php endif; ?>
<?php saas_page_end(); ?>
