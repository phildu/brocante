<?php
/**
 * API d'envoi : une plateforme externe (ou Zapier, Make…) y poste ses produits.
 *
 *   POST /api/catalog-push.php
 *   Authorization: Bearer <jeton généré dans Administration → Boutiques connectées>
 *   Content-Type: application/json
 *   {"products": [{"external_id": "SKU-1", "name": "…", "price": "34,00", …}]}
 *
 * Même traitement que les connecteurs (voir includes/connectors.php) ; format
 * des champs détaillé dans CONNECTEURS.md. Pas de session ni de cookie : seul
 * le jeton authentifie l'appelant.
 */
require_once __DIR__ . '/../includes/connectors.php';

const CATALOG_PUSH_MAX_PRODUCTS = 25;   // photos téléchargées pendant la requête → lot borné
const CATALOG_PUSH_MAX_BODY = 2 * 1024 * 1024;

function push_reply(int $status, array $data, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    push_reply(405, ['error' => 'Méthode non autorisée : utilisez POST.'], ['Allow: POST']);
}

// Le jeton ne doit jamais circuler en clair.
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$https && !connector_testing()) {
    push_reply(400, ['error' => 'HTTPS obligatoire.']);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');

connectors_ensure_schema();
$source = $token !== '' ? connector_api_source_for_token($token) : null;
if (!$source) {
    // Même réponse que le jeton soit absent, faux ou révoqué.
    push_reply(401, ['error' => 'Jeton invalide.'], ['WWW-Authenticate: Bearer']);
}

$raw = file_get_contents('php://input', false, null, 0, CATALOG_PUSH_MAX_BODY + 1);
if ($raw === false || strlen($raw) > CATALOG_PUSH_MAX_BODY) push_reply(413, ['error' => 'Corps de requête trop volumineux (2 Mo max).']);
$body = json_decode($raw, true);
$list = is_array($body) ? ($body['products'] ?? null) : null;
if (!is_array($list) || $list !== array_values($list)) push_reply(400, ['error' => 'Attendu : {"products": [ … ]}.']);
if (count($list) === 0) push_reply(400, ['error' => 'La liste de produits est vide.']);
if (count($list) > CATALOG_PUSH_MAX_PRODUCTS) {
    push_reply(413, ['error' => 'Trop de produits par requête (' . CATALOG_PUSH_MAX_PRODUCTS . ' maximum) : envoyez-les par lots.']);
}

@set_time_limit(120);
$runId = connector_new_run_id();
$cache = [];
$counts = ['created' => 0, 'updated' => 0, 'failed' => 0];
$results = [];
foreach ($list as $i => $entry) {
    $extId = is_array($entry) ? (string) ($entry['external_id'] ?? '') : '';
    try {
        if (!is_array($entry)) throw new RuntimeException('produit invalide');
        $status = connector_upsert_item($source, connector_item_from_api($entry), $runId, $cache);
        $counts[$status]++;
        $results[] = ['index' => $i, 'external_id' => $extId, 'status' => $status];
    } catch (Throwable $e) {
        $counts['failed']++;
        $results[] = ['index' => $i, 'external_id' => $extId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}

db()->prepare('UPDATE import_sources SET last_sync_at = ?, last_sync_status = ?, last_sync_summary = ? WHERE id = ?')->execute([
    gmdate('Y-m-d H:i:s'),
    $counts['failed'] ? 'partial' : 'ok',
    sprintf('%d créé(s), %d mis à jour, %d en échec (dernier lot reçu).', $counts['created'], $counts['updated'], $counts['failed']),
    (int) $source['id'],
]);

push_reply($counts['failed'] === count($list) ? 422 : 200, $counts + ['results' => $results]);
