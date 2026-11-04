<?php
require_once __DIR__ . '/../includes/session.php';
// Le cookie de session (daté si « Rester connecté » était coché) est effacé du navigateur.
$params = session_get_cookie_params();
$clear = [
    'expires' => time() - 3600,
    'path' => $params['path'] !== '' ? $params['path'] : '/',
    'secure' => !empty($params['secure']),
    'httponly' => true,
    'samesite' => $params['samesite'] ?? 'Lax',
];
if (($params['domain'] ?? '') !== '') $clear['domain'] = $params['domain'];
setcookie(session_name(), '', $clear);
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');
exit;
