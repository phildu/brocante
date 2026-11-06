<?php
/**
 * Point d'entrée JSON du comptage : le téléphone y envoie ses appuis en
 * attente et en reçoit les totaux à jour (ce qui fait aussi office de
 * rafraîchissement). POST uniquement, jeton CSRF en en-tête X-CSRF-Token.
 */
require_once __DIR__ . '/../includes/comptage.php';
require_once __DIR__ . '/../includes/session.php';
cp_public_enter();   // galerie d'après l'adresse (/galerie/<galerie>/comptage/…), extension active
cp_url_map_off();    // réponse JSON : rien à réécrire

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cp_json(int $status, array $body)
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cp_json(405, ['error' => 'method']);
if (!cp_is_volunteer()) cp_json(401, ['error' => 'auth']);

$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || !hash_equals(shop_csrf_token(), $token)) cp_json(403, ['error' => 'csrf']);
// Libère le verrou de session : plusieurs téléphones / onglets peuvent synchroniser sans s'attendre.
session_write_close();

cp_boot();

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 512 * 1024) cp_json(413, ['error' => 'too_large']);
$in = json_decode($raw, true);
if (!is_array($in)) cp_json(400, ['error' => 'json']);

$eventId = (int) ($in['event'] ?? 0);
$event = cp_event_get($eventId);
if (!$event) cp_json(404, ['error' => 'event']);

$ops = isset($in['ops']) && is_array($in['ops']) ? $in['ops'] : [];
$result = $ops ? cp_record($eventId, $ops) : ['applied' => 0, 'undone' => 0, 'rejected' => 0];

$totals = cp_totals($eventId);
$active = cp_active_event();
cp_json(200, [
    'ok' => true,
    'result' => $result,
    'totals' => $totals,
    'active_event' => $active ? (int) $active['id'] : null,
    'config' => cp_config_hash($event, $totals['gates']),
    'server_time' => time(),
]);
