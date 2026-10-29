<?php
// Enregistrement du menu latéral commun aux boutiques (portail/menu.php) :
// classement, catégories, entrées masquées et items créés depuis le portail.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/admin-menu.php';
require_once __DIR__ . '/_menu.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portail/menu.php');
    exit;
}
csrf_check();

$action = (string) ($_POST['action'] ?? '');

function menu_back(string $message, string $kind = 'ok'): void
{
    portail_flash($message, $kind);
    header('Location: /portail/menu.php');
    exit;
}

if ($action === 'reset') {
    try {
        admin_menu_global_reset();
        portail_flash('Menu latéral rétabli avec les catégories et l’ordre de départ, pour toutes les boutiques.');
    } catch (Throwable $e) {
        portail_flash($e->getMessage(), 'error');
    }
    header('Location: /portail/menu.php');
    exit;
}

$config = admin_menu_config();
$available = portail_menu_catalog();

// Suppression d'un item créé dans le portail (les autres se masquent).
if ($action === 'delete_custom') {
    $key = (string) ($_POST['key'] ?? '');
    if (!isset($config['custom'][$key])) {
        menu_back('Item introuvable : il a peut-être déjà été supprimé.', 'error');
    }
    $custom = $config['custom'];
    unset($custom[$key]);
    $hidden = array_values(array_diff($config['hidden'], [$key]));
    // La configuration lue est une carte clé => groupe : on la remet en lignes.
    $groups = [];
    foreach ($config['groups'] as $groupKey => $groupLabel) {
        $groups[] = ['key' => (string) $groupKey, 'label' => (string) $groupLabel];
    }
    $items = [];
    foreach ($config['items'] as $itemKey => $itemGroup) {
        if ((string) $itemKey !== $key) $items[] = ['key' => (string) $itemKey, 'group' => (string) $itemGroup];
    }
    if (!$groups) {
        menu_back('Enregistrez d’abord le classement du menu, puis supprimez l’item.', 'error');
    }
    try {
        admin_menu_global_save($groups, $items, $hidden, $custom);
        portail_flash('Item supprimé pour toutes les boutiques.');
    } catch (Throwable $e) {
        portail_flash($e->getMessage(), 'error');
    }
    header('Location: /portail/menu.php');
    exit;
}

// Création d'un item : il rejoint la fin de la catégorie choisie.
if ($action === 'create') {
    $label = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['label'] ?? '')));
    $href = trim((string) ($_POST['href'] ?? ''));
    $icon = (string) ($_POST['icon'] ?? '');
    $group = (string) ($_POST['group'] ?? '');

    if ($label === '') menu_back('Donnez un libellé à l’item.', 'error');
    $label = mb_substr($label, 0, 40);
    if (!admin_menu_href_ok($href)) {
        menu_back('Adresse invalide : indiquez une page interne du site, par exemple /admin/ma-page.php.', 'error');
    }
    if (!isset(portail_menu_icon_choices()[$icon])) $icon = 'grid';

    $layout = admin_menu_layout($available);
    $groupKeys = array_column($layout, 'key');
    if (!in_array($group, $groupKeys, true)) $group = (string) ($groupKeys[0] ?? '');
    if ($group === '') menu_back('Créez d’abord une catégorie.', 'error');

    // Nouvel item placé après le dernier item de la catégorie choisie.
    $key = admin_menu_custom_key($label, array_keys($available));
    $entries = [];
    $insertAt = null;
    $groups = [];
    foreach ($layout as $groupRow) {
        $groups[] = ['key' => $groupRow['key'], 'label' => $groupRow['label']];
        foreach ($groupRow['items'] as $itemKey => $unused) {
            $entries[] = ['key' => (string) $itemKey, 'group' => (string) $groupRow['key']];
        }
        if ($groupRow['key'] === $group) $insertAt = count($entries);
    }
    array_splice($entries, $insertAt ?? count($entries), 0, [['key' => $key, 'group' => $group]]);

    $custom = $config['custom'];
    $custom[$key] = ['label' => $label, 'href' => $href, 'icon' => $icon];
    try {
        admin_menu_global_save($groups, $entries, $config['hidden'], $custom);
        portail_flash('Item « ' . $label . ' » créé pour toutes les boutiques.');
    } catch (Throwable $e) {
        portail_flash($e->getMessage(), 'error');
    }
    header('Location: /portail/menu.php');
    exit;
}

if ($action !== 'save') {
    menu_back('Action inconnue.', 'error');
}

$posted = $_POST['groups'] ?? [];
if (!is_array($posted)) $posted = [];
uksort($posted, static fn ($a, $b): int => (int) $a <=> (int) $b);

$groups = [];
$items = [];
$hidden = [];
$usedKeys = [];
$usedLabels = [];
$assigned = [];
foreach ($posted as $row) {
    if (!is_array($row)) continue;
    $label = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['label'] ?? '')));
    if ($label === '') {
        menu_back('Chaque catégorie doit avoir un nom.', 'error');
    }
    $label = mb_substr($label, 0, 40);
    $base = $label;
    for ($n = 2; in_array(mb_strtolower($label), $usedLabels, true); $n++) $label = $base . ' ' . $n;
    $usedLabels[] = mb_strtolower($label);

    $key = (string) ($row['key'] ?? '');
    if (!preg_match('/^[a-z0-9_-]{1,40}$/', $key) || in_array($key, $usedKeys, true)) {
        $key = admin_menu_group_key($label, $usedKeys);
    }
    $usedKeys[] = $key;
    $groups[] = ['key' => $key, 'label' => $label];

    $rowHidden = $row['hidden'] ?? [];
    if (!is_array($rowHidden)) $rowHidden = [$rowHidden];
    foreach ($rowHidden as $hiddenKey) {
        $hiddenKey = (string) $hiddenKey;
        if (isset($available[$hiddenKey])) $hidden[$hiddenKey] = true;
    }

    $rowItems = $row['items'] ?? [];
    if (!is_array($rowItems)) $rowItems = [];
    foreach ($rowItems as $itemKey) {
        $itemKey = (string) $itemKey;
        if (!isset($available[$itemKey]) || isset($assigned[$itemKey])) continue;
        $assigned[$itemKey] = true;
        $items[] = ['key' => $itemKey, 'group' => $key];
    }
}

if (!$groups) {
    menu_back('Le menu doit garder au moins une catégorie.', 'error');
}

try {
    admin_menu_global_save($groups, $items, array_keys($hidden), $config['custom']);
    portail_flash('Menu latéral enregistré : le classement et les entrées masquées s’appliquent à toutes les boutiques.');
} catch (Throwable $e) {
    portail_flash($e->getMessage(), 'error');
}
header('Location: /portail/menu.php');
