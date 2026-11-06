<?php

/**
 * Génère un token CSRF unique pour la session
 */
function shop_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valide un token CSRF soumis via un formulaire POST
 */
function shop_validate_csrf(): bool
{
    if (!isset($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Génère un input hidden avec le token CSRF (pour les formulaires)
 */
function shop_csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(shop_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Vérifie et valide le token CSRF, puis arrête l'exécution si invalide
 */
function shop_require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !shop_validate_csrf()) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
}

/*
 * Noms courts utilisés par le module Notifications (formulaires admin et page publique
 * de préférences) : alias des fonctions shop_*, avec le même jeton de session.
 */
if (!function_exists('csrf_input')) {
    function csrf_input(): string
    {
        return shop_csrf_input();
    }
}
if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        shop_require_csrf();
    }
}
