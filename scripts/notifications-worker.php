<?php
// Vide la file d'envoi des notifications (reprises, envois différés, gros envois)
// et purge l'historique ancien. À lancer par cron, toutes les 5 minutes :
//   */5 * * * * php /chemin/vers/scripts/notifications-worker.php --quiet
//
// Options : --limit=200 (envois max par passage) · --budget=50 (secondes max)
//           --purge-days=90 (0 = ne pas purger) · --quiet (aucune sortie si rien à signaler)
// Deux passages simultanés ne se gênent pas : le second s'arrête aussitôt.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/../includes/notifications.php';

$o = getopt('', ['limit::', 'budget::', 'purge-days::', 'quiet']);
$limit = max(1, min(500, (int) ($o['limit'] ?? 200)));
$budget = max(5.0, min(280.0, (float) ($o['budget'] ?? 50)));
$purgeDays = (int) ($o['purge-days'] ?? 90);
$quiet = array_key_exists('quiet', $o);

$lock = fopen(sys_get_temp_dir() . '/brocante-notifications-' . md5(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) echo "Un autre passage est déjà en cours.\n";
    exit(0);
}

nt_boot();
$stats = nt_process_queue($limit, $budget);
nt_meta_set('worker_last_run', (string) time());
$purged = $purgeDays > 0 ? nt_purge($purgeDays) : 0;

if (!$quiet || $stats['sent'] || $stats['failed'] || $stats['retry'] || $purged) {
    printf("[%s] envoyés : %d · échecs : %d · à réessayer : %d · ignorés : %d · en attente de rang : %d · purgés : %d\n",
        nt_fmt(time()), $stats['sent'], $stats['failed'], $stats['retry'], $stats['skipped'], $stats['deferred'], $purged);
}
// Code 0 même s'il y a des échecs de livraison : c'est un fonctionnement normal (voir le tableau de bord).
exit(0);
