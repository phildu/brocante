<?php
// API JSON du widget « Un retour ? » sur les pages du portail (exploitant ou
// administrateur de galerie). Même traitement que admin/feedback-api.php
// (includes/feedback-endpoint.php) ; l'authentification et le jeton CSRF sont
// ceux de la session du portail. Un administrateur de galerie ne voit que les
// retours de sa galerie.

require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/feedback-endpoint.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$gallery = defined('GA_LIMITED') ? (string) GA_LIMITED : '';
$name = trim((string) ($_SESSION['portail_user'] ?? ''));
if ($name === '') $name = $gallery !== '' ? 'Administrateur de galerie' : 'Exploitant';

feedback_api_handle([
    'audience' => 'admin',
    'surface' => 'portail',
    'shop' => '',
    'gallery' => $gallery,
    'csrf' => csrf_token(),
    'customer' => null,
    'session' => null,
    'account_name' => $name,
]);
