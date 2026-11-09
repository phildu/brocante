<?php
// Documents de découverte OAuth du serveur MCP : ?doc=resource (RFC 9728) ou ?doc=server (RFC 8414). Appelé aussi par les règles
// /.well-known/… du .htaccess, qui passent le commerce dans ?t=<identifiant>. Voir includes/mcp-oauth.php.
$t = (string) ($_GET['t'] ?? '');
if ($t !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $t) && is_file(__DIR__ . '/tenants/' . $t . '/tenant.php') && !file_exists(__DIR__ . '/' . $t)) {
    putenv('TENANT=' . $t);
    $GLOBALS['mcp_oauth_base'] = '/' . $t; // le commerce vient de l'adresse, pas d'un domaine à lui
}
require_once __DIR__ . '/includes/mcp-oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') mcp_oauth_json(405, ['error' => 'Utilisez GET.'], ['Allow: GET']);
$doc = (string) ($_GET['doc'] ?? '');
if ($doc === 'resource') mcp_oauth_json(200, mcp_oauth_resource_document());
if ($doc === 'server') mcp_oauth_json(200, mcp_oauth_server_document());
mcp_oauth_json(404, ['error' => 'Document inconnu.']);
