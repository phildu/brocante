<?php
/**
 * Actions sur les collaborateurs (POST). Chaque action exige son droit
 * « portail.utilisateurs.* » ET un jeton CSRF ; les règles anti-escalade
 * (ne gérer que des comptes et rôles couverts par ses propres droits) sont
 * appliquées dans includes/auth.php.
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/users.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$needs = [
    'create' => 'portail.utilisateurs.creer',
    'update' => 'portail.utilisateurs.modifier',
    'set_password' => 'portail.utilisateurs.modifier',
    'toggle_active' => 'portail.utilisateurs.modifier',
    'delete' => 'portail.utilisateurs.supprimer',
];
if (!isset($needs[$action])) {
    auth_require_login();
    auth_deny();
}
require_permission($needs[$action]);
require_csrf();

$me = auth_user();
$id = (int) ($_POST['id'] ?? 0);
$back = '/admin/users.php';
$err = null;
$ok = null;

switch ($action) {
    case 'create': {
        [$newId, $err] = auth_user_create(
            $me,
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['name'] ?? ''),
            (int) ($_POST['role_id'] ?? 0),
            (string) ($_POST['password'] ?? ''),
            !empty($_POST['must_change'])
        );
        $ok = 'Compte créé. Transmettez-lui son e-mail et son mot de passe provisoire.';
        break;
    }
    case 'update': {
        $err = auth_user_update(
            $me,
            $id,
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (int) ($_POST['role_id'] ?? 0),
            !empty($_POST['is_active'])
        );
        $ok = 'Collaborateur mis à jour.';
        $back = '/admin/users.php?edit=' . $id . '#edition';
        break;
    }
    case 'set_password': {
        $err = auth_user_set_password($me, $id, (string) ($_POST['password'] ?? ''), !empty($_POST['must_change']));
        $ok = 'Mot de passe réinitialisé. Transmettez-le à la personne.';
        $back = '/admin/users.php?edit=' . $id . '#edition';
        break;
    }
    case 'toggle_active': {
        $target = auth_user_get($id);
        if (!$target) {
            $err = 'Collaborateur introuvable.';
            break;
        }
        $err = auth_user_update($me, $id, $target['name'], $target['email'], $target['role_id'], !$target['is_active']);
        $ok = $target['is_active'] ? 'Compte désactivé : la personne est déconnectée à sa prochaine action.' : 'Compte réactivé.';
        break;
    }
    case 'delete': {
        $err = auth_user_delete($me, $id);
        $ok = 'Compte supprimé.';
        break;
    }
}

flash_set($err ?? $ok, $err ? 'error' : 'ok');
header('Location: ' . ($err && $action === 'create' ? '/admin/users.php#invite' : $back));
exit;
