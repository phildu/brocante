<?php

// Fournisseurs d'IA de la plateforme : quels services assurent le texte, l'image et la vidéo, avec quelle clé et quel modèle,
// et si l'IA est proposée à chaque boutique. Réglé dans le portail (portail/ia-fournisseurs.php), partagé par toutes les boutiques
// de ce déploiement. Le fichier data/saas/ai-providers.json vit hors racine web, comme le barème et les quotas (includes/ai-plans.php).
//
// Deux interrupteurs : « IA de la plateforme » (valeur par défaut) et une exception par boutique. Une boutique dont l'IA est
// désactivée n'émet plus aucun appel payant et ne voit plus les écrans d'IA dans son administration (voir ai_gate() dans
// includes/ai-usage.php, includes/admin-menu.php, includes/admin-nav.php et admin/dashboard.php).
//
// Fournisseurs fournis au départ : Gemini (texte et image), Claude (texte), Veo (vidéo), SiliconFlow (vidéo) et Modal (vidéo).
// On peut en ajouter d'autres : un service « compatible OpenAI » pour le texte et l'image, « compatible SiliconFlow » pour la
// vidéo, ou Claude. La clé d'un fournisseur ajouté est un fichier .secrets/_partage/ai-<catégorie>-<identifiant>.key (0600).

require_once __DIR__ . '/shared-secrets.php';

const AI_PROVIDER_CATEGORIES = ['text' => 'Texte', 'image' => 'Image', 'video' => 'Vidéo'];

/** Connecteurs disponibles par catégorie : identifiant => libellé. */
const AI_PROVIDER_DRIVERS = [
    'text' => ['gemini' => 'Google Gemini', 'openai' => 'Compatible OpenAI', 'anthropic' => 'Anthropic Claude'],
    'image' => ['gemini' => 'Google Gemini', 'openai' => 'Compatible OpenAI'],
    'video' => ['veo' => 'Google Veo', 'siliconflow' => 'Compatible SiliconFlow', 'modal' => 'Modal'],
];

function ai_provider_file(): string
{
    return dirname(__DIR__) . '/data/saas/ai-providers.json';
}

/** Fournisseurs fournis au départ, par catégorie. « secret » est le nom du fichier de clé existant. */
function ai_provider_builtins(): array
{
    return [
        'text' => [
            ['id' => 'gemini', 'label' => 'Google Gemini', 'driver' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => '', 'secret' => 'gemini.key', 'builtin' => true, 'enabled' => true],
            ['id' => 'claude', 'label' => 'Anthropic Claude', 'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-sonnet-4-5', 'secret' => 'claude.key', 'builtin' => true, 'enabled' => true],
        ],
        'image' => [
            ['id' => 'gemini', 'label' => 'Google Gemini', 'driver' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => '', 'secret' => 'gemini.key', 'builtin' => true, 'enabled' => true],
        ],
        'video' => [
            ['id' => 'veo', 'label' => 'Google Veo', 'driver' => 'veo', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => '', 'secret' => 'gemini.key', 'builtin' => true, 'enabled' => true],
            ['id' => 'siliconflow', 'label' => 'SiliconFlow (Wan 2.2)', 'driver' => 'siliconflow', 'base_url' => 'https://api.siliconflow.com/v1', 'model' => 'Wan-AI/Wan2.2-I2V-A14B', 'secret' => 'siliconflow.key', 'builtin' => true, 'enabled' => true],
            ['id' => 'modal', 'label' => 'Modal (Wan 2.2 5B)', 'driver' => 'modal', 'base_url' => '', 'model' => '', 'secret' => 'modal-video.json', 'builtin' => true, 'enabled' => true],
        ],
    ];
}

