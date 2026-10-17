<?php
// POST {path} : crée la version web d'une image. POST {batch: true} : crée celles qui manquent, 8 par appel (le navigateur
// rappelle tant qu'il en reste), pour ne pas dépasser le temps d'exécution d'un hébergement mutualisé.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$in = plugin_input();
if (!empty($in['batch'])) {
    $todo = array_filter(com_items(0), static fn (array $i): bool => $i['type'] === 'photo' && !$i['web_url'] && !$i['missing']);
    $done = 0;
    $failed = [];
    foreach (array_slice($todo, 0, 8) as $item) {
        if (com_make_web($item['path'])) $done++; else $failed[] = $item['path'];
    }
    // Les images impossibles à traiter (trop grandes) ne sont pas recomptées indéfiniment.
    foreach ($failed as $path) com_meta_save($path, ['web_path' => $path]);
    plugin_json(['ok' => true, 'done' => $done, 'failed' => count($failed), 'remaining' => max(0, count($todo) - $done - count($failed))]);
}
$item = com_find_item((string) ($in['path'] ?? ''));
if (!$item || $item['type'] !== 'photo') plugin_json(['error' => 'Image introuvable dans la médiathèque.'], 404);
$web = com_make_web($item['path']);
if (!$web) plugin_json(['error' => 'Version web impossible pour cette image (format ou taille).'], 422);
plugin_json(['ok' => true, 'item' => com_find_item($item['path'])]);
