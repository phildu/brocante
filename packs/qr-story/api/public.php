<?php
// Page publique d'une pièce : /api/story/<jeton> (jeton aléatoire de 32 caractères imprimé dans le QR Code).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/qs.php';

if (!preg_match('#^/story/([a-f0-9]{32})$#', $agentPath, $m) || !addon_is_entitled(QS_MODULE)) plugin_json(['error' => 'Adresse inconnue.'], 404);
$st = db()->prepare('SELECT * FROM qs_stories WHERE token = ? AND published = 1');
$st->execute([$m[1]]);
$story = $st->fetch(PDO::FETCH_ASSOC);
if (!$story) plugin_json(['error' => 'Adresse inconnue.'], 404);
$ps = db()->prepare('SELECT * FROM products WHERE ref = ?');
$ps->execute([$story['product_ref']]);
$p = $ps->fetch(PDO::FETCH_ASSOC);
if (!$p) plugin_json(['error' => 'Adresse inconnue.'], 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
echo qs_public_html($story, $p);
exit;
