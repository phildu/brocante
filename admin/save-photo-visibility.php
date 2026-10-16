<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
db(); // charge includes/recto-verso.php (réglages)
$on = !empty($_POST['hide_shooting_photos']);
shop_setting_set('hide_shooting_photos', $on ? '1' : '0');
flash_set($on ? 'Les photos du shooting seront masquées une fois les images générées.' : 'Les photos du shooting restent visibles.');
header('Location: /admin/index.php#photo-visibility-settings');
exit;
