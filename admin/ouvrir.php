<?php
// Ouverture de l'administration juste après la création de la boutique : lien à usage unique, valable 30 minutes,
// généré par inscription/creer.php (jeton conservé dans la demande d'inscription, privée).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saas.php';

$fail = static function (): void {
    header('Location: /admin/login.php', true, 303);
    exit;
};

$req = saas_request_load((string) ($_GET['r'] ?? ''));
$token = (string) ($_GET['t'] ?? '');
if (!$req || $token === '' || empty($req['open_token']) || ($req['status'] ?? '') !== 'approved'
    || (string) ($req['slug'] ?? '') !== tenant_slug()
    || (int) ($req['open_exp'] ?? 0) < time()
    || !hash_equals((string) $req['open_token'], $token)) {
    $fail();
}

// Usage unique : le jeton est consommé avant d'ouvrir la session.
unset($req['open_token'], $req['open_exp']);
saas_request_save($req);

session_regenerate_id(true);
$_SESSION['is_admin'] = tenant_slug();
unset($_SESSION['admin_account_id']);
header('Location: ' . admin_home_for_role('admin'), true, 303);
exit;
