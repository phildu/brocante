<?php
// Envoi d'un SMS de test depuis les réglages SMS de l'administration.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php#sms-test');
    exit;
}
admin_csrf_check();

db(); // charge includes/recto-verso.php (réglages)
require_once __DIR__ . '/../includes/sms.php';

$to = trim((string) ($_POST['sms_test_to'] ?? ''));
if (sms_phone_e164($to) === null) {
    flash_set('Numéro de test invalide : indiquez-le au format international, ex. +33 6 12 34 56 78.', 'error');
    header('Location: /admin/index.php#sms-test');
    exit;
}

$provider = sms_provider_name();
if ($provider === '' && !sms_local_host()) {
    flash_set('Aucun fournisseur SMS complet : enregistrez d\'abord les identifiants OVH ou Twilio ci-dessus.', 'error');
    header('Location: /admin/index.php#sms-settings');
    exit;
}

$body = 'Test SMS de votre boutique : si vous lisez ce message, l\'envoi ' . ($provider !== '' ? 'via ' . sms_provider_label($provider) : 'local') . ' fonctionne.';
$ok = sms_send($to, $body);

flash_set($ok
    ? ($provider !== ''
        ? 'SMS de test envoyé à ' . h($to) . ' via ' . sms_provider_label($provider) . ' — vérifiez sa réception sur ce téléphone.'
        : 'SMS de test écrit dans data/sms-outbox.log (hôte local, pas d\'envoi réel) pour ' . h($to) . '.')
    : 'Échec de l\'envoi du SMS de test : vérifiez les identifiants' . ($provider === 'ovh' ? ', l\'expéditeur et l\'IP autorisée chez OVH (voir le journal d\'erreur du serveur)' : '') . '.',
    $ok ? 'ok' : 'error');
header('Location: /admin/index.php#sms-test');
exit;
