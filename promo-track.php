<?php
// Affichage d'un visuel promo (compté une fois par visite, quand il est réellement visible : assets/promo-track.js). POST JSON {shop, ref, placement, token}.
// Sert la page d'accueil des boutiques et celle des galeries commerciales ; le jeton prouve que l'emplacement a été servi par la plateforme.
require_once __DIR__ . '/includes/promo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }
$in = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($in) ? $in : $_POST;
$shop = (string) ($in['shop'] ?? '');
$ref = (string) ($in['ref'] ?? '');
$placement = (string) ($in['placement'] ?? '');
$ok = false;
if (!promo_is_bot() && promo_token_valid($shop, $ref, $placement, (string) ($in['token'] ?? '')) && promo_rate_ok()) {
    $ok = promo_record($shop, $ref, $placement, 1, 0);
}
echo json_encode(['ok' => $ok]);
