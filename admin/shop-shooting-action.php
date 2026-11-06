<?php
// Endpoint du shooting du lieu (admin/shop-shooting.php).
//   review  → mesures locales (GD) + critique IA facultative d'une photo du lieu
//   caption → légende suggérée par l'IA
//   script  → texte de vidéo suggéré par l'IA (présentation ou témoignage)
//   video_begin / video_chunk / video_finish → envoi d'une vidéo par morceaux
//   video_delete → suppression d'une vidéo (administrateurs)
//   enhance → retouche IA de la lumière et de la netteté, décor inchangé
//   publish → enregistrement de la photo, médiathèque, puis publication sur les cibles choisies
//             (un photographe ne publie pas : sa photo part en validation)
//   finish  → fin de séance : les administrateurs sont prévenus des photos à valider
//   pending / decide → file de validation, réservée aux administrateurs
//   state   → ce qui est déjà en place sur le site
// Les photos publiées restent modifiables dans les écrans existants (admin/hero.php,
// admin/slideshow.php, admin/banners.php, Réglages → Contenu des pages).
// Réponses JSON : {ok: true, ...} ou {ok: false, error: "..."}.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shop-shooting.php';

header('Content-Type: application/json; charset=utf-8');

function ss_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    ss_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ss_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
@set_time_limit(120);

$root = realpath(__DIR__ . '/..');
$action = (string) ($_POST['action'] ?? '');
// Un photographe peut tout préparer, sauf mettre en ligne : ses envois passent
// par la file de validation, et les écrans de décision restent aux administrateurs.
$livePublish = shop_shooting_can_publish_live();
$adminOnly = static function (): void {
    if (admin_role() !== 'admin') {
        ss_reply(['ok' => false, 'error' => 'Réservé aux administrateurs.'], 403);
    }
};

/** Envoi présenté au navigateur (file de validation et « mes envois »). */
function ss_request_out(array $row): array
{
    $targets = json_decode((string) $row['targets'], true);
    $targets = is_array($targets) ? $targets : [];
    $labels = [];
    foreach ($targets as $target) {
        $info = shop_shooting_target((string) $target);
        if ($info !== null) $labels[] = $info['label'];
    }
    $place = shop_shooting_place((string) $row['place']);
    return [
        'id' => (int) $row['id'],
        'path' => (string) $row['path'],
        'url' => '/' . $row['path'],
        'place' => $place['title'],
        'shot' => (string) $row['shot_label'],
        'caption' => (string) $row['caption'],
        'targets' => $labels,
        'author' => (string) $row['author_name'],
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * Photo à traiter : soit un fichier envoyé, soit le chemin d'une photo déjà
 * stockée sous uploads/ par la retouche IA. Le chemin est borné par motif pour
 * qu'aucun fichier hors uploads/ ne soit lu depuis le formulaire.
 *
 * @return array{abs:string,stored:string}
 */
function ss_source(string $root): array
{
    $path = trim((string) ($_POST['path'] ?? ''));
    if ($path !== '') {
        if (!preg_match('#^uploads/shop-[A-Za-z0-9._-]{1,120}\.jpe?g$#i', $path)) {
            ss_reply(['ok' => false, 'error' => 'Chemin de photo non reconnu.'], 400);
        }
        $abs = $root . '/' . $path;
        if (!is_file($abs) || !@getimagesize($abs)) {
            ss_reply(['ok' => false, 'error' => 'Photo introuvable sur le serveur.'], 404);
        }
        return ['abs' => $abs, 'stored' => $path];
    }

    $file = $_FILES['photo'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || !@getimagesize($file['tmp_name'])) {
        ss_reply(['ok' => false, 'error' => 'Photo non reçue ou illisible.'], 400);
    }
    if ($file['size'] > 12 * 1048576) {
        ss_reply(['ok' => false, 'error' => 'Photo trop lourde (12 Mo au plus).'], 413);
    }
    return ['abs' => $file['tmp_name'], 'stored' => ''];
}

/** Lieu et vue déclarés par le formulaire, bornés aux programmes connus. */
function ss_shot(): array
{
    $place = shop_shooting_place((string) ($_POST['place'] ?? ''));
    $key = (string) ($_POST['shot'] ?? '');
    foreach ($place['shots'] as $shot) {
        if ($shot['key'] === $key) return [$place, $shot];
    }
    return [$place, $place['shots'][0]];
}

/** Dossier des vidéos en cours d'envoi (hors racine web, jamais servi). */
function ss_video_dir(string $root): string
{
    $dir = $root . '/data/video-uploads';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** Métadonnées d'un envoi en cours, bornées par un identifiant hexadécimal. */
function ss_video_meta(string $dir, string $id): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
        ss_reply(['ok' => false, 'error' => 'Envoi vidéo inconnu.'], 400);
    }
    $meta = json_decode((string) @file_get_contents($dir . '/' . $id . '.json'), true);
    if (!is_array($meta) || !is_file($dir . '/' . $id . '.part')) {
        ss_reply(['ok' => false, 'error' => 'Envoi vidéo introuvable : reprenez depuis le début.'], 404);
    }
    $meta['id'] = $id;
    return $meta;
}

/** Conteneur réellement reçu (mp4 / mov / webm), indépendamment du nom d'origine. */
function ss_video_ext(string $file): ?string
{
    $fh = @fopen($file, 'rb');
    if (!$fh) return null;
    $head = (string) fread($fh, 12);
    fclose($fh);
    if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
        // Les vidéos d'iPhone sont des QuickTime .mov : même structure, lecture
        // assurée dans Safari. On garde l'extension réelle du conteneur.
        return substr($head, 8, 4) === 'qt  ' ? 'mov' : 'mp4';
    }
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return 'webm';
    return null;
}

