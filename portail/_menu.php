<?php
// Menu latéral commun aux boutiques : catalogue des entrées (plateforme, pages
// de plugins et items créés dans le portail) et nombre de boutiques où chaque
// plugin est actif. Utilisé par portail/menu.php et portail/menu-action.php.

require_once __DIR__ . '/../includes/admin-menu.php';
require_once __DIR__ . '/../includes/plugins.php';

/** Catalogue proposé au classement : clé => [adresse, libellé, icône, entrée remplacée]. */
function portail_menu_catalog(): array
{
    $items = admin_menu_core_items();
    foreach (plugin_catalog() as $id => $m) {
        foreach ($m['pages'] as $page) {
            if (!$page['menu']) continue;
            $items['plugin:' . $id . ':' . $page['id']] = [
                plugin_page_url($id, $page['id']),
                $page['label'],
                $page['icon'],
                $page['replaces'],
            ];
        }
    }
    foreach (admin_menu_config()['custom'] as $key => $row) {
        $items[$key] = [$row['href'], $row['label'], $row['icon']];
    }
    return $items;
}

/** Icônes proposées pour un item créé, avec un libellé lisible. */
function portail_menu_icon_choices(): array
{
    return [
        'grid' => 'Grille',
        'gauge' => 'Tableau de bord',
        'receipt' => 'Reçu / liste',
        'folder' => 'Dossier',
        'truck' => 'Livraison',
        'camera' => 'Appareil photo',
        'image' => 'Image',
        'play' => 'Vidéo',
        'banner' => 'Bandeau',
        'upload' => 'Import',
        'palette' => 'Palette',
        'user' => 'Personne',
        'users' => 'Équipe',
        'gear' => 'Réglage',
        'cloud' => 'Nuage',
        'puzzle' => 'Extension',
    ];
}

/** Nombre de boutiques où chaque plugin est actif (activé et à jour), par identifiant. */
function portail_menu_plugin_activity(array $shops): array
{
    $catalog = plugin_catalog();
    $active = array_fill_keys(array_keys($catalog), 0);
    foreach ($shops as $slug => $shop) {
        try {
            [$pdo] = plugin_open_tenant_db($slug);
            foreach (plugin_states($pdo) as $id => $row) {
                $m = $catalog[$id] ?? null;
                if ($m && in_array(plugin_status($row, $m), ['ready', 'update', 'newer'], true)) {
                    $active[$id] = ($active[$id] ?? 0) + 1;
                }
            }
        } catch (Throwable $e) {
            // Base pas encore créée : aucun plugin actif à compter.
        }
    }
    return $active;
}
