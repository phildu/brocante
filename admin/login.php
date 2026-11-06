<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

$content = get_content();
$error = null;

// Déjà connecté : direction la première page accessible.
if (auth_user() !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . (auth_user()['must_change'] ? '/admin/account.php?force=1' : auth_home_url()));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Session expirée : rechargez la page et réessayez.';
    } else {
        $error = auth_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($error === null) {
            $u = auth_user();
            header('Location: ' . ($u['must_change'] ? '/admin/account.php?force=1' : auth_home_url()));
            exit;
        }
    }
}
$noAccess = auth_no_access_configured();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<style>
  .login-wrap { max-width: 380px; margin: 14vh auto 0; padding: 0 24px; }
  .login-box { background: var(--surface); border: 1px solid var(--line); padding: 32px; }
  .login-box h1 { font-size: 1.5rem; margin-bottom: 6px; }
  .login-box p.lede { font-size: 0.9rem; margin-bottom: 22px; }
  .login-box input[type="password"], .login-box input[type="email"] {
    width: 100%; background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 12px 14px; font-family: var(--font-body); font-size: 0.95rem; margin-bottom: 16px;
  }
  .login-error { color: var(--accent); font-size: 0.85rem; margin-bottom: 14px; font-family: var(--font-mono); }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <p class="eyebrow">Espace boutique</p>
    <h1>Administration</h1>
    <p class="lede">Connectez-vous pour gérer les contenus et le catalogue.</p>
    <?php if ($noAccess): ?>
      <p class="login-error">Aucun accès n'est configuré. Sur le serveur, créez un compte propriétaire avec <code>php scripts/create-owner.php votre@email.fr</code>, ou déposez un mot de passe dans <code>.secrets/admin_password.txt</code>.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="login-error"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
      <?= csrf_input() ?>
      <input type="email" name="email" placeholder="E-mail (laisser vide pour le mot de passe principal)" autocomplete="username" autofocus>
      <input type="password" name="password" placeholder="Mot de passe" autocomplete="current-password" required>
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Se connecter</button>
    </form>
  </div>
</div>
</body>
</html>
