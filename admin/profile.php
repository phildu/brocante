<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vendor-avatar.php';
require_admin();

$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$session = admin_session();
$account = $session['id'] !== null ? account_find((int) $session['id']) : null; // null = compte principal (tenant.php)
$lastLogin = $account && $account['last_login_at'] ? date('d/m/Y à H:i', strtotime($account['last_login_at'])) : '—';
$avatar = vendor_avatar_for($session);
$avatarBlocked = vendor_avatar_blocked_reason();
$avatarCost = ai_format_eur(vendor_avatar_cost_eur());
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mon profil — <?= h($content['site_name']) ?></title>
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
  .avatar-layout { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; margin-top: 14px; }
  .avatar-preview { width: 150px; height: 150px; border-radius: 50%; border: 2px solid var(--line); background: #fff; object-fit: contain; }
  .avatar-empty { display: flex; align-items: center; justify-content: center; text-align: center; border-style: dashed; color: var(--ink-soft); font-size: 0.8rem; line-height: 1.3; }
  .avatar-actions { flex: 1 1 280px; display: grid; gap: 12px; }
  .avatar-form { display: grid; gap: 8px; justify-items: start; }
  .avatar-form input[type=file] { font-size: 0.88rem; max-width: 100%; }
  .avatar-actions .btn, .avatar-actions .btn-small { justify-self: start; }
  .avatar-source { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .avatar-file-status { color: var(--ink); }
  .asnap[hidden] { display: none !important; }
  .asnap [hidden], .avatar-source [hidden], .avatar-file-status[hidden] { display: none !important; }
  .asnap { position: fixed; inset: 0; z-index: 200; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(24, 18, 12, 0.72); }
  .asnap-dialog { background: var(--bg, #fff); color: var(--ink, #222); border-radius: 16px; width: 100%; max-width: 520px; max-height: calc(100vh - 32px); overflow: auto; padding: 18px; box-shadow: 0 24px 70px rgba(0, 0, 0, 0.35); }
  .asnap-dialog h2 { font-size: 1.15rem; margin: 0 0 6px; }
  .asnap-stage { position: relative; margin-top: 12px; background: #111; border-radius: 12px; overflow: hidden; aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center; }
  .asnap-stage video, .asnap-stage img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .asnap-stage video.is-mirrored { transform: scaleX(-1); }
  .asnap-error { margin: 10px 0 0; color: #a03123; font-size: 0.9rem; }
  .asnap-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
  .asnap-actions .btn, .asnap-actions .btn-small { margin: 0; }
  @media (max-width: 520px) {
    .asnap { padding: 0; align-items: flex-end; }
    .asnap-dialog { max-width: none; border-radius: 16px 16px 0 0; padding: 16px 16px calc(16px + env(safe-area-inset-bottom)); max-height: 92vh; }
  }
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

    <div class="admin-block" style="max-width:560px;margin-top:14px;">
      <h2 style="font-size:1.15rem;margin:0 0 6px;">Mon avatar</h2>
      <p class="hint" style="margin:0;">Importez une photo ou prenez un selfie : l'IA en fait une illustration fidèle, détourée. Vous pourrez ensuite l'utiliser comme logo de boutique.</p>
      <div class="avatar-layout">
        <?php if ($avatar): ?>
          <img class="avatar-preview" src="/<?= h($avatar['avatar']) ?>" alt="Votre avatar">
        <?php else: ?>
          <div class="avatar-preview avatar-empty">Aucun avatar<br>pour l'instant</div>
        <?php endif; ?>
        <div class="avatar-actions">
          <?php if ($avatarBlocked): ?>
            <p class="hint" style="margin:0;"><?= h($avatarBlocked) ?></p>
          <?php else: ?>
            <form class="avatar-form" id="avatar-form" method="post" action="/admin/profile-action.php" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="avatar_action" value="generate">
              <label for="a-portrait" class="hint"><?= $avatar ? 'Changer le portrait' : 'Votre portrait' ?> <small>(JPG, PNG ou WebP — 8 Mo max)</small></label>
              <input type="file" name="portrait" id="a-portrait" accept="image/jpeg,image/png,image/webp" required>
              <div class="avatar-source">
                <span class="hint">ou</span>
                <button type="button" class="btn" data-avatar-selfie hidden>Prendre un selfie</button>
              </div>
              <p class="hint avatar-file-status" data-avatar-file-status role="status" hidden></p>
              <button type="submit" class="btn btn-primary"><?= $avatar ? 'Générer un nouvel avatar' : 'Générer mon avatar' ?></button>
              <p class="hint" style="margin:0;">Environ <?= h($avatarCost) ?> décomptés de votre crédit IA. Le portrait importé n'est pas publié sur le site.</p>
            </form>
          <?php endif; ?>
          <?php if ($avatar): ?>
            <form method="post" action="/admin/profile-action.php" onsubmit="return confirm('Votre avatar remplacera les logos actuels de la boutique (en-tête, vertical, carré). Continuer ?');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="avatar_action" value="use_logo">
              <button type="submit" class="btn">Utiliser comme logo de boutique</button>
            </form>
            <form method="post" action="/admin/profile-action.php" onsubmit="return confirm('Retirer votre avatar ? Le logo de la boutique restera inchangé.');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="avatar_action" value="remove">
              <button type="submit" class="btn-small">Retirer l'avatar</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

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
<?php if (!$avatarBlocked): ?>
<div class="asnap" data-avatar-camera hidden>
  <div class="asnap-dialog" role="dialog" aria-modal="true" aria-labelledby="asnap-title">
    <h2 id="asnap-title">Prendre un selfie</h2>
    <p class="hint" data-asnap-hint style="margin:0;">Placez votre visage au centre de l'image.</p>
    <div class="asnap-stage">
      <video data-asnap-video playsinline autoplay muted aria-label="Aperçu de la caméra"></video>
      <img data-asnap-still alt="Aperçu du selfie" hidden>
    </div>
    <p class="asnap-error" data-asnap-error role="alert" hidden></p>
    <div class="asnap-actions">
      <button type="button" class="btn btn-primary" data-asnap-capture>Prendre la photo</button>
      <button type="button" class="btn btn-primary" data-asnap-accept hidden>Utiliser ce selfie</button>
      <button type="button" class="btn" data-asnap-retake hidden>Reprendre</button>
      <button type="button" class="btn" data-asnap-switch hidden>Changer de caméra</button>
      <button type="button" class="btn-small" data-asnap-close>Annuler</button>
    </div>
  </div>
</div>
<?php endif; ?>
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
<script src="/assets/avatar-selfie.js" defer></script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