/** Réglages enregistrés (tableau vide si le fichier n'existe pas encore). */
function ai_provider_read(): array
{
    $file = ai_provider_file();
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

/** Écrit les réglages sous verrou ; $mutate reçoit l'état courant et retourne le nouveau. */
function ai_provider_update(callable $mutate): bool
{
    $file = ai_provider_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    $fh = @fopen($file, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $cur = json_decode((string) stream_get_contents($fh), true);
    $new = $mutate(is_array($cur) ? $cur : []);
    ftruncate($fh, 0);
    rewind($fh);
    $ok = fwrite($fh, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($file, 0644);
    return $ok;
}

function ai_provider_id_ok(string $id): bool
{
    return preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $id) === 1;
}

/** Nom du fichier de clé d'un fournisseur ajouté. */
function ai_provider_custom_secret(string $category, string $id): string
{
    return 'ai-' . preg_replace('/[^a-z]/', '', $category) . '-' . $id . '.key';
}

/** Identifiant lisible et unique dérivé d'un libellé. */
function ai_provider_new_id(string $category, string $label, array $used): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
    $base = strtolower((string) ($ascii !== false ? $ascii : $label));
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
    if ($base === '') $base = 'fournisseur';
    $base = substr($base, 0, 32);
    $id = $base;
    for ($n = 2; in_array($id, $used, true) || in_array($id, ['gemini', 'claude', 'veo', 'siliconflow', 'modal'], true); $n++) $id = $base . '-' . $n;
    return $id;
}

/** Une ligne de fournisseur normalisée, ou null si elle est inutilisable. */
function ai_provider_row(array $row, string $category, ?array $base = null): ?array
{
    $id = (string) ($row['id'] ?? $base['id'] ?? '');
    if (!ai_provider_id_ok($id)) return null;
    $drivers = AI_PROVIDER_DRIVERS[$category] ?? [];
    $driver = (string) ($row['driver'] ?? $base['driver'] ?? '');
    if (!isset($drivers[$driver])) $driver = (string) array_key_first($drivers);
    $label = mb_substr(trim((string) ($row['label'] ?? $base['label'] ?? $id)), 0, 60);
    if ($label === '') $label = $id;
    $baseUrl = rtrim(trim((string) ($row['base_url'] ?? $base['base_url'] ?? '')), '/');
    if ($baseUrl !== '' && !preg_match('#^https?://[^\s]+$#i', $baseUrl)) $baseUrl = '';
    $model = mb_substr(trim((string) ($row['model'] ?? $base['model'] ?? '')), 0, 120);
    $builtin = $base !== null;
    $secret = $builtin ? (string) $base['secret'] : ai_provider_custom_secret($category, $id);
    $enabled = array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true;
    return ['id' => $id, 'label' => $label, 'driver' => $driver, 'base_url' => $baseUrl, 'model' => $model,
        'secret' => $secret, 'builtin' => $builtin, 'enabled' => $enabled];
}

/** Fournisseurs d'une catégorie, dans l'ordre enregistré (à défaut, ceux fournis au départ). */
function ai_provider_rows(string $category): array
{
    $builtins = ai_provider_builtins()[$category] ?? [];
    $byId = [];
    foreach ($builtins as $b) $byId[$b['id']] = $b;
    $saved = ai_provider_read()['providers'][$category] ?? null;
    if (!is_array($saved) || !$saved) return $builtins;
    $rows = [];
    foreach ($saved as $row) {
        if (!is_array($row)) continue;
        $id = (string) ($row['id'] ?? '');
        $normalized = ai_provider_row($row, $category, $byId[$id] ?? null);
        if ($normalized && !isset($rows[$normalized['id']])) $rows[$normalized['id']] = $normalized;
    }
    return $rows ? array_values($rows) : $builtins;
}

/** Fournisseurs de toutes les catégories : catégorie => lignes. */
function ai_providers(): array
{
    $out = [];
    foreach (array_keys(AI_PROVIDER_CATEGORIES) as $category) $out[$category] = ai_provider_rows($category);
    return $out;
}

/** Fournisseur actif d'une catégorie (le premier activé si le fournisseur choisi a disparu), ou null. */
function ai_provider_active(string $category): ?array
{
    $rows = ai_provider_rows($category);
    if (!$rows) return null;
    $active = (string) (ai_provider_read()['active'][$category] ?? '');
    foreach ($rows as $row) if ($row['id'] === $active && $row['enabled']) return $row;
    foreach ($rows as $row) if ($row['enabled']) return $row;
    return null;
}

/** Fournisseur choisi par défaut dans le portail (même s'il est désactivé), pour l'affichage. */
function ai_provider_selected(string $category): string
{
    $active = (string) (ai_provider_read()['active'][$category] ?? '');
    foreach (ai_provider_rows($category) as $row) if ($row['id'] === $active) return $active;
    return (string) (ai_provider_rows($category)[0]['id'] ?? '');
}

/** IA proposée à une boutique ? (exception par boutique, sinon valeur de la plateforme ; oui par défaut). */
function ai_shop_enabled(?string $slug = null): bool
{
    $settings = ai_provider_read();
    if ($slug === null) $slug = function_exists('tenant_slug') ? (string) tenant_slug() : '';
    $shops = isset($settings['shops']) && is_array($settings['shops']) ? $settings['shops'] : [];
    if ($slug !== '' && array_key_exists($slug, $shops)) return (bool) $shops[$slug];
    return !array_key_exists('enabled', $settings) || (bool) $settings['enabled'];
}

/** Réglage d'une boutique : true/false (exception) ou null (suit la plateforme). */
function ai_shop_setting(string $slug): ?bool
{
    $shops = ai_provider_read()['shops'] ?? [];
    return is_array($shops) && array_key_exists($slug, $shops) ? (bool) $shops[$slug] : null;
}

// ── Clés ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Chemin de la clé d'un fournisseur : celle du commerce puis la partagée (fournis), ou la clé partagée (ajoutés). */
function ai_provider_secret_path(array $provider): string
{
    $name = (string) ($provider['secret'] ?? '');
    if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) return '';
    if (!empty($provider['builtin'])) {
        if (defined('SECRETS_DIR') && is_file(SECRETS_DIR . '/' . $name)) return SECRETS_DIR . '/' . $name;
        $shared = shared_secret_read_path($name);
        if ($shared !== null) return $shared;
        return (defined('SECRETS_DIR') ? SECRETS_DIR : dirname(__DIR__) . '/.secrets') . '/' . $name;
    }
    return shared_secrets_dir() . '/' . $name;
}

/** Contenu de la clé (le jeton seul pour Modal). Chaîne vide si absente. */
function ai_provider_key_value(array $provider): string
{
    $file = ai_provider_secret_path($provider);
    if ($file === '' || !is_file($file)) return '';
    $raw = trim((string) @file_get_contents($file));
    if (str_ends_with($file, '.json')) {
        $data = json_decode($raw, true) ?: [];
        return trim((string) ($data['token'] ?? ''));
    }
    return $raw;
}

function ai_provider_has_key(array $provider): bool
{
    return ai_provider_key_value($provider) !== '';
}

/** Enregistre la clé d'un fournisseur ajouté (fichier partagé, lisible par le seul propriétaire). */
function ai_provider_secret_store(string $name, string $value): bool
{
    if (!preg_match('/^ai-[a-z]+-[a-z0-9-]+\.key$/', $name) || trim($value) === '') return false;
    $dir = shared_secrets_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $file = $dir . '/' . $name;
    if (@file_put_contents($file, trim($value)) === false) return false;
    @chmod($file, 0600);
    return true;
}

/** La clé d'un fournisseur ajouté est-elle enregistrée ? */
function ai_provider_secret_present(array $provider): bool
{
    return ai_provider_has_key($provider);
}

/** Efface la clé d'un fournisseur ajouté (les clés fournies se gèrent dans « API & réseaux »). */
function ai_provider_secret_delete(array $provider): void
{
    if (!empty($provider['builtin'])) return;
    $file = ai_provider_secret_path($provider);
    if ($file !== '' && is_file($file)) @unlink($file);
}

// ── Test de connexion ─────────────────────────────────────────────────────────────────────────────────────────────────────

/** Appel HTTP brut (cURL) : [code, corps]. Code 0 = pas de réponse. */
function ai_provider_http(string $method, string $url, array $headers = [], $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($raw) ? $raw : ''];
}

/** Teste la connexion d'un fournisseur : ['ok' => bool, 'message' => texte]. */
function ai_provider_test(array $provider): array
{
    $key = ai_provider_key_value($provider);
    if ($key === '') return ['ok' => false, 'message' => 'Aucune clé enregistrée pour ce fournisseur.'];
    $driver = (string) ($provider['driver'] ?? '');
    if ($driver === 'modal') {
        $file = ai_provider_secret_path($provider);
        $data = $file !== '' && is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
        return !empty($data['url'])
            ? ['ok' => true, 'message' => 'Service Modal configuré : ' . (string) $data['url'] . '. Il répondra à la première génération.']
            : ['ok' => false, 'message' => "Adresse Modal absente : renseignez l'adresse et le jeton dans « API & réseaux »."];
    }
    $base = rtrim((string) ($provider['base_url'] ?? ''), '/');
    if ($base === '') return ['ok' => false, 'message' => "Adresse de base absente : indiquez-la pour pouvoir tester."];
    $url = $base . '/models';
    if ($driver === 'gemini' || $driver === 'veo') {
        [$code, $raw] = ai_provider_http('GET', $url . '?pageSize=1', ['x-goog-api-key: ' . $key]);
    } elseif ($driver === 'anthropic') {
        [$code, $raw] = ai_provider_http('GET', $url, ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01']);
    } else {
        [$code, $raw] = ai_provider_http('GET', $url, ['Authorization: Bearer ' . $key]);
    }
    $data = json_decode($raw, true) ?: [];
    $detail = trim((string) ($data['error']['message'] ?? $data['message'] ?? ''));
    if ($code === 200) return ['ok' => true, 'message' => 'Connexion réussie : le service répond.'];
    if ($code === 0) return ['ok' => false, 'message' => 'Aucune réponse du service : adresse ou réseau indisponible.'];
    if (in_array($code, [401, 403], true)) return ['ok' => false, 'message' => 'Clé refusée par le service (HTTP ' . $code . ')' . ($detail !== '' ? ' : ' . mb_substr($detail, 0, 160) : '.')];
    if ($code === 402) return ['ok' => false, 'message' => 'Crédits épuisés chez ce service (HTTP 402).'];
    if ($code === 404) return ['ok' => false, 'message' => "Adresse de base probablement incorrecte : /models est introuvable (HTTP 404)."];
    return ['ok' => false, 'message' => 'Réponse inattendue (HTTP ' . $code . ')' . ($detail !== '' ? ' : ' . mb_substr($detail, 0, 160) : '.')];
}

// ── Adaptateurs d'appel (texte et image) ──────────────────────────────────────────────────────────────────────────────────

function ai_provider_endpoint(array $provider, string $path): string
{
    return rtrim((string) $provider['base_url'], '/') . '/' . ltrim($path, '/');
}

function ai_provider_text_model(array $provider): string
{
    $model = trim((string) ($provider['model'] ?? ''));
    return $model !== '' ? $model : 'gpt-4o-mini';
}

/**
 * Texte (avec photos éventuelles) par le fournisseur actif, hors Gemini (qui a son propre appel dans functions.php).
 * Retourne le texte ou null.
 */
function ai_provider_text(array $provider, string $prompt, array $imagePaths = [], int $timeout = 90): ?string
{
    $key = ai_provider_key_value($provider);
    if ($key === '') return null;
    $images = [];
    foreach ($imagePaths as $path) {
        $bytes = @file_get_contents($path);
        if ($bytes === false) continue;
        $images[] = ['mime' => @getimagesize($path)['mime'] ?? 'image/jpeg', 'data' => base64_encode($bytes)];
    }
    $driver = (string) ($provider['driver'] ?? '');
    $model = ai_provider_text_model($provider);

    if ($driver === 'openai') {
        $content = [];
        if ($images) {
            $content[] = ['type' => 'text', 'text' => $prompt];
            foreach ($images as $img) $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['data']]];
        } else {
            $content = $prompt;
        }
        $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $content]]];
        [$status, $raw] = ai_provider_http('POST', ai_provider_endpoint($provider, 'chat/completions'),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $key], json_encode($payload), $timeout);
        if ($status >= 400 || $status === 0) { error_log('ai_provider_text openai: HTTP ' . $status . ' ' . substr($raw, 0, 400)); return null; }
        $data = json_decode($raw, true) ?: [];
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (is_array($text)) {
            $parts = [];
            foreach ($text as $part) if (is_array($part) && isset($part['text'])) $parts[] = (string) $part['text'];
            $text = implode("\n", $parts);
        }
        if (!is_string($text) || trim($text) === '') return null;
        if (function_exists('ai_usage_record') && function_exists('ai_pricing')) {
            $in = (int) ($data['usage']['prompt_tokens'] ?? 0);
            $out = (int) ($data['usage']['completion_tokens'] ?? 0);
            $pricing = ai_pricing();
            ai_usage_record('text', ($in * $pricing['text_in'] + $out * $pricing['text_out']) / 1e6, $model, $in, $out);
        }
        return $text;
    }

    if ($driver === 'anthropic') {
        $blocks = [];
        foreach ($images as $img) $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]];
        $blocks[] = ['type' => 'text', 'text' => $prompt];
        $payload = ['model' => $model, 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => $blocks]]];
        [$status, $raw] = ai_provider_http('POST', ai_provider_endpoint($provider, 'messages'),
            ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], json_encode($payload), $timeout);
        if ($status >= 400 || $status === 0) { error_log('ai_provider_text anthropic: HTTP ' . $status . ' ' . substr($raw, 0, 400)); return null; }
        $data = json_decode($raw, true) ?: [];
        $parts = [];
        foreach ((array) ($data['content'] ?? []) as $block) if (($block['type'] ?? '') === 'text') $parts[] = (string) ($block['text'] ?? '');
        $text = trim(implode("\n", $parts));
        if ($text === '') return null;
        if (function_exists('ai_usage_log_claude')) ai_usage_log_claude((array) ($data['usage'] ?? []), $model);
        return $text;
    }

    return null;
}

/**
 * Image générée (image source + consigne) par le fournisseur actif, hors Gemini. Retourne les octets ou null.
 * Connecteur « Compatible OpenAI » : POST /images/edits en multipart, réponse b64_json ou URL.
 */
function ai_provider_image(array $provider, string $srcAbsPath, string $prompt, ?string $aspectRatio = null, array $extraAbsPaths = []): ?string
{
    if ((string) ($provider['driver'] ?? '') !== 'openai') return null;
    $key = ai_provider_key_value($provider);
    if ($key === '') return null;
    $src = @file_get_contents($srcAbsPath);
    if ($src === false) return null;
    $model = trim((string) ($provider['model'] ?? '')) ?: 'gpt-image-1';
    $size = match ($aspectRatio) {
        '3:2', '16:9', '4:3' => '1536x1024',
        '9:16', '2:3' => '1024x1536',
        default => '1024x1024',
    };
    $hint = $aspectRatio !== null ? $prompt . ' Compose l\'image au format ' . $aspectRatio . '.' : $prompt;
    $tmp = tempnam(sys_get_temp_dir(), 'ai-img-');
    if ($tmp === false) return null;
    file_put_contents($tmp, $src);
    @chmod($tmp, 0600);
    $fields = ['model' => $model, 'prompt' => $hint, 'n' => '1', 'size' => $size, 'image' => new CURLFile($tmp, @getimagesize($srcAbsPath)['mime'] ?? 'image/jpeg', 'source.jpg')];
    try {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init(ai_provider_endpoint($provider, 'images/edits'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
                CURLOPT_POSTFIELDS => $fields, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status >= 200 && $status < 300 && is_string($raw)) {
                $data = json_decode($raw, true) ?: [];
                $b64 = (string) ($data['data'][0]['b64_json'] ?? '');
                if ($b64 !== '') {
                    if (function_exists('ai_usage_record') && function_exists('ai_pricing')) ai_usage_record('image', ai_pricing()['image'], $model);
                    return base64_decode($b64);
                }
                $url = (string) ($data['data'][0]['url'] ?? '');
                if ($url !== '') {
                    [$code, $bytes] = ai_provider_http('GET', $url, [], null, 60);
                    if ($code === 200 && $bytes !== '') {
                        if (function_exists('ai_usage_record') && function_exists('ai_pricing')) ai_usage_record('image', ai_pricing()['image'], $model);
                        return $bytes;
                    }
                }
                error_log('ai_provider_image: réponse sans image (HTTP ' . $status . ')');
            } else {
                error_log('ai_provider_image attempt ' . $attempt . ': HTTP ' . $status . ', ' . substr((string) $raw, 0, 400));
            }
            if ($attempt < 2) sleep(3);
        }
    } finally {
        @unlink($tmp);
    }
    return null;
}

// ── Vidéo : fournisseurs compatibles SiliconFlow ──────────────────────────────────────────────────────────────────────────

/** Clé de modèle utilisée dans la galerie pour un fournisseur vidéo ajouté : « sf:<identifiant> ». */
function ai_provider_video_key(array $provider): string
{
    return 'sf:' . (string) $provider['id'];
}

/** Fournisseur vidéo d'après la clé de modèle de la galerie, ou null si c'est un connecteur fourni. */
function ai_provider_video_by_key(string $modelKey): ?array
{
    if (!str_starts_with($modelKey, 'sf:')) return null;
    $id = substr($modelKey, 3);
    foreach (ai_provider_rows('video') as $row) {
        if ($row['id'] === $id && $row['driver'] === 'siliconflow') return $row;
    }
    return null;
}

/** Fournisseur vidéo compatible SiliconFlow, fourni ou ajouté, d'après son identifiant (null pour un autre connecteur). */
function ai_provider_video_siliconflow(?string $id = null): ?array
{
    foreach (ai_provider_rows('video') as $row) {
        if ($row['driver'] !== 'siliconflow') continue;
        if ($id === null || $row['id'] === $id) return $row;
    }
    return null;
}

/** Choix de vidéo de la galerie : clé de modèle => [libellé, disponible, note, fournisseur]. */
function ai_provider_video_choices(): array
{
    $out = [];
    foreach (ai_provider_rows('video') as $row) {
        if (!$row['enabled']) continue;
        if ($row['driver'] === 'veo') {
            foreach (VEO_MODELS as $key => $m) {
                $out[$key] = ['label' => 'Google Veo — ' . $m['label'], 'available' => GEMINI_API_KEY !== '', 'note' => '', 'provider' => $row];
            }
        } elseif ($row['driver'] === 'siliconflow') {
            $available = ai_provider_has_key($row);
            $key = $row['builtin'] && $row['id'] === 'siliconflow' ? SF_MODEL_KEY : ai_provider_video_key($row);
            $out[$key] = ['label' => $row['label'], 'available' => $available, 'note' => $available ? '' : ' · clé à renseigner dans les réglages', 'provider' => $row];
        } elseif ($row['driver'] === 'modal') {
            $available = function_exists('modal_video_available') && modal_video_available();
            $out[MODAL_MODEL_KEY] = ['label' => $row['label'], 'available' => $available, 'note' => $available ? '' : ' · service à configurer dans les réglages', 'provider' => $row];
        }
    }
    return $out;
}

/** Clé de modèle vidéo proposée par défaut d'après le fournisseur actif de la catégorie. */
function ai_provider_video_default_key(): string
{
    $active = ai_provider_active('video');
    if (!$active) return '';
    if ($active['driver'] === 'siliconflow') return $active['builtin'] && $active['id'] === 'siliconflow' ? SF_MODEL_KEY : ai_provider_video_key($active);
    if ($active['driver'] === 'modal') return MODAL_MODEL_KEY;
    return 'fast';
}
