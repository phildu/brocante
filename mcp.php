<?php
// Serveur MCP du commerce (Streamable HTTP, réponses JSON). Authentification : « Authorization: Bearer bbmcp_… »
// (jeton créé dans Administration → Accès MCP). Pas de session, pas de cookie. Détails : MCP.md.
require_once __DIR__ . '/includes/mcp.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function mcp_http(int $status, ?array $data = null, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $h) header($h);
    if ($data !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('MCP : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    mcp_http(500, mcp_rpc_error(null, -32603, 'Erreur interne.'));
});

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') mcp_http(405, ['error' => 'Utilisez POST (JSON-RPC).'], ['Allow: POST']);

// Le jeton ne doit jamais circuler en clair (sauf sur la machine de développement).
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$https && app_env() !== 'local') mcp_http(400, ['error' => 'HTTPS obligatoire.']);

// Contre le « DNS rebinding » : une page web (en-tête Origin) n'a rien à faire ici ; seuls les clients MCP natifs appellent.
if (($_SERVER['HTTP_ORIGIN'] ?? '') !== '') mcp_http(403, ['error' => 'Origine refusée.']);

$ip = mcp_client_ip();
if (mcp_auth_blocked($ip)) mcp_http(429, ['error' => 'Trop d\'échecs : réessayez dans quelques minutes.'], ['Retry-After: ' . MCP_AUTH_WINDOW_SECONDS]);

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($token === '' || !mcp_token_valid($token)) {
    mcp_audit('auth', null, 'refus');
    usleep(400000);
    // Même réponse que le jeton soit absent, faux, révoqué ou que le MCP soit désactivé.
    mcp_http(401, ['error' => 'Jeton invalide.'], ['WWW-Authenticate: Bearer']);
}

$raw = file_get_contents('php://input', false, null, 0, MCP_MAX_BODY + 1);
if ($raw === false || strlen($raw) > MCP_MAX_BODY) mcp_http(413, mcp_rpc_error(null, -32600, 'Requête trop volumineuse.'));
$body = json_decode($raw, true);
if (!is_array($body) || $body === []) mcp_http(400, mcp_rpc_error(null, -32700, 'JSON invalide.'));

@set_time_limit(60);
$isBatch = array_is_list($body);
$responses = [];
foreach ($isBatch ? $body : [$body] as $msg) {
    if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0') { $responses[] = mcp_rpc_error(null, -32600, 'Requête JSON-RPC 2.0 attendue.'); continue; }
    // Une réponse client (résultat d'une requête serveur) : rien à faire.
    if (!isset($msg['method'])) continue;
    $r = mcp_handle($msg);
    if ($r !== null) $responses[] = $r;
}

if (!$responses) mcp_http(202); // notifications seules
mcp_http(200, $isBatch ? $responses : $responses[0]);
