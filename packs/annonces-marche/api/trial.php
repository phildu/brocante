<?php
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/settings.php';

if (am_is_entitled()) {
    plugin_json(['error' => 'L\'option est déjà active.'], 409);
}
if (am_setting('trial_used') === '1') {
    plugin_json(['error' => 'L\'essai gratuit a déjà été utilisé. Souscrivez pour continuer.'], 409);
}

$until = time() + (AM_TRIAL_DAYS * 86400);
am_setting_set('subscription_status', 'trial');
am_setting_set('trial_until', (string) $until);
am_setting_set('trial_used', '1');
am_setting_set('trial_started_at', gmdate('c'));

plugin_json([
    'ok' => true,
    'entitlement' => am_entitlement(),
    'message' => 'Essai de ' . AM_TRIAL_DAYS . ' jours activé jusqu\'au ' . gmdate('d/m/Y', $until) . '.',
]);
