<?php
/**
 * Synchronise les boutiques connectées depuis la ligne de commande — à planifier
 * (cron OVH, par exemple toutes les nuits) pour garder prix et stocks à jour
 * sans passer par l'administration :
 *
 *   php scripts/connectors-sync.php          # toutes les boutiques actives
 *   php scripts/connectors-sync.php 3        # uniquement la boutique n°3
 *
 * Code de sortie : 0 si tout s'est bien passé, 1 sinon.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/../includes/connectors.php';

$only = isset($argv[1]) ? (int) $argv[1] : 0;
$exit = 0;
$found = false;
foreach (connector_source_list() as $source) {
    if (!connector_platforms()[$source['platform']]['pull'] || !$source['is_active']) continue;
    if ($only && (int) $source['id'] !== $only) continue;
    $found = true;

    echo "[{$source['name']}] synchronisation…\n";
    $r = connector_sync_all_pages($source, fn($page) => print("  lot : {$page['created']} créé(s), {$page['updated']} mis à jour, {$page['failed']} en échec\n"));
    echo "  terminé : {$r['created']} créé(s), {$r['updated']} mis à jour, {$r['retired']} retiré(s), {$r['skipped']} ignoré(s), {$r['failed']} en échec\n";
    foreach (array_slice($r['errors'], 0, 10) as $e) echo "  ! $e\n";
    if (!$r['complete'] || $r['failed']) $exit = 1;
}
if (!$found) echo "Aucune boutique à synchroniser.\n";
exit($exit);
