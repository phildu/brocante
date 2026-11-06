<?php

// Identité unique : un même identifiant ou une même adresse e-mail peut ouvrir
// l'administration d'une boutique, une galerie commerciale, le portail de
// l'exploitant ou l'espace client. Rien n'est migré : ce fichier rassemble les
// comptes déjà en place (table accounts de chaque boutique, compte principal de
// tenant.php, admins des galeries, .secrets/portail.json) et vérifie le mot de
// passe sur chacun.
//
// Quand l'espace choisi vit dans un autre cookie de session (une boutique servie
// sous /<identifiant>/ a son propre sess_<identifiant>), un jeton à usage unique
// (5 minutes, data/sso-tickets.json) est remis à sso.php : cette page s'ouvre
// dans l'adresse de l'espace, y ouvre la session et redirige.

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/password-reset.php';

const UA_TICKET_TTL = 300;
const UA_TRY_MAX = 12;
const UA_TRY_WINDOW = 900;

function ua_data_dir(): string
{
    return defined('UA_DATA_DIR') ? rtrim((string) UA_DATA_DIR, '/') : dirname(__DIR__) . '/data';
}

function ua_tickets_file(): string
{
    return ua_data_dir() . '/sso-tickets.json';
}

function ua_throttle_file(): string
{
    return ua_data_dir() . '/connexion-throttle.json';
}

/* ---------- découverte des comptes ---------- */

/** Connexion à la base d'un commerce (sans toucher à celle du commerce actif), ou null si elle n'existe pas encore. */
function ua_shop_db(array $config): ?PDO
{
    $path = tenant_file($config, 'db_file');
    if (!is_file($path)) return null;
    try {
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

/** Mot de passe du compte principal d'un commerce, en tenant compte du remplacement choisi par « mot de passe oublié ». */
function ua_shop_main_password(array $config, ?PDO $pdo): string
{
    $base = (string) ($config['admin_password'] ?? '');
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
            $stmt->execute(['admin_password_override']);
            $raw = (string) ($stmt->fetchColumn() ?: '');
            $d = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($d) && !empty($d['hash']) && ($d['base'] ?? '') === sha1($base)) return (string) $d['hash'];
        } catch (Throwable $e) {
        }
    }
    return $base;
}

/** Comptes d'une boutique (équipe et clients) qui répondent à cet identifiant, plus le compte principal. */
function ua_shop_identities(array $config, string $login): array
{
    $slug = (string) ($config['slug'] ?? '');
    $pdo = ua_shop_db($config);
    $rows = [];
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT id, email, name, role, password_hash FROM accounts
                WHERE is_active = 1 AND password_hash IS NOT NULL AND password_hash != ''
                  AND (email = ? COLLATE NOCASE OR username = ? COLLATE NOCASE) LIMIT 1");
            $stmt->execute([$login, $login]);
            $row = $stmt->fetch();
            if ($row) {
                $rows[] = $row;
            } else {
                // Le nom n'est accepté que s'il désigne un seul compte (deux homonymes ouvriraient le compte de l'autre).
                $byName = $pdo->prepare("SELECT id, email, name, role, password_hash FROM accounts
                    WHERE is_active = 1 AND password_hash IS NOT NULL AND password_hash != '' AND name = ? COLLATE NOCASE AND name != '' LIMIT 2");
                $byName->execute([$login]);
                $found = $byName->fetchAll();
                if (count($found) === 1) $rows = $found;
            }
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'space' => 'shop',
            'tenant' => $slug,
            'account_id' => (int) $row['id'],
            'role' => (string) $row['role'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'label' => (string) ($config['name'] ?? $slug),
            '_hash' => (string) $row['password_hash'],
        ];
    }
    // Compte principal du commerce : identifiant admin_user ou adresse admin_email.
    $user = mb_strtolower(trim((string) ($config['admin_user'] ?? '')));
    $contact = mb_strtolower(trim((string) ($config['admin_email'] ?? '')));
    if ($user !== '' && (mb_strtolower(trim($login)) === $user || ($contact !== '' && mb_strtolower(trim($login)) === $contact))) {
        $password = ua_shop_main_password($config, $pdo);
        if ($password !== '') {
            $out[] = [
                'space' => 'shop',
                'tenant' => $slug,
                'account_id' => null,
                'role' => 'admin',
                'email' => filter_var($user, FILTER_VALIDATE_EMAIL) ? (string) ($config['admin_user'] ?? '') : $contact,
                'name' => '',
                'label' => (string) ($config['name'] ?? $slug),
                '_hash' => $password,
            ];
        }
    }
    // Compte de la configuration (ADMIN_USER / ADMIN_PASSWORD) : ouvre l'administration de tous les commerces.
    $configUser = mb_strtolower(trim(defined('ADMIN_USER') ? (string) ADMIN_USER : (getenv('ADMIN_USER') ?: 'admin')));
    if ($configUser !== '' && defined('ADMIN_PASSWORD') && (string) ADMIN_PASSWORD !== '' && mb_strtolower(trim($login)) === $configUser) {
        $out[] = [
            'space' => 'shop',
            'tenant' => $slug,
            'account_id' => null,
            'role' => 'admin',
            'email' => filter_var($configUser, FILTER_VALIDATE_EMAIL) ? $configUser : '',
            'name' => '',
            'label' => (string) ($config['name'] ?? $slug),
            '_hash' => (string) ADMIN_PASSWORD,
        ];
    }
    return $out;
}

