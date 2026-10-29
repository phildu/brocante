<?php
// Connexion des administrateurs d'une galerie : /galerie/admin.php. Une fois connecté, l'administrateur gère SA galerie depuis le portail
// (mode restreint : voir portail/_bootstrap.php). Les comptes sont créés par l'exploitant (portail → Galeries → Administrateurs).
session_start();
require_once __DIR__ . '/../includes/gallery-admin.php';
require_once __DIR__ . '/../includes/oauth.php';

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
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Administration de galerie — Connexion</title>
<link rel="stylesheet" href="/assets/oauth.css">
<style>
  body { font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f0e8; color: #221f1a; margin: 0; }
  main { max-width: 380px; margin: 12vh auto 0; padding: 0 18px; }
  form { background: #fff; border: 1px solid #ddd5c6; border-radius: 10px; padding: 22px; display: grid; gap: 12px; }
  h1 { font-size: 1.3rem; margin: 0; }
  label { display: grid; gap: 4px; font-size: .85rem; font-weight: 600; }
  input { padding: 10px; border: 1px solid #cfc6b4; border-radius: 8px; font: inherit; }
  button { padding: 11px; border: 0; border-radius: 8px; background: #221f1a; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
  .err { color: #a4361b; margin: 0; } .ok { color: #1e6b3a; margin: 0; } small { color: #6b6457; }
</style>
</head>
<body>
<main>
  <form method="post" autocomplete="on">
    <h1>Administration de galerie</h1>
    <?php if ($error): ?><p class="err" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="ok" role="status"><?= ga_e($notice) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= ga_e($_SESSION['ga_csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <label>Adresse e-mail<input type="email" name="email" autocomplete="username" required autofocus value="<?= ga_e($_POST['email'] ?? '') ?>"></label>
    <label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">Se connecter</button>
    <small>Compte créé par l'exploitant de la plateforme. <a href="/galerie/mot-de-passe.php">Mot de passe oublié ?</a></small>
  </form>
  <?php if ($social = oauth_buttons_html('gadmin', ['csrf' => $_SESSION['ga_csrf']])): ?>
    <div class="oauth-sep"><span>ou</span></div>
    <?= $social ?>
    <p class="oauth-note">Avec un réseau social, l'adresse e-mail doit être celle de votre compte d'administrateur de galerie.</p>
  <?php endif; ?>
</main>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
