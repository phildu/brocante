<?php
// Point d'échange OAuth du serveur MCP : code + PKCE → jetons, ou renouvellement. Client public (aucun secret), PKCE S256 obligatoire.
require_once __DIR__ . '/includes/mcp-oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') mcp_oauth_json(405, ['error' => 'Utilisez POST.'], ['Allow: POST']);
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
if (!$https && app_env() !== 'local') mcp_oauth_json(400, ['error' => 'invalid_request', 'error_description' => 'HTTPS obligatoire.']);

$p = [];
foreach (['grant_type', 'code', 'redirect_uri', 'client_id', 'code_verifier', 'refresh_token'] as $k) {
    $p[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
}
if (mcp_auth_blocked(mcp_client_ip())) mcp_oauth_json(429, ['error' => 'invalid_request', 'error_description' => 'Trop d\'échecs : réessayez dans quelques minutes.']);
$client = mcp_oauth_client($p['client_id']);
$fail = static function (string $error, string $why): never {
    mcp_audit('oauth', null, 'refus : ' . $error);
    usleep(300000);
    mcp_oauth_json(400, ['error' => $error, 'error_description' => $why]);
};
if (!$client) $fail('invalid_client', 'Client inconnu.');

if ($p['grant_type'] === 'authorization_code') {
    $tokens = mcp_oauth_code_redeem($p['code'], $client['client_id'], $p['redirect_uri'], $p['code_verifier']);
    if (!$tokens) $fail('invalid_grant', 'Code invalide, expiré ou déjà utilisé.');
    mcp_audit('oauth', null, 'jeton émis (' . ($client['client_name'] ?: $client['client_id']) . ')');
    mcp_oauth_json(200, $tokens);
}
if ($p['grant_type'] === 'refresh_token') {
    $tokens = mcp_oauth_refresh($p['refresh_token'], $client['client_id']);
    if (!$tokens) $fail('invalid_grant', 'Jeton de renouvellement invalide ou expiré.');
    mcp_oauth_json(200, $tokens);
}
$fail('unsupported_grant_type', 'authorization_code ou refresh_token attendu.');
