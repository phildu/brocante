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

/** Qui reçoit le lien pour cet identifiant ou cet e-mail ? ['account_id' => id|null, 'email' => …] ou null. */
function password_reset_target(string $login): ?array
{
    $login = trim($login);
    if ($login === '') return null;
    // Compte de l'équipe : identifiant ou e-mail, avec ou sans mot de passe (un compte créé par connexion sociale peut en choisir un).
    $stmt = db()->prepare("SELECT id, email FROM accounts WHERE (email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE)
        AND role IN ('admin', 'community_manager') AND is_active = 1 AND email != '' LIMIT 1");
    $stmt->execute([$login, $login]);
    if ($row = $stmt->fetch()) {
        return filter_var($row['email'], FILTER_VALIDATE_EMAIL) ? ['account_id' => (int) $row['id'], 'email' => (string) $row['email']] : null;
    }
    // Compte principal du commerce.
    $user = mb_strtolower(trim((string) tenant('admin_user')));
    if ($user === '' || mb_strtolower($login) !== $user) return null;
    $email = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : trim((string) tenant('admin_email', ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = (string) (pricing_admin_emails()[0] ?? '');
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? ['account_id' => null, 'email' => $email] : null;
}

/** Message e-mail simple (UTF-8). En local (hôte « localhost », « .test », adresse IP), le message est aussi écrit dans data/mail-outbox.log. */
function password_reset_mail(string $to, string $subject, string $body): bool
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $local = !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP);
    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $from = 'noreply@' . (!$local ? preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', $host) : 'localhost.localdomain');
    if ($local) {
        @file_put_contents(__DIR__ . '/../data/mail-outbox.log', '[' . date('c') . "] À : $to\nObjet : $subject\n$body\n----\n", FILE_APPEND | LOCK_EX);
    }
    $headers = 'From: ' . str_replace(['"', "\r", "\n"], '', $site) . " <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    return function_exists('mail') && @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/**
 * Traite une demande : crée le jeton et envoie le lien si le compte existe et si les limites le permettent.
 * Ne retourne rien : l'appelant affiche toujours le même message.
 */
function password_reset_request(string $login, string $returnTo = ''): void
{
    password_reset_ensure_schema();
    $now = time();
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $perIp = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > ?');
    $perIp->execute([$ip, $now - 3600]);
    if ((int) $perIp->fetchColumn() >= 10) return;

    $target = password_reset_target($login);
    if (!$target) {
        // Demande enregistrée quand même (compte inconnu) : la limite par IP joue aussi contre l'énumération.
        db()->prepare('INSERT INTO password_resets (token_hash, account_id, email, ip, expires_at, used_at, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', random_bytes(16)), '', $ip, $now, $now, $now]);
        return;
    }
    $recent = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ? AND created_at > ?');
    $recent->execute([$target['email'], $now - 900]);
    if ((int) $recent->fetchColumn() >= 3) return;

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO password_resets (token_hash, account_id, email, ip, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $target['account_id'], $target['email'], $ip, $now + PASSWORD_RESET_TTL, $now]);

    $site = (string) (get_content()['site_name'] ?: tenant('name'));
    $path = '/admin/reinitialiser.php?token=' . $token . ($returnTo === 'studio' ? '&from=studio' : '');
    $body = "Bonjour,\n\nVous avez demandé à réinitialiser le mot de passe de l'administration de « $site ».\n\n"
        . "Choisissez un nouveau mot de passe avec ce lien (valable 1 heure, utilisable une seule fois) :\n" . shop_abs_url($path) . "\n\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n";
    password_reset_mail($target['email'], "Réinitialiser votre mot de passe — $site", $body);
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
    if (mb_strlen($password) < ACCOUNT_PASSWORD_MIN) throw new InvalidArgumentException('Le mot de passe doit faire au moins ' . ACCOUNT_PASSWORD_MIN . ' caractères.');
    if (mb_strlen($password) > 200) throw new InvalidArgumentException('Mot de passe trop long.');
    if (!hash_equals($password, $confirm)) throw new InvalidArgumentException('Les deux mots de passe ne sont pas identiques.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($reset['account_id'] !== null) {
        $upd = db()->prepare("UPDATE accounts SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role IN ('admin', 'community_manager') AND is_active = 1");
        $upd->execute([$hash, (int) $reset['account_id']]);
        if (!$upd->rowCount()) throw new InvalidArgumentException('Ce compte n\'existe plus ou est désactivé.');
    } else {
        shop_setting_set('admin_password_override', json_encode(['hash' => $hash, 'base' => sha1((string) tenant('admin_password'))]));
    }
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .login-wrap { max-width: 400px; margin: 12vh auto 40px; padding: 0 20px; }
  .login-box { background: var(--surface); border: 1px solid var(--line); padding: 32px; }
  .login-box h1 { font-size: 1.5rem; margin-bottom: 6px; }
  .login-box p.lede { font-size: 0.92rem; margin-bottom: 20px; }
  .login-box input[type="password"], .login-box input[type="text"] {
    width: 100%; box-sizing: border-box; background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 12px 14px; font-family: var(--font-body); font-size: 16px; margin-bottom: 14px;
  }
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
</body>
</html>
<?php
    exit;
}
