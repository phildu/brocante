<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin(); // réservée aux administrateurs

$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$actingId = admin_session()['id'] ?? null;

$role = (string) ($_GET['role'] ?? '');
$role = isset(ACCOUNT_ROLES[$role]) ? $role : null;
$search = trim((string) ($_GET['q'] ?? ''));
$counts = accounts_counts();
$accounts = accounts_list($role, $search);

$editing = isset($_GET['edit']) ? account_find((int) $_GET['edit']) : null;
$old = $_SESSION['accounts_old'] ?? null; // saisie conservée après une erreur de création
unset($_SESSION['accounts_old']);
$showAdd = isset($_GET['add']) || $old !== null;
$newDefaults = ['role' => $role ?? 'prospect', 'name' => '', 'email' => '', 'username' => '', 'phone' => '', 'notes' => ''];
$new = $old ? array_merge($newDefaults, $old) : $newDefaults;

$mainUser = (string) tenant('admin_user');
$nonStaffExport = $counts['client'] + $counts['prospect'] > 0;
$fmtMoney = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';
$fmtDate = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

/** Champs communs au formulaire d'ajout et de modification. */
$renderFields = static function (array $a, string $prefix) {
    ?>
    <div class="field-row-2">
      <div class="field"><label for="<?= $prefix ?>-role">Rôle</label>
        <select name="role" id="<?= $prefix ?>-role" data-role-select>
          <?php foreach (ACCOUNT_ROLES as $key => $label): ?>
            <option value="<?= h($key) ?>"<?= $a['role'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label for="<?= $prefix ?>-name">Nom</label><input type="text" name="name" id="<?= $prefix ?>-name" maxlength="80" value="<?= h($a['name']) ?>"></div>
    </div>
    <div class="field-row-2">
      <div class="field"><label for="<?= $prefix ?>-email">E-mail</label><input type="email" name="email" id="<?= $prefix ?>-email" required maxlength="120" value="<?= h($a['email']) ?>"></div>
      <div class="field"><label for="<?= $prefix ?>-phone">Téléphone</label><input type="text" name="phone" id="<?= $prefix ?>-phone" maxlength="30" value="<?= h($a['phone']) ?>"></div>
    </div>
    <div class="field-row-2" data-staff-only>
      <div class="field"><label for="<?= $prefix ?>-username">Identifiant de connexion <small>(facultatif — sinon l'e-mail)</small></label>
        <input type="text" name="username" id="<?= $prefix ?>-username" maxlength="40" autocomplete="off" autocapitalize="none" value="<?= h($a['username'] ?? '') ?>"></div>
      <div class="field"><label for="<?= $prefix ?>-password"><?= $prefix === 'edit' ? 'Nouveau mot de passe <small>(laisser vide pour le conserver)</small>' : 'Mot de passe' ?></label>
        <span class="password-row">
          <input type="password" name="password" id="<?= $prefix ?>-password" minlength="<?= ACCOUNT_PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password" placeholder="<?= ACCOUNT_PASSWORD_MIN ?> caractères minimum">
          <button type="button" class="btn-small" data-pw-toggle="<?= $prefix ?>-password">Afficher</button>
          <button type="button" class="btn-small" data-pw-generate="<?= $prefix ?>-password">Générer</button>
        </span>
      </div>
    </div>
    <div class="field"><label for="<?= $prefix ?>-notes">Notes</label><textarea name="notes" id="<?= $prefix ?>-notes" maxlength="600" rows="2"><?= h($a['notes']) ?></textarea></div>
    <?php
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comptes — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .acc-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 20px 0 14px; }
  .acc-tabs a { border: 1px solid var(--line); padding: 6px 12px; font-size: 0.85rem; color: var(--ink-soft); text-decoration: none; background: var(--bg); }
  .acc-tabs a[aria-current="page"] { border-color: var(--accent); color: var(--ink); background: var(--surface); font-weight: 600; }
  .acc-tabs small { color: var(--ink-soft); margin-left: 4px; }
  .acc-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
  .acc-toolbar form.search { display: flex; gap: 6px; margin-right: auto; }
  .acc-toolbar input[type="search"], .acc-form input, .acc-form select, .acc-form textarea {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem; box-sizing: border-box; }
  .acc-form .field input, .acc-form .field select, .acc-form .field textarea { width: 100%; }
  .acc-form .field { margin-bottom: 10px; }
  .password-row { display: flex; gap: 6px; align-items: center; }
  .password-row input { flex: 1; min-width: 0; }
  table.acc-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; background: var(--surface); border: 1px solid var(--line); }
  table.acc-table th, table.acc-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.acc-table th { color: var(--ink-soft); font-weight: 600; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.03em; }
  .acc-name { font-weight: 600; }
  .acc-meta { color: var(--ink-soft); font-size: 0.8rem; }
  .acc-badge { display: inline-block; font-family: var(--font-mono); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid var(--line); padding: 2px 7px; }
  .acc-badge[data-role="admin"] { border-color: var(--accent); color: var(--accent); }
  .acc-badge[data-role="community_manager"] { border-color: var(--accent-2, var(--accent)); color: var(--accent-2, var(--accent)); }
  .acc-off { opacity: 0.55; }
  .acc-actions { display: flex; gap: 6px; flex-wrap: wrap; }
  .acc-actions form { display: inline; }
  .acc-legend table { border-collapse: collapse; font-size: 0.85rem; margin-top: 10px; }
  .acc-legend th, .acc-legend td { text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
  .acc-form [hidden] { display: none !important; }
  @media (max-width: 720px) { table.acc-table .hide-sm { display: none; } }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'comptes'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Équipe et contacts</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Comptes</h1>
    <p class="hint">
      <strong>Administrateurs</strong> et <strong>community managers</strong> se connectent à l'administration ;
      <strong>clients</strong> et <strong>prospects</strong> sont des contacts de la boutique (sans accès).
      Un client est créé automatiquement à chaque commande payée, un prospect à chaque inscription à la newsletter ;
      un prospect qui commande devient client.
    </p>
    <details class="acc-legend" style="margin:10px 0;">
      <summary style="cursor:pointer;">Ce que chaque rôle peut faire</summary>
      <table>
        <tr><th>Administrateur</th><td>Tout : catalogue, commandes, comptes, frais de port, apparence, réglages, envoi en préprod.</td></tr>
        <tr><th>Community manager</th><td>Visuels et communication : diaporamas, bandeaux de page, médiathèque, galerie photo des pièces. Pas d'accès au catalogue, aux prix, aux commandes, aux clients ni aux réglages.</td></tr>
        <tr><th>Client / Prospect</th><td>Fiche de contact (coordonnées, notes, commandes rapprochées par e-mail). Aucune connexion.</td></tr>
      </table>
    </details>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php if ($mainUser !== ''): ?>
      <p class="hint" style="margin:8px 0;">Compte principal du commerce : <strong><?= h($mainUser) ?></strong> (défini dans <code>tenants/<?= h(tenant_slug()) ?>/tenant.php</code>, administrateur, toujours valable). Il n'apparaît pas dans la liste.</p>
    <?php endif; ?>

    <nav class="acc-tabs" aria-label="Filtrer par rôle">
      <a href="/admin/accounts.php"<?= $role === null ? ' aria-current="page"' : '' ?>>Tous<small><?= (int) $counts['all'] ?></small></a>
      <?php foreach (ACCOUNT_ROLES as $key => $label): ?>
        <a href="/admin/accounts.php?role=<?= h($key) ?>"<?= $role === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?>s<small><?= (int) $counts[$key] ?></small></a>
      <?php endforeach; ?>
    </nav>

    <div class="acc-toolbar">
      <form class="search" method="get" action="/admin/accounts.php" role="search">
        <?php if ($role): ?><input type="hidden" name="role" value="<?= h($role) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Rechercher (nom, e-mail, téléphone…)" aria-label="Rechercher un compte">
        <button type="submit" class="btn-small">Rechercher</button>
        <?php if ($search !== ''): ?><a class="btn-small" href="/admin/accounts.php<?= $role ? '?role=' . h($role) : '' ?>">Effacer</a><?php endif; ?>
      </form>
      <form method="post" action="/admin/accounts-action.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="sync_clients">
        <button type="submit" class="btn-small" title="Ajoute une fiche client pour chaque adresse e-mail ayant une commande payée">Importer les clients depuis les commandes</button>
      </form>
      <?php if ($nonStaffExport): ?>
        <a class="btn-small" href="/admin/accounts-action.php?export=1<?= in_array($role, ['client', 'prospect'], true) ? '&amp;role=' . h($role) : '' ?>">Exporter les contacts (CSV)</a>
      <?php endif; ?>
      <a class="btn btn-primary" href="/admin/accounts.php?add=1<?= $role ? '&amp;role=' . h($role) : '' ?>#ajout">Ajouter un compte</a>
    </div>

    <?php if ($editing): ?>
      <div class="admin-block acc-form" id="modifier">
        <h2 style="font-size:1.1rem;">Modifier — <?= h($editing['name'] !== '' ? $editing['name'] : $editing['email']) ?></h2>
        <form method="post" action="/admin/accounts-action.php" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <input type="hidden" name="back_role" value="<?= h($role ?? '') ?>">
          <?php $renderFields($editing, 'edit'); ?>
          <label class="featured-check" style="margin-bottom:12px;"><input type="checkbox" name="is_active" value="1"<?= $editing['is_active'] ? ' checked' : '' ?><?= $actingId !== null && (int) $editing['id'] === $actingId ? ' disabled' : '' ?>> Compte actif</label>
          <p class="hint">Origine : <?= h(ACCOUNT_SOURCES[$editing['source']] ?? $editing['source']) ?> · créé le <?= h($fmtDate($editing['created_at'])) ?> · dernière connexion : <?= h($fmtDate($editing['last_login_at'])) ?></p>
          <div style="display:flex;gap:10px;margin-top:12px;">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
            <a class="btn" href="/admin/accounts.php<?= $role ? '?role=' . h($role) : '' ?>">Annuler</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($showAdd): ?>
      <div class="admin-block acc-form" id="ajout">
        <h2 style="font-size:1.1rem;">Ajouter un compte</h2>
        <form method="post" action="/admin/accounts-action.php" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="back_role" value="<?= h($role ?? '') ?>">
          <?php $renderFields($new, 'add'); ?>
          <div style="display:flex;gap:10px;margin-top:12px;">
            <button type="submit" class="btn btn-primary">Créer le compte</button>
            <a class="btn" href="/admin/accounts.php<?= $role ? '?role=' . h($role) : '' ?>">Annuler</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if (!$accounts): ?>
      <p class="empty-state"><?= $search !== '' ? 'Aucun compte ne correspond à cette recherche.' : 'Aucun compte pour l\'instant.' ?></p>
    <?php else: ?>
      <table class="acc-table">
        <thead>
          <tr><th>Compte</th><th>Rôle</th><th class="hide-sm">Origine</th><th class="hide-sm">Commandes</th><th class="hide-sm">Dernière connexion</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $a): $isSelf = $actingId !== null && (int) $a['id'] === $actingId; ?>
            <tr class="<?= $a['is_active'] ? '' : 'acc-off' ?>">
              <td>
                <div class="acc-name"><?= h($a['name'] !== '' ? $a['name'] : $a['email']) ?><?= $isSelf ? ' <span class="acc-meta">(vous)</span>' : '' ?></div>
                <div class="acc-meta"><?= $a['name'] !== '' ? h($a['email']) : '' ?><?= $a['phone'] !== '' ? ' · ' . h($a['phone']) : '' ?><?= $a['username'] ? ' · identifiant ' . h($a['username']) : '' ?></div>
                <?php if ($a['notes'] !== ''): ?><div class="acc-meta" title="<?= h($a['notes']) ?>">📝 <?= h(mb_strimwidth($a['notes'], 0, 80, '…')) ?></div><?php endif; ?>
              </td>
              <td><span class="acc-badge" data-role="<?= h($a['role']) ?>"><?= h(account_role_label($a['role'])) ?></span><?= $a['is_active'] ? '' : '<div class="acc-meta">Désactivé</div>' ?></td>
              <td class="hide-sm"><?= h(ACCOUNT_SOURCES[$a['source']] ?? $a['source']) ?><div class="acc-meta"><?= h($fmtDate($a['created_at'])) ?></div></td>
              <td class="hide-sm"><?= (int) $a['orders_count'] > 0 ? (int) $a['orders_count'] . ' · ' . h($fmtMoney((int) $a['orders_total'])) : '—' ?></td>
              <td class="hide-sm"><?= account_is_staff($a['role']) ? h($fmtDate($a['last_login_at'])) : '—' ?></td>
              <td>
                <div class="acc-actions">
                  <a class="btn-small" href="/admin/accounts.php?edit=<?= (int) $a['id'] ?><?= $role ? '&amp;role=' . h($role) : '' ?>#modifier">Modifier</a>
                  <?php if (!$isSelf): ?>
                    <form method="post" action="/admin/accounts-action.php">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <input type="hidden" name="active" value="<?= $a['is_active'] ? '0' : '1' ?>">
                      <input type="hidden" name="back_role" value="<?= h($role ?? '') ?>">
                      <button type="submit" class="btn-small"><?= $a['is_active'] ? 'Désactiver' : 'Réactiver' ?></button>
                    </form>
                    <form method="post" action="/admin/accounts-action.php" onsubmit="return confirm('Supprimer définitivement ce compte ?');">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <input type="hidden" name="back_role" value="<?= h($role ?? '') ?>">
                      <button type="submit" class="admin-delete">Supprimer</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
</main>
<script>
(function () {
  var STAFF = ['admin', 'community_manager'];
  // Identifiant et mot de passe : seulement pour les rôles qui se connectent.
  document.querySelectorAll('[data-role-select]').forEach(function (select) {
    var form = select.closest('form');
    function sync() {
      var staff = STAFF.indexOf(select.value) !== -1;
      form.querySelectorAll('[data-staff-only]').forEach(function (el) { el.hidden = !staff; });
    }
    select.addEventListener('change', sync);
    sync();
  });
  document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.pwToggle);
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Masquer' : 'Afficher';
    });
  });
  document.querySelectorAll('[data-pw-generate]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.pwGenerate);
      var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      var bytes = new Uint8Array(14);
      crypto.getRandomValues(bytes);
      input.value = Array.prototype.map.call(bytes, function (b) { return chars[b % chars.length]; }).join('');
      input.type = 'text';
      var toggle = document.querySelector('[data-pw-toggle="' + input.id + '"]');
      if (toggle) toggle.textContent = 'Masquer';
    });
  });
})();
</script>
</body>
</html>
