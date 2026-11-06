<?php

// Mot de passe oublié de l'administration (et du Studio) : un lien à usage unique, valable une heure, est envoyé par e-mail.
//
// Compte de l'équipe (table accounts) : le lien arrive sur l'adresse e-mail du compte et change SON mot de passe.
// Compte principal du commerce (admin_user / admin_password de tenant.php) : le lien arrive sur son e-mail d'identifiant
// (admin_user est une adresse), sinon sur « admin_email » de tenant.php, sinon sur un administrateur de l'équipe. Le nouveau
// mot de passe est gardé (haché) dans les réglages de la base ; il ne vaut que tant que tenant.php n'a pas changé de mot de passe
// (une modification faite depuis le portail reprend alors la main) — voir admin_accounts().
//
// Le jeton n'est jamais conservé en clair (SHA-256), la réponse de la demande est toujours la même (on ne révèle pas si un compte existe)
// et les demandes sont limitées (par compte et par adresse IP).

require_once __DIR__ . '/share.php'; // shop_abs_url()

const PASSWORD_RESET_TTL = 3600;

function password_reset_ensure_schema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS password_resets (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      token_hash TEXT NOT NULL UNIQUE,
      account_id INTEGER, -- NULL : compte principal du commerce
      email TEXT NOT NULL,
      ip TEXT NOT NULL DEFAULT '',
      expires_at INTEGER NOT NULL,
      used_at INTEGER,
      created_at INTEGER NOT NULL
    )");
}

/**
 * Qui reçoit le lien pour cet identifiant, ce nom ou cet e-mail ?
 * ['account_id' => id|null, 'email' => …, 'phone' => …, 'name' => …] ou null.
 * Le nom n'est accepté que s'il désigne un seul compte.
 */
function password_reset_target(string $login): ?array
{
    $login = trim($login);
    if ($login === '') return null;
    // Compte de l'équipe : identifiant ou e-mail, avec ou sans mot de passe (un compte créé par connexion sociale peut en choisir un).
    $stmt = db()->prepare("SELECT id, email, phone, name FROM accounts WHERE (email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE)
        AND role IN ('admin', 'community_manager', 'photographe') AND is_active = 1 AND email != '' LIMIT 1");
    $stmt->execute([$login, $login]);
    $row = $stmt->fetch();
    if (!$row) {
        $byName = db()->prepare("SELECT id, email, phone, name FROM accounts WHERE name = ? COLLATE NOCASE AND name != ''
            AND role IN ('admin', 'community_manager', 'photographe') AND is_active = 1 AND email != '' LIMIT 2");
        $byName->execute([$login]);
        $rows = $byName->fetchAll();
        $row = count($rows) === 1 ? $rows[0] : null;
    }
    if ($row) {
        return filter_var($row['email'], FILTER_VALIDATE_EMAIL)
            ? ['account_id' => (int) $row['id'], 'email' => (string) $row['email'], 'phone' => (string) ($row['phone'] ?? ''), 'name' => (string) ($row['name'] ?? '')]
            : null;
    }
    // Compte principal du commerce : son identifiant (admin_user) ou son adresse de contact (admin_email).
    $user = mb_strtolower(trim((string) tenant('admin_user')));
    $contact = mb_strtolower(trim((string) tenant('admin_email', '')));
    if ($user === '' || (mb_strtolower($login) !== $user && ($contact === '' || mb_strtolower($login) !== $contact))) return null;
    $email = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : $contact;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = (string) (pricing_admin_emails()[0] ?? '');
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL)
        ? ['account_id' => null, 'email' => $email, 'phone' => trim((string) tenant('admin_phone', '')), 'name' => '']
        : null;
}

/** Applique un nouveau mot de passe à la cible d'une réinitialisation (compte d'équipe ou compte principal). */
function password_reset_set_target(array $target, string $password): void
{
    if (mb_strlen($password) < ACCOUNT_PASSWORD_MIN) throw new InvalidArgumentException('Le mot de passe doit faire au moins ' . ACCOUNT_PASSWORD_MIN . ' caractères.');
    if (mb_strlen($password) > 200) throw new InvalidArgumentException('Mot de passe trop long.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (($target['account_id'] ?? null) !== null) {
        $upd = db()->prepare("UPDATE accounts SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role IN ('admin', 'community_manager', 'photographe') AND is_active = 1");
        $upd->execute([$hash, (int) $target['account_id']]);
        if (!$upd->rowCount()) throw new InvalidArgumentException('Ce compte n\'existe plus ou est désactivé.');
    } else {
        shop_setting_set('admin_password_override', json_encode(['hash' => $hash, 'base' => sha1((string) tenant('admin_password'))]));
    }
}

/** Requête servie en local (hôte sans point, « .test », « .localhost », adresse IP) : aucune boîte réelle ne reçoit le message. */
function password_reset_is_local_host(): bool
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP) !== false;
}

/**
 * Message e-mail simple (UTF-8), avec un pixel de suivi d'ouverture.
 * En local, le message est recopié dans data/mail-outbox.log et compte comme envoyé : mail() n'est pas fiable en développement.
 * $source et $context servent à ranger les envois dans le suivi (voir includes/mail-tracking.php).
 */
