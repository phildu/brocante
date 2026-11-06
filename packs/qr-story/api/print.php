<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/qs.php';

if (!addon_is_entitled(QS_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
$kind = (($_GET['kind'] ?? '') === 'certificate') ? 'certificate' : 'labels';
$refs = array_slice(array_values(array_filter(array_map(static fn ($r) => preg_replace('/[^A-Za-z0-9_-]/', '', trim($r)), explode(',', (string) ($_GET['refs'] ?? ''))))), 0, 60);
header('Content-Type: text/html; charset=utf-8');
echo qs_print_html($kind, $refs);
exit;
