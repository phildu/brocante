<?php
// Vente de la création d'une galerie commerciale : /galerie/creer.php — trois offres (test gratuit, puis abonnements selon le nombre de commerces).
// Test gratuit : galerie créée tout de suite, accès par e-mail. Offre payante : paiement Stripe, puis galerie créée (page de retour + webhook).
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/oauth.php';

session_start();
// Retour d'un réseau social (oauth/finish.php) : l'adresse e-mail vérifiée par le fournisseur complète la demande mémorisée au départ, comme si le formulaire avait été envoyé.
$oauthError = (string) ($_SESSION['ga_oauth_error'] ?? '');
unset($_SESSION['ga_oauth_error']);
if (isset($_GET['social']) && is_array($saved = $_SESSION['oauth_gcreate'] ?? null) && is_array($who = $_SESSION['oauth_gcreate_email'] ?? null) && time() - (int) ($who['at'] ?? 0) < 900) {
    $_POST = $saved + ['email' => (string) $who['email'], 'social' => '1'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
}
unset($_SESSION['oauth_gcreate'], $_SESSION['oauth_gcreate_email']);

function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

$origin = saas_origin();
// Type de galerie (marché aux puces, vide-grenier…) : chaque type a ses offres, son vocabulaire et sa page de création.
$types = gt_active();
$typeKey = strtolower((string) ($_GET['type'] ?? $_POST['type'] ?? ''));
$chooser = !isset($types[$typeKey]) && count($types) > 1 && !isset($_GET['r']) && $_SERVER['REQUEST_METHOD'] !== 'POST';
$type = $types[$typeKey] ?? gt_type(array_key_first($types) ?? GT_DEFAULT);
$V = $type['vocab'];
$namePlaceholder = [
    'vide-grenier' => 'Vide-grenier de la Rue des Fleurs',
    'puces' => 'Marché aux puces du Port',
    'marche-de-noel' => 'Marché de Noël de la Place Bellecour',
    'marche-producteurs' => 'Marché des producteurs du Bourg',
][$type['key']] ?? 'La Rue des Arts';
$offers = array_filter($type['offers'], static fn ($o) => $o['active']);
$message = null;
$error = $oauthError !== '' ? $oauthError : null;
$done = null;

// Retour de Stripe : on vérifie le paiement sans attendre le webhook.
if (isset($_GET['r'], $_GET['s']) && preg_match('/^cs_[A-Za-z0-9_]+$/', (string) $_GET['s'])) {
    try {
        $session = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode((string) $_GET['s']));
        $r = go_apply_session($session);
        if (in_array($r['state'], ['added', 'updated', 'already'], true)) {
            $done = ['slug' => $r['slug'], 'state' => $r['state']];
        } else {
            $message = "Le paiement n'est pas encore confirmé par Stripe : votre galerie sera créée dès sa confirmation et vous recevrez vos accès par e-mail.";
        }
    } catch (Throwable $ex) {
        $message = 'Impossible de vérifier le paiement pour le moment : vous recevrez vos accès par e-mail dès sa confirmation.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $offer = $offers[(string) ($_POST['offre'] ?? '')] ?? null;
    $name = (string) ($_POST['name'] ?? '');
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $billing = ($_POST['billing'] ?? '') === 'year' ? 'year' : 'month';
    if (!empty($_POST['website'])) {
        $error = 'Demande refusée.';                       // champ piège rempli par un robot
    } elseif (!$offer) {
        $error = 'Choisissez une offre.';
    } elseif (!ga_login_attempts_ok()) {
        $error = 'Trop de demandes depuis votre connexion : réessayez plus tard.';
    } elseif (mb_strlen(trim($name)) < 3) {
        $error = 'Donnez un nom à votre ' . $V['gallery'] . ' (3 caractères au moins).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse e-mail invalide.';
    } else {
        ga_login_attempt_note();
        if ($offer['month_cents'] <= 0) {
            $used = false;
            foreach (gallery_list() as $g) if (ga_admin_find($g, $email) && ($g['plan']['offer'] ?? '') === $offer['key'] && gt_of($g)['key'] === $type['key']) $used = true;
            if ($used) {
                $error = 'Cette adresse a déjà utilisé le test gratuit : choisissez une offre payante.';
            } else {
                [$slug, $err, , $mailed] = go_create($offer, $name, $email, 'month', [], $type['key']) + [null, null, null, false];
                if ($err) $error = $err;
                else $done = ['slug' => $slug, 'state' => $mailed ? 'trial' : 'trial_nomail'];
            }
        } else {
            [$url, $err] = go_checkout($offer, $billing, $email, $name, '', '/galerie/creer.php', $origin . '/galerie/creer.php?annule=1&type=' . rawurlencode($type['key']), $type['key']);
            if ($err) $error = $err; else { header('Location: ' . $url, true, 303); exit; }
        }
    }
} elseif (isset($_GET['annule'])) {
    $message = 'Le paiement a été annulé : rien n\'a été débité.';
}
$pre = (string) ($_GET['offre'] ?? $_POST['offre'] ?? 'test');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($chooser ? 'Créer ma galerie' : $V['create_title']) ?></title>
<link rel="stylesheet" href="/assets/oauth.css">
<style>
  :root { --bg:#f7f3ec; --ink:#221f1a; --soft:#6b6457; --line:#ddd5c6; --accent:#b5502e; --card:#fff; }
  * { box-sizing: border-box; } body { margin: 0; font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--ink); }
  main { max-width: 1000px; margin: 0 auto; padding: 36px 18px 60px; } h1 { font-size: 2rem; line-height: 1.15; margin: 0 0 8px; } .lede { color: var(--soft); max-width: 60ch; margin: 0 0 28px; }
  .offers { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
  .offer { background: var(--card); border: 2px solid var(--line); border-radius: 12px; padding: 20px; display: grid; gap: 8px; align-content: start; cursor: pointer; }
  .offer:has(input:checked) { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(181,80,46,.15); }
  .offer h2 { margin: 0; font-size: 1.2rem; } .price { font-size: 1.9rem; font-weight: 800; } .price small { font-size: .9rem; font-weight: 500; color: var(--soft); } .offer ul { margin: 0; padding-left: 1.1rem; color: var(--soft); }
  .offer input[type=radio] { justify-self: start; width: 18px; height: 18px; accent-color: var(--accent); }
  form.send { margin-top: 24px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; display: grid; gap: 12px; max-width: 560px; }
  label { display: grid; gap: 4px; font-weight: 600; font-size: .9rem; } input[type=text], input[type=email], select { padding: 11px; border: 1px solid #cfc6b4; border-radius: 8px; font: inherit; background: #fff; }
  button { padding: 13px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
  .err { color: #a4361b; font-weight: 600; } .ok { background: #e8f3ec; border: 1px solid #b9d9c4; border-radius: 10px; padding: 16px; } .info { background: #fff6e0; border: 1px solid #ecd9a0; border-radius: 10px; padding: 14px; } small.n { color: var(--soft); }
  .type-hover { position: relative; display: flex; }
  .type-hover > .offer { flex: 1; }
  .type-hover:hover > .offer, .type-hover:focus-within > .offer { border-color: var(--accent); }
  .type-hover > .offer:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
</style>
</head>
<body>
<main>
  <p><a href="/galerie/">← Les galeries</a> · <a href="/galerie/admin.php" rel="nofollow">Déjà une galerie ? Se connecter</a></p>
  <?php if ($chooser): ?>
  <h1>Que souhaitez-vous créer ?</h1>
  <p class="lede">Chaque type a son vocabulaire, ses fonctionnalités et ses tarifs. Vous pourrez ensuite tout personnaliser.</p>
  <div class="offers">
    <?php foreach ($types as $tk => $t): $cheapest = null; foreach ($t['offers'] as $o) if ($o['active'] && $o['month_cents'] > 0 && ($cheapest === null || $o['month_cents'] < $cheapest)) $cheapest = $o['month_cents']; ?>
      <div class="type-hover lp-hover">
        <a class="offer" href="/galerie/creer.php?type=<?= e($tk) ?>" style="text-decoration:none;color:inherit;">
          <span style="font-size:2rem;"><?= e($t['emoji']) ?></span>
          <h2><?= e($t['name']) ?></h2>
          <p style="margin:0;color:var(--soft);font-weight:400;"><?= e($t['tagline']) ?></p>
          <small style="color:var(--soft);">Test gratuit<?= $cheapest ? ' · puis dès ' . e(go_price_text($cheapest)) : '' ?></small>
          <strong style="color:var(--accent);"><?= e($t['vocab']['create_cta']) ?> →</strong>
        </a>
        <?= gt_landing_preview_html((string) $tk, (string) $t['vocab']['create_intro'], 'Aperçu ' . $t['name']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <?php if (count($types) > 1): ?><p style="margin:0 0 6px;"><a href="/galerie/creer.php">← Choisir un autre type</a> · <?= e($type['emoji'] . ' ' . $type['name']) ?></p><?php endif; ?>
  <h1><?= e($V['create_title']) ?></h1>
  <p class="lede"><?= e($V['create_intro']) ?></p>

  <?php if ($done): ?>
    <div class="ok" role="status">
      <?php if ($done['state'] === 'trial'): ?><strong>Votre galerie est créée.</strong> Vos accès viennent de vous être envoyés par e-mail : connectez-vous sur <a href="<?= e(ga_login_url($done['slug'])) ?>"><?= e(ga_login_url($done['slug'])) ?></a>, choisissez vos commerces et publiez la page.
      <?php elseif ($done['state'] === 'trial_nomail'): ?><strong>Votre galerie est créée</strong>, mais l'e-mail contenant vos accès n'a pas pu partir : contactez-nous pour les recevoir.
      <?php elseif ($done['state'] === 'updated'): ?><strong>Merci !</strong> Votre nouvelle offre est active : <a href="/portail/galerie-offre.php">voir mon offre</a>.
      <?php else: ?><strong>Merci pour votre paiement !</strong> Votre galerie est créée et vos accès vous sont envoyés par e-mail. Connexion : <a href="<?= e(ga_login_url($done['slug'])) ?>"><?= e(ga_login_url($done['slug'])) ?></a>.<?php endif; ?>
    </div>
  <?php else: ?>
    <?php if ($message): ?><p class="info" role="status"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="type" value="<?= e($type['key']) ?>">
      <div class="offers">
        <?php foreach ($offers as $o): $free = $o['month_cents'] <= 0; ?>
          <label class="offer">
            <input type="radio" name="offre" value="<?= e($o['key']) ?>"<?= $o['key'] === $pre || (!isset($offers[$pre]) && $o['key'] === array_key_first($offers)) ? ' checked' : '' ?> required>
            <h2><?= e($o['name']) ?></h2>
            <div class="price"><?= $free ? 'Gratuit' : e(go_price_text($o['month_cents'])) ?><?= $free ? ' <small>' . (int) $o['trial_days'] . ' jours</small>' : '' ?></div>
            <p style="margin:0;color:var(--soft);font-weight:400;"><?= e($o['tagline']) ?></p>
            <ul><li><?= $free ? 'Jusqu\'à' : 'Jusqu\'à' ?> <strong><?= (int) $o['max_members'] ?> <?= e($V['members']) ?></strong></li><li>Page publique personnalisable</li><li>Tableau de bord et prospects</li>
              <li><?= $free ? 'Sans engagement, sans carte bancaire' : ($o['year_cents'] > 0 ? 'ou ' . e(go_price_text($o['year_cents'], 'year')) : 'Sans engagement') ?></li></ul>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="send" style="margin-top:20px;display:grid;gap:12px;max-width:560px;">
        <label>Nom de votre <?= e($V['gallery']) ?><input type="text" name="name" required minlength="3" maxlength="80" placeholder="<?= e($namePlaceholder) ?>" value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label>Votre e-mail <small class="n">(vos accès y seront envoyés)</small><input type="email" name="email" required maxlength="160" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Facturation <small class="n">(offres payantes)</small><select name="billing"><option value="month">Mensuelle</option><option value="year"<?= ($_POST['billing'] ?? '') === 'year' ? ' selected' : '' ?>>Annuelle (plus avantageuse)</option></select></label>
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
        <button type="submit">Continuer</button>
        <?php if ($social = oauth_buttons_html('gcreate', [], false)): ?>
          <div class="oauth-sep"><span>ou</span></div>
          <?= $social ?>
          <p class="oauth-note">Avec un réseau social : choisissez l'offre et donnez un nom à votre <?= e($V['gallery']) ?>, puis cliquez ; votre e-mail est repris de votre compte.</p>
        <?php endif; ?>
        <small class="n">Offre payante : vous serez redirigé vers la page de paiement sécurisée Stripe. Résiliable à tout moment depuis votre espace.</small>
      </div>
    </form>
  <?php endif; ?>
  <?php endif; ?>
</main>
<?= gt_landing_preview_assets() ?>
</body>
</html>
