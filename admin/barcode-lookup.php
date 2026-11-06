<?php
// Fiche d'un article à partir d'un code-barres / ISBN / code QR (voir includes/barcode.php et assets/barcode-scan.js).
// POST code=… → JSON {ok, code, kind, label, name, description, nature, sous_categorie, materials, size_text, weight_grams, cover, sources, facts, existing}.
// « existing » : la pièce du catalogue qui porte déjà ce code, s'il y en a une (pour ne pas la créer en double).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/barcode.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!admin_access_ok()) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']));
}

$res = barcode_lookup((string) ($_POST['code'] ?? ''));
$res['existing'] = null;
if (($res['code'] ?? '') !== '') {
    $stmt = db()->prepare('SELECT ref, name, stock FROM products WHERE barcode = ? ORDER BY ref LIMIT 1');
    $stmt->execute([$res['code']]);
    if ($row = $stmt->fetch()) $res['existing'] = ['ref' => $row['ref'], 'name' => $row['name'], 'stock' => (int) $row['stock']];
}
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
