<?php

// Vidéo IA, troisième fournisseur : LTX-Video exécuté sur Modal (GPU à la seconde, 30 $ de crédit gratuit par mois).
// Le service lui-même est le script modal/ltx_video_app.py, déployé par le commerçant sur son compte Modal ; la boutique
// n'en connaît que l'adresse et le jeton (Administration > Réglages du site). Même principe que Veo et Wan : génération
// asynchrone lancée par un appel HTTP, suivie toutes les 10 secondes par la page, puis vidéo téléchargée dans uploads/.

// Réglages : fichier .secrets/<commerce>/modal-video.json {"url": "...", "token": "..."} (ici et non dans config.php :
// en local, config.local.php remplace tout le bloc de production de config.php).
if (!defined('MODAL_VIDEO_URL')) {
    $mvFile = (defined('SECRETS_DIR') ? SECRETS_DIR : __DIR__ . '/../.secrets') . '/modal-video.json';
    $mv = is_file($mvFile) ? (json_decode((string) file_get_contents($mvFile), true) ?: []) : [];
    define('MODAL_VIDEO_URL', rtrim((string) ($mv['url'] ?? ''), '/'));
    define('MODAL_VIDEO_TOKEN', (string) ($mv['token'] ?? ''));
}

/** Clé de modèle utilisée dans le formulaire, la table veo_jobs et les tarifs. */
const MODAL_MODEL_KEY = 'ltx';
const MODAL_NEGATIVE_PROMPT = 'worst quality, inconsistent motion, blurry, jittery, distorted, deformed object, text, watermark, subtitles, people, hands';
/**
 * 65 images à 24 i/s ≈ 2,7 s (le modèle veut 8n + 1 images). Plus long, LTX-Video déforme l'objet vers la fin
 * (essais sur une photo de sweat imprimé : à 97 images le texte se défait, à 65 il reste intact).
 */
const MODAL_FRAMES = 65;
const MODAL_STEPS = 30;

function modal_video_available(): bool
{
    return MODAL_VIDEO_URL !== '' && MODAL_VIDEO_TOKEN !== '';
}

/** Adresse acceptée : un service Modal en https ; en développement local seulement (config.local.php), aussi localhost. */
function modal_video_url_valid(string $url): bool
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $host = strtolower($parts['host']);
    if (($parts['scheme'] ?? '') === 'https' && str_ends_with($host, '.modal.run')) return true;
    return is_file(__DIR__ . '/../config.local.php') && in_array($host, ['localhost', '127.0.0.1'], true);
}

/** Appel au service : [code HTTP, réponse décodée (JSON), corps brut]. $path commence par « / ». */
function modal_video_request(string $method, string $path, ?array $payload = null, int $timeout = 50): array
{
    $ch = curl_init(MODAL_VIDEO_URL . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . MODAL_VIDEO_TOKEN],
    ];
    if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, is_string($body) ? (json_decode($body, true) ?: []) : [], is_string($body) ? $body : ''];
}

function modal_video_error_message(int $status, array $data): string
{
    if ($status === 0) return "Le service Modal ne répond pas (premier démarrage lent ou adresse incorrecte) : réessayez dans une minute.";
    if ($status === 401 || $status === 403) return 'Le service Modal refuse le jeton enregistré : vérifiez-le dans les réglages du site.';
    if ($status === 404) return "Adresse du service Modal introuvable : vérifiez-la dans les réglages du site (y a-t-il eu « modal deploy » ?).";
    if ($status === 429) return 'Service Modal saturé : réessayez dans quelques minutes.';
    $msg = (string) ($data['detail'] ?? $data['error'] ?? '');
    return $msg !== '' ? mb_substr($msg, 0, 240) : "Le service Modal a répondu par une erreur ($status).";
}

/** Test de connexion (réglages) : ['ok' => bool, 'message' => texte]. */
function modal_video_check(): array
{
    if (!modal_video_available()) return ['ok' => false, 'message' => 'Adresse ou jeton manquant.'];
    [$status, $data] = modal_video_request('GET', '/health', null, 40);
    return $status === 200 && !empty($data['ok'])
        ? ['ok' => true, 'message' => 'Connexion réussie : le service Modal répond.']
        : ['ok' => false, 'message' => modal_video_error_message($status, $data)];
}

/**
 * Prompt de LTX-Video : une description continue, avec un mouvement de caméra doux (un mouvement fort ou un prompt
 * vague fait déformer l'objet, voire le remplace par autre chose) et un léger mouvement ambiant (sans lui, la vidéo
 * peut rester figée). Éprouvé sur Modal avec une photo de sweat imprimé.
 */
function build_ltx_prompt(string $effect, string $keywords): string
{
    $motion = match ($effect) {
        'zoom_out' => 'slowly and smoothly pulls back a little, a subtle, gentle pull-out revealing a bit more of the surroundings',
        'pan_left' => 'slowly and smoothly glides sideways from right to left, a subtle, gentle lateral travelling',
        'pan_right' => 'slowly and smoothly glides sideways from left to right, a subtle, gentle lateral travelling',
        'orbit' => 'slowly and smoothly arcs a little around the product, a subtle, gentle orbit',
        default => 'slowly and smoothly moves a little closer to the product, a subtle, gentle push-in',
    };
    return "The camera $motion. The product stays perfectly sharp, stable and unchanged: same shape, colours, print, lettering and details. "
        . 'A faint natural movement in the scene: the light shifts very softly and soft materials move slightly as if in a light breeze. '
        . 'Soft natural light, photorealistic, high quality. If the first frame has plain empty margins, extend the background naturally.'
        . owner_direction_prompt($keywords);
}

/** Format de la vidéo (largeur × hauteur, multiples de 32) d'après le format demandé. */
function modal_video_size(string $aspect): ?array
{
    return ['16:9' => [768, 448], '9:16' => [448, 768]][$aspect] ?? null;
}

/** Lance la génération : ['ok' => true, 'operation' => identifiant] ou ['ok' => false, 'error' => message]. */
function modal_video_start(string $srcAbsPath, string $prompt, string $aspect): array
{
    if (!modal_video_available()) return ['ok' => false, 'error' => 'Service Modal non configuré : renseignez son adresse et son jeton dans les réglages du site.'];
    $size = modal_video_size($aspect);
    if (!$size) return ['ok' => false, 'error' => 'Format de vidéo non valide.'];
    $image = veo_prepare_image($srcAbsPath, $aspect);
    if (!$image) return ['ok' => false, 'error' => 'Impossible de lire la photo de départ.'];

    [$status, $data] = modal_video_request('POST', '/submit', [
        'image' => base64_encode($image[0]),
        'prompt' => $prompt,
        'negative_prompt' => MODAL_NEGATIVE_PROMPT,
        'width' => $size[0],
        'height' => $size[1],
        'num_frames' => MODAL_FRAMES,
        'steps' => MODAL_STEPS,
        'seed' => random_int(1, 2147483646),
    ]);
    if ($status >= 400 || $status === 0 || empty($data['call_id'])) {
        error_log("modal_video_start: HTTP $status " . substr(json_encode($data), 0, 400));
        return ['ok' => false, 'error' => modal_video_error_message($status, $data)];
    }
    return ['ok' => true, 'operation' => (string) $data['call_id']];
}

/** État d'une génération : ['state' => 'pending'] ; ['state' => 'done', 'uri' => lien du MP4] ; ['state' => 'failed', 'error' => message]. */
function modal_video_poll(string $callId): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $callId)) return ['state' => 'failed', 'error' => 'Génération inconnue.'];
    [$status, $data] = modal_video_request('GET', '/status/' . $callId);
    if ($status >= 500 || $status === 0) return ['state' => 'pending']; // incident ou démarrage à froid : on réessaiera
    if ($status >= 400) return ['state' => 'failed', 'error' => modal_video_error_message($status, $data)];
    return match ($data['status'] ?? '') {
        'done' => ['state' => 'done', 'uri' => MODAL_VIDEO_URL . '/video/' . $callId],
        'failed' => ['state' => 'failed', 'error' => 'La génération a échoué sur Modal' . (!empty($data['error']) ? ' : ' . mb_substr((string) $data['error'], 0, 200) : '.')],
        default => ['state' => 'pending'],
    };
}
