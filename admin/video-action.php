<?php
// Vidéos de la galerie d'une pièce, en JSON :
//   upload     → vidéo « zoom / travelling » fabriquée dans le navigateur (gratuite, sans ffmpeg)
//   veo_start  → lance une vidéo IA (Google Veo, ou Wan 2.2 via SiliconFlow) à partir d'une photo
//   veo_poll   → état d'une vidéo IA ; à la fin, la télécharge et l'ajoute à la galerie
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
// Réponse toujours en JSON pur : un avertissement PHP (ou une dépréciation) ne doit pas le casser.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ob_start();
function video_reply(array $data, int $status = 200): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') video_reply(['ok' => false, 'error' => 'Requête invalide.'], 405);
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) video_reply(['ok' => false, 'error' => 'Page expirée : rechargez-la et recommencez.'], 400);

$ref = (string) ($_POST['ref'] ?? '');
$product = get_product($ref);
if (!$product) video_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);
ai_usage_context($ref);
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'upload': {
        $effect = (string) ($_POST['effect'] ?? 'zoom_in');
        $label = video_effects()[$effect] ?? 'Vidéo';
        $path = store_uploaded_video('file');
        if (!$path) video_reply(['ok' => false, 'error' => "La vidéo n'a pas pu être enregistrée (format ou taille non valides)."], 422);
        add_product_photo($ref, $path, $label, false, 'video');
        flash_set('Vidéo générée et ajoutée à la galerie.');
        video_reply(['ok' => true]);
    }

    case 'veo_start': {
        $sourceId = (int) ($_POST['source_photo_id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM product_photos WHERE id = ? AND product_ref = ? AND COALESCE(type, 'photo') = 'photo'");
        $stmt->execute([$sourceId, $ref]);
        $photo = $stmt->fetch();
        $srcAbs = $photo ? __DIR__ . '/../' . $photo['path'] : '';
        if (!$photo || !is_file($srcAbs)) video_reply(['ok' => false, 'error' => 'Photo de départ introuvable.'], 404);

        $modelKey = (string) ($_POST['model'] ?? 'fast');
        $seconds = (int) ($_POST['seconds'] ?? 6);
        $aspect = (string) ($_POST['aspect'] ?? 'auto');
        if ($aspect === 'auto') $aspect = veo_auto_aspect($srcAbs);
        $effect = (string) ($_POST['effect'] ?? 'auto');
        if (!isset(veo_motions()[$effect])) $effect = 'auto';
        $keywords = mb_substr(trim((string) ($_POST['keywords'] ?? '')), 0, SAVED_PROMPT_MAX_LENGTH);

        $wan = $modelKey === SF_MODEL_KEY;
        if ($wan) $seconds = 0; // durée fixée par le service
        $prompt = build_veo_prompt($effect, $keywords);
        $started = $wan ? sf_video_start($srcAbs, $prompt, $aspect) : veo_start($srcAbs, $prompt, $aspect, $modelKey, $seconds);
        if (!$started['ok']) video_reply(['ok' => false, 'error' => $started['error']], 502);

        $label = ($wan ? 'Vidéo IA (Wan) — ' : 'Vidéo IA — ') . (veo_motions()[$effect] ?? 'Vidéo');
        db()->prepare('INSERT INTO veo_jobs (product_ref, operation, model, seconds, aspect, label) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$ref, $started['operation'], $modelKey, $seconds, $aspect, $label]);
        video_reply(['ok' => true, 'job' => (int) db()->lastInsertId(), 'aspect' => $aspect]);
    }

    case 'veo_poll': {
        $stmt = db()->prepare('SELECT * FROM veo_jobs WHERE id = ? AND product_ref = ?');
        $stmt->execute([(int) ($_POST['job'] ?? 0), $ref]);
        $job = $stmt->fetch();
        if (!$job) video_reply(['ok' => false, 'state' => 'failed', 'error' => 'Génération introuvable.']);
        if ($job['status'] === 'done') video_reply(['ok' => true, 'state' => 'done']);
        if ($job['status'] === 'failed') video_reply(['ok' => true, 'state' => 'failed', 'error' => $job['error']]);

        $fail = static function (string $error) use ($job): never {
            db()->prepare("UPDATE veo_jobs SET status = 'failed', error = ? WHERE id = ?")->execute([$error, $job['id']]);
            video_reply(['ok' => true, 'state' => 'failed', 'error' => $error]);
        };
        $expired = time() - strtotime($job['created_at'] . ' UTC') > VEO_JOB_TIMEOUT;
        // « saving » : un autre onglet télécharge déjà la vidéo (ou sa requête s'est interrompue : on abandonne alors au bout du délai).
        if ($job['status'] === 'saving') {
            if ($expired) $fail('Le téléchargement de la vidéo a été interrompu : relancez la génération.');
            video_reply(['ok' => true, 'state' => 'pending']);
        }

        $wan = $job['model'] === SF_MODEL_KEY;
        $poll = $wan ? sf_video_poll($job['operation']) : veo_poll($job['operation']);
        if ($poll['state'] === 'failed') $fail($poll['error']);
        if ($poll['state'] === 'pending') {
            if ($expired) $fail("Google n'a pas terminé la vidéo à temps : relancez la génération (vous n'êtes pas facturé).");
            video_reply(['ok' => true, 'state' => 'pending']);
        }

        // Terminée : un seul requêteur la télécharge.
        $claim = db()->prepare("UPDATE veo_jobs SET status = 'saving' WHERE id = ? AND status = 'pending'");
        $claim->execute([$job['id']]);
        if ($claim->rowCount() === 0) video_reply(['ok' => true, 'state' => 'pending']);
        $path = veo_download($poll['uri'], !$wan);
        if (!$path) $fail('La vidéo est prête mais son téléchargement a échoué : relancez la génération.');
        add_product_photo($ref, $path, $job['label'], true, 'video');
        ai_usage_log_video($job['model'], (int) $job['seconds']);
        db()->prepare("UPDATE veo_jobs SET status = 'done' WHERE id = ?")->execute([$job['id']]);
        flash_set('Vidéo IA générée et ajoutée à la galerie.');
        video_reply(['ok' => true, 'state' => 'done']);
    }
}

video_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
