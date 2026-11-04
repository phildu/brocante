<?php

// Relais OmniRoute : passerelle locale (endpoint compatible OpenAI) qui prend le relais quand Gemini refuse un appel
// (crédits épuisés 402, clé absente, saturation 429) et bascule sur un autre fournisseur. Réglage dans
// data/saas/omniroute.json, désactivé par défaut : une boutique sans passerelle joignable se comporte comme avant.
// Variables d'environnement prioritaires : OMNIROUTE_URL, OMNIROUTE_KEY, OMNIROUTE_MODEL.

function omniroute_settings(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = ['enabled' => false, 'base_url' => 'http://127.0.0.1:20128/v1', 'model' => 'gemini/gemini-2.5-flash', 'image_model' => '', 'key' => ''];
    $file = dirname(__DIR__) . '/data/saas/omniroute.json';
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    $settings = array_merge($defaults, is_array($data) ? $data : []);
    foreach (['base_url' => 'OMNIROUTE_URL', 'model' => 'OMNIROUTE_MODEL', 'key' => 'OMNIROUTE_KEY'] as $field => $env) {
        $value = trim((string) getenv($env));
        if ($value !== '') $settings[$field] = $value;
    }
    $settings['base_url'] = rtrim(trim((string) $settings['base_url']), '/');
    $settings['model'] = trim((string) $settings['model']);
    return $cache = $settings;
}

function omniroute_available(): bool
{
    $settings = omniroute_settings();
    return (bool) $settings['enabled'] && $settings['base_url'] !== '' && $settings['model'] !== '';
}

/**
 * Texte (avec photos éventuelles) par la passerelle. Retourne le texte ou null si la passerelle est désactivée,
 * muette ou en erreur — l'appelant garde alors son propre message d'échec.
 *
 * @param string[] $imagePaths Chemins absolus des photos jointes (JPEG, PNG…).
 */
function omniroute_text(string $prompt, array $imagePaths = [], int $timeout = 60): ?string
{
    if (!omniroute_available()) return null;
    $settings = omniroute_settings();

    $content = [];
    foreach ($imagePaths as $path) {
        if ($path === '' || !is_file($path)) continue;
        $bytes = @file_get_contents($path);
        if ($bytes === false) continue;
        $mime = @getimagesize($path)['mime'] ?? 'image/jpeg';
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]];
    }
    if ($content) array_unshift($content, ['type' => 'text', 'text' => $prompt]);

    $headers = ['Content-Type: application/json'];
    if (trim((string) $settings['key']) !== '') $headers[] = 'Authorization: Bearer ' . trim((string) $settings['key']);

    $ch = curl_init($settings['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $settings['model'],
            'messages' => [['role' => 'user', 'content' => $content ?: $prompt]],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch);

    if ($raw === false || $status >= 400) {
        error_log('omniroute_text: HTTP ' . $status . ' ' . substr((string) $raw, 0, 300));
        return null;
    }

    $message = json_decode((string) $raw, true)['choices'][0]['message']['content'] ?? null;
    if (is_array($message)) {
        $parts = [];
        foreach ($message as $part) if (is_array($part) && isset($part['text'])) $parts[] = (string) $part['text'];
        $message = implode("\n", $parts);
    }
    $text = is_string($message) ? trim($message) : '';
    return $text !== '' ? $text : null;
}

/** Une route image est-elle réglée sur la passerelle ? (modèle « image_model » renseigné) */
function omniroute_image_ready(): bool
{
    $settings = omniroute_settings();
    return omniroute_available() && trim((string) ($settings['image_model'] ?? '')) !== '';
}

/**
 * Retouche d'image (photo source + consigne) par la passerelle, en chat multimodal : l'image revient dans la
 * réponse (image_url base64). Null tant qu'aucun modèle d'image n'est réglé dans « image_model » ou en cas d'échec.
 */
function omniroute_image(string $srcAbsPath, string $prompt, ?string $aspectRatio = null, int $timeout = 120): ?string
{
    $settings = omniroute_settings();
    $model = trim((string) ($settings['image_model'] ?? ''));
    if (!omniroute_available() || $model === '') return null;
    $src = @file_get_contents($srcAbsPath);
    if ($src === false) return null;

    $hint = $aspectRatio !== null ? $prompt . " Compose l'image au format " . $aspectRatio . '.' : $prompt;
    $headers = ['Content-Type: application/json'];
    if (trim((string) $settings['key']) !== '') $headers[] = 'Authorization: Bearer ' . trim((string) $settings['key']);

    $ch = curl_init($settings['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'modalities' => ['image', 'text'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $hint],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . (@getimagesize($srcAbsPath)['mime'] ?? 'image/jpeg') . ';base64,' . base64_encode($src)]],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch);

    if ($raw === false || $status >= 400) {
        error_log('omniroute_image: HTTP ' . $status . ' ' . substr((string) $raw, 0, 300));
        return null;
    }

    $message = json_decode((string) $raw, true)['choices'][0]['message'] ?? [];
    $candidates = array_merge((array) ($message['images'] ?? []), (array) ($message['content'] ?? []));
    foreach ($candidates as $part) {
        $url = (string) (is_array($part) ? ($part['image_url']['url'] ?? $part['url'] ?? '') : '');
        if (preg_match('#^data:[^;]+;base64,(.+)$#s', $url, $m)) return base64_decode($m[1]);
        if (preg_match('#^https?://#', $url)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
            $bytes = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80000) curl_close($ch);
            if ($bytes !== false && $code === 200 && $bytes !== '') return $bytes;
        }
    }
    error_log('omniroute_image: réponse sans image (HTTP ' . $status . ')');
    return null;
}
