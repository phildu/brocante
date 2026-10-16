<?php
// Mot de passe oublié : demande d'un lien de réinitialisation par e-mail (voir includes/password-reset.php).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

$from = (string) ($_GET['from'] ?? $_POST['from'] ?? '') === 'studio' ? 'studio' : '';
$back = $from === 'studio' ? '/studio.php' : '/admin/login.php';
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Le formulaire a expiré : réessayez.';
    } else {
        password_reset_request((string) ($_POST['login'] ?? ''), $from);
        $sent = true;
    }
}

ob_start();
?>
    <p class="eyebrow">Espace boutique</p>
    <h1>Mot de passe oublié</h1>
<?php if ($sent): ?>
    <p class="login-ok" role="status">Si un compte correspond à cet identifiant, un e-mail vient d'être envoyé avec un lien pour choisir un nouveau mot de passe (valable 1 heure). Pensez à vérifier vos courriers indésirables.</p>
    <p class="login-links"><a href="<?= h($back) ?>">← Retour à la connexion</a></p>
<?php else: ?>
    <p class="lede">Indiquez votre identifiant ou votre adresse e-mail : nous vous envoyons un lien pour choisir un nouveau mot de passe.</p>
    <?php if ($error): ?><p class="login-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="from" value="<?= h($from) ?>">
      <label class="lbl" for="login">Identifiant ou e-mail</label>
      <input type="text" name="login" id="login" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required maxlength="120" value="<?= h($_POST['login'] ?? '') ?>">
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Envoyer le lien</button>
    </form>
    <p class="login-links"><a href="<?= h($back) ?>">← Retour à la connexion</a></p>
<?php endif; ?>
<?php password_reset_page('Mot de passe oublié', (string) ob_get_clean());
