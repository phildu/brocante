<?php
// Export CSV du journal des dépenses (pour le comptable) : mêmes période et filtres que la page, toutes les lignes avec leur état.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expenses.php';
require_admin();

$pdo = db();
expense_ensure_schema($pdo);
$period = (string) ($_GET['p'] ?? '');
[$from, $to, $label] = expense_period($period, dash_now());
$rows = expense_list($pdo, [
    'from' => $from, 'to' => $to,
    'kind' => (string) ($_GET['kind'] ?? ''), 'category' => (string) ($_GET['cat'] ?? ''), 'q' => (string) ($_GET['q'] ?? ''),
]);
$slug = $period === 'tout' ? 'tout' : substr($from, 0, 10 - (strlen($period) === 4 ? 6 : 3));   // « 2026 » ou « 2026-11 »

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="depenses-' . $slug . '.csv"');
header('Cache-Control: private, no-store');
echo expense_csv($rows);
