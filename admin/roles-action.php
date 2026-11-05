<?php
/**
 * Actions sur les rôles (POST). Droit « portail.roles.* » + CSRF ; on ne peut
 * accorder que des droits qu'on possède (includes/auth.php).
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/roles.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$needs = [
    'create' => 'portail.roles.creer',
    'update' => 'portail.roles.modifier',
    'delete' => 'portail.roles.supprimer',
];
if (!isset($needs[$action])) {
    auth_require_login();
    auth_deny();
}
require_permission($needs[$action]);
require_csrf();

$me = auth_user();
$id = (int) ($_POST['id'] ?? 0);
$perms = array_values(array_filter((array) ($_POST['perms'] ?? []), 'is_string'));
$err = null;
$ok = null;
$back = '/admin/roles.php';

switch ($action) {
    case 'create': {
        [$newId, $err] = auth_role_create($me, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''), $perms);
        $ok = 'Rôle créé.';
        $back = $err ? '/admin/roles.php?new=1#role-form' : '/admin/roles.php?edit=' . (int) $newId . '#role-form';
        break;
    }
    case 'update': {
        $err = auth_role_update($me, $id, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''), $perms);
        $ok = 'Droits du rôle enregistrés — effectifs immédiatement.';
        $back = '/admin/roles.php?edit=' . $id . '#role-form';
        break;
    }
    case 'delete': {
        $err = auth_role_delete($me, $id);
        $ok = 'Rôle supprimé.';
        break;
    }
}

if ($err !== null && $action !== 'delete') {
    // Garde la saisie (nom + matrice) : refaire tout le cochage pour une faute de nom serait pénible.
    $_SESSION['role_form_draft'] = [
        'id' => $action === 'update' ? $id : null,
        'name' => (string) ($_POST['name'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'perms' => $perms,
    ];
}

flash_set($err ?? $ok, $err ? 'error' : 'ok');
header('Location: ' . $back);
exit;