/** Administrateurs de galerie (une entrée par adresse : une adresse peut administrer plusieurs galeries). */
function ua_gallery_identities(string $login): array
{
    require_once __DIR__ . '/gallery-admin.php';
    $byEmail = [];
    foreach (gallery_list() as $g) {
        $a = ga_admin_find_by_login($g, $login);
        if (!$a || empty($a['hash']) || !filter_var((string) $a['email'], FILTER_VALIDATE_EMAIL)) continue;
        $email = mb_strtolower((string) $a['email']);
        $byEmail[$email] ??= [
            'space' => 'gallery', 'tenant' => null, 'account_id' => null, 'role' => 'gallery_admin',
            'email' => $email, 'name' => (string) ($a['name'] ?? ''),
            'label' => (string) ($g['name'] ?? $g['slug']), 'galleries' => [], 'must_change' => false,
        ];
        $byEmail[$email]['galleries'][(string) $g['slug']] = (string) $a['hash'];
        $byEmail[$email]['must_change'] = $byEmail[$email]['must_change'] || !empty($a['must_change']);
    }
    return array_values($byEmail);
}

/** Compte de l'exploitant (.secrets/portail.json), s'il répond à cet identifiant. */
function ua_portal_identity(string $login): ?array
{
    $file = dirname(__DIR__) . '/.secrets/portail.json';
    if (!is_file($file)) return null;
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || empty($data['user']) || empty($data['password_hash'])) return null;
    $wanted = mb_strtolower(trim($login));
    $matches = false;
    foreach ([$data['user'], $data['name'] ?? '', $data['email'] ?? ''] as $alias) {
        if (trim((string) $alias) !== '' && hash_equals(mb_strtolower(trim((string) $alias)), $wanted)) {
            $matches = true;
            break;
        }
    }
    return $matches ? [
        'space' => 'portal', 'tenant' => null, 'account_id' => null, 'role' => 'portal',
        'email' => mb_strtolower(trim((string) ($data['email'] ?? ''))), 'name' => (string) ($data['name'] ?? ''),
        'label' => "Portail de l'exploitant", 'portal_user' => (string) $data['user'],
        '_hash' => (string) $data['password_hash'],
    ] : null;
}

/**
 * Tous les comptes qui répondent à cet identifiant, dans l'ordre où ils seront
 * proposés : la boutique ouverte d'abord, puis les autres boutiques, les
 * galeries et le portail. Les clés commençant par « _ » ne sortent jamais d'ici.
 */
