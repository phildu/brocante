<?php

// Adresse personnalisée : un domaine déjà acheté ailleurs (OVH, Gandi, Ionos…)
// rattaché à une boutique. Deux preuves avant toute mise en service :
//   1. un enregistrement TXT « brocs-verify=<jeton> » prouve le contrôle du DNS ;
//   2. l'enregistrement A du domaine doit pointer vers ce serveur.
// L'activation (alias d'hébergement, certificat HTTPS) reste décidée par
// l'exploitant depuis le portail. État : data/domaines/custom/<boutique>.json
// (0600), un fichier par boutique, jamais envoyé par le déploiement.

const DOMAIN_CUSTOM_STATES = [
    'pending' => 'Preuve DNS à ajouter',
    'verified' => 'Propriété confirmée',
    'dns_ok' => 'DNS en place',
    'ready' => 'Mise en service demandée',
    'active' => 'En service',
];

function domain_custom_slug_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug);
}

function domain_custom_dir(): string
{
    if (defined('DOMAIN_CUSTOM_DATA_DIR')) return rtrim((string) DOMAIN_CUSTOM_DATA_DIR, '/');
    if (defined('DOMAIN_SHOP_DATA_DIR')) return rtrim((string) DOMAIN_SHOP_DATA_DIR, '/') . '/custom';
    return dirname(__DIR__) . '/data/domaines/custom';
}

function domain_custom_file(string $slug): string
{
    if (!domain_custom_slug_valid($slug)) throw new InvalidArgumentException('Identifiant de boutique invalide.');
    return domain_custom_dir() . '/' . $slug . '.json';
}

function domain_custom_state_label(string $state): string
{
    return DOMAIN_CUSTOM_STATES[$state] ?? 'État inconnu';
}

/** Adresse nettoyée : minuscules, sans schéma, sans chemin ni « www. ». */
function domain_custom_normalize(string $input): string
{
    $domain = strtolower(trim($input));
    $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
    $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);
    $domain = trim($domain, ". \t\n\r\0\x0B");
    if (preg_match('/[^\x20-\x7E]/', $domain) && function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46')) {
        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii !== false) $domain = strtolower($ascii);
    }
    return (string) preg_replace('/^www\./', '', $domain);
}

function domain_custom_domain_valid(string $domain): bool
{
    return strlen($domain) <= 253
        && (bool) preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
}

function domain_custom_validate(string $input): string
{
    $domain = domain_custom_normalize($input);
    if (!domain_custom_domain_valid($domain)) {
        throw new InvalidArgumentException('Saisissez un nom de domaine valide, par exemple moncommerce.fr (sans http ni chemin).');
    }
    return $domain;
}

function domain_custom_load(string $slug): array
{
    $file = domain_custom_file($slug);
    if (!is_file($file)) return [];
    try {
        $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $ex) {
        error_log('domain_custom_load: ' . $ex->getMessage());
        return [];
    }
    return is_array($data) ? $data : [];
}

/** Modifie l'état sous verrou exclusif puis l'écrit de façon atomique (comme la commande OVH). */
function domain_custom_update(string $slug, callable $update): array
{
    $file = domain_custom_file($slug);
    $dir = dirname($file);
    if (is_link($dir)) throw new RuntimeException('Le dossier des domaines ne peut pas être un lien symbolique.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de préparer le dossier des domaines.');
    }
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Impossible de verrouiller ce domaine.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller ce domaine.');
        $data = $update(domain_custom_load($slug));
        $tmp = tempnam($dir, '.custom-');
        if ($tmp === false) throw new RuntimeException('Impossible de préparer l’enregistrement du domaine.');
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                throw new RuntimeException('Impossible d’enregistrer ce domaine.');
            }
        } catch (Throwable $ex) {
            if (is_file($tmp)) @unlink($tmp);
            throw $ex;
        }
        @chmod($file, 0600);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Toutes les adresses rattachées (une entrée par boutique), pour le portail. */
