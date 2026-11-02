<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php?s=paiement');
    exit;
}
admin_csrf_check();

// Un champ laissé vide conserve la valeur déjà enregistrée : on peut modifier
// un réglage sans recoller la clé ou le secret à chaque fois.
$errors = [];
$messages = [];

// ── Monetico ─────────────────────────────────────────────────────────────
$monetico = payment_config('monetico') ?? [];
$tpe = trim((string) ($_POST['monetico_tpe'] ?? ''));
$societe = trim((string) ($_POST['monetico_societe'] ?? ''));
$key = trim((string) ($_POST['monetico_key'] ?? ''));
$moneticoMode = (string) ($_POST['monetico_mode'] ?? 'test');

if ($tpe !== '') {
    if (!preg_match('/^[0-9]{7}$/', $tpe)) $errors[] = 'Le numéro de TPE Monetico doit comporter exactement 7 chiffres.';
    else $monetico['tpe'] = $tpe;
}
if ($societe !== '') $monetico['societe'] = mb_substr($societe, 0, 8);
if ($key !== '') {
    if (!preg_match('/^[A-Fa-f0-9]{40}$/', $key)) $errors[] = 'La clé Monetico doit comporter 40 caractères hexadécimaux.';
    else $monetico['key'] = strtoupper($key);
}
$monetico['mode'] = in_array($moneticoMode, ['test', 'production'], true) ? $moneticoMode : 'test';
unset($monetico['api_base']);

if (($monetico['tpe'] ?? '') !== '' || ($monetico['key'] ?? '') !== '' || ($monetico['societe'] ?? '') !== '') {
    if (!payment_config_save('monetico', $monetico)) {
        $errors[] = 'Impossible d’enregistrer les réglages Monetico (dossier .secrets non inscriptible).';
    } else {
        $messages[] = 'Monetico enregistré';
    }
}

// ── PayPal ───────────────────────────────────────────────────────────────
$paypal = payment_config('paypal') ?? [];
$clientId = trim((string) ($_POST['paypal_client_id'] ?? ''));
$paypalSecret = trim((string) ($_POST['paypal_secret'] ?? ''));
$paypalMode = (string) ($_POST['paypal_mode'] ?? 'sandbox');

if ($clientId !== '') $paypal['client_id'] = $clientId;
if ($paypalSecret !== '') $paypal['secret'] = $paypalSecret;
$paypal['mode'] = in_array($paypalMode, ['sandbox', 'live'], true) ? $paypalMode : 'sandbox';
unset($paypal['api_base']);

if (($paypal['client_id'] ?? '') !== '' || ($paypal['secret'] ?? '') !== '') {
    if (!payment_config_save('paypal', $paypal)) {
        $errors[] = 'Impossible d’enregistrer les réglages PayPal (dossier .secrets non inscriptible).';
    } else {
        $messages[] = 'PayPal enregistré';
    }
}

// ── Moyens proposés au client ────────────────────────────────────────────
// La liste enregistrée ne contient que des fournisseurs réellement configurés.
// Une valeur sentinelle « - » signifie « aucun » (une chaîne vide, elle, veut
// dire « tout ce qui est configuré » pour les boutiques plus anciennes).
$available = shop_payment_methods_available();
$selected = [];
foreach ((array) ($_POST['payment_methods'] ?? []) as $method) {
    if (is_string($method) && isset($available[$method])) $selected[] = $method;
}
shop_setting_set('payment_methods', $selected ? implode(',', array_unique($selected)) : '-');

$messages[] = $selected ? 'moyens actifs : ' . implode(', ', $available ? array_intersect_key($available, array_flip($selected)) : []) : 'aucun moyen actif (paiement désactivé)';

if ($errors) {
    flash_set(implode(' ', $errors) . ($messages ? ' ' . implode(' ; ', $messages) . '.' : ''), 'error');
} else {
    flash_set(ucfirst(implode(' ; ', $messages)) . '.', 'ok');
}

header('Location: /admin/index.php?s=paiement#payment-settings');
exit;
