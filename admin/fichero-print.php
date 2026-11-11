<?php
// Jobs d'impression Fichero D11s pour les pièces cochées : bitmap QR (paiement
// si la pièce peut être réglée en ligne, sinon fiche produit).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/shop-qr.php';
require_once __DIR__ . '/../includes/fichero.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!admin_access_ok()) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.']));
}

$refs = array_values(array_unique(array_filter(array_map(
    static fn ($r) => preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $r)),
    explode(',', (string) ($_GET['refs'] ?? ''))
))));
$copies = max(1, min(20, (int) ($_GET['copies'] ?? 1)));

if (!$refs) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Aucune pièce à imprimer.']));
}
if (count($refs) > 50) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Choisissez au maximum 50 pièces à la fois.']));
}

$in = implode(',', array_fill(0, count($refs), '?'));
$st = db()->prepare("SELECT * FROM products WHERE ref IN ($in)");
$st->execute($refs);
$byRef = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $byRef[$p['ref']] = $p;
}

$jobs = [];
foreach ($refs as $ref) {
    if (!isset($byRef[$ref])) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'Une pièce sélectionnée est introuvable. Actualisez la sélection.']));
    }
    $jobs[] = fichero_job_for_product($byRef[$ref]);
}

echo json_encode([
    'ok' => true,
    'copies' => $copies,
    'jobs' => $jobs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
