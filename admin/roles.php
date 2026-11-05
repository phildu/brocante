<?php
session_start();
require_once __DIR__ . '/../includes/team-layout.php';
require_permission('portail.roles.lire');

$me = auth_user();
$roles = auth_roles();
$canCreate = auth_can('portail.roles.creer');
$canEdit = auth_can('portail.roles.modifier');
$canDelete = auth_can('portail.roles.supprimer');

// Droits que l'acteur peut accorder (le propriétaire : tous).
$grantable = $me['owner'] ? null : $me['perm_set'];

$editId = (int) ($_GET['edit'] ?? 0);
$isNew = isset($_GET['new']);
$cloneId = (int) ($_GET['from'] ?? 0);
$form = null; // [id|null, name, description, perms, owner, covered]

if ($editId > 0) {
    $role = auth_role_get($editId);
    if (!$role) {
        flash_set('Rôle introuvable.', 'error');
        header('Location: /admin/roles.php');
        exit;
    }
    $covered = auth_actor_covers($me, $role['perms'], (bool) $role['is_owner']);
    $form = ['id' => $role['id'], 'name' => $role['name'], 'description' => $role['description'], 'perms' => $role['perms'], 'owner' => (bool) $role['is_owner'], 'covered' => $covered];
} elseif ($isNew && $canCreate) {
    $src = $cloneId > 0 ? auth_role_get($cloneId) : null;
    $perms = $src && !$src['is_owner'] ? $src['perms'] : [];
    // Un clone ne reprend que ce que l'acteur peut lui-même accorder.
    if ($grantable !== null) $perms = array_values(array_filter($perms, fn($p) => isset($grantable[$p])));
    $form = ['id' => null, 'name' => $src ? 'Copie de ' . $src['name'] : '', 'description' => $src ? $src['description'] : '', 'perms' => $perms, 'owner' => false, 'covered' => true];
}

// Saisie conservée après un refus (nom déjà pris, droit non accordable…).
$draft = $_SESSION['role_form_draft'] ?? null;
unset($_SESSION['role_form_draft']);
if ($form && $draft && $draft['id'] === $form['id'] && !$form['owner']) {
    $form['name'] = (string) $draft['name'];
    $form['description'] = (string) $draft['description'];
    $form['perms'] = auth_normalize_permissions($draft['perms']);
}

$formEditable = $form && !$form['owner'] && (
    $form['id'] === null ? $canCreate : ($canEdit && $form['covered'] && ($me['owner'] || $form['id'] !== $me['role_id']))
);

team_head('Équipe', 'equipe', 'roles');
?>
    <p class="team-note">
      Un rôle regroupe des droits <strong>par niveau</strong> (Portail, Galerie, Boutique) et <strong>par ressource</strong> :
      consulter, créer, modifier, supprimer, plus quelques droits spécifiques (IA, masquage, prix d'achat…).
      Les changements s'appliquent immédiatement aux personnes qui ont ce rôle.
      <?php if (!$me['owner']): ?>Vous ne pouvez accorder que les droits que vous possédez.<?php endif; ?>
    </p>

    <div class="admin-shell">

      <?php if ($form): ?>
      <form class="admin-block" method="post" action="/admin/roles-action.php" id="role-form">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="<?= $form['id'] === null ? 'create' : 'update' ?>">
        <?php if ($form['id'] !== null): ?><input type="hidden" name="id" value="<?= (int) $form['id'] ?>"><?php endif; ?>
        <h2><?= $form['id'] === null ? 'Nouveau rôle' : 'Rôle « ' . h($form['name']) . ' »' ?></h2>

        <?php if ($form['owner']): ?>
          <p class="team-note">Le rôle Propriétaire a tous les droits, y compris ceux qui seront ajoutés plus tard. Il n'est pas modifiable.</p>
        <?php elseif (!$formEditable && $form['id'] !== null): ?>
          <p class="team-note is-warn">
            <?php if (!$form['covered']): ?>Ce rôle contient des droits que vous ne possédez pas : consultation seulement.
            <?php elseif (!$me['owner'] && $form['id'] === $me['role_id']): ?>C'est votre propre rôle : un autre responsable doit le modifier.
            <?php else: ?>Consultation seulement.<?php endif; ?>
          </p>
        <?php endif; ?>

        <div class="team-grid">
          <div class="field team-field"><label>Nom du rôle</label><input type="text" name="name" value="<?= h($form['name']) ?>" maxlength="60" required <?= $formEditable ? '' : 'disabled' ?>></div>
          <div class="field team-field"><label>Description (facultatif)</label><input type="text" name="description" value="<?= h($form['description']) ?>" maxlength="300" <?= $formEditable ? '' : 'disabled' ?>></div>
        </div>

        <?php team_permission_matrix($form['perms'], $formEditable, $formEditable ? $grantable : null); ?>

        <div style="margin-top:26px;display:flex;gap:10px;flex-wrap:wrap;">
          <?php if ($formEditable): ?><button type="submit" class="btn btn-primary"><?= $form['id'] === null ? 'Créer le rôle' : 'Enregistrer les droits' ?></button><?php endif; ?>
          <a class="btn btn-ghost" href="/admin/roles.php">Fermer</a>
        </div>
      </form>
      <?php if ($formEditable) team_permission_matrix_script(); ?>
      <?php endif; ?>

      <div class="admin-block">
        <h2>Rôles (<?= count($roles) ?>)</h2>
        <div>
          <?php foreach ($roles as $r): $perms = $r['is_owner'] ? [] : auth_role_permissions($r['id']); $covered = auth_actor_covers($me, $perms, (bool) $r['is_owner']); ?>
            <div class="team-row">
              <div>
                <h3><?= h($r['name']) ?> <?php if ($r['is_owner']): ?><span class="team-chip is-owner">Tous les droits</span><?php endif; ?></h3>
                <?php if ($r['description'] !== ''): ?><p class="team-meta"><?= h($r['description']) ?></p><?php endif; ?>
                <p class="team-meta">
                  <?= $r['is_owner'] ? 'Droits illimités' : $r['perms_count'] . ' droit' . ($r['perms_count'] > 1 ? 's' : '') ?>
                  · <?= $r['users_count'] ?> collaborateur<?= $r['users_count'] > 1 ? 's' : '' ?>
                </p>
              </div>
              <div class="team-actions">
                <a class="btn-small" href="/admin/roles.php?edit=<?= (int) $r['id'] ?>#role-form"><?= (!$r['is_owner'] && $canEdit && $covered) ? 'Modifier' : 'Voir' ?></a>
                <?php if ($canCreate && !$r['is_owner']): ?>
                  <a class="btn-small" href="/admin/roles.php?new=1&amp;from=<?= (int) $r['id'] ?>#role-form">Dupliquer</a>
                <?php endif; ?>
                <?php if ($canDelete && !$r['is_owner'] && $covered): ?>
                  <form method="post" action="/admin/roles-action.php" onsubmit="return confirm('Supprimer le rôle « <?= h(addslashes($r['name'])) ?> » ?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="admin-delete"<?= $r['users_count'] > 0 ? ' disabled title="Des collaborateurs ont ce rôle"' : '' ?>>Supprimer</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($canCreate && !$form): ?>
          <a class="add-product-btn" style="display:block;text-decoration:none;" href="/admin/roles.php?new=1#role-form">+ Créer un rôle</a>
        <?php endif; ?>
      </div>

    </div>
<?php team_foot(); ?>
