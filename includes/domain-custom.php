<?php

// Adresse personnalisée : un domaine déjà acheté ailleurs (OVH, Gandi, Ionos…)
// rattaché à une boutique ou à une galerie. Deux preuves avant toute mise en service :
//   1. un enregistrement TXT « brocs-verify=<jeton> » prouve le contrôle du DNS ;
//   2. l'enregistrement A du domaine doit pointer vers ce serveur.
// L'activation (alias d'hébergement, certificat HTTPS) reste décidée par
// l'exploitant depuis le portail. État : data/domaines/custom/<boutique>.json
// (0600), un fichier par boutique, jamais envoyé par le déploiement.

require_once __DIR__ . '/domain-owner.php';

const DOMAIN_CUSTOM_STATES = [
    'pending' => 'Preuve DNS à ajouter',
    'verified' => 'Propriété confirmée',
    'dns_ok' => 'DNS en place',
    'ready' => 'Mise en service demandée',
    'active' => 'En service',
];

/** Clé de propriétaire valide : identifiant de boutique, ou « g~ » + identifiant de galerie (includes/domain-owner.php). */
function domain_custom_slug_valid(string $slug): bool
{
    return domain_owner_key_valid($slug);
}

function domain_custom_dir(): string
{
    if (defined('DOMAIN_CUSTOM_DATA_DIR')) return rtrim((string) DOMAIN_CUSTOM_DATA_DIR, '/');
    if (defined('DOMAIN_SHOP_DATA_DIR')) return rtrim((string) DOMAIN_SHOP_DATA_DIR, '/') . '/custom';
    return dirname(__DIR__) . '/data/domaines/custom';
}

function domain_custom_file(string $slug): string
{
    if (!domain_custom_slug_valid($slug)) throw new InvalidArgumentException('Identifiant invalide.');
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

/** Domaines en service : hôte → boutique. Consulté à chaque requête par la résolution des commerces (les galeries n'y figurent pas : voir domain_owner_sync). */
function domain_custom_hosts(): array
{
    $hosts = [];
    foreach (domain_custom_list() as $record) {
        if (($record['state'] ?? '') !== 'active' || domain_owner_is_gallery((string) $record['slug'])) continue;
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
        return 'Cette adresse fait partie du domaine de la plateforme : utilisez l’adresse fournie pour votre ' . domain_owner_noun($slug) . '.';
    }
    foreach (domain_custom_list() as $record) {
        if ((string) $record['slug'] !== $slug && strtolower((string) ($record['domain'] ?? '')) === $domain) {
            return 'Ce domaine est déjà rattaché à une autre boutique ou galerie.';
        }
    }
    // Une galerie peut déjà porter ce domaine (champ « domain », saisi par l'exploitant ou issu d'un sous-domaine).
    if (is_file(__DIR__ . '/galleries.php')) {
        require_once __DIR__ . '/galleries.php';
        if (gallery_domain_owner($domain, domain_owner_gallery_slug($slug)) !== null) return 'Ce domaine est déjà l’adresse d’une galerie de la plateforme.';
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
                return 'Ce domaine a été acheté pour une autre boutique ou galerie de la plateforme.';
            }
        }
    }
    return '';
}

