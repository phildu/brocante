<?php
// Connexion des administrateurs d'une galerie : /galerie/admin.php. Une fois connecté, l'administrateur gère SA galerie depuis le portail
// (mode restreint : voir portail/_bootstrap.php). Les comptes sont créés par l'exploitant (portail → Galeries → Administrateurs).
session_start();
require_once __DIR__ . '/../includes/gallery-admin.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/login-ui.php';

function ga_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['ga_csrf'])) $_SESSION['ga_csrf'] = bin2hex(random_bytes(16));
$error = null;
$notice = null;
if (!empty($_SESSION['ga_oauth_error'])) { $error = (string) $_SESSION['ga_oauth_error']; unset($_SESSION['ga_oauth_error']); }   // message laissé par la connexion sociale (oauth/finish.php)

if (!empty($_SESSION['ga_notice'])) { $notice = (string) $_SESSION['ga_notice']; unset($_SESSION['ga_notice']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['ga_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La page avait expiré : réessayez.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        unset($_SESSION['gallery_admin']);
        $notice = 'Vous êtes déconnecté·e.';
    } elseif (($_POST['action'] ?? '') === 'login') {
        $error = ga_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    }
}
if ($admin = ga_current()) {
    $next = $admin['must_change'] ? '/portail/galerie-compte.php' : '/portail/galerie-tableau.php';
    header('Location: ' . $next);
    exit;
}
http_response_code($error ? 401 : 200);
$social = oauth_buttons_html('gadmin', ['csrf' => $_SESSION['ga_csrf']]);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Administration de galerie — Connexion</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/oauth.css">
<link rel="stylesheet" href="/assets/login.css">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
</head>
<body class="login-body">
<main class="login-card">
  <div class="login-brand">
    <span class="mark" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></svg></span>
    <div><b>brocs</b><small>Espace galerie</small></div>
  </div>
  <h1>Connexion</h1>
  <p class="hint">Gérez votre galerie commerciale, ses commerces membres et ses exposants.</p>
  <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="flash" data-kind="ok" role="status"><?= ga_e($notice) ?></p><?php endif; ?>
  <?= login_form_html([
    'id' => 'gallery-login',
    'identifier_name' => 'email',
    'social' => $social,
    'forgot_url' => '/galerie/mot-de-passe.php',
    'submit' => 'Se connecter',
    'hidden' => ['csrf' => $_SESSION['ga_csrf'], 'action' => 'login'],
  ]) ?>
  <?php if ($social): ?>
    <p class="oauth-note">Avec un réseau social, l'adresse e-mail doit être celle de votre compte d'administrateur de galerie.</p>
  <?php endif; ?>
  <p class="login-foot">Compte créé par l'exploitant de la plateforme.<br><a href="/connexion.php">Connexion unique — boutique, galerie, portail</a></p>
</main>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
