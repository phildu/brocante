<?php

// Réglages SMS communs à toutes les boutiques, réglés une fois dans le portail
// (/portail/sms.php). Une boutique qui saisit ses propres identifiants dans son
// administration garde la priorité ; sinon, includes/sms.php reprend ces valeurs
// (l'ordre est : variable d'environnement, réglages de la boutique, portail).
//
// Le fichier data/sms-partage.json contient un mot de passe ou un jeton : droits
// 0600, interdit en HTTP par le .htaccess racine (RedirectMatch 404 sur /data),
// jamais envoyé par le déploiement.
//
// Ce fichier ne dépend d'aucun autre : il sert aussi bien le portail que les
// boutiques, dont la base n'est pas forcément ouverte.

const SMS_SHARED_FIELDS = [
    'sms_provider',
    'ovh_sms_account', 'ovh_sms_login', 'ovh_sms_password', 'ovh_sms_from', 'ovh_sms_nostop',
    'twilio_sid', 'twilio_token', 'twilio_from',
];

/** Champs jamais réaffichés en clair (le formulaire les remplace, ne les relit pas). */
const SMS_SHARED_SECRETS = ['ovh_sms_password', 'twilio_token'];

function sms_shared_file(): string
{
    $custom = trim((string) getenv('SMS_SHARED_FILE'));
    return $custom !== '' ? $custom : dirname(__DIR__) . '/data/sms-partage.json';
}

/** Réglages du portail, champs connus seulement (chaîne vide si jamais réglé). */
function sms_shared(): array
{
    $file = sms_shared_file();
    $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (SMS_SHARED_FIELDS as $name) {
        $out[$name] = trim((string) ($saved[$name] ?? ''));
    }
    return $out;
}

function sms_shared_get(string $name): string
{
    return in_array($name, SMS_SHARED_FIELDS, true) ? (sms_shared()[$name] ?? '') : '';
}

/** Expéditeur SMS valide : 11 caractères alphanumériques au plus, ou 15 chiffres au plus. */
function sms_sender_valid(string $sender): bool
{
    return preg_match('/^([A-Za-z0-9]{1,11}|\d{1,15})$/', $sender) === 1;
}

/**
 * Fusionne un formulaire avec les réglages enregistrés. Un mot de passe ou un
 * jeton laissé vide garde la valeur enregistrée ; les autres champs se vident
 * quand on les vide (bascule d'un fournisseur à l'autre). Un expéditeur OVH
 * invalide est refusé et l'ancienne valeur conservée ; le fournisseur est
 * ramené à « auto » (chaîne vide), « ovh » ou « twilio ».
 * Retourne [valeurs, avertissement].
 */
function sms_shared_merge(array $current, array $input): array
{
    $out = $current;
    $warn = '';
    if (array_key_exists('sms_provider', $input)) {
        $provider = strtolower(trim((string) $input['sms_provider']));
        $out['sms_provider'] = in_array($provider, ['ovh', 'twilio'], true) ? $provider : '';
    }
    foreach (SMS_SHARED_FIELDS as $name) {
        if ($name === 'sms_provider' || !array_key_exists($name, $input)) continue;
        $value = trim((string) $input[$name]);
        if ($value === '' && in_array($name, SMS_SHARED_SECRETS, true)) continue;
        if ($name === 'ovh_sms_from' && $value !== '' && !sms_sender_valid($value)) {
            $warn = "Expéditeur OVH refusé : 11 caractères alphanumériques au plus (ou 15 chiffres) — l'ancienne valeur est conservée.";
            continue;
        }
        $out[$name] = $value;
    }
    return [$out, $warn];
}

/** Écrit les réglages du portail (écriture atomique, fichier en 0600). */
function sms_shared_save(array $values): bool
{
    $file = sms_shared_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0705, true)) return false;
    $data = [];
    foreach (SMS_SHARED_FIELDS as $name) {
        $data[$name] = trim((string) ($values[$name] ?? ''));
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $file . '.tmp';
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}
