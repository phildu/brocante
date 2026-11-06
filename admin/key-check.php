<?php
// Test des clés d'API : bouton « Tester » (POST), « Tout tester » (POST) et rafraîchissement discret du tableau de bord (GET ?json=1).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/key-check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['json'])) {
    foreach (keycheck_stale() as $id) keycheck_run($id);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html' => keycheck_alert_html()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/social.php');
    exit;
}
admin_csrf_check();

$services = keycheck_services();
$id = (string) ($_POST['service'] ?? '');
$back = '/admin/social.php';
if ($id === 'all') {
    $bad = 0;
    $n = 0;
    foreach ($services as $sid => $svc) {
        if (!$svc[1]) continue;
        $n++;
        if (!keycheck_run($sid)['ok']) $bad++;
    }
    if ($n === 0) flash_set('Aucune clé enregistrée à tester.');
    elseif ($bad === 0) flash_set($n . ' clé' . ($n > 1 ? 's testées' : ' testée') . ' : tout fonctionne.');
    else flash_set($bad . ' clé' . ($bad > 1 ? 's' : '') . ' sur ' . $n . ' en échec : voir le détail ci-dessous.', 'error');
} elseif (isset($services[$id])) {
    $r = keycheck_run($id);
    flash_set($services[$id][0] . ' : ' . $r['message'], $r['ok'] ? 'ok' : 'error');
    $back = $services[$id][3];
} else {
    flash_set('Service inconnu.', 'error');
}
header('Location: ' . $back);
exit;
