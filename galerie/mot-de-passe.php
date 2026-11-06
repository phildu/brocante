<?php
// Mot de passe oublié d'un administrateur de galerie : lien par e-mail ou code par SMS, puis choix du nouveau mot de passe.
session_start();
require_once __DIR__ . '/../includes/gallery-admin.php';
require_once __DIR__ . '/../includes/login-ui.php';
require_once __DIR__ . '/../includes/login-otp.php';

function ga_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['ga_csrf'])) $_SESSION['ga_csrf'] = bin2hex(random_bytes(16));
// Galerie d'où l'on vient (?g=), pour revenir sur SA page de connexion ; l'e-mail de réinitialisation, lui, vaut pour toutes les galeries du compte.
$loginUrl = ga_login_url((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$email = (string) ($_GET['e'] ?? $_POST['e'] ?? '');
$exp = (int) ($_GET['x'] ?? $_POST['x'] ?? 0);
$sig = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$resetMode = $sig !== '';
$error = null;
$sent = false;
if (isset($_GET['reset'])) unset($_SESSION['ga_otp']);
$otp = $_SESSION['ga_otp'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['ga_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La page avait expiré : réessayez.';
    } elseif ($resetMode) {
        $error = ga_reset_apply($email, $exp, $sig, (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
        if ($error === null) {
            $_SESSION['ga_notice'] = 'Mot de passe modifié : connectez-vous avec le nouveau.';
            header('Location: ' . $loginUrl);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'otp') {
        if (!is_array($otp) || empty($otp['login'])) {
            $error = 'Demandez d\'abord un code par SMS.';
        } else {
            $error = (string) login_otp_apply('gallery', (string) $otp['login'], (string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            if ($error === '') {
                unset($_SESSION['ga_otp']);
                $_SESSION['ga_notice'] = 'Mot de passe modifié : connectez-vous avec le nouveau.';
                header('Location: ' . $loginUrl);
                exit;
            }
        }
    } elseif (($_POST['method'] ?? 'email') === 'sms') {
        $res = login_otp_request('gallery', (string) ($_POST['email'] ?? ''));
        if ($res['sent']) {
            $otp = ['login' => (string) ($_POST['email'] ?? ''), 'phone' => $res['phone']];
            $_SESSION['ga_otp'] = $otp;
        } else {
            $error = (string) $res['error'];
        }
    } else {
        ga_reset_request((string) ($_POST['email'] ?? ''));
        $sent = true;
    }
}
$linkOk = $resetMode && ga_reset_valid($email, $exp, $sig);
http_response_code($error ? 400 : 200);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Administration de galerie — Mot de passe oublié</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<link rel="stylesheet" href="/assets/login.css">
</head>
<body class="login-body">
<main class="login-card">
  <div class="login-brand">
    <span class="mark" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></svg></span>
    <div><b>brocs</b><small>Espace galerie</small></div>
  </div>
<?php if ($resetMode && !$linkOk && !$error): ?>
  <h1>Lien non valable</h1>
  <p class="flash" data-kind="error" role="alert">Ce lien a expiré ou a déjà servi (valable 1 heure, à usage unique).</p>
  <p class="login-foot"><a href="/galerie/mot-de-passe.php">Redemander un lien</a></p>
<?php elseif ($resetMode): ?>
  <h1>Nouveau mot de passe</h1>
  <p class="hint">Choisissez un mot de passe de 10 caractères au moins.</p>
  <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
  <form class="login-unified-form" method="post">
    <input type="hidden" name="csrf" value="<?= ga_e($_SESSION['ga_csrf']) ?>">
    <input type="hidden" name="e" value="<?= ga_e($email) ?>"><input type="hidden" name="x" value="<?= (int) $exp ?>"><input type="hidden" name="t" value="<?= ga_e($sig) ?>">
    <?= login_password_field_html(['name' => 'password', 'label' => 'Nouveau mot de passe', 'autocomplete' => 'new-password', 'minlength' => 10, 'autofocus' => true]) ?>
    <?= login_password_field_html(['name' => 'confirm', 'label' => 'Confirmer', 'autocomplete' => 'new-password', 'minlength' => 10]) ?>
    <button class="btn btn-primary login-submit" type="submit">Enregistrer</button>
  </form>
  <p class="login-foot"><a href="<?= ga_e($loginUrl) ?>">← Retour à la connexion</a></p>
<?php elseif ($otp): ?>
  <h1>Code par SMS</h1>
  <p class="hint">Saisissez le code reçu au <?= ga_e((string) ($otp['phone'] ?? '')) ?> et choisissez un nouveau mot de passe (10 minutes).</p>
  <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
  <?= login_otp_form_html([
    'id' => 'gallery-otp',
    'identifier_name' => 'email',
    'identifier_value' => (string) ($otp['login'] ?? ''),
    'hidden' => ['csrf' => $_SESSION['ga_csrf'], 'action' => 'otp'],
    'minlength' => 10,
  ]) ?>
  <p class="login-foot"><a href="?reset=1">Recevoir un nouveau code ou un lien e-mail</a> · <a href="<?= ga_e($loginUrl) ?>">← Retour à la connexion</a></p>
<?php elseif ($sent): ?>
  <h1>Mot de passe oublié</h1>
  <p class="hint">Si cette adresse administre une galerie, un e-mail avec un lien (valable 1 heure) vient de lui être envoyé. Pensez à vérifier vos courriers indésirables.</p>
  <?php $gaHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')); if (!str_contains($gaHost, '.') || str_ends_with($gaHost, '.test') || filter_var($gaHost, FILTER_VALIDATE_IP)): ?>
    <p class="hint">En local, le message est recopié dans <code>data/mail-outbox.log</code> : ouvrez la <a href="/portail/journal-local.php?canal=mail">boîte locale</a> pour récupérer le lien.</p>
  <?php endif; ?>
  <p class="login-foot"><a href="<?= ga_e($loginUrl) ?>">← Retour à la connexion</a></p>
<?php else: ?>
  <h1>Mot de passe oublié</h1>
  <p class="hint">Indiquez votre identifiant, votre nom ou votre e-mail : recevez un lien par e-mail ou un code par SMS.</p>
  <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
  <?= login_forgot_form_html([
    'id' => 'gallery-forgot',
    'identifier_name' => 'email',
    'hidden' => ['csrf' => $_SESSION['ga_csrf']],
    'sms' => login_otp_sms_available(),
    'note' => 'Le code par SMS n\'est proposé que si un numéro est enregistré sur votre compte.',
  ]) ?>
  <p class="login-foot"><a href="<?= ga_e($loginUrl) ?>">← Retour à la connexion</a></p>
<?php endif; ?>
</main>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
