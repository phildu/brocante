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
// Appareil de confiance : le jeton est révoqué en base et son cookie effacé.
require_once __DIR__ . '/../includes/remember.php';
$trustedCookie = (string) ($_COOKIE[remember_cookie_name()] ?? '');
if ($trustedCookie !== '') {
    try {
        require_once __DIR__ . '/../config.php';
        remember_revoke_cookie(db(), $trustedCookie);
    } catch (Throwable $e) {
    }
    remember_set_cookie('', time() - 3600);
}
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');
exit;
