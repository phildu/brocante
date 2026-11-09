<?php
// Justificatif d'une ligne du journal (photo ou PDF), relu depuis data/depenses/ : jamais servi directement, réservé aux administrateurs.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expenses.php';
require_admin();

$pdo = db();
expense_ensure_schema($pdo);
$row = expense_find($pdo, (int) ($_GET['id'] ?? 0));
$path = $row ? expense_receipt_path((string) $row['receipt']) : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Justificatif introuvable.';
    exit;
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="justificatif-' . (int) $row['id'] . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
