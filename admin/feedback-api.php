<?php
// API JSON du widget « Un retour ? » (assets/feedback-widget.js).
// Session de l'administration de la boutique ou compte client de la boutique
// obligatoire ; jeton CSRF pour toute écriture. Un client ne voit que ses
// propres retours. GET ?action=mine : retours et réponses. POST action=submit :
// nouveau retour, capture facultative (multipart). Traitement : includes/feedback-endpoint.php
// (le portail a sa propre entrée : portail/retour-api.php).

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/feedback-endpoint.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$session = admin_session();
$customer = null;
if ($session !== null) {
    $audience = 'admin';
    $surface = 'admin';
    $csrf = admin_csrf_token();
} else {
    $customer = customer_session();
    if (!is_array($customer) || !filter_var((string) ($customer['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        feedback_json(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
    }
    $audience = 'customer';
    $surface = 'front';
    $csrf = feedback_csrf_token();
}

feedback_api_handle([
    'audience' => $audience,
    'surface' => $surface,
    'shop' => tenant_slug(),
    'csrf' => $csrf,
    'customer' => $customer,
    'session' => $session,
]);
