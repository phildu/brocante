<?php

require_once __DIR__ . '/saas.php';

function branding_gemini_key(): string
{
    $key = trim((string) getenv('GEMINI_API_KEY'));
    if ($key !== '') return $key;

    $file = dirname(__DIR__) . '/.secrets/gemini.key';
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

function branding_save_request(array $request): void
{
    if (!saas_request_save($request)) throw new RuntimeException('Impossible d’enregistrer cette étape. Réessayez.');
}

function branding_activity_catalog(): array
{
    return [
        'mode' => 'Mode et vêtements',
        'sport' => 'Sport et loisirs',
        'deco' => 'Déco et maison',
        'alimentaire' => 'Alimentaire et boissons',
        'brocante' => 'Brocante, vintage et collection',
        'livres' => 'Livres, musique et médias',
        'jeux' => 'Jeux, jouets et loisirs créatifs',
        'informatique' => 'Informatique et électronique',
        'bijoux' => 'Bijoux, montres et beauté',
        'jardin' => 'Bricolage et jardin',
        'auto' => 'Auto et moto',
        'enfants' => 'Bébé et enfant',
        'animaux' => 'Animaux',
        'artisanat' => 'Artisanat et création',
        'restauration' => 'Restauration et métiers de bouche',
        'beaute' => 'Beauté et bien-être',
        'services' => 'Services et conseil',
        'culture' => 'Culture et loisirs',
    ];
}

function branding_activity_normalize($input): array
{
    if (!is_array($input)) $input = [];
    $catalog = branding_activity_catalog();
    $primary = is_string($input['primary'] ?? null) ? $input['primary'] : '';
    $secondary = array_values(array_unique(array_filter(
        (array) ($input['secondary'] ?? []),
        static fn ($key): bool => is_string($key)
    )));
    $secondary = array_values(array_filter(
        $secondary,
        static fn (string $key): bool => isset($catalog[$key]) || $key === 'other'
    ));
    $secondary = array_values(array_filter($secondary, static fn (string $key): bool => $key !== $primary));
    if ($primary !== 'other' && !isset($catalog[$primary])) $primary = '';
    if (count($secondary) > 6) $secondary = array_slice($secondary, 0, 6);
    $otherInput = $input['other'] ?? '';
    $other = mb_substr(trim(is_string($otherInput) ? $otherInput : ''), 0, 120);
    return ['primary' => $primary, 'secondary' => $secondary, 'other' => $other];
}

function branding_activity_validate(array $activity): void
{
    if (($activity['primary'] ?? '') === '') {
        throw new InvalidArgumentException('Choisissez une activité principale.');
    }
    if (($activity['primary'] === 'other' || in_array('other', (array) ($activity['secondary'] ?? []), true))
        && trim((string) ($activity['other'] ?? '')) === '') {
        throw new InvalidArgumentException('Précisez l’activité dans le champ « Autre ».');
    }
}

function branding_activity_from_post(array $post, array $fallback = []): array
{
    if (isset($post['activity_json'])) {
        if (!is_string($post['activity_json'])) throw new InvalidArgumentException('La sélection des activités est illisible. Recommencez.');
        $json = $post['activity_json'];
        if (strlen($json) > 4096) throw new InvalidArgumentException('La sélection des activités est trop longue.');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) throw new InvalidArgumentException('La sélection des activités est illisible. Recommencez.');
        return branding_activity_normalize($decoded);
    }
    if (isset($post['activity_primary']) || isset($post['activity_secondary']) || isset($post['activity_other'])) {
        return branding_activity_normalize([
            'primary' => $post['activity_primary'] ?? '',
            'secondary' => $post['activity_secondary'] ?? [],
            'other' => $post['activity_other'] ?? '',
        ]);
    }
    return branding_activity_normalize($fallback);
}

function branding_activity_context(array $activity): string
{
    $catalog = branding_activity_catalog();
    $activity = branding_activity_normalize($activity);
    $labels = [];
    if ($activity['primary'] !== '') $labels[] = $activity['primary'] === 'other' ? $activity['other'] : $catalog[$activity['primary']];
    foreach ($activity['secondary'] as $key) {
        $labels[] = $key === 'other' ? $activity['other'] : $catalog[$key];
    }
    return implode(', ', array_unique($labels));
}

