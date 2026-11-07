<?php

// Appareil de confiance : connexion administrateur « permanente » sur une machine choisie.
//
// Un jeton aléatoire (sélecteur + validateur) est mémorisé dans un cookie ; seule l'empreinte du validateur est
// gardée en base. Contrairement au cookie de session, il ne dépend d'aucun fichier de session (purge, redémarrage
// du serveur, changement de navigateur) et se révoque : déconnexion, changement de mot de passe, liste des appareils.
// Le validateur change à chaque usage ; un validateur rejoué (cookie volé puis copié) révoque l'appareil.

const REMEMBER_DAYS = 400;   // plafond des navigateurs pour la durée d'un cookie ; renouvelé à chaque usage

function remember_cookie_name(): string
{
    return 'trusted_' . (function_exists('tenant_slug') ? tenant_slug() : 'shop');
}

function remember_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_remember (
        selector TEXT PRIMARY KEY,
        validator_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        account_id INTEGER,
        label TEXT NOT NULL DEFAULT \'\',
        created INTEGER NOT NULL,
        last_used INTEGER NOT NULL,
        expires INTEGER NOT NULL
    )');
}

/** Libellé lisible d'un appareil, d'après son user-agent (« Safari · macOS »). */
function remember_device_label(string $userAgent): string
{
    $browser = 'Navigateur';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
        if (stripos($userAgent, $needle) !== false) { $browser = $name; break; }
    }
    $os = '';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Mac OS' => 'macOS', 'Windows' => 'Windows', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($userAgent, $needle) !== false) { $os = $name; break; }
    }
    return $os !== '' ? $browser . ' · ' . $os : $browser;
}

/** Enregistre un appareil de confiance. Retourne la valeur du cookie « sélecteur:validateur » et sa date d'expiration. */
function remember_issue(PDO $pdo, string $role, ?int $accountId, string $userAgent = '', ?int $now = null): array
{
    remember_ensure_schema($pdo);
    $now ??= time();
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = $now + REMEMBER_DAYS * 86400;
    $pdo->prepare('INSERT INTO admin_remember (selector, validator_hash, role, account_id, label, created, last_used, expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$selector, hash('sha256', $validator), $role, $accountId, remember_device_label($userAgent), $now, $now, $expires]);
    return ['value' => $selector . ':' . $validator, 'expires' => $expires];
}

/**
 * Vérifie le cookie d'un appareil de confiance. Retourne ['role' => …, 'id' => …, 'value' => nouveau cookie, 'expires' => …]
 * (le validateur est renouvelé), ou null si le jeton est inconnu, expiré ou rejoué (l'appareil est alors révoqué).
 */
function remember_consume(PDO $pdo, string $cookie, ?int $now = null): ?array
{
    $now ??= time();
    if (!preg_match('/^([0-9a-f]{18}):([0-9a-f]{64})$/', $cookie, $m)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM admin_remember WHERE selector = ?');
        $stmt->execute([$m[1]]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;   // table pas encore créée : aucun appareil enregistré
    }
    if (!$row) {
        return null;
    }
    if ((int) $row['expires'] < $now) {
        remember_revoke($pdo, $m[1]);
        return null;
    }
    if (!hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        remember_revoke($pdo, $m[1]);   // validateur rejoué : cookie probablement copié
        return null;
    }
    $validator = bin2hex(random_bytes(32));
    $expires = $now + REMEMBER_DAYS * 86400;
    $pdo->prepare('UPDATE admin_remember SET validator_hash = ?, last_used = ?, expires = ? WHERE selector = ?')
        ->execute([hash('sha256', $validator), $now, $expires, $m[1]]);
    return [
        'role' => (string) $row['role'],
        'id' => $row['account_id'] === null ? null : (int) $row['account_id'],
        'value' => $m[1] . ':' . $validator,
        'expires' => $expires,
    ];
}

function remember_revoke(PDO $pdo, string $selector): void
{
    try {
        $pdo->prepare('DELETE FROM admin_remember WHERE selector = ?')->execute([$selector]);
    } catch (Throwable $e) {
    }
}

/** Révoque l'appareil du cookie fourni (déconnexion). */
function remember_revoke_cookie(PDO $pdo, string $cookie): void
{
    if (preg_match('/^([0-9a-f]{18}):/', $cookie, $m)) {
        remember_revoke($pdo, $m[1]);
    }
}

/** Révoque tous les appareils d'un compte (null = compte principal), ex. après un changement de mot de passe. */
function remember_revoke_all(PDO $pdo, ?int $accountId): int
{
    try {
        $stmt = $accountId === null
            ? $pdo->prepare('DELETE FROM admin_remember WHERE account_id IS NULL')
            : $pdo->prepare('DELETE FROM admin_remember WHERE account_id = ?');
        $stmt->execute($accountId === null ? [] : [$accountId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Dépose (ou efface avec $expires = 0) le cookie d'appareil de confiance. */
function remember_set_cookie(string $value, int $expires): void
{
    $base = function_exists('tenant_base_path') ? tenant_base_path() : '';
    setcookie(remember_cookie_name(), $value, [
        'expires' => $expires,
        'path' => $base !== '' ? $base . '/' : '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
