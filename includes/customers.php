<?php

// Comptes clients de la boutique (e-mail + mot de passe), en plus de la connexion par réseau social : table accounts de la boutique, rôles
// « prospect » / « client » (jamais un compte de l'équipe). L'adresse e-mail est TOUJOURS vérifiée par un lien envoyé à la boutique choisie :
// le mot de passe n'est enregistré qu'au clic sur ce lien (création, mot de passe oublié, ou case « créer mon compte » du panier).
// Jetons à usage unique (empreinte SHA-256 en base, valables 24 h) ; essais de connexion et demandes de lien limités.

const CUSTOMER_TOKEN_TTL = 86400;
const CUSTOMER_LOGIN_MAX = 8;            // essais de connexion par quart d'heure (par adresse e-mail et par connexion)
const CUSTOMER_LINK_MAX = 5;             // liens demandés par heure et par connexion

function customers_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_tokens (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      token_hash TEXT NOT NULL UNIQUE,
      email TEXT NOT NULL,
      name TEXT NOT NULL DEFAULT \'\',
      phone TEXT NOT NULL DEFAULT \'\',
      kind TEXT NOT NULL DEFAULT \'setup\',
      created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_hits (k TEXT NOT NULL, at INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_hits ON customer_hits(k, at)');
}

/** Téléphone nettoyé : « +33612345678 » pour un numéro français saisi à la française, sinon chiffres (avec « + » initial). null si invalide. */
function customer_phone_normalize(string $raw, string $country = 'FR'): ?string
{
    $p = preg_replace('/[\s.\-()\/]/', '', trim($raw));
    if ($p === '' || !preg_match('/^\+?\d+$/', $p)) return null;
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if ($p[0] !== '+' && $p[0] === '0' && $country === 'FR' && preg_match('/^0[1-9]\d{8}$/', $p)) $p = '+33' . substr($p, 1);
    $digits = strlen(ltrim($p, '+'));
    return $digits >= 8 && $digits <= 15 ? $p : null;
}

/** Numéro pour l'affichage : « 06 12 34 56 78 » pour un +33 français, sinon tel quel. */
function customer_phone_display(string $p): string
{
    if (preg_match('/^\+33([1-9])(\d{8})$/', $p, $m)) return implode(' ', str_split('0' . $m[1] . $m[2], 2));
    return $p;
}

/** Fiche de client (jamais un compte de l'équipe) pour cette adresse, ou null. */
function customer_account(string $email): ?array
{
    $a = account_find_by_email($email);
    return $a && !account_is_staff($a['role']) ? $a : null;
}

/**
 * Fiche de client à partir de l'identifiant saisi (e-mail ou nom). Le nom
 * n'est accepté que s'il désigne un seul client actif, pour ne jamais ouvrir
 * le compte d'un homonyme.
 */
function customer_account_by_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') return null;
    $byEmail = customer_account($login);
    if ($byEmail) return $byEmail;
    $stmt = db()->prepare("SELECT * FROM accounts WHERE name = ? COLLATE NOCASE AND name != ''
        AND role IN ('prospect', 'client') AND is_active = 1 LIMIT 2");
    $stmt->execute([$login]);
    $rows = $stmt->fetchAll();
    return count($rows) === 1 ? $rows[0] : null;
}

function customer_rate_ok(string $key, int $max, int $window): bool
{
    db()->prepare('DELETE FROM customer_hits WHERE at < ?')->execute([time() - 3600]);
    $st = db()->prepare('SELECT COUNT(*) FROM customer_hits WHERE k = ? AND at > ?');
    $st->execute([$key, time() - $window]);
    return (int) $st->fetchColumn() < $max;
}

function customer_rate_note(string $key): void
{
    db()->prepare('INSERT INTO customer_hits (k, at) VALUES (?, ?)')->execute([$key, time()]);
}

function customer_ip_hash(): string
{
    return substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . tenant_slug()), 0, 24);
}

/** Ouvre la session client (même forme que la connexion par réseau social). */
function customer_login_session(array $account, string $provider = 'password'): void
{
    session_regenerate_id(true);
    $_SESSION['customer'] = ['tenant' => tenant_slug(), 'email' => mb_strtolower($account['email']), 'name' => (string) $account['name'], 'provider' => $provider, 'at' => time()];
    account_touch_login((int) $account['id']);
}

