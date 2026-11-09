<?php
// Traitement commun des requêtes du widget « Un retour ? » (assets/feedback-widget.js).
// Utilisé par admin/feedback-api.php (administration d'une boutique, site public) et
// par portail/retour-api.php (portail). Chaque point d'entrée authentifie l'appelant,
// puis fournit le contexte ; ce fichier gère peek / mine / submit.

require_once __DIR__ . '/feedback.php';
require_once __DIR__ . '/feedback-todo.php';
require_once __DIR__ . '/notion-todo.php';

function feedback_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

/**
 * Traite la requête courante (termine le script).
 *
 * $ctx : audience (admin|customer), surface (admin|front|portail), shop ('' pour le portail),
 *        gallery (portail : galerie de l'administrateur connecté, sinon ''), csrf, customer (client connecté ou null),
 *        session (session d'administration ou null), account_name (auteur du retour portail).
 */
function feedback_api_handle(array $ctx): never
{
    $audience = (string) $ctx['audience'];
    $surface = (string) $ctx['surface'];
    $shop = (string) $ctx['shop'];
    $gallery = (string) ($ctx['gallery'] ?? '');
    $csrf = (string) $ctx['csrf'];
    $customer = is_array($ctx['customer'] ?? null) ? $ctx['customer'] : null;
    $session = is_array($ctx['session'] ?? null) ? $ctx['session'] : null;
    $portail = $surface === 'portail';

    $enabled = $portail ? feedback_portail_widget_enabled() : feedback_widget_enabled($shop, $surface);
    if (!$enabled) {
        feedback_json(['ok' => false, 'error' => $portail
            ? "Le widget de retour n'est pas ouvert sur le portail."
            : "Le widget de retour n'est pas ouvert à cette boutique."], 403);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
    if ($method !== 'GET') {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
        if ($csrf === '' || !hash_equals($csrf, $token)) feedback_json(['ok' => false, 'error' => 'Jeton expiré : rechargez la page.'], 400);
    }

    // Retours visibles par la session courante : toute la boutique pour l'équipe, les siens pour un client,
    // ceux du portail (limités à sa galerie pour un administrateur de galerie).
    $scope = static function () use ($portail, $audience, $shop, $gallery, $customer): array {
        if ($portail) return feedback_portail_records($gallery, 20);
        if ($audience === 'customer') return feedback_records_for_email($shop, (string) ($customer['email'] ?? ''), 20);
        return feedback_shop_records($shop, 20);
    };
    $teamUnread = static fn (): int => $portail ? feedback_portail_unread($gallery) : feedback_shop_unread($shop);

    if ($method === 'GET' && $action === 'peek') {
        if ($audience === 'customer') {
            $seen = (string) ($_SESSION['feedback_seen'][$shop] ?? '');
            feedback_json(['ok' => true, 'unread' => feedback_records_unread($scope(), $seen)]);
        }
        feedback_json(['ok' => true, 'unread' => $teamUnread()]);
    }

    if ($method === 'GET' && $action === 'mine') {
        $records = $scope();
        if ($audience === 'customer') {
            $unread = feedback_records_unread($records, (string) ($_SESSION['feedback_seen'][$shop] ?? ''));
            $_SESSION['feedback_seen'][$shop] = gmdate('c');
        } else {
            $unread = $teamUnread();
            if ($portail) feedback_mark_portail_seen($gallery); else feedback_mark_shop_seen($shop);
        }
        feedback_json(['ok' => true, 'unread' => $unread, 'items' => feedback_widget_items($records)]);
    }

    if ($method === 'POST' && $action === 'submit') {
        try {
            $accountId = $audience === 'customer' || $portail ? null : (isset($session['id']) ? (int) $session['id'] : null);
            $accountName = $audience === 'customer'
                ? (string) ($customer['name'] ?? '')
                : ($portail ? (string) ($ctx['account_name'] ?? '') : (string) ($session['name'] ?? ''));
            $record = feedback_add([
                'shop' => $shop,
                'surface' => $surface,
                'gallery' => $gallery,
                'audience' => $audience,
                'email' => $audience === 'customer' ? (string) $customer['email'] : '',
                'type' => (string) ($_POST['type'] ?? 'other'),
                'severity' => (string) ($_POST['severity'] ?? ''),
                'message' => (string) ($_POST['message'] ?? ''),
                'page' => (string) ($_POST['page'] ?? ''),
                'url' => (string) ($_POST['url'] ?? ''),
                'page_title' => (string) ($_POST['page_title'] ?? ''),
                'module' => $portail ? '' : (string) ($_POST['module'] ?? ''),
                'viewport' => (string) ($_POST['viewport'] ?? ''),
                'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'version' => (string) ($_POST['version'] ?? ''),
                'attachment_file' => $_FILES['shot'] ?? null,
            ], $accountId, $accountName);
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
}
