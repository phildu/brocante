<?php
session_start();
require_once __DIR__ . '/../includes/team-layout.php';

// Accessible à tout compte connecté, y compris celui dont le mot de passe provisoire doit être changé.
$me = auth_require_login(true);
$forced = $me['must_change'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($me['legacy']) {
        $error = 'Le mot de passe principal se change dans .secrets/admin_password.txt sur le serveur.';
    } else {
        $new = (string) ($_POST['new_password'] ?? '');
        if ($new !== (string) ($_POST['new_password2'] ?? '')) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $error = auth_change_own_password($me['id'], (string) ($_POST['current_password'] ?? ''), $new);
        }
        if ($error === null) {
            session_regenerate_id(true);
            flash_set('Mot de passe modifié.');
            header('Location: ' . auth_home_url());
            exit;
        }
    }
}

team_head('Mon compte', 'compte');
?>
    <?php if ($forced): ?>
      <p class="team-note is-warn">Votre mot de passe a été défini par un responsable : choisissez-en un nouveau pour continuer.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="publish-status" data-kind="error" style="margin-bottom:24px;"><?= h($error) ?></p><?php endif; ?>

    <div class="admin-shell">
      <div class="admin-block">
        <h2><?= h($me['name']) ?></h2>
        <p class="team-meta">
          <?php if ($me['legacy']): ?>
            Connecté avec le mot de passe principal.
          <?php else: ?>
            <?= h($me['email']) ?>
          <?php endif; ?>
        </p>
        <p><span class="team-chip<?= $me['owner'] ? ' is-owner' : '' ?>"><?= h($me['role_name']) ?></span></p>
      </div>

      <?php if (!$me['legacy']): ?>
      <form class="admin-block" method="post" autocomplete="off">
        <?= csrf_input() ?>
        <h2>Changer mon mot de passe</h2>
        <p class="hint">Au moins <?= AUTH_PASSWORD_MIN_LENGTH ?> caractères. Une phrase de passe est plus sûre qu'un mot court.</p>
        <div class="field team-field"><label>Mot de passe actuel</label><input type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="team-grid">
          <div class="field team-field"><label>Nouveau mot de passe</label><input type="password" name="new_password" autocomplete="new-password" minlength="<?= AUTH_PASSWORD_MIN_LENGTH ?>" required></div>
          <div class="field team-field"><label>Confirmer</label><input type="password" name="new_password2" autocomplete="new-password" minlength="<?= AUTH_PASSWORD_MIN_LENGTH ?>" required></div>
        </div>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </form>
      <?php endif; ?>

      <div class="admin-block">
        <h2>Mes droits</h2>
        <?php if ($me['owner']): ?>
          <p class="hint" style="margin:0;">Propriétaire : tous les droits, présents et futurs.</p>
        <?php else: ?>
          <p class="hint">Ce que votre rôle « <?= h($me['role_name']) ?> » vous permet de faire.</p>
          <?php team_permission_summary($me['perms']); ?>
        <?php endif; ?>
      </div>
    </div>
<?php team_foot(); ?>
