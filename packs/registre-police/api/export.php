<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/pr.php';

if (!addon_is_entitled(PR_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
$from = (string) ($_GET['from'] ?? '2000-01-01');
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) plugin_json(['error' => 'Période invalide.'], 422);
if (($_GET['format'] ?? 'html') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="livre-de-police-' . $from . '_' . $to . '.csv"');
    echo pr_csv($from, $to);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo pr_html($from, $to);
exit;
