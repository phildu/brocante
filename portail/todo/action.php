<?php
require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /portail/todo/');
    exit;
}
csrf_check();

$fromDashboard = ($_POST['retour'] ?? '') === 'dashboard';
$vue = (string) ($_POST['vue'] ?? 'ouvert');
if (!in_array($vue, ['ouvert', 'fait', 'tout'], true)) {
    $vue = 'ouvert';
}
$cat = (string) ($_POST['cat'] ?? '');
if (!isset(TD_CATEGORIES[$cat])) {
    $cat = '';
}
$back = $fromDashboard
    ? '/portail/'
    : '/portail/todo/?vue=' . $vue . ($cat !== '' ? '&cat=' . rawurlencode($cat) : '');
$id = (int) ($_POST['id'] ?? 0);

try {
    switch ($_POST['action'] ?? '') {
        case 'add':
            td_add($_POST);
            portail_flash('Tâche ajoutée.');
            $back = '/portail/todo/';
            break;

        case 'update':
            if ($id <= 0 || !td_find($id)) {
                throw new InvalidArgumentException('Tâche introuvable.');
            }
            td_update($id, $_POST);
            portail_flash('Tâche modifiée.');
            break;

        case 'toggle':
            $task = td_find($id);
            if (!$task) {
                throw new InvalidArgumentException('Tâche introuvable.');
            }
            td_toggle($id);
            portail_flash($task['status'] === 'todo' ? 'Tâche terminée.' : 'Tâche remise à faire.');
            break;

        case 'delete':
            if ($id <= 0 || !td_find($id)) {
                throw new InvalidArgumentException('Tâche introuvable.');
            }
            td_delete($id);
            portail_flash('Tâche supprimée.');
            break;

        default:
            throw new InvalidArgumentException('Action inconnue.');
    }
} catch (InvalidArgumentException $ex) {
    portail_flash($ex->getMessage(), 'error');
}

header('Location: ' . $back);
exit;
