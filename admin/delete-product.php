<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
auth_require_action(basename(__FILE__), (string) ($_POST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

$ref = (string) ($_POST['ref'] ?? '');
$moved = move_product_photos_to_media($ref);
$stmt = db()->prepare('DELETE FROM products WHERE ref = ?');
$stmt->execute([$ref]);

flash_set($moved
    ? "Pièce supprimée du catalogue — ses photos ont été conservées dans la médiathèque."
    : 'Pièce supprimée du catalogue.');
header('Location: /admin/catalog.php');
exit;
