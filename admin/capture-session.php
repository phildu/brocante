<?php
// Sessions de prise de vue depuis un téléphone proche (voir includes/capture.php),
// pilotées par la médiathèque. Réponses JSON.
//   POST action=create → nouveau code : {url, id, reachable, expires_in, max_video_seconds, max_mb}
//   GET  action=status → fichiers reçus depuis l'envoi n° `after` : {active, expires_in, uploads[]}
//   POST action=close  → ferme le code
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    reply(['ok' => false, 'error' => 'Connexion requise.'], 401);
}

$action = (string) ($_REQUEST['action'] ?? '');

if ($action === 'status') {
    $session = capture_session_find((int) ($_GET['id'] ?? 0));
    if (!$session) {
        reply(['ok' => false, 'error' => 'Session inconnue.'], 404);
    }
    $uploads = capture_uploads_since((int) $session['id'], (int) ($_GET['after'] ?? 0));
    reply([
        'ok' => true,
        'active' => capture_session_is_active($session),
        'expires_in' => max(0, strtotime($session['expires_at'] . ' UTC') - time()),
        'total' => capture_upload_count((int) $session['id']),
        'uploads' => array_map(static fn (array $u): array => [
            'id' => (int) $u['id'], 'type' => $u['type'], 'path' => $u['path'], 'label' => $u['label'],
        ], $uploads),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    reply(['ok' => false, 'error' => 'Formulaire expiré : rechargez la page.'], 400);
}

if ($action === 'create') {
    $session = capture_session_create(admin_session()['id'] ?? null);
    [$url, $reachable] = capture_public_url($session['token']);
    reply([
        'ok' => true,
        'id' => $session['id'],
        'url' => $url,
        'reachable' => $reachable,
        'expires_in' => CAPTURE_TTL_MINUTES * 60,
        'max_video_seconds' => CAPTURE_VIDEO_MAX_SECONDS,
        'max_mb' => (int) round(min(capture_max_file_bytes(), 4096 * 1048576) / 1048576),
    ]);
}

if ($action === 'close') {
    capture_session_close((int) ($_POST['id'] ?? 0));
    reply(['ok' => true]);
}

reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
