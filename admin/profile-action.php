<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin(); // tout compte connecté (administrateur ou community manager) gère SON profil

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/profile.php');
    exit;
}
admin_csrf_check();

// Le compte modifié est toujours celui de la session, jamais un identifiant envoyé par le formulaire.
$actingId = admin_session()['id'] ?? null;
if ($actingId === null) {
    flash_set("Le compte principal du commerce n'est pas modifiable ici : il est défini dans tenants/" . tenant_slug() . '/tenant.php.', 'error');
    header('Location: /admin/profile.php');
    exit;
}

try {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    if ($newPassword !== '' && $newPassword !== (string) ($_POST['new_password_confirm'] ?? '')) {
        throw new InvalidArgumentException('Les deux nouveaux mots de passe ne sont pas identiques.');
    }
    account_update_profile((int) $actingId, $_POST, (string) ($_POST['current_password'] ?? ''), $newPassword);
    if ($newPassword !== '') {
        session_regenerate_id(true); // nouvelle session après un changement de mot de passe
        flash_set('Profil mis à jour et mot de passe changé.');
    } else {
        flash_set('Profil mis à jour.');
    }
} catch (InvalidArgumentException $ex) {
    flash_set($ex->getMessage(), 'error');
} catch (PDOException $ex) {
    error_log('profile-action : ' . $ex->getMessage());
    flash_set("L'enregistrement a échoué (adresse e-mail ou identifiant déjà utilisé ?).", 'error');
}

header('Location: /admin/profile.php');
exit;