/** Connexion par identifiant, nom ou e-mail et mot de passe. -> [compte, erreur]. */
function customer_login(string $login, string $password): array
{
    $login = mb_strtolower(trim($login));
    $key = 'login:' . $login . ':' . customer_ip_hash();
    if (!customer_rate_ok($key, CUSTOMER_LOGIN_MAX, 900)) return [null, 'Trop d\'essais : réessayez dans un quart d\'heure.'];
    $a = customer_account_by_login($login);
    // Même durée et même message que le compte existe ou non (on ne révèle pas qui est inscrit).
    $ok = $a && (int) $a['is_active'] === 1 && !empty($a['password_hash']) && password_verify($password, $a['password_hash']);
    if (!$ok) {
        customer_rate_note($key);
        if (!$a || empty($a['password_hash'])) { static $dummy = null; $dummy ??= password_hash('dummy-password', PASSWORD_DEFAULT); password_verify($password, $dummy); }
        return [null, 'Identifiant, nom ou e-mail incorrect. Pas encore de compte, ou mot de passe oublié ? Utilisez les liens ci-dessous.'];
    }
    if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $a['id']]);
    }
    return [$a, null];
}

/** Demande « mot de passe oublié » à partir d'un identifiant, d'un nom ou d'un e-mail. Réponse toujours identique. */
function customer_request_reset(string $login): array
{
    $a = customer_account_by_login($login);
    if ($a && !empty($a['password_hash'])) {
        return customer_send_link((string) $a['email'], '', '', 'reset');
    }
    $ip = 'link:' . customer_ip_hash();
    if (!customer_rate_ok($ip, CUSTOMER_LINK_MAX, 3600)) return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    customer_rate_note($ip);
    return ['Si un compte correspond à ces informations, un e-mail avec un lien vient de lui être envoyé (valable 24 h). Pensez à regarder vos courriers indésirables.', null];
}

/** Nouveau mot de passe d'un compte client (vérification par code SMS, sans lien). -> erreur ou null. */
function customer_set_password(array $account, string $password, string $confirm): ?string
{
    if ($password !== $confirm) return 'Les deux mots de passe ne sont pas identiques.';
    try {
        account_validate_password($password);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    $upd = db()->prepare("UPDATE accounts SET password_hash = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND role IN ('prospect', 'client')");
    $upd->execute([password_hash($password, PASSWORD_DEFAULT), (int) $account['id']]);
    return $upd->rowCount() ? null : 'Ce compte n\'existe plus.';
}

/**
 * Envoie le lien qui permet de choisir un mot de passe (création de compte, ou mot de passe oublié si $kind = 'reset').
 * Réponse identique que l'adresse soit connue ou non. -> [message, erreur].
 */
function customer_send_link(string $email, string $name, string $phone, string $kind, bool $force = false): array
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) return [null, 'Adresse e-mail invalide.'];
    $ip = 'link:' . customer_ip_hash();
    if (!$force && !customer_rate_ok($ip, CUSTOMER_LINK_MAX, 3600)) return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    customer_rate_note($ip);
    $existing = account_find_by_email($email);
    $generic = 'Si cette adresse peut avoir un compte, un e-mail avec un lien vient de lui être envoyé (valable 24 h). Pensez à regarder vos courriers indésirables.';
    // Adresse d'un membre de l'équipe : jamais de compte client (même réponse que pour toute autre adresse : on ne révèle pas qui fait partie de l'équipe).
    if ($existing && account_is_staff($existing['role'])) return [$generic, null];
    if ($kind === 'reset' && (!$existing || empty($existing['password_hash']))) return [$generic, null];   // rien à réinitialiser : même réponse
    $token = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM customer_tokens WHERE email = ? OR created_at < ?')->execute([$email, time() - CUSTOMER_TOKEN_TTL]);
    db()->prepare('INSERT INTO customer_tokens (token_hash, email, name, phone, kind, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $email, mb_substr(trim($name), 0, 80), mb_substr(trim($phone), 0, 30), $kind === 'reset' ? 'reset' : 'setup', time()]);
    require_once __DIR__ . '/password-reset.php';
    require_once __DIR__ . '/share.php';
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $url = shop_abs_url('/compte.php?a=lien&t=' . $token);
    $hello = trim($name) !== '' ? 'Bonjour ' . trim($name) . ",\n\n" : "Bonjour,\n\n";
    if ($kind === 'reset') {
        password_reset_mail($email, 'Votre mot de passe — ' . $site, $hello . "Vous avez demandé à changer le mot de passe de votre compte {$site}. Choisissez-en un nouveau (lien valable 24 h) :\n$url\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne change.\n\n{$site}\n");
    } else {
        password_reset_mail($email, 'Créez votre compte — ' . $site, $hello . "Pour finir la création de votre compte {$site} et choisir votre mot de passe (lien valable 24 h) :\n$url\n\nVous y retrouverez vos commandes et commanderez plus vite. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : aucun compte ne sera créé.\n\n{$site}\n");
    }
    return [$generic, null];
}

