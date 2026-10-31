<?php

// Envoi de SMS de la plateforme, utilisé par le code de connexion, les annonces
// des actions commerciales et les alertes de l'assistance vendeur.
//
// Deux fournisseurs au choix : OVH (compte SMS, identifiant, mot de passe et
// expéditeur) ou Twilio (SID, jeton, numéro). Les réglages de la boutique sont
// lus en priorité, sinon les variables d'environnement OVH_SMS_* / TWILIO_*.
// « sms_provider » (ou SMS_PROVIDER) force le fournisseur : auto par défaut,
// c'est-à-dire le premier des deux dont les identifiants sont complets.
//
// En local (hôte sans point, « .test », « .localhost » ou adresse IP), le message
// est écrit dans data/sms-outbox.log : le parcours reste testable sans crédit.

require_once __DIR__ . '/recto-verso.php'; // shop_setting()

/** Réglage de la boutique, sinon variable d'environnement. */
function sms_setting(string $name, string $env, string $default = ''): string
{
    $v = trim((string) getenv($env));
    if ($v !== '') return $v;
    try {
        return trim(shop_setting($name, $default));
    } catch (Throwable $e) {
        return $default;
    }
}

/** Hôte local ? (même règle que les e-mails et le code de connexion) */
function sms_local_host(): bool
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP);
}

/** Identifiants OVH : ['account', 'login', 'password', 'from', 'nostop']. */
function sms_ovh_config(): array
{
    return [
        'account' => sms_setting('ovh_sms_account', 'OVH_SMS_ACCOUNT'),
        'login' => sms_setting('ovh_sms_login', 'OVH_SMS_LOGIN'),
        'password' => sms_setting('ovh_sms_password', 'OVH_SMS_PASSWORD'),
        'from' => sms_setting('ovh_sms_from', 'OVH_SMS_FROM'),
        'nostop' => sms_setting('ovh_sms_nostop', 'OVH_SMS_NOSTOP', '1') !== '0',
    ];
}

/** Identifiants Twilio : ['sid', 'token', 'from']. */
function sms_twilio_config(): array
{
    return [
        'sid' => sms_setting('twilio_sid', 'TWILIO_ACCOUNT_SID'),
        'token' => sms_setting('twilio_token', 'TWILIO_AUTH_TOKEN'),
        'from' => sms_setting('twilio_from', 'TWILIO_FROM'),
    ];
}

/** Le fournisseur est-il complet ? */
function sms_provider_ready(string $provider): bool
{
    if ($provider === 'ovh') {
        $c = sms_ovh_config();
        return $c['account'] !== '' && $c['login'] !== '' && $c['password'] !== '' && $c['from'] !== '';
    }
    if ($provider === 'twilio') {
        $c = sms_twilio_config();
        return $c['sid'] !== '' && $c['token'] !== '' && $c['from'] !== '';
    }
    return false;
}

/** Fournisseur retenu : « ovh », « twilio » ou '' si aucun n'est complet. */
function sms_provider_name(): string
{
    $chosen = strtolower(sms_setting('sms_provider', 'SMS_PROVIDER'));
    if ($chosen === 'ovh' || $chosen === 'twilio') return sms_provider_ready($chosen) ? $chosen : '';
    foreach (['ovh', 'twilio'] as $p) {
        if (sms_provider_ready($p)) return $p;
    }
    return '';
}

function sms_provider_label(?string $provider = null): string
{
    $provider ??= sms_provider_name();
    return match ($provider) {
        'ovh' => 'OVH',
        'twilio' => 'Twilio',
        default => '',
    };
}

/** Un SMS peut-il partir ? (fournisseur complet, ou mode local pour tester) */
function sms_available(): bool
{
    return sms_local_host() || sms_provider_name() !== '';
}

/**
 * Numéro au format international « +33612345678 » attendu par OVH et Twilio.
 * « 06 12 34 56 78 » et « 0033… » sont convertis ; null si le numéro n'a pas
 * l'air d'un numéro international valide (8 à 15 chiffres).
 */
function sms_phone_e164(string $raw): ?string
{
    $p = preg_replace('/[\s.\-()\/]/', '', trim($raw));
    if ($p === '' || !preg_match('/^\+?\d+$/', $p)) return null;
    if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
    if ($p[0] !== '+' && preg_match('/^0[1-9]\d{8}$/', $p)) $p = '+33' . substr($p, 1);
    if ($p[0] !== '+') $p = '+' . $p;
    $n = strlen($p) - 1;
    return $n >= 8 && $n <= 15 ? $p : null;
}

