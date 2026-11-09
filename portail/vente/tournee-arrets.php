<?php
// Liste des commerces proposés dans la modale « Choisir les arrêts » de la page Tournées (JSON, périmètre du membre connecté).
// Appelé une fois à l'ouverture de la modale par assets/tournee-arrets.js ; tri et filtres se font ensuite dans le navigateur.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_tournee.php';
fv_require_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$ctx = fv_current();
$rows = array_map('fv_tournee_row', fv_tournee_candidates());

$owners = [];
if ($ctx['manager']) {
    foreach (fv_members_list(false) as $m) $owners[] = ['id' => (int) $m['id'], 'name' => (string) $m['name']];
}

if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
echo json_encode([
    'rows' => $rows,
    'statuses' => fv_statuses(),
    'owners' => $owners,
    'today' => fv_today(),
    'week' => fv_days_from(7),
    'now' => fv_hours_now(),
    'max' => FV_TOURNEE_MAX_IDS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
