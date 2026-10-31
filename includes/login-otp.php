<?php

// Code de secours par SMS pour le mot de passe oublié, à côté du lien e-mail.
//
// Un code à 6 chiffres, valable 10 minutes, est envoyé au numéro connu du compte
// (compte de l'équipe, compte client, administrateur de galerie). Il sert à choisir
// un nouveau mot de passe sur place, sans quitter la page.
//
// L'envoi passe par OVH ou Twilio (includes/sms.php) quand les réglages de la
// boutique ou les variables d'environnement fournissent des identifiants. En
// local, le message est écrit dans data/sms-outbox.log (comme les e-mails) :
// le parcours reste testable.
//
// Les codes sont conservés dans data/login-otps.json (empreinte SHA-256, jamais
// le code en clair) : la page de galerie n'ouvre pas la base de la boutique, le
// même stockage fonctionne donc dans tous les espaces. Les essais sont limités
// (5 par code, 5 demandes par heure et par connexion) et l'existence d'un compte
// n'est jamais révélée : la demande répond toujours la même chose.

require_once __DIR__ . '/password-reset.php'; // password_reset_target(), password_reset_set_target()
require_once __DIR__ . '/accounts.php';       // account_find_by_email() (téléphone d'un administrateur de galerie)
require_once __DIR__ . '/customers.php';      // customer_phone_normalize(), customer_account_by_login(), customer_set_password()

const LOGIN_OTP_TTL = 600;
const LOGIN_OTP_MAX_ATTEMPTS = 5;
const LOGIN_OTP_MAX_PER_HOUR = 5;

function login_otp_file(): string
{
    return __DIR__ . '/../data/login-otps.json';
}

/** Codes en attente, indexés par « espace|identifiant » ; les entrées expirées sont retirées. */
function login_otp_rows(): array
{
    $file = login_otp_file();
    $rows = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $now = time();
    foreach ($rows as $key => $row) {
        if (!is_array($row) || (int) ($row['expires_at'] ?? 0) <= $now) unset($rows[$key]);
    }
    return $rows;
}

function login_otp_save(array $rows): bool
{
    $dir = dirname(login_otp_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents(login_otp_file(), json_encode($rows), LOCK_EX) !== false;
}

function login_otp_key(string $scope, string $login): string
{
    return $scope . '|' . mb_strtolower(trim($login));
}

/** Hôte local ? (même règle que les e-mails : hôte sans point, « .test », « .localhost » ou adresse IP) */
function login_otp_local(): bool
{
    require_once __DIR__ . '/sms.php';
    return sms_local_host();
}

/** Un numéro peut-il recevoir un code ? (fournisseur configuré ou mode local pour tester) */
function login_otp_sms_available(): bool
{
    require_once __DIR__ . '/sms.php';
    return sms_available();
}

/** Numéro masqué pour l'affichage : « +33 6 •• •• •• 78 ». */
function login_otp_phone_mask(string $phone): string
{
    $p = preg_replace('/[\s.\-()\/]/', '', trim($phone));
    if ($p === '') return '';
    $digits = ltrim($p, '+');
    if (strlen($digits) <= 4) return $p;
    return substr($p, 0, max(2, strlen($p) - 6)) . str_repeat('•', min(6, strlen($digits) - 4)) . substr($digits, -2);
}

/** Envoi du message : OVH ou Twilio selon les réglages, sinon copie locale dans data/sms-outbox.log. */
function login_otp_send_sms(string $to, string $body): bool
{
    require_once __DIR__ . '/sms.php';
    return sms_send($to, $body);
}

/**
 * Compte visé par un identifiant, un nom ou un e-mail, selon l'espace.
 * -> ['key' => …, 'phone' => …, 'label' => …] ou null (inconnu, ambigu ou sans numéro).
 * $key reprend la saisie : le même identifiant sert à demander puis à valider le code.
 */
function login_otp_resolve(string $scope, string $login): ?array
{
    $login = trim($login);
    if ($login === '') return null;
    $key = mb_strtolower($login);
    if ($scope === 'admin' || $scope === 'studio') {
        $t = password_reset_target($login);
        if (!$t) return null;
        return ['key' => $key, 'phone' => (string) ($t['phone'] ?? ''), 'label' => (string) ($t['name'] ?? '')];
    }
    if ($scope === 'customer') {
        $a = customer_account_by_login($login);
        if (!$a) return null;
        return ['key' => $key, 'phone' => trim((string) ($a['phone'] ?? '')), 'label' => (string) ($a['name'] ?? '')];
    }
    if ($scope === 'gallery') {
        $matches = [];
        foreach (gallery_list() as $g) {
            $a = ga_admin_find_by_login($g, $login);
            if ($a && !empty($a['email'])) $matches[mb_strtolower((string) $a['email'])] = $a;
        }
        if (count($matches) !== 1) return null;
        $admin = array_values($matches)[0];
        $phone = trim((string) ($admin['phone'] ?? ''));
        // Le téléphone peut venir de la fiche contact de la boutique quand la base est ouverte (ce n'est pas le cas sur /galerie/).
        if ($phone === '' && function_exists('db') && function_exists('account_find_by_email')) {
            try {
                $phone = trim((string) (account_find_by_email((string) $admin['email'])['phone'] ?? ''));
            } catch (Throwable $e) {
                $phone = '';
            }
        }
        return ['key' => $key, 'phone' => $phone, 'label' => ''];
    }
    return null;
}

/** Limite les demandes : 5 par heure et par connexion, tous espaces confondus. */
function login_otp_rate_ok(): bool
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $n = 0;
    foreach (login_otp_rows() as $row) if (($row['ip'] ?? '') === $ip && (int) ($row['created_at'] ?? 0) > time() - 3600) $n++;
    return $n < LOGIN_OTP_MAX_PER_HOUR;
}

