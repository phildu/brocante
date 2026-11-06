<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

// Alerte de démonstration : voix et liste de l'administration, sans e-mail, SMS ni WhatsApp.
av_alert_create([
    'kind' => 'test',
    'detail' => 'Alerte de démonstration : la voix et le son de cette page fonctionnent.',
]);
plugin_json(av_admin_state() + ['message' => 'Alerte de démonstration envoyée.']);