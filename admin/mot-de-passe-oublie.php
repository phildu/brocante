<?php
// Mot de passe oublié : lien par e-mail ou code par SMS (voir includes/password-reset.php et includes/login-otp.php).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/login-ui.php';
require_once __DIR__ . '/../includes/login-otp.php';

$from = (string) ($_GET['from'] ?? $_POST['from'] ?? '') === 'studio' ? 'studio' : '';
$back = $from === 'studio' ? '/studio.php' : '/admin/login.php';
$sent = false;
$error = '';
if (isset($_GET['reset'])) unset($_SESSION['admin_otp']);
$otp = $_SESSION['admin_otp'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Le formulaire a expiré : réessayez.';
    } elseif (($_POST['action'] ?? '') === 'otp') {
        if (!is_array($otp) || empty($otp['login'])) {
            $error = 'Demandez d\'abord un code par SMS.';
        } else {
            $error = (string) login_otp_apply('admin', (string) $otp['login'], (string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            if ($error === '') {
                unset($_SESSION['admin_otp']);
                $_SESSION['login_notice'] = 'Mot de passe modifié : connectez-vous avec le nouveau.';
                header('Location: ' . $back);
                exit;
            }
        }
    } elseif (($_POST['method'] ?? 'email') === 'sms') {
        $res = login_otp_request('admin', (string) ($_POST['login'] ?? ''));
        if ($res['sent']) {
            $otp = ['login' => (string) ($_POST['login'] ?? ''), 'phone' => $res['phone']];
            $_SESSION['admin_otp'] = $otp;
        } else {
            $error = (string) $res['error'];
        }
    } else {
        password_reset_request((string) ($_POST['login'] ?? ''), $from);
        $sent = true;
    }
}

ob_start();
?>
    <p class="eyebrow">Espace boutique</p>
    <h1><?= $otp ? 'Code par SMS' : 'Mot de passe oublié' ?></h1>
<?php if ($sent): ?>
    <p class="login-ok" role="status">Si un compte correspond à cet identifiant, un e-mail vient d'être envoyé avec un lien pour choisir un nouveau mot de passe (valable 1 heure). Pensez à vérifier vos courriers indésirables.</p>
    <p class="login-links"><a href="<?= h($back) ?>">← Retour à la connexion</a></p>
<?php elseif ($otp): ?>
    <p class="lede">Saisissez le code reçu au <?= h((string) ($otp['phone'] ?? '')) ?> et choisissez un nouveau mot de passe (10 minutes).</p>
    <?php if ($error): ?><p class="login-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= login_otp_form_html([
      'id' => 'admin-otp',
      'identifier_name' => 'login',
      'identifier_value' => (string) ($otp['login'] ?? ''),
      'hidden' => ['csrf' => admin_csrf_token(), 'from' => $from, 'action' => 'otp'],
      'minlength' => ACCOUNT_PASSWORD_MIN,
    ]) ?>
    <p class="login-links"><a href="?reset=1<?= $from ? '&from=studio' : '' ?>">Recevoir un nouveau code ou un lien e-mail</a> · <a href="<?= h($back) ?>">← Retour à la connexion</a></p>
<?php else: ?>
    <p class="lede">Indiquez votre identifiant, votre nom ou votre e-mail : recevez un lien par e-mail ou un code par SMS.</p>
    <?php if ($error): ?><p class="login-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= login_forgot_form_html([
      'id' => 'admin-forgot',
      'identifier_name' => 'login',
      'hidden' => ['csrf' => admin_csrf_token(), 'from' => $from],
      'sms' => login_otp_sms_available(),
      'note' => 'Le code par SMS n\'est proposé que si un numéro est enregistré sur votre compte.',
    ]) ?>
    <p class="login-links"><a href="<?= h($back) ?>">← Retour à la connexion</a></p>
<?php endif; ?>
<?php password_reset_page('Mot de passe oublié', (string) ob_get_clean());
