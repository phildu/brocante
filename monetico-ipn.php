<?php

// Confirmation serveur Monetico (« URL de confirmation CGI2 », configurée
// dans le back-office Monetico). Pas de session ici : l'authenticité vient du
// MAC. Réponse attendue par Monetico : « version=2 » puis « cdr=0 » (reçu).
// cdr=1 demande une nouvelle tentative.

require_once __DIR__ . '/includes/functions.php';

$answer = static function (bool $accepted): void {
    header('Content-Type: text/plain; charset=utf-8');
    echo "version=2\ncdr=" . ($accepted ? '0' : '1') . "\n";
    exit;
};

$config = monetico_config();
if (!$config) $answer(false);

$check = monetico_validate_response($_POST, $config);
if (!$check['ok']) {
    error_log('Monetico IPN refusé : ' . $check['message']);
    if (!empty($check['order'])) {
        order_event((int) $check['order']['id'], 'paiement_echec', $check['message'], 'monetico');
    }
    $answer(false);
}

$order = $check['order'];
if ($check['paid']) {
    $detail = trim(implode(' ', array_filter([
        $check['brand'] ?? '',
        $check['masked_card'] ?? '',
        $check['payment_method'] ?? '',
    ])));
    $paymentId = (string) (($check['authorization'] ?? '') !== '' ? $check['authorization'] : ($order['payment_reference'] ?? ''));
    order_finalize_external($order, 'monetico', $paymentId, (string) ($check['authorization'] ?? ''), $detail);
    $answer(true);
}

if ($order['status'] === 'pending') {
    order_event((int) $order['id'], 'paiement_echec', $check['message'], 'monetico');
}
$answer(true);
