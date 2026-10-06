<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
admin_csrf_check();

// Un champ laissé vide ne touche pas à la clé déjà enregistrée — même logique que save-fal-key.php.
$key = trim((string) ($_POST['siliconflow_key'] ?? ''));
$file = SECRETS_DIR . '/siliconflow.key';

if (!empty($_POST['remove'])) {
    if (is_file($file)) unlink($file);
    flash_set('Clé SiliconFlow retirée.');
} elseif ($key !== '') {
    if (!preg_match('/^[A-Za-z0-9._-]{16,200}$/', $key)) {
        flash_set("Cette clé n'a pas le bon format : copiez-la telle quelle depuis SiliconFlow, sans espace.", 'error');
    } else {
        if (!is_dir(SECRETS_DIR)) mkdir(SECRETS_DIR, 0700, true);
        file_put_contents($file, $key);
        chmod($file, 0600);
        flash_set('Clé SiliconFlow enregistrée.');
    }
} else {
    flash_set('Aucune clé fournie — rien de changé.');
}

header('Location: /admin/index.php#siliconflow-settings');
exit;