function branding_rate_limit(string $requestId, string $action, int $limit): void
{
    $dir = saas_requests_dir() . '/.branding-rate';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Le contrôle de fréquence est indisponible. Réessayez plus tard.');
    }
    $key = hash('sha256', $requestId . '|' . $action . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $path = $dir . '/' . $key . '.json';
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Le contrôle de fréquence est indisponible. Réessayez plus tard.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Le contrôle de fréquence est indisponible. Réessayez plus tard.');
        $times = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (!is_array($times)) throw new RuntimeException('Le contrôle de fréquence est illisible.');
        $times = array_values(array_filter((array) $times, static fn ($time) => (int) $time > time() - 3600));
        if (count($times) >= $limit) throw new RuntimeException('Trop de demandes récentes. Réessayez dans une heure.');
        $times[] = time();
        if (file_put_contents($path, json_encode($times), LOCK_EX) === false) {
            throw new RuntimeException('Le contrôle de fréquence est indisponible. Réessayez plus tard.');
        }
        chmod($path, 0600);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function branding_gemini_call(string $model, array $payload): array
{
    $key = branding_gemini_key();
    if ($key === '') throw new RuntimeException('La génération IA n’est pas configurée sur cette plateforme.');

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($key);
    $ch = curl_init($url);
    try {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $ex) {
        throw new RuntimeException('Le contenu à envoyer au service IA contient des caractères non valides.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_TIMEOUT => 55,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log('branding_gemini_call: HTTP ' . $status . ' ' . substr((string) $body, 0, 300));
        throw new RuntimeException($status === 429
            ? 'Le service IA est momentanément saturé. Réessayez dans un instant.'
            : ($body === false ? 'Le service IA est momentanément injoignable (' . $error . ').' : 'La génération IA a échoué. Vérifiez la clé Gemini et réessayez.'));
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) throw new RuntimeException('La réponse du service IA est illisible.');
    return $data;
}

function branding_generate_names(array $request): array
{
    $name = trim((string) ($request['preferred_shop_name'] ?? $request['shop_name'] ?? ''));
    $about = trim((string) ($request['about'] ?? ''));
    $location = trim((string) ($request['location'] ?? ''));
    $activity = branding_activity_context((array) ($request['activity_details'] ?? []));
    $prompt = "Propose 6 noms de commerce originaux, mémorisables, en français, pour cette boutique. "
        . "Réponds uniquement en JSON strict sous la forme {\"names\":[\"Nom\", ...]}. "
        . "N'inclus aucune marque connue et évite les noms de domaine ou slogans. "
        . "Nom actuel : " . mb_substr($name, 0, 60) . ". Activité principale et activités secondaires : " . mb_substr($activity, 0, 400)
        . ". Description complémentaire : " . mb_substr($about, 0, 400)
        . ". Emplacement : " . mb_substr($location, 0, 100);
    $data = branding_gemini_call('gemini-2.5-flash', [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.9],
    ]);
    $text = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $result = json_decode($text, true);
    $names = [];
    foreach ((array) ($result['names'] ?? []) as $candidate) {
        $candidate = trim(preg_replace('/\s+/u', ' ', (string) $candidate));
        if ($candidate !== '' && mb_strlen($candidate) <= 60) $names[] = $candidate;
    }
    $names = array_values(array_unique($names));
    if (!$names) throw new RuntimeException('Gemini n’a pas proposé de noms exploitables. Réessayez.');
    return array_slice($names, 0, 6);
}

function branding_domain_label(string $name): string
{
    $label = trim(saas_slug($name), '-');
    if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
        throw new InvalidArgumentException('Le nom saisi ne peut pas servir de nom de domaine. Essayez un nom plus court.');
    }
    return $label;
}

/**
 * Vérifie la disponibilité de domaines auprès d’OVH sans ajouter les domaines au panier.
 * La recherche teste le nom exact, puis quelques variantes proches si le nom exact est déjà pris.
 */
function branding_ovh_search_domains(string $name, string $location = ''): array
{
    $primary = branding_domain_label($name);
    $extensions = ['.fr', '.com', '.net', '.shop'];
    $alternativeRoots = [];
    $city = trim(saas_slug($location), '-');
    foreach ([$primary . '-boutique', $primary . '-shop', ($city !== '' ? $primary . '-' . $city : 'boutique-' . $primary)] as $variant) {
        $variant = trim($variant, '-');
        if (strlen($variant) <= 63 && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $variant)) $alternativeRoots[] = $variant;
    }
    $domains = array_map(static fn (string $extension): array => ['domain' => $primary . $extension, 'kind' => 'name'], $extensions);

    $client = branding_ovh_client();
    $config = branding_domain_config();
    $stage = 'POST /order/cart';
    try {
        $cart = $client->post('/order/cart', ['ovhSubsidiary' => $config['subsidiary'], 'description' => 'Vérification de domaines']);
        $cartId = (string) ($cart['cartId'] ?? '');
        if ($cartId === '') throw new RuntimeException('OVH n’a pas créé de panier de vérification.');
        $check = static function (array &$candidates) use ($client, $cartId, &$stage): bool {
            $anyAvailable = false;
            foreach ($candidates as &$candidate) {
                $stage = 'GET /order/cart/*/domain';
                $offers = $client->get('/order/cart/' . rawurlencode($cartId) . '/domain', ['domain' => $candidate['domain']]);
                $candidate['available'] = false;
                foreach ((array) $offers as $offer) {
                    if (($offer['action'] ?? '') === 'create' && !empty($offer['orderable'])) {
                        $candidate['available'] = true;
                        $anyAvailable = true;
                        break;
                    }
                }
            }
            unset($candidate);
            return $anyAvailable;
        };
        $hasExactAvailable = $check($domains);
        if (!$hasExactAvailable) {
            $alternatives = [];
            foreach (array_values(array_unique($alternativeRoots)) as $root) {
                foreach ($extensions as $extension) {
                    $alternatives[] = ['domain' => $root . $extension, 'kind' => 'alternative', 'available' => false];
                }
            }
            $check($alternatives);
            $domains = array_merge($domains, $alternatives);
        }
    } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
        throw branding_ovh_api_error($ex, $stage);
    }

    return ['searched_at' => time(), 'primary' => $primary, 'domains' => $domains];
}

