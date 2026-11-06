<?php
require __DIR__ . '/_bootstrap.php';

$ctx = fv_current();
if ($ctx['member'] || $ctx['manager']) {
    fv_redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fv_csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));
    $code = (string) ($_POST['code'] ?? '');
    $member = fv_member_login($email, $code);
    if ($member) {
        session_regenerate_id(true);
        $_SESSION['fv_member_id'] = (int) $member['id'];
        unset($_SESSION['fv_view']);
        fv_redirect('index.php');
    }
    sleep(1);
    $error = 'Adresse ou code d’accès incorrect. Vérifiez le code remis par la direction.';
}
$brand = fv_setting('marque_nom', 'Brocs');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion — Forces de vente <?= e($brand) ?></title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="assets/vente.css?v=6">
</head>
<body class="fv-body fv-login">
  <div class="fv-login-card">
    <span class="fv-mark" aria-hidden="true"><?= e(mb_substr($brand, 0, 1)) ?></span>
    <span class="fv-eyebrow"><?= e($brand) ?> · forces de vente</span>
    <h1>Reprendre le terrain</h1>
    <p>Tournées, appels, mailing, kits, pitch, codes de parrainage et récompenses : votre espace de vente.</p>

    <?php if ($error): ?><p class="fv-flash" data-kind="error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <form method="post" class="fv-form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
      <label class="fv-field">Adresse e-mail
        <input type="email" name="email" required autocomplete="username" autocapitalize="none" autofocus value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <label class="fv-field">Code d’accès
        <input type="text" name="code" required autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" value="">
        <span class="fv-hint">Le code personnel figure dans le message d’invitation envoyé par la direction.</span>
      </label>
      <button class="fv-btn fv-btn-primary" type="submit">Entrer dans l’espace</button>
    </form>

    <div class="fv-login-links">
      <span>Vous êtes l’exploitant ? <a href="/portail/">Connectez-vous au portail</a>, puis ouvrez « Forces de vente ».</span>
      <a href="/portail/vente/sondage.php?slug=satisfaction-boutique">Répondre au sondage commerçants →</a>
    </div>
  </div>
</body>
</html>
