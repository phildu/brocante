<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vendor-avatar.php';
require_admin(); // tout compte connecté (administrateur ou community manager) gère SON profil

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/profile.php');
    exit;
}
admin_csrf_check();

// Avatar : disponible aussi pour le compte principal (non modifiable par
// ailleurs), puisqu'il n'est rangé que dans les réglages de la boutique.
$avatarAction = (string) ($_POST['avatar_action'] ?? '');
if ($avatarAction !== '') {
    $avatarSession = admin_session();
    $avatarKey = vendor_avatar_key($avatarSession);
    @set_time_limit(120);
    try {
        if ($avatarAction === 'generate') {
            vendor_avatar_generate_from_upload($avatarKey, $_FILES['portrait'] ?? [], $avatarSession);
            flash_set("Avatar généré (" . ai_format_eur(vendor_avatar_cost_eur()) . " décomptés de votre crédit IA). Vous pouvez maintenant l'utiliser comme logo de boutique.");
        } elseif ($avatarAction === 'use_logo') {
            $avatar = vendor_avatar_for($avatarSession) ?? throw new InvalidArgumentException("Générez d'abord un avatar.");
            $composed = vendor_avatar_use_as_logo($avatar);
            $count = count($composed['paths']);
            flash_set("Votre avatar est désormais le logo de la boutique : $count déclinaison" . ($count > 1 ? 's' : '')
                . ' (en-tête, vertical, carré) composée' . ($count > 1 ? 's' : '') . '.'
                . ($composed['failed'] ? ' Échec pour : ' . implode(', ', $composed['failed']) . '.' : ''));
        } elseif ($avatarAction === 'remove') {
            vendor_avatar_remove($avatarKey);
            flash_set('Avatar retiré. Le logo de la boutique, lui, reste inchangé.');
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        flash_set($ex->getMessage(), 'error');
    } catch (Throwable $ex) {
        error_log('profile-action avatar : ' . $ex->getMessage());
        flash_set("L'opération sur l'avatar a échoué. Réessayez.", 'error');
    }
    header('Location: /admin/profile.php');
    exit;
}

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