function branding_apply_shop_name(string $slug, string $name): void
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_strlen($name) > 60) throw new InvalidArgumentException('Le nom doit contenir de 1 à 60 caractères.');

    $tenantFile = tenant_dir($slug) . '/tenant.php';
    $seedFile = tenant_dir($slug) . '/seed-data.json';
    if (!is_file($tenantFile) || !is_file($seedFile)) throw new RuntimeException('La configuration de cette boutique est introuvable.');
    $tenant = tenant_load($slug);
    $seed = json_decode((string) file_get_contents($seedFile), true);
    if (!is_array($seed)) throw new RuntimeException('Le contenu de départ de la boutique est illisible.');

    $previousName = (string) ($tenant['name'] ?? '');
    $tenant['name'] = $name;
    if (!empty($seed['brand']) && is_array($seed['brand'])) $seed['brand']['name'] = $name;
    if (!empty($seed['hero']) && is_array($seed['hero'])
        && (string) ($seed['hero']['title'] ?? '') === $previousName) {
        $seed['hero']['title'] = $name;
    }

    $php = "<?php\n\nreturn " . var_export($tenant, true) . ";\n";
    $json = json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $tenantTmp = $tenantFile . '.tmp';
    $seedTmp = $seedFile . '.tmp';
    if (file_put_contents($tenantTmp, $php, LOCK_EX) === false || file_put_contents($seedTmp, $json, LOCK_EX) === false) {
        @unlink($tenantTmp);
        @unlink($seedTmp);
        throw new RuntimeException('Impossible d’enregistrer le nouveau nom de boutique.');
    }
    if (!rename($tenantTmp, $tenantFile) || !rename($seedTmp, $seedFile)) {
        @unlink($tenantTmp);
        @unlink($seedTmp);
        throw new RuntimeException('Impossible de finaliser le changement de nom.');
    }
    try {
        $db = new PDO('sqlite:' . tenant_file($tenant, 'db_file'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $db->prepare('UPDATE content SET site_name = ?, hero_title = CASE WHEN hero_title = ? THEN ? ELSE hero_title END WHERE id = 1');
        $stmt->execute([$name, $previousName, $name]);
    } catch (PDOException $ex) {
        error_log('branding_apply_shop_name: ' . $ex->getMessage());
        throw new RuntimeException('Le nom a été enregistré, mais la page de la boutique n’a pas pu être actualisée.');
    }
    if (function_exists('opcache_invalidate')) opcache_invalidate($tenantFile, true);
}

function branding_apply_shop_template(string $slug, string $template): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/', $slug) || !shop_template_valid($template)) {
        throw new InvalidArgumentException('Choisissez un modèle de design valide.');
    }

    $tenant = tenant_load($slug);
    $database = tenant_file($tenant, 'db_file');
    if (!is_file($database)) throw new RuntimeException('La base de données de cette boutique est introuvable.');

    $design = SHOP_TEMPLATES[$template];
    try {
        $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $value = json_encode([
            'colors' => $design['colors'],
            'fonts' => $design['fonts'],
            'template' => $template,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare("INSERT INTO settings (name, value) VALUES ('appearance', ?)
                              ON CONFLICT(name) DO UPDATE SET value = excluded.value");
        $stmt->execute([$value]);
    } catch (PDOException | JsonException $ex) {
        error_log('branding_apply_shop_template: ' . $ex->getMessage());
        throw new RuntimeException('Impossible d’enregistrer le modèle de design de cette boutique.');
    }
}

function branding_generate_logo(array $request): string
{
    $shopName = trim((string) ($request['shop_name'] ?? ''));
    $about = trim((string) ($request['about'] ?? ''));
    $prompt = "Crée un logo de marque professionnel, simple, original et lisible pour un petit commerce. "
        . "Le nom exact est « " . mb_substr($shopName, 0, 60) . " ». Activité : "
        . mb_substr($about, 0, 400) . ". Image carrée, fond blanc, composition centrée, formes vectorielles nettes, "
        . "palette de deux ou trois couleurs, sans maquette, sans filigrane, sans autre texte que le nom fourni.";
    $data = branding_gemini_call('gemini-2.5-flash-image', [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => '1:1']],
    ]);
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $encoded = (string) ($part['inlineData']['data'] ?? '');
        if ($encoded === '') continue;
        $bytes = base64_decode($encoded, true);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || strlen($bytes) > 8 * 1024 * 1024 || $image === false) {
            throw new RuntimeException('Gemini a renvoyé un logo image invalide.');
        }
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$image['mime'] ?? ''] ?? null;
        if ($extension === null) throw new RuntimeException('Gemini a renvoyé un format de logo non accepté.');
        $dir = dirname(__DIR__) . '/assets/tenants/' . $request['slug'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de préparer le dossier du logo.');
        }
        $relative = 'assets/tenants/' . $request['slug'] . '/logo-genere-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $absolute = dirname(__DIR__) . '/' . $relative;
        if (file_put_contents($absolute, $bytes, LOCK_EX) === false) throw new RuntimeException('Impossible d’enregistrer le logo généré.');

        $tenantFile = tenant_dir((string) $request['slug']) . '/tenant.php';
        $tenant = tenant_load((string) $request['slug']);
        $tenant['logo'] = $relative;
        $tenant['logo_macaron'] = $relative;
        $tmp = $tenantFile . '.tmp';
        if (file_put_contents($tmp, "<?php\n\nreturn " . var_export($tenant, true) . ";\n", LOCK_EX) === false
            || !rename($tmp, $tenantFile)) {
            @unlink($tmp);
            @unlink($absolute);
            throw new RuntimeException('Le logo est généré mais n’a pas pu être installé sur la boutique.');
        }
        if (function_exists('opcache_invalidate')) opcache_invalidate($tenantFile, true);
        return $relative;
    }
    throw new RuntimeException('Gemini n’a pas renvoyé d’image de logo. Réessayez.');
}