function ua_discover(string $login): array
{
    $login = trim($login);
    if ($login === '') return [];
    $out = [];
    foreach (tenant_list() as $config) {
        foreach (ua_shop_identities($config, $login) as $identity) $out[] = $identity;
    }
    foreach (ua_gallery_identities($login) as $identity) $out[] = $identity;
    if (($portal = ua_portal_identity($login)) !== null) $out[] = $portal;
    usort($out, static function (array $a, array $b): int {
        $rank = static function (array $i): array {
            $current = $i['space'] === 'shop' && $i['tenant'] === tenant_slug() ? 0 : ($i['space'] === 'shop' ? 1 : ($i['space'] === 'gallery' ? 2 : 3));
            return [$current, $i['space'] === 'shop' ? 0 : 1, $i['label']];
        };
        return $rank($a) <=> $rank($b);
    });
    return $out;
}

/* ---------- vérification du mot de passe ---------- */

function ua_password_matches(string $stored, string $password): bool
{
    if ($stored === '') return false;
    return password_get_info($stored)['algo'] !== null
        ? password_verify($password, $stored)
        : hash_equals($stored, $password);
}

/**
 * Vérifie le mot de passe sur une identité. Retourne l'identité (réduite aux
 * galeries réellement ouvertes par ce mot de passe), ou null.
 */
function ua_identity_check(array $identity, string $password): ?array
{
    if (($identity['space'] ?? '') === 'gallery') {
        $open = [];
        foreach ((array) ($identity['galleries'] ?? []) as $slug => $hash) {
            if (ua_password_matches((string) $hash, $password)) $open[$slug] = $hash;
        }
        if (!$open) return null;
        $identity['galleries'] = $open;
        return $identity;
    }
    return ua_password_matches((string) ($identity['_hash'] ?? ''), $password) ? $identity : null;
}

/** Identité sans secret, telle qu'elle peut être rangée dans un jeton ou une page. */
function ua_public_identity(array $identity): array
{
    if (($identity['space'] ?? '') === 'gallery') {
        $galleries = (array) ($identity['galleries'] ?? []);
        // Déjà une liste de slugs (identité publique passée une seconde fois) : ne pas la reconvertir en clés.
        if (!array_is_list($galleries)) $galleries = array_keys($galleries);
        $identity['galleries'] = array_values(array_filter($galleries, 'is_string'));
    }
    unset($identity['_hash']);
    return $identity;
}

/** Libellé d'une identité sur la page de connexion (boutique, galerie, portail…). */
function ua_option_label(array $identity): string
{
    $label = trim((string) ($identity['label'] ?? ''));
    if (($identity['space'] ?? '') === 'shop') {
        $area = account_is_staff((string) $identity['role']) ? 'Administration' : 'Mon compte';
        return ($label !== '' ? $label . ' — ' : '') . $area;
    }
    if (($identity['space'] ?? '') === 'gallery') {
        $count = count((array) ($identity['galleries'] ?? []));
        return ($label !== '' ? $label : 'Galerie') . ' — Administration de galerie' . ($count > 1 ? ' (' . $count . ' galeries)' : '');
    }
    return "Portail de l'exploitant";
}

/* ---------- réinitialisation groupée ---------- */

/**
 * Crée un jeton de réinitialisation dans la base d'un commerce (sans passer par
 * la base du commerce actif) et retourne le lien absolu, ou null.
 */
function ua_shop_reset_link(array $config, string $email, ?int $accountId = null): ?string
{
    $pdo = ua_shop_db($config);
    if ($pdo === null) return null;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          token_hash TEXT NOT NULL UNIQUE,
          account_id INTEGER,
          email TEXT NOT NULL,
          ip TEXT NOT NULL DEFAULT '',
          expires_at INTEGER NOT NULL,
          used_at INTEGER,
          created_at INTEGER NOT NULL
        )");
        $recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ? AND created_at > ?');
        $recent->execute([$email, time() - 900]);
        if ((int) $recent->fetchColumn() >= 3) return null;
        $token = bin2hex(random_bytes(32));
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $pdo->prepare('INSERT INTO password_resets (token_hash, account_id, email, ip, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $accountId, $email, $ip, time() + PASSWORD_RESET_TTL, time()]);
    } catch (Throwable $e) {
        return null;
    }
    return ua_shop_origin($config) . '/admin/reinitialiser.php?token=' . $token;
}

/** Lien signé de réinitialisation d'un administrateur de galerie, ou null si l'adresse n'administre aucune galerie. */
function ua_gallery_reset_link(string $email): ?string
{
    require_once __DIR__ . '/gallery-admin.php';
    $found = false;
    foreach (gallery_list() as $g) {
        if (ga_admin_find($g, $email)) {
            $found = true;
            break;
        }
    }
    if (!$found) return null;
    $exp = time() + GA_RESET_TTL;
    return share_origin() . '/galerie/mot-de-passe.php?' . http_build_query([
        'e' => $email, 'x' => $exp, 't' => ga_reset_sig($email, $exp),
    ]);
}

/**
 * Envoie à chaque adresse concernée un seul e-mail qui réunit les liens des
 * espaces qu'elle ouvre (boutique, galerie). Retourne les adresses servies ;
 * vide si rien n'a pu être envoyé (aucune adresse, ou demandes trop fréquentes).
 */
function ua_reset_request(string $login): array
{
    $login = trim($login);
    if ($login === '' || !ua_try_allowed('reset:' . $login)) return [];
    ua_try_note('reset:' . $login);
    $groups = [];
    foreach (ua_discover($login) as $identity) {
        $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (($identity['space'] ?? '') === 'shop') {
            $link = ua_shop_reset_link(tenant_load((string) $identity['tenant']), $email, $identity['account_id']);
        } elseif (($identity['space'] ?? '') === 'gallery') {
            $link = ua_gallery_reset_link($email);
        } else {
            continue; // compte de l'exploitant : mot de passe défini au déploiement, hors de ce formulaire
        }
        if ($link === null) continue;
        $groups[$email][] = ['label' => ua_option_label($identity), 'url' => $link];
    }
    $sent = [];
    foreach ($groups as $email => $links) {
        $body = "Bonjour,\n\nVous avez demandé à choisir de nouveaux mots de passe pour les espaces suivants :\n\n";
        foreach ($links as $link) $body .= '— ' . $link['label'] . " :\n" . $link['url'] . "\n\n";
        $body .= "Chaque lien est valable 1 heure et utilisable une seule fois. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : vos mots de passe restent valables.\n";
        if (password_reset_mail($email, 'Réinitialiser vos mots de passe', $body, 'compte')) $sent[] = $email;
    }
    return $sent;
}

/* ---------- jeton de formulaire (sans session) ---------- */

/** Secret local des jetons de formulaire (data/.auth-salt, jamais servi en HTTP). */
function ua_auth_salt(): string
{
    $file = ua_data_dir() . '/.auth-salt';
    $salt = is_file($file) ? (string) @file_get_contents($file) : '';
    if (strlen($salt) >= 32) return $salt;
    if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) return hash('sha256', __DIR__);
    $lock = @fopen($file, 'c+');
    if ($lock === false) return hash('sha256', __DIR__);
    try {
        if (!flock($lock, LOCK_EX)) return hash('sha256', __DIR__);
        rewind($lock);
        $salt = (string) stream_get_contents($lock);
        if (strlen($salt) < 32) {
            $salt = bin2hex(random_bytes(32));
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, $salt);
            fflush($lock);
            @chmod($file, 0600);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $salt;
}

/**
 * Jeton de formulaire signé, valable deux heures : la page de connexion n'ouvre
 * pas de session avant de savoir dans quel espace elle va (boutique, galerie,
 * portail), pour ne pas écraser le cookie de session de l'espace en cours.
 */
function ua_csrf_token(): string
{
    $payload = base64_encode(time() . '|' . bin2hex(random_bytes(16)));
    return $payload . '.' . hash_hmac('sha256', $payload, ua_auth_salt());
}

function ua_csrf_ok(string $token): bool
{
    [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
    if ($payload === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $payload, ua_auth_salt()), $sig)) return false;
    $at = (int) explode('|', (string) base64_decode($payload, true))[0];
    return $at > time() - 7200 && $at <= time() + 300;
}

/* ---------- limite d'essais ---------- */

function ua_try_key(string $login): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . mb_strtolower(trim($login)));
}

function ua_try_allowed(string $login): bool
{
    $data = ua_json_read(ua_throttle_file());
    $hits = array_values(array_filter((array) ($data[ua_try_key($login)] ?? []), static fn ($t) => (int) $t > time() - UA_TRY_WINDOW));
    return count($hits) < UA_TRY_MAX;
}

function ua_try_note(string $login): void
{
    $key = ua_try_key($login);
    ua_json_update(ua_throttle_file(), static function (array $data) use ($key): array {
        $now = time();
        foreach ($data as $k => $hits) {
            $kept = array_values(array_filter((array) $hits, static fn ($t) => (int) $t > $now - UA_TRY_WINDOW));
            if ($kept) $data[$k] = $kept; else unset($data[$k]);
        }
        $data[$key][] = $now;
        return $data;
    });
}

/**
 * Vérifie un couple identifiant / mot de passe sur tous les espaces.
 * Retourne les identités publiques qui correspondent (vide si aucune).
 */
function ua_login(string $login, string $password): array
{
    $login = trim($login);
    if ($login === '' || $password === '') return [];
    if (!ua_try_allowed($login)) return [];
    $matches = [];
    $seen = [];
    foreach (ua_discover($login) as $identity) {
        $checked = ua_identity_check($identity, $password);
        if ($checked === null) continue;
        // Un même espace peut répondre deux fois (compte principal et compte de la configuration) : une seule proposition.
        $key = $checked['space'] . '|' . ($checked['tenant'] ?? '') . '|' . ($checked['gallery'] ?? '') . '|' . ($checked['account_id'] ?? 'main');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $matches[] = ua_public_identity($checked);
    }
    if (!$matches) {
        ua_try_note($login);
        ua_password_matches('$2y$12$z1UVcc1AoYyQPd3h9tNn8.gcPzUCtAceZH7qkzjYKYhBfeDzC4Y.S', $password); // même durée que pour un compte existant
        sleep(1);
    }
    return $matches;
}

/* ---------- jetons à usage unique (sso.php) ---------- */

