<?php

// OAuth 2.1 du serveur MCP (RFC 9728 / 8414 / 7591, PKCE S256) : permet d'ajouter le site comme « connecteur personnalisé » dans
// Claude (Paramètres → Connecteurs) sans jeton à copier. Le propriétaire se connecte à l'administration du commerce et approuve
// l'accès ; le client reçoit un jeton d'accès (1 h) et un jeton de renouvellement (30 jours, renouvelé à chaque usage).
// Aucun secret en clair : seules les empreintes SHA-256 sont conservées. Révocation : Administration → Accès MCP.
// Fichiers : mcp-oauth-meta.php (documents de découverte), mcp-register.php, mcp-authorize.php, mcp-token.php. Voir MCP.md.

require_once __DIR__ . '/mcp.php';

const MCP_OAUTH_ACCESS_TTL = 3600;
const MCP_OAUTH_REFRESH_TTL = 2592000; // 30 jours
const MCP_OAUTH_CODE_TTL = 600;
const MCP_OAUTH_MAX_CLIENTS = 100;
const MCP_OAUTH_ACCESS_PREFIX = 'bbmcpo_';
const MCP_OAUTH_REFRESH_PREFIX = 'bbmcpr_';

function mcp_oauth_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
        client_id TEXT PRIMARY KEY,
        client_name TEXT NOT NULL DEFAULT \'\',
        redirect_uris TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        last_used_at INTEGER
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
        hash TEXT PRIMARY KEY,
        client_id TEXT NOT NULL,
        redirect_uri TEXT NOT NULL,
        challenge TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (
        hash TEXT PRIMARY KEY,
        kind TEXT NOT NULL,
        client_id TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $done = true;
}

/* ------------------------------------------------------------------ */
/* Adresses                                                            */
/* ------------------------------------------------------------------ */

/** Racine de l'adresse publique du commerce, sans barre finale : https://pre.bricbrok.com/<identifiant> (ou le domaine seul). */
function mcp_oauth_issuer(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host)) $host = 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . ($GLOBALS['mcp_oauth_base'] ?? tenant_base_path());
}

function mcp_oauth_resource_url(): string { return mcp_oauth_issuer() . '/mcp.php'; }

function mcp_oauth_resource_metadata_url(): string { return mcp_oauth_issuer() . '/mcp-oauth-meta.php?doc=resource'; }

function mcp_oauth_resource_document(): array
{
    return [
        'resource' => mcp_oauth_resource_url(),
        'authorization_servers' => [mcp_oauth_issuer()],
        'bearer_methods_supported' => ['header'],
        'resource_name' => 'Site ' . (string) (get_content()['site_name'] ?? '') . ' (MCP)',
    ];
}

function mcp_oauth_server_document(): array
{
    $i = mcp_oauth_issuer();
    return [
        'issuer' => $i,
        'authorization_endpoint' => $i . '/mcp-authorize.php',
        'token_endpoint' => $i . '/mcp-token.php',
        'registration_endpoint' => $i . '/mcp-register.php',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => ['mcp'],
    ];
}

function mcp_oauth_json(int $status, array $data, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

/** Adresse de retour acceptée : https, ou http sur la machine locale (clients en ligne de commande). Jamais de fragment ni d'identifiants. */
function mcp_oauth_redirect_uri_ok(string $uri): bool
{
    if ($uri === '' || strlen($uri) > 500 || preg_match('/[\x00-\x20\\\\#]/', $uri)) return false;
    $p = parse_url($uri);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    $host = strtolower($p['host']);
    if ($p['scheme'] === 'https') return true;
    return $p['scheme'] === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

/** PKCE S256 : verifier (43-128 caractères) → challenge = base64url(SHA-256(verifier)). */
function mcp_oauth_pkce_ok(string $verifier, string $challenge): bool
{
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) return false;
    $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return hash_equals($challenge, $calc);
}

function mcp_oauth_challenge_ok(string $challenge): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
}

/* ------------------------------------------------------------------ */
/* Clients (enregistrement dynamique)                                  */
/* ------------------------------------------------------------------ */