function branding_domain_config_file(): string
{
    return dirname(__DIR__) . '/.secrets/ovh.json';
}

function branding_domain_config_saved(): array
{
    $file = branding_domain_config_file();
    if (!is_file($file)) return [];
    try {
        $saved = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $ex) {
        throw new RuntimeException('Le fichier de configuration OVH est illisible. Corrigez-le depuis le portail.');
    }
    if (!is_array($saved)) throw new RuntimeException('Le fichier de configuration OVH est invalide.');
    return $saved;
}

function branding_domain_config_save(array $input): void
{
    $saved = branding_domain_config_saved();
    foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('Les identifiants OVH doivent être du texte.');
        $value = trim($value);
        if ($value !== '') {
            if (strlen($value) > 512 || preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Un identifiant OVH est invalide ou trop long.');
            }
            $saved[$key] = $value;
        }
    }

    foreach (['OVH_ENDPOINT', 'OVH_SUBSIDIARY', 'OVH_DOMAIN_MARGIN_EUR'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) {
            throw new InvalidArgumentException('Les réglages OVH doivent être du texte.');
        }
    }
    $endpoint = trim((string) ($input['OVH_ENDPOINT'] ?? $saved['OVH_ENDPOINT'] ?? 'ovh-eu'));
    if (!in_array($endpoint, ['ovh-eu', 'ovh-ca', 'ovh-us'], true)) {
        throw new InvalidArgumentException('Choisissez un endpoint OVH valide.');
    }
    $subsidiary = strtoupper(trim((string) ($input['OVH_SUBSIDIARY'] ?? $saved['OVH_SUBSIDIARY'] ?? 'FR')));
    if (!preg_match('/^[A-Z]{2}$/', $subsidiary)) {
        throw new InvalidArgumentException('La filiale OVH doit être un code pays à deux lettres, par exemple FR.');
    }
    $marginInput = trim((string) ($input['OVH_DOMAIN_MARGIN_EUR'] ?? $saved['OVH_DOMAIN_MARGIN_EUR'] ?? '0'));
    $margin = filter_var($marginInput, FILTER_VALIDATE_FLOAT);
    if ($margin === false || $margin < 0 || $margin > 1000) {
        throw new InvalidArgumentException('La marge doit être comprise entre 0 et 1000 €.');
    }
    $saved['OVH_ENDPOINT'] = $endpoint;
    $saved['OVH_SUBSIDIARY'] = $subsidiary;
    $saved['OVH_DOMAIN_MARGIN_EUR'] = (string) $margin;

    foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'] as $key) {
        if (trim((string) ($saved[$key] ?? '')) === '' && trim((string) getenv($key)) === '') {
            throw new InvalidArgumentException('Renseignez les trois identifiants API OVH avant d’enregistrer.');
        }
    }

    $dir = dirname(branding_domain_config_file());
    if (is_link($dir)) throw new RuntimeException('Le dossier .secrets ne peut pas être un lien symbolique.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier .secrets.');
    }
    $tmp = tempnam($dir, '.ovh-');
    if ($tmp === false) throw new RuntimeException('Impossible de préparer le fichier de configuration OVH.');
    try {
        $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !@chmod($tmp, 0600)
            || !@rename($tmp, branding_domain_config_file())) {
            throw new RuntimeException('Impossible d’enregistrer la configuration OVH. Vérifiez les droits du dossier .secrets.');
        }
    } catch (Throwable $ex) {
        if (is_file($tmp)) @unlink($tmp);
        throw $ex;
    }
    @chmod(branding_domain_config_file(), 0600);
}

function branding_domain_config_delete(): void
{
    $file = branding_domain_config_file();
    if (is_file($file) && !@unlink($file)) {
        throw new RuntimeException('Impossible de supprimer la configuration OVH enregistrée.');
    }
}