/** Rattache (ou remplace) l'adresse existante d'une boutique ; un nouveau jeton de preuve est émis. */
function domain_custom_attach(string $slug, string $input, string $platformHost = ''): array
{
    if (!domain_custom_slug_valid($slug)) throw new InvalidArgumentException('Identifiant invalide.');
    $domain = domain_custom_validate($input);
    if (domain_custom_active_host($slug) !== '') {
        throw new RuntimeException('Un domaine est déjà en service pour cette ' . domain_owner_noun($slug) . ' : détachez-le avant d’en rattacher un autre.');
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
        throw new RuntimeException('Aucun domaine n’est rattaché à cette ' . domain_owner_noun($slug) . '.');
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

/**
 * Réglages du rattachement automatique à l'hébergement OVH : service
 * (ex. arrimage.com), dossier racine (ex. www/brocs) et plafond optionnel de
 * domaines. Tableau vide tant que le service ou le dossier manque : le
 * rattachement reste alors manuel.
 */
function domain_custom_hosting_settings(): array
{
    if (!function_exists('saas_config')) return [];
    $cfg = saas_config();
    $service = (string) ($cfg['hosting_service'] ?? '');
    $path = (string) ($cfg['hosting_path'] ?? '');
    if ($service === '' || $path === '') return [];
    return ['service' => $service, 'path' => $path, 'max' => (int) ($cfg['hosting_max_domains'] ?? 0)];
}

/** Noms rattachés à l'hébergement pour un domaine de boutique : l'apex et son « www ». */
function domain_custom_hosting_names(string $domain): array
{
    return [$domain, 'www.' . $domain];
}

/**
 * Appel de l'API OVH (méthode, route, paramètres) → réponse décodée. Remplaçable
 * dans les tests : aucune requête réseau ne part d'un appelant qui fournit le sien.
 */
function domain_custom_ovh_api(): callable
{
    if (!function_exists('branding_ovh_client')) {
        throw new RuntimeException('Le client API OVH n’est pas chargé.');
    }
    $client = branding_ovh_client();
    return static function (string $method, string $path, array $params = []) use ($client) {
        try {
            return match ($method) {
                'GET' => $client->get($path, $params ?: null),
                'POST' => $client->post($path, $params),
                'DELETE' => $client->delete($path),
                default => throw new InvalidArgumentException('Méthode OVH non prise en charge.'),
            };
        } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
            throw branding_ovh_api_error($ex, $method . ' ' . strtok($path, '?'));
        }
    };
}

/**
 * Déclare le domaine (et son « www ») comme alias de l'hébergement OVH : le
 * certificat Let's Encrypt est demandé dans le même appel. Les alias déjà en
 * place sont conservés, le plafond configuré est respecté. Le domaine n'est
 * servi qu'une fois activé depuis le portail.
 * $api : fonction (méthode, route, paramètres) → réponse, remplaçable dans les tests.
 */
function domain_custom_hosting_attach(string $slug, ?callable $api = null, ?array $settings = null): array
{
    $record = domain_custom_load($slug);
    if (!$record || (string) ($record['domain'] ?? '') === '') {
        throw new RuntimeException('Aucun domaine n’est rattaché à cette ' . domain_owner_noun($slug) . '.');
    }
    if (!in_array((string) ($record['state'] ?? ''), ['dns_ok', 'ready', 'active'], true)) {
        throw new RuntimeException('Le domaine doit d’abord prouver la propriété et pointer vers le serveur.');
    }
    $settings ??= domain_custom_hosting_settings();
    if (!$settings) {
        throw new RuntimeException('Le rattachement automatique n’est pas réglé : indiquez le service d’hébergement et le dossier racine dans Portail → Noms de domaine.');
    }
    $api ??= domain_custom_ovh_api();
    $domain = (string) $record['domain'];
    $base = '/hosting/web/' . rawurlencode((string) $settings['service']) . '/attachedDomain';

    $existing = array_map('strtolower', array_map('strval', (array) $api('GET', $base)));
    $missing = array_values(array_filter(domain_custom_hosting_names($domain), static fn (string $n): bool => !in_array($n, $existing, true)));
    $max = (int) ($settings['max'] ?? 0);
    if ($max > 0 && count($existing) + count($missing) > $max) {
        throw new RuntimeException('Plafond de ' . $max . ' domaines atteint sur l’hébergement (' . count($existing) . ' déjà rattachés) : libérez des alias ou relevez le plafond dans les réglages.');
    }

    $tasks = [];
    $wwwError = '';
    foreach ($missing as $name) {
        try {
            $task = (array) $api('POST', $base, [
                'domain' => $name,
                'path' => (string) $settings['path'],
                'ssl' => true,
                'cdn' => 'none',
                'firewall' => 'none',
                'bypassDNSConfiguration' => true,
            ]);
            $tasks[$name] = (int) ($task['id'] ?? 0);
        } catch (RuntimeException $ex) {
            if ($name === $domain) throw $ex;
            $wwwError = $ex->getMessage();
        }
    }

    $done = array_values(array_unique(array_merge(
        array_values(array_filter(domain_custom_hosting_names($domain), static fn (string $n): bool => in_array($n, $existing, true))),
        array_keys($tasks)
    )));
    return domain_custom_update($slug, static function (array $d) use ($settings, $done, $tasks, $wwwError): array {
        $d['hosting'] = [
            'service' => (string) $settings['service'],
            'path' => (string) $settings['path'],
            'domains' => $done,
            'tasks' => $tasks,
            'attached_at' => time(),
            'error' => '',
            'www_error' => $wwwError,
        ];
        return $d;
    });
}

/**
 * Retire chez OVH les alias d'hébergement décrits par $hosting (bloc « hosting »
 * de l'état d'une boutique). Renvoie les noms retirés ; s'arrête à la première erreur.
 */
function domain_custom_hosting_detach(array $hosting, ?callable $api = null): array
{
    $names = array_values(array_filter(array_map('strval', (array) ($hosting['domains'] ?? []))));
    $service = (string) ($hosting['service'] ?? '');
    if (!$names || $service === '') return [];
    $api ??= domain_custom_ovh_api();
    $removed = [];
    foreach ($names as $name) {
        $api('DELETE', '/hosting/web/' . rawurlencode($service) . '/attachedDomain/' . rawurlencode($name) . '?bypassDNSConfiguration=true');
        $removed[] = $name;
    }
    return $removed;
}

/** Mémorise l'échec du rattachement automatique, pour l'afficher à l'exploitant. */
function domain_custom_hosting_fail(string $slug, string $message): void
{
    try {
        domain_custom_update($slug, static function (array $d) use ($message): array {
            $hosting = (array) ($d['hosting'] ?? []);
            $hosting['error'] = mb_substr($message, 0, 400);
            $d['hosting'] = $hosting;
            return $d;
        });
    } catch (Throwable $ex) {
        error_log('domain_custom_hosting_fail: ' . $ex->getMessage());
    }
}

/**
 * Le commerce demande la mise en service : l'alias d'hébergement est créé tout
 * de suite si le rattachement automatique est réglé, puis l'exploitant est
 * prévenu. Il vérifie https://<domaine>/ et active depuis le portail.
 * $api et $settings : fonction OVH et réglages remplaçables dans les tests.
 */
function domain_custom_request_activation(string $slug, ?callable $api = null, ?array $settings = null): array
{
    $record = domain_custom_load($slug);
    if (!$record) throw new RuntimeException('Aucun domaine n’est rattaché à cette ' . domain_owner_noun($slug) . '.');
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

    $noun = domain_owner_noun($slug);
    $ownerRef = domain_owner_is_gallery($slug) ? domain_owner_gallery_slug($slug) : $slug;
    $hostingNote = "Déclarez $domain comme alias d’hébergement (le certificat HTTPS est alors émis), vérifiez que https://$domain/ affiche la $noun, puis activez l’adresse dans Portail → Noms de domaine.\n";
    $settings ??= domain_custom_hosting_settings();
    if ($settings) {
        try {
            $data = domain_custom_hosting_attach($slug, $api, $settings);
            $hostingNote = "L’alias d’hébergement a été créé automatiquement chez OVH pour : " . implode(', ', (array) ($data['hosting']['domains'] ?? [])) . ".\n"
                . "Le certificat HTTPS est en cours d’émission (quelques minutes). Vérifiez que https://$domain/ affiche la $noun, puis activez l’adresse dans Portail → Noms de domaine.\n";
        } catch (Throwable $ex) {
            error_log('domain_custom_request_activation hosting: ' . $ex->getMessage());
            $message = $ex instanceof RuntimeException ? $ex->getMessage() : 'Erreur inattendue pendant le rattachement.';
            domain_custom_hosting_fail($slug, $message);
            $data = domain_custom_load($slug) ?: $data;
            $hostingNote = "Le rattachement automatique à l’hébergement a échoué : $message\n"
                . "Relancez-le depuis Portail → Noms de domaine, ou déclarez $domain comme alias d’hébergement à la main, puis activez l’adresse.\n";
        }
    }

    if (function_exists('saas_mail') && function_exists('saas_config')) {
        $contact = (string) (saas_config()['contact'] ?? '');
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            saas_mail($contact, 'Mise en service d’un domaine à faire : ' . $domain,
                "La $noun $ownerRef demande la mise en service de son domaine $domain.\n\n"
                . "DNS contrôlé le " . date('d/m/Y à H:i', (int) ($data['checked_at'] ?? time())) . ".\n"
                . "Pointage vu : " . implode(', ', (array) ($data['observed']['a'] ?? [])) . "\n\n"
                . $hostingNote);
        }
    }
    return $data;
}

