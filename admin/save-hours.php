<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/closure-alerts.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php?s=pages&p=horaires');
    exit;
}
admin_csrf_check();

if (!empty($_POST['reset_hours'])) {
    shop_setting_set('opening_hours', '');
    shop_setting_set('opening_closures', '');
    shop_setting_set('opening_notice', '');
    flash_set('Horaires structurés retirés : le texte libre des horaires est de nouveau affiché.');
    header('Location: /admin/index.php?s=pages&p=horaires');
    exit;
}

$schedule = [];
foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
    $row = $_POST['hours'][$day] ?? [];
    $schedule[$day] = [
        'closed' => !empty($row['closed']),
        'from' => is_array($row['from'] ?? null) ? array_values($row['from']) : [],
        'to' => is_array($row['to'] ?? null) ? array_values($row['to']) : [],
    ];
}
shop_hours_save_schedule($schedule);

$knownClosureKeys = array_map('closure_alert_key', shop_hours_closures());   // avant l'enregistrement : sert à repérer les nouvelles périodes
$closures = [];
foreach ((array) ($_POST['closures'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $from = trim((string) ($row['from'] ?? ''));
    $to = trim((string) ($row['to'] ?? '')) ?: $from;
    if ($from === '' && $to === '') continue;
    $closures[] = [
        'from' => $from,
        'to' => $to,
        'type' => (string) ($row['type'] ?? 'closure'),
        'label' => (string) ($row['label'] ?? ''),
    ];
}
shop_hours_save_closures($closures);

shop_setting_set('opening_notice', mb_substr(trim((string) ($_POST['opening_notice'] ?? '')), 0, 300));

$newClosures = closure_alert_new_pending($knownClosureKeys);
if ($newClosures && auth_can('portail.notifications.envoyer')) {
    // Une nouvelle fermeture à venir : on propose (sans jamais envoyer) de prévenir les clients.
    $firstKey = (string) array_key_first($newClosures);
    flash_set('Horaires, fermetures et vacances enregistrés. ' . (count($newClosures) > 1 ? count($newClosures) . ' nouvelles fermetures à venir.' : 'Nouvelle fermeture à venir.') . ' Le site l’annonce déjà ; voulez-vous aussi prévenir vos clients ?', 'ok',
        ['url' => '/admin/closure-alert.php?closure=' . rawurlencode($firstKey), 'label' => 'Prévenir les clients']);
} else {
    flash_set('Horaires, fermetures et vacances enregistrés.');
}
header('Location: /admin/index.php?s=pages&p=horaires');
exit;
