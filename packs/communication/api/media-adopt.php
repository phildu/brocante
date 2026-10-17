<?php
// POST {path} : s'assure qu'un élément est inscrit dans media_library (une photo de fiche ne l'est pas). Les pages de la
// plateforme (hero, diaporama, bandeaux, réglages) n'acceptent que ces ressources : le sélecteur commun l'appelle avant d'y
// appliquer un choix.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$item = com_find_item((string) (plugin_input()['path'] ?? ''));
if (!$item) plugin_json(['error' => 'Élément introuvable dans la médiathèque.'], 404);
if (!$item['media_id']) {
    add_media_item($item['type'], $item['path'], $item['title'] !== '' ? $item['title'] : 'Photo de fiche', 'Photo de fiche',
        $item['tags'], $item['fiche']['ref'] ?? null, $item['fiche']['name'] ?? null);
}
plugin_json(['ok' => true, 'item' => com_find_item($item['path'])]);