/** @return array{client_id:string,client_name:string,redirect_uris:array,client_id_issued_at:int} */
function mcp_oauth_register(array $in): array
{
    mcp_oauth_ensure_schema();
    $uris = $in['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 5) throw new McpToolError('redirect_uris : 1 à 5 adresses attendues.');
    foreach ($uris as $u) {
        if (!is_string($u) || !mcp_oauth_redirect_uri_ok($u)) throw new McpToolError('redirect_uris : adresse refusée (https ou http local uniquement).');
    }
    $pdo = db();
    // Les clients jamais utilisés depuis une semaine sont purgés ; plafond de clients.
    $pdo->prepare('DELETE FROM mcp_oauth_clients WHERE COALESCE(last_used_at, created_at) < ?')->execute([time() - 7 * 86400]);
    if ((int) $pdo->query('SELECT COUNT(*) FROM mcp_oauth_clients')->fetchColumn() >= MCP_OAUTH_MAX_CLIENTS) throw new McpToolError('Trop de clients enregistrés.');
    $id = 'mcpc_' . bin2hex(random_bytes(12));
    $name = mb_substr(trim(strip_tags((string) ($in['client_name'] ?? ''))), 0, 80);
    $now = time();
    $pdo->prepare('INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES), $now]);
    return ['client_id' => $id, 'client_name' => $name, 'redirect_uris' => array_values($uris), 'client_id_issued_at' => $now];
}

function mcp_oauth_client(string $id): ?array
{
    mcp_oauth_ensure_schema();
    if (!preg_match('/^mcpc_[a-f0-9]{24}$/', $id)) return null;
    $st = db()->prepare('SELECT * FROM mcp_oauth_clients WHERE client_id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
    return $row;
}

/* ------------------------------------------------------------------ */
/* Codes et jetons                                                     */
/* ------------------------------------------------------------------ */

function mcp_oauth_hash(string $secret): string { return hash('sha256', $secret); }

/** Code d'autorisation à usage unique (10 minutes), lié au client, à l'adresse de retour et au challenge PKCE. */
function mcp_oauth_code_issue(string $clientId, string $redirectUri, string $challenge): string
{
    mcp_oauth_ensure_schema();
    $pdo = db();
    $pdo->prepare('DELETE FROM mcp_oauth_codes WHERE expires_at < ?')->execute([time()]);
    $code = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO mcp_oauth_codes (hash, client_id, redirect_uri, challenge, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([mcp_oauth_hash($code), $clientId, $redirectUri, $challenge, time() + MCP_OAUTH_CODE_TTL]);
    return $code;
}

/** Nouvelle paire de jetons pour un client. */
function mcp_oauth_tokens_issue(string $clientId): array
{
    mcp_oauth_ensure_schema();
    $pdo = db();
    $now = time();
    $pdo->prepare('DELETE FROM mcp_oauth_tokens WHERE expires_at < ?')->execute([$now]);
    $access = MCP_OAUTH_ACCESS_PREFIX . bin2hex(random_bytes(24));
    $refresh = MCP_OAUTH_REFRESH_PREFIX . bin2hex(random_bytes(24));
    $ins = $pdo->prepare('INSERT INTO mcp_oauth_tokens (hash, kind, client_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([mcp_oauth_hash($access), 'access', $clientId, $now + MCP_OAUTH_ACCESS_TTL, $now]);
    $ins->execute([mcp_oauth_hash($refresh), 'refresh', $clientId, $now + MCP_OAUTH_REFRESH_TTL, $now]);
    $pdo->prepare('UPDATE mcp_oauth_clients SET last_used_at = ? WHERE client_id = ?')->execute([$now, $clientId]);
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => MCP_OAUTH_ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => 'mcp'];
}

/** Échange un code (usage unique) contre des jetons ; null si le code, le client, l'adresse de retour ou le verifier ne correspondent pas. */
function mcp_oauth_code_redeem(string $code, string $clientId, string $redirectUri, string $verifier): ?array
{
    mcp_oauth_ensure_schema();
    $pdo = db();
    $h = mcp_oauth_hash($code);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM mcp_oauth_codes WHERE hash = ?');
        $st->execute([$h]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        // Usage unique : le code est consommé dans tous les cas, même si une vérification échoue.
        if ($row) $pdo->prepare('DELETE FROM mcp_oauth_codes WHERE hash = ?')->execute([$h]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if (!$row || (int) $row['expires_at'] < time() || !hash_equals((string) $row['client_id'], $clientId)
        || !hash_equals((string) $row['redirect_uri'], $redirectUri) || !mcp_oauth_pkce_ok($verifier, (string) $row['challenge'])) {
        return null;
    }
    return mcp_oauth_tokens_issue($clientId);
}

/** Renouvelle : l'ancien jeton de renouvellement est consommé, une nouvelle paire est émise. */
function mcp_oauth_refresh(string $refresh, string $clientId): ?array
{
    mcp_oauth_ensure_schema();
    if (!str_starts_with($refresh, MCP_OAUTH_REFRESH_PREFIX)) return null;
    $pdo = db();
    $h = mcp_oauth_hash($refresh);
    $st = $pdo->prepare("SELECT client_id, expires_at FROM mcp_oauth_tokens WHERE hash = ? AND kind = 'refresh'");
    $st->execute([$h]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['expires_at'] < time() || !hash_equals((string) $row['client_id'], $clientId)) return null;
    $pdo->prepare('DELETE FROM mcp_oauth_tokens WHERE hash = ?')->execute([$h]);
    return mcp_oauth_tokens_issue($clientId);
}

function mcp_oauth_access_valid(string $token): bool
{
    if (!preg_match('/^' . MCP_OAUTH_ACCESS_PREFIX . '[a-f0-9]{48}$/', $token)) return false;
    mcp_oauth_ensure_schema();
    $st = db()->prepare("SELECT expires_at FROM mcp_oauth_tokens WHERE hash = ? AND kind = 'access'");
    $st->execute([mcp_oauth_hash($token)]);
    $exp = $st->fetchColumn();
    return $exp !== false && (int) $exp >= time();
}

/** Coupe tous les accès OAuth (jetons, codes en attente, clients). */
function mcp_oauth_revoke_all(): void
{
    mcp_oauth_ensure_schema();
    db()->exec('DELETE FROM mcp_oauth_tokens');
    db()->exec('DELETE FROM mcp_oauth_codes');
    db()->exec('DELETE FROM mcp_oauth_clients');
}

/** Applications autorisées (au moins un jeton de renouvellement valable) : pour la page d'administration. */
function mcp_oauth_connections(): array
{
    mcp_oauth_ensure_schema();
    $st = db()->prepare("SELECT c.client_name, c.client_id, MAX(t.created_at) AS since FROM mcp_oauth_tokens t JOIN mcp_oauth_clients c ON c.client_id = t.client_id WHERE t.kind = 'refresh' AND t.expires_at >= ? GROUP BY c.client_id ORDER BY since DESC");
    $st->execute([time()]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
