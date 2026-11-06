<?php
// Versions smartphone (9:16) des visuels générés, produites une par une par
// des requêtes séparées (voir queue_mobile_variant() et le script de
// includes/admin-nav.php) : aucune requête n'enchaîne deux générations IA,
// donc pas de dépassement du délai du serveur (erreur 504).
//   list → identifiants des visuels en attente
//   run  → génère la version smartphone d'un visuel

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!admin_access_ok()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expirée.']);
    exit;
}
session_write_close(); // ne bloque pas la navigation pendant la génération
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
if ($action === 'list') {
    echo json_encode(['ok' => true, 'ids' => pending_mobile_variant_ids()]);
    exit;
}
if ($action === 'run') {
    @set_time_limit(90);
    echo json_encode(run_mobile_variant((int) ($_POST['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
