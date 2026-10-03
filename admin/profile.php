<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$session = admin_session();
$account = $session['id'] !== null ? account_find((int) $session['id']) : null; // null = compte principal (tenant.php)
$lastLogin = $account && $account['last_login_at'] ? date('d/m/Y à H:i', strtotime($account['last_login_at'])) : '—';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mon profil — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .profile-form { max-width: 560px; }
  .profile-form .field { margin-bottom: 12px; }
  .profile-form input { width: 100%; box-sizing: border-box; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 10px 12px; font-family: var(--font-body); font-size: 0.95rem; }
  .profile-form fieldset { border: 1px solid var(--line); padding: 14px 16px 6px; margin: 18px 0; }
  .profile-form legend { padding: 0 8px; font-weight: 600; font-size: 0.9rem; }
  .pw-row { display: flex; gap: 6px; align-items: center; }
  .pw-row input { flex: 1; min-width: 0; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'profil'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Mon compte</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Mon profil</h1>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if (!$account): ?>
      <div class="admin-block">
        <p>Vous êtes connecté avec le <strong>compte principal</strong> du commerce (administrateur). Il est défini dans
          <code>tenants/<?= h(tenant_slug()) ?>/tenant.php</code> et ne se modifie pas depuis l'administration : pour changer son identifiant
          ou son mot de passe, utilisez « Changer l'accès admin » du portail des commerces.</p>
        <p class="hint">Vous pouvez créer votre propre compte nominatif (modifiable ici) dans <a href="/admin/accounts.php">Comptes</a>.</p>
      </div>
    <?php else: ?>
      <p class="hint">
        Rôle : <strong><?= h(account_role_label($account['role'])) ?></strong> · dernière connexion : <?= h($lastLogin) ?>.
        Votre rôle et vos droits sont gérés par un administrateur.
      </p>
      <form class="admin-block profile-form" method="post" action="/admin/profile-action.php" autocomplete="off" style="margin-top:14px;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="field"><label for="p-name">Nom</label><input type="text" name="name" id="p-name" maxlength="80" value="<?= h($account['name']) ?>"></div>
        <div class="field"><label for="p-phone">Téléphone</label><input type="text" name="phone" id="p-phone" maxlength="30" value="<?= h($account['phone']) ?>"></div>
        <div class="field"><label for="p-email">E-mail</label><input type="email" name="email" id="p-email" required maxlength="120" value="<?= h($account['email']) ?>"></div>
        <div class="field"><label for="p-username">Identifiant de connexion <small>(facultatif — sinon l'e-mail)</small></label>
          <input type="text" name="username" id="p-username" maxlength="40" autocapitalize="none" value="<?= h($account['username'] ?? '') ?>"></div>

        <fieldset>
          <legend>Sécurité</legend>
          <p class="hint" style="margin-top:0;">Pour changer votre e-mail, votre identifiant ou votre mot de passe, saisissez votre mot de passe actuel.</p>
          <div class="field"><label for="p-current">Mot de passe actuel</label><input type="password" name="current_password" id="p-current" autocomplete="current-password"></div>
          <div class="field"><label for="p-new">Nouveau mot de passe <small>(laisser vide pour le conserver — <?= ACCOUNT_PASSWORD_MIN ?> caractères minimum)</small></label>
            <span class="pw-row">
              <input type="password" name="new_password" id="p-new" minlength="<?= ACCOUNT_PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password">
              <button type="button" class="btn-small" data-pw-toggle="p-new,p-confirm">Afficher</button>
            </span>
          </div>
          <div class="field"><label for="p-confirm">Confirmer le nouveau mot de passe</label><input type="password" name="new_password_confirm" id="p-confirm" maxlength="72" autocomplete="new-password"></div>
        </fieldset>

        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </form>
    <?php endif; ?>
  </div>
</section>
</main>
<script>
document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var inputs = btn.dataset.pwToggle.split(',').map(function (id) { return document.getElementById(id); });
    var show = inputs[0].type === 'password';
    inputs.forEach(function (input) { input.type = show ? 'text' : 'password'; });
    btn.textContent = show ? 'Masquer' : 'Afficher';
  });
});
</script>
</body>
</html>
