<?php
// Reçu imprimable à l'adresse /api/recu/<jeton> (jeton aléatoire de 32 caractères, communiqué à l'acheteur dans son e-mail).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rc.php';

if (!preg_match('#^/recu/([a-f0-9]{32})$#', $agentPath, $m)) plugin_json(['error' => 'Adresse inconnue.'], 404);
$st = db()->prepare('SELECT * FROM rc_receipts WHERE token = ?');
$st->execute([$m[1]]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) plugin_json(['error' => 'Reçu introuvable.'], 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
echo rc_html($r);
exit;
