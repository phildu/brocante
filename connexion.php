<?php
// Connexion unique : le même identifiant ou la même adresse e-mail ouvre
// l'administration d'une boutique, une galerie commerciale, le portail de
// l'exploitant ou l'espace client (includes/unified-auth.php). Quand plusieurs
// espaces répondent, la page propose lequel ouvrir ; la session de l'espace
// visé n'est ouverte qu'ensuite, par /sso.php. Le jeton du formulaire est signé
// sans session (ua_csrf_token) : cette page ne démarre jamais la session d'un
// autre espace (le modèle de design peut, lui, ouvrir celle de la boutique courante).
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/login-ui.php';
require_once __DIR__ . '/includes/unified-auth.php';

$expired = isset($_GET['expire']);
$error = null;
$options = [];
$remember = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ua_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La page avait expiré : réessayez.';
    } elseif (!ua_try_allowed((string) ($_POST['login'] ?? ''))) {
        $error = 'Trop d\'essais : réessayez dans un quart d\'heure.';
    } else {
        $matches = ua_login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (!$matches) {
            $error = 'Identifiant, nom ou e-mail incorrect — ou mot de passe incorrect.';
        } else {
            // La préférence voyage dans le jeton : c'est sso.php, dans l'espace visé, qui la consomme.
            $remember = !empty($_POST['remember']);
            foreach ($matches as $identity) {
                if ($remember) $identity['remember'] = true;
                $token = ua_ticket_create($identity);
                if ($token !== null) $options[] = ['identity' => $identity, 'url' => ua_sso_url($identity) . '?t=' . rawurlencode($token)];
            }
            if (count($options) === 1) {
                header('Location: ' . $options[0]['url']);
                exit;
            }
            if (!$options) $error = 'La connexion n\'a pas pu être ouverte : réessayez.';
        }
    }
}

$content = get_content();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/login.css">
<style>
  .login-wrap { max-width: 440px; margin: 10vh auto 40px; padding: 0 20px; }
  .login-box { background: var(--surface); border: 1px solid var(--line); padding: 32px; }
  .login-box h1 { font-size: 1.5rem; margin-bottom: 6px; }
  .login-box p.lede { font-size: 0.92rem; margin-bottom: 22px; }
  .login-notice { font-size: 0.88rem; margin-bottom: 14px; padding: 10px 12px; border: 1px solid var(--line); background: var(--bg); }
  .login-error { color: var(--accent); font-size: 0.88rem; margin-bottom: 14px; font-family: var(--font-mono); }
  .space-list { display: grid; gap: 10px; margin: 0 0 14px; padding: 0; list-style: none; }
  .space-list .btn { display: block; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
<?php if ($options): ?>
    <p class="eyebrow">Connexion</p>
    <h1>Choisissez l'espace à ouvrir</h1>
    <p class="lede">Cet identifiant ouvre plusieurs espaces : sélectionnez celui que vous voulez utiliser maintenant.</p>
    <ul class="space-list">
<?php foreach ($options as $option): ?>
      <li><a class="btn btn-primary" href="<?= h($option['url']) ?>"><?= h(ua_option_label($option['identity'])) ?></a></li>
<?php endforeach; ?>
    </ul>
    <p class="login-links"><a href="/connexion.php">← Revenir à la connexion</a></p>
<?php else: ?>
    <p class="eyebrow">Connexion</p>
    <h1>Se connecter</h1>
    <p class="lede">Un seul identifiant pour votre boutique, votre galerie, le portail ou votre compte client.</p>
    <?php if ($expired): ?><p class="login-notice" role="status">Ce lien de connexion a expiré ou a déjà servi : reconnectez-vous.</p><?php endif; ?>
    <?php if ($error): ?><p class="login-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= login_form_html([
      'id' => 'unified-login',
      'identifier_name' => 'login',
      'identifier_label' => 'Identifiant, nom ou e-mail',
      'forgot_url' => '/mot-de-passe-oublie.php',
      'submit' => 'Se connecter',
      'remember' => true,
      'remember_name' => 'remember',
      'remember_label' => 'Rester connecté sur cet appareil de confiance (30 jours)',
      'hidden' => ['csrf' => ua_csrf_token()],
    ]) ?>
<?php endif; ?>
  </div>
</div>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
