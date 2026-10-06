<?php

// Vidéo IA : animation d'une photo par Google Veo (API Gemini, même clé que les images).
// La génération est asynchrone (11 s à 6 min) : on la lance, puis la page interroge le serveur toutes les 10 secondes
// (admin/video-action.php) ; chaque requête reste donc très courte, sous la limite de 60 s de l'hébergement mutualisé.
// Aucun exec() ni ffmpeg : tout passe par des appels HTTP. Les tarifs sont ceux de l'offre payante (pas de quota gratuit).

/** Modèles Veo 3.1 : identifiant de l'API, libellé, clé de tarif ($ par seconde de vidéo, 720p). */
const VEO_MODELS = [
    'lite' => ['id' => 'veo-3.1-lite-generate-preview', 'label' => 'Lite (le moins cher)', 'price' => 'veo_lite'],
    'fast' => ['id' => 'veo-3.1-fast-generate-preview', 'label' => 'Fast (recommandé)', 'price' => 'veo_fast'],
    'standard' => ['id' => 'veo-3.1-generate-preview', 'label' => 'Standard (meilleure qualité)', 'price' => 'veo_std'],
];
const VEO_SECONDS = [4, 6, 8];
const VEO_API = 'https://generativelanguage.googleapis.com/v1beta/';
/** Au-delà, une génération sans réponse est abandonnée (Google annonce 6 minutes au plus). */
const VEO_JOB_TIMEOUT = 900;

function veo_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'veo_jobs'")->fetchColumn();
    if ($exists) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS veo_jobs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      product_ref TEXT NOT NULL,
      operation TEXT NOT NULL,
      model TEXT NOT NULL,
      seconds INTEGER NOT NULL,
      aspect TEXT NOT NULL,
      label TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'pending',
      error TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

/** Coût estimé en dollars d'une vidéo de $seconds secondes. */
function veo_cost_usd(string $modelKey, int $seconds): float
{
    $model = VEO_MODELS[$modelKey] ?? VEO_MODELS['fast'];
    return ai_pricing()[$model['price']] * $seconds;
}

/** Libellés d'estimation « modèle-secondes » → « ≈ 0,55 € », pour l'affichage avant de générer. */
function veo_cost_labels(): array
{
    $out = [];
    foreach (array_keys(VEO_MODELS) as $key) {
        foreach (VEO_SECONDS as $s) $out["$key-$s"] = ai_estimate_label(['usd' => veo_cost_usd($key, $s)]);
    }
    return $out;
}

/** Mouvement de caméra (en anglais, pour Veo) correspondant aux effets proposés pour la vidéo gratuite. */
function veo_motion_prompt(string $effect): string
{
    return match ($effect) {
        'zoom_in' => 'a slow, smooth push-in towards the product',
        'zoom_out' => 'a slow, smooth pull-back revealing the product and its surroundings',
        'pan_left' => 'a slow, smooth lateral travelling from right to left',
        'pan_right' => 'a slow, smooth lateral travelling from left to right',
        'orbit' => 'a slow, smooth orbit around the product showing it from slightly different sides',
        default => 'a gentle, natural camera movement chosen to show the product at its best',
    };
}

/** Mouvements proposés à Veo (en plus de « au choix de l'IA »). */
function veo_motions(): array
{
    return ['auto' => "Au choix de l'IA"] + video_effects() + ['orbit' => 'Tour de l\'objet (léger)'];
}

function build_veo_prompt(string $effect, string $keywords): string
{
    return 'Animate this product photograph into a short, smooth, photorealistic video. Camera: ' . veo_motion_prompt($effect) . '. '
        . 'The product must stay exactly as in the photo: same shape, colours, pattern, texture and details — nothing added, removed or deformed. '
        . 'Steady camera, soft natural lighting, one continuous shot, no cuts, no text, no watermark, no hands. Silent: no music, no speech. '
        . 'If the first frame has plain empty margins, fill them naturally by extending the background or surface — never show bars or borders.'
        . owner_direction_prompt($keywords);
}

