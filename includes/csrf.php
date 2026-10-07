<?php

/**
 * Génère un token CSRF unique pour la session
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valide un token CSRF soumis via un formulaire POST
 */
function validate_csrf(): bool
{
    if (!isset($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Génère un input hidden avec le token CSRF (pour les formulaires)
 */
function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Vérifie et valide le token CSRF, puis arrête l'exécution si invalide
 */
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validate_csrf()) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
}
