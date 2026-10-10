<?php
// Connexion sociale — DÉPART : mémorise le nonce de la session puis envoie chez le fournisseur (voir includes/oauth.php).
// Contextes : signup (création de boutique, session de l'inscription), admin (administration de la boutique), customer (compte client).
$ctx = (string) ($_POST['ctx'] ?? $_GET['ctx'] ?? '');
if (!in_array($ctx, ['signup', 'admin', 'customer'], true)) {
    http_response_code(400);
    exit('Demande invalide.');
}
if ($ctx === 'signup') {
    require_once __DIR__ . '/../includes/saas.php';
    session_start();
} else {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
}
require_once __DIR__ . '/../includes/oauth.php';

/** Retour sur la page d'origine avec un message. */
function oauth_start_fail(string $ctx, string $message): never
{
    if ($ctx === 'signup') {
        $_SESSION['inscription_flash'] = $message;
        header('Location: /inscription/', true, 303);
    } elseif ($ctx === 'admin') {
        $_SESSION['login_error'] = $message;
        header('Location: /admin/login.php', true, 303);
    } else {
        $_SESSION['customer_error'] = $message;
        header('Location: /compte.php', true, 303);
    }
    exit;
}

$provider = (string) ($_POST['provider'] ?? $_GET['provider'] ?? '');
$creds = oauth_creds($provider, $ctx);
if (!$creds) {
    oauth_start_fail($ctx, "Cette méthode de connexion n'est pas disponible.");
}

if ($ctx === 'signup') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string) ($_SESSION['saas_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        oauth_start_fail($ctx, 'Le formulaire a expiré : réessayez.');
    }
    $plans = array_column(saas_config()['plans'], null, 'key');
    $plan = (string) ($_POST['plan'] ?? '');
    if (!isset($plans[$plan])) oauth_start_fail($ctx, 'Choisissez une formule.');
    if (empty($_POST['terms'])) oauth_start_fail($ctx, 'Acceptez les conditions (case à cocher) avant de continuer avec ' . OAUTH_PROVIDERS[$provider]['label'] . '.');
    $_SESSION['oauth_signup'] = ['plan' => $plan];
}
if ($ctx === 'customer') {
    // Page où revenir après la connexion : un chemin du site, jamais une adresse extérieure.
    $next = (string) ($_POST['next'] ?? $_GET['next'] ?? '');
    $_SESSION['oauth_next'] = preg_match('#^/(?!/)[A-Za-z0-9_./?=&%-]*$#', $next) ? $next : '/compte.php';
}

$nonce = bin2hex(random_bytes(16));
$_SESSION['oauth'] = ['nonce' => $nonce, 'ctx' => $ctx, 'provider' => $provider, 'at' => time()];
try {
    $state = oauth_sign(['n' => $nonce, 'o' => oauth_return_base(), 'p' => $provider, 'c' => $ctx, 'm' => $creds['mode']], 600, $creds['sign']);
} catch (RuntimeException $e) {
    oauth_start_fail($ctx, $e->getMessage());
}
header('Location: ' . oauth_authorize_url($provider, $state, $creds), true, 303);
exit;
