<?php

// Ouvre la session du commerce actif. Un commerce servi sous un préfixe
// d'adresse (brocs.arrimage.com/<slug>/) partage son domaine avec les autres :
// son cookie de session a donc un nom et un chemin propres, sinon le panier
// et la connexion admin d'un commerce se retrouveraient dans les autres.
// Sans préfixe (sous-domaine, .tenant), la session reste celle de toujours.
require_once __DIR__ . '/tenant.php';

if (session_status() === PHP_SESSION_NONE) {
    $base = tenant_base_path();
    if ($base !== '') {
        session_name('sess_' . tenant_slug());
        session_set_cookie_params([
            'path' => $base . '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }
    session_start();
}

// Redirections et liens préfixés, même pour les pages qui ne chargent pas config.php (ex. déconnexion).
tenant_enable_base_path();
