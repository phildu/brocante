<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin(); // page réservée aux administrateurs (voir admin_role_can_access())

$actingId = admin_session()['id'] ?? null;

// ── Export CSV des contacts (lecture seule, GET) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    $role = (string) ($_GET['role'] ?? '');
    $rows = accounts_list(in_array($role, ['client', 'prospect'], true) ? $role : null);
    // Les comptes de l'équipe ne sont jamais exportés : seulement les contacts.
    $rows = array_values(array_filter($rows, static fn (array $a): bool => !account_is_staff($a['role'])));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts-' . tenant_slug() . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit correctement les accents
    // Une cellule qui commence par = + - @ serait interprétée comme une formule par un tableur.
    $safe = static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    // Paramètre $escape passé explicitement (obligatoire depuis PHP 8.4).
    fputcsv($out, ['Rôle', 'Nom', 'E-mail', 'Téléphone', 'Origine', 'Inscrit le', 'Commandes payées', 'Total payé (€)'], ';', '"', '');
    foreach ($rows as $a) {
        fputcsv($out, [
            account_role_label($a['role']), $safe($a['name']), $safe($a['email']), $safe($a['phone']),
            ACCOUNT_SOURCES[$a['source']] ?? $a['source'], substr((string) $a['created_at'], 0, 10),
            (int) $a['orders_count'], number_format(((int) $a['orders_total']) / 100, 2, ',', ''),
        ], ';', '"', '');
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/accounts.php');
    exit;
}
admin_csrf_check();

$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$backRole = (string) ($_POST['back_role'] ?? '');
$back = '/admin/accounts.php' . (isset(ACCOUNT_ROLES[$backRole]) ? '?role=' . $backRole : '');

try {
    switch ($action) {
        case 'create': {
            // Les champs saisis (sauf le mot de passe) sont conservés si la création échoue.
            $_SESSION['accounts_old'] = array_intersect_key($_POST, array_flip(['role', 'name', 'email', 'username', 'phone', 'notes']));
            $newId = account_create($_POST, (string) ($_POST['password'] ?? ''));
            unset($_SESSION['accounts_old']);
            $created = account_find($newId);
            flash_set('Compte « ' . ($created['name'] !== '' ? $created['name'] : $created['email']) . ' » créé (' . account_role_label($created['role']) . ').');
            $back = '/admin/accounts.php?role=' . $created['role'];
            break;
        }
        case 'update': {
            account_update($id, $_POST, (string) ($_POST['password'] ?? ''), $actingId);
            flash_set('Compte mis à jour.');
            break;
        }
        case 'toggle': {
            $active = !empty($_POST['active']);
            account_set_active($id, $active, $actingId);
            flash_set($active ? 'Compte réactivé.' : 'Compte désactivé : sa connexion est coupée immédiatement.');
            break;
        }
        case 'delete': {
            $account = account_find($id);
            account_delete($id, $actingId);
            flash_set('Compte « ' . ($account['name'] ?: $account['email']) . ' » supprimé.');
            break;
        }
        case 'sync_clients': {
            [$created, $upgraded] = accounts_sync_clients_from_orders();
            flash_set($created + $upgraded === 0
                ? 'Tous les clients ayant commandé sont déjà dans la liste.'
                : "$created client(s) ajouté(s) depuis les commandes" . ($upgraded ? ", $upgraded prospect(s) passé(s) en client" : '') . '.');
            $back = '/admin/accounts.php?role=client';
            break;
        }
        default:
            flash_set('Action inconnue.', 'error');
    }
} catch (InvalidArgumentException $ex) {
    flash_set($ex->getMessage(), 'error');
    if ($action === 'update' && $id) {
        $back = '/admin/accounts.php?edit=' . $id;
    } elseif ($action === 'create') {
        $back = '/admin/accounts.php?add=1' . (isset(ACCOUNT_ROLES[$backRole]) ? '&role=' . $backRole : '');
    }
} catch (PDOException $ex) {
    error_log('accounts-action : ' . $ex->getMessage());
    flash_set("L'enregistrement a échoué (adresse e-mail ou identifiant déjà utilisé ?).", 'error');
    $back = $action === 'update' && $id ? '/admin/accounts.php?edit=' . $id : '/admin/accounts.php?add=1';
}

header('Location: ' . $back);
exit;
