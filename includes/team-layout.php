<?php
/**
 * Gabarit des pages « Équipe » : collaborateurs, rôles et droits, mon compte.
 * Même squelette que les autres pages /admin/ (style.css + menu partagé).
 */

require_once __DIR__ . '/functions.php';

function team_head(string $title, string $navKey = 'equipe', string $tab = ''): void
{
    $content = get_content();
    $flash = flash_get();
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&family=Special+Elite&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<style>
  .team-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 26px; border-bottom: 1px solid var(--line); }
  .team-tabs a { padding: 10px 16px; font-family: var(--font-mono); font-size: 0.78rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ink-soft); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
  .team-tabs a[aria-current="page"] { color: var(--ink); border-bottom-color: var(--accent); }
  .team-field input[type="text"], .team-field input[type="email"], .team-field input[type="password"], .team-field select {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 10px 12px; font-family: var(--font-body); font-size: 0.94rem; width: 100%; box-sizing: border-box;
  }
  .team-field input:disabled, .team-field select:disabled { opacity: 0.6; }
  .team-row { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; padding: 16px 0; border-top: 1px solid var(--line); }
  .team-row:first-child { border-top: none; padding-top: 0; }
  .team-row.is-off { opacity: 0.55; }
  .team-row h3 { font-size: 1.05rem; margin: 0 0 4px; }
  .team-meta { font-size: 0.82rem; color: var(--ink-soft); margin: 2px 0; }
  .team-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .team-actions form { margin: 0; }
  .team-chip { display: inline-block; font-family: var(--font-mono); font-size: 0.68rem; letter-spacing: 0.04em; text-transform: uppercase; padding: 3px 8px; border: 1px solid var(--line); color: var(--ink-soft); margin-right: 6px; }
  .team-chip.is-owner { color: var(--accent); border-color: var(--accent); }
  .team-chip.is-ok { color: var(--sage); border-color: var(--sage); }
  .team-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 640px) { .team-grid { grid-template-columns: 1fr; } .team-row { grid-template-columns: 1fr; } .team-actions { justify-content: flex-start; } }
  .team-note { background: var(--surface-2); border: 1px solid var(--line); padding: 12px 14px; font-size: 0.86rem; margin: 0 0 22px; }
  .team-note.is-warn { border-color: var(--accent); }

  /* matrice des droits */
  .perm-level { margin-top: 28px; }
  .perm-level > header { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; border-bottom: 2px solid var(--ink); padding-bottom: 6px; }
  .perm-level h3 { font-size: 1.15rem; margin: 0; }
  .perm-level .hint { margin: 2px 0 0; font-size: 0.82rem; color: var(--ink-soft); }
  .perm-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
  .perm-table th { font-family: var(--font-mono); font-size: 0.66rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ink-soft); text-align: center; padding: 10px 6px; font-weight: 500; }
  .perm-table th:first-child, .perm-table td:first-child { text-align: left; }
  .perm-table td { padding: 10px 6px; border-top: 1px solid var(--line); text-align: center; vertical-align: top; }
  .perm-table td:first-child { padding-left: 0; }
  .perm-res strong { display: block; font-weight: 600; }
  .perm-res span { display: block; color: var(--ink-soft); font-size: 0.76rem; margin-top: 2px; max-width: 34ch; }
  .perm-table input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--accent); cursor: pointer; }
  .perm-table input[type="checkbox"]:disabled { cursor: not-allowed; opacity: 0.35; }
  .perm-na { color: var(--line); }
  .perm-extra { display: flex; flex-direction: column; gap: 4px; text-align: left; }
  .perm-extra label { display: flex; align-items: flex-start; gap: 6px; font-size: 0.78rem; }
  .perm-quick { white-space: nowrap; }
  .perm-quick button, .perm-level button.perm-all { background: none; border: none; color: var(--accent); cursor: pointer; font-size: 0.74rem; padding: 2px 4px; text-decoration: underline; }
  .perm-readonly input[type="checkbox"] { pointer-events: none; }
  @media (max-width: 760px) { .perm-table { display: block; overflow-x: auto; } }
  .perm-summary { display: flex; flex-direction: column; gap: 14px; }
  .perm-summary h4 { margin: 0 0 4px; font-size: 0.95rem; }
  .perm-summary ul { margin: 0; padding-left: 18px; font-size: 0.84rem; color: var(--ink-soft); }
</style>
</head>
<body>
<?php include __DIR__ . '/icons.php'; ?>
<?php $adminNav = $navKey; include __DIR__ . '/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:2rem;margin:12px 0 20px;"><?= h($title) ?></h1>
    <?php if ($navKey === 'equipe'): ?>
      <nav class="team-tabs" aria-label="Équipe">
        <?php if (auth_can('portail.utilisateurs.lire')): ?><a href="/admin/users.php"<?= $tab === 'users' ? ' aria-current="page"' : '' ?>>Collaborateurs</a><?php endif; ?>
        <?php if (auth_can('portail.roles.lire')): ?><a href="/admin/roles.php"<?= $tab === 'roles' ? ' aria-current="page"' : '' ?>>Rôles et droits</a><?php endif; ?>
      </nav>
    <?php endif; ?>
    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>
<?php
}

function team_foot(): void
{
    ?>
  </div>
</section>
</main>
</body>
</html>
<?php
}

