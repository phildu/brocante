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

// ── Revolut Merchant ─────────────────────────────────────────────────────
$revolut = payment_config('revolut') ?? [];
$revolutKey = trim((string) ($_POST['revolut_api_key'] ?? ''));
$revolutWebhook = trim((string) ($_POST['revolut_webhook_secret'] ?? ''));
$revolutMode = (string) ($_POST['revolut_mode'] ?? 'sandbox');

if ($revolutKey !== '') $revolut['api_key'] = $revolutKey;
if ($revolutWebhook !== '') $revolut['webhook_secret'] = $revolutWebhook;
$revolut['mode'] = in_array($revolutMode, ['sandbox', 'production'], true) ? $revolutMode : 'sandbox';
unset($revolut['api_base']);

if (($revolut['api_key'] ?? '') !== '') {
    if (!payment_config_save('revolut', $revolut)) {
        $errors[] = 'Impossible d’enregistrer les réglages Revolut (dossier .secrets non inscriptible).';
    } else {
        $messages[] = 'Revolut enregistré';
    }
}

// ── SumUp ────────────────────────────────────────────────────────────────
$sumup = payment_config('sumup') ?? [];
$sumupKey = trim((string) ($_POST['sumup_api_key'] ?? ''));
$sumupMerchant = strtoupper(trim((string) ($_POST['sumup_merchant_code'] ?? '')));

if ($sumupKey !== '') $sumup['api_key'] = $sumupKey;
if ($sumupMerchant !== '' && !preg_match('/^[A-Z0-9]{4,16}$/', $sumupMerchant)) {
    $errors[] = 'Le code marchand SumUp est invalide (4 à 16 lettres ou chiffres, ex. MH4H92C7).';
} elseif ($sumupMerchant !== '') {
    $sumup['merchant_code'] = $sumupMerchant;
}
unset($sumup['api_base']);

if (($sumup['api_key'] ?? '') !== '' || ($sumup['merchant_code'] ?? '') !== '') {
    if (!payment_config_save('sumup', $sumup)) {
        $errors[] = 'Impossible d’enregistrer les réglages SumUp (dossier .secrets non inscriptible).';
    } elseif (!sumup_configured()) {
        $messages[] = 'SumUp inactif (clé API ou code marchand manquant)';
    } else {
        $messages[] = 'SumUp enregistré';
    }
}

// ── Wero (via Stripe) ────────────────────────────────────────────────────
shop_setting_set('stripe_wero', !empty($_POST['stripe_wero']) ? '1' : '0');

// ── Virement SEPA ────────────────────────────────────────────────────────
$sepaOld = sepa_config();
$sepaHolder = trim((string) ($_POST['sepa_holder'] ?? ''));
$sepaIbanRaw = trim((string) ($_POST['sepa_iban'] ?? ''));
$sepaIban = sepa_iban_normalize($sepaIbanRaw);
$sepaBic = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['sepa_bic'] ?? '')) ?? '');
$sepaBank = trim((string) ($_POST['sepa_bank'] ?? ''));
$sepaDelay = (int) ($_POST['sepa_delay_days'] ?? 7);
$sepaNote = trim((string) ($_POST['sepa_note'] ?? ''));
$sepaOk = true;

if ($sepaIban !== '' && !sepa_iban_valid($sepaIban)) {
    $errors[] = 'L’IBAN du virement SEPA est invalide (vérifiez la saisie, espaces acceptés).';
    $sepaOk = false;
}
if ($sepaIban !== '' && $sepaHolder === '') {
    $errors[] = 'Indiquez le titulaire du compte pour le virement SEPA.';
    $sepaOk = false;
}
if (!sepa_bic_valid($sepaBic)) {
    $errors[] = 'Le BIC doit comporter 8 ou 11 caractères (lettres et chiffres).';
    $sepaOk = false;
}
if (!in_array($sepaDelay, range(1, 30), true)) $sepaDelay = $sepaOld['delay_days'];
if (mb_strlen($sepaNote) > 300) {
    $errors[] = 'La consigne du virement SEPA est limitée à 300 caractères.';
    $sepaOk = false;
}
if ($sepaOk) {
    shop_setting_set('sepa_holder', mb_substr($sepaHolder, 0, 80));
    shop_setting_set('sepa_iban', $sepaIban);
    shop_setting_set('sepa_bic', $sepaBic);
    shop_setting_set('sepa_bank', mb_substr($sepaBank, 0, 80));
    shop_setting_set('sepa_delay_days', (string) $sepaDelay);
    shop_setting_set('sepa_instructions', mb_substr($sepaNote, 0, 300));
    $messages[] = sepa_configured() ? 'virement SEPA activé' : 'virement SEPA inactif (titulaire ou IBAN manquant)';
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
