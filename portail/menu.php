<?php
// Menu latéral de l'administration, commun à toutes les boutiques : catégories,
// ordre des catégories et des entrées, pages de plugins comprises. La
// configuration est écrite dans data/admin-menu.json (includes/admin-menu.php) ;
// le menu de chaque boutique la lit et n'affiche que les entrées de son rôle et
// de ses plugins actifs.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/admin-menu.php';
require_once __DIR__ . '/_menu.php';

$shops = tenant_list();
$flash = portail_flash();
$catalog = portail_menu_catalog();
$layout = admin_menu_layout($catalog);
$activity = portail_menu_plugin_activity($shops);
$plugins = plugin_catalog();
$config = admin_menu_config();
$personalized = admin_menu_global_config() !== null;
$hiddenKeys = array_fill_keys($config['hidden'], true);
$customKeys = $config['custom'];
$iconChoices = portail_menu_icon_choices();
$hiddenCount = 0;
foreach (array_keys($hiddenKeys) as $hiddenKey) {
    if (isset($catalog[$hiddenKey])) $hiddenCount++;
}
$itemCount = array_sum(array_map(static fn (array $g): int => count($g['items']), $layout));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Menu latéral — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .menu-wrap { width: 100%; max-width: 1100px; margin: 0 auto; padding-bottom: 56px; }
  .menu-help { color: var(--muted); font-size: 0.88rem; max-width: 820px; margin: 0; }
  .menu-status { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 14px 0 0; }
  .menu-tag { display: inline-block; padding: 1px 8px; border: 1px solid var(--line); border-radius: 999px; font-size: 0.74rem; font-weight: 700; }
  .menu-tag.ok { color: var(--ok); border-color: currentColor; }
  .menu-editor { display: grid; gap: 14px; margin: 18px 0 0; }
  .menu-group { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; }
  .menu-group-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .menu-group-head input[type="text"] { flex: 1 1 220px; min-width: 130px; background: var(--panel-2); border: 1px solid var(--line); border-radius: 7px; padding: 7px 9px; color: var(--ink); }
  .menu-count { font-family: var(--font-mono); font-size: 0.72rem; color: var(--muted); white-space: nowrap; }
  .menu-grip { color: var(--muted); font-size: 0.9rem; letter-spacing: -2px; user-select: none; }
  .menu-items { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 4px; }
  .menu-items:empty { display: none; }
  .menu-item { display: flex; align-items: center; gap: 10px; padding: 7px 9px; border: 1px solid var(--line); border-radius: 7px; background: var(--panel-2); }
  .menu-item.is-hidden { border-style: dashed; opacity: 0.6; }
  .menu-item-icon { display: flex; flex: none; color: var(--muted); }
  .menu-item-icon svg { width: 17px; height: 17px; display: block; }
  .menu-item-label { flex: 1 1 180px; min-width: 0; font-size: 0.9rem; }
  .menu-item-label small { display: block; font-family: var(--font-mono); font-size: 0.66rem; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .menu-item select { max-width: 190px; background: var(--panel); border: 1px solid var(--line); border-radius: 6px; padding: 5px 7px; font-size: 0.85rem; }
  .menu-hide { display: flex; align-items: center; gap: 5px; flex: none; font-size: 0.72rem; color: var(--muted); cursor: pointer; }
  .menu-hide input { margin: 0; }
  .menu-item .menu-delete { border-color: #d9a1a1; color: #a33; }
  .menu-empty { margin: 10px 0 0; font-size: 0.82rem; color: var(--muted); }
  .menu-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 18px; }
  .menu-create { margin-top: 26px; border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; }
  .menu-create h2 { margin: 0; font-size: 1rem; }
  .menu-create p { margin: 6px 0 12px; font-size: 0.82rem; color: var(--muted); }
  .menu-create-grid { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .menu-create-grid label { display: flex; flex-direction: column; gap: 4px; flex: 1 1 180px; font-size: 0.78rem; color: var(--muted); }
  .menu-create-grid input, .menu-create-grid select { background: var(--panel-2); border: 1px solid var(--line); border-radius: 7px; padding: 7px 9px; color: var(--ink); font: inherit; }
  @media (max-width: 640px) {
    .menu-item { flex-wrap: wrap; }
    .menu-item select { max-width: none; width: 100%; }
  }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Menu latéral',
    'eyebrow' => 'Boutiques',
    'desc' => 'Catégories et ordre communs à toutes les boutiques, pages de plugins comprises.',
]); ?>

  <?php if ($flash): ?>
    <p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
  <?php endif; ?>

  <div class="menu-wrap">
    <p class="menu-help">Rangez les entrées de la barre latérale d’administration une fois pour <strong>toutes les boutiques</strong> :
      renommez les catégories, déplacez-les, classez les pages. Cochez <strong>Masqué</strong> pour retirer une entrée du menu de toutes les
      boutiques sans la supprimer (elle reste ici pour la réafficher). Un <strong>item créé</strong> ici peut être supprimé définitivement ; les
      pages de la plateforme et des plugins, elles, ne font que se masquer. Chaque boutique n’affiche que les entrées ouvertes à ses rôles et
      les pages de ses plugins actifs ; une entrée absente y est simplement ignorée.</p>
    <p class="menu-status">
      <span class="menu-tag<?= $personalized ? ' ok' : '' ?>"><?= $personalized ? 'Classement personnalisé' : 'Valeurs de départ' ?></span>
      <span class="menu-count"><?= (int) $itemCount ?> entrées · <?= (int) $hiddenCount ?> masquée<?= $hiddenCount > 1 ? 's' : '' ?> · <?= count($shops) ?> boutique(s)</span>
    </p>

    <form method="post" action="/portail/menu-action.php" id="menu-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">

      <div class="menu-editor" id="menu-groups">
        <?php foreach ($layout as $gi => $groupRow): ?>
          <section class="menu-group" data-group>
            <div class="menu-group-head">
              <span class="menu-grip" aria-hidden="true">⋮⋮</span>
              <input type="hidden" data-group-key name="groups[<?= $gi ?>][key]" value="<?= e($groupRow['key']) ?>">
              <input type="text" data-group-label name="groups[<?= $gi ?>][label]" value="<?= e($groupRow['label']) ?>" maxlength="40" aria-label="Nom de la catégorie" required>
              <span class="menu-count"><?= count($groupRow['items']) ?> entrée<?= count($groupRow['items']) > 1 ? 's' : '' ?></span>
              <span class="menu-actions" style="margin:0;">
                <button type="button" class="btn small" data-group-up aria-label="Monter la catégorie <?= e($groupRow['label']) ?>" title="Monter">↑</button>
                <button type="button" class="btn small" data-group-down aria-label="Descendre la catégorie <?= e($groupRow['label']) ?>" title="Descendre">↓</button>
                <button type="button" class="btn small" data-group-remove aria-label="Supprimer la catégorie <?= e($groupRow['label']) ?>">Supprimer</button>
              </span>
            </div>
            <ul class="menu-items">
              <?php foreach ($groupRow['items'] as $key => [$href, $label, $icon]): ?>
                <?php
                  $pluginId = str_starts_with((string) $key, 'plugin:') ? explode(':', (string) $key)[1] : '';
                  $pluginName = $pluginId !== '' ? (string) ($plugins[$pluginId]['name'] ?? $pluginId) : '';
                  $isHidden = isset($hiddenKeys[$key]);
                ?>
                <li class="menu-item<?= $isHidden ? ' is-hidden' : '' ?>" data-item>
                  <span class="menu-grip" aria-hidden="true">⋮⋮</span>
                  <span class="menu-item-icon" aria-hidden="true"><?= admin_menu_icon_svg((string) $icon) ?></span>
                  <span class="menu-item-label"><?= e($label) ?>
                    <small><?= $pluginName !== ''
                      ? 'Plugin ' . e($pluginName) . ' · actif dans ' . (int) ($activity[$pluginId] ?? 0) . ' boutique(s)'
                      : e($href) ?></small>
                  </span>
                  <label class="menu-hide" title="Masquer cette entrée dans toutes les boutiques">
                    <input type="checkbox" data-item-hide name="groups[<?= $gi ?>][hidden][]" value="<?= e($key) ?>"<?= $isHidden ? ' checked' : '' ?>>
                    <span>Masqué</span>
                  </label>
                  <select data-item-move aria-label="Catégorie de <?= e($label) ?>">
                    <?php foreach ($layout as $g2i => $g2): ?>
                      <option value="<?= $g2i ?>"<?= $g2['key'] === $groupRow['key'] ? ' selected' : '' ?>><?= e($g2['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn small" data-item-up aria-label="Monter <?= e($label) ?>" title="Monter">↑</button>
                  <button type="button" class="btn small" data-item-down aria-label="Descendre <?= e($label) ?>" title="Descendre">↓</button>
                  <?php if (isset($customKeys[$key])): ?>
                    <button type="submit" form="menu-delete-form" name="key" value="<?= e($key) ?>" class="btn small menu-delete"
                      onclick="return confirm(<?= e(json_encode('Supprimer définitivement l’item « ' . $label . ' » pour toutes les boutiques ?', JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"
                      aria-label="Supprimer <?= e($label) ?>">Supprimer</button>
                  <?php endif; ?>
                  <input type="hidden" data-item-key name="groups[<?= $gi ?>][items][]" value="<?= e($key) ?>">
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="menu-empty"<?= $groupRow['items'] ? ' hidden' : '' ?>>Catégorie vide — elle ne s’affiche pas dans la barre tant qu’elle n’a pas d’entrée.</p>
          </section>
        <?php endforeach; ?>
      </div>

      <div class="menu-actions">
        <button type="submit" class="btn btn-primary">Enregistrer pour toutes les boutiques</button>
        <button type="button" class="btn" id="menu-add-group">Ajouter une catégorie</button>
      </div>
    </form>

    <form method="post" action="/portail/menu-action.php" id="menu-delete-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_custom">
    </form>

    <form method="post" action="/portail/menu-action.php" class="menu-create">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <h2>Créer un item</h2>
      <p>Un lien vers une page interne (site ou administration), ajouté à la fin de la catégorie choisie. Il apparaît dans
        toutes les boutiques où le rôle y a accès, puis se range comme les autres entrées. Seuls les items créés ici peuvent être supprimés.</p>
      <div class="menu-create-grid">
        <label>Libellé<input type="text" name="label" maxlength="40" required placeholder="Ex. Nos ateliers"></label>
        <label>Adresse<input type="text" name="href" maxlength="200" required placeholder="/admin/ma-page.php" spellcheck="false"></label>
        <label>Catégorie
          <select name="group">
            <?php foreach ($layout as $groupRow): ?>
              <option value="<?= e($groupRow['key']) ?>"><?= e($groupRow['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Icône
          <select name="icon">
            <?php foreach ($iconChoices as $iconKey => $iconLabel): ?>
              <option value="<?= e($iconKey) ?>"<?= $iconKey === 'grid' ? ' selected' : '' ?>><?= e($iconLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn">Créer l’item</button>
      </div>
    </form>

    <?php if ($personalized): ?>
      <form method="post" action="/portail/menu-action.php" style="margin-top:12px;" onsubmit="return confirm('Rétablir les catégories et l’ordre de départ pour toutes les boutiques ? Les entrées masquées et les items créés seront perdus.');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="btn small">Rétablir le menu de départ</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<template id="menu-group-template">
  <section class="menu-group" data-group>
    <div class="menu-group-head">
      <span class="menu-grip" aria-hidden="true">⋮⋮</span>
      <input type="hidden" data-group-key value="">
      <input type="text" data-group-label value="" maxlength="40" placeholder="Nom de la catégorie" aria-label="Nom de la catégorie" required>
      <span class="menu-count">0 entrée</span>
      <span class="menu-actions" style="margin:0;">
        <button type="button" class="btn small" data-group-up aria-label="Monter la catégorie" title="Monter">↑</button>
        <button type="button" class="btn small" data-group-down aria-label="Descendre la catégorie" title="Descendre">↓</button>
        <button type="button" class="btn small" data-group-remove aria-label="Supprimer la catégorie">Supprimer</button>
      </span>
    </div>
    <ul class="menu-items"></ul>
    <p class="menu-empty">Catégorie vide — elle ne s’affiche pas dans la barre tant qu’elle n’a pas d’entrée.</p>
  </section>
</template>

<script>
(function () {
  var list = document.getElementById('menu-groups');
  var form = document.getElementById('menu-form');
  var addBtn = document.getElementById('menu-add-group');
  var template = document.getElementById('menu-group-template');

  function groups() {
    return Array.prototype.filter.call(list.children, function (el) { return el.classList.contains('menu-group'); });
  }

  function updateCounts() {
    groups().forEach(function (group) {
      var count = group.querySelectorAll('.menu-item').length;
      var badge = group.querySelector('.menu-count');
      if (badge) badge.textContent = count + ' entrée' + (count > 1 ? 's' : '');
      var empty = group.querySelector('.menu-empty');
      if (empty) empty.hidden = count > 0;
    });
  }

  // Les champs sont nommés groups[0], groups[1]… : on renumérote après chaque
  // déplacement pour que l'ordre du formulaire soit celui de l'écran.
  function rebuildMoves() {
    var all = groups();
    all.forEach(function (group) {
      group.querySelectorAll('.menu-item').forEach(function (item) {
        var select = item.querySelector('[data-item-move]');
        if (!select) return;
        var current = all.indexOf(item.closest('.menu-group'));
        select.innerHTML = '';
        all.forEach(function (target, index) {
          var label = target.querySelector('[data-group-label]');
          var option = document.createElement('option');
          option.value = String(index);
          option.textContent = (label && label.value.trim()) || 'Catégorie ' + (index + 1);
          if (index === current) option.selected = true;
          select.appendChild(option);
        });
      });
    });
  }

  function renumber() {
    groups().forEach(function (group, index) {
      var key = group.querySelector('[data-group-key]');
      if (key) key.name = 'groups[' + index + '][key]';
      var label = group.querySelector('[data-group-label]');
      if (label) label.name = 'groups[' + index + '][label]';
      group.querySelectorAll('[data-item-key]').forEach(function (input) {
        input.name = 'groups[' + index + '][items][]';
      });
      group.querySelectorAll('[data-item-hide]').forEach(function (input) {
        input.name = 'groups[' + index + '][hidden][]';
      });
    });
    rebuildMoves();
    updateCounts();
  }

  function moveWithin(item, direction) {
    var sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling || !sibling.classList.contains('menu-item')) return;
    item.parentElement.insertBefore(item, direction < 0 ? sibling : sibling.nextElementSibling);
    renumber();
  }

  function moveGroup(group, direction) {
    var sibling = direction < 0 ? group.previousElementSibling : group.nextElementSibling;
    if (!sibling || !sibling.classList.contains('menu-group')) return;
    list.insertBefore(group, direction < 0 ? sibling : sibling.nextElementSibling);
    renumber();
  }

  list.addEventListener('click', function (event) {
    var item = event.target.closest('.menu-item');
    var group = event.target.closest('.menu-group');
    if (!group) return;
    if (event.target.closest('[data-group-up]')) return moveGroup(group, -1);
    if (event.target.closest('[data-group-down]')) return moveGroup(group, 1);
    if (event.target.closest('[data-group-remove]')) {
      if (groups().length < 2) return alert('Gardez au moins une catégorie.');
      if (!confirm('Supprimer la catégorie « ' + (group.querySelector('[data-group-label]').value || 'sans nom') + ' » ? Ses entrées passent dans la première catégorie.')) return;
      var target = groups().filter(function (g) { return g !== group; })[0];
      var targetList = target.querySelector('.menu-items');
      group.querySelectorAll('.menu-item').forEach(function (row) { targetList.appendChild(row); });
      group.remove();
      return renumber();
    }
    if (item && event.target.closest('[data-item-up]')) return moveWithin(item, -1);
    if (item && event.target.closest('[data-item-down]')) return moveWithin(item, 1);
  });

  list.addEventListener('change', function (event) {
    var hide = event.target.closest('[data-item-hide]');
    if (hide) {
      var row = hide.closest('.menu-item');
      if (row) row.classList.toggle('is-hidden', hide.checked);
      return;
    }
    var select = event.target.closest('[data-item-move]');
    if (!select) return;
    var item = select.closest('.menu-item');
    var target = groups()[Number(select.value)];
    if (!item || !target) return;
    target.querySelector('.menu-items').appendChild(item);
    renumber();
  });

  addBtn.addEventListener('click', function () {
    var group = template.content.firstElementChild.cloneNode(true);
    list.appendChild(group);
    renumber();
    group.querySelector('[data-group-label]').focus();
  });

  form.addEventListener('submit', function (event) {
    var missing = groups().filter(function (group) {
      var input = group.querySelector('[data-group-label]');
      return !input || !input.value.trim();
    })[0];
    if (missing) {
      event.preventDefault();
      missing.querySelector('[data-group-label]').focus();
      alert('Chaque catégorie doit avoir un nom.');
      return;
    }
    renumber();
  });
})();
</script>
</body>
</html>
