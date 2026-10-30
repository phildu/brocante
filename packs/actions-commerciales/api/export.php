<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

if (!aco_enabled()) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

$op = aco_op((int) ($_GET['id'] ?? 0));
if (!$op) plugin_json(['error' => 'Opération introuvable.'], 404);

$kind = (string) preg_replace('/[^a-z0-9-]/', '', (string) $op['kind']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="operation-' . (int) $op['id'] . '-' . $kind . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach (aco_csv_rows($op, aco_sales_report($op)) as $row) {
    fputcsv($out, $row, ';', '"', '');
}
fclose($out);
exit;
