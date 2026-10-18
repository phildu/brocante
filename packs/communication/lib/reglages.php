<?php
// Page « API & Réseaux » d'electroboy80 dans la boutique : ses réglages en double avec la plateforme sont branchés sur ceux de la
// plateforme, jamais rangés dans la base d'electroboy80 de la boutique (data/com/<boutique>.db) :
//  - gemini_api_key  → la clé Gemini propre à la boutique (.secrets/<boutique>/gemini.key, celle du tableau de bord) ;
//  - claude_api_key  → la clé Claude propre à la boutique (.secrets/<boutique>/claude.key : scénario de la Vidéo IA si Claude est
//    choisi ; sans elle, la clé partagée du portail sert s'il y en a une) ;
//  - oauth_google_*, oauth_facebook_* → les clés de connexion sociale propres à la boutique (.secrets/<boutique>/oauth.json,
//    celles de « Connexion sociale »).
// Les réglages qu'aucune fonction de la boutique n'utilise (SMTP, WhatsApp, SMS, billetterie, recherche) sont ignorés à
// l'enregistrement. Lecture : un secret n'est jamais renvoyé, seulement « •••••• » s'il existe (laisser tel quel = le garder).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/oauth.php';

const COM_REGLAGE_MASQUE = '••••••';
/** Réglages d'electroboy80 sans usage dans une boutique (interface : « non utilisé par la boutique », eb80-after.js). */
const COM_REGLAGES_INUTILISES = '/^(smtp_.*|meta_wa_.*|twilio_.*|billetweb_.*|brave_search_key|google_cse_.*|google_places_key|serper_api_key)$/';
/** Clé d'electroboy80 → fournisseur et champ de la connexion sociale de la boutique. */
const COM_REGLAGES_OAUTH = [
    'oauth_google_client_id' => ['google', 'client_id'], 'oauth_google_client_secret' => ['google', 'client_secret'],
    'oauth_facebook_client_id' => ['facebook', 'client_id'], 'oauth_facebook_client_secret' => ['facebook', 'client_secret'],
];

function com_gemini_key_file(): string
{
    return SECRETS_DIR . '/gemini.key';
}

/** Clés d'IA de la boutique : réglage d'electroboy80 → fichier de .secrets/<boutique>/. */
const COM_REGLAGES_CLES = ['gemini_api_key' => 'gemini.key', 'claude_api_key' => 'claude.key'];

/** Valeurs des réglages branchés, telles que l'interface les affiche (secrets masqués). */
function com_reglages_plateforme(): array
{
    $out = [];
    foreach (COM_REGLAGES_CLES as $key => $file) {
        $path = SECRETS_DIR . '/' . $file;
        $out[$key] = is_file($path) && trim((string) file_get_contents($path)) !== '' ? COM_REGLAGE_MASQUE : '';
    }
    $oauth = oauth_shop_config();
    foreach (COM_REGLAGES_OAUTH as $key => [$provider, $field]) {
        $v = (string) ($oauth['providers'][$provider][$field] ?? '');
        $out[$key] = $field === 'client_secret' ? ($v !== '' ? COM_REGLAGE_MASQUE : '') : $v;
    }
    return $out;
}

/** Réponse GET /settings d'electroboy80 : les réglages branchés remplacent ceux de sa base (liste clé → valeur et liste détaillée). */
function com_reglages_superposer(array $data): array
{
    foreach (com_reglages_plateforme() as $key => $value) {
        $data['settings'][$key] = $value;
        $found = false;
        foreach ((array) ($data['settingsList'] ?? []) as $i => $row) {
            if (is_array($row) && ($row['key'] ?? null) === $key) { $data['settingsList'][$i]['value'] = $value; $found = true; }
        }
        if (!$found) $data['settingsList'][] = ['key' => $key, 'value' => $value, 'label' => $key, 'section' => 'plateforme', 'type' => 'text'];
    }
    return $data;
}

/**
 * Enregistrement (PATCH /settings) : écrit les réglages branchés là où la plateforme les range, retire ceux-ci et les réglages
 * inutilisés du corps de l'appel, et renvoie [reste du corps, clés enregistrées côté plateforme].
 */
function com_reglages_enregistrer(array $input): array
{
    $saved = [];
    $value = static fn ($v): string => trim((string) $v);
    foreach (COM_REGLAGES_CLES as $name => $file) {
        if (!array_key_exists($name, $input)) continue;
        $key = $value($input[$name]);
        if ($key !== '' && $key !== COM_REGLAGE_MASQUE) {
            $path = SECRETS_DIR . '/' . $file;
            if (!is_dir(SECRETS_DIR)) @mkdir(SECRETS_DIR, 0700, true);
            if (@file_put_contents($path, $key) === false) throw new RuntimeException('Clé ' . ucfirst(strtok($file, '.')) . ' : enregistrement impossible.');
            @chmod($path, 0600);
            $saved[] = $name;
        }
        unset($input[$name]);
    }
    $oauth = oauth_shop_config();
    $oauthChanged = false;
    foreach (COM_REGLAGES_OAUTH as $key => [$provider, $field]) {
        if (!array_key_exists($key, $input)) continue;
        $v = $value($input[$key]);
        unset($input[$key]);
        if ($v === '' || $v === COM_REGLAGE_MASQUE || $v === ($oauth['providers'][$provider][$field] ?? '')) continue;
        $oauth['providers'][$provider][$field] = $v;
        $oauthChanged = true;
        $saved[] = $key;
    }
    if ($oauthChanged && !oauth_shop_config_save($oauth)) throw new RuntimeException('Connexion sociale : enregistrement impossible.');
    foreach (array_keys($input) as $key) {
        if (is_string($key) && preg_match(COM_REGLAGES_INUTILISES, $key)) unset($input[$key]);
    }
    return [$input, $saved];
}
