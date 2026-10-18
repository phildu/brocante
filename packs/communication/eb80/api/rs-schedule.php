<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Publications RS programmées
 *
 * Routes :
 *   GET    /rs/schedule              → liste
 *   POST   /rs/schedule              → créer
 *   PATCH  /rs/schedule/{id}         → modifier (pause/reprise/next_run)
 *   DELETE /rs/schedule/{id}         → supprimer
 *   POST   /rs/schedule/{id}/run-now → exécuter manuellement
 *   POST   /rs/schedule/run          → exécuter les posts dus (cron OVH)
 */

defined('RS_LIB_ONLY') || define('RS_LIB_ONLY', true);
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/rs-v2.php'; // fonctions de publication partagées

_initScheduledPosts();

$path   = getPath(); // ex: /rs/schedule, /rs/schedule/5, /rs/schedule/run
$method = getMethod();
$input  = getJsonInput();

// Router
if ($path === '/rs/schedule/run' && $method === 'POST') {
    _cronRun();
} elseif (preg_match('#^/rs/schedule/(\d+)/run-now$#', $path, $m) && $method === 'POST') {
    requireAuth(['admin']);
    _runOne((int)$m[1]);
} elseif ($path === '/rs/schedule' && $method === 'GET') {
    requireAuth(['admin']);
    _listPosts();
} elseif ($path === '/rs/schedule' && $method === 'POST') {
    requireAuth(['admin']);
    _createPost($input);
} elseif (preg_match('#^/rs/schedule/(\d+)$#', $path, $m) && $method === 'PATCH') {
    requireAuth(['admin']);
    _updatePost((int)$m[1], $input);
} elseif (preg_match('#^/rs/schedule/(\d+)$#', $path, $m) && $method === 'DELETE') {
    requireAuth(['admin']);
    _deletePost((int)$m[1]);
} else {
    errorResponse('Route schedule non trouvée [' . $path . ' ' . $method . ']', 404);
}

// ── Init table ────────────────────────────────────────────────────────────────
function _initScheduledPosts(): void {
    $db = getDb();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS scheduled_posts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            title           TEXT    DEFAULT '',
            message         TEXT    NOT NULL DEFAULT '',
            messages        TEXT    DEFAULT NULL,
            image_url       TEXT    DEFAULT '',
            video_url       TEXT    DEFAULT '',
            networks        TEXT    NOT NULL DEFAULT '[]',
            content_type    TEXT    DEFAULT 'post',
            next_run        DATETIME NOT NULL,
            frequency       TEXT    DEFAULT NULL,
            frequency_value INTEGER DEFAULT NULL,
            status          TEXT    DEFAULT 'active',
            last_run        DATETIME DEFAULT NULL,
            run_count       INTEGER DEFAULT 0,
            max_runs        INTEGER DEFAULT NULL,
            last_results    TEXT    DEFAULT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Migration : ajouter messages si table existante sans la colonne
        try { $db->exec("ALTER TABLE scheduled_posts ADD COLUMN messages TEXT DEFAULT NULL"); } catch (\Throwable $_) {}
    } catch (\Throwable $e) { /* déjà existante */ }
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function _listPosts(): void {
    $db   = getDb();
    $rows = $db->query("SELECT * FROM scheduled_posts ORDER BY next_run ASC")->fetchAll();
    $rows = array_map(fn($r) => array_merge($r, [
        'networks'     => json_decode($r['networks'], true) ?: [],
        'messages'     => $r['messages'] ? json_decode($r['messages'], true) : null,
        'last_results' => $r['last_results'] ? json_decode($r['last_results'], true) : null,
    ]), $rows);
    jsonResponse(['posts' => $rows]);
}

// ── Créer ─────────────────────────────────────────────────────────────────────
function _createPost(array $input): void {
    $message     = trim($input['message'] ?? '');
    $messages    = $input['messages'] ?? null;   // {"facebook":"…","instagram":"…"}
    $imageUrl    = trim($input['image_url'] ?? '');
    $videoUrl    = trim($input['video_url'] ?? '');
    $networks    = $input['networks'] ?? [];
    $contentType = trim($input['content_type'] ?? 'post');
    $nextRun     = trim($input['next_run'] ?? '');
    $frequency   = ($input['frequency'] ?? '') ?: null;
    $freqValue   = isset($input['frequency_value']) ? (int)$input['frequency_value'] : null;
    $maxRuns     = isset($input['max_runs']) && $input['max_runs'] > 0 ? (int)$input['max_runs'] : null;
    $title       = trim($input['title'] ?? '');
    $messagesJson = ($messages && is_array($messages)) ? json_encode($messages) : null;

    if (empty($message) && !$messagesJson && $contentType === 'post') errorResponse('Message requis', 400);
    if (empty($networks)) errorResponse('Au moins un réseau requis', 400);
    if (empty($nextRun))  errorResponse('Date de publication requise (next_run)', 400);

    $db = getDb();
    $db->prepare("INSERT INTO scheduled_posts
        (title,message,messages,image_url,video_url,networks,content_type,next_run,frequency,frequency_value,max_runs)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$title, $message, $messagesJson, $imageUrl, $videoUrl, json_encode($networks), $contentType,
                  $nextRun, $frequency, $freqValue, $maxRuns]);

    jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId()], 201);
}

