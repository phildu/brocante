<?php
// Routes publiques de l'espace visiteur : /api/visiteur/lien (POST), /moi (GET), /recompense (POST), /supprimer (POST).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';

if (!addon_is_entitled(EV_MODULE)) plugin_json(['error' => "L'espace visiteur n'est pas disponible pour le moment."], 503);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : $_POST;
}
$member = static function () use ($in): array {
    $m = ev_member_by_token((string) ($_GET['t'] ?? $in['t'] ?? ''));
    if (!$m) plugin_json(['error' => 'Lien invalide ou expiré : demandez un nouveau lien.'], 401);
    return $m;
};

if ($agentPath === '/visiteur/lien' && $method === 'POST') {
    if (!empty($in['website'])) plugin_json(['ok' => true, 'message' => 'Un lien vient de vous être envoyé.']);   // champ piège rempli par un robot
    [$msg, $err] = ev_request_link((string) ($in['email'] ?? ''), (string) ($in['name'] ?? ''), !empty($in['consent']), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'message' => $msg]);
}
if ($agentPath === '/visiteur/moi' && $method === 'GET') {
    $m = $member();
    ev_touch($m);
    plugin_json(['ok' => true] + ev_member_view(ev_member_by_token($m['token'])));
}
if ($agentPath === '/visiteur/recompense' && $method === 'POST') {
    $m = $member();
    if ((int) $m['welcomed'] === 0) ev_touch($m);
    [$r, $err] = ev_reward_create($m, (int) ($in['tier'] ?? -1));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'reward' => $r]);
}
if ($agentPath === '/visiteur/supprimer' && $method === 'POST') {
    $m = $member();
    ev_member_delete((int) $m['id']);
    plugin_json(['ok' => true, 'message' => 'Votre espace et vos points ont été effacés.']);
}
plugin_json(['error' => 'Adresse inconnue.'], 404);