function branding_domain_config(): array
{
    $saved = branding_domain_config_saved();
    $value = static function (string $key, string $fallback = '') use ($saved): string {
        $env = trim((string) getenv($key));
        $stored = $saved[$key] ?? $fallback;
        return $env !== '' ? $env : (is_scalar($stored) ? trim((string) $stored) : $fallback);
    };
    $margin = filter_var($value('OVH_DOMAIN_MARGIN_EUR', '0'), FILTER_VALIDATE_FLOAT);
    if ($margin === false || $margin < 0 || $margin > 1000) {
        throw new RuntimeException('OVH_DOMAIN_MARGIN_EUR doit être compris entre 0 et 1000.');
    }
    $subsidiary = strtoupper($value('OVH_SUBSIDIARY', 'FR'));
    if (!preg_match('/^[A-Z]{2}$/', $subsidiary)) {
        throw new RuntimeException('OVH_SUBSIDIARY doit être un code pays ISO à deux lettres.');
    }
    return [
        'application_key' => $value('OVH_APPLICATION_KEY'),
        'application_secret' => $value('OVH_APPLICATION_SECRET'),
        'consumer_key' => $value('OVH_CONSUMER_KEY'),
        'endpoint' => $value('OVH_ENDPOINT', 'ovh-eu'),
        'subsidiary' => $subsidiary,
        'margin_cents' => (int) round($margin * 100),
    ];
}

function branding_ovh_client(): \Ovh\Api
{
    $config = branding_domain_config();
    if ($config['application_key'] === '' || $config['application_secret'] === '' || $config['consumer_key'] === '') {
        throw new RuntimeException('L’achat de domaines n’est pas configuré. Les identifiants OVH manquent.');
    }
    if (!in_array($config['endpoint'], ['ovh-eu', 'ovh-ca', 'ovh-us'], true)) {
        throw new RuntimeException('La valeur OVH_ENDPOINT n’est pas un endpoint OVH pris en charge.');
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Le SDK OVH est absent. Exécutez composer install sur le serveur.');
    require_once $autoload;
    return new \Ovh\Api($config['application_key'], $config['application_secret'], $config['endpoint'], $config['consumer_key']);
}

function branding_ovh_api_error(\GuzzleHttp\Exception\GuzzleException $ex, string $stage): RuntimeException
{
    $response = method_exists($ex, 'getResponse') ? $ex->getResponse() : null;
    $status = $response ? $response->getStatusCode() : 0;
    $handlerContext = method_exists($ex, 'getHandlerContext') ? $ex->getHandlerContext() : [];
    $curlError = (int) ($handlerContext['errno'] ?? 0);
    $cause = strtolower((string) ($handlerContext['error'] ?? '') . ' ' . $ex->getMessage());
    error_log('OVH API request failed at ' . $stage . ': HTTP ' . $status . '; cURL ' . $curlError . ' ' . substr($cause, 0, 300));
    if ($ex instanceof \GuzzleHttp\Exception\ConnectException || ($ex instanceof \GuzzleHttp\Exception\RequestException && !$response)) {
        $detail = match (true) {
            $curlError === 6, str_contains($cause, 'cURL error 6'), str_contains($cause, 'could not resolve') =>
                'Le serveur ne résout pas le nom de domaine OVH. Vérifiez le DNS sortant de l’hébergement.',
            $curlError === 28, str_contains($cause, 'cURL error 28'), str_contains($cause, 'timed out') =>
                'La connexion au serveur OVH expire. Vérifiez que l’hébergement autorise les connexions HTTPS sortantes vers l’API OVH.',
            $curlError === 35, str_contains($cause, 'cURL error 35'), str_contains($cause, 'ssl connect error') =>
                'La négociation TLS avec OVH échoue. Vérifiez la version TLS et les certificats CA installés sur l’hébergement.',
            $curlError === 60, str_contains($cause, 'cURL error 60'), str_contains($cause, 'certificate problem') =>
                'Le certificat TLS d’OVH ne peut pas être vérifié par ce serveur. Vérifiez le bundle CA de PHP/cURL.',
            $curlError === 7, str_contains($cause, 'cURL error 7'), str_contains($cause, 'connection refused') =>
                'La connexion HTTPS à OVH est refusée par le réseau de l’hébergement.',
            default => 'Le serveur ne parvient pas à établir une connexion HTTPS avec OVH. Vérifiez le DNS, le pare-feu sortant et la configuration TLS de l’hébergement.',
        };
        return new RuntimeException($detail . ' (étape « ' . $stage . ' »).');
    }
    $detail = match ($status) {
        401 => 'OVH refuse l’authentification (HTTP 401). Vérifiez l’Application Key, le Secret et le Consumer Key.',
        403 => 'OVH refuse les droits (HTTP 403) sur « ' . $stage . ' ». Autorisez précisément cette route dans le jeton OVH.',
        404 => 'OVH ne trouve pas la route ou la ressource (HTTP 404) à l’étape « ' . $stage . ' ». Vérifiez l’endpoint et recommencez avec un nouveau jeton si nécessaire.',
        429 => 'OVH limite temporairement les appels (HTTP 429). Attendez quelques instants puis réessayez.',
        default => $status > 0
            ? 'La requête OVH a échoué (HTTP ' . $status . ') à l’étape « ' . $stage . ' ». Vérifiez les réglages OVH.'
            : 'OVH n’a renvoyé aucune réponse HTTP à l’étape « ' . $stage . ' ». Vérifiez les journaux PHP du serveur pour la cause réseau exacte.',
    };
    return new RuntimeException($detail);
}

function branding_ovh_test_connection(): array
{
    $config = branding_domain_config();
    $client = branding_ovh_client();
    $stage = 'GET /auth/time';
    try {
        $client->get('/auth/time', null, [], false);
        $stage = 'POST /order/cart';
        $cart = $client->post('/order/cart', [
            'ovhSubsidiary' => $config['subsidiary'],
            'description' => 'Test de connexion API OVH',
        ]);
        $cartId = (string) ($cart['cartId'] ?? '');
        if ($cartId === '') {
            throw new RuntimeException('OVH a répondu, mais n’a pas créé le panier de test. Vérifiez la filiale de facturation et les droits API.');
        }
        $stage = 'GET /order/cart/*/domain';
        $client->get('/order/cart/' . rawurlencode($cartId) . '/domain', ['domain' => 'example.com']);
    } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
        throw branding_ovh_api_error($ex, $stage);
    }

    return ['endpoint' => $config['endpoint'], 'subsidiary' => $config['subsidiary']];
}

