<?php

// Ouvre la session du commerce actif. Un commerce servi sous un préfixe
// d'adresse (brocs.arrimage.com/<slug>/) partage son domaine avec les autres :
// son cookie de session a donc un nom et un chemin propres, sinon le panier
// et la connexion admin d'un commerce se retrouveraient dans les autres.
// Sans préfixe (sous-domaine, .tenant), le cookie garde son nom et son chemin habituels.
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
    // Cookie en SameSite=Lax pour tous les commerces : un formulaire envoyé depuis un autre site arrive sans session (ni panier,
    // ni connexion admin), ce qui protège aussi les actions de l'administration qui n'ont pas de jeton propre. Un lien suivi
    // depuis un autre site (retour de Stripe, connexion sociale) garde la session.
    $cookie = [
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ];
    $base = tenant_base_path();
    if ($base !== '') {
        session_name('sess_' . tenant_slug());
        $cookie['path'] = $base . '/';
    }
    session_set_cookie_params($cookie);
    session_start();
}

// Redirections et liens préfixés, même pour les pages qui ne chargent pas config.php (ex. déconnexion).
tenant_enable_base_path();
