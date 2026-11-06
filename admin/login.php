<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/login-ui.php';

// Le QR code du shooting ouvre l'application : après connexion, retour à cette
// page. Adresse relative uniquement, jamais la page de connexion elle-même.
$next = (string) ($_GET['next'] ?? ($_POST['next'] ?? ''));
$nextPath = $next === '' ? '' : (string) (parse_url($next, PHP_URL_PATH) ?: '');
$nextOk = $next !== ''
    && $next[0] === '/'
    && !str_starts_with($next, '//')
    && !str_contains($next, '\\')
    && !preg_match('/[\r\n]/', $next)
    && $nextPath !== ''
    && !str_ends_with($nextPath, '/admin/login.php');
$next = $nextOk ? $next : '';
$after_login = static function (string $role) use ($next, $nextPath): string {
    if ($next !== '' && admin_role_can_access($role, $nextPath)) {
        return $next;
    }
    return admin_home_for_role($role);
};

// Déjà connecté (bouton « Connexion » du site) : direction l'administration.
if (is_admin_logged_in()) {
    header('Location: ' . $after_login(admin_role()));
    exit;
}

$content = get_content();
$error = null;
$notice = null;
if (!empty($_SESSION['login_notice'])) {   // « Mot de passe modifié » (admin/reinitialiser.php)
    $notice = (string) $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}
if (!empty($_SESSION['login_error'])) {   // message laissé par la connexion sociale (oauth/finish.php)
    $error = (string) $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sans compte configuré, l'administration reste fermée.
    $login = admin_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($login) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = tenant_slug();
        if ($login['id'] === null) {
            unset($_SESSION['admin_account_id']);
        } else {
            $_SESSION['admin_account_id'] = $login['id'];
        }
        if (!empty($_POST['remember'])) {
            session_remember();
        }
        header('Location: ' . $after_login($login['role']));
        exit;
    }
    sleep(1); // freine les essais en série
    $error = 'Identifiant, nom ou e-mail incorrect.';
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
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/oauth.css">
<link rel="stylesheet" href="/assets/login.css">
<style>
  .login-wrap { max-width: 420px; margin: 12vh auto 40px; padding: 0 20px; }
  .login-box { background: var(--surface); border: 1px solid var(--line); padding: 32px; }
  .login-box h1 { font-size: 1.5rem; margin-bottom: 6px; }
  .login-box p.lede { font-size: 0.9rem; margin-bottom: 22px; }
  .login-notice { font-size: 0.88rem; margin-bottom: 14px; padding: 10px 12px; border: 1px solid var(--line); background: var(--bg); }
  .login-error { color: var(--accent); font-size: 0.85rem; margin-bottom: 14px; font-family: var(--font-mono); }
  .login-other { font-size: 0.82rem; margin-top: 16px; text-align: center; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <p class="eyebrow">Espace boutique</p>
    <h1>Administration</h1>
    <p class="lede">Connectez-vous pour gérer les contenus et le catalogue.</p>
    <?php if ($notice): ?><p class="login-notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="login-error"><?= h($error) ?></p><?php endif; ?>
    <?= login_form_html([
      'id' => 'admin-login',
      'identifier_name' => 'username',
      'social' => oauth_buttons_html('admin'),
      'forgot_url' => '/admin/mot-de-passe-oublie.php',
      'submit' => 'Se connecter',
      'remember' => true,
      'remember_name' => 'remember',
      'remember_label' => 'Rester connecté sur cet appareil de confiance (30 jours)',
    ]) ?>
    <p class="login-other"><a href="/connexion.php">Connexion unique — boutique, galerie, portail, compte client</a></p>
  </div>
</div>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