function build_veo_prompt_fr(string $effect, string $keywords): string
{
    $motion = match ($effect) {
        'zoom_in' => 'un lent et doux rapprochement vers le produit',
        'zoom_out' => "un lent et doux recul qui révèle le produit et ce qui l'entoure",
        'pan_left' => 'un lent et doux travelling latéral de droite à gauche',
        'pan_right' => 'un lent et doux travelling latéral de gauche à droite',
        'orbit' => "un lent et doux tour de l'objet, montré sous des côtés légèrement différents",
        default => 'un mouvement de caméra doux et naturel, choisi pour mettre le produit en valeur',
    };
    return 'Anime cette photo de produit en une courte vidéo fluide et photoréaliste. Caméra : ' . $motion . '. '
        . "Le produit doit rester exactement comme sur la photo : même forme, mêmes couleurs, même motif, même matière et mêmes détails — rien d'ajouté, de retiré ni de déformé. "
        . 'Caméra stable, lumière naturelle douce, un seul plan continu, sans coupure, sans texte, sans filigrane, sans mains. Silencieux : ni musique ni voix. '
        . "Si la première image a des marges vides unies, remplis-les naturellement en prolongeant le fond ou la surface — n'affiche jamais de bandes ni de bordures."
        . owner_direction_prompt_fr($keywords);
}

/** Appel HTTP à l'API Gemini : [code HTTP, réponse décodée]. */
function veo_request(string $url, ?array $payload = null, int $timeout = 50): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, is_string($body) ? (json_decode($body, true) ?: []) : []];
}

/** Message d'erreur lisible d'une réponse de l'API. */
function veo_error_message(int $status, array $data): string
{
    $msg = (string) ($data['error']['message'] ?? '');
    if ($status === 0) return "Pas de réponse de Google (connexion perdue ou délai dépassé) : réessayez.";
    if ($status === 429) return 'Quota Google atteint pour le moment : réessayez dans quelques minutes.';
    if ($status === 403 || $status === 401) return "Google refuse l'accès à Veo avec cette clé (la génération vidéo demande un compte Gemini avec facturation activée)" . ($msg !== '' ? ' : ' . mb_substr($msg, 0, 160) : '.');
    return $msg !== '' ? mb_substr($msg, 0, 240) : "Google a répondu par une erreur ($status).";
}

/**
 * Image de départ : la photo entière, posée sans recadrage sur une toile au format demandé (16:9 ou 9:16).
 * Retourne [octets JPEG, type MIME] ou null.
 */
function veo_prepare_image(string $srcAbsPath, string $aspect): ?array
{
    $src = @imagecreatefromstring((string) @file_get_contents($srcAbsPath));
    if (!$src) return null;
    $ratio = aspect_ratio_value($aspect);
    $w = imagesx($src);
    $h = imagesy($src);
    $fill = abs(($w / $h) / $ratio - 1) <= 0.08 ? 1.0 : 0.92;
    $transparent = image_has_transparent_edge($src);
    $canvas = pad_image_to_ratio($src, $ratio, $fill, 1280, $transparent);
    if ($transparent) {
        // Un fond transparent n'a pas de sens pour une vidéo : on le remplace par du blanc.
        $flat = imagecreatetruecolor(imagesx($canvas), imagesy($canvas));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $canvas, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas));
        $canvas = $flat;
    }
    ob_start();
    imagejpeg($canvas, null, 90);
    $bytes = (string) ob_get_clean();
    return $bytes !== '' ? [$bytes, 'image/jpeg'] : null;
}

/** Format de la vidéo d'après celui de la photo : portrait → 9:16, sinon 16:9. */
function veo_auto_aspect(string $srcAbsPath): string
{
    $info = @getimagesize($srcAbsPath);
    return ($info && $info[1] > $info[0] * 1.05) ? '9:16' : '16:9';
}

/** Lance la génération. Retourne ['ok' => true, 'operation' => nom] ou ['ok' => false, 'error' => message]. */
function veo_start(string $srcAbsPath, string $prompt, string $aspect, string $modelKey, int $seconds): array
{
    if (!GEMINI_API_KEY) return ['ok' => false, 'error' => 'Clé Gemini absente : renseignez-la dans les réglages du site.'];
    $model = VEO_MODELS[$modelKey] ?? null;
    if (!$model || !in_array($seconds, VEO_SECONDS, true) || !in_array($aspect, ['16:9', '9:16'], true)) {
        return ['ok' => false, 'error' => 'Réglages de vidéo non valides.'];
    }
    $image = veo_prepare_image($srcAbsPath, $aspect);
    if (!$image) return ['ok' => false, 'error' => 'Impossible de lire la photo de départ.'];

    [$status, $data] = veo_request(VEO_API . 'models/' . $model['id'] . ':predictLongRunning', [
        'instances' => [[
            'prompt' => $prompt,
            'image' => ['bytesBase64Encoded' => base64_encode($image[0]), 'mimeType' => $image[1]],
        ]],
        'parameters' => ['aspectRatio' => $aspect, 'durationSeconds' => $seconds, 'resolution' => '720p'],
    ]);
    if ($status >= 400 || $status === 0 || empty($data['name'])) {
        error_log("veo_start: HTTP $status " . substr(json_encode($data), 0, 500));
        return ['ok' => false, 'error' => veo_error_message($status, $data)];
    }
    return ['ok' => true, 'operation' => (string) $data['name']];
}

/**
 * État d'une génération : ['state' => 'pending'] ; ['state' => 'done', 'uri' => lien du fichier] ;
 * ['state' => 'failed', 'error' => message].
 */
function veo_poll(string $operation): array
{
    if (!preg_match('#^[A-Za-z0-9_/.-]+$#', $operation)) return ['state' => 'failed', 'error' => 'Opération inconnue.'];
    [$status, $data] = veo_request(VEO_API . $operation);
    if ($status >= 500 || $status === 0) return ['state' => 'pending']; // incident passager : on réessaiera
    if ($status === 403 || $status === 404) return ['state' => 'failed', 'error' => "Google ne retrouve plus cette génération : relancez-la (vous n'êtes pas facturé)."];
    if ($status >= 400) return ['state' => 'failed', 'error' => veo_error_message($status, $data)];
    if (empty($data['done'])) return ['state' => 'pending'];
    if (!empty($data['error'])) return ['state' => 'failed', 'error' => mb_substr((string) ($data['error']['message'] ?? 'La génération a échoué.'), 0, 240)];
    $resp = $data['response']['generateVideoResponse'] ?? [];
    $uri = (string) ($resp['generatedSamples'][0]['video']['uri'] ?? '');
    if ($uri !== '') return ['state' => 'done', 'uri' => $uri];
    $why = $resp['raiMediaFilteredReasons'][0] ?? '';
    return ['state' => 'failed', 'error' => $why !== '' ? 'Google a refusé cette vidéo : ' . mb_substr((string) $why, 0, 200) : "Google n'a renvoyé aucune vidéo."];
}

/** Télécharge la vidéo terminée dans uploads/ ; retourne le chemin relatif ou null. */
function veo_download(string $uri): ?string
{
    if (!str_starts_with($uri, 'https://generativelanguage.googleapis.com/')) return null;
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'video-ia-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.mp4';
    $abs = $dir . '/' . $filename;
    $fh = fopen($abs, 'wb');
    if (!$fh) return null;
    $ch = curl_init($uri);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . GEMINI_API_KEY],
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fh);
    // Un MP4 commence par « ftyp » à l'octet 4.
    $head = (string) @file_get_contents($abs, false, null, 4, 4);
    if ($ok === false || $status !== 200 || $head !== 'ftyp') {
        error_log("veo_download: HTTP $status, " . (is_file($abs) ? filesize($abs) : 0) . ' octets');
        @unlink($abs);
        return null;
    }
    return 'uploads/' . $filename;
}

/** Enregistre une vidéo envoyée par le navigateur (zoom / travelling gratuit) dans uploads/ : chemin relatif ou null. */
function store_uploaded_video(string $fieldName): ?string
{
    $file = $_FILES[$fieldName] ?? null;
    if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['size'] > 40 * 1048576 || $file['size'] < 1000) return null;
    $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 12);
    $ext = str_contains(substr($head, 4, 4), 'ftyp') ? 'mp4' : (str_starts_with($head, "\x1A\x45\xDF\xA3") ? 'webm' : null);
    if (!$ext) return null;
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'video-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . '/' . $filename) ? 'uploads/' . $filename : null;
}
