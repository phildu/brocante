<?php
session_start();
require_once __DIR__ . '/../includes/team-layout.php';
require_permission('portail.utilisateurs.lire');

$me = auth_user();
auth_boot();
$users = auth_users();
$roles = auth_assignable_roles($me);
$canCreate = auth_can('portail.utilisateurs.creer');
$canEdit = auth_can('portail.utilisateurs.modifier');
$canDelete = auth_can('portail.utilisateurs.supprimer');

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? auth_user_get($editId) : null;
if ($editId > 0 && !$editing) {
    flash_set('Collaborateur introuvable.', 'error');
    header('Location: /admin/users.php');
    exit;
}
$mayManageEditing = $editing && auth_actor_may_manage($me, $editing);
$isSelf = $editing && !$me['legacy'] && $editing['id'] === $me['id'];
// Le rôle actuel doit rester sélectionnable à l'écran même s'il dépasse l'acteur (lecture seule dans ce cas).
$roleIds = array_column($roles, 'id');

team_head('Équipe', 'equipe', 'users');
?>
    <p class="team-note">
      Chaque collaborateur se connecte avec son e-mail et son mot de passe, et n'a que les droits de son
      <a href="/admin/roles.php" style="color:var(--accent);">rôle</a>.
      Le mot de passe principal du site (champ e-mail vide à la connexion) reste un accès « propriétaire ».
    </p>

    <div class="admin-shell">

      <?php if ($editing): ?>
      <div class="admin-block" id="edition">
        <h2>Modifier <?= h($editing['name'] ?: $editing['email']) ?></h2>
        <?php if (!$mayManageEditing): ?>
          <p class="team-note is-warn">Ce compte a plus de droits que vous : consultation seulement.</p>
        <?php endif; ?>
        <form method="post" action="/admin/users-action.php">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <div class="team-grid">
            <div class="field team-field"><label>Nom</label><input type="text" name="name" value="<?= h($editing['name']) ?>" maxlength="80"></div>
            <div class="field team-field"><label>E-mail (identifiant)</label><input type="email" name="email" value="<?= h($editing['email']) ?>" required></div>
          </div>
          <div class="team-grid">
            <div class="field team-field">
              <label>Rôle</label>
              <select name="role_id" <?= (!$canEdit || !$mayManageEditing || $isSelf) ? 'disabled' : '' ?>>
                <?php if (!in_array($editing['role_id'], $roleIds, true)): ?>
                  <option value="<?= (int) $editing['role_id'] ?>" selected><?= h($editing['role_name']) ?></option>
                <?php endif; ?>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= (int) $r['id'] ?>"<?= $r['id'] === $editing['role_id'] ? ' selected' : '' ?>><?= h($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($isSelf): ?><span class="team-meta">Vous ne pouvez pas modifier votre propre rôle.</span><?php endif; ?>
            </div>
            <div class="field team-field">
              <label>Compte</label>
              <label class="featured-check" style="text-transform:none;letter-spacing:0;">
                <input type="checkbox" name="is_active" value="1" <?= $editing['is_active'] ? 'checked' : '' ?> <?= (!$canEdit || !$mayManageEditing || $isSelf) ? 'disabled' : '' ?>>
                Actif (décoché : il ne peut plus se connecter, tout de suite)
              </label>
            </div>
          </div>
          <?php if ($canEdit && $mayManageEditing): ?>
            <?php if ($isSelf): ?><input type="hidden" name="role_id" value="<?= (int) $editing['role_id'] ?>"><input type="hidden" name="is_active" value="1"><?php endif; ?>
            <button type="submit" class="btn btn-primary">Enregistrer</button>
          <?php endif; ?>
          <a class="btn btn-ghost" href="/admin/users.php">Fermer</a>
        </form>

        <?php if ($canEdit && $mayManageEditing): ?>
        <form method="post" action="/admin/users-action.php" style="margin-top:28px;padding-top:22px;border-top:1px solid var(--line);" autocomplete="off">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="set_password">
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <h2 style="font-size:1.1rem;">Réinitialiser le mot de passe</h2>
          <div class="field team-field">
            <label>Nouveau mot de passe provisoire</label>
            <div style="display:flex;gap:8px;">
              <input type="text" name="password" minlength="<?= AUTH_PASSWORD_MIN_LENGTH ?>" autocomplete="off" required data-generated>
              <button type="button" class="btn-small" data-generate>Générer</button>
            </div>
          </div>
          <label class="featured-check" style="margin-bottom:14px;"><input type="checkbox" name="must_change" value="1" checked> Lui demander d'en choisir un nouveau à la prochaine connexion</label>
          <button type="submit" class="btn-small">Réinitialiser</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="admin-block">
        <h2>Collaborateurs (<?= count($users) ?>)</h2>
        <?php if (!$users): ?>
          <p class="empty-state">Aucun collaborateur pour l'instant. Invitez-en un ci-dessous.</p>
        <?php endif; ?>
        <div>
          <?php foreach ($users as $u): $manage = auth_actor_may_manage($me, auth_user_get($u['id'])); $self = !$me['legacy'] && $u['id'] === $me['id']; ?>
            <div class="team-row<?= $u['is_active'] ? '' : ' is-off' ?>">
              <div>
                <h3><?= h($u['name'] ?: $u['email']) ?><?= $self ? ' <span class="team-meta">(vous)</span>' : '' ?></h3>
                <p class="team-meta"><?= h($u['email']) ?></p>
                <p class="team-meta">
                  <span class="team-chip<?= $u['is_owner'] ? ' is-owner' : '' ?>"><?= h($u['role_name']) ?></span>
                  <?php if (!$u['is_active']): ?><span class="team-chip">Désactivé</span><?php endif; ?>
                  <?php if ($u['must_change_password']): ?><span class="team-chip">Mot de passe provisoire</span><?php endif; ?>
                  <?= $u['last_login_at'] ? 'Dernière connexion : ' . h((new DateTimeImmutable($u['last_login_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i')) : 'Jamais connecté' ?>
                </p>
              </div>
              <div class="team-actions">
                <a class="btn-small" href="/admin/users.php?edit=<?= (int) $u['id'] ?>#edition"><?= ($canEdit && $manage) ? 'Modifier' : 'Voir' ?></a>
                <?php if ($canEdit && $manage && !$self): ?>
                  <form method="post" action="/admin/users-action.php">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" class="btn-small"><?= $u['is_active'] ? 'Désactiver' : 'Réactiver' ?></button>
                  </form>
                <?php endif; ?>
                <?php if ($canDelete && $manage && !$self): ?>
                  <form method="post" action="/admin/users-action.php" onsubmit="return confirm('Supprimer définitivement ce compte ?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" class="admin-delete">Supprimer</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($canCreate): ?>
      <form class="admin-block" method="post" action="/admin/users-action.php" autocomplete="off" id="invite">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <h2>Inviter un collaborateur</h2>
        <p class="hint">Vous choisissez un mot de passe provisoire et le lui transmettez ; il devra le remplacer à sa première connexion.</p>
        <?php if (!$roles): ?>
          <p class="team-note is-warn">Aucun rôle n'est attribuable avec vos droits. <?php if (auth_can('portail.roles.creer')): ?><a href="/admin/roles.php?new=1" style="color:var(--accent);">Créez un rôle →</a><?php endif; ?></p>
        <?php endif; ?>
        <div class="team-grid">
          <div class="field team-field"><label>Nom</label><input type="text" name="name" maxlength="80" placeholder="Prénom Nom"></div>
          <div class="field team-field"><label>E-mail (identifiant)</label><input type="email" name="email" required></div>
        </div>
        <div class="team-grid">
          <div class="field team-field">
            <label>Rôle</label>
            <select name="role_id" required>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>"><?= h($r['name']) ?><?= $r['is_owner'] ? ' (tous les droits)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field team-field">
            <label>Mot de passe provisoire</label>
            <div style="display:flex;gap:8px;">
              <input type="text" name="password" minlength="<?= AUTH_PASSWORD_MIN_LENGTH ?>" autocomplete="off" required data-generated>
              <button type="button" class="btn-small" data-generate>Générer</button>
            </div>
          </div>
        </div>
        <label class="featured-check" style="margin-bottom:14px;"><input type="checkbox" name="must_change" value="1" checked> Exiger un nouveau mot de passe à la première connexion</label>
        <button type="submit" class="btn btn-primary"<?= $roles ? '' : ' disabled' ?>>Créer le compte</button>
      </form>
      <?php endif; ?>

    </div>

<script>
document.querySelectorAll('[data-generate]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = btn.parentNode.querySelector('[data-generated]');
    var alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var buf = new Uint32Array(14);
    (window.crypto || window.msCrypto).getRandomValues(buf);
    input.value = Array.prototype.map.call(buf, function (n) { return alphabet[n % alphabet.length]; }).join('');
    input.type = 'text';
    input.select();
  });
});
</script>
<?php team_foot(); ?>
