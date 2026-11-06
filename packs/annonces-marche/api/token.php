<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/auth.php';

if (!am_is_entitled()) {
    plugin_json(['error' => 'Souscrivez à l\'option (ou démarrez l\'essai) avant de créer un jeton.'], 402);
}
$token = am_token_rotate();
plugin_json([
    'ok' => true,
    'token' => $token,
    'token_prefix' => am_setting('token_prefix'),
    'hint' => 'Copiez ce jeton dans l\'extension Chrome. Il ne sera plus jamais réaffiché.',
]);