function domain_custom_list(): array
{
    $list = [];
    foreach (glob(domain_custom_dir() . '/*.json') ?: [] as $file) {
        $slug = basename($file, '.json');
        if (!domain_custom_slug_valid($slug)) continue;
        $record = domain_custom_load($slug);
        if (!$record || (string) ($record['domain'] ?? '') === '') continue;
        $list[] = $record + ['slug' => $slug];
    }
    usort($list, static fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));
    return $list;
}

/** Domaines en service : hôte → boutique. Consulté à chaque requête par la résolution des commerces. */
function domain_custom_hosts(): array
{
    $hosts = [];
    foreach (domain_custom_list() as $record) {
        if (($record['state'] ?? '') !== 'active') continue;
        $domain = strtolower((string) ($record['domain'] ?? ''));
        if (domain_custom_domain_valid($domain) && $domain !== '') $hosts[$domain] = (string) $record['slug'];
    }
    return $hosts;
}

function domain_custom_active_host(string $slug): string
{
    $record = domain_custom_load($slug);
    return ($record['state'] ?? '') === 'active' ? (string) ($record['domain'] ?? '') : '';
}

/**
 * Refus d'un domaine déjà pris ailleurs : domaine de la plateforme, adresse
 * rattachée à une autre boutique, ou domaine acheté pour une autre boutique.
 */
function domain_custom_conflict(string $domain, string $slug, string $platformHost = ''): string
{
    $domain = strtolower($domain);
    $platformHost = strtolower((string) preg_replace('/^www\./', '', $platformHost));
    if ($platformHost !== '' && ($domain === $platformHost || str_ends_with($domain, '.' . $platformHost))) {
        return 'Cette adresse fait partie du domaine de la plateforme : utilisez l’adresse fournie pour votre boutique.';
    }
    foreach (domain_custom_list() as $record) {
        if ((string) $record['slug'] !== $slug && strtolower((string) ($record['domain'] ?? '')) === $domain) {
            return 'Ce domaine est déjà rattaché à une autre boutique.';
        }
    }
    // Une autre boutique peut déjà déclarer ce domaine comme son site_url :
    // le rattachement serait alors sans effet, c'est cette boutique qui répondrait.
    if (function_exists('tenant_list')) {
        try {
            foreach (tenant_list() as $other => $config) {
                if ((string) $other === $slug) continue;
                $url = rtrim((string) ($config['site_url'] ?? ''), '/');
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($url === '' || ($path !== '' && $path !== '/')) continue;
                $declared = strtolower((string) parse_url($url, PHP_URL_HOST));
                if ($declared !== '' && preg_replace('/^www\./', '', $declared) === $domain) {
                    return 'Ce domaine est déjà l’adresse déclarée d’une autre boutique.';
                }
            }
        } catch (Throwable $ex) {
            error_log('domain_custom_conflict tenants: ' . $ex->getMessage());
        }
    }
    foreach (glob(dirname(domain_custom_dir()) . '/*.json') ?: [] as $file) {
        $other = basename($file, '.json');
        if ($other === $slug || !domain_custom_slug_valid($other)) continue;
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) continue;
        $purchases = array_merge([$data['purchase'] ?? null], (array) ($data['history'] ?? []));
        foreach ($purchases as $purchase) {
            if (is_array($purchase) && strtolower((string) ($purchase['quote']['domain'] ?? '')) === $domain) {
                return 'Ce domaine a été acheté pour une autre boutique de la plateforme.';
            }
        }
    }
    return '';
}

/** Rattache (ou remplace) l'adresse existante d'une boutique ; un nouveau jeton de preuve est émis. */
function domain_custom_attach(string $slug, string $input, string $platformHost = ''): array
{
    if (!domain_custom_slug_valid($slug)) throw new InvalidArgumentException('Identifiant de boutique invalide.');
    $domain = domain_custom_validate($input);
    if (domain_custom_active_host($slug) !== '') {
        throw new RuntimeException('Un domaine est déjà en service pour cette boutique : détachez-le avant d’en rattacher un autre.');
    }
    if (($why = domain_custom_conflict($domain, $slug, $platformHost)) !== '') throw new RuntimeException($why);
    $record = [
        'domain' => $domain,
        'state' => 'pending',
        'token' => bin2hex(random_bytes(16)),
        'created_at' => time(),
        'checked_at' => 0,
        'ownership_at' => 0,
        'routing_at' => 0,
        'observed' => [],
    ];
    return domain_custom_update($slug, static fn (array $d): array => $record);
}

