<?php
// Contacts joignables et accords par canal : liste pour l'administration, enregistrement du numéro et des accords.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

if (!aco_enabled()) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    plugin_json(['contacts' => aco_contacts_list(), 'channels' => aco_channel_labels()]);
}

$in = plugin_input();
$err = aco_contact_save((string) ($in['email'] ?? ''), (string) ($in['phone'] ?? ''),
    !empty($in['inapp']), !empty($in['sms']), !empty($in['wa']));
if ($err) plugin_json(['error' => $err], 422);
plugin_json(['ok' => true, 'message' => 'Consentements enregistrés.']);