/** Alphabet GSM 03.38 par défaut : un texte fait seulement de ces caractères tient en 7 bits. */
function sms_gsm7_compatible(string $body): bool
{
    $alphabet = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    $len = mb_strlen($body, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        if (!str_contains($alphabet, mb_substr($body, $i, 1, 'UTF-8'))) return false;
    }
    return true;
}

/** Paramètres http2sms d'OVH (séparés pour être vérifiables sans réseau). */
function sms_ovh_params(array $config, string $toE164, string $body): array
{
    $params = [
        'account' => $config['account'],
        'login' => $config['login'],
        'password' => $config['password'],
        'from' => $config['from'],
        'to' => $toE164,
        // OVH attend %0d comme retour à la ligne.
        'message' => preg_replace('/\r\n|\r|\n/', "\r", $body),
        // 0 = affiché immédiatement, pas mémorisé dans le téléphone.
        'class' => '0',
        // 1 = 7 bits (160 car.), 2 = Unicode (70 car.) : les accents hors alphabet GSM
        // (œ, €, apostrophes typographiques…) imposent l'Unicode sous peine de « ? ».
        'smsCoding' => sms_gsm7_compatible($body) ? '1' : '2',
    ];
    if (!empty($config['nostop'])) $params['noStop'] = '1';
    return $params;
}

/** POST formulaire ; -> [code HTTP, corps de la réponse]. */
function sms_http_post(string $url, string $data, array $headers = [], ?string $userpwd = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
        ];
        if ($userpwd !== null) $opts[CURLOPT_USERPWD] = $userpwd;
        curl_setopt_array($ch, $opts);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers)),
        'content' => $data,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $body = (string) @file_get_contents($url, false, $ctx);
    $status = 0;
    // PHP 8.4 déprécie $http_response_header dans une fonction : la fonction dédiée le remplace.
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : ($http_response_header ?? []);
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) $status = (int) $m[1];
    return [$status, $body];
}

/** Réponse text/plain d'OVH : « OK » + crédit + identifiant, ou « KO » + motif. */
function sms_ovh_answer_ok(string $answer): bool
{
    return preg_match('/^OK\b/i', trim($answer)) === 1;
}

/**
 * Envoi OVH (http2sms). En text/plain, OVH répond « OK » puis le crédit restant
 * et l'identifiant ; une erreur commence par « KO ». Le statut HTTP n'est pas
 * fiable pour juger du succès.
 */
function sms_send_ovh(string $to, string $body): bool
{
    $config = sms_ovh_config();
    if (!sms_provider_ready('ovh')) return false;
    $number = sms_phone_e164($to);
    if ($number === null) return false;
    [$status, $answer] = sms_http_post('https://www.ovh.com/cgi-bin/sms/http2sms.cgi', http_build_query(sms_ovh_params($config, $number, $body)));
    if (sms_ovh_answer_ok($answer)) return true;
    error_log('SMS OVH refusé (HTTP ' . $status . ') : ' . mb_substr(trim($answer), 0, 200));
    return false;
}

/** Envoi Twilio (API Messages). */
function sms_send_twilio(string $to, string $body): bool
{
    $config = sms_twilio_config();
    if (!sms_provider_ready('twilio')) return false;
    $number = sms_phone_e164($to);
    if ($number === null) return false;
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($config['sid']) . '/Messages.json';
    $data = http_build_query(['To' => $number, 'From' => $config['from'], 'Body' => $body]);
    [$status] = sms_http_post($url, $data, [], $config['sid'] . ':' . $config['token']);
    return $status >= 200 && $status < 300;
}

/** Envoi d'un SMS par le fournisseur retenu ; en local, copie dans data/sms-outbox.log. */
function sms_send(string $to, string $body): bool
{
    $provider = sms_provider_name();
    if ($provider === 'ovh') return sms_send_ovh($to, $body);
    if ($provider === 'twilio') return sms_send_twilio($to, $body);
    if (!sms_local_host()) return false;
    @file_put_contents(__DIR__ . '/../data/sms-outbox.log',
        '[' . date('c') . "] À : $to\n$body\n----\n", FILE_APPEND | LOCK_EX);
    return true;
}
