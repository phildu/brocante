<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portail/');
    exit;
}
csrf_check();

$slug = (string) ($_POST['slug'] ?? '');
$shops = tenant_list();
if (!isset($shops[$slug])) {
    portail_flash('Commerce introuvable.', 'error');
    header('Location: /portail/');
    exit;
}

switch ($_POST['action'] ?? '') {
    case 'activer':
        if (!is_file(tenant_file($shops[$slug], 'db_file'))) {
            seed_tenant($shops[$slug]);
        }
        set_active_slug($slug);
        portail_flash('Le site affiche maintenant « ' . $shops[$slug]['name'] . ' ».');
        break;

    case 'reinitialiser':
        if ($slug === TENANT_DEFAULT) {
            portail_flash('La base du Petit Chalet ne se réinitialise pas depuis le portail.', 'error');
            break;
        }
        $count = seed_tenant($shops[$slug]);
        portail_flash('« ' . $shops[$slug]['name'] . " » remis à zéro : $count produits.");
        break;

    case 'acces':
        try {
            $user = set_tenant_admin_access($slug, (string) ($_POST['admin_user'] ?? ''), (string) ($_POST['admin_password'] ?? ''));
            portail_flash('Accès admin de « ' . $shops[$slug]['name'] . " » changé : identifiant « $user » et le nouveau mot de passe.");
        } catch (InvalidArgumentException $ex) {
            portail_flash($ex->getMessage(), 'error');
            header('Location: /portail/?voir=' . rawurlencode($slug) . '&acces=' . rawurlencode($slug));
            exit;
        }
        break;

    case 'supprimer':
        if (($why = tenant_delete_blocker($slug)) !== null) {
            portail_flash($why, 'error');
            break;
        }
        // Confirmation : l'identifiant doit être retapé tel quel.
        if (!hash_equals($slug, trim((string) ($_POST['confirm_slug'] ?? '')))) {
            portail_flash("Suppression annulée : l'identifiant saisi (« " . trim((string) ($_POST['confirm_slug'] ?? '')) . " ») ne correspond pas à « $slug ».", 'error');
            header('Location: /portail/?supprimer=' . rawurlencode($slug));
            exit;
        }
        try {
            $trash = delete_tenant($slug);
            portail_flash('« ' . $shops[$slug]['name'] . " » supprimé de ce serveur. Ses fichiers sont conservés dans $trash (déplacés, pas effacés).");
            header('Location: /portail/');
            exit;
        } catch (RuntimeException | InvalidArgumentException $ex) {
            portail_flash($ex->getMessage(), 'error');
        }
        break;

    default:
        portail_flash('Action inconnue.', 'error');
}

header('Location: /portail/?voir=' . rawurlencode($slug));
exit;
