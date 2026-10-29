<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rc.php';

if (!addon_is_entitled(RC_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    plugin_json(['error' => 'Période invalide (dates AAAA-MM-JJ, début avant fin).'], 422);
}
$format = (string) ($_GET['format'] ?? 'csv');
if ($format === 'fec') {
    $name = rc_siren() . 'FEC' . str_replace('-', '', $to) . '.txt';
    $body = rc_fec($from, $to);
    $type = 'text/plain; charset=utf-8';
} elseif ($format === 'csv') {
    $name = 'journal-des-ventes-' . $from . '_' . $to . '.csv';
    $body = rc_journal_csv($from, $to);
    $type = 'text/csv; charset=utf-8';
} else {
    plugin_json(['error' => 'Format inconnu (csv ou fec).'], 422);
}
header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="' . $name . '"');
echo $body;
exit;
