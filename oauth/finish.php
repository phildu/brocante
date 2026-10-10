<?php
// Connexion sociale — ARRIVÉE sur la boutique : vérifie le justificatif signé (destinataire, durée, nonce de CETTE session, usage unique),
// puis termine l'action : création de boutique (signup), ouverture de l'administration (admin) ou du compte client (customer).
require_once __DIR__ . '/../includes/oauth.php';

$claims = oauth_verify_any((string) ($_GET['a'] ?? ''));
$ctx = (string) ($claims['c'] ?? '');
if (!$claims || !in_array($ctx, ['signup', 'admin', 'customer'], true) || ($ctx === 'signup' && ($claims['m'] ?? '') !== 'platform')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Ce lien de connexion a expiré ou n'est pas valable : revenez sur le site et recommencez.");
}
if ($ctx === 'signup') {
    require_once __DIR__ . '/../includes/saas.php';
    session_start();
} else {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
}

function oauth_finish_fail(string $ctx, string $message): never
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
    // Même chemin que l'étape 1 du formulaire : l'adresse e-mail vient du fournisseur (pas de saisie, pas de mot de passe à créer).
    $plan = (string) ($_SESSION['oauth_signup']['plan'] ?? '');
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
    header('Location: ' . admin_home_for_role($login['role']), true, 303);
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
