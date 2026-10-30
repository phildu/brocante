<?php
// Arrivée d'un lien de partage du shooting (admin/shop-shooting.php → « Partager »).
// Page publique : le jeton du lien, valable sept jours et à usage unique, autorise
// la création d'un compte « photographe ». Ce compte peut photographier et envoyer
// des photos ; leur mise en ligne reste à valider par un administrateur.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shop-shooting.php';

$token = (string) ($_GET['t'] ?? ($_POST['t'] ?? ''));
$invite = shop_shooting_invite_find($token);
$content = get_content();
$siteName = trim((string) $content['site_name']) ?: (string) tenant('name');
$error = null;
$old = ['name' => '', 'email' => ''];

if (!$invite) {
    http_response_code(404);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Formulaire expiré : rechargez la page.';
    } else {
        $old['name'] = trim((string) ($_POST['name'] ?? ''));
        $old['email'] = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($old['name'] === '' || mb_strlen($old['name']) > 80) {
            $error = 'Indiquez votre nom (80 caractères au plus).';
        } else {
            try {
                accounts_ensure_schema(db());
                $accountId = account_create([
                    'role' => 'photographe',
                    'name' => $old['name'],
                    'email' => $old['email'],
                ], $password, 'manuel');
                shop_shooting_invite_consume((int) $invite['id'], $accountId);
                session_regenerate_id(true);
                $_SESSION['is_admin'] = tenant_slug();
                $_SESSION['admin_account_id'] = $accountId;
                header('Location: /admin/shop-shooting.php');
                exit;
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Accès au shooting — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .invite-wrap { max-width: 460px; margin: 10vh auto 40px; padding: 0 20px; }
  .invite-box { background: var(--surface); border: 1px solid var(--line); padding: 28px; }
  .invite-box h1 { font-size: 1.45rem; margin: 0 0 8px; }
  .invite-box p.lede { font-size: 0.9rem; margin-bottom: 18px; color: var(--ink-soft); }
  .invite-box label { display: block; margin-top: 14px; font-size: 0.82rem; }
  .invite-box input { width: 100%; margin-top: 6px; }
  .invite-error { color: var(--accent); font-size: 0.85rem; margin: 10px 0; font-family: var(--font-mono); }
  .invite-note { font-size: 0.78rem; color: var(--ink-soft); margin-top: 16px; }
  .invite-box .btn { margin-top: 20px; width: 100%; }
</style>
</head>
<body>
<main class="invite-wrap">
  <div class="invite-box">
    <p class="eyebrow">Photos du lieu</p>
    <?php if (!$invite): ?>
      <h1>Lien expiré</h1>
      <p class="lede">Ce lien de partage n'est plus valable : il a déjà servi, ou sa date limite est passée. Demandez un nouveau lien à l'administrateur de la boutique.</p>
      <p class="invite-note"><a href="/admin/login.php">Se connecter</a> si vous avez déjà un compte.</p>
    <?php else: ?>
      <h1>Créer votre accès</h1>
      <p class="lede">Vous allez photographier <strong><?= h($siteName) ?></strong> : la boutique, un stand ou l'atelier. Vos photos seront relues par l'administrateur avant d'être publiées sur le site.</p>
      <?php if ($error): ?><p class="invite-error" role="alert"><?= h($error) ?></p><?php endif; ?>
      <?php if (is_admin_logged_in()): ?>
        <p class="invite-note">Vous êtes déjà connecté à cette boutique : le nouveau compte remplacera cette session dans ce navigateur.</p>
      <?php endif; ?>
      <form method="post" action="/admin/shooting-invite.php">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <label for="invite-name">Votre nom</label>
        <input type="text" id="invite-name" name="name" maxlength="80" required autocomplete="name" value="<?= h($old['name']) ?>">
        <label for="invite-email">Votre adresse e-mail</label>
        <input type="email" id="invite-email" name="email" maxlength="120" required autocomplete="email" value="<?= h($old['email']) ?>">
        <label for="invite-password">Mot de passe (<?= (int) ACCOUNT_PASSWORD_MIN ?> caractères au moins)</label>
        <input type="password" id="invite-password" name="password" minlength="<?= (int) ACCOUNT_PASSWORD_MIN ?>" maxlength="200" required autocomplete="new-password">
        <button type="submit" class="btn btn-primary">Créer mon accès</button>
      </form>
      <p class="invite-note">Cet accès donne uniquement le shooting du lieu. Il ne permet ni de publier des articles, ni de modifier le catalogue, ni d'ouvrir les réglages de la boutique.</p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