/** Vidéo présentée au navigateur. */
function ss_video_out(array $row, string $siteName): array
{
    $program = shop_shooting_video_program((string) $row['program']) ?? shop_shooting_video_program('presentation');
    return [
        'id' => (int) $row['id'],
        'program' => (string) $row['program'],
        'program_title' => (string) $program['title'],
        'path' => (string) $row['path'],
        'url' => '/' . $row['path'],
        'caption' => (string) $row['caption'],
        'author' => (string) $row['author_name'],
        'duration' => (int) $row['duration'],
        'created_at' => (string) $row['created_at'],
    ];
}

switch ($action) {

    case 'state':
        ss_reply(['ok' => true, 'state' => shop_shooting_state()]);

    case 'review': {
        $src = ss_source($root);
        [$place, $shot] = ss_shot();

        $metrics = shooting_photo_metrics($src['abs']);
        $review = shop_shooting_review($metrics);
        $aiError = '';
        $ai = false;

        // La critique IA est à la demande : le contrôle local suffit le plus souvent
        // et ne coûte aucun crédit.
        if (!empty($_POST['ai'])) {
            if (!GEMINI_API_KEY) {
                $aiError = 'Clé Gemini non configurée : le contrôle local reste affiché.';
            } elseif ($gate = ai_gate()) {
                $aiError = $gate;
            } else {
                $small = downscale_for_ai($src['abs'], 1024, 80);
                $text = gemini_describe_image($small ?: $src['abs'], shop_shooting_review_prompt($place['title'], $shot['label']));
                if ($small && $small !== $src['abs']) @unlink($small);
                $parsed = $text !== null ? shop_shooting_parse_review($text) : null;
                if ($parsed === null) {
                    $aiError = "L'IA n'a pas répondu dans le format attendu : le contrôle local est affiché.";
                } else {
                    $ai = true;
                    $review = shop_shooting_review($metrics, $parsed);
                }
            }
        }

        ss_reply([
            'ok' => true,
            'ai' => $ai,
            'ai_error' => $aiError,
            'metrics' => $metrics,
            'review' => $review,
            'issueLabels' => shop_shooting_issue_labels(),
        ]);
    }

    case 'caption': {
        $src = ss_source($root);
        [$place, $shot] = ss_shot();
        if (!GEMINI_API_KEY) ss_reply(['ok' => false, 'error' => 'Clé Gemini non configurée : écrivez la légende à la main.'], 503);
        if ($gate = ai_gate()) ss_reply(['ok' => false, 'quota' => true, 'error' => $gate]);

        $small = downscale_for_ai($src['abs'], 1024, 80);
        $text = gemini_describe_image($small ?: $src['abs'], shop_shooting_caption_prompt($place['title'], $shot['label']));
        if ($small && $small !== $src['abs']) @unlink($small);

        $caption = shop_shooting_clean_caption($text);
        if ($caption === '') ss_reply(['ok' => false, 'error' => "L'IA n'a pas proposé de légende exploitable."]);
        ss_reply(['ok' => true, 'caption' => $caption]);
    }

    case 'script': {
        $programKey = (string) ($_POST['program'] ?? '');
        $program = shop_shooting_video_program($programKey);
        if ($program === null) ss_reply(['ok' => false, 'error' => 'Programme vidéo inconnu.'], 400);
        $place = shop_shooting_place((string) ($_POST['place'] ?? ''));
        $content = get_content();
        $siteName = trim((string) ($content['site_name'] ?? '')) ?: (string) tenant('name');
        $provider = ai_provider_active('text');
        if (!GEMINI_API_KEY && !($provider && in_array($provider['driver'], ['openai', 'anthropic'], true))) {
            ss_reply(['ok' => false, 'error' => 'IA non configurée : écrivez le texte à la main.'], 503);
        }
        if ($gate = ai_gate()) ss_reply(['ok' => false, 'quota' => true, 'error' => $gate]);
        $text = gemini_describe_image('', shop_shooting_video_prompt($programKey, $siteName, $place['title']), 1);
        $clean = shop_shooting_clean_script($text);
        if ($clean === '') ss_reply(['ok' => false, 'error' => "L'IA n'a pas proposé de texte exploitable. Écrivez le vôtre."]);
        ss_reply(['ok' => true, 'program' => $programKey, 'text' => $clean]);
    }

    // Envoi d'une vidéo par morceaux : les hébergements limitent souvent chaque
    // requête à 2 Mo, une vidéo de téléphone en fait dix à trente fois plus.
    case 'video_begin': {
        $programKey = (string) ($_POST['program'] ?? '');
        $program = shop_shooting_video_program($programKey);
        if ($program === null) ss_reply(['ok' => false, 'error' => 'Programme vidéo inconnu.'], 400);
        $size = (int) ($_POST['size'] ?? 0);
        if ($size <= 0 || $size > 120 * 1048576) {
            ss_reply(['ok' => false, 'error' => 'Vidéo vide ou trop lourde (120 Mo au plus).'], 413);
        }
        $consent = !empty($_POST['consent']);
        if ($program['consent'] && !$consent) {
            ss_reply(['ok' => false, 'error' => "Cochez l'accord du client avant d'envoyer le témoignage."], 400);
        }

        $dir = ss_video_dir($root);
        foreach (glob($dir . '/*.part') ?: [] as $old) {
            if (preg_match('/[a-f0-9]{24}\.part$/', $old) && @filemtime($old) < time() - 86400) {
                @unlink($old);
                @unlink(substr($old, 0, -5) . '.json');
            }
        }

        $session = admin_session() ?? [];
        $id = bin2hex(random_bytes(12));
        $meta = [
            'program' => $programKey,
            'caption' => mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 120),
            'script' => shop_shooting_clean_script($_POST['script'] ?? ''),
            'author_name' => (string) ($session['name'] ?? ''),
            'account_id' => $session['id'] ?? null,
            'consent' => $consent ? 1 : 0,
            'duration' => max(0, min(180, (int) ($_POST['duration'] ?? 0))),
            'size' => $size,
            'created' => time(),
        ];
        if (!@file_put_contents($dir . '/' . $id . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE))
            || !@touch($dir . '/' . $id . '.part')) {
            ss_reply(['ok' => false, 'error' => "L'envoi n'a pas pu démarrer. Réessayez."], 500);
        }
        ss_reply(['ok' => true, 'id' => $id, 'chunk' => 1048576]);
    }

    case 'video_chunk': {
        $dir = ss_video_dir($root);
        $meta = ss_video_meta($dir, (string) ($_POST['id'] ?? ''));
        $chunk = $_FILES['chunk'] ?? null;
        if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
            ss_reply(['ok' => false, 'error' => 'Morceau de vidéo non reçu.'], 400);
        }
        $part = $dir . '/' . $meta['id'] . '.part';
        clearstatcache(true, $part);
        $received = (int) filesize($part);
        if ($received + (int) $chunk['size'] > (int) $meta['size'] + 1024) {
            ss_reply(['ok' => false, 'error' => 'Envoi incohérent : reprenez la vidéo.'], 409);
        }
        $out = @fopen($part, 'ab');
        $in = @fopen($chunk['tmp_name'], 'rb');
        if (!$out || !$in) {
            if ($out) fclose($out);
            if ($in) fclose($in);
            ss_reply(['ok' => false, 'error' => 'Morceau de vidéo non enregistré.'], 500);
        }
        flock($out, LOCK_EX);
        $copied = stream_copy_to_stream($in, $out);
        fflush($out);
        flock($out, LOCK_UN);
        fclose($in);
        fclose($out);
        if ($copied === false) ss_reply(['ok' => false, 'error' => 'Morceau de vidéo non enregistré.'], 500);
        clearstatcache(true, $part);
        ss_reply(['ok' => true, 'received' => (int) filesize($part), 'size' => (int) $meta['size']]);
    }

    case 'video_finish': {
        $dir = ss_video_dir($root);
        $meta = ss_video_meta($dir, (string) ($_POST['id'] ?? ''));
        $part = $dir . '/' . $meta['id'] . '.part';
        clearstatcache(true, $part);
        $actual = (int) filesize($part);
        if ($actual <= 0 || $actual !== (int) $meta['size']) {
            ss_reply(['ok' => false, 'error' => 'Envoi incomplet (' . $actual . ' octets sur ' . (int) $meta['size'] . ') : recommencez.'], 409);
        }
        $ext = ss_video_ext($part);
        if ($ext === null) {
            ss_reply(['ok' => false, 'error' => "Ce fichier n'est pas une vidéo lisible (MP4, MOV ou WebM attendu)."], 415);
        }

        $videosDir = $root . '/uploads/videos';
        if (!is_dir($videosDir)) @mkdir($videosDir, 0755, true);
        $name = 'shop-video-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@rename($part, $videosDir . '/' . $name)) {
            if (!@copy($part, $videosDir . '/' . $name)) {
                ss_reply(['ok' => false, 'error' => "La vidéo n'a pas pu être enregistrée."], 500);
            }
            @unlink($part);
        }
        @unlink($dir . '/' . $meta['id'] . '.json');

        $path = 'uploads/videos/' . $name;
        $content = get_content();
        $siteName = trim((string) ($content['site_name'] ?? '')) ?: (string) tenant('name');
        $program = shop_shooting_video_program((string) $meta['program']) ?? shop_shooting_video_program('presentation');
        $rowId = 0;
        try {
            $rowId = shop_shooting_video_create([
                'program' => $meta['program'],
                'path' => $path,
                'caption' => $meta['caption'],
                'script' => $meta['script'],
                'author_name' => $meta['author_name'],
                'account_id' => $meta['account_id'],
                'consent' => !empty($meta['consent']),
                'duration' => $meta['duration'],
            ]);
            $label = $meta['caption'] !== '' ? $meta['caption'] : $program['title'] . ' — ' . $siteName;
            media_library_register($path, $label, 'Shooting vidéo', 'lieu,video,' . $meta['program'], 'video');
        } catch (Throwable $e) {
            if ($rowId > 0) {
                try { shop_shooting_video_delete($rowId); } catch (Throwable $ignored) {}
            }
            @unlink($videosDir . '/' . $name);
            ss_reply(['ok' => false, 'error' => "La vidéo n'a pas pu être classée."], 500);
        }
        $row = shop_shooting_video_find($rowId);
        ss_reply(['ok' => true, 'video' => ss_video_out($row ?: [], $siteName)]);
    }

    case 'video_delete': {
        $adminOnly();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || !shop_shooting_video_delete($id)) {
            ss_reply(['ok' => false, 'error' => 'Vidéo introuvable.'], 404);
        }
        ss_reply(['ok' => true]);
    }

    case 'enhance': {
        $src = ss_source($root);
        if (!GEMINI_API_KEY) ss_reply(['ok' => false, 'error' => 'Clé Gemini non configurée.'], 503);
        if ($gate = ai_gate()) ss_reply(['ok' => false, 'quota' => true, 'error' => $gate]);

        $small = downscale_for_ai($src['abs'], 1600, 90);
        $bytes = gemini_generate_image($small ?: $src['abs'], shop_shooting_enhance_prompt(), 1);
        if ($small && $small !== $src['abs']) @unlink($small);
        if (!$bytes) ss_reply(['ok' => false, 'error' => "L'amélioration IA a échoué. Reprenez la photo, ou publiez l'originale."]);

        $img = @imagecreatefromstring($bytes);
        if (!$img) ss_reply(['ok' => false, 'error' => "L'image retouchée n'a pas pu être lue."]);
        $uploadsDir = $root . '/uploads';
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
        $name = 'shop-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        if (!@imagejpeg($img, $uploadsDir . '/' . $name, 90)) {
            imagedestroy($img);
            ss_reply(['ok' => false, 'error' => "L'image retouchée n'a pas pu être enregistrée."]);
        }
        imagedestroy($img);
        ss_reply(['ok' => true, 'path' => 'uploads/' . $name, 'url' => '/uploads/' . $name]);
    }

    case 'publish': {
        $src = ss_source($root);
        [$place, $shot] = ss_shot();
        $caption = mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 120);

        // Cibles bornées au module : rien d'arbitraire n'arrive du formulaire.
        $targets = [];
        foreach ((array) ($_POST['targets'] ?? []) as $target) {
            $target = (string) $target;
            if (shop_shooting_target($target) !== null && !in_array($target, $targets, true)) $targets[] = $target;
        }

        if ($src['stored'] !== '') {
            $path = $src['stored'];
        } else {
            $baseName = 'shop-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($shot['key']));
            $path = store_uploaded_photo('photo', $baseName, 1600, 86);
            if (!$path) ss_reply(['ok' => false, 'error' => "La photo n'a pas pu être enregistrée."]);
        }

        $options = [
            'overlay' => max(0, min(100, (int) ($_POST['overlay'] ?? 60))),
            'duration' => max(4, min(30, (int) ($_POST['duration'] ?? 8))),
        ];

        // Photographe : rien ne part sur le site, la photo attend la validation
        // d'un administrateur avec les supports et la légende préparés ici.
        if (!$livePublish) {
            $session = admin_session() ?? [];
            $requestId = shop_shooting_request_create([
                'account_id' => $session['id'] ?? null,
                'author_name' => $session['name'] ?? '',
                'place' => $place['key'],
                'shot' => $shot['key'],
                'shot_label' => $shot['label'],
                'path' => $path,
                'caption' => $caption,
                'targets' => $targets,
                'overlay' => $options['overlay'],
                'duration' => $options['duration'],
                'session_key' => preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['session_key'] ?? '')),
            ]);
            ss_reply([
                'ok' => true,
                'pending' => true,
                'request_id' => $requestId,
                'path' => $path,
                'url' => '/' . $path,
                'caption' => $caption,
                'shot' => $shot['key'],
                'place' => $place['key'],
                'state' => shop_shooting_state(),
            ]);
        }

        // La photo rejoint toujours la médiathèque : elle reste réutilisable ailleurs.
        $tags = 'lieu,' . $place['key'] . ',' . $shot['key'];
        foreach ($targets as $target) $tags .= ',' . str_replace(':', '-', $target);
        media_library_register($path, $caption !== '' ? $caption : $place['title'] . ' — ' . $shot['label'], shop_shooting_media_source(), $tags);

        $applied = [];
        foreach ($targets as $target) {
            $message = shop_shooting_publish_to($target, $path, $caption, $options);
            if ($message !== null) $applied[] = ['target' => $target, 'message' => $message];
        }

        ss_reply([
            'ok' => true,
            'path' => $path,
            'url' => '/' . $path,
            'caption' => $caption,
            'shot' => $shot['key'],
            'place' => $place['key'],
            'applied' => $applied,
            'media_only' => $applied === [],
            'state' => shop_shooting_state(),
        ]);
    }

    case 'finish': {
        // Fin de séance d'un photographe : un seul message aux administrateurs,
        // limité aux envois de cette séance encore non annoncés.
        if ($livePublish) ss_reply(['ok' => true, 'notified' => 0, 'pending' => 0]);
        $session = admin_session() ?? [];
        $key = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['session_key'] ?? ''));
        $rows = [];
        if (($session['id'] ?? null) !== null && $key !== '') {
            $stmt = db()->prepare("SELECT * FROM shooting_requests WHERE account_id = ? AND session_key = ? AND status = 'pending' AND notified_at IS NULL ORDER BY id ASC");
            $stmt->execute([(int) $session['id'], $key]);
            $rows = $stmt->fetchAll();
        }
        ss_reply([
            'ok' => true,
            'notified' => $rows ? shop_shooting_notify_admins($rows) : 0,
            'pending' => count($rows),
            'emails' => count(shop_shooting_admin_emails()),
        ]);
    }

    case 'mine': {
        $session = admin_session() ?? [];
        $rows = ($session['id'] ?? null) !== null ? shop_shooting_requests_mine((int) $session['id']) : [];
        ss_reply(['ok' => true, 'requests' => array_map('ss_request_out', $rows)]);
    }

    case 'pending': {
        $adminOnly();
        ss_reply([
            'ok' => true,
            'requests' => array_map('ss_request_out', shop_shooting_requests_pending()),
            'count' => shop_shooting_pending_count(),
        ]);
    }

    case 'decide': {
        $adminOnly();
        $id = (int) ($_POST['id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        if (!in_array($decision, ['publish', 'reject'], true)) {
            ss_reply(['ok' => false, 'error' => 'Décision inconnue.'], 400);
        }
        $session = admin_session() ?? [];
        $result = shop_shooting_request_decide($id, $decision, (string) (($session['name'] ?? '') ?: admin_role()));
        if (empty($result['ok'])) ss_reply(['ok' => false, 'error' => $result['error'] ?? 'Décision impossible.']);
        ss_reply([
            'ok' => true,
            'messages' => $result['messages'] ?? [],
            'count' => shop_shooting_pending_count(),
            'state' => shop_shooting_state(),
        ]);
    }

}

ss_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
