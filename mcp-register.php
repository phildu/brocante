<?php
// Enregistrement dynamique de client OAuth (RFC 7591) pour le serveur MCP. Un client enregistré ne peut rien faire seul :
// il faut ensuite que le propriétaire se connecte à l'administration et approuve (mcp-authorize.php).
require_once __DIR__ . '/includes/mcp-oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') mcp_oauth_json(405, ['error' => 'Utilisez POST.'], ['Allow: POST']);
$raw = file_get_contents('php://input', false, null, 0, 20001);
$in = $raw !== false && strlen($raw) <= 20000 ? json_decode($raw, true) : null;
if (!is_array($in)) mcp_oauth_json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'JSON attendu.']);
try {
    $c = mcp_oauth_register($in);
} catch (McpToolError $e) {
    mcp_oauth_json(400, ['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()]);
}
mcp_audit('oauth', null, 'client enregistré (' . ($c['client_name'] !== '' ? $c['client_name'] : $c['client_id']) . ')');
mcp_oauth_json(201, $c + [
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => 'none',
]);
