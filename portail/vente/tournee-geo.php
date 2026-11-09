<?php
// Situe un commerce sur la carte de la tournée : géocode son adresse (IGN) et enregistre les coordonnées.
// Appelé en JSON, un commerce à la fois, par assets/tournee-carte.js. Réponse : {ok, lat, lng} ou {ok:false}.
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !hash_equals(fv_csrf(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Requête expirée : rechargez la page.']);
    exit;
}

$p = fv_prospect_get((int) ($_POST['id'] ?? 0));
if (!$p) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Commerce introuvable.']);
    exit;
}
if (fv_geo_has($p)) {
    echo json_encode(['ok' => true, 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']]);
    exit;
}

$geo = fv_geocode(fv_prospect_address_line($p), (string) $p['postal']);
if ($geo === null) {
    // Adresse introuvable ou service injoignable : rien n'est enregistré, une prochaine demande réessaie.
    echo json_encode(['ok' => false, 'error' => 'Adresse introuvable.']);
    exit;
}
fv_prospect_set_geo((int) $p['id'], $geo['lat'], $geo['lng']);
echo json_encode(['ok' => true, 'lat' => $geo['lat'], 'lng' => $geo['lng']]);
