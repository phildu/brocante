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
$origin = saas_origin();
// La galerie choisie détermine la grille de tarifs (formules et tranches, mensuel et annuel) ; sans galerie, la grille par défaut.
$galleries = saas_signup_galleries();
$gallery = strtolower(trim((string) ($_POST['galerie'] ?? $_GET['galerie'] ?? '')));
if (!isset($galleries[$gallery])) $gallery = '';
$grid = saas_grid($gallery);
$offers = saas_grid_offers($grid);
$offersByKey = array_column($offers, null, 'key');
$stripeOn = saas_stripe_configured();
$errors = [];
if (!empty($_SESSION['inscription_flash'])) {   // message laissé par la connexion sociale (oauth/finish.php)
    $errors['form'] = (string) $_SESSION['inscription_flash'];
    unset($_SESSION['inscription_flash']);
}
$resumable = null;
$featured = array_values(array_filter($offers, static fn ($o) => $o['featured']))[0] ?? ($offers[1] ?? $offers[0] ?? null);
$v = ['email' => trim((string) ($_GET['email'] ?? '')), 'plan' => (string) ($_GET['formule'] ?? ($featured['key'] ?? '')),
    'billing' => ($_GET['facturation'] ?? '') === 'year' && $grid['yearly'] ? 'year' : 'month'];
if (!isset($offersByKey[$v['plan']]) && $featured) $v['plan'] = $featured['key'];

// Retour d'un paiement annulé : la demande reste réservée 24 h et peut être reprise.
if (isset($_GET['annule']) && ($r = saas_request_load((string) $_GET['annule'])) && ($r['status'] ?? '') === 'awaiting_payment' && saas_request_active($r)) {
    $resumable = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['email'] = trim((string) ($_POST['email'] ?? ''));
    $v['plan'] = trim((string) ($_POST['plan'] ?? $v['plan']));
    $v['billing'] = ($_POST['facturation'] ?? '') === 'year' && $grid['yearly'] ? 'year' : 'month';
    if (!hash_equals((string) $_SESSION['saas_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Le formulaire a expiré : rechargez la page et recommencez.';
    } elseif (isset($_POST['resume'])) {
        // Reprendre le paiement d'une demande annulée.
        $r = saas_request_load((string) $_POST['resume']);
        if ($r && ($r['status'] ?? '') === 'awaiting_payment' && saas_request_active($r) && saas_req_payable($r)) {
            header('Location: ' . saas_payment_url($r, $origin), true, 303);
            exit;
        }
        $errors['form'] = 'Cette demande ne peut plus être reprise : refaites-la ci-dessous.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        // Champ piège rempli : un robot. On fait comme si c'était accepté.
        header('Location: /inscription/?recu=1', true, 303);
        exit;
    } elseif (saas_rate_limited()) {
        $errors['form'] = 'Trop de demandes depuis votre connexion : réessayez dans une heure.';
    } elseif (isset($offersByKey[$v['plan']]) && (saas_offer_cents($offersByKey[$v['plan']], $v['billing']) ?? 1) === 0 && saas_free_cap_reached()) {
        $errors['form'] = "Les créations de boutiques gratuites sont complètes pour aujourd'hui : revenez demain, ou choisissez une formule payante.";
    } else {
        $res = saas_request_start($_POST + ['galerie' => $gallery]);
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
        <?php if ($galleries): ?>
          <div class="field full"><label for="galerie-pick" style="font-size:.82rem;font-weight:600">Galerie commerciale <small>(les tarifs peuvent y être différents)</small></label>
            <select id="galerie-pick" onchange="location.href='/inscription/?galerie='+encodeURIComponent(this.value)+(this.form.email.value?'&email='+encodeURIComponent(this.form.email.value):'')">
              <option value="">Aucune : ma boutique seule</option>
              <?php foreach ($galleries as $slug => $name): ?><option value="<?= saas_e($slug) ?>"<?= $slug === $gallery ? ' selected' : '' ?>><?= saas_e($name) ?></option><?php endforeach; ?>
            </select>
            <?php if ($gallery !== ''): ?><small><?= $grid['own'] ? 'Tarifs propres à cette galerie.' : 'Cette galerie applique les tarifs habituels.' ?></small><?php endif; ?></div>
        <?php endif; ?>
        <input type="hidden" name="galerie" value="<?= saas_e($gallery) ?>">
        <fieldset class="field full<?= isset($errors['plan']) ? ' has-error' : '' ?>" style="border:0;padding:0;margin:0"><span style="font-size:.82rem;font-weight:600">Votre offre</span>
          <?= saas_offer_picker_html($grid, $v['plan'], $v['billing']) ?>
          <?= $err('plan') ?><?php if ($stripeOn): ?><small>Offre payante : paiement par carte (Stripe), abonnement résiliable. Offre gratuite : aucun paiement.</small><?php endif; ?></fieldset>

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
