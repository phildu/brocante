<?php
// Jeton d'extension : généré dans l'admin, vérifié par l'agent_api (sans session).

require_once __DIR__ . '/settings.php';

/** Crée un nouveau jeton ; retourne le secret en clair (affiché une seule fois). */
function am_token_rotate(): string
{
    $token = 'am_' . bin2hex(random_bytes(24));
    am_setting_set('token_hash', hash('sha256', $token));
    am_setting_set('token_prefix', substr($token, 0, 10) . '…');
    am_setting_set('token_rotated_at', gmdate('c'));
    return $token;
}

/** Extrait le jeton Bearer / X-Annonces-Token de la requête courante. */
function am_request_token(): string
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return trim($m[1]);
    $header = (string) ($_SERVER['HTTP_X_ANNONCES_TOKEN'] ?? '');
    return trim($header);
}

/** Vérifie le jeton ; true si valide. */
function am_token_valid(?string $token = null): bool
{
    $token = $token ?? am_request_token();
    $hash = am_setting('token_hash');
    if ($token === '' || $hash === '' || !preg_match('/^am_[a-f0-9]{48}$/', $token)) return false;
    return hash_equals($hash, hash('sha256', $token));
}

/**
 * Refuse l'accès agent si jeton invalide ou option non souscrite.
 * Répond JSON et termine.
 */
function am_agent_require(callable $json): void
{
    if (!am_token_valid()) {
        $json(['error' => 'Jeton invalide ou manquant.'], 401);
    }
    if (!am_is_entitled()) {
        $json(['error' => 'Option non active : souscrivez depuis l\'administration de la boutique.', 'code' => 'not_entitled'], 402);
    }
}