function password_reset_mail(string $to, string $subject, string $body, string $source = 'boutique', string $context = ''): bool
{
    require_once __DIR__ . '/mail-tracking.php';
    $local = password_reset_is_local_host();
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    return mail_open_send($to, $subject, $body, $source, [
        'from' => 'From: ' . str_replace(['"', "\r", "\n"], '', $site) . " <$from>",
        'log' => __DIR__ . '/../data/mail-outbox.log',
        'local' => $local,
        'context' => $context,
    ]);
}

/**
 * Traite une demande : crée le jeton et envoie le lien si le compte existe et si les limites le permettent.
 * Retourne l'adresse à qui le lien a été envoyé, ou null (compte inconnu, aucune adresse ou demande limitée).
 * La page garde le même message public ; en local, elle peut afficher cette adresse pour aider à retrouver le lien.
 */
function password_reset_request(string $login, string $returnTo = ''): ?string
{
    password_reset_ensure_schema();
    $now = time();
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $perIp = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > ?');
    $perIp->execute([$ip, $now - 3600]);
    if ((int) $perIp->fetchColumn() >= 10) return null;

    $target = password_reset_target($login);
    if (!$target) {
        // Demande enregistrée quand même (compte inconnu) : la limite par IP joue aussi contre l'énumération.
        db()->prepare('INSERT INTO password_resets (token_hash, account_id, email, ip, expires_at, used_at, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', random_bytes(16)), '', $ip, $now, $now, $now]);
        return null;
    }
    $recent = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ? AND created_at > ?');
    $recent->execute([$target['email'], $now - 900]);
    if ((int) $recent->fetchColumn() >= 3) return null;

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO password_resets (token_hash, account_id, email, ip, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $target['account_id'], $target['email'], $ip, $now + PASSWORD_RESET_TTL, $now]);

    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $path = '/admin/reinitialiser.php?token=' . $token . ($returnTo === 'studio' ? '&from=studio' : '');
    $body = "Bonjour,\n\nVous avez demandé à réinitialiser le mot de passe de l'administration de « $site ».\n\n"
        . "Choisissez un nouveau mot de passe avec ce lien (valable 1 heure, utilisable une seule fois) :\n" . shop_abs_url($path) . "\n\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n";
    password_reset_mail($target['email'], "Réinitialiser votre mot de passe — $site", $body, 'compte');
    return (string) $target['email'];
}

/** Demande valable (ni expirée ni utilisée) pour ce jeton, ou null. */
function password_reset_find(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    password_reset_ensure_schema();
    $stmt = db()->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? AND email != ?');
    $stmt->execute([hash('sha256', $token), time(), '']);
    return $stmt->fetch() ?: null;
}

/** Enregistre le nouveau mot de passe et verrouille le jeton (et ses frères). Lève InvalidArgumentException si le mot de passe est refusé. */
function password_reset_apply(array $reset, string $password, string $confirm): void
{
    db();
    if (!hash_equals($password, $confirm)) throw new InvalidArgumentException('Les deux mots de passe ne sont pas identiques.');
    password_reset_set_target(['account_id' => $reset['account_id']], $password);
    db()->prepare('UPDATE password_resets SET used_at = ? WHERE (account_id IS ? OR account_id = ?) AND used_at IS NULL')
        ->execute([time(), $reset['account_id'], $reset['account_id']]);
}

/** Mot de passe (haché) du compte principal choisi par réinitialisation, tant que tenant.php n'a pas changé depuis ; sinon null. */
function password_reset_override(): ?string
{
    db(); // charge shop_setting() (includes/recto-verso.php)
    $raw = shop_setting('admin_password_override', '');
    if ($raw === '') return null;
    $d = json_decode($raw, true);
    return is_array($d) && !empty($d['hash']) && ($d['base'] ?? '') === sha1((string) tenant('admin_password')) ? (string) $d['hash'] : null;
}

/** Page complète (même présentation que la connexion de l'administration) autour de $bodyHtml. */
function password_reset_page(string $title, string $bodyHtml): never
{
    $content = get_content();
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer'); // le jeton est dans l'adresse : jamais transmis à un autre site
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/login.css">
<style>
  .login-wrap { max-width: 420px; margin: 12vh auto 40px; padding: 0 20px; }
  .login-box { background: var(--surface); border: 1px solid var(--line); padding: 32px; }
  .login-box h1 { font-size: 1.5rem; margin-bottom: 6px; }
  .login-box p.lede { font-size: 0.92rem; margin-bottom: 20px; }
  .login-box label.lbl { display: block; font-size: .85rem; margin-bottom: 4px; color: var(--ink-soft); }
  .login-error { color: var(--accent); font-size: 0.88rem; margin-bottom: 14px; font-family: var(--font-mono); }
  .login-ok { font-size: 0.92rem; margin-bottom: 14px; padding: 12px; border: 1px solid var(--line); background: var(--bg); }
  .login-box .showpw { display: flex; gap: 8px; align-items: center; font-size: .9rem; margin: -4px 0 16px; }
  .login-links { margin-top: 18px; font-size: .9rem; }
</style>
</head>
<body>
<div class="login-wrap"><div class="login-box">
<?= $bodyHtml ?>
</div></div>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
<?php
    exit;
}
