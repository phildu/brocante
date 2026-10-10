<?php

// Connexion par les réseaux sociaux (Google, Facebook, Microsoft, Apple) — OAuth 2.0 / OpenID Connect, sans bibliothèque.
//
// Un seul jeu de clés pour toute la plateforme (portail → Connexion sociale), car un fournisseur n'accepte que des adresses de retour
// déclarées à l'avance : impossible d'y mettre une adresse par boutique. Le retour de chez le fournisseur se fait donc toujours sur UNE
// adresse centrale (/oauth/callback.php du domaine du portail), qui renvoie ensuite le visiteur sur la boutique d'où il venait, avec un
// justificatif signé de courte durée (HMAC avec la clé de signature de la plateforme) :
//
//   boutique ──/oauth/start.php──▶ fournisseur ──▶ domaine central /oauth/callback.php ──▶ boutique /oauth/finish.php
//
//   - state (aller) : signé, 10 min, contient le nonce de la session de la boutique et son adresse de retour ;
//   - justificatif (retour) : signé, 2 min, destiné à UNE boutique (aud), lié au nonce de sa session, utilisable une seule fois.
// Trois usages (ctx) : « signup » (création de boutique), « admin » (administration d'une boutique), « customer » (compte client).
// Une boutique peut aussi avoir SES clés (Administration → Connexion sociale, .secrets/<boutique>/oauth.json) : le retour se fait alors
// directement sur SA propre adresse (/oauth/callback.php de la boutique, à déclarer chez le fournisseur), sans passer par le domaine
// central, et le justificatif est signé avec la clé de signature de la boutique. Les clés de la boutique priment sur celles de la plateforme,
// qui n'est utilisée que pour l'inscription et pour les boutiques sans clés propres. La boutique peut aussi couper un fournisseur,
// la connexion à son administration ou les comptes clients.
// Les clés de la plateforme vivent dans .secrets/oauth_plateforme.json (jamais déployé, illisible par HTTP) ; elles se lisent aussi dans le déploiement
// désigné par .shared-secrets-from (le Petit Chalet utilise ainsi les clés du portail).

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/shared-secrets.php';

const OAUTH_PROVIDERS = [
    'google' => [
        'label' => 'Google', 'scope' => 'openid email profile',
        'auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
        'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'console' => 'https://console.cloud.google.com/apis/credentials',
        'help' => "Console Google Cloud → API et services → Identifiants → Créer des identifiants → ID client OAuth (application Web). Écran de consentement : type « Externe », e-mail et profil.",
    ],
    'facebook' => [
        'label' => 'Facebook', 'scope' => 'email,public_profile',
        'auth' => 'https://www.facebook.com/v21.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v21.0/oauth/access_token',
        'userinfo' => 'https://graph.facebook.com/v21.0/me?fields=id,name,first_name,last_name,email',
        'console' => 'https://developers.facebook.com/apps/',
        'help' => "developers.facebook.com → Créer une app (type « Consommateur » ou « Autre ») → produit « Facebook Login » → Paramètres : URI de redirection OAuth valides. Passez l'app en mode « Live » (politique de confidentialité en ligne requise).",
    ],
    'microsoft' => [
        'label' => 'Microsoft', 'scope' => 'openid email profile',
        'auth' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'userinfo' => '',
        'console' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
        'help' => "Microsoft Entra → Inscriptions d'applications → Nouvelle inscription : comptes dans un annuaire organisationnel ET comptes Microsoft personnels ; URI de redirection de type « Web ». Puis Certificats et secrets → Nouveau secret client (copiez sa VALEUR).",
    ],
    'apple' => [
        'label' => 'Apple', 'scope' => 'name email',
        'auth' => 'https://appleid.apple.com/auth/authorize', 'token' => 'https://appleid.apple.com/auth/token',
        'userinfo' => '',
        'console' => 'https://developer.apple.com/account/resources/identifiers/list/serviceId',
        'help' => "Compte Apple Developer : créez un identifiant « Services ID » (c'est l'ID client) avec « Sign in with Apple » et l'adresse de retour (HTTPS obligatoire), puis une clé « Sign in with Apple » (fichier .p8, identifiant de clé et Team ID). Pas de secret à copier : il est calculé à partir de la clé.",
    ],
];

/** Identifiant du tenant « consommateur » de Microsoft : un e-mail venant de ce tenant est un compte personnel dont l'adresse est vérifiée. */
const OAUTH_MICROSOFT_PERSONAL_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

// ── Configuration ───────────────────────────────────────────────────────────

function oauth_is_dev(): bool
{
    return is_file(dirname(__DIR__) . '/config.local.php');
}

function oauth_config_path(): string
{
    return dirname(__DIR__) . '/.secrets/oauth_plateforme.json';
}

/** Fichiers de configuration à lire : celui de ce déploiement, puis celui du déploiement désigné par .shared-secrets-from. */
function oauth_config_read_path(): ?string
{
    $own = oauth_config_path();
    if (is_file($own)) return $own;
    foreach (shared_secrets_read_dirs() as $dir) {
        $f = dirname($dir) . '/oauth_plateforme.json';
        if (is_file($f)) return $f;
    }
    return null;
}

function oauth_config_defaults(): array
{
    $providers = [];
    foreach (OAUTH_PROVIDERS as $k => $_) {
        $providers[$k] = ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'team_id' => '', 'key_id' => '', 'private_key' => ''];
    }
    return ['signing_key' => '', 'callback_origin' => '', 'return_hosts' => [], 'dev_base' => '', 'providers' => $providers];
}

function oauth_config(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = oauth_config_defaults();
    $file = oauth_config_read_path();
    $saved = $file ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    foreach (['signing_key', 'callback_origin', 'dev_base'] as $k) {
        if (isset($saved[$k]) && is_string($saved[$k])) $cfg[$k] = trim($saved[$k]);
    }
    if (isset($saved['return_hosts']) && is_array($saved['return_hosts'])) {
        $cfg['return_hosts'] = array_values(array_filter(array_map(static fn ($h) => strtolower(trim((string) $h)), $saved['return_hosts'])));
    }
    foreach ($cfg['providers'] as $k => $def) {
        foreach ($def as $f => $_) {
            $v = $saved['providers'][$k][$f] ?? null;
            if ($f === 'enabled') $cfg['providers'][$k][$f] = !empty($v);
            elseif (is_string($v)) $cfg['providers'][$k][$f] = trim($v);
        }
    }
    if (!oauth_is_dev()) $cfg['dev_base'] = ''; // l'adresse de simulation n'a de sens qu'en développement
    return $cache = $cfg;
}

function oauth_config_save(array $cfg): bool
{
    $file = oauth_config_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if ($cfg['signing_key'] === '') $cfg['signing_key'] = bin2hex(random_bytes(32));
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

/** Les champs d'un fournisseur sont-ils complets (clé Apple : Team ID, Key ID et clé privée ; les autres : ID et secret) ? */
function oauth_fields_complete(string $key, array $p): bool
{
    if (($p['client_id'] ?? '') === '') return false;
    return $key === 'apple' ? (($p['team_id'] ?? '') !== '' && ($p['key_id'] ?? '') !== '' && ($p['private_key'] ?? '') !== '') : ($p['client_secret'] ?? '') !== '';
}

/** Le fournisseur est-il prêt côté PLATEFORME (activé, clés complètes, clé de signature) ? */
function oauth_provider_ready(string $key): bool
{
    $cfg = oauth_config();
    $p = $cfg['providers'][$key] ?? null;
    return $p && $p['enabled'] && $cfg['signing_key'] !== '' && oauth_fields_complete($key, $p);
}

// ── Réglages et clés propres à une boutique ─────────────────────────────────

function oauth_shop_file(): string
{
    return tenant_path('secrets_dir') . '/oauth.json';
}

/**
 * Réglages de la boutique active : admin / customer (la connexion sociale est-elle offerte à l'administration / aux clients ?), off (fournisseurs
 * coupés), providers (ses propres clés, prioritaires sur celles de la plateforme) et signing_key (signature de ses justificatifs).
 */
function oauth_shop_config(bool $reload = false): array
{
    static $cache = [];
    $file = oauth_shop_file();
    if ($reload || !isset($cache[$file])) {
        $cfg = ['admin' => true, 'customer' => true, 'off' => [], 'signing_key' => '', 'providers' => []];
        $saved = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
        foreach (['admin', 'customer'] as $k) if (array_key_exists($k, $saved)) $cfg[$k] = !empty($saved[$k]);
        if (is_string($saved['signing_key'] ?? null)) $cfg['signing_key'] = trim($saved['signing_key']);
        $cfg['off'] = array_values(array_intersect(array_keys(OAUTH_PROVIDERS), (array) ($saved['off'] ?? [])));
        foreach (OAUTH_PROVIDERS as $k => $_) {
            $cfg['providers'][$k] = ['client_id' => '', 'client_secret' => '', 'team_id' => '', 'key_id' => '', 'private_key' => ''];
            foreach ($cfg['providers'][$k] as $f => $_v) {
                if (is_string($saved['providers'][$k][$f] ?? null)) $cfg['providers'][$k][$f] = trim($saved['providers'][$k][$f]);
            }
        }
        $cache[$file] = $cfg;
    }
    return $cache[$file];
}

function oauth_shop_config_save(array $cfg): bool
{
    $file = oauth_shop_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if ($cfg['signing_key'] === '') $cfg['signing_key'] = bin2hex(random_bytes(32));
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) return false;
    @chmod($tmp, 0600);
    $ok = @rename($tmp, $file);
    oauth_shop_config(true);
    return $ok;
}

// ── Quelles clés pour quel contexte ─────────────────────────────────────────

/**
 * Clés à utiliser, sous la forme ['mode' => 'platform'|'own', 'fields' => clés du fournisseur, 'redirect' => adresse de retour déclarée chez lui,
 * 'sign' => clé de signature], ou null si ce fournisseur n'est pas disponible. Plateforme : retour sur le domaine central.
 */
function oauth_platform_creds(string $key): ?array
{
    if (!oauth_provider_ready($key)) return null;
    $cfg = oauth_config();
    return ['mode' => 'platform', 'fields' => $cfg['providers'][$key], 'redirect' => oauth_callback_url(), 'sign' => $cfg['signing_key']];
}

/** Clés propres à la boutique active : retour directement sur sa propre adresse (avec son préfixe éventuel). */
function oauth_own_creds(string $key): ?array
{
    $s = oauth_shop_config();
    if (!oauth_fields_complete($key, $s['providers'][$key] ?? []) || $s['signing_key'] === '') return null;
    return ['mode' => 'own', 'fields' => $s['providers'][$key], 'redirect' => oauth_return_base() . '/oauth/callback.php', 'sign' => $s['signing_key']];
}

/**
 * Clés d'un fournisseur pour un contexte. L'inscription (signup) n'utilise que la plateforme. L'administration et les comptes clients d'une
 * boutique respectent ses réglages (usage coupé, fournisseur coupé) puis préfèrent ses clés propres à celles de la plateforme.
 */
function oauth_creds(string $key, string $ctx): ?array
{
    if (!isset(OAUTH_PROVIDERS[$key])) return null;
    if ($ctx === 'signup') return oauth_platform_creds($key);
    $s = oauth_shop_config();
    if (!($ctx === 'admin' ? $s['admin'] : $s['customer']) || in_array($key, $s['off'], true)) return null;
    return oauth_own_creds($key) ?? oauth_platform_creds($key);
}

/** Fournisseurs disponibles pour un contexte : clé → libellé. */
function oauth_enabled_providers(string $ctx = 'signup'): array
{
    $out = [];
    foreach (OAUTH_PROVIDERS as $k => $def) {
        if (oauth_creds($k, $ctx)) $out[$k] = $def['label'];
    }
    return $out;
}

/** Adresses du fournisseur (remplacées par celles d'un simulateur en développement si « dev_base » est renseigné). */
function oauth_provider_def(string $key): array
{
    $def = OAUTH_PROVIDERS[$key];
    $dev = oauth_config()['dev_base'];
    if ($dev !== '') {
        $dev = rtrim($dev, '/');
        $def['auth'] = "$dev/$key/authorize";
        $def['token'] = "$dev/$key/token";
        $def['userinfo'] = $def['userinfo'] !== '' ? "$dev/$key/userinfo" : '';
    }
    return $def;
}

// ── Adresses ────────────────────────────────────────────────────────────────

function oauth_request_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

/** Adresse de la boutique d'où part la connexion (avec son préfixe éventuel : https://brocs.exemple.fr/naty). */
function oauth_return_base(): string
{
    return oauth_request_origin() . tenant_base_path();
}

/** Domaine central : celui du retour du fournisseur. Réglage « callback_origin », sinon le domaine de la plateforme (sans sous-domaine de boutique). */
function oauth_central_origin(): string
{
    $cfg = oauth_config();
    if ($cfg['callback_origin'] !== '') return rtrim($cfg['callback_origin'], '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $port = preg_match('/:\d+$/', $host, $m) ? $m[0] : '';
    return $scheme . '://' . tenant_base_host(preg_replace('/:\d+$/', '', $host)) . $port;
}

function oauth_callback_url(): string
{
    return oauth_central_origin() . '/oauth/callback.php';
}

/**
 * Une adresse de retour est-elle acceptable ? (le justificatif signé n'est remis qu'à un domaine connu)
 * Oui pour le domaine central et ses sous-domaines de boutique, les domaines des boutiques de ce serveur (site_url) et les
 * domaines ajoutés dans les réglages.
 */
function oauth_return_allowed(string $base): bool
{
    $p = parse_url($base);
    $host = strtolower((string) ($p['host'] ?? ''));
    if ($host === '' || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) return false;
    $central = parse_url(oauth_central_origin(), PHP_URL_HOST);
    if ($host === $central || tenant_base_host($host) === $central || str_ends_with($host, '.' . $central)) return true;
    if (in_array($host, oauth_config()['return_hosts'], true)) return true;
    foreach (array_keys(tenant_list()) as $slug) {
        $h = strtolower((string) parse_url((string) (tenant_load($slug)['site_url'] ?? ''), PHP_URL_HOST));
        if ($h !== '' && $h === $host) return true;
    }
    return false;
}

// ── Justificatifs signés ────────────────────────────────────────────────────

function oauth_b64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function oauth_unb64(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/'), true);
}

function oauth_sign(array $payload, int $ttl, string $key): string
{
    if ($key === '') throw new RuntimeException('La connexion sociale n\'est pas configurée.');
    $payload['exp'] = time() + $ttl;
    $body = oauth_b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $body . '.' . oauth_b64(hash_hmac('sha256', $body, $key, true));
}

/** Contenu d'un justificatif dont la signature est bonne avec cette clé et la durée non dépassée, sinon null. */
function oauth_verify(string $token, string $key): ?array
{
    if ($key === '' || substr_count($token, '.') !== 1) return null;
    [$body, $sig] = explode('.', $token);
    if (!hash_equals(hash_hmac('sha256', $body, $key, true), oauth_unb64($sig))) return null;
    $data = json_decode(oauth_unb64($body), true);
    return is_array($data) && (int) ($data['exp'] ?? 0) >= time() ? $data : null;
}

/**
 * Vérifie un justificatif avec la clé de la plateforme ou celle de la boutique active. Son mode (« m ») doit correspondre à la clé qui l'a
 * signé : un justificatif « plateforme » ne s'accepte qu'avec la clé de la plateforme, un justificatif « boutique » qu'avec celle de CETTE boutique.
 */
function oauth_verify_any(string $token): ?array
{
    foreach (['platform' => oauth_config()['signing_key'], 'own' => oauth_shop_config()['signing_key']] as $mode => $key) {
        $data = oauth_verify($token, $key);
        if ($data && ($data['m'] ?? '') === $mode) return $data;
    }
    return null;
}

// ── Aller chez le fournisseur ───────────────────────────────────────────────

/** Adresse de la page d'autorisation du fournisseur (state = justificatif signé produit par start.php). */
function oauth_authorize_url(string $key, string $state, array $creds): string
{
    $def = oauth_provider_def($key);
    $q = ['client_id' => $creds['fields']['client_id'], 'redirect_uri' => $creds['redirect'], 'response_type' => 'code', 'scope' => $def['scope'], 'state' => $state];
    if ($key === 'google' || $key === 'microsoft') $q['prompt'] = 'select_account';
    if ($key === 'apple') $q['response_mode'] = 'form_post';
    return $def['auth'] . (str_contains($def['auth'], '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

// ── Retour du fournisseur ───────────────────────────────────────────────────

function oauth_http(string $method, string $url, array $form = [], array $headers = []): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&');
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('Le fournisseur ne répond pas (' . curl_error($ch) . ').');
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['status' => $status, 'json' => json_decode($body, true) ?? []];
}

/** Contenu (non vérifié) d'un id_token OpenID reçu directement du point d'accès « token » du fournisseur, en HTTPS. */
function oauth_jwt_claims(string $jwt): array
{
    $parts = explode('.', $jwt);
    return count($parts) === 3 ? (json_decode(oauth_unb64($parts[1]), true) ?: []) : [];
}

/** Signature ES256 : conversion du format DER d'OpenSSL en R||S (64 octets) attendu par les JWT. */
function oauth_der_to_raw(string $der): string
{
    $o = 2;
    if ((ord($der[1]) & 0x80) !== 0) $o += ord($der[1]) & 0x7f;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]);
        $parts[] = substr($der, $o + 2, $len);
        $o += 2 + $len;
    }
    return implode('', array_map(static fn (string $n): string => str_pad(ltrim($n, "\x00"), 32, "\x00", STR_PAD_LEFT), $parts));
}

/** « Secret client » d'Apple : un JWT de 5 minutes signé avec la clé privée .p8 (ES256). */
function oauth_apple_client_secret(array $p): string
{
    $head = oauth_b64(json_encode(['alg' => 'ES256', 'kid' => $p['key_id']]));
    $claims = oauth_b64(json_encode(['iss' => $p['team_id'], 'iat' => time(), 'exp' => time() + 300, 'aud' => 'https://appleid.apple.com', 'sub' => $p['client_id']]));
    $pem = $p['private_key'];
    if (!str_contains($pem, 'BEGIN')) $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END PRIVATE KEY-----\n";
    $key = openssl_pkey_get_private($pem);
    if (!$key || !openssl_sign("$head.$claims", $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Clé privée Apple illisible.');
    return "$head.$claims." . oauth_b64(oauth_der_to_raw($der));
}

/**
 * Échange le code d'autorisation contre le profil de la personne :
 * ['provider', 'sub', 'email', 'verified' (adresse vérifiée par le fournisseur), 'name', 'first', 'last'].
 * Lève une RuntimeException (message affichable) en cas d'échec.
 */
function oauth_fetch_profile(string $key, string $code, array $creds): array
{
    $def = oauth_provider_def($key);
    $p = $creds['fields'];
    $secret = $key === 'apple' ? oauth_apple_client_secret($p) : $p['client_secret'];
    $tok = oauth_http('POST', $def['token'], ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $creds['redirect'], 'client_id' => $p['client_id'], 'client_secret' => $secret]);
    if ($tok['status'] >= 400 || empty($tok['json']['access_token']) && empty($tok['json']['id_token'])) {
        error_log("oauth $key token : HTTP {$tok['status']} " . json_encode($tok['json']));
        throw new RuntimeException('Le fournisseur a refusé la connexion (' . ($tok['json']['error_description'] ?? $tok['json']['error'] ?? 'erreur ' . $tok['status']) . ').');
    }
    $access = (string) ($tok['json']['access_token'] ?? '');
    $first = $last = '';
    if ($key === 'google' || $key === 'facebook') {
        $url = $def['userinfo'];
        $headers = [];
        if ($key === 'google') $headers[] = 'Authorization: Bearer ' . $access;
        else $url .= (str_contains($url, '?') ? '&' : '?') . 'access_token=' . rawurlencode($access);
        $info = oauth_http('GET', $url, [], $headers);
        if ($info['status'] >= 400) throw new RuntimeException('Impossible de lire le profil auprès du fournisseur.');
        $j = $info['json'];
        $email = strtolower(trim((string) ($j['email'] ?? '')));
        $verified = $key === 'google' ? filter_var($j['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) : $email !== ''; // Facebook ne renvoie qu'une adresse confirmée
        $sub = (string) ($j['sub'] ?? $j['id'] ?? '');
        $name = trim((string) ($j['name'] ?? ''));
        $first = (string) ($j['given_name'] ?? $j['first_name'] ?? '');
        $last = (string) ($j['family_name'] ?? $j['last_name'] ?? '');
    } else { // microsoft, apple : le profil est dans l'id_token
        $c = oauth_jwt_claims((string) ($tok['json']['id_token'] ?? ''));
        $email = strtolower(trim((string) ($c['email'] ?? ($key === 'microsoft' ? ($c['preferred_username'] ?? '') : ''))));
        if ($key === 'microsoft') {
            $verified = ($c['tid'] ?? '') === OAUTH_MICROSOFT_PERSONAL_TENANT || filter_var($c['xms_edov'] ?? false, FILTER_VALIDATE_BOOLEAN);
        } else {
            $verified = filter_var($c['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $sub = (string) ($c['sub'] ?? $c['oid'] ?? '');
        $name = trim((string) ($c['name'] ?? ''));
    }
    if ($sub === '') throw new RuntimeException('Réponse inattendue du fournisseur.');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Le fournisseur n\'a pas communiqué d\'adresse e-mail : autorisez l\'accès à votre e-mail ou utilisez une autre méthode.');
    return ['provider' => $key, 'sub' => $sub, 'email' => $email, 'verified' => (bool) $verified, 'name' => $name, 'first' => $first, 'last' => $last];
}

/** Sépare un nom complet en prénom / nom quand le fournisseur ne les donne pas à part. */
function oauth_split_name(string $name, string $first = '', string $last = ''): array
{
    if ($first !== '' || $last !== '') return [$first, $last];
    $parts = preg_split('/\s+/', trim($name), 2) ?: [];
    return [$parts[0] ?? '', $parts[1] ?? ''];
}

// ── Boutons « Continuer avec… » ─────────────────────────────────────────────

const OAUTH_ICONS = [
    'google' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8z"/><path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.3v3.1A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.3a12 12 0 0 0 0 10.8z"/><path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.3 6.6l4 3.1C6.2 6.9 8.9 4.8 12 4.8z"/></svg>',
    'facebook' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v3h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/></svg>',
    'microsoft' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#F25022" d="M1 1h10.5v10.5H1z"/><path fill="#7FBA00" d="M12.5 1H23v10.5H12.5z"/><path fill="#00A4EF" d="M1 12.5h10.5V23H1z"/><path fill="#FFB900" d="M12.5 12.5H23V23H12.5z"/></svg>',
    'apple' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/></svg>',
];

/**
 * Boutons des fournisseurs actifs. $ctx : signup | admin | customer. Les boutons sont des boutons de formulaire : placés dans le
 * formulaire d'inscription, ils emportent ses champs (formule, case des conditions, jeton) ; sinon (ctx admin / customer) le HTML
 * produit son propre formulaire. $extra : champs cachés ajoutés (ex. next). Retourne '' si aucun fournisseur n'est actif.
 */
function oauth_buttons_html(string $ctx, array $extra = [], bool $ownForm = true): string
{
    $providers = oauth_enabled_providers($ctx);
    if (!$providers) return '';
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $btns = '';
    foreach ($providers as $k => $label) {
        $btns .= '<button type="submit" class="oauth-btn oauth-' . $k . '" name="provider" value="' . $k . '" formaction="/oauth/start.php" formmethod="post" formnovalidate>'
            . OAUTH_ICONS[$k] . '<span>Continuer avec ' . $esc($label) . '</span></button>';
    }
    $hidden = '<input type="hidden" name="ctx" value="' . $esc($ctx) . '">';
    foreach ($extra as $n => $v) $hidden .= '<input type="hidden" name="' . $esc((string) $n) . '" value="' . $esc((string) $v) . '">';
    $html = '<div class="oauth-box">' . $hidden . $btns . '</div>';
    return $ownForm ? '<form method="post" action="/oauth/start.php" class="oauth-form">' . $html . '</form>' : $html;
}

// ── Compte client de la boutique ────────────────────────────────────────────

/** Client connecté à CETTE boutique par un réseau social : ['email', 'name', 'provider', …], ou null. */
function customer_session(): ?array
{
    $c = $_SESSION['customer'] ?? null;
    return is_array($c) && ($c['tenant'] ?? '') === tenant_slug() ? $c : null;
}

/** Commandes payées d'un client (rapprochées par e-mail), les plus récentes d'abord. Les e-mails sont vérifiés par le fournisseur à la connexion. */
function customer_orders(string $email): array
{
    $stmt = db()->prepare("SELECT * FROM orders WHERE status = 'paid' AND lower(trim(email)) = ? ORDER BY id DESC LIMIT 50");
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetchAll();
}
