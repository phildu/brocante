<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 20000) plugin_json(['error' => 'Requête trop grande.'], 413);
$in = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    $in = is_array($decoded) ? $decoded : $_POST;
}

if ($agentPath === '/presence/state' && $method === 'GET') {
    $token = av_token($_GET['token'] ?? '');
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['ref'] ?? ''));
    plugin_json(av_state($token, $ref));
}

if ($agentPath === '/presence/ping') {
    if ($method !== 'POST') {
        header('Allow: POST');
        plugin_json(['error' => 'Méthode non autorisée.'], 405);
    }
    $state = av_ping($in);
    plugin_json($state, isset($state['error']) ? 422 : 200);
}

if ($agentPath === '/presence/question') {
    if ($method !== 'POST') {
        header('Allow: POST');
        plugin_json(['error' => 'Méthode non autorisée.'], 405);
    }
    $r = av_question($in);
    plugin_json($r, empty($r['ok']) ? 422 : 200);
}

plugin_json(['error' => 'Adresse inconnue.'], 404);