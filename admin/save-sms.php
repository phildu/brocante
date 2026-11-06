<?php
// Fournisseur SMS de la boutique (Administration → Réglages du site → SMS).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php#sms-settings');
    exit;
}
admin_csrf_check();

db(); // charge includes/recto-verso.php (réglages)
require_once __DIR__ . '/../includes/sms.php';

$provider = (string) ($_POST['sms_provider'] ?? '');
if (!in_array($provider, ['', 'ovh', 'twilio'], true)) $provider = '';

// Un mot de passe ou un jeton vide conserve la valeur déjà enregistrée ; les
// autres champs se vident quand on les vide (bascule d'un fournisseur à l'autre).
$fields = ['ovh_sms_account', 'ovh_sms_login', 'ovh_sms_password', 'ovh_sms_from', 'twilio_sid', 'twilio_token', 'twilio_from'];
$secrets = ['ovh_sms_password', 'twilio_token'];
$warn = '';

foreach ($fields as $name) {
    $value = trim((string) ($_POST[$name] ?? ''));
    if ($value === '' && in_array($name, $secrets, true)) continue;
    if ($name === 'ovh_sms_from' && $value !== '' && !sms_sender_valid($value)) {
        $warn = ' Expéditeur OVH refusé : 11 caractères alphanumériques au plus (ou 15 chiffres) — l\'ancienne valeur est conservée.';
        continue;
    }
    shop_setting_set($name, $value);
}
shop_setting_set('sms_provider', $provider);

$label = sms_provider_label();
$own = sms_ovh_ready(sms_ovh_config(SMS_SOURCE_SHOP)) || sms_twilio_ready(sms_twilio_config(SMS_SOURCE_SHOP));
$message = match (true) {
    $label === '' => 'Réglages SMS enregistrés, mais aucun fournisseur n\'est complet : renseignez les identifiants ici ou dans le portail (/portail/sms.php), sinon les envois restent bloqués.',
    $own => 'SMS : fournisseur ' . $label . ' enregistré pour cette boutique et complet.',
    default => 'Réglages SMS enregistrés : cette boutique utilise les identifiants du portail (' . $label . ').',
};
flash_set($message . $warn, $label !== '' && $warn === '' ? 'ok' : 'error');
header('Location: /admin/index.php#sms-settings');
exit;