/** Active l'adresse (exploitant) : la boutique ou la galerie est alors servie à la racine du domaine. */
function domain_custom_activate(string $slug): array
{
    $record = domain_custom_load($slug);
    if (!$record) throw new RuntimeException('Aucun domaine n’est rattaché à cette ' . domain_owner_noun($slug) . '.');
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
    domain_owner_sync($slug);
    if (function_exists('saas_mail')) {
        try {
            $email = domain_owner_email($slug);
            if ($email !== '') {
                saas_mail($email, 'Votre domaine ' . $domain . ' est en service',
                    "Bonjour,\n\nLa " . domain_owner_noun($slug) . " est maintenant servie à l’adresse https://$domain/ .\n"
                    . "L’adresse historique de la plateforme continue de fonctionner. Le domaine reste renouvelé chez votre fournisseur actuel.\n");
            }
        } catch (Throwable $ex) {
            error_log('domain_custom_activate mail: ' . $ex->getMessage());
        }
    }
    return $data;
}

/**
 * Détache l'adresse (boutique ou exploitant) : la résolution vers la boutique
 * s'arrête aussitôt. Les alias d'hébergement créés automatiquement sont retirés
 * ensuite ; si OVH refuse, le détachement reste fait et le message d'échec est
 * renvoyé (chaîne vide : rien à signaler).
 * $api : fonction OVH remplaçable dans les tests.
 */
function domain_custom_remove(string $slug, ?callable $api = null): string
{
    $file = domain_custom_file($slug);
    if (!is_file($file)) return '';
    $hosting = (array) (domain_custom_load($slug)['hosting'] ?? []);
    $removedHost = domain_custom_active_host($slug);

    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Impossible de verrouiller ce domaine.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller ce domaine.');
        if (!@unlink($file) && is_file($file)) throw new RuntimeException('Impossible de détacher ce domaine.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    domain_owner_sync($slug, $removedHost);

    if (empty($hosting['domains'])) return '';
    try {
        domain_custom_hosting_detach($hosting, $api);
    } catch (Throwable $ex) {
        error_log('domain_custom_remove hosting: ' . $ex->getMessage());
        return 'Domaine détaché, mais les alias d’hébergement n’ont pas pu être retirés chez OVH (' . ($ex instanceof RuntimeException ? $ex->getMessage() : 'erreur inattendue') . ') : retirez-les à la main.';
    }
    return '';
}
