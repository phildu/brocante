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

    default:
        portail_flash('Action inconnue.', 'error');
}

header('Location: /portail/');
exit;
