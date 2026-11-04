<?php
// API JSON du widget « Un retour ? » (assets/feedback-widget.js).
// Session de l'administration de la boutique ou compte client de la boutique
// obligatoire ; jeton CSRF pour toute écriture. Un client ne voit que ses
// propres retours. GET ?action=mine : retours et réponses. POST action=submit :
// nouveau retour, capture facultative (multipart).

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/feedback.php';
require_once __DIR__ . '/../includes/feedback-todo.php';
require_once __DIR__ . '/../includes/notion-todo.php';

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
$customer = null;
if ($session !== null) {
    $audience = 'admin';
    $csrf = admin_csrf_token();
} else {
    $customer = customer_session();
    if (!is_array($customer) || !filter_var((string) ($customer['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        feedback_json(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
    }
    $audience = 'customer';
    $csrf = feedback_csrf_token();
}
$shop = tenant_slug();
if (!feedback_widget_enabled($shop)) feedback_json(['ok' => false, 'error' => "Le widget de retour n'est pas ouvert à cette boutique."], 403);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($method !== 'GET') {
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, $token)) feedback_json(['ok' => false, 'error' => 'Jeton expiré : rechargez la page.'], 400);
}

/** Retours tels que leur auteur les voit (sans notes internes ni journal technique). */
function feedback_widget_items(array $records): array
{
    $out = [];
    foreach ($records as $f) {
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

/** Retours visibles par la session courante : toute la boutique pour l'équipe, les siens pour un client. */
function feedback_widget_scope(string $shop, string $audience, ?array $customer): array
{
    if ($audience === 'customer') {
        return feedback_records_for_email($shop, (string) ($customer['email'] ?? ''), 20);
    }
    return feedback_shop_records($shop, 20);
}

if ($method === 'GET' && $action === 'peek') {
    if ($audience === 'customer') {
        $seen = (string) ($_SESSION['feedback_seen'][$shop] ?? '');
        feedback_json(['ok' => true, 'unread' => feedback_records_unread(feedback_widget_scope($shop, $audience, $customer), $seen)]);
    }
    feedback_json(['ok' => true, 'unread' => feedback_shop_unread($shop)]);
}

if ($method === 'GET' && $action === 'mine') {
    $records = feedback_widget_scope($shop, $audience, $customer);
    if ($audience === 'customer') {
        $unread = feedback_records_unread($records, (string) ($_SESSION['feedback_seen'][$shop] ?? ''));
        $_SESSION['feedback_seen'][$shop] = gmdate('c');
    } else {
        $unread = feedback_shop_unread($shop);
        feedback_mark_shop_seen($shop);
    }
    feedback_json(['ok' => true, 'unread' => $unread, 'items' => feedback_widget_items($records)]);
}

if ($method === 'POST' && $action === 'submit') {
    try {
        $record = feedback_add([
            'shop' => $shop,
            'audience' => $audience,
            'email' => $audience === 'customer' ? (string) $customer['email'] : '',
            'type' => (string) ($_POST['type'] ?? 'other'),
            'severity' => (string) ($_POST['severity'] ?? ''),
            'message' => (string) ($_POST['message'] ?? ''),
            'page' => (string) ($_POST['page'] ?? ''),
            'url' => (string) ($_POST['url'] ?? ''),
            'page_title' => (string) ($_POST['page_title'] ?? ''),
            'module' => (string) ($_POST['module'] ?? ''),
            'viewport' => (string) ($_POST['viewport'] ?? ''),
            'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'version' => (string) ($_POST['version'] ?? ''),
            'attachment_file' => $_FILES['shot'] ?? null,
        ], $audience === 'customer' ? null : (isset($session['id']) ? (int) $session['id'] : null),
            $audience === 'customer' ? (string) ($customer['name'] ?? '') : (string) ($session['name'] ?? ''));
        echo json_encode([
            'ok' => true,
            'message' => 'Merci ! Votre retour est enregistré.',
            'feedback' => [
                'id' => $record['id'],
                'type_label' => FEEDBACK_TYPE_LABELS[$record['type']] ?? $record['type'],
                'status_label' => feedback_status_tester_label($record['status']),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Réponse envoyée avant les imports quand le serveur le permet : un
        // incident Notion ne retarde jamais l'envoi du retour.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        feedback_todo_import($record);
        notion_todo_import($record);
        exit;
    } catch (InvalidArgumentException $e) {
        feedback_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('feedback-api: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
        feedback_json(['ok' => false, 'error' => "Le retour n'a pas pu être enregistré. Réessayez dans un instant."], 500);
    }
}

feedback_json(['ok' => false, 'error' => 'Action inconnue.'], 404);
