<?php
require_once __DIR__ . '/../includes/comptage-layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
cp_boot();

if (cp_is_volunteer()) {
    header('Location: /comptage/index.php');
    exit;
}

$error = null;
if (cp_post_action() === 'login') {
    $error = cp_volunteer_login((string) ($_POST['code'] ?? ''));
    if ($error === null) {
        header('Location: /comptage/index.php');
        exit;
    }
}

cp_login_page($error);