// ── Modifier ──────────────────────────────────────────────────────────────────
function _updatePost(int $id, array $input): void {
    $db   = getDb();
    $stmt = $db->prepare("SELECT id FROM scheduled_posts WHERE id=?");
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) errorResponse('Post introuvable', 404);

    $allowed = ['status','next_run','frequency','frequency_value','max_runs','message','image_url','title','networks'];
    $sets = []; $vals = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $input)) continue;
        $sets[] = "$k = ?";
        $v = $input[$k];
        if ($k === 'frequency' && empty($v)) $v = null;
        if ($k === 'networks' && is_array($v)) $v = json_encode($v);
        $vals[] = $v;
    }
    if (empty($sets)) errorResponse('Aucune donnée', 400);
    $vals[] = $id;
    $db->prepare("UPDATE scheduled_posts SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
    jsonResponse(['ok' => true]);
}

// ── Supprimer ─────────────────────────────────────────────────────────────────
function _deletePost(int $id): void {
    getDb()->prepare("DELETE FROM scheduled_posts WHERE id=?")->execute([$id]);
    jsonResponse(['ok' => true]);
}

// ── Exécuter un post maintenant ───────────────────────────────────────────────
function _runOne(int $id): void {
    $db   = getDb();
    $stmt = $db->prepare("SELECT * FROM scheduled_posts WHERE id=?");
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) errorResponse('Post introuvable', 404);
    $res = _executePost($row, $db);
    jsonResponse($res);
}

// ── Endpoint cron — POST /rs/schedule/run ─────────────────────────────────────
function _cronRun(): void {
    $cronToken = getSetting('rs_cron_token');
    $reqToken  = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

    $ok = false;
    if ($cronToken && hash_equals($cronToken, $reqToken)) {
        $ok = true;
    } else {
        // Fallback : auth JWT admin
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader) {
            try { requireAuth(['admin']); $ok = true; } catch (\Throwable $e) { $ok = false; }
        }
    }
    if (!$ok) { http_response_code(401); echo json_encode(['error' => 'Token cron invalide']); exit; }

    $db   = getDb();
    $now  = date('Y-m-d H:i:s');
    $stmt2 = $db->prepare("SELECT * FROM scheduled_posts WHERE status='active' AND next_run <= ?");
    $stmt2->execute([$now]);
    $rows = $stmt2->fetchAll();

    $results = [];
    foreach ($rows as $row) {
        $results[$row['id']] = _executePost($row, $db);
    }
    jsonResponse(['ok' => true, 'executed' => count($results), 'results' => $results, 'checked_at' => $now]);
}

// ── Publier un post et planifier la prochaine occurence ───────────────────────
function _executePost(array $row, $db): array {
    $networks    = json_decode($row['networks'], true) ?: [];
    $contentType = $row['content_type'] ?? 'post';
    $baseMessage = $row['message'];
    $perMessages = $row['messages'] ? json_decode($row['messages'], true) : null;
    $imageUrl    = $row['image_url'];
    $videoUrl    = $row['video_url'];
    $mediaUrl    = $videoUrl ?: $imageUrl;

    $pubResults = [];
    foreach ($networks as $net) {
        $key = strtolower($net);
        $msg = trim($perMessages[$key] ?? $perMessages[$net] ?? $baseMessage);
        switch ($key) {
            case 'facebook':
                $pubResults['facebook'] = match($contentType) {
                    'story' => publishFacebookStory($mediaUrl),
                    'reel'  => publishFacebookReel($msg, $mediaUrl),
                    default => publishFacebook($msg, $imageUrl),
                };
                break;
            case 'instagram':
                $pubResults['instagram'] = match($contentType) {
                    'story' => publishInstagramStory($mediaUrl),
                    'reel'  => publishInstagramReel($msg, $mediaUrl),
                    default => publishInstagram($msg, $imageUrl),
                };
                break;
            case 'tiktok':
                $pubResults['tiktok'] = publishTikTok($msg, $mediaUrl);
                break;
            case 'threads':
                $pubResults['threads'] = publishThreads($msg, $imageUrl, $videoUrl, $contentType);
                break;
            default:
                $pubResults[$key] = ['ok' => false, 'error' => 'Réseau non supporté pour la programmation'];
        }
    }

    $anyOk   = !empty(array_filter($pubResults, fn($r) => $r['ok'] ?? false));
    $now     = date('Y-m-d H:i:s');
    $newCount = (int)$row['run_count'] + 1;
    $maxRuns  = $row['max_runs'] ? (int)$row['max_runs'] : null;
    $freq     = $row['frequency'];
    $freqVal  = (int)($row['frequency_value'] ?: 7);

    // Calculer la prochaine occurrence
    $newNextRun = null;
    if ($freq === 'weekly') {
        $newNextRun = date('Y-m-d H:i:s', strtotime($row['next_run'] . ' +7 days'));
    } elseif ($freq === 'interval') {
        $newNextRun = date('Y-m-d H:i:s', strtotime($row['next_run'] . " +{$freqVal} days"));
    } elseif ($freq === 'monthly') {
        $newNextRun = date('Y-m-d H:i:s', strtotime($row['next_run'] . ' +1 month'));
    }

    $newStatus = 'active';
    if (!$freq || !$newNextRun) {
        $newStatus = 'done';                       // one-shot
    } elseif ($maxRuns && $newCount >= $maxRuns) {
        $newStatus = 'done';                       // limite atteinte
    }

    $resultsJson = json_encode($pubResults);
    if ($newStatus === 'done') {
        $db->prepare("UPDATE scheduled_posts SET last_run=?,run_count=?,status='done',last_results=? WHERE id=?")
           ->execute([$now, $newCount, $resultsJson, $row['id']]);
    } else {
        $db->prepare("UPDATE scheduled_posts SET last_run=?,run_count=?,next_run=?,last_results=? WHERE id=?")
           ->execute([$now, $newCount, $newNextRun, $resultsJson, $row['id']]);
    }

    return [
        'ok'       => $anyOk,
        'results'  => $pubResults,
        'status'   => $newStatus,
        'next_run' => $newNextRun,
    ];
}
