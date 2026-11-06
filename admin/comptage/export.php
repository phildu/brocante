<?php
require_once __DIR__ . '/../../includes/comptage.php';
require_once __DIR__ . '/../../includes/session.php';
require_admin();
require_once __DIR__ . '/../../includes/shop-modules.php';
shop_module_require('comptage-foule');
cp_boot();

$event = cp_event_get((int) ($_GET['event'] ?? 0));
if (!$event) {
    http_response_code(404);
    exit('Événement introuvable.');
}

$what = ($_GET['what'] ?? 'timeline') === 'taps' ? 'taps' : 'timeline';
$bucket = (int) ($_GET['bucket'] ?? 30);
if (!in_array($bucket, [15, 30, 60], true)) $bucket = 30;

$slug = slugify($event['name'], 40) ?: 'evenement';
$file = 'comptage-' . $slug . ($event['event_date'] ? '-' . $event['event_date'] : '') . '-' . ($what === 'taps' ? 'detail' : 'courbe-' . $bucket . 'min') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: no-store');
echo $what === 'taps' ? cp_export_taps((int) $event['id']) : cp_export_timeline((int) $event['id'], $bucket);
