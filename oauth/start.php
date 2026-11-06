<?php
// Connexion sociale — DÉPART : mémorise le nonce de la session puis envoie chez le fournisseur (voir includes/oauth.php).
// Contextes : signup (création de boutique, session de l'inscription), admin (administration de la boutique), customer (compte client).
$ctx = (string) ($_POST['ctx'] ?? $_GET['ctx'] ?? '');
if (!in_array($ctx, ['signup', 'admin', 'customer', 'gadmin', 'gcreate', 'gvisitor'], true)) {
    http_response_code(400);
    exit('Demande invalide.');
}
$galleryCtx = str_starts_with($ctx, 'g');
if ($galleryCtx) {
    session_start();
    require_once __DIR__ . '/../includes/gallery-loyalty.php';
} elseif ($ctx === 'signup') {
    require_once __DIR__ . '/../includes/saas.php';
    require_once __DIR__ . '/../includes/branding-services.php';
    session_start();
} else {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
}
require_once __DIR__ . '/../includes/oauth.php';

/** Retour sur la page d'origine avec un message. */
function oauth_start_fail(string $ctx, string $message): never
{
    if (str_starts_with($ctx, 'g')) oauth_g_fail($ctx, $message);
    if ($ctx === 'signup') {
        $_SESSION['inscription_flash'] = $message;
        $req = saas_request_by_link((string) ($_POST['r'] ?? ''), (string) ($_POST['k'] ?? ''));
        if (!$req) {
            $signup = $_SESSION['oauth_signup'] ?? [];
            $req = saas_request_by_link((string) ($signup['request_id'] ?? ''), (string) ($signup['token'] ?? ''));
        }
        $target = $req && saas_request_active($req) && ($req['status'] ?? '') === 'draft'
            ? saas_configurateur_url($req, '')
            : '/inscription/';
        header('Location: ' . $target, true, 303);
    } elseif ($ctx === 'admin') {
        $_SESSION['login_error'] = $message;
        $back = ($_SESSION['oauth_next'] ?? '') === '/studio.php' ? '/studio.php' : '/admin/login.php';
        unset($_SESSION['oauth_next']);
        header('Location: ' . $back, true, 303);
    } elseif (($_SESSION['oauth_next'] ?? '') === '/visiteur.php') {        // connexion lancée depuis l'espace visiteur de la boutique
        unset($_SESSION['oauth_next']);
        $_SESSION['ev_oauth_error'] = $message;
        header('Location: /visiteur.php', true, 303);
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

if ($galleryCtx && $_SERVER['REQUEST_METHOD'] !== 'POST') oauth_start_fail($ctx, 'Demande invalide : réessayez.');
if ($ctx === 'gadmin' && !hash_equals((string) ($_SESSION['ga_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) oauth_start_fail($ctx, 'La page avait expiré : réessayez.');
if ($ctx === 'gcreate') {          // les champs du formulaire de création sont repris au retour (offre, nom, type, facturation)
    if (mb_strlen(trim((string) ($_POST['name'] ?? ''))) < 3) oauth_start_fail($ctx, "Donnez d'abord un nom à votre galerie (3 caractères au moins), puis choisissez un réseau social.");
    $_SESSION['oauth_gcreate'] = ['offre' => (string) ($_POST['offre'] ?? ''), 'name' => (string) ($_POST['name'] ?? ''), 'type' => (string) ($_POST['type'] ?? ''), 'billing' => (string) ($_POST['billing'] ?? '')];
}
if ($ctx === 'gvisitor') {
    $gv = strtolower((string) ($_POST['g'] ?? ''));
    $_SESSION['oauth_gvisitor'] = ['g' => $gv];
    $gal = gallery_slug_valid($gv) ? gallery_load($gv) : null;
    if (!$gal || !gl_open($gal)) oauth_start_fail($ctx, "L'espace visiteur n'est pas ouvert.");
    if (empty($_POST['consent'])) oauth_start_fail($ctx, "Cochez la case de consentement avant de continuer avec un réseau social.");
}
if ($ctx === 'signup') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string) ($_SESSION['saas_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        oauth_start_fail($ctx, 'Le formulaire a expiré : réessayez.');
    }
    if (empty($_POST['terms'])) oauth_start_fail($ctx, 'Acceptez les conditions (case à cocher) avant de continuer avec ' . OAUTH_PROVIDERS[$provider]['label'] . '.');
    $requestId = (string) ($_POST['r'] ?? '');
    $requestToken = (string) ($_POST['k'] ?? '');
    if ($requestId !== '' || $requestToken !== '') {
        $req = saas_request_by_link($requestId, $requestToken);
        if (!$req || ($req['status'] ?? '') !== 'draft' || !saas_request_active($req)) {
            oauth_start_fail($ctx, 'Cette demande n’est plus disponible : recommencez votre inscription.');
        }
        $shopName = trim((string) ($_POST['shop_name'] ?? ''));
        if ($shopName === '' || mb_strlen($shopName) > 60) oauth_start_fail($ctx, 'Indiquez un nom de boutique de 1 à 60 caractères.');
        try {
            $activity = branding_activity_from_post($_POST, (array) ($req['activity_details'] ?? []));
        } catch (InvalidArgumentException $ex) {
            oauth_start_fail($ctx, $ex->getMessage());
        }
        try {
            branding_activity_validate($activity);
        } catch (InvalidArgumentException $ex) {
            oauth_start_fail($ctx, $ex->getMessage());
        }
        $req['preferred_shop_name'] = $shopName;
        $req['about'] = mb_substr(trim((string) ($_POST['about'] ?? $req['about'] ?? '')), 0, 400);
        $req['location'] = mb_substr(trim((string) ($_POST['location'] ?? $req['location'] ?? '')), 0, 100);
        $req['activity_details'] = $activity;
        $req = array_merge($req, saas_owner_input($_POST, $req));
        if (($req['branding']['domain_choice']['name'] ?? '') !== $shopName) unset($req['branding']['domain_choice']);
        $availability = (array) ($req['branding']['step2']['availability'] ?? []);
        if (($availability['primary'] ?? '') !== trim(saas_slug($shopName), '-')) $req['branding']['step2']['availability'] = [];
        $req['terms_accepted'] = time();
        if (!saas_request_save($req)) oauth_start_fail($ctx, "Impossible d'enregistrer votre consentement : réessayez.");
        $_SESSION['oauth_signup'] = ['request_id' => $req['id'], 'token' => $req['token']];
    } else {
        $plans = array_column(saas_config()['plans'], null, 'key');
        $plan = (string) ($_POST['plan'] ?? '');
        if (!isset($plans[$plan])) oauth_start_fail($ctx, 'Choisissez une formule.');
        $_SESSION['oauth_signup'] = ['plan' => $plan];
    }
}
if ($ctx === 'admin') {
    // Seule autre page d'arrivée possible : le Studio (sa propre fenêtre de connexion).
    if (($_POST['next'] ?? $_GET['next'] ?? '') === '/studio.php') $_SESSION['oauth_next'] = '/studio.php';
    else unset($_SESSION['oauth_next']);
}
if ($ctx === 'customer') {
    // Page où revenir après la connexion : un chemin du site, jamais une adresse extérieure.
    $next = (string) ($_POST['next'] ?? $_GET['next'] ?? '');
    $_SESSION['oauth_next'] = preg_match('#^/(?!/)[A-Za-z0-9_./?=&%-]*$#', $next) ? $next : '/compte.php';
    // Espace visiteur (le lien a pu être préfixé par le chemin de la boutique : /naty/visiteur.php) : forme canonique, puis accord exigé avant le clic.
    if (preg_match('#^(?:/[a-z0-9_-]+)?/visiteur\.php$#i', $_SESSION['oauth_next'])) $_SESSION['oauth_next'] = '/visiteur.php';
    if ($_SESSION['oauth_next'] === '/visiteur.php' && empty($_POST['consent'])) oauth_start_fail($ctx, "Cochez la case de consentement avant de continuer avec un réseau social.");
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
