<?php
// Choix d'un nouveau mot de passe depuis le lien reçu par e-mail (voir includes/password-reset.php).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/login-ui.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$from = (string) ($_GET['from'] ?? $_POST['from'] ?? '') === 'studio' ? 'studio' : '';
$back = $from === 'studio' ? '/studio.php' : '/admin/login.php';
$reset = password_reset_find($token);
$error = '';

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Le formulaire a expiré : réessayez.';
    } else {
        try {
            password_reset_apply($reset, (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            $_SESSION['login_notice'] = 'Mot de passe modifié : connectez-vous avec le nouveau.';
            header('Location: ' . $back);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

ob_start();
?>
    <p class="eyebrow">Espace boutique</p>
<?php if (!$reset): ?>
    <h1>Lien non valable</h1>
    <p class="lede">Ce lien a expiré ou a déjà servi. Faites une nouvelle demande : un lien est valable 1 heure et ne s'utilise qu'une fois.</p>
    <p><a class="btn btn-primary" href="/admin/mot-de-passe-oublie.php<?= $from ? '?from=studio' : '' ?>">Redemander un lien</a></p>
<?php else: ?>
    <h1>Nouveau mot de passe</h1>
    <p class="lede">Choisissez un mot de passe d'au moins <?= (int) ACCOUNT_PASSWORD_MIN ?> caractères.</p>
    <?php if ($error): ?><p class="login-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" class="login-unified-form">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="from" value="<?= h($from) ?>">
      <?= login_password_field_html(['name' => 'password', 'id' => 'pw1', 'label' => 'Nouveau mot de passe', 'autocomplete' => 'new-password', 'minlength' => ACCOUNT_PASSWORD_MIN, 'maxlength' => 200, 'autofocus' => true]) ?>
      <?= login_password_field_html(['name' => 'confirm', 'id' => 'pw2', 'label' => 'Confirmer le mot de passe', 'autocomplete' => 'new-password', 'minlength' => ACCOUNT_PASSWORD_MIN, 'maxlength' => 200]) ?>
      <button class="btn btn-primary login-submit" type="submit">Enregistrer</button>
    </form>
<?php endif; ?>
<?php password_reset_page('Nouveau mot de passe', (string) ob_get_clean());