function branding_domain_normalize(string $domain): string
{
    $domain = strtolower(trim($domain));
    if (strlen($domain) > 253 || !preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        throw new InvalidArgumentException('Saisissez un nom de domaine valide, par exemple moncommerce.fr.');
    }
    return $domain;
}

function branding_claim_domain_order(string $requestId, array $session): array
{
    $id = preg_match('/^[a-f0-9]{16}$/', $requestId) ? $requestId : '';
    if ($id === '') throw new RuntimeException('Identifiant de demande invalide.');
    $lockPath = saas_requests_dir() . '/' . $id . '.domain.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Impossible de verrouiller la commande du domaine.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Impossible de verrouiller la commande du domaine.');
        $request = saas_request_load($id);
        $purchase = $request['branding']['domain_purchase'] ?? null;
        if (!$request || !is_array($purchase)) throw new RuntimeException('Demande ou devis du domaine introuvable.');
        $quote = (array) ($purchase['quote'] ?? []);
        if (($session['payment_status'] ?? '') !== 'paid'
            || (string) ($session['mode'] ?? '') !== 'payment'
            || strtolower((string) ($session['currency'] ?? '')) !== 'eur'
            || (string) ($session['client_reference_id'] ?? '') !== $id
            || (string) ($session['metadata']['type'] ?? '') !== 'domain_purchase'
            || (string) ($session['metadata']['domain'] ?? '') !== (string) ($quote['domain'] ?? '')
            || (string) ($session['metadata']['cart_id'] ?? '') !== (string) ($quote['cart_id'] ?? '')
            || (int) ($session['amount_total'] ?? 0) !== (int) ($quote['amount_cents'] ?? -1)
            || (string) ($session['id'] ?? '') !== (string) ($purchase['stripe_session'] ?? '')) {
            throw new RuntimeException('Le paiement Stripe ne correspond pas au devis de domaine enregistré.');
        }
        if (in_array(($purchase['state'] ?? ''), ['ordered', 'ordering', 'paid_needs_review'], true)) return $purchase;
        if (($purchase['state'] ?? '') !== 'awaiting_payment') throw new RuntimeException('Cette commande de domaine n’est pas en attente de paiement.');

        $request['branding']['domain_purchase']['state'] = 'ordering';
        $request['branding']['domain_purchase']['paid_at'] = time();
        branding_save_request($request);
        return $request['branding']['domain_purchase'] + ['claimed' => true];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function branding_ovh_quote(string $domain): array
{
    $domain = branding_domain_normalize($domain);
    $client = branding_ovh_client();
    $config = branding_domain_config();
    $stage = 'POST /order/cart';
    try {
        $cart = $client->post('/order/cart', ['ovhSubsidiary' => $config['subsidiary'], 'description' => 'Domaine de boutique']);
        $cartId = (string) ($cart['cartId'] ?? '');
        if ($cartId === '') throw new RuntimeException('OVH n’a pas créé de panier de commande.');

        $stage = 'GET /order/cart/*/domain';
        $offers = $client->get('/order/cart/' . rawurlencode($cartId) . '/domain', ['domain' => $domain]);
        $offer = null;
        foreach ((array) $offers as $candidate) {
            if (($candidate['action'] ?? '') === 'create' && !empty($candidate['orderable'])) {
                $offer = $candidate;
                break;
            }
        }
        if (!$offer) throw new RuntimeException('Ce domaine n’est pas disponible à l’enregistrement chez OVH.');

        $stage = 'POST /order/cart/*/domain';
        $item = $client->post('/order/cart/' . rawurlencode($cartId) . '/domain', [
            'domain' => $domain,
            'duration' => 'P1Y',
            'offerId' => (string) ($offer['offerId'] ?? ''),
            'planCode' => (string) ($offer['planCode'] ?? ''),
            'pricingMode' => (string) ($offer['pricingMode'] ?? ''),
        ]);
        $itemId = (string) ($item['itemId'] ?? '');
        if ($itemId === '') throw new RuntimeException('OVH n’a pas ajouté le domaine au panier.');

        $stage = 'GET /order/cart/*/summary';
        $summary = $client->get('/order/cart/' . rawurlencode($cartId) . '/summary');
        $ovhCents = (int) round((float) ($summary['prices']['withTax']['value'] ?? -1) * 100);
        if ($ovhCents <= 0) throw new RuntimeException('OVH n’a pas renvoyé de prix TTC exploitable.');
        return [
            'domain' => $domain,
            'cart_id' => $cartId,
            'item_id' => $itemId,
            'offer_id' => (string) ($offer['offerId'] ?? ''),
            'pricing_mode' => (string) ($offer['pricingMode'] ?? ''),
            'ovh_cents' => $ovhCents,
            'amount_cents' => $ovhCents + $config['margin_cents'],
            'quoted_at' => time(),
        ];
    } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
        throw branding_ovh_api_error($ex, $stage);
    }
}