/** Jeton valide (non expiré) : ligne de customer_tokens, ou null. */
function customer_token_find(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM customer_tokens WHERE token_hash = ? AND created_at > ?');
    $st->execute([hash('sha256', $token), time() - CUSTOMER_TOKEN_TTL]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Consomme un jeton : enregistre le mot de passe (compte créé au besoin) et ouvre la session. -> erreur ou null. */
function customer_token_use(string $token, string $password, string $confirm): ?string
{
    $t = customer_token_find($token);
    if (!$t) return 'Ce lien n\'est plus valable : demandez-en un nouveau.';
    if ($password !== $confirm) return 'Les deux mots de passe ne sont pas identiques.';
    try {
        account_validate_password($password);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    $existing = account_find_by_email($t['email']);
    if ($existing && account_is_staff($existing['role'])) return "Cette adresse correspond à un compte de l'équipe de la boutique.";
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($existing) {
        $phone = $existing['phone'] !== '' ? $existing['phone'] : $t['phone'];
        $name = $existing['name'] !== '' ? $existing['name'] : $t['name'];
        db()->prepare('UPDATE accounts SET password_hash = ?, phone = ?, name = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$hash, $phone, $name, $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        require_once __DIR__ . '/legal.php';
        $id = account_create(['role' => 'prospect', 'email' => $t['email'], 'name' => $t['name'], 'phone' => $t['phone'],
            'notes' => 'Compte créé le ' . date('d/m/Y') . ' — conditions acceptées le ' . date('d/m/Y H:i', (int) $t['created_at']) . ' (textes ' . legal_terms_version() . ')'], '', 'inscription');
        db()->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }
    db()->prepare('DELETE FROM customer_tokens WHERE id = ?')->execute([$t['id']]);
    customer_login_session(account_find($id));
    return null;
}

/** Mise à jour du nom et du téléphone du client connecté. -> erreur ou null. */
function customer_update_profile(int $id, string $name, string $phone): ?string
{
    $name = mb_substr(trim($name), 0, 80);
    $p = trim($phone) === '' ? '' : customer_phone_normalize($phone);
    if ($p === null) return 'Numéro de téléphone invalide (8 à 15 chiffres, par exemple 06 12 34 56 78).';
    db()->prepare('UPDATE accounts SET name = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role IN (\'prospect\', \'client\')')->execute([$name, $p, $id]);
    if (isset($_SESSION['customer'])) $_SESSION['customer']['name'] = $name;
    return null;
}

function customer_change_password(array $account, string $current, string $new, string $confirm): ?string
{
    if (empty($account['password_hash']) || !password_verify($current, $account['password_hash'])) return 'Mot de passe actuel incorrect.';
    if ($new !== $confirm) return 'Les deux mots de passe ne sont pas identiques.';
    try {
        account_validate_password($new);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    db()->prepare('UPDATE accounts SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $account['id']]);
    return null;
}

/** Suppression du compte à la demande du client : un prospect est effacé ; un client perd son accès et ses coordonnées, ses commandes restent (comptabilité). */
function customer_delete_account(array $account): void
{
    if ($account['role'] === 'prospect') {
        db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$account['id']]);
    } else {
        db()->prepare("UPDATE accounts SET password_hash = NULL, phone = '', name = '', is_active = 0, notes = '', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role = 'client'")->execute([$account['id']]);
    }
    db()->prepare('DELETE FROM customer_tokens WHERE email = ?')->execute([mb_strtolower($account['email'])]);
}
