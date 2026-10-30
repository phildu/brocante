<?php
// API JSON du widget « Un retour ? » (assets/feedback-widget.js).
// Session de l'administration de la boutique obligatoire ; jeton CSRF pour
// toute écriture. GET ?action=mine : retours de la boutique et réponses.
// POST action=submit : nouveau retour, capture facultative (multipart).

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/feedback.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function feedback_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$session = admin_session();
if ($session === null) feedback_json(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
$shop = tenant_slug();
if (!feedback_widget_enabled($shop)) feedback_json(['ok' => false, 'error' => "Le widget de retour n'est pas ouvert à cette boutique."], 403);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($method !== 'GET') {
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if (!hash_equals(admin_csrf_token(), $token)) feedback_json(['ok' => false, 'error' => 'Jeton expiré : rechargez la page.'], 400);
}

/** Retours de la boutique tels que le testeur les voit (sans notes internes ni journal technique). */
function feedback_widget_items(string $shop): array
{
    $out = [];
    foreach (feedback_shop_records($shop, 20) as $f) {
        $replies = [];
        foreach ($f['replies'] as $reply) {
            $replies[] = ['at' => (string) ($reply['at'] ?? ''), 'by' => (string) ($reply['by'] ?? ''), 'text' => (string) ($reply['text'] ?? '')];
        }
        $out[] = [
            'id' => $f['id'],
            'at' => $f['at'],
            'type' => $f['type'],
            'type_label' => FEEDBACK_TYPE_LABELS[$f['type']] ?? $f['type'],
            'severity' => $f['severity'],
            'severity_label' => FEEDBACK_SEVERITY_LABELS[$f['severity']] ?? '',
            'status' => $f['status'],
            'status_label' => feedback_status_tester_label($f['status']),
            'status_tone' => feedback_status_tone($f['status']),
            'message' => $f['message'],
            'page' => $f['page'],
            'replies' => $replies,
        ];
    }
    return $out;
}

if ($method === 'GET' && $action === 'peek') {
    feedback_json(['ok' => true, 'unread' => feedback_shop_unread($shop)]);
}

if ($method === 'GET' && $action === 'mine') {
    $unread = feedback_shop_unread($shop);
    feedback_mark_shop_seen($shop);
    feedback_json(['ok' => true, 'unread' => $unread, 'items' => feedback_widget_items($shop)]);
}

if ($method === 'POST' && $action === 'submit') {
    try {
        $record = feedback_add([
            'shop' => $shop,
            'type' => (string) ($_POST['type'] ?? 'other'),
            'severity' => (string) ($_POST['severity'] ?? ''),
            'message' => (string) ($_POST['message'] ?? ''),
            'page' => (string) ($_POST['page'] ?? ''),
            'page_title' => (string) ($_POST['page_title'] ?? ''),
            'module' => (string) ($_POST['module'] ?? ''),
            'viewport' => (string) ($_POST['viewport'] ?? ''),
            'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'version' => (string) ($_POST['version'] ?? ''),
            'attachment_file' => $_FILES['shot'] ?? null,
        ], isset($session['id']) ? (int) $session['id'] : null, (string) ($session['name'] ?? ''));
        feedback_json([
            'ok' => true,
            'message' => 'Merci ! Votre retour est enregistré.',
            'feedback' => [
                'id' => $record['id'],
                'type_label' => FEEDBACK_TYPE_LABELS[$record['type']] ?? $record['type'],
                'status_label' => feedback_status_tester_label($record['status']),
            ],
        ]);
    } catch (InvalidArgumentException $e) {
        feedback_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('feedback-api: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
        feedback_json(['ok' => false, 'error' => "Le retour n'a pas pu être enregistré. Réessayez dans un instant."], 500);
    }
}

feedback_json(['ok' => false, 'error' => 'Action inconnue.'], 404);
