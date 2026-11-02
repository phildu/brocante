<?php
// Passage d'un espace à l'autre après la connexion unique (/connexion.php) :
// le jeton à usage unique est présenté ici, dans l'adresse de l'espace visé,
// pour ouvrir la session qui convient (boutique, galerie, portail) puis
// rediriger vers sa page d'accueil. Voir includes/unified-auth.php.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/unified-auth.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer'); // le jeton est dans l'adresse : jamais transmis à un autre site
tenant_enable_base_path(); // redirections préfixées (/<identifiant>/connexion.php, /admin/…) quand la page est servie sous un préfixe

$token = (string) ($_GET['t'] ?? '');
$identity = ua_ticket_peek($token);
if ($identity === null) {
    header('Location: /connexion.php?expire=1', true, 303);
    exit;
}

// Le jeton reste valable : on le présente à l'adresse de l'espace visé, où la session lui appartient.
// Vérifié avant d'ouvrir la moindre session, pour qu'un passage par la mauvaise adresse ne pose pas de cookie inutile.
if (!ua_context_matches($identity)) {
    header('Location: ' . ua_sso_url($identity) . '?t=' . rawurlencode($token), true, 303);
    exit;
}

// Chaque espace garde sa session : celle du commerce (data/sessions, nommée
// sess_<identifiant> quand le commerce est servi sous un préfixe) ou celle du
// portail et des galeries (PHPSESSID par défaut, comme leurs pages).
if (($identity['space'] ?? '') === 'shop') {
    require_once __DIR__ . '/includes/session.php';
} elseif (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

ua_ticket_redeem($token);
ua_open_session($identity);
header('Location: ' . ua_destination($identity));
exit;
