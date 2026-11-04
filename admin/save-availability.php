<?php
// Validation de la disponibilité avant paiement (Administration → Réglages du site → Paiement).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php#availability-settings');
    exit;
}
admin_csrf_check();

$enabled = !empty($_POST['oa_enabled']);
$channel = (string) ($_POST['oa_channel'] ?? 'auto');
if (!in_array($channel, ['auto', 'sms', 'whatsapp', 'email'], true)) $channel = 'auto';
$phone = trim((string) ($_POST['oa_phone'] ?? ''));
$hours = (int) ($_POST['oa_validity_hours'] ?? 48);
if ($hours < 6 || $hours > 168) $hours = 48;

shop_setting_set('oa_enabled', $enabled ? '1' : '0');
shop_setting_set('oa_channel', $channel);
shop_setting_set('oa_phone', mb_substr($phone, 0, 40));
shop_setting_set('oa_validity_hours', (string) $hours);

$message = $enabled
    ? 'Validation avant paiement activée : chaque commande attend votre réponse (' . oa_channel_label($channel) . ').'
    : 'Validation avant paiement désactivée : le paiement reprend immédiatement.';
$kind = 'ok';
if ($enabled && $channel === 'whatsapp' && !oa_whatsapp_ready()) {
    $message .= ' WhatsApp n’est pas configuré — les alertes partiront par e-mail.';
    $kind = 'error';
} elseif ($enabled && $channel === 'sms' && !oa_sms_ready()) {
    $message .= ' Aucun fournisseur SMS n’est complet — les alertes partiront par e-mail.';
    $kind = 'error';
} elseif ($enabled && oa_seller_phone() === '') {
    $message .= ' Aucun numéro de vendeur n’est renseigné — seules les alertes e-mail partiront.';
    $kind = 'error';
}

flash_set($message, $kind);
header('Location: /admin/index.php#availability-settings');
exit;
