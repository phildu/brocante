<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Vidéos promo (file d'attente de rendu)
 *
 * L'admin compose la vidéo (page video-promo.html) et crée une demande ;
 * un agent de rendu (script Node sur une machine de l'équipe, dépôt remotion-videos)
 * interroge le site, génère le MP4 et le renvoie ici. Le site n'appelle jamais l'agent.
 *
 * Admin et collaborateurs « editor » (le jeton d'agent reste réservé à l'admin) :
 *   GET    /api/video-jobs                 → dernières demandes
 *   POST   /api/video-jobs                 → { title, composition, props } crée une demande
 *   POST   /api/video-jobs/{id}/retry      → remet une demande en attente
 *   DELETE /api/video-jobs/{id}            → supprime la demande (pas le MP4)
 *   POST   /api/video-jobs/worker-token    → génère le jeton de l'agent (affiché une seule fois)
 *   GET    /api/video-jobs/worker          → état de l'agent (dernier passage)
 *   GET    /api/video-jobs/ratios          → ratios personnalisés enregistrés [{ nom, largeur, hauteur }]
 *   POST   /api/video-jobs/ratios          → { ratios: [...] } remplace la liste (20 au plus)
 *   GET    /api/video-jobs/reprises        → [{ id, output_url }] des vidéos terminées (reprise depuis la médiathèque)
 *   GET    /api/video-jobs/{id}            → demande complète, avec l'état de l'éditeur (editeur) pour reprise
 * Agent (en-tête X-Worker-Token) :
 *   GET    /api/video-jobs/next            → prend la plus ancienne demande en attente (204 si aucune)
 *   POST   /api/video-jobs/{id}/result     → MP4 par morceaux (chunk, chunk_index, total_chunks)
 *                                            ou JSON { error } en cas d'échec
 */

require_once __DIR__ . '/../compat.php';

// Sur OVH, Apache peut servir /api/video-jobs/ai directement par ce fichier (MultiViews),
// sans passer par entry.php qui aurait chargé video-jobs-ai.php : on le relaie ici.
if (rtrim(getPath(), '/') === '/video-jobs/ai') { require __DIR__ . '/video-jobs-ai.php'; exit; }

const VJ_COMPOSITIONS = ['electroboy80-promo-concert', 'electroboy80-promo-concert-carre', 'electroboy80-promo-concert-libre'];
// Une demande prise par l'agent depuis plus longtemps est considérée abandonnée.
const VJ_DELAI_ABANDON_MIN = 30;

_initVideoJobs();

$path   = rtrim(getPath(), '/');
$method = getMethod();

if ($path === '/video-jobs' && $method === 'GET') {
    requireAuth(['admin', 'editor']);
    vjList();
} elseif ($path === '/video-jobs' && $method === 'POST') {
    $user = requireAuth(['admin', 'editor']);
    vjCreate($user);
} elseif ($path === '/video-jobs/worker-token' && $method === 'POST') {
    requireAuth(['admin']);
    vjNewWorkerToken();
} elseif ($path === '/video-jobs/worker' && $method === 'GET') {
    requireAuth(['admin', 'editor']);
    jsonResponse(['last_seen' => getSetting('video_worker_last_seen', null),
                  'version' => (int)getSetting('video_worker_version', '0'),
                  'configured' => getSetting('video_worker_token_hash') !== '']);
} elseif ($path === '/video-jobs/ratios' && $method === 'GET') {
    requireAuth(['admin', 'editor']);
    jsonResponse(json_decode(getSetting('video_ratios_perso', '[]'), true) ?: []);
} elseif ($path === '/video-jobs/ratios' && $method === 'POST') {
    requireAuth(['admin', 'editor']);
    vjSaveRatios();
} elseif ($path === '/video-jobs/reprises' && $method === 'GET') {
    requireAuth(['admin', 'editor']);
    $rows = dbFetchAll("SELECT id, output_url FROM video_jobs WHERE status = 'done' AND output_url IS NOT NULL ORDER BY id DESC");
    jsonResponse(array_map(fn($r) => ['id' => (int)$r['id'], 'output_url' => $r['output_url']], $rows));
} elseif (preg_match('#^/video-jobs/(\d+)$#', $path, $m) && $method === 'GET') {
    requireAuth(['admin', 'editor']);
    $job = vjGet((int)$m[1]);
    if (!$job) errorResponse('Vidéo introuvable', 404);
    jsonResponse($job);
} elseif ($path === '/video-jobs/next' && $method === 'GET') {
    vjRequireWorker();
    vjNext();
} elseif (preg_match('#^/video-jobs/(\d+)/result$#', $path, $m) && $method === 'POST') {
    vjRequireWorker();
    vjResult((int)$m[1]);
} elseif (preg_match('#^/video-jobs/(\d+)/retry$#', $path, $m) && $method === 'POST') {
    requireAuth(['admin', 'editor']);
    dbExec("UPDATE video_jobs SET status = 'pending', error = NULL, claimed_at = NULL WHERE id = ?", [(int)$m[1]]);
    jsonResponse(vjGet((int)$m[1]));
} elseif (preg_match('#^/video-jobs/(\d+)$#', $path, $m) && $method === 'DELETE') {
    requireAuth(['admin', 'editor']);
    dbExec("DELETE FROM video_jobs WHERE id = ?", [(int)$m[1]]);
    jsonResponse(['ok' => true]);
} else {
    errorResponse('Route non trouvée [' . $method . ' ' . $path . ']', 404);
}

// ── Table ─────────────────────────────────────────────────────────────────────
function _initVideoJobs(): void {
    $db = getDb();
    if (DB_DRIVER === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS video_jobs (
            id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title       VARCHAR(255) DEFAULT '',
            composition VARCHAR(100) NOT NULL,
            props       MEDIUMTEXT NOT NULL,
            status      VARCHAR(20) NOT NULL DEFAULT 'pending',
            output_url  VARCHAR(500) DEFAULT NULL,
            error       TEXT DEFAULT NULL,
            created_by  INT DEFAULT NULL,
            created_at  DATETIME NOT NULL,
            claimed_at  DATETIME DEFAULT NULL,
            done_at     DATETIME DEFAULT NULL,
            editeur     MEDIUMTEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS video_jobs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT DEFAULT '',
            composition TEXT NOT NULL,
            props       TEXT NOT NULL,
            status      TEXT NOT NULL DEFAULT 'pending',
            output_url  TEXT DEFAULT NULL,
            error       TEXT DEFAULT NULL,
            created_by  INTEGER DEFAULT NULL,
            created_at  TEXT NOT NULL,
            claimed_at  TEXT DEFAULT NULL,
            done_at     TEXT DEFAULT NULL,
            editeur     TEXT DEFAULT NULL
        )");
    }
    // Colonne ajoutée après coup : état de l'éditeur Vidéo IA, pour reprendre une vidéo.
    try {
        $db->query("SELECT editeur FROM video_jobs LIMIT 1");
    } catch (\Throwable $e) {
        $db->exec("ALTER TABLE video_jobs ADD COLUMN editeur " . (DB_DRIVER === 'mysql' ? 'MEDIUMTEXT' : 'TEXT') . " DEFAULT NULL");
    }
}

function vjNow(): string { return date('Y-m-d H:i:s'); }

function vjFormat(array $row): array {
    $row['id'] = (int)$row['id'];
    $row['props'] = json_decode($row['props'], true);
    $row['editeur'] = isset($row['editeur']) && $row['editeur'] !== null ? json_decode($row['editeur'], true) : null;
    return $row;
}

function vjGet(int $id): ?array {
    $row = dbFetch("SELECT * FROM video_jobs WHERE id = ?", [$id]);
    return $row ? vjFormat($row) : null;
}

// ── Admin ─────────────────────────────────────────────────────────────────────
function vjList(): void {
    $rows = dbFetchAll("SELECT * FROM video_jobs ORDER BY id DESC LIMIT 50");
    // Liste allégée : l'état de l'éditeur se lit avec GET /video-jobs/{id}.
    jsonResponse(array_map(function ($r) {
        $j = vjFormat($r);
        $j['a_editeur'] = $j['editeur'] !== null;
        unset($j['editeur']);
        return $j;
    }, $rows));
}

function vjCreate(array $user): void {
    $in = getJsonInput();
    $composition = $in['composition'] ?? '';
    $props = $in['props'] ?? null;
    if (!in_array($composition, VJ_COMPOSITIONS, true)) errorResponse('Composition inconnue', 400);
    if (!is_array($props)) errorResponse('props manquantes', 400);
    $scenes = $props['scenes'] ?? null;
    if (is_array($scenes) && $scenes) {
        if (count($scenes) > 24) errorResponse('Trop de scènes (24 au plus)', 400);
        $total = 0;
        foreach ($scenes as $sc) {
            if (!is_array($sc) || !in_array($sc['type'] ?? '', ['titre', 'photo', 'mots', 'compte', 'concert', 'fin'], true)) {
                errorResponse('Scène invalide dans le scénario', 400);
            }
            $total += (float)($sc['duree'] ?? 0);
        }
        if ($total < 3 || $total > 90) errorResponse('Durée du scénario hors limites (3 à 90 s)', 400);
        $props['photos'] = $props['photos'] ?? [];
    } elseif (empty($props['photos']) || !is_array($props['photos'])) {
        errorResponse('Choisir au moins une photo ou un scénario', 400);
    }
    // Au rendu, Remotion complète les clés absentes avec les valeurs par défaut de la composition
    // (dont une charte de lieu) : « pas de charte » doit donc être explicite.
    if ($composition === 'electroboy80-promo-concert-libre') {
        if (!vjRatio($props['ratio'] ?? null)) errorResponse('Ratio invalide (de 1:4 à 4:1)', 400);
        $props['ratio'] = vjRatio($props['ratio']);
    }
    if (!array_key_exists('charteLieu', $props)) $props['charteLieu'] = null;
    if (!array_key_exists('musique', $props)) $props['musique'] = null;
    $json = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > 100000) errorResponse('props trop volumineuses', 400);

    // État complet de l'éditeur (consigne, légende, réglages…) pour reprendre la vidéo plus tard.
    $editeur = null;
    if (is_array($in['editeur'] ?? null)) {
        $editeur = json_encode($in['editeur'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($editeur) > 200000) $editeur = null;
    }

    $db = getDb();
    $db->prepare("INSERT INTO video_jobs (title, composition, props, status, created_by, created_at, editeur)
                  VALUES (?, ?, ?, 'pending', ?, ?, ?)")
       ->execute([mb_substr((string)($in['title'] ?? ''), 0, 255), $composition, $json, $user['id'] ?? null, vjNow(), $editeur]);
    jsonResponse(vjGet((int)$db->lastInsertId()), 201);
}

/** Ratio { largeur, hauteur } valide (nombres positifs, de 1:4 à 4:1), ou null. */
function vjRatio($r): ?array {
    if (!is_array($r)) return null;
    $l = (float)($r['largeur'] ?? 0);
    $h = (float)($r['hauteur'] ?? 0);
    if ($l <= 0 || $h <= 0 || $l > 1000 || $h > 1000) return null;
    $q = $l / $h;
    if ($q < 0.25 || $q > 4) return null;
    return ['largeur' => $l, 'hauteur' => $h];
}

/** Enregistre la liste des ratios personnalisés (réglage partagé par l'équipe). */
function vjSaveRatios(): void {
    $in = getJsonInput();
    $liste = [];
    foreach (array_slice((array)($in['ratios'] ?? []), 0, 20) as $r) {
        $ok = vjRatio($r);
        if (!$ok) continue;
        $ok['nom'] = mb_substr(trim((string)($r['nom'] ?? '')), 0, 40);
        $liste[] = $ok;
    }
    $json = json_encode($liste, JSON_UNESCAPED_UNICODE);
    if (dbFetchColumn("SELECT COUNT(*) FROM settings WHERE `key` = 'video_ratios_perso'")) {
        dbExec("UPDATE settings SET value = ? WHERE `key` = 'video_ratios_perso'", [$json]);
    } else {
        dbExec("INSERT INTO settings (`key`, value, label, section, type) VALUES ('video_ratios_perso', ?, ?, 'video', 'hidden')",
               [$json, 'Ratios vidéo personnalisés']);
    }
    jsonResponse($liste);
}

function vjNewWorkerToken(): void {
    $token = randToken(48);
    $db = getDb();
    $hash = hash('sha256', $token);
    $existe = dbFetchColumn("SELECT COUNT(*) FROM settings WHERE `key` = 'video_worker_token_hash'");
    if ($existe) {
        dbExec("UPDATE settings SET value = ? WHERE `key` = 'video_worker_token_hash'", [$hash]);
    } else {
        $db->prepare("INSERT INTO settings (`key`, value, label, section, type) VALUES (?, ?, ?, 'video', 'hidden')")
           ->execute(['video_worker_token_hash', $hash, "Empreinte du jeton de l'agent de rendu vidéo"]);
    }
    jsonResponse(['token' => $token]);
}

// ── Agent de rendu ────────────────────────────────────────────────────────────
function vjRequireWorker(): void {
    $token = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? '';
    $hash = getSetting('video_worker_token_hash');
    if (!$token || !$hash || !hash_equals($hash, hash('sha256', $token))) {
        errorResponse('Jeton agent invalide', 401);
    }
    $now = vjNow();
    if (dbFetchColumn("SELECT COUNT(*) FROM settings WHERE `key` = 'video_worker_last_seen'")) {
        dbExec("UPDATE settings SET value = ? WHERE `key` = 'video_worker_last_seen'", [$now]);
    } else {
        dbExec("INSERT INTO settings (`key`, value, label, section, type) VALUES ('video_worker_last_seen', ?, ?, 'video', 'hidden')",
               [$now, "Dernier passage de l'agent de rendu vidéo"]);
    }
    // Version des fonctions de rendu de l'agent (absente : agent antérieur aux animations).
    $version = (string)(int)($_SERVER['HTTP_X_WORKER_VERSION'] ?? 0);
    if (dbFetchColumn("SELECT COUNT(*) FROM settings WHERE `key` = 'video_worker_version'")) {
        dbExec("UPDATE settings SET value = ? WHERE `key` = 'video_worker_version'", [$version]);
    } else {
        dbExec("INSERT INTO settings (`key`, value, label, section, type) VALUES ('video_worker_version', ?, ?, 'video', 'hidden')",
               [$version, "Version de rendu de l'agent vidéo"]);
    }
}

function vjNext(): void {
    // Les demandes prises puis abandonnées (agent coupé en plein rendu) repassent en attente.
    $limite = date('Y-m-d H:i:s', time() - VJ_DELAI_ABANDON_MIN * 60);
    dbExec("UPDATE video_jobs SET status = 'pending', claimed_at = NULL WHERE status = 'rendering' AND claimed_at < ?", [$limite]);

    $row = dbFetch("SELECT * FROM video_jobs WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
    if (!$row) {
        http_response_code(204);
        exit;
    }
    // Réservation atomique : si un autre agent l'a prise entre-temps, on ne renvoie rien.
    $stmt = getDb()->prepare("UPDATE video_jobs SET status = 'rendering', claimed_at = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([vjNow(), $row['id']]);
    if ($stmt->rowCount() === 0) {
        http_response_code(204);
        exit;
    }
    $job = vjGet((int)$row['id']);
    jsonResponse(['id' => $job['id'], 'composition' => $job['composition'], 'props' => $job['props']]);
}

function vjResult(int $id): void {
    $job = vjGet($id);
    if (!$job) errorResponse('Demande introuvable', 404);

    // Échec signalé par l'agent
    if (!isset($_FILES['chunk'])) {
        $in = getJsonInput();
        $err = mb_substr((string)($in['error'] ?? 'Erreur inconnue'), 0, 500);
        dbExec("UPDATE video_jobs SET status = 'error', error = ?, done_at = ? WHERE id = ?", [$err, vjNow(), $id]);
        jsonResponse(['ok' => true]);
    }

    $index = (int)($_POST['chunk_index'] ?? 0);
    $total = (int)($_POST['total_chunks'] ?? 1);
    if ($total < 1 || $total > 400 || $index < 0 || $index >= $total) errorResponse('Découpage invalide', 400);
    if ($_FILES['chunk']['error'] !== UPLOAD_ERR_OK) errorResponse('Erreur envoi : ' . $_FILES['chunk']['error'], 400);

    $tmpDir = EB80_TMP_DIR . 'video-job-' . $id . '/'; // hors de uploads/ : jamais servi
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) errorResponse('Dossier temporaire impossible', 500);
    $part = $tmpDir . 'chunk_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $part)) errorResponse('Erreur déplacement du morceau', 500);

    $recus = count(glob($tmpDir . 'chunk_*'));
    if ($recus < $total) jsonResponse(['done' => false, 'received' => $recus, 'total' => $total]);

    // Réassemblage dans la médiathèque
    $base = slugifyFilename('promo-' . ($job['title'] ?: 'video-' . $id));
    $filename = $base . '-' . $id . '.mp4'; // jamais d'écrasement d'un fichier de la médiathèque
    $final = UPLOAD_DIR . $filename;
    $out = fopen($final, 'wb');
    if (!$out) errorResponse('Impossible de créer le fichier', 500);
    for ($i = 0; $i < $total; $i++) {
        $p = $tmpDir . 'chunk_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        if (!file_exists($p)) {
            fclose($out);
            @unlink($final);
            errorResponse("Morceau $i manquant", 500);
        }
        $in = fopen($p, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        unlink($p);
    }
    fclose($out);
    @rmdir($tmpDir);
    // Fichier rendu : un vrai MP4 (ou MOV) d'une taille raisonnable, sinon refusé et supprimé.
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($final);
    if (!in_array($mime, ['video/mp4', 'video/quicktime'], true) || filesize($final) > EB80_VIDEO_MAX_OCTETS) {
        @unlink($final);
        dbExec("UPDATE video_jobs SET status = 'error', error = ?, done_at = ? WHERE id = ?", ['Fichier rendu refusé (MP4 attendu, 500 Mo au plus).', vjNow(), $id]);
        errorResponse('Fichier rendu refusé : MP4 attendu, 500 Mo au plus.', 400);
    }

    $url = UPLOAD_URL . $filename;
    dbExec("UPDATE video_jobs SET status = 'done', output_url = ?, error = NULL, done_at = ? WHERE id = ?", [$url, vjNow(), $id]);
    jsonResponse(['done' => true, 'output_url' => $url]);
}