/** Valeurs TXT renvoyées par dns_get_record(), sans guillemets ni espaces. */
function domain_custom_txt_values(array $records): array
{
    $values = [];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        if (isset($record['txt'])) $values[] = (string) $record['txt'];
        foreach ((array) ($record['entries'] ?? []) as $entry) {
            $values[] = is_array($entry) ? (string) ($entry['txt'] ?? '') : (string) $entry;
        }
    }
    return array_values(array_filter(array_map(
        static fn (string $v): string => trim(trim($v), '"'),
        $values
    ), static fn (string $v): bool => $v !== ''));
}

function domain_custom_a_values(array $records): array
{
    $ips = [];
    foreach ($records as $record) {
        if (is_array($record) && isset($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
            $ips[] = (string) $record['ip'];
        }
    }
    return array_values(array_unique($ips));
}

function domain_custom_cname_values(array $records): array
{
    $targets = [];
    foreach ($records as $record) {
        if (is_array($record) && isset($record['target'])) $targets[] = trim((string) $record['target'], '.');
    }
    return array_values(array_unique(array_filter($targets, static fn (string $t): bool => $t !== '')));
}

/**
 * Adresses vers lesquelles un domaine doit pointer pour être servi ici :
 * celle réglée par l'exploitant dans le portail, sinon celle(s) du serveur
 * qui répond à la requête. Vide en local : la confirmation finale y est
 * toujours faite en ligne.
 */
function domain_custom_expected_ips(): array
{
    $ips = [];
    if (function_exists('saas_config')) {
        $ip = (string) (saas_config()['domain_ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[] = $ip;
    }
    $host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost' && !str_ends_with($host, '.test')) {
        foreach (gethostbynamel($host) ?: [] as $ip) $ips[] = $ip;
    }
    return array_values(array_unique($ips));
}

/**
 * Contrôle le domaine rattaché : preuve TXT puis pointage A. Une propriété
 * déjà prouvée reste acquise (le TXT peut disparaître après la première
 * vérification) ; le pointage, lui, est toujours relu.
 * $lookup : fonction (nom, type) → enregistrements, remplaçable dans les tests.
 */
function domain_custom_check(string $slug, ?callable $lookup = null, ?array $expectedIps = null): array
{
    $record = domain_custom_load($slug);
    if (!$record || (string) ($record['domain'] ?? '') === '') {
        throw new RuntimeException('Aucun domaine n’est rattaché à cette boutique.');
    }
    $state = (string) ($record['state'] ?? '');
    if ($state === 'active') return $record;

    $lookup ??= static fn (string $name, int $type): array => @dns_get_record($name, $type) ?: [];
    $expectedIps ??= domain_custom_expected_ips();
    $domain = (string) $record['domain'];
    $token = 'brocs-verify=' . (string) ($record['token'] ?? '');

    $txt = [];
    $proofName = '';
    foreach (['_brocs.' . $domain, $domain] as $name) {
        foreach (domain_custom_txt_values((array) $lookup($name, DNS_TXT)) as $value) {
            if (count($txt) < 8) $txt[] = $name . ' = ' . $value;
            if ($proofName === '' && $value === $token) $proofName = $name;
        }
    }
    $ips = domain_custom_a_values((array) $lookup($domain, DNS_A));
    $www = domain_custom_cname_values((array) $lookup('www.' . $domain, DNS_CNAME));
    $routing = (bool) array_intersect($ips, $expectedIps);

    $ownership = $proofName !== '' || (int) ($record['ownership_at'] ?? 0) > 0;
    $newState = !$ownership ? 'pending' : ($routing ? 'dns_ok' : 'verified');
    if ($state === 'ready') $newState = 'ready';

    return domain_custom_update($slug, static function (array $d) use ($newState, $proofName, $ownership, $routing, $txt, $ips, $www): array {
        $d['state'] = $newState;
        $d['checked_at'] = time();
        $d['observed'] = ['txt' => $txt, 'a' => $ips, 'www' => $www];
        if ($proofName !== '') {
            $d['proof_name'] = $proofName;
            $d['ownership_at'] = (int) ($d['ownership_at'] ?? 0) ?: time();
        }
        $d['routing_at'] = $routing ? time() : 0;
        return $d;
    });
}

/** Le commerce demande la mise en service : prévient l'exploitant, qui active depuis le portail. */
function domain_custom_request_activation(string $slug): array
{
    $record = domain_custom_load($slug);
    if (!$record) throw new RuntimeException('Aucun domaine n’est rattaché à cette boutique.');
    if (($record['state'] ?? '') === 'active') return $record;
    if (($record['state'] ?? '') !== 'dns_ok') {
        throw new RuntimeException('Vérifiez d’abord le domaine : la preuve DNS et l’adresse du serveur doivent être en place.');
    }
    $data = domain_custom_update($slug, static function (array $d): array {
        $d['state'] = 'ready';
        $d['requested_at'] = time();
        return $d;
    });
    $domain = (string) ($data['domain'] ?? '');
    if (function_exists('saas_mail') && function_exists('saas_config')) {
        $contact = (string) (saas_config()['contact'] ?? '');
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            saas_mail($contact, 'Mise en service d’un domaine à faire : ' . $domain,
                "La boutique $slug demande la mise en service de son domaine $domain.\n\n"
                . "DNS contrôlé le " . date('d/m/Y à H:i', (int) ($data['checked_at'] ?? time())) . ".\n"
                . "Pointage vu : " . implode(', ', (array) ($data['observed']['a'] ?? [])) . "\n\n"
                . "Déclarez $domain comme alias d’hébergement (le certificat HTTPS est alors émis), vérifiez que https://$domain/ affiche la boutique, puis activez l’adresse dans Portail → Noms de domaine.\n");
        }
    }
    return $data;
}

/** Active l'adresse (exploitant) : la boutique est alors servie à la racine du domaine. */
function domain_custom_activate(string $slug): array
{
    $record = domain_custom_load($slug);
    if (!$record) throw new RuntimeException('Aucun domaine n’est rattaché à cette boutique.');
    if (($record['state'] ?? '') === 'active') return $record;
    if (($record['state'] ?? '') !== 'ready') {
        throw new RuntimeException('Cette demande n’est pas prête : il faut d’abord la preuve DNS et le pointage du domaine.');
    }
    $data = domain_custom_update($slug, static function (array $d): array {
        $d['state'] = 'active';
        $d['activated_at'] = time();
        return $d;
    });
    $domain = (string) ($data['domain'] ?? '');
    if (function_exists('saas_mail') && function_exists('tenant_load') && function_exists('tenant_dir')
        && is_file(tenant_dir($slug) . '/tenant.php')) {
        try {
            $email = strtolower(trim((string) (tenant_load($slug)['admin_email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                saas_mail($email, 'Votre domaine ' . $domain . ' est en service',
                    "Bonjour,\n\nLa boutique est maintenant servie à l’adresse https://$domain/ .\n"
                    . "L’adresse historique de la plateforme continue de fonctionner. Le domaine reste renouvelé chez votre fournisseur actuel.\n");
            }
        } catch (Throwable $ex) {
            error_log('domain_custom_activate mail: ' . $ex->getMessage());
        }
    }
    return $data;
}

/** Détache l'adresse (boutique ou exploitant) : la résolution vers la boutique s'arrête aussitôt. */
function domain_custom_remove(string $slug): void
{
    $file = domain_custom_file($slug);
    if (!is_file($file)) return;
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Impossible de verrouiller ce domaine.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller ce domaine.');
        if (!@unlink($file) && is_file($file)) throw new RuntimeException('Impossible de détacher ce domaine.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
