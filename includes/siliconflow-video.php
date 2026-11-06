<?php

// Vidéo IA, second fournisseur : Wan 2.2 (image vers vidéo) hébergé par SiliconFlow. Même principe que Veo (includes/veo.php) :
// génération asynchrone lancée par un appel HTTP, suivie toutes les 10 secondes par la page, puis téléchargée dans uploads/.
// Tarif : un prix par vidéo (la durée est fixée par le service). Clé : réglages du site → « Vidéo IA (SiliconFlow) ».
// L'API vidéo n'est PAS au format OpenAI : elle a ses propres points d'entrée (/video/submit et /video/status).

// Clé lue dans le dossier des secrets du commerce, comme les autres (ici et non dans config.php : en local,
// config.local.php remplace tout le bloc de production de config.php).
if (!defined('SILICONFLOW_API_KEY')) {
    require_once __DIR__ . '/shared-secrets.php';
    $sfKeyFile = secret_path('siliconflow.key'); // la clé du commerce, sinon la clé partagée de test
    define('SILICONFLOW_API_KEY', is_file($sfKeyFile) ? trim((string) file_get_contents($sfKeyFile)) : '');
}

const SF_API = 'https://api.siliconflow.com/v1/';
const SF_VIDEO_MODEL = 'Wan-AI/Wan2.2-I2V-A14B';
/** Clé de modèle utilisée dans le formulaire, la table veo_jobs et les tarifs. */
const SF_MODEL_KEY = 'wan22';
const SF_NEGATIVE_PROMPT = 'text, watermark, subtitles, blurry, low quality, deformed object, extra objects, hands, people, scene cuts, flicker';

/** Clé, adresse et modèle : ceux du fournisseur vidéo ajouté (portail), sinon ceux de SiliconFlow. */
function sf_video_settings(?array $provider = null): array
{
    if ($provider !== null) {
        return [
            'key' => ai_provider_key_value($provider),
            'base' => rtrim((string) ($provider['base_url'] ?: SF_API), '/') . '/',
            'model' => trim((string) ($provider['model'] ?? '')) ?: SF_VIDEO_MODEL,
            'label' => trim((string) ($provider['label'] ?? '')) ?: 'SiliconFlow',
        ];
    }
    return ['key' => SILICONFLOW_API_KEY, 'base' => SF_API, 'model' => SF_VIDEO_MODEL, 'label' => 'SiliconFlow'];
}

function sf_available(?array $provider = null): bool
{
    return $provider !== null ? ai_provider_key_value($provider) !== '' : SILICONFLOW_API_KEY !== '';
}

/** Appel à l'API compatible SiliconFlow : [code HTTP, réponse décodée]. */
function sf_request(string $path, array $payload, int $timeout = 50, ?array $provider = null): array
{
    $settings = sf_video_settings($provider);
    $ch = curl_init($settings['base'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['key']],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, is_string($body) ? (json_decode($body, true) ?: []) : []];
}

function sf_error_message(int $status, array $data, string $label = 'SiliconFlow'): string
{
    $msg = (string) ($data['message'] ?? $data['error']['message'] ?? '');
    if ($status === 0) return "Pas de réponse de $label (connexion perdue ou délai dépassé) : réessayez.";
    if ($status === 401) return "$label refuse la clé enregistrée : vérifiez-la dans les réglages du site.";
    if ($status === 429) return "Trop de demandes chez $label : réessayez dans quelques minutes.";
    if ($status === 503 || $status === 504) return "$label est surchargé : réessayez dans quelques minutes.";
    if ($status === 402 || stripos($msg, 'balance') !== false) return "Solde $label insuffisant : rechargez le compte ou utilisez un autre fournisseur.";
    return $msg !== '' ? mb_substr($msg, 0, 240) : "$label a répondu par une erreur ($status).";
}

/** Lance la génération : ['ok' => true, 'operation' => requestId] ou ['ok' => false, 'error' => message]. */
function sf_video_start(string $srcAbsPath, string $prompt, string $aspect, ?array $provider = null): array
{
    if ($g = ai_gate()) return ['ok' => false, 'error' => $g];
    $settings = sf_video_settings($provider);
    if (!sf_available($provider)) return ['ok' => false, 'error' => "Clé $settings[label] absente : renseignez-la dans les réglages du site."];
    $size = ['16:9' => '1280x720', '9:16' => '720x1280'][$aspect] ?? null;
    if (!$size) return ['ok' => false, 'error' => 'Format de vidéo non valide.'];
    $image = veo_prepare_image($srcAbsPath, $aspect);
    if (!$image) return ['ok' => false, 'error' => 'Impossible de lire la photo de départ.'];

    [$status, $data] = sf_request('video/submit', [
        'model' => $settings['model'],
        'prompt' => $prompt,
        'negative_prompt' => SF_NEGATIVE_PROMPT,
        'image_size' => $size,
        'image' => 'data:' . $image[1] . ';base64,' . base64_encode($image[0]),
    ], 50, $provider);
    if ($status >= 400 || $status === 0 || empty($data['requestId'])) {
        error_log("sf_video_start: HTTP $status " . substr(json_encode($data), 0, 500));
        return ['ok' => false, 'error' => sf_error_message($status, $data, $settings['label'])];
    }
    return ['ok' => true, 'operation' => (string) $data['requestId']];
}

/** État d'une génération : ['state' => 'pending'] ; ['state' => 'done', 'uri' => lien] ; ['state' => 'failed', 'error' => message]. */
function sf_video_poll(string $requestId, ?array $provider = null): array
{
    $settings = sf_video_settings($provider);
    [$status, $data] = sf_request('video/status', ['requestId' => $requestId], 50, $provider);
    if ($status >= 500 || $status === 0) return ['state' => 'pending']; // incident passager : on réessaiera
    if ($status === 404) return ['state' => 'failed', 'error' => "$settings[label] ne retrouve plus cette génération : relancez-la."];
    if ($status >= 400) return ['state' => 'failed', 'error' => sf_error_message($status, $data, $settings['label'])];
    $state = (string) ($data['status'] ?? '');
    if ($state === 'Succeed') {
        $url = (string) ($data['results']['videos'][0]['url'] ?? '');
        return $url !== '' ? ['state' => 'done', 'uri' => $url] : ['state' => 'failed', 'error' => "$settings[label] n'a renvoyé aucune vidéo."];
    }
    if ($state === 'Failed') {
        $reason = trim((string) ($data['reason'] ?? ''));
        return ['state' => 'failed', 'error' => $reason !== '' ? "$settings[label] a échoué : " . mb_substr($reason, 0, 200) : "La génération a échoué chez $settings[label]."];
    }
    return ['state' => 'pending']; // InQueue, InProgress
}