function branding_normalize_owner_phone(string $phone, string $country): string
{
    $phone = preg_replace('/[\s().-]+/', '', trim($phone)) ?? '';
    if (str_starts_with($phone, '00')) $phone = '+' . substr($phone, 2);

    if ($country === 'FR') {
        if (preg_match('/^0[1-9]\d{8}$/', $phone)) {
            $phone = '+33' . substr($phone, 1);
        } elseif (str_starts_with($phone, '+330')) {
            $phone = '+33' . substr($phone, 4);
        }
    }

    if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        throw new InvalidArgumentException('Le téléphone doit être au format international, par exemple +33612345678.');
    }
    return $phone;
}

function branding_ovh_prepare_domain(array $request, array $purchase): string
{
    $client = branding_ovh_client();
    $cartId = rawurlencode((string) ($purchase['quote']['cart_id'] ?? ''));
    $itemId = rawurlencode((string) ($purchase['quote']['item_id'] ?? ''));
    $owner = (array) ($purchase['owner'] ?? []);
    if ($cartId === '' || $itemId === '') throw new RuntimeException('Le devis OVH ne contient pas de panier valide.');

    $stage = 'POST /order/cart/*/assign';
    try {
        $client->post('/order/cart/' . $cartId . '/assign');
        $legalForm = (string) ($owner['legal_form'] ?? 'individual');
        if (!in_array($legalForm, ['administration', 'association', 'corporation', 'individual', 'other', 'personalcorporation'], true)) {
            throw new RuntimeException('La forme juridique du titulaire est invalide.');
        }
        if ($legalForm !== 'individual' && trim((string) ($owner['organization'] ?? '')) === '') {
            throw new RuntimeException('Le nom de l’entreprise ou de l’organisation titulaire est obligatoire.');
        }
        $contactData = [
            'email' => (string) $request['email'],
            'firstName' => (string) ($owner['first_name'] ?? ''),
            'lastName' => (string) ($owner['last_name'] ?? ''),
            'address' => [
                'line1' => (string) ($owner['address'] ?? ''),
                'zip' => (string) ($owner['postal_code'] ?? ''),
                'city' => (string) ($owner['city'] ?? ''),
                'country' => (string) ($owner['country'] ?? 'FR'),
            ],
            'phone' => (string) ($owner['phone'] ?? ''),
            'language' => 'fr_FR',
            'legalForm' => $legalForm,
        ];
        if ((string) ($owner['organization'] ?? '') !== '') {
            $contactData['organisationName'] = (string) $owner['organization'];
        }
        $stage = 'POST /domain/contact';
        $contact = $client->post('/domain/contact', $contactData);
        $contactId = (string) ($contact['id'] ?? '');
        if ($contactId === '') throw new RuntimeException('OVH n’a pas créé le contact titulaire du domaine.');

        $stage = 'GET /order/cart/*/item/*/requiredConfiguration';
        $required = $client->get('/order/cart/' . $cartId . '/item/' . $itemId . '/requiredConfiguration');
        $ownerConfigured = false;
        foreach ((array) $required as $configuration) {
            $label = (string) ($configuration['label'] ?? '');
            $value = match ($label) {
                'OWNER_CONTACT' => '/me/contact/' . $contactId,
                'OWNER_LEGAL_AGE' => 'true',
                'ACCEPT_CONDITIONS' => 'true',
                default => '',
            };
            if ($value !== '') {
                if ($label === 'OWNER_CONTACT') $ownerConfigured = true;
                $stage = 'POST /order/cart/*/item/*/configuration';
                $client->post('/order/cart/' . $cartId . '/item/' . $itemId . '/configuration', [
                    'label' => $label,
                    'value' => $value,
                ]);
            } elseif (!empty($configuration['mandatory'])) {
                throw new RuntimeException('OVH demande une information supplémentaire obligatoire (' . $label . ').');
            }
        }
        if (!$ownerConfigured) throw new RuntimeException('OVH n’a pas fourni de configuration de titulaire. Le paiement est bloqué pour éviter une commande au nom du compte plateforme.');
        $stage = 'POST /order/cart/*/validate';
        $client->post('/order/cart/' . $cartId . '/validate');
        return $contactId;
    } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
        throw branding_ovh_api_error($ex, $stage);
    }
}

