<?php
// POST {path} : s'assure qu'un élément est inscrit dans media_library (une photo de fiche ou un fichier envoyé par la médiathèque
// d'electroboy80 ne l'est pas). Les pages de la plateforme (hero, diaporama, bandeaux, réglages) n'acceptent que ces ressources :
// le sélecteur l'appelle avant d'y appliquer un choix. Accepte un chemin (« uploads/… ») ou une adresse (« /uploads/…?v=… ») ;
// une version web (« …_web.jpg ») désigne son original.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$path = ltrim((string) parse_url((string) (plugin_input()['path'] ?? ''), PHP_URL_PATH), '/');
$items = com_items(0);
$item = $items[$path] ?? null;
if (!$item && preg_match('/_web\.jpg$/i', $path)) {
    foreach ($items as $candidate) {
        if ($candidate['web_url'] === '/' . $path) { $item = $candidate; break; }
    }
}
if (!$item) plugin_json(['error' => 'Élément introuvable dans la médiathèque.'], 404);
if (!$item['media_id']) {
    add_media_item($item['type'], $item['path'], $item['title'] !== '' ? $item['title'] : 'Photo de fiche', $item['fiche'] ? 'Photo de fiche' : 'Médiathèque',
        $item['tags'], $item['fiche']['ref'] ?? null, $item['fiche']['name'] ?? null);
}
plugin_json(['ok' => true, 'item' => com_find_item($item['path'])]);
