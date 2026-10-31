<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$by = (string) (($session['name'] ?? '') !== '' ? $session['name'] : 'administrateur');

if ($action === 'availability') {
    $minutes = (int) ($in['minutes'] ?? 0);
    av_set_availability($minutes, (string) ($in['note'] ?? ''), $by);
    plugin_json(av_admin_state() + ['message' => $minutes > 0 ? 'Disponibilité affichée sur le site.' : 'Vous n’êtes plus disponible.']);
}
if ($action === 'settings') {
    if ($err = av_settings_save($in)) plugin_json(['error' => $err], 422);
    plugin_json(av_admin_state() + ['message' => 'Réglages enregistrés.']);
}
if ($action === 'seller') {
    if ($err = av_seller_save($in)) plugin_json(['error' => $err], 422);
    plugin_json(av_admin_state() + ['message' => 'Vendeur enregistré.']);
}
if ($action === 'seller-delete') {
    av_seller_delete((string) ($in['email'] ?? ''));
    plugin_json(av_admin_state() + ['message' => 'Vendeur retiré.']);
}
if ($action === 'sync') {
    $n = av_sellers_sync();
    plugin_json(av_admin_state() + ['message' => $n > 0 ? $n . ' compte(s) de l’équipe ajouté(s).' : 'Tous les comptes de l’équipe sont déjà présents.']);
}
plugin_json(['error' => 'Action invalide.'], 422);