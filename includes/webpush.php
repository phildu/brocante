<?php
/**
 * Web Push sans bibliothèque externe : chiffrement du message (RFC 8291, aes128gcm)
 * et identification du serveur (VAPID, RFC 8292). Seule l'extension openssl est requise.
 *
 * Les clés VAPID (une paire par installation) sont créées au premier besoin dans
 * .secrets/webpush.json, à côté de notifications.json : jamais dans la base, jamais déployées.
 * Changer de clés invalide tous les abonnements existants (ils sont liés à la clé publique).
 *
 * Ce fichier ne dépend de rien d'autre que de nt_config_path() (appelée à l'exécution).
 */

/** Hôtes des services de push des navigateurs : l'endpoint est fourni par le navigateur, on n'appelle que ceux-là (anti-SSRF). */
const WP_ENDPOINT_RE = '#^https://(?:fcm\.googleapis\.com|android\.googleapis\.com|(?:[a-z0-9-]+\.)?push\.services\.mozilla\.com|(?:[a-z0-9-]+\.)*push\.apple\.com|[a-z0-9.-]+\.notify\.windows\.com)/[\x21-\x7E]{1,1500}$#';

function wp_b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function wp_b64u_dec(string $s): ?string
{
    if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s)) return null;
    $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $bin === false ? null : $bin;
}

/** Les fonctions openssl nécessaires (ECDH, GCM) sont-elles là ? */
function wp_supported(): bool
{
    return function_exists('openssl_pkey_derive') && function_exists('openssl_pkey_new')
        && in_array('aes-128-gcm', openssl_get_cipher_methods(), true) && in_array('prime256v1', openssl_get_curve_names() ?: [], true);
}

/* ---------- clés VAPID ---------- */

function wp_keys_path(): string
{
    return dirname(nt_config_path()) . '/webpush.json';
}

/** Paire de clés VAPID ['public' => base64url (65 octets), 'private' => base64url (32 octets)] ; null si impossible. */
function wp_keys(bool $create = true): ?array
{
    static $cache = [];
    $path = wp_keys_path();
    if (isset($cache[$path])) return $cache[$path];
    if (is_file($path)) {
        $j = json_decode((string) @file_get_contents($path), true);
        if (is_array($j) && wp_b64u_dec((string) ($j['public'] ?? '')) !== null && wp_b64u_dec((string) ($j['private'] ?? '')) !== null) {
            return $cache[$path] = ['public' => $j['public'], 'private' => $j['private']];
        }
        return null; // fichier présent mais illisible : on ne l'écrase jamais
    }
    if (!$create || !wp_supported()) return null;
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = $key ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
    if (!$ec) return null;
    $pad = fn(string $b) => str_pad($b, 32, "\0", STR_PAD_LEFT);
    $keys = ['public' => wp_b64u_enc("\x04" . $pad($ec['x']) . $pad($ec['y'])), 'private' => wp_b64u_enc($pad($ec['d']))];
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
    if (@file_put_contents($path, json_encode($keys), LOCK_EX) === false) return null;
    @chmod($path, 0600);
    return $cache[$path] = $keys;
}

/** Le canal peut-il fonctionner ici ? (openssl présent et clés lisibles, ou dossier où les créer) */
function wp_ready(): bool
{
    if (!wp_supported()) return false;
    return is_file(wp_keys_path()) ? wp_keys(false) !== null : is_writable(dirname(wp_keys_path())) || is_writable(dirname(dirname(wp_keys_path())));
}

/** Clé publique (base64url) à donner au navigateur pour s'abonner. */
function wp_public_key(): string
{
    return wp_keys()['public'] ?? '';
}

/* ---------- courbe P-256 : construction des clés openssl à partir des octets bruts ---------- */

function wp_ec_public_pem(string $point65): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function wp_ec_private_pem(string $d32, string $point65): string
{
    $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $point65;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/* ---------- chiffrement du message (RFC 8291) ---------- */

/**
 * Corps chiffré (aes128gcm) d'un message pour un abonnement.
 * $asPrivate / $asPublic / $salt : uniquement pour les tests (vecteur de l'annexe A de la RFC) ; sinon aléatoires.
 * @throws RuntimeException clé d'abonnement invalide ou openssl défaillant
 */
function wp_encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $asPrivate = null, ?string $salt = null, ?string $asPublic = null): string
{
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) throw new RuntimeException('Clés d\'abonnement invalides');
    if (strlen($plaintext) > 3800) throw new RuntimeException('Message trop long pour un push');

    if ($asPrivate === null || $asPublic === null) {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = $k ? (openssl_pkey_get_details($k)['ec'] ?? null) : null;
        if (!$ec) throw new RuntimeException('Clé éphémère impossible');
        $asPrivate = str_pad($ec['d'], 32, "\0", STR_PAD_LEFT);
        $asPublic = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }
    $salt ??= random_bytes(16);

    $uaKey = openssl_pkey_get_public(wp_ec_public_pem($uaPublic));
    $asKey = openssl_pkey_get_private(wp_ec_private_pem($asPrivate, $asPublic));
    if (!$uaKey || !$asKey) throw new RuntimeException('Clé de l\'abonné rejetée par openssl');
    $shared = openssl_pkey_derive($uaKey, $asKey);
    if ($shared === false) throw new RuntimeException('ECDH impossible');

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $tag = '';
    $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) throw new RuntimeException('Chiffrement impossible');
    // En-tête : sel (16) + taille d'enregistrement (4) + longueur de l'identifiant (1) + clé publique éphémère (65)
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/* ---------- VAPID (RFC 8292) ---------- */

/**
 * En-tête Authorization « vapid t=<jwt>, k=<clé publique> » pour un endpoint.
 * @param string $subject mailto: ou https: (contact de l'expéditeur)
 */
function wp_vapid_authorization(string $endpoint, string $subject, ?int $now = null): string
{
    $keys = wp_keys();
    if (!$keys) throw new RuntimeException('Clés VAPID indisponibles');
    $p = parse_url($endpoint);
    $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    $head = wp_b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = wp_b64u_enc(json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    $d = wp_b64u_dec($keys['private']);
    $pub = wp_b64u_dec($keys['public']);
    $key = openssl_pkey_get_private(wp_ec_private_pem($d, $pub));
    if (!$key || !openssl_sign("$head.$claims", $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signature VAPID impossible');
    return 'vapid t=' . "$head.$claims." . wp_b64u_enc(wp_der_to_raw_sig($der)) . ', k=' . $keys['public'];
}

/** Signature ECDSA DER -> r||s (2 × 32 octets), format attendu par JWT ES256. */
function wp_der_to_raw_sig(string $der): string
{
    $o = 2; // 0x30 len
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7F;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]);
        $int = substr($der, $o + 2, $len);
        $parts[] = str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $o += 2 + $len;
    }
    return $parts[0] . $parts[1];
}

/* ---------- abonnement (adresse du canal « push ») ---------- */

/**
 * Valide un abonnement envoyé par le navigateur et le ramène à sa forme canonique (JSON) ;
 * null si invalide. Forme attendue : {"endpoint": "...", "keys": {"p256dh": "...", "auth": "..."}}
 * ou déjà canonique {"endpoint", "p256dh", "auth"}.
 */
function wp_normalize_subscription(string $raw): ?string
{
    if (strlen($raw) > 2500) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    $endpoint = (string) ($j['endpoint'] ?? '');
    $p256 = (string) ($j['keys']['p256dh'] ?? $j['p256dh'] ?? '');
    $auth = (string) ($j['keys']['auth'] ?? $j['auth'] ?? '');
    if (!preg_match(WP_ENDPOINT_RE, $endpoint)) return null;
    $pk = wp_b64u_dec($p256);
    $au = wp_b64u_dec($auth);
    if ($pk === null || strlen($pk) !== 65 || $pk[0] !== "\x04" || $au === null || strlen($au) !== 16) return null;
    return json_encode(['endpoint' => $endpoint, 'p256dh' => $p256, 'auth' => $auth], JSON_UNESCAPED_SLASHES);
}