/**
 * Matrice des droits : un bloc par niveau, une ligne par ressource.
 * $selected = droits cochés ; $editable = false pour un affichage en lecture seule ;
 * $grantable (clé => true) = droits que l'acteur peut accorder (null = tous).
 */
function team_permission_matrix(array $selected, bool $editable = true, ?array $grantable = null): void
{
    $sel = array_fill_keys($selected, true);
    $standard = auth_standard_actions();
    foreach (auth_catalog() as $levelKey => $level): ?>
      <div class="perm-level" data-level="<?= h($levelKey) ?>">
        <header>
          <div>
            <h3><?= h($level['label']) ?></h3>
            <p class="hint"><?= h($level['hint']) ?></p>
          </div>
          <?php if ($editable): ?>
            <span><button type="button" class="perm-all" data-level-all="1">Tout cocher</button><button type="button" class="perm-all" data-level-all="0">Tout décocher</button></span>
          <?php endif; ?>
        </header>
        <table class="perm-table<?= $editable ? '' : ' perm-readonly' ?>">
          <thead>
            <tr>
              <th>Ressource</th>
              <?php foreach ($standard as $label): ?><th><?= h($label) ?></th><?php endforeach; ?>
              <th style="text-align:left;">Droits spécifiques</th>
              <?php if ($editable): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($level['resources'] as $resKey => $res): ?>
              <tr data-resource="<?= h("$levelKey.$resKey") ?>">
                <td class="perm-res"><strong><?= h($res['label']) ?></strong><span><?= h($res['hint']) ?></span></td>
                <?php foreach ($standard as $action => $stdLabel): ?>
                  <td>
                    <?php if (isset($res['actions'][$action])): $key = "$levelKey.$resKey.$action"; ?>
                      <input type="checkbox" name="perms[]" value="<?= h($key) ?>" data-action="<?= h($action) ?>"
                             title="<?= h($res['actions'][$action]) ?>" aria-label="<?= h($res['label'] . ' — ' . $res['actions'][$action]) ?>"
                             <?= isset($sel[$key]) ? 'checked' : '' ?>
                             <?= (!$editable || ($grantable !== null && !isset($grantable[$key]))) ? 'disabled' : '' ?>>
                    <?php else: ?><span class="perm-na">—</span><?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td>
                  <div class="perm-extra">
                    <?php foreach ($res['actions'] as $action => $label): if (isset($standard[$action])) continue; $key = "$levelKey.$resKey.$action"; ?>
                      <label><input type="checkbox" name="perms[]" value="<?= h($key) ?>" data-action="<?= h($action) ?>"
                             <?= isset($sel[$key]) ? 'checked' : '' ?>
                             <?= (!$editable || ($grantable !== null && !isset($grantable[$key]))) ? 'disabled' : '' ?>> <?= h($label) ?></label>
                    <?php endforeach; ?>
                  </div>
                </td>
                <?php if ($editable): ?>
                  <td class="perm-quick"><button type="button" data-row-all="1">tout</button><button type="button" data-row-all="0">aucun</button></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach;
}

/** Comportement de la matrice : « Consulter » est coché d'office dès qu'un autre droit l'est, et inversement. */
function team_permission_matrix_script(): void
{
    ?>
<script>
(function () {
  var form = document.getElementById('role-form');
  if (!form) return;
  function boxes(scope) { return Array.prototype.slice.call(scope.querySelectorAll('input[type="checkbox"][name="perms[]"]:not(:disabled)')); }
  function syncRow(row, changed) {
    var lire = row.querySelector('input[data-action="lire"]');
    if (!lire || lire.disabled) return;
    var others = boxes(row).filter(function (b) { return b !== lire; });
    if (changed === lire && !lire.checked) others.forEach(function (b) { b.checked = false; });
    else if (others.some(function (b) { return b.checked; })) lire.checked = true;
  }
  form.addEventListener('change', function (e) {
    var row = e.target.closest('tr[data-resource]');
    if (row) syncRow(row, e.target);
  });
  form.addEventListener('click', function (e) {
    var b = e.target;
    if (b.dataset.rowAll !== undefined) {
      var row = b.closest('tr[data-resource]');
      boxes(row).forEach(function (c) { c.checked = b.dataset.rowAll === '1'; });
    } else if (b.dataset.levelAll !== undefined) {
      boxes(b.closest('.perm-level')).forEach(function (c) { c.checked = b.dataset.levelAll === '1'; });
    }
  });
})();
</script>
<?php
}

/** Résumé lisible des droits d'une personne ou d'un rôle, groupé par niveau et ressource. */
function team_permission_summary(array $perms): void
{
    $have = array_fill_keys($perms, true);
    ?><div class="perm-summary"><?php
    $any = false;
    foreach (auth_catalog() as $levelKey => $level) {
        $lines = [];
        foreach ($level['resources'] as $resKey => $res) {
            $labels = [];
            foreach ($res['actions'] as $action => $label) {
                if (isset($have["$levelKey.$resKey.$action"])) $labels[] = $label;
            }
            if ($labels) $lines[] = '<li><strong>' . h($res['label']) . '</strong> — ' . h(implode(' · ', $labels)) . '</li>';
        }
        if ($lines) {
            $any = true;
            echo '<div><h4>' . h($level['label']) . '</h4><ul>' . implode('', $lines) . '</ul></div>';
        }
    }
    if (!$any) echo '<p class="team-meta">Aucun droit pour l\'instant.</p>';
    ?></div><?php
}
