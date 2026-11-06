<?php
// Suivi des appels à l'action du site public : affichage (bouton visible) ou clic, compté par emplacement et variante.
// POST JSON {slot, variant, token, click}. Le jeton prouve que le CTA a été servi par la plateforme ; les robots sont exclus.
require_once __DIR__ . '/includes/cta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }
$in = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($in) ? $in : $_POST;
$slot = (string) ($in['slot'] ?? '');
$variant = (string) ($in['variant'] ?? '');
$click = !empty($in['click']);
$ok = false;
if (!cta_is_bot() && cta_token_valid($slot, $variant, (string) ($in['token'] ?? '')) && cta_rate_ok()) {
    $ok = cta_record($slot, $variant, $click ? 0 : 1, $click ? 1 : 0);
}
echo json_encode(['ok' => $ok]);
