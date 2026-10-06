<?php

// Vidéo IA, troisième fournisseur : Wan 2.2 (TI2V-5B) exécuté sur Modal (GPU à la seconde, 30 $ de crédit gratuit par mois).
// Il a remplacé LTX-Video, jugé mauvais : sur des photos de vêtements, Wan garde l'objet et son lettrage intacts.
// Le service lui-même est le script modal/ltx_video_app.py, déployé par le commerçant sur son compte Modal ; la boutique
// n'en connaît que l'adresse et le jeton (Administration > Réglages du site). Même principe que Veo et Wan : génération
// asynchrone lancée par un appel HTTP, suivie toutes les 10 secondes par la page, puis vidéo téléchargée dans uploads/.

// Réglages : fichier .secrets/<commerce>/modal-video.json {"url": "...", "token": "..."} (ici et non dans config.php :
// en local, config.local.php remplace tout le bloc de production de config.php).
if (!defined('MODAL_VIDEO_URL')) {
    require_once __DIR__ . '/shared-secrets.php';
    $mvFile = secret_path('modal-video.json'); // la configuration du commerce, sinon celle partagée de test
    $mv = is_file($mvFile) ? (json_decode((string) file_get_contents($mvFile), true) ?: []) : [];
    define('MODAL_VIDEO_URL', rtrim((string) ($mv['url'] ?? ''), '/'));
    define('MODAL_VIDEO_TOKEN', (string) ($mv['token'] ?? ''));
}

/** Clé de modèle utilisée dans le formulaire, la table veo_jobs et les tarifs. */
const MODAL_MODEL_KEY = 'wan5b';
const MODAL_NEGATIVE_PROMPT = 'Bright tones, overexposed, static, blurred details, subtitles, style, works, paintings, images, static, overall gray, worst quality, low quality, JPEG compression residue, ugly, incomplete, extra fingers, poorly drawn hands, poorly drawn faces, deformed, disfigured, misshapen limbs, fused fingers, still picture, messy background, many people in the background, hands, feet';
/** 81 images à 24 i/s ≈ 3,4 s. */
const MODAL_FRAMES = 81;
const MODAL_STEPS = 30;
/** Surface de la vidéo (pixels) : 480 × 832, un bon compromis qualité / temps (~22 s de GPU). */
const MODAL_VIDEO_AREA = 480 * 832;

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
 * Prompt de Wan 2.2 : un mouvement de caméra net mais lisse, et l'objet « stable et inchangé ». Éprouvé sur Modal avec une photo de
 * sweat imprimé : le rapprochement se voit, le lettrage reste net. À ne PAS ajouter : « brise », « tissu qui ondule »… (le modèle
 * fait alors apparaître des mains et des pieds).
 */
function build_wan_prompt(string $effect, string $keywords): string
{
    $motion = match ($effect) {
        'zoom_out' => 'steadily pulls back, revealing a bit more around the product, while staying perfectly smooth',
        'pan_left' => 'glides slowly and smoothly from right to left across the product, with a clear, continuous lateral movement and gentle parallax',
        'pan_right' => 'glides slowly and smoothly from left to right across the product, with a clear, continuous lateral movement and gentle parallax',
        'orbit' => 'slowly arcs around the product, revealing it from a slightly different angle as it moves, with clear smooth motion',
        default => 'steadily pushes in towards the product, getting clearly closer so the print fills more of the frame, while staying perfectly smooth',
    };
    return "The camera $motion. The product stays sharp, stable and unchanged: same shape, colours, print and lettering. "
        . 'Soft natural light, photorealistic, high quality.'
        . owner_direction_prompt($keywords);
}

/**
 * Taille de la vidéo (largeur, hauteur : multiples de 32). « auto » : le format naturel de la photo, sans bande ajoutée ;
 * « 16:9 » / « 9:16 » : ce format (la photo est alors recadrée côté Modal).
 */
function modal_video_size(string $aspect, string $srcAbsPath): ?array
{
    if ($aspect === '16:9') return [832, 480];
    if ($aspect === '9:16') return [480, 832];
    $info = @getimagesize($srcAbsPath);
    if (!$info || !$info[0] || !$info[1]) return null;
    $ratio = $info[1] / $info[0];
    $clamp = static fn (float $v): int => max(256, min(1280, (int) (round($v / 32) * 32)));
    return [$clamp(sqrt(MODAL_VIDEO_AREA / $ratio)), $clamp(sqrt(MODAL_VIDEO_AREA * $ratio))];
}

/** Lance la génération : ['ok' => true, 'operation' => identifiant] ou ['ok' => false, 'error' => message]. */
function modal_video_start(string $srcAbsPath, string $prompt, string $aspect): array
{
    if (!modal_video_available()) return ['ok' => false, 'error' => 'Service Modal non configuré : renseignez son adresse et son jeton dans les réglages du site.'];
    $size = modal_video_size($aspect, $srcAbsPath);
    if (!$size) return ['ok' => false, 'error' => 'Impossible de lire la photo de départ.'];
    // La photo d'origine, sans bandes ajoutées (réduite à 1280 px) : Wan les prendrait pour un vrai décor.
    $small = downscale_for_ai($srcAbsPath, 1280, 92);
    $bytes = (string) @file_get_contents($small ?: $srcAbsPath);
    if ($small) @unlink($small);
    if ($bytes === '') return ['ok' => false, 'error' => 'Impossible de lire la photo de départ.'];

    [$status, $data] = modal_video_request('POST', '/submit', [
        'image' => base64_encode($bytes),
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


// ── Détourage (fond transparent) : BiRefNet via rembg sur Modal ──
// Plus précis que le détourage de fal.ai sur les détails (lettrage fin, franges) ; mais un démarrage à froid peut prendre une
// à plusieurs minutes : le détourage est donc suivi par la page comme une vidéo (table veo_jobs, modèle « cutout »).

/** Clé de modèle d'un détourage dans la table veo_jobs. */
const MODAL_CUTOUT_KEY = 'cutout';

/** Lance un détourage : ['ok' => true, 'operation' => identifiant] ou ['ok' => false, 'error' => message]. */
function modal_cutout_start(string $srcAbsPath): array
{
    if (!modal_video_available()) return ['ok' => false, 'error' => 'Service Modal non configuré : renseignez son adresse et son jeton dans les réglages du site.'];
    $small = downscale_for_ai($srcAbsPath, 1600, 92);
    $bytes = (string) @file_get_contents($small ?: $srcAbsPath);
    if ($small) @unlink($small);
    if ($bytes === '') return ['ok' => false, 'error' => 'Impossible de lire la photo de départ.'];
    [$status, $data] = modal_video_request('POST', '/cutout', ['image' => base64_encode($bytes)]);
    if ($status >= 400 || $status === 0 || empty($data['call_id'])) {
        error_log("modal_cutout_start: HTTP $status " . substr(json_encode($data), 0, 400));
        return ['ok' => false, 'error' => modal_video_error_message($status, $data)];
    }
    return ['ok' => true, 'operation' => (string) $data['call_id']];
}

/** Même suivi que modal_video_poll(), avec le lien du PNG. */
function modal_cutout_poll(string $callId): array
{
    $poll = modal_video_poll($callId);
    if ($poll['state'] === 'done') $poll['uri'] = MODAL_VIDEO_URL . '/png/' . $callId;
    return $poll;
}

/** Télécharge le PNG détouré et l'enregistre dans uploads/ : chemin relatif, ou null. */
function modal_cutout_download(string $uri, string $baseName): ?string
{
    if (!modal_video_url_valid($uri) || !str_starts_with($uri, MODAL_VIDEO_URL . '/')) return null;
    $ch = curl_init($uri);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 50, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MODAL_VIDEO_TOKEN]]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status !== 200 || !is_string($bytes) || !str_starts_with($bytes, "\x89PNG")) {
        error_log("modal_cutout_download: HTTP $status, " . (is_string($bytes) ? strlen($bytes) : 0) . ' octets');
        return null;
    }
    return save_binary_photo($bytes, $baseName, 'png');
}
