<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

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