function branding_stripe_domain_checkout(array $request, array $quote, string $origin): array
{
    $amount = (int) ($quote['amount_cents'] ?? 0);
    if ($amount < 50 || $amount > 1000000) throw new RuntimeException('Le prix du domaine est invalide.');
    $description = 'Enregistrement 1 an — ' . $quote['domain'] . ' via OVH';
    return saas_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'locale' => 'fr',
        'customer_email' => $request['email'],
        'client_reference_id' => $request['id'],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $amount,
                'product_data' => ['name' => 'Nom de domaine ' . $quote['domain'], 'description' => $description],
            ],
        ]],
        'metadata' => [
            'type' => 'domain_purchase',
            'request_id' => $request['id'],
            'domain' => $quote['domain'],
            'cart_id' => $quote['cart_id'],
        ],
        'success_url' => $origin . '/inscription/identite.php?r=' . $request['id'] . '&k=' . $request['token'] . '&domaine={CHECKOUT_SESSION_ID}',
        'cancel_url' => $origin . '/inscription/identite.php?r=' . $request['id'] . '&k=' . $request['token'],
    ]);
}

function branding_apply_domain_checkout(array $session): array
{
    $requestId = (string) ($session['metadata']['request_id'] ?? '');
    $request = saas_request_load($requestId);
    if (!$request) throw new RuntimeException('Demande d’inscription introuvable.');
    $purchase = $request['branding']['domain_purchase'] ?? null;
    if (!is_array($purchase)) throw new RuntimeException('Devis du domaine introuvable.');
    $purchase = branding_claim_domain_order($requestId, $session);
    if (empty($purchase['claimed'])) return $purchase;
    unset($purchase['claimed']);
    $request = saas_request_load($requestId) ?? $request;

    try {
        $client = branding_ovh_client();
        $cartId = rawurlencode((string) $purchase['quote']['cart_id']);
        $client->post('/order/cart/' . $cartId . '/validate');
        $order = $client->post('/order/cart/' . $cartId . '/checkout', [
            'autoPayWithPreferredPaymentMethod' => true,
            'waiveRetractationPeriod' => true,
        ]);
    } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
        error_log('OVH domain order: ' . $ex->getMessage());
        $request = saas_request_load($requestId) ?? $request;
        $request['branding']['domain_purchase']['state'] = 'paid_needs_review';
        $request['branding']['domain_purchase']['message'] = 'OVH n’a pas confirmé la commande après le paiement Stripe.';
        branding_save_request($request);
        branding_domain_review_mail($request, $purchase);
        throw new RuntimeException('Paiement confirmé, mais OVH n’a pas confirmé la commande. Notre équipe vérifiera le statut avant toute nouvelle tentative.');
    } catch (RuntimeException $ex) {
        $request = saas_request_load($requestId) ?? $request;
        $request['branding']['domain_purchase']['state'] = 'paid_needs_review';
        $request['branding']['domain_purchase']['message'] = $ex->getMessage();
        branding_save_request($request);
        branding_domain_review_mail($request, $purchase);
        throw $ex;
    }
    $orderId = (string) ($order['orderId'] ?? $order['id'] ?? '');
    if ($orderId === '') {
        $request['branding']['domain_purchase']['state'] = 'paid_needs_review';
        $request['branding']['domain_purchase']['message'] = 'Paiement reçu ; OVH ne confirme pas de numéro de commande.';
        branding_save_request($request);
        branding_domain_review_mail($request, $purchase);
        throw new RuntimeException('Paiement reçu. La confirmation de commande OVH nécessite une vérification manuelle.');
    }

    $request['branding']['domain_purchase']['state'] = 'ordered';
    $request['branding']['domain_purchase']['ovh_order_id'] = $orderId;
    $request['branding']['domain_purchase']['ordered_at'] = time();
    branding_save_request($request);
    saas_event('domain_ordered', [
        'slug' => (string) ($request['slug'] ?? ''),
        'ref' => $requestId,
        'email' => (string) ($request['email'] ?? ''),
        'note' => (string) $purchase['quote']['domain'] . ' — commande OVH ' . $orderId,
        'actor' => 'stripe',
    ]);
    saas_mail((string) $request['email'], 'Commande de domaine transmise à OVH',
        "Bonjour {$request['first_name']},\n\nLa commande du domaine {$purchase['quote']['domain']} a été transmise à OVH (référence {$orderId}).\n"
        . "La mise en service dépend du délai de traitement d’OVH. La facturation du renouvellement annuel n’est pas incluse dans le paiement de la première année.\n");
    return $request['branding']['domain_purchase'];
}

function branding_domain_review_mail(array $request, array $purchase): void
{
    $domain = (string) ($purchase['quote']['domain'] ?? 'inconnu');
    $message = "Le paiement de {$domain} est confirmé, mais OVH n’a pas confirmé la commande. Aucune nouvelle tentative automatique ne sera faite avant vérification.\n\n"
        . "Demande : " . (string) ($request['id'] ?? '') . "\nBoutique : " . (string) ($request['slug'] ?? '')
        . "\nPanier OVH : " . (string) ($purchase['quote']['cart_id'] ?? '')
        . "\nSession Stripe : " . (string) ($purchase['stripe_session'] ?? '') . "\n";
    saas_mail((string) ($request['email'] ?? ''), 'Vérification requise pour votre nom de domaine', $message);
    $contact = (string) (saas_config()['contact'] ?? '');
    if ($contact !== '') saas_mail($contact, 'Commande de domaine à vérifier : ' . $domain, $message);
}
