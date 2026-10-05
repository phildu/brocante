<?php

// Ouvre la session du commerce actif. Un commerce servi sous un préfixe
// d'adresse (brocs.arrimage.com/<slug>/) partage son domaine avec les autres :
// son cookie de session a donc un nom et un chemin propres, sinon le panier
// et la connexion admin d'un commerce se retrouveraient dans les autres.
// Sans préfixe (sous-domaine, .tenant), la session reste celle de toujours.
require_once __DIR__ . '/tenant.php';

if (session_status() === PHP_SESSION_NONE) {
    // Fichiers de session dans data/ (jamais servi), conservés assez longtemps pour la connexion
    // mémorisée du Studio : le répertoire système est purgé au bout de ~24 min d'inactivité.
    // Le cookie, lui, reste « jusqu'à la fermeture du navigateur » sauf demande contraire.
    $sessionDir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        ini_set('session.save_path', $sessionDir);
        ini_set('session.gc_maxlifetime', (string) (31 * 86400));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
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