function ua_json_read(string $file): array
{
    if (!is_file($file)) return [];
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** Lecture-modification-écriture verrouillée d'un fichier JSON (créé au besoin). */
function ua_json_update(string $file, callable $change): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $lock = @fopen($file, 'c+');
    if ($lock === false) return;
    try {
        if (!flock($lock, LOCK_EX)) return;
        rewind($lock);
        $data = json_decode((string) stream_get_contents($lock), true);
        $data = $change(is_array($data) ? $data : []);
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, (string) json_encode($data));
        fflush($lock);
        @chmod($file, 0600);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ua_ticket_ip(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function ua_ticket_create(array $identity): ?string
{
    $token = bin2hex(random_bytes(24));
    ua_json_update(ua_tickets_file(), static function (array $data) use ($token, $identity): array {
        $now = time();
        foreach ($data as $k => $entry) if ((int) ($entry['exp'] ?? 0) <= $now) unset($data[$k]);
        $data[hash('sha256', $token)] = ['exp' => $now + UA_TICKET_TTL, 'ip' => ua_ticket_ip(), 'identity' => ua_public_identity($identity)];
        return $data;
    });
    return isset(ua_json_read(ua_tickets_file())[hash('sha256', $token)]) ? $token : null;
}

/** Identité du jeton s'il est valable (ni expiré, ni émis pour une autre adresse), sans le consommer. */
function ua_ticket_peek(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
    $entry = ua_json_read(ua_tickets_file())[hash('sha256', $token)] ?? null;
    if (!is_array($entry) || (int) ($entry['exp'] ?? 0) <= time()) return null;
    $ip = (string) ($entry['ip'] ?? '');
    if ($ip !== '' && !hash_equals($ip, ua_ticket_ip())) return null;
    return is_array($entry['identity'] ?? null) ? $entry['identity'] : null;
}

/** Consomme le jeton (usage unique) et retourne l'identité qu'il portait. */
function ua_ticket_redeem(string $token): ?array
{
    $identity = ua_ticket_peek($token);
    if ($identity === null) return null;
    ua_json_update(ua_tickets_file(), static function (array $data) use ($token): array {
        unset($data[hash('sha256', $token)]);
        return $data;
    });
    return $identity;
}

/* ---------- adresses et sessions ---------- */

/** Adresse d'origine d'un commerce : son site_url s'il est réel, sinon l'hôte courant (ou son préfixe /<identifiant>). En local, on reste sur l'hôte courant. */
function ua_shop_origin(array $config): string
{
    $slug = (string) ($config['slug'] ?? '');
    // Le commerce ouvert dans cette requête garde exactement son adresse (et donc son cookie de session).
    if ($slug === tenant_slug()) return share_origin() . tenant_base_path();
    if (tenant_from_host() === $slug) return share_origin();
    if (password_reset_is_local_host()) return share_origin() . '/' . $slug;
    $url = rtrim((string) ($config['site_url'] ?? ''), '/');
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $placeholder = $host === '' || $host === 'localhost' || $host === '127.0.0.1'
        || str_ends_with($host, '.test') || str_ends_with($host, '.example.com');
    return $placeholder ? share_origin() . '/' . $slug : $url;
}

/** Adresse de sso.php pour l'espace de cette identité. */
function ua_sso_url(array $identity): string
{
    if (($identity['space'] ?? '') === 'shop') {
        return ua_shop_origin(tenant_load((string) $identity['tenant'])) . '/sso.php';
    }
    return share_origin() . '/sso.php';
}

/** Page d'arrivée après connexion, dans les chemins de l'espace (préfixés à l'envoi par tenant_rewrite_location()). */
function ua_destination(array $identity): string
{
    if (($identity['space'] ?? '') === 'shop') {
        return account_is_staff((string) $identity['role'])
            ? admin_home_for_role((string) $identity['role'])
            : '/compte.php';
    }
    if (($identity['space'] ?? '') === 'gallery') {
        return !empty($identity['must_change']) ? '/portail/galerie-compte.php' : '/portail/galerie-tableau.php';
    }
    return '/portail/';
}

/** La requête se déroule-t-elle dans le cookie de session de cet espace ? */
function ua_context_matches(array $identity): bool
{
    if (($identity['space'] ?? '') === 'shop') {
        return tenant_slug() === (string) ($identity['tenant'] ?? '');
    }
    return tenant_base_path() === '';
}

/** Ouvre la session de l'espace de cette identité (l'appelant a vérifié ua_context_matches()). */
function ua_open_session(array $identity): void
{
    $space = (string) ($identity['space'] ?? '');
    session_regenerate_id(true);
    if ($space === 'shop') {
        if (account_is_staff((string) $identity['role'])) {
            $_SESSION['is_admin'] = (string) $identity['tenant'];
            if (($identity['account_id'] ?? null) === null) {
                unset($_SESSION['admin_account_id']);
            } else {
                $_SESSION['admin_account_id'] = (int) $identity['account_id'];
                account_touch_login((int) $identity['account_id']);
            }
        } else {
            $_SESSION['customer'] = [
                'tenant' => (string) $identity['tenant'],
                'email' => mb_strtolower((string) $identity['email']),
                'name' => (string) $identity['name'],
                'provider' => 'password',
                'at' => time(),
            ];
            if (($identity['account_id'] ?? null) !== null) account_touch_login((int) $identity['account_id']);
        }
        return;
    }
    if ($space === 'gallery') {
        $slugs = array_values(array_filter((array) ($identity['galleries'] ?? []), 'is_string'));
        $_SESSION['gallery_admin'] = [
            'email' => (string) $identity['email'],
            'slugs' => $slugs,
            'slug' => $slugs[0] ?? '',
            'at' => time(),
        ];
        require_once __DIR__ . '/gallery-admin.php';
        foreach ($slugs as $slug) {
            $g = gallery_load($slug);
            if (!$g) continue;
            foreach ($g['admins'] as &$a) {
                if (mb_strtolower((string) $a['email']) === mb_strtolower((string) $identity['email'])) $a['last_login'] = time();
            }
            unset($a);
            gallery_save($g);
        }
        return;
    }
    $_SESSION['portail_user'] = (string) ($identity['portal_user'] ?? '');
}
