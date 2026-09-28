<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/appearance.php');
    exit;
}

switch ($_POST['action'] ?? '') {
    case 'save':
        appearance_save(
            (array) ($_POST['colors'] ?? []),
            (string) ($_POST['font_display'] ?? ''),
            (string) ($_POST['font_body'] ?? '')
        );
        flash_set('Apparence enregistrée : elle s\'applique maintenant à tout le site.');
        break;

    case 'reset':
        appearance_reset();
        flash_set("Apparence d'origine rétablie.");
        break;
}

header('Location: /admin/appearance.php');
exit;
