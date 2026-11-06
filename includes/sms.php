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
// Les identifiants se règlent une fois pour toutes les boutiques dans le portail
// (/portail/sms.php, data/sms-partage.json) ; une boutique peut encore saisir les
// siens dans son administration, ils restent prioritaires. Ordre de lecture :
// variable d'environnement, réglages de la boutique, réglages du portail.
//
// En local (hôte sans point, « .test », « .localhost » ou adresse IP), le message
// est écrit dans data/sms-outbox.log : le parcours reste testable sans crédit.

require_once __DIR__ . '/recto-verso.php';   // shop_setting()
require_once __DIR__ . '/sms-shared.php';    // sms_shared(), sms_sender_valid()
require_once __DIR__ . '/sms-credits.php';   // crédit SMS par boutique

/** Réglage SMS effectif : variable d'environnement, puis boutique, puis portail. */
function sms_setting(string $name, string $env, string $default = ''): string
{
    $v = trim((string) getenv($env));
    if ($v !== '') return $v;
    try {
        $shop = trim(shop_setting($name, ''));
    } catch (Throwable $e) {
        $shop = '';
    }
    if ($shop !== '') return $shop;
    $shared = sms_shared_get($name);
    return $shared !== '' ? $shared : $default;
}

/** D'où vient un réglage SMS : « env », « boutique », « portail » ou '' si jamais réglé. */
function sms_setting_origin(string $name, string $env): string
{
    if (trim((string) getenv($env)) !== '') return 'env';
    try {
        if (trim(shop_setting($name, '')) !== '') return 'shop';
    } catch (Throwable $e) {
        // Réglages de la boutique indisponibles : on regarde le portail.
    }
    return sms_shared_get($name) !== '' ? 'portail' : '';
}

/** Hôte local ? (même règle que les e-mails et le code de connexion) */
function sms_local_host(): bool
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return !str_contains($host, '.') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP);
}

const SMS_SOURCE_AUTO = 'auto';     // variable d'environnement → boutique → portail
const SMS_SOURCE_SHOP = 'shop';     // boutique seule (formulaire d'administration)
const SMS_SOURCE_SHARED = 'shared'; // portail seul (page /portail/sms.php, test)

