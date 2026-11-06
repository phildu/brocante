<?php

// Page publique d'inscription Stripe d'un commerce ou d'une galerie.
// Le lien envoyé par e-mail pointe ici (jeton stable) : chaque visite recrée un lien Stripe,
// car les liens d'inscription Stripe eux-mêmes expirent en quelques minutes.
define('PORTAIL_PUBLIC_ROUTE', true);
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/commissions.php';

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$token = strtolower((string) ($_GET['t'] ?? ''));
$key = cm_account_by_token($token);
$done = ($_GET['done'] ?? '') === '1';
$here = saas_origin() . '/portail/stripe-inscription.php?t=' . rawurlencode($token);
$name = '';
$ready = false;
$status = 200;

if ($key !== null) {
    $slug = substr($key, (int) strpos($key, ':') + 1);
    $item = str_starts_with($key, 'shop:') ? tenant_load($slug) : gallery_load($slug);
    $name = (string) ($item['name'] ?? $slug);
}

if ($key === null) {
    $status = 404;
    $title = 'Lien d’inscription invalide';
    $text = 'Ce lien n’est plus valable. Demandez un nouveau lien à votre contact Brocs.';
} elseif (cm_connect_ready($key)) {
    $ready = true;
    $title = 'Votre compte de paiement est déjà validé';
    $text = 'Aucune action n’est nécessaire. Pour modifier vos informations bancaires, utilisez le tableau de bord Stripe reçu par e-mail.';
} elseif ($done) {
    cm_account_refresh($key);
    $a = cm_connect()[$key] ?? [];
    $ready = ($a['status'] ?? '') === 'ready';
    $title = $ready ? 'Votre compte de paiement est validé' : 'Merci, votre dossier est transmis à Stripe';
    $text = $ready
        ? 'Stripe a validé vos informations : vos ventes en ligne peuvent vous être versées.'
        : 'Stripe vérifie vos informations. Vous recevrez un e-mail dès que votre compte sera validé (souvent sous 24 à 48 h ouvrées).';
} else {
    [$url] = cm_account_link($key, $here . '&done=1', $here);
    if ($url !== null) {
        header('Location: ' . $url, true, 303);
        exit;
    }
    $status = 502;
    $title = 'Inscription momentanément indisponible';
    $text = 'Réessayez dans quelques instants ou prévenez votre contact Brocs.';
}

http_response_code($status);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — Brocs</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<link rel="stylesheet" href="/assets/login.css">
</head>
<body class="login-body">
<main class="login-card">
  <div class="login-brand">
    <span class="mark" aria-hidden="true">B</span>
    <div><b>brocs</b><small>Paiements en ligne</small></div>
  </div>
  <h1><?= e($title) ?></h1>
  <?php if ($name !== ''): ?><p class="hint"><?= e($name) ?></p><?php endif; ?>
  <p class="hint"><?= e($text) ?></p>
  <?php if ($done && !$ready): ?><p class="login-foot">Vous pouvez fermer cette page. Si Stripe vous a demandé un justificatif, envoyez-le depuis le lien reçu par e-mail.</p><?php endif; ?>
</main>
</body>
</html>
