<?php
// Pièces retenues par le vendeur depuis l'assistance : elles ne sont plus achetables en ligne (sauf par le visiteur
// pour qui la pièce est gardée), le temps de répondre ou de la vendre en boutique.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

if (!av_active()) return [];
$token = av_session_token(false);
$out = [];
foreach (db()->query('SELECT ref, visitor FROM av_holds WHERE until > ' . time() . ' AND released_at IS NULL') as $h) {
    $ref = (string) $h['ref'];
    if ($ref === '') continue;
    if ((string) $h['visitor'] !== '' && (string) $h['visitor'] === $token) continue;
    $out[$ref] = 1;
}
return $out;