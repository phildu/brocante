<?php
// Connexion sociale — ARRIVÉE sur la boutique : vérifie le justificatif signé (destinataire, durée, nonce de CETTE session, usage unique),
// puis termine l'action : création de boutique (signup), ouverture de l'administration (admin) ou du compte client (customer).
require_once __DIR__ . '/../includes/oauth.php';

$claims = oauth_verify_any((string) ($_GET['a'] ?? ''));
$ctx = (string) ($claims['c'] ?? '');
if (!$claims || !in_array($ctx, ['signup', 'admin', 'customer', 'gadmin', 'gcreate', 'gvisitor'], true) || (in_array($ctx, OAUTH_PLATFORM_CTX, true) && ($claims['m'] ?? '') !== 'platform')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Ce lien de connexion a expiré ou n'est pas valable : revenez sur le site et recommencez.");
}
$galleryCtx = str_starts_with($ctx, 'g');
if ($galleryCtx) {
    session_start();
    require_once __DIR__ . '/../includes/gallery-loyalty.php';
} elseif ($ctx === 'signup') {
    require_once __DIR__ . '/../includes/saas.php';
    session_start();
} else {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
}

function oauth_finish_fail(string $ctx, string $message): never
{
    if (str_starts_with($ctx, 'g')) oauth_g_fail($ctx, $message);
    if ($ctx === 'signup') {
        $_SESSION['inscription_flash'] = $message;
        $signup = $_SESSION['oauth_signup'] ?? [];
        $req = saas_request_by_link((string) ($signup['request_id'] ?? ''), (string) ($signup['token'] ?? ''));
        $target = $req && saas_request_active($req) && ($req['status'] ?? '') === 'draft'
            ? '/inscription/paiement.php?r=' . rawurlencode((string) $req['id']) . '&k=' . rawurlencode((string) $req['token'])
            : '/inscription/';
        unset($_SESSION['oauth_signup']);
        header('Location: ' . $target, true, 303);
    } elseif ($ctx === 'admin') {
        $_SESSION['login_error'] = $message;
        $back = ($_SESSION['oauth_next'] ?? '') === '/studio.php' ? '/studio.php' : '/admin/login.php';
        unset($_SESSION['oauth_next']);
        header('Location: ' . $back, true, 303);
    } elseif (($_SESSION['oauth_next'] ?? '') === '/visiteur.php') {
        unset($_SESSION['oauth_next']);
        $_SESSION['ev_oauth_error'] = $message;
        header('Location: /visiteur.php', true, 303);
    } else {
        $_SESSION['customer_error'] = $message;
        header('Location: /compte.php', true, 303);
    }
    exit;
}

// Le justificatif doit être destiné à cette boutique et répondre à la connexion lancée depuis CETTE session (une seule fois).
$mine = $_SESSION['oauth'] ?? null;
unset($_SESSION['oauth']);
if (strtolower((string) ($claims['aud'] ?? '')) !== strtolower(oauth_return_base())
    || !is_array($mine) || !hash_equals((string) $mine['nonce'], (string) ($claims['n'] ?? '')) || ($mine['ctx'] ?? '') !== $ctx || time() - (int) ($mine['at'] ?? 0) > 900) {
    oauth_finish_fail($ctx, 'La connexion n\'a pas pu être vérifiée : recommencez.');
}
if (!empty($claims['err'])) oauth_finish_fail($ctx, (string) $claims['err']);

$email = strtolower((string) $claims['email']);
$verified = !empty($claims['v']);
$provider = (string) ($claims['p'] ?? '');
$label = OAUTH_PROVIDERS[$provider]['label'] ?? $provider;
$name = trim(((string) ($claims['fn'] ?? '')) . ' ' . ((string) ($claims['ln'] ?? '')));

if ($ctx === 'signup') {
    if (!$verified) oauth_finish_fail($ctx, "$label n'a pas confirmé que l'adresse $email vous appartient : utilisez un autre compte ou une autre méthode.");
    $signup = $_SESSION['oauth_signup'] ?? [];
    if (!empty($signup['request_id']) && !empty($signup['token'])) {
        $req = saas_request_by_link((string) $signup['request_id'], (string) $signup['token']);
        if (!$req || ($req['status'] ?? '') !== 'draft' || !saas_request_active($req)) {
            oauth_finish_fail($ctx, 'Cette demande n’est plus disponible : recommencez votre inscription.');
        }
        if (empty($req['terms_accepted'])) oauth_finish_fail($ctx, 'Votre consentement n’a pas été enregistré : recommencez depuis la page de configuration.');
        $req['email'] = $email;
        $req['contact_method'] = 'google';
        $req['social'] = ['provider' => $provider, 'verified' => true];
        $req['first_name'] = (string) ($claims['fn'] ?? '');
        $req['last_name'] = (string) ($claims['ln'] ?? '');
        if (!saas_request_save($req)) oauth_finish_fail($ctx, "Impossible d'enregistrer votre compte Google : réessayez.");
        unset($_SESSION['oauth_signup']);
        $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
        header('Location: ' . saas_design_url($req, saas_origin()), true, 303);
        exit;
    }
    // Anciennes sessions OAuth de création, conservées pendant la transition du parcours.
    $plan = (string) ($signup['plan'] ?? '');
    unset($_SESSION['oauth_signup']);
    $plansByKey = array_column(saas_config()['plans'], null, 'key');
    if (!isset($plansByKey[$plan])) oauth_finish_fail($ctx, 'Choisissez une formule.');
    if (saas_rate_limited()) oauth_finish_fail($ctx, 'Trop de demandes depuis votre connexion : réessayez dans une heure.');
    if ((saas_plan_cents($plansByKey[$plan]) ?? 0) === 0 && saas_free_cap_reached()) {
        oauth_finish_fail($ctx, "Les créations de boutiques gratuites sont complètes pour aujourd'hui : revenez demain, ou choisissez une formule payante.");
    }
    $res = saas_request_start(['email' => $email, 'plan' => $plan, 'terms' => '1']);
    if (!$res['ok']) oauth_finish_fail($ctx, implode(' ', $res['errors']));
    $req = $res['request'];
    $req['social'] = ['provider' => $provider, 'verified' => $verified];
    $req['first_name'] = (string) ($claims['fn'] ?? '');
    $req['last_name'] = (string) ($claims['ln'] ?? '');
    saas_request_save($req);
    $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
    header('Location: ' . saas_after_start_url($req, saas_origin()), true, 303);
    exit;
}

if (!$verified) {
    oauth_finish_fail($ctx, "$label n'a pas confirmé que l'adresse $email vous appartient : utilisez un autre compte ou une autre méthode.");
}

if ($ctx === 'gadmin') {            // administrateur de galerie : l'adresse doit être celle d'un administrateur existant
    if ($err = ga_login_social($email)) oauth_finish_fail($ctx, $err);
    header('Location: /galerie/admin.php', true, 303);   // reprend la galerie retenue au départ ($_SESSION['ga_target'], voir galerie/admin.php)
    exit;
}
if ($ctx === 'gcreate') {           // création de galerie : l'adresse vérifiée remplace la saisie ; creer.php reprend la demande mémorisée au départ
    $_SESSION['oauth_gcreate_email'] = ['email' => $email, 'at' => time()];
    header('Location: /galerie/creer.php?social=1', true, 303);
    exit;
}
if ($ctx === 'gvisitor') {          // visiteur : membre créé ou retrouvé, puis ouverture de son espace avec son lien personnel
    $slug = (string) ($_SESSION['oauth_gvisitor']['g'] ?? '');
    unset($_SESSION['oauth_gvisitor']);
    $gal = gallery_slug_valid($slug) ? gallery_load($slug) : null;
    $tok = $gal ? gl_social_login($gal, $email, (string) ($claims['fn'] ?? '')) : null;
    if (!$tok) oauth_finish_fail($ctx, "L'espace visiteur n'est pas disponible pour le moment.");
    header('Location: ' . gl_app_url($slug, $tok), true, 303);
    exit;
}
if ($ctx === 'admin') {
    // Le compte principal du commerce (son e-mail d'identifiant) ou un compte de l'équipe portant cette adresse e-mail.
    $login = null;
    if (strtolower(trim((string) tenant('admin_user'))) === $email) {
        $login = ['role' => 'admin', 'id' => null];
    } elseif ($account = account_staff_by_email($email)) {
        account_touch_login((int) $account['id']);
        $login = ['role' => $account['role'], 'id' => (int) $account['id']];
    }
    if (!$login) oauth_finish_fail($ctx, "Aucun compte d'administration ne correspond à $email : connectez-vous avec l'identifiant et le mot de passe, ou demandez à un administrateur de vous ajouter.");
    session_regenerate_id(true);
    $_SESSION['is_admin'] = tenant_slug();
    if ($login['id'] === null) unset($_SESSION['admin_account_id']);
    else $_SESSION['admin_account_id'] = $login['id'];
    // Connexion lancée depuis le Studio : on y revient ; sinon, page d'arrivée de l'administration.
    $toStudio = ($_SESSION['oauth_next'] ?? '') === '/studio.php';
    unset($_SESSION['oauth_next']);
    if ($toStudio && defined('STUDIO_REMEMBER_DAYS')) {
        // Comme la connexion du Studio : le téléphone reste connecté.
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + STUDIO_REMEMBER_DAYS * 86400, 'path' => $params['path'] ?: '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    header('Location: ' . ($toStudio ? '/studio.php' : admin_home_for_role($login['role'])), true, 303);
    exit;
}

// customer, depuis l'espace visiteur (plugin) : membre créé ou retrouvé, puis ouverture de son espace avec son lien personnel.
if (($_SESSION['oauth_next'] ?? '') === '/visiteur.php') {
    unset($_SESSION['oauth_next']);
    require_once __DIR__ . '/../includes/plugins.php';
    $to = plugin_filter('visiteur_social', '', ['email' => $email, 'name' => (string) ($claims['fn'] ?? '')]);
    if (!is_string($to) || $to === '') {
        $_SESSION['oauth_next'] = '/visiteur.php';
        oauth_finish_fail($ctx, "L'espace visiteur n'est pas disponible pour le moment.");
    }
    header('Location: ' . $to, true, 303);
    exit;
}

// customer : le visiteur devient un contact de la boutique (client dès sa première commande payée).
$next = (string) ($_SESSION['oauth_next'] ?? '/compte.php');
unset($_SESSION['oauth_next']);
account_upsert_contact($email, $name, 'prospect', 'connexion');
session_regenerate_id(true);
$_SESSION['customer'] = ['tenant' => tenant_slug(), 'email' => $email, 'name' => $name, 'provider' => $provider, 'at' => time()];
header('Location: ' . $next, true, 303);
exit;
