<?php
// Endpoint du shooting du lieu (admin/shop-shooting.php).
//   review  → mesures locales (GD) + critique IA facultative d'une photo du lieu
//   caption → légende suggérée par l'IA
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