/** Réglage lu à une seule source : un formulaire de boutique ne recopie jamais l'héritage du portail. */
function sms_source_setting(string $name, string $env, string $default, string $source): string
{
    if ($source === SMS_SOURCE_SHARED) {
        $v = sms_shared_get($name);
        return $v !== '' ? $v : $default;
    }
    if ($source === SMS_SOURCE_SHOP) {
        try {
            $v = trim(shop_setting($name, ''));
            return $v !== '' ? $v : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
    return sms_setting($name, $env, $default);
}

/** Identifiants OVH : ['account', 'login', 'password', 'from', 'nostop']. */
function sms_ovh_config(string $source = SMS_SOURCE_AUTO): array
{
    return [
        'account' => sms_source_setting('ovh_sms_account', 'OVH_SMS_ACCOUNT', '', $source),
        'login' => sms_source_setting('ovh_sms_login', 'OVH_SMS_LOGIN', '', $source),
        'password' => sms_source_setting('ovh_sms_password', 'OVH_SMS_PASSWORD', '', $source),
        'from' => sms_source_setting('ovh_sms_from', 'OVH_SMS_FROM', '', $source),
        'nostop' => sms_source_setting('ovh_sms_nostop', 'OVH_SMS_NOSTOP', '1', $source) !== '0',
    ];
}

/** Identifiants Twilio : ['sid', 'token', 'from']. */
function sms_twilio_config(string $source = SMS_SOURCE_AUTO): array
{
    return [
        'sid' => sms_source_setting('twilio_sid', 'TWILIO_ACCOUNT_SID', '', $source),
        'token' => sms_source_setting('twilio_token', 'TWILIO_AUTH_TOKEN', '', $source),
        'from' => sms_source_setting('twilio_from', 'TWILIO_FROM', '', $source),
    ];
}

function sms_ovh_ready(array $config): bool
{
    return $config['account'] !== '' && $config['login'] !== '' && $config['password'] !== '' && $config['from'] !== '';
}

function sms_twilio_ready(array $config): bool
{
    return $config['sid'] !== '' && $config['token'] !== '' && $config['from'] !== '';
}

/** Fournisseur retenu : celui demandé s'il est complet, sinon OVH, sinon Twilio, sinon ''. */
function sms_pick_provider(string $chosen, bool $ovhReady, bool $twilioReady): string
{
    $chosen = strtolower(trim($chosen));
    if ($chosen === 'ovh' && $ovhReady) return 'ovh';
    if ($chosen === 'twilio' && $twilioReady) return 'twilio';
    if ($ovhReady) return 'ovh';
    return $twilioReady ? 'twilio' : '';
}

/** Le fournisseur est-il complet ? */
function sms_provider_ready(string $provider): bool
{
    if ($provider === 'ovh') return sms_ovh_ready(sms_ovh_config());
    if ($provider === 'twilio') return sms_twilio_ready(sms_twilio_config());
    return false;
}

/** Fournisseur effectif : « ovh », « twilio » ou '' si aucun n'est complet. */
function sms_provider_name(): string
{
    return sms_pick_provider(
        sms_setting('sms_provider', 'SMS_PROVIDER'),
        sms_provider_ready('ovh'),
        sms_provider_ready('twilio')
    );
}

/** Fournisseur complet en ne regardant que les réglages du portail (page /portail/sms.php). */
function sms_shared_provider_name(): string
{
    return sms_pick_provider(
        sms_shared_get('sms_provider'),
        sms_ovh_ready(sms_ovh_config(SMS_SOURCE_SHARED)),
        sms_twilio_ready(sms_twilio_config(SMS_SOURCE_SHARED))
    );
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

/**
 * Boutique dont on décompte les SMS ('' hors contexte de boutique : portail,
 * galerie, où le compte SMS reste celui de la plateforme même si le serveur
 * affiche un commerce par défaut via .tenant).
 */
function sms_credit_shop(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_starts_with($script, '/portail/') || str_starts_with($script, '/galerie/')) return '';
    if (!function_exists('tenant_slug')) return '';
    try {
        return (string) tenant_slug();
    } catch (Throwable $e) {
        return '';
    }
}

/** Crédit SMS suffisant pour ce nombre de segments ? (boutique sans fiche = pas de limite) */
function sms_credit_ok(int $segments = 1): bool
{
    $slug = sms_credit_shop();
    return $slug === '' || sms_credit_allows($slug, $segments);
}

/** Un SMS peut-il partir ? (fournisseur complet ou mode local, et crédit disponible) */
function sms_available(): bool
{
    if (!sms_local_host() && sms_provider_name() === '') return false;
    return sms_credit_ok(1);
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

/**
 * Nombre de SMS facturés pour un texte : 160 caractères en 7 bits (153 par partie
 * au-delà de la première), 70 en Unicode (67 par partie).
 */
function sms_segments(string $body): int
{
    $len = mb_strlen($body, 'UTF-8');
    if (sms_gsm7_compatible($body)) return $len <= 160 ? 1 : (int) ceil($len / 153);
    return $len <= 70 ? 1 : (int) ceil($len / 67);
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
 * fiable pour juger du succès. $answer reçoit la réponse brute (test depuis le
 * portail), $config une configuration imposée (identifiants du portail seuls).
 */
function sms_send_ovh(string $to, string $body, ?array $config = null, ?string &$answer = null): bool
{
    $config ??= sms_ovh_config();
    $answer = '';
    if (!sms_ovh_ready($config)) return false;
    $number = sms_phone_e164($to);
    if ($number === null) return false;
    [$status, $answer] = sms_http_post('https://www.ovh.com/cgi-bin/sms/http2sms.cgi', http_build_query(sms_ovh_params($config, $number, $body)));
    if (sms_ovh_answer_ok($answer)) return true;
    error_log('SMS OVH refusé (HTTP ' . $status . ') : ' . mb_substr(trim($answer), 0, 200));
    return false;
}

/** Envoi Twilio (API Messages) ; $status reçoit le code HTTP de la réponse (test depuis le portail). */
function sms_send_twilio(string $to, string $body, ?array $config = null, ?int &$status = null): bool
{
    $config ??= sms_twilio_config();
    $status = null;
    if (!sms_twilio_ready($config)) return false;
    $number = sms_phone_e164($to);
    if ($number === null) return false;
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($config['sid']) . '/Messages.json';
    $data = http_build_query(['To' => $number, 'From' => $config['from'], 'Body' => $body]);
    [$status] = sms_http_post($url, $data, [], $config['sid'] . ':' . $config['token']);
    return $status >= 200 && $status < 300;
}

/**
 * Envoi d'un SMS par le fournisseur retenu ; en local, copie dans data/sms-outbox.log.
 * Une boutique qui a un crédit SMS voit chaque envoi réussi décompté (un SMS long
 * compte plusieurs segments) ; sans crédit restant, l'envoi est refusé.
 */
function sms_send(string $to, string $body): bool
{
    $slug = sms_credit_shop();
    $segments = sms_segments($body);
    if ($slug !== '' && !sms_credit_allows($slug, $segments)) {
        error_log('SMS bloqué pour ' . $slug . ' : crédit SMS épuisé (' . sms_credit_account($slug)['left'] . ' restant, ' . $segments . ' demandé).');
        return false;
    }
    $provider = sms_provider_name();
    if ($provider === 'ovh') {
        $ok = sms_send_ovh($to, $body);
    } elseif ($provider === 'twilio') {
        $ok = sms_send_twilio($to, $body);
    } elseif (sms_local_host()) {
        $ok = @file_put_contents(__DIR__ . '/../data/sms-outbox.log',
            '[' . date('c') . "] À : $to\n$body\n----\n", FILE_APPEND | LOCK_EX) !== false;
    } else {
        $ok = false;
    }
    if ($ok && $slug !== '') sms_credit_spend($slug, $segments, ['to' => $to]);
    return $ok;
}
