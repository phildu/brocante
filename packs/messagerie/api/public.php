<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ms.php';

if (!addon_is_entitled(MS_MODULE)) plugin_json(['error' => "La messagerie n'est pas disponible pour le moment."], 503);
if ($agentPath === '/messages/envoyer' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : $_POST;
    if (!empty($in['website'])) plugin_json(['ok' => true, 'message' => 'Merci ! Votre message est bien parti : nous vous répondons par e-mail.']);   // champ piège
    [$msg, $err] = ms_receive((string) ($in['name'] ?? ''), (string) ($in['email'] ?? ''), (string) ($in['ref'] ?? ''), (string) ($in['body'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'message' => $msg]);
}
plugin_json(['error' => 'Adresse inconnue.'], 404);