/** Nom du site pour le message, sans dépendre du thème (page de galerie). */
function login_otp_site_name(): string
{
    try {
        if (function_exists('get_content')) return (string) (get_content()['site_name'] ?: tenant('name'));
        if (function_exists('tenant')) return (string) tenant('name');
    } catch (Throwable $e) {
    }
    return 'Brocs';
}

/**
 * Envoie un code si le compte existe, a un numéro et si le fournisseur est prêt.
 * -> ['sent' => bool, 'phone' => numéro masqué, 'error' => message ou null].
 * Le message d'erreur ne distingue jamais un compte inconnu d'un compte sans numéro.
 */
function login_otp_request(string $scope, string $login): array
{
    $none = ['sent' => false, 'phone' => '', 'error' => 'Aucun code n\'a pu être envoyé à ce compte. Vérifiez vos informations ou choisissez le lien par e-mail.'];
    if (!login_otp_sms_available() || !login_otp_rate_ok()) return $none;
    $target = login_otp_resolve($scope, $login);
    if (!$target) return $none;
    $phone = customer_phone_normalize($target['phone']);
    if (!$phone) return $none;

    $code = (string) random_int(100000, 999999);
    $rows = login_otp_rows();
    $rows[login_otp_key($scope, $target['key'])] = [
        'phone' => $phone,
        'code_hash' => hash('sha256', $code),
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'expires_at' => time() + LOGIN_OTP_TTL,
        'attempts' => 0,
        'created_at' => time(),
    ];
    login_otp_save($rows);
    $body = 'Votre code ' . login_otp_site_name() . " : $code. Il est valable 10 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.";
    if (!login_otp_send_sms($phone, $body)) {
        unset($rows[login_otp_key($scope, $target['key'])]);
        login_otp_save($rows);
        return $none;
    }
    return ['sent' => true, 'phone' => login_otp_phone_mask($phone), 'error' => null];
}

/** Un code est-il en attente pour cet identifiant ? */
function login_otp_pending(string $scope, string $login): bool
{
    $row = login_otp_rows()[login_otp_key($scope, $login)] ?? null;
    return is_array($row) && (int) ($row['expires_at'] ?? 0) > time();
}

/**
 * Vérifie le code et enregistre le nouveau mot de passe du compte visé.
 * -> message d'erreur ou null. Le code reste valable si seul le mot de passe est refusé.
 */
function login_otp_apply(string $scope, string $login, string $code, string $password, string $confirm): ?string
{
    $code = preg_replace('/\D+/', '', $code);
    if (strlen($code) !== 6) return 'Saisissez le code à 6 chiffres reçu par SMS.';
    $key = login_otp_key($scope, $login);
    $rows = login_otp_rows();
    $row = $rows[$key] ?? null;
    if (!is_array($row) || (int) ($row['expires_at'] ?? 0) <= time()) return 'Ce code a expiré : demandez-en un nouveau.';
    if ((int) ($row['attempts'] ?? 0) >= LOGIN_OTP_MAX_ATTEMPTS) {
        unset($rows[$key]);
        login_otp_save($rows);
        return 'Trop d\'essais sur ce code : demandez-en un nouveau.';
    }
    if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
        $rows[$key]['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
        login_otp_save($rows);
        return 'Code incorrect. Vérifiez le SMS reçu.';
    }
    if ($password !== $confirm) return 'Les deux mots de passe ne sont pas identiques.';
    try {
        login_otp_set_password($scope, $login, $password, $confirm);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    unset($rows[$key]);
    login_otp_save($rows);
    return null;
}

/** Enregistre le mot de passe sur le compte visé (lève InvalidArgumentException si refusé). */
function login_otp_set_password(string $scope, string $login, string $password, string $confirm): void
{
    if ($scope === 'admin' || $scope === 'studio') {
        $target = password_reset_target($login);
        if (!$target) throw new InvalidArgumentException('Ce compte n\'existe plus ou est désactivé.');
        password_reset_set_target($target, $password);
        return;
    }
    if ($scope === 'customer') {
        $account = customer_account_by_login($login);
        if (!$account) throw new InvalidArgumentException('Ce compte n\'existe plus ou est désactivé.');
        $err = customer_set_password($account, $password, $confirm);
        if ($err !== null) throw new InvalidArgumentException($err);
        return;
    }
    if ($scope === 'gallery') {
        $done = false;
        foreach (gallery_list() as $g) {
            $a = ga_admin_find_by_login($g, $login);
            if (!$a || empty($a['email'])) continue;
            $err = ga_admin_set_password($g['slug'], (string) $a['email'], $password);
            if ($err !== null) throw new InvalidArgumentException($err);
            $done = true;
        }
        if (!$done) throw new InvalidArgumentException('Ce compte n\'existe plus.');
        return;
    }
    throw new InvalidArgumentException('Espace de connexion inconnu.');
}
