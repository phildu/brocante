<?php
// Choix d'un nouveau mot de passe depuis le lien reçu par e-mail (voir includes/password-reset.php).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

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
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="from" value="<?= h($from) ?>">
      <label class="lbl" for="pw1">Nouveau mot de passe</label>
      <input type="password" name="password" id="pw1" autocomplete="new-password" minlength="<?= (int) ACCOUNT_PASSWORD_MIN ?>" maxlength="200" required autofocus>
      <label class="lbl" for="pw2">Confirmer le mot de passe</label>
      <input type="password" name="confirm" id="pw2" autocomplete="new-password" minlength="<?= (int) ACCOUNT_PASSWORD_MIN ?>" maxlength="200" required>
      <label class="showpw"><input type="checkbox" id="showpw"> Afficher les mots de passe</label>
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Enregistrer</button>
    </form>
    <script>
      (function () {
        var cb = document.getElementById('showpw'), a = document.getElementById('pw1'), b = document.getElementById('pw2');
        cb.addEventListener('change', function () { a.type = b.type = cb.checked ? 'text' : 'password'; });
        a.form.addEventListener('submit', function () { a.type = b.type = 'password'; });
      })();
    </script>
<?php endif; ?>
<?php password_reset_page('Nouveau mot de passe', (string) ob_get_clean());
