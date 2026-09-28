<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

// Déjà connecté (bouton « Connexion » du site) : direction l'administration.
if (is_admin_logged_in()) {
    header('Location: /admin/catalog.php');
    exit;
}

$content = get_content();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sans mot de passe configuré, l'administration reste fermée.
    if (admin_login_matches((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = tenant_slug();
        header('Location: /admin/catalog.php');
        exit;
    }
    $error = 'Identifiant ou mot de passe incorrect.';
}
if (!admin_password_configured()) {
    $error = "Aucun compte n'est défini pour ce commerce : renseignez admin_user et admin_password dans tenants/"
        . tenant_slug() . '/tenant.php.';
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .login-wrap { max-width: 380px; margin: 14vh auto 0; padding: 0 24px; }
  .login-box { background: var(--surface); border: 1px solid var(--line); padding: 32px; }
  .login-box h1 { font-size: 1.5rem; margin-bottom: 6px; }
  .login-box p.lede { font-size: 0.9rem; margin-bottom: 22px; }
  .login-box input[type="password"], .login-box input[type="text"] {
    width: 100%; background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 12px 14px; font-family: var(--font-body); font-size: 0.95rem; margin-bottom: 16px;
  }
  .login-error { color: var(--accent); font-size: 0.85rem; margin-bottom: 14px; font-family: var(--font-mono); }
  .password-field { position: relative; margin-bottom: 16px; }
  .login-box .password-field input { margin-bottom: 0; padding-right: 92px; }
  .password-toggle {
    position: absolute; top: 50%; right: 6px; transform: translateY(-50%);
    background: none; border: 0; padding: 6px 8px; cursor: pointer;
    color: var(--ink-soft); font-family: var(--font-body); font-size: 0.82rem; text-decoration: underline;
  }
  .password-toggle:hover, .password-toggle:focus-visible { color: var(--accent); }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <p class="eyebrow">Espace boutique</p>
    <h1>Administration</h1>
    <p class="lede">Connectez-vous pour gérer les contenus et le catalogue.</p>
    <?php if ($error): ?><p class="login-error"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="text" name="username" placeholder="Identifiant" aria-label="Identifiant" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required value="<?= h($_POST['username'] ?? '') ?>">
      <div class="password-field">
        <input type="password" name="password" id="password" placeholder="Mot de passe" aria-label="Mot de passe" autocomplete="current-password" required>
        <button type="button" class="password-toggle" id="password-toggle" aria-controls="password" aria-pressed="false">Afficher</button>
      </div>
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Se connecter</button>
    </form>
    <script>
    (function () {
      var input = document.getElementById('password');
      var toggle = document.getElementById('password-toggle');
      toggle.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.textContent = show ? 'Masquer' : 'Afficher';
        toggle.setAttribute('aria-pressed', String(show));
        input.focus();
      });
      // Masque à nouveau avant l'envoi, pour que le navigateur propose d'enregistrer le mot de passe.
      input.form.addEventListener('submit', function () { input.type = 'password'; });
    })();
    </script>
  </div>
</div>
</body>
</html>
