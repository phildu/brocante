<?php
// Enregistrement d'une note de testeur sur un résultat IA (widget « Résultat IA » et
// tableau « Derniers appels » de Consommation IA). Réservé aux rôles qui voient l'IA.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai-rating.php';

$session = admin_session();
if (!$session) {
    header('Location: /admin/login.php');
    exit;
}
if (!admin_role_can_access((string) $session['role'], '/admin/ai-usage.php')) {
    admin_forbidden((string) $session['role']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/ai-usage.php');
    exit;
}
admin_csrf_check();

$usageId = (int) ($_POST['usage_id'] ?? 0);
$back = (string) ($_POST['back'] ?? '/admin/ai-usage.php');
if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || preg_match('/[\r\n]/', $back)) $back = '/admin/ai-usage.php';

if (!empty($_POST['later'])) {
    $_SESSION['ai_rating_skip'] = $usageId;
    header('Location: ' . $back);
    exit;
}

$saved = ai_rating_save(db(), $usageId, (int) ($_POST['stars'] ?? 0), (string) ($_POST['comment'] ?? ''), ai_rating_tester($session));
if ($saved) {
    unset($_SESSION['ai_rating_skip']);
    flash_set('Merci : votre note est enregistrée et compte dans le taux d\'efficacité de l\'IA.');
} else {
    flash_set('Note non enregistrée : choisissez une note de 1 à 5 sur un appel IA existant.', 'error');
}
header('Location: ' . $back);
exit;
