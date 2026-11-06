<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Media API
 * GET    /api/media
 * POST   /api/media/upload
 * DELETE /api/media
 * POST   /api/media/video
 * GET    /api/media/states
 * PATCH  /api/media/states
 */

require_once __DIR__ . '/../compat.php';

$path = getPath();
$method = getMethod();
$input = getJsonInput();

// Routes
if ($path === '/media' && $method === 'GET') {
    handleGetMedia();
} elseif ($path === '/media/upload' && $method === 'POST') {
    handleUpload();
} elseif ($path === '/media' && $method === 'DELETE') {
    handleDeleteMedia();
} elseif ($path === '/media/video' && $method === 'POST') {
    handleSetVideo();
} elseif ($path === '/media/states' && $method === 'GET') {
    handleGetStates();
} elseif ($path === '/media/states' && $method === 'PATCH') {
    handlePatchStates($input);
} elseif ($path === '/media/links' && $method === 'GET') {
    handleGetMediaLinks();
} elseif ($path === '/media/link' && $method === 'POST') {
    handleAddMediaLink($input);
} elseif (preg_match('#^/media/links/(\d+)$#', $path, $m) && $method === 'DELETE') {
    handleDeleteMediaLink((int)$m[1]);
} elseif ($path === '/media/projects' && $method === 'GET') {
    handleListProjects();
} elseif ($path === '/media/state' && $method === 'POST') {
    handleSaveState($input);
} elseif (preg_match('#^/media/state/(.+)$#', $path, $m) && $method === 'GET') {
    handleLoadState(urldecode($m[1]));
} elseif (preg_match('#^/media/state/(.+)$#', $path, $m) && $method === 'DELETE') {
    handleDeleteState(urldecode($m[1]));
} elseif (preg_match('#^/media/duplicate/(.+)$#', $path, $m) && $method === 'POST') {
    handleDuplicateMedia(urldecode($m[1]));
} elseif ($path === '/media/import' && $method === 'POST') {
    handleImportUrl($input);
} elseif ($path === '/media/upload-chunk' && $method === 'POST') {
    handleUploadChunk();
} elseif ($path === '/media/kit' && $method === 'POST') {
    handleGenerateKit($input);
} elseif ($path === '/media/seo-meta' && $method === 'GET') {
    handleGetSeoMeta();
} elseif ($path === '/media/seo-meta' && $method === 'POST') {
    handleSaveSeoMeta($input);
} elseif ($path === '/media/seo-suggest' && $method === 'POST') {
    handleSeoSuggest($input);
} elseif ($path === '/media/credits' && $method === 'GET') {
    handleGetCredits();
} elseif ($path === '/media/gallery' && $method === 'GET') {
    handleGetGallery();
} elseif ($path === '/media/gallery-flags' && $method === 'GET') {
    handleGetGalleryFlags();
} elseif ($path === '/media/gallery-flag' && $method === 'POST') {
    handleSetGalleryFlag($input);
} elseif ($path === '/media/download-video' && $method === 'POST') {
    handleDownloadVideo($input);
} elseif ($path === '/media/merge-av' && $method === 'POST') {
    handleMergeAV($input);
} elseif ($path === '/media/extract-audio' && $method === 'POST') {
    handleExtractAudio($input);
} elseif ($path === '/media/local-file' && $method === 'GET') {
    handleLocalFile();
} elseif ($path === '/media/optimize' && $method === 'POST') {
    handleOptimizeImage($input);
} elseif ($path === '/media/optimize-all' && $method === 'POST') {
    handleOptimizeAll();
} elseif ($path === '/media/used-files' && $method === 'GET') {
    handleGetUsedFiles();
} elseif ($path === '/media/orphan-crops' && $method === 'GET') {
    handleGetOrphanCrops();
} elseif ($path === '/media/orphan-crops' && $method === 'DELETE') {
    handleDeleteOrphanCrops($input);
} elseif ($path === '/media/rename-batch' && $method === 'POST') {
    handleRenameBatch($input);
} else {
    errorResponse('Route non trouvée', 404);
}

/**
 * GET /api/media
 * Liste tous les médias
 */
function _eb80_orig_handleGetMedia() {
    $db = getDb();

    $files  = [];
    $webMap = []; // basename → _web.jpg filename

    if (is_dir(UPLOAD_DIR)) {
        $iterator = new DirectoryIterator(UPLOAD_DIR);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isDot()) continue;
            $fn = $file->getFilename();
            // Collect _web.jpg alternates separately
            if (preg_match('/^(.+)_web\.jpg$/', $fn, $m)) {
                $webMap[$m[1]] = $fn;
                continue;
            }
            $files[$fn] = [
                'url'      => UPLOAD_URL . $fn,
                'url_web'  => null,
                'filename' => $fn,
                'name'     => $fn,
                'size'     => $file->getSize(),
                'mimetype' => mime_content_type($file->getPathname()),
                'date'     => date('Y-m-d H:i:s', $file->getMTime())
            ];
        }
    }

    // Attach url_web where an alternate exists
    foreach ($files as $fn => &$entry) {
        $base = pathinfo($fn, PATHINFO_FILENAME);
        if (isset($webMap[$base])) {
            $entry['url_web'] = UPLOAD_URL . $webMap[$base];
        }
    }
    unset($entry);

    $files = array_values($files);
    usort($files, fn($a, $b) => strcmp($b['date'], $a['date']));

    jsonResponse($files);
}

/**
 * POST /api/media/upload
 * Upload d'un fichier
 */
function handleUpload() {
    $user = requireAuth(['admin']);
    
    // Handle file upload
    $result = uploadFile('file');
    
    if (isset($result['error'])) {
        errorResponse($result['error'], 400);
    }
    
    // Retourne le même format que le backend Node.js
    jsonResponse($result, 201);
}

/**
 * DELETE /api/media
 * Supprime un fichier
 */
function handleDeleteMedia() {
    $user = requireAuth(['admin']);
    
    $filename = $_GET['filename'] ?? '';
    
    if (empty($filename)) {
        errorResponse('Nom de fichier requis', 400);
    }
    
    // Security: prevent directory traversal
    $filename = basename($filename);
    $filepath = UPLOAD_DIR . $filename;
    
    if (!file_exists($filepath)) {
        errorResponse('Fichier non trouvé', 404);
    }
    
    if (!unlink($filepath)) {
        errorResponse('Erreur suppression fichier', 500);
    }
    
    jsonResponse([
        'message' => 'Fichier supprimé'
    ]);
}

/**
 * POST /api/media/video
 * Définit une URL vidéo pour un slot
 */
function handleSetVideo() {
    $user = requireAuth(['admin']);
    $input = getJsonInput();

    $slot = $input['slot'] ?? '';
    $url = $input['url'] ?? '';
    
    if (empty($slot) || empty($url)) {
        errorResponse('Slot et URL requis', 400);
    }
    
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        errorResponse('URL invalide', 400);
    }
    
    $db = getDb();
    
    // Store video URL in settings
    $key = 'video_' . preg_replace('/[^a-zA-Z0-9_]/', '', $slot);
    
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value, label, section, type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$key, $url, "Vidéo $slot", 'videos', 'url']);
    
    jsonResponse([
        'message' => 'Vidéo mise à jour',
        'slot' => $slot,
        'url' => $url
    ]);
}

/**
 * GET /api/media/states
 * Récupère les états des médias (pour l'éditeur)
 */
function handleGetStates() {
    requireAuth(['admin']);
    $filename = $_GET['filename'] ?? '';

    $db = getDb();

    if (!empty($filename)) {
        // Legacy: single file state lookup
        $stmt = $db->prepare("SELECT state_json FROM media_states WHERE filename = ?");
        $stmt->execute([$filename]);
        $result = $stmt->fetch();
        jsonResponse(['state' => $result ? json_decode($result['state_json'], true) : null]);
        return;
    }

    // Return only states with a known editable type (poster, video, diaporama)
    $rows = $db->query(
        "SELECT filename, type, updated_at FROM media_states WHERE filename NOT LIKE '_project_%' AND type IN ('poster','video','diaporama') ORDER BY updated_at DESC"
    )->fetchAll();
    jsonResponse($rows);
}

/**
 * PATCH /api/media/states
 * Sauvegarde l'état d'un média (pour l'éditeur)
 */
function handlePatchStates($input) {
    $user = requireAuth(['admin']);
    
    $filename = $input['filename'] ?? '';
    $state = $input['state'] ?? null;
    
    if (empty($filename)) {
        errorResponse('Nom de fichier requis', 400);
    }
    
    $db = getDb();
    
    $stmt = $db->prepare("INSERT OR REPLACE INTO media_states (filename, state_json, updated_at) VALUES (?, ?, datetime('now'))");
    $stmt->execute([$filename, json_encode($state)]);

    jsonResponse([
        'message' => 'État sauvegardé'
    ]);
}

/**
 * GET /api/media/links
 * Retourne tous les liens vidéo externes (YouTube, Vimeo, direct)
 */
function handleGetMediaLinks() {
    requireAuth(['admin']);
    $db   = getDb();
    $stmt = $db->prepare("SELECT id, url, title, platform, thumb, created_at FROM media_links ORDER BY created_at DESC");
    $stmt->execute();
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * POST /api/media/link
 * Ajoute un lien vidéo externe (YouTube, Vimeo, direct)
 */
function handleAddMediaLink($input) {
    requireAuth(['admin']);

    $url = trim($input['url'] ?? '');
    $title = trim($input['title'] ?? '');

    if (empty($url)) {
        errorResponse('URL requise', 400);
    }

    $platform = 'direct';
    $thumb = null;

    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]{11})/', $url, $yt)) {
        $platform = 'youtube';
        $thumb = "https://img.youtube.com/vi/{$yt[1]}/mqdefault.jpg";
    } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $vimeo)) {
        $platform = 'vimeo';
        $thumb = "https://vumbnail.com/{$vimeo[1]}.jpg";
    }

    $db = getDb();
    $stmt = $db->prepare("INSERT INTO media_links (url, title, platform, thumb) VALUES (?, ?, ?, ?)");
    $stmt->execute([$url, $title ?: $url, $platform, $thumb]);
    $id = $db->lastInsertId();

    jsonResponse([
        'id' => (int)$id,
        'url' => $url,
        'title' => $title ?: $url,
        'platform' => $platform,
        'thumb' => $thumb
    ], 201);
}

/**
 * DELETE /api/media/links/:id
 * Supprime un lien vidéo externe
 */
function handleDeleteMediaLink($id) {
    requireAuth(['admin']);

    $db = getDb();
    $stmt = $db->prepare("DELETE FROM media_links WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Lien supprimé']);
}

/**
 * GET /api/media/projects?type=poster|diap
 * Liste tous les projets éditables sauvegardés
 */
function handleListProjects() {
    requireAuth(['admin']);
    $type = $_GET['type'] ?? '';
    $db   = getDb();

    // Projets nommés (_project_*)
    if ($type) {
        $stmt = $db->prepare("SELECT filename, type, updated_at, state_json FROM media_states WHERE type = ? AND filename LIKE '_project_%' ORDER BY updated_at DESC");
        $stmt->execute([$type]);
    } else {
        $stmt = $db->prepare("SELECT filename, type, updated_at, state_json FROM media_states WHERE filename LIKE '_project_%' ORDER BY updated_at DESC");
        $stmt->execute();
    }
    $rows = $stmt->fetchAll();
    $projects = array_map(function($r) {
        $name  = preg_replace('/^_project_[a-z]+_/', '', $r['filename']);
        $thumb = null; $thumbVideo = null; $format = null;
        if (!empty($r['state_json'])) {
            $s = json_decode($r['state_json'], true);
            $thumb      = $s['_thumb']      ?? null;
            $thumbVideo = $s['_thumbVideo'] ?? null;
            $format     = $s['format']      ?? ($s['poster']['format'] ?? null);
        }
        return ['filename' => $r['filename'], 'name' => $name, 'type' => $r['type'], 'updated_at' => $r['updated_at'], 'thumb' => $thumb, 'thumbVideo' => $thumbVideo, 'format' => $format];
    }, $rows);

    // Visuels éditables (fichiers exportés avec état sauvegardé)
    if ($type) {
        $stmt2 = $db->prepare("SELECT filename, type, updated_at FROM media_states WHERE type = ? AND filename NOT LIKE '_project_%' ORDER BY updated_at DESC");
        $stmt2->execute([$type]);
    } else {
        $stmt2 = $db->prepare("SELECT filename, type, updated_at FROM media_states WHERE filename NOT LIKE '_project_%' ORDER BY updated_at DESC");
        $stmt2->execute();
    }
    $editables = array_map(function($r) {
        return ['filename' => $r['filename'], 'name' => $r['filename'], 'type' => $r['type'], 'updated_at' => $r['updated_at'], 'url' => UPLOAD_URL . $r['filename']];
    }, $stmt2->fetchAll());

    jsonResponse(['projects' => $projects, 'editables' => $editables]);
}

/**
 * POST /api/media/state
 * Sauvegarde l'état éditeur d'un fichier (poster, diaporama…)
 */
function handleSaveState($input) {
    requireAuth(['admin']);
    $filename = $input['filename'] ?? '';
    $type     = $input['type']     ?? 'generic';
    $state    = $input['state']    ?? null;
    if (empty($filename)) errorResponse('filename requis', 400);

    $db = getDb();
    $db->prepare("INSERT OR REPLACE INTO media_states (filename, type, state_json, updated_at) VALUES (?, ?, ?, datetime('now'))")
       ->execute([$filename, $type, json_encode($state)]);
    jsonResponse(['message' => 'État sauvegardé']);
}

/**
 * GET /api/media/state/:filename
 * Charge l'état éditeur d'un fichier
 */
function handleLoadState($filename) {
    requireAuth(['admin']);
    $db = getDb();
    $stmt = $db->prepare("SELECT type, state_json FROM media_states WHERE filename = ?");
    $stmt->execute([$filename]);
    $row = $stmt->fetch();
    if (!$row) errorResponse('État non trouvé', 404);
    jsonResponse(['type' => $row['type'], 'state' => json_decode($row['state_json'], true)]);
}

/**
 * DELETE /api/media/state/:filename
 * Supprime l'état éditeur d'un fichier
 */
function handleDeleteState($filename) {
    requireAuth(['admin']);
    $db = getDb();
    $db->prepare("DELETE FROM media_states WHERE filename = ?")->execute([$filename]);
    jsonResponse(['message' => 'État supprimé']);
}

/**
 * POST /api/media/duplicate/:filename
 * Duplique un fichier dans le dossier uploads
 */
function handleDuplicateMedia($filename) {
    requireAuth(['admin']);
    $safe = basename($filename);
    $src  = UPLOAD_DIR . $safe;
    if (!file_exists($src)) errorResponse('Fichier non trouvé', 404);

    $ext    = pathinfo($safe, PATHINFO_EXTENSION);
    $newName = time() . '-' . rand(100000, 999999) . '.' . $ext;
    $dst    = UPLOAD_DIR . $newName;

    if (!copy($src, $dst)) errorResponse('Erreur duplication', 500);
    jsonResponse(['filename' => $newName, 'url' => UPLOAD_URL . $newName]);
}

/**
 * POST /api/media/import
 * Télécharge un fichier depuis une URL externe
 */
function _eb80_orig_handleImportUrl($input) {
    requireAuth(['admin']);
    $url = trim($input['url'] ?? '');
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        errorResponse('URL invalide', 400);
    }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    // Téléchargement via cURL (fonctionne même si allow_url_fopen est Off,
    // et permet d'envoyer un User-Agent pour débloquer les CDNs Instagram/Meta)
    if (!function_exists('curl_init')) errorResponse('cURL non disponible', 500);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        // User-Agent navigateur réel — indispensable pour les CDNs Instagram/Meta
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
            'Referer: https://www.instagram.com/',
        ],
        CURLOPT_ENCODING       => '',   // gzip auto
    ]);

    $content     = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno       = curl_errno($ch);

    if ($errno || $content === false || $content === '') {
        errorResponse('Impossible de télécharger le fichier (curl errno ' . $errno . ')', 400);
    }
    if ($httpCode >= 400) {
        errorResponse('Le serveur distant a répondu ' . $httpCode, 400);
    }

    $size = strlen($content);
    if ($size > MAX_FILE_SIZE) errorResponse('Fichier trop volumineux', 400);
    if ($size < 100) errorResponse('Fichier trop petit ou vide (' . $size . ' octets)', 400);

    // Détecter l'extension — d'abord depuis le Content-Type HTTP,
    // puis depuis l'URL en fallback
    $ext = '';
    if ($contentType) {
        $mime2ext = [
            'image/jpeg'  => 'jpg',
            'image/png'   => 'png',
            'image/gif'   => 'gif',
            'image/webp'  => 'webp',
            'image/svg+xml' => 'svg',
            'video/mp4'   => 'mp4',
            'video/webm'  => 'webm',
            'video/quicktime' => 'mov',
            'audio/mpeg'  => 'mp3',
            'audio/wav'   => 'wav',
        ];
        $mimeBase = strtolower(explode(';', $contentType)[0]);
        $ext = $mime2ext[$mimeBase] ?? '';
    }
    if (!$ext) {
        $urlPath = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        $urlExt  = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        // Nettoyer (Instagram ajoute parfois des params dans l'extension parsée)
        $urlExt  = preg_replace('/[^a-z0-9]/', '', $urlExt);
        if (in_array($urlExt, ['jpg','jpeg','png','gif','webp','svg','mp4','webm','mov','mp3','wav'])) {
            $ext = $urlExt === 'jpeg' ? 'jpg' : $urlExt;
        }
    }
    if (!$ext) $ext = 'jpg'; // fallback ultime

    $filename = time() . '-' . rand(100000, 999999) . '.' . $ext;
    file_put_contents(UPLOAD_DIR . $filename, $content);

    jsonResponse(['filename' => $filename, 'url' => UPLOAD_URL . $filename, 'size' => $size]);
}

/**
 * POST /api/media/upload-chunk
 * Reçoit un chunk de fichier, le stocke en temp, et réassemble quand tous reçus.
 * FormData: chunk (file), upload_id, chunk_index, total_chunks, original_name
 */
function _eb80_orig_handleUploadChunk() {
    requireAuth(['admin']);

    $uploadId    = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']    ?? '');
    $chunkIndex  = (int)($_POST['chunk_index']  ?? 0);
    $totalChunks = (int)($_POST['total_chunks'] ?? 1);
    $origName    = basename($_POST['original_name'] ?? 'file');
    $ext         = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (empty($uploadId))    errorResponse('upload_id requis', 400);
    if ($totalChunks < 1)    errorResponse('total_chunks invalide', 400);
    if (!isset($_FILES['chunk'])) errorResponse('chunk manquant', 400);
    if ($_FILES['chunk']['error'] !== UPLOAD_ERR_OK)
        errorResponse('Erreur upload chunk: ' . $_FILES['chunk']['error'], 400);

    // Répertoire temporaire propre à cet upload
    // On s'assure que le dossier tmp/ parent existe d'abord
    $tmpBase = UPLOAD_DIR . 'tmp/';
    if (!is_dir($tmpBase)) {
        mkdir($tmpBase, 0755, true);
    }
    $tmpDir = $tmpBase . $uploadId . '/';
    if (!is_dir($tmpDir)) {
        if (!mkdir($tmpDir, 0755, true))
            errorResponse('Impossible de créer le répertoire temporaire (' . $tmpDir . ')', 500);
    }

    // Sauvegarde du chunk (nommé avec padding pour tri correct)
    $chunkFile = $tmpDir . 'chunk_' . str_pad($chunkIndex, 8, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile))
        errorResponse('Erreur déplacement du chunk', 500);

    // Compte les chunks reçus
    $received = count(glob($tmpDir . 'chunk_*'));

    if ($received < $totalChunks) {
        // Pas encore complet
        jsonResponse(['done' => false, 'received' => $received, 'total' => $totalChunks]);
        return;
    }

    // Tous les chunks sont là — réassemblage
    $filename  = time() . '-' . rand(100000, 999999) . '.' . ($ext ?: 'bin');
    $finalPath = UPLOAD_DIR . $filename;

    $out = fopen($finalPath, 'wb');
    if (!$out) errorResponse('Impossible de créer le fichier final', 500);

    for ($i = 0; $i < $totalChunks; $i++) {
        $part = $tmpDir . 'chunk_' . str_pad($i, 8, '0', STR_PAD_LEFT);
        if (!file_exists($part)) {
            fclose($out);
            unlink($finalPath);
            errorResponse("Chunk $i manquant lors du réassemblage", 500);
        }
        $in = fopen($part, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    // Nettoyage du répertoire temporaire de cet upload uniquement
    // On ne supprime PAS tmp/ parent pour éviter un 403 sur le prochain upload
    foreach (glob($tmpDir . 'chunk_*') as $f) unlink($f);
    @rmdir($tmpDir);

    $size = filesize($finalPath);
    jsonResponse([
        'done'     => true,
        'filename' => $filename,
        'url'      => UPLOAD_URL . $filename,
        'size'     => $size,
    ]);
}

/**
 * POST /api/media/kit
 * Génère un ZIP des fichiers sélectionnés pour le kit partenaire
 * Body: { "filenames": ["file1.jpg", "file2.png", ...] }
 */
function handleGenerateKit($input) {
    requireAuth(['admin']);

    $filenames = $input['filenames'] ?? [];
    if (!is_array($filenames) || empty($filenames)) {
        errorResponse('Liste de fichiers requise', 400);
    }

    if (!class_exists('ZipArchive')) {
        errorResponse('Extension ZIP non disponible sur ce serveur', 500);
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'eb80kit_');
    $zip     = new ZipArchive();

    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        errorResponse('Impossible de créer l\'archive ZIP', 500);
    }

    $added = 0;
    foreach ($filenames as $fn) {
        $fn   = basename($fn); // sécurité : pas de traversal
        $path = UPLOAD_DIR . $fn;
        if (file_exists($path) && is_file($path)) {
            $zip->addFile($path, $fn);
            $added++;
        }
    }

    $zip->close();

    if ($added === 0) {
        unlink($tmpFile);
        errorResponse('Aucun fichier valide trouvé', 404);
    }

    $date    = date('Y-m-d');
    $zipName = "kit-graphique-{$date}.zip";
    $size    = filesize($tmpFile);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache, no-store');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

/**
 * GET /api/media/credits — public, no auth
 * Retourne {filename: credit} pour toutes les images ayant un crédit.
 */
function handleGetCredits() {
    $db   = getDb();
    $rows = $db->query(
        "SELECT filename, state_json FROM media_states WHERE filename NOT LIKE '_project_%'"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $state  = json_decode($r['state_json'] ?? '{}', true) ?: [];
        $credit = trim($state['credit'] ?? '');
        if ($credit !== '') $out[$r['filename']] = $credit;
    }
    jsonResponse($out);
}

/**
 * GET /api/media/gallery — public
 * Retourne les images marquées gallery=true avec credit intégré.
 */
function handleGetGallery() {
    $db   = getDb();
    $rows = $db->query(
        "SELECT filename, state_json FROM media_states WHERE filename NOT LIKE '_project_%'"
    )->fetchAll();

    $gallerySet = [];
    $credits    = [];
    foreach ($rows as $r) {
        $state = json_decode($r['state_json'] ?? '{}', true) ?: [];
        if (!empty($state['gallery'])) $gallerySet[$r['filename']] = true;
        $credit = trim($state['credit'] ?? '');
        if ($credit) $credits[$r['filename']] = $credit;
    }

    if (empty($gallerySet)) { jsonResponse([]); return; }

    $webMap = [];
    $items  = [];
    if (is_dir(UPLOAD_DIR)) {
        foreach (new DirectoryIterator(UPLOAD_DIR) as $f) {
            if (!$f->isFile()) continue;
            if (preg_match('/^(.+)_web\.jpg$/', $f->getFilename(), $m))
                $webMap[$m[1]] = UPLOAD_URL . $f->getFilename();
        }
        foreach (new DirectoryIterator(UPLOAD_DIR) as $f) {
            if (!$f->isFile()) continue;
            $fn = $f->getFilename();
            if (preg_match('/^(.+)_web\.jpg$/', $fn)) continue;
            if (!isset($gallerySet[$fn])) continue;
            $mime = mime_content_type($f->getPathname());
            if (!$mime || !str_starts_with($mime, 'image/')) continue;
            $base    = pathinfo($fn, PATHINFO_FILENAME);
            $items[] = [
                'filename' => $fn,
                'url'      => UPLOAD_URL . $fn,
                'url_web'  => $webMap[$base] ?? null,
                'credit'   => $credits[$fn] ?? '',
                'date'     => date('Y-m-d H:i:s', $f->getMTime()),
            ];
        }
    }
    usort($items, fn($a, $b) => strcmp($b['date'], $a['date']));
    jsonResponse($items);
}

/**
 * GET /api/media/gallery-flags — public
 * Retourne {filename: true} pour toutes les images marquées gallery=true.
 */
function handleGetGalleryFlags() {
    $db   = getDb();
    $rows = $db->query(
        "SELECT filename, state_json FROM media_states WHERE filename NOT LIKE '_project_%'"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $state = json_decode($r['state_json'] ?? '{}', true) ?: [];
        if (!empty($state['gallery'])) $out[$r['filename']] = true;
    }
    jsonResponse($out);
}

/**
 * POST /api/media/gallery-flag — auth requise
 * Body: {filename, gallery: bool}
 * Active ou désactive le flag gallery dans state_json.
 */
function handleSetGalleryFlag($input) {
    requireAuth(['admin']);
    $filename = basename(trim($input['filename'] ?? ''));
    if (!$filename) errorResponse('filename requis', 400);
    $inGallery = !empty($input['gallery']);

    $db   = getDb();
    $stmt = $db->prepare("SELECT state_json FROM media_states WHERE filename = ?");
    $stmt->execute([$filename]);
    $row   = $stmt->fetch();
    $state = $row ? (json_decode($row['state_json'] ?? '{}', true) ?: []) : [];

    if ($inGallery) {
        $state['gallery'] = true;
    } else {
        unset($state['gallery']);
    }

    $db->prepare(
        "INSERT INTO media_states (filename, type, state_json, updated_at)
         VALUES (?, '', ?, datetime('now'))
         ON CONFLICT(filename) DO UPDATE SET state_json = excluded.state_json, updated_at = excluded.updated_at"
    )->execute([$filename, json_encode($state)]);

    jsonResponse(['ok' => true, 'filename' => $filename, 'gallery' => $inGallery]);
}

/**
 * GET /api/media/seo-meta
 * Retourne les métadonnées SEO (alt, title, slug, credit) de toutes les images.
 */
function handleGetSeoMeta() {
    requireAuth(['admin']);
    $db = getDb();

    $rows = $db->query(
        "SELECT filename, state_json FROM media_states WHERE filename NOT LIKE '_project_%'"
    )->fetchAll();

    $meta = [];
    foreach ($rows as $r) {
        $state = json_decode($r['state_json'] ?? '{}', true) ?: [];
        if (!empty($state['seo_alt']) || !empty($state['seo_title']) || !empty($state['seo_slug']) || !empty($state['credit'])) {
            $meta[$r['filename']] = [
                'alt'    => $state['seo_alt']   ?? '',
                'title'  => $state['seo_title']  ?? '',
                'slug'   => $state['seo_slug']   ?? '',
                'credit' => $state['credit']     ?? '',
            ];
        }
    }

    jsonResponse($meta);
}

/**
 * POST /api/media/seo-meta
 * Sauvegarde {filename, alt, title, slug} dans media_states.state_json.
 */
function handleSaveSeoMeta($input) {
    requireAuth(['admin']);
    $filename = trim($input['filename'] ?? '');
    if (!$filename) errorResponse('filename requis', 400);

    $db  = getDb();
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // ── Renommage si le slug a changé ─────────────────────────
    $newSlug    = trim($input['slug'] ?? '');
    $newFilename = $filename;
    $renamed     = false;

    if ($newSlug) {
        $slugClean   = preg_replace('/[^a-z0-9\-]/', '', strtolower($newSlug));
        $slugClean   = trim($slugClean, '-');
        $proposed    = $slugClean . '.' . $ext;

        if ($proposed !== $filename && $slugClean !== '') {
            $oldPath = UPLOAD_DIR . $filename;
            $newPath = UPLOAD_DIR . $proposed;

            // Résoudre conflit de nom
            if (file_exists($newPath) && $newPath !== $oldPath) {
                $proposed = $slugClean . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.' . $ext;
                $newPath  = UPLOAD_DIR . $proposed;
            }

            if (file_exists($oldPath) && rename($oldPath, $newPath)) {
                $newFilename = $proposed;
                $renamed     = true;

                // Renommer le _web.jpg associé
                $oldBase = pathinfo($filename, PATHINFO_FILENAME);
                $newBase = pathinfo($proposed, PATHINFO_FILENAME);
                $oldWeb  = UPLOAD_DIR . $oldBase . '_web.jpg';
                $newWeb  = UPLOAD_DIR . $newBase . '_web.jpg';
                if (file_exists($oldWeb)) rename($oldWeb, $newWeb);

                // Mettre à jour les références en base
                $oldUrl = UPLOAD_URL . $filename;
                $newUrl = UPLOAD_URL . $proposed;
                foreach ([
                    "UPDATE settings SET value  = REPLACE(value,  ?, ?) WHERE value  LIKE ?",
                    "UPDATE content  SET media  = REPLACE(media,  ?, ?) WHERE media  LIKE ?",
                    "UPDATE content  SET body   = REPLACE(body,   ?, ?) WHERE body   LIKE ?",
                    "UPDATE events   SET poster = REPLACE(poster, ?, ?) WHERE poster LIKE ?",
                ] as $sql) {
                    $db->prepare($sql)->execute([$oldUrl, $newUrl, '%' . $filename . '%']);
                }
                // Déplacer l'entrée media_states
                $db->prepare("UPDATE media_states SET filename = ? WHERE filename = ?")
                   ->execute([$newFilename, $filename]);
            }
        }
    }

    // ── Sauvegarder les métadonnées SEO ───────────────────────
    $row = $db->prepare("SELECT state_json FROM media_states WHERE filename = ?");
    $row->execute([$newFilename]);
    $existing = $row->fetch();
    $state = $existing ? (json_decode($existing['state_json'] ?? '{}', true) ?: []) : [];

    $state['seo_alt']   = array_key_exists('alt',    $input) ? trim($input['alt'])    : ($state['seo_alt']   ?? '');
    $state['seo_title'] = array_key_exists('title',  $input) ? trim($input['title'])  : ($state['seo_title'] ?? '');
    $state['seo_slug']  = $newSlug ?: ($state['seo_slug'] ?? '');
    $state['credit']    = array_key_exists('credit', $input) ? trim($input['credit']) : ($state['credit']    ?? '');

    $db->prepare(
        "INSERT INTO media_states (filename, type, state_json, updated_at)
         VALUES (?, '', ?, datetime('now'))
         ON CONFLICT(filename) DO UPDATE SET state_json = excluded.state_json, updated_at = excluded.updated_at"
    )->execute([$newFilename, json_encode($state)]);

    jsonResponse(['ok' => true, 'renamed' => $renamed, 'new_filename' => $newFilename]);
}

/**
 * POST /api/media/seo-suggest
 * Analyse l'image via IA (Claude ou Gemini) et retourne des suggestions SEO.
 * Détection automatique : sk-ant-* → Anthropic Claude, AIza* → Google Gemini.
 */
function _eb80_orig_handleSeoSuggest($input) {
    requireAuth(['admin']);
    $filename = trim($input['filename'] ?? '');
    if (!$filename) errorResponse('filename requis', 400);

    $filepath = UPLOAD_DIR . basename($filename);
    if (!file_exists($filepath)) errorResponse('Fichier introuvable', 404);

    // Clé API : body en priorité, sinon settings DB
    $apiKey = trim($input['anthropic_api_key'] ?? '');
    if (!$apiKey) {
        $db     = getDb();
        $keyRow = $db->prepare("SELECT value FROM settings WHERE key = 'anthropic_api_key'");
        $keyRow->execute();
        $apiKey = trim($keyRow->fetchColumn() ?: '');
    }
    if (!$apiKey) errorResponse("Clé API non configurée (Anthropic sk-ant-… ou Google AIza…)", 400);

    $mime    = mime_content_type($filepath);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($mime, $allowed)) errorResponse('Format image non supporté', 400);
    $b64     = base64_encode(file_get_contents($filepath));

    $isGemini = str_starts_with($apiKey, 'AIza');

    $prompt = 'Tu es un expert SEO pour le site Electroboy 80 (spectacle musical solo annees 80).'
        . ' Analyse cette image et reponds en JSON avec exactement ces 3 champs :'
        . ' "alt" (texte alternatif SEO en francais, max 120 caracteres, mentionner Electroboy 80),'
        . ' "title" (attribut title court en francais, max 60 caracteres),'
        . ' "slug" (nom de fichier SEO, minuscules, tirets, sans accents, commencer par electroboy80-).'
        . ' Reponds uniquement avec le JSON, rien d\'autre.';

    // ── Appel API ──────────────────────────────────────────────
    if ($isGemini) {
        $content = callGemini($apiKey, $b64, $mime, $prompt);
    } else {
        $content = callClaude($apiKey, $b64, $mime, $prompt);
    }

    $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

    // ── Extraction selon le format ────────────────────────────
    $alt = $title = $slug = '';

    if ($isGemini) {
        // Cherche le premier { et tente de parser le JSON depuis là
        $jsonStart = strpos($content, '{');
        if ($jsonStart !== false) {
            $jsonEnd = strrpos($content, '}');
            if ($jsonEnd !== false && $jsonEnd > $jsonStart) {
                $data = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);
            }
        }
        if (isset($data) && is_array($data)) {
            $alt   = trim($data['alt']   ?? '');
            $title = trim($data['title'] ?? '');
            $slug  = trim($data['slug']  ?? '');
        }
    }

    // Fallback — cherche alt/title/slug n'importe où dans le texte (texte libre Gemini)
    if (!$alt) {
        $flat = preg_replace('/[\*`_]+/', '', $content); // strip markdown
        // Cherche "alt :", "texte alt :", "ALT :" etc. suivi de la valeur jusqu'à la fin de ligne
        if (preg_match('/\balt\b[^:\n]*:\s*([^\n]+)/i', $flat, $m))               $alt   = trim($m[1]);
        if (preg_match('/\btit(?:le|re)\b[^:\n]*:\s*([^\n]+)/i', $flat, $m))      $title = trim($m[1]);
        if (preg_match('/\bslug\b[^:\n]*:\s*([^\n]+)/i', $flat, $m))              $slug  = trim($m[1]);
    }

    if ($alt) {
        jsonResponse(['alt' => $alt, 'title' => $title, 'slug' => $slug, '_raw' => substr($content, 0, 400)]);
    }
    errorResponse('Réponse non reconnue — brut: ' . substr($content, 0, 400), 502);
}

function callClaude(string $apiKey, string $b64, string $mime, string $prompt): string {
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 300,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                ['type' => 'text',  'text'   => $prompt],
            ],
        ]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status !== 200) {
        $err = json_decode($resp, true);
        errorResponse('Erreur Claude : ' . ($err['error']['message'] ?? $resp), 502);
    }
    $body = json_decode($resp, true);
    return $body['content'][0]['text'] ?? '';
}

function callGemini(string $apiKey, string $b64, string $mime, string $prompt): string {
    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($apiKey);
    $payload = json_encode([
        'contents' => [[
            'parts' => [
                ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                ['text'        => $prompt],
            ],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => 512,
            'temperature'     => 0.2,
            'responseMimeType' => 'application/json',
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['content-type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status !== 200) {
        $err = json_decode($resp, true);
        errorResponse('Erreur Gemini : ' . ($err['error']['message'] ?? $resp), 502);
    }
    $body = json_decode($resp, true);
    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        // Expose la réponse complète pour débug (candidates vide, safety block, etc.)
        errorResponse('Gemini sans contenu — brut API: ' . substr($resp, 0, 600), 502);
    }
    return $text;
}

/**
 * POST /api/media/download-video
 * Télécharge une vidéo YouTube/Vimeo via yt-dlp et la sauvegarde dans uploads/
 */
function _eb80_orig_handleDownloadVideo(array $input) {
    set_time_limit(0);
    $url = trim($input['url'] ?? '');
    if (!$url) {
        errorResponse('URL manquante', 400);
    }

    // Valider que c'est bien YouTube ou Vimeo
    $isYt    = preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]{11})/', $url, $ytM);
    $isVimeo = preg_match('/vimeo\.com\/(\d+)/', $url, $vmM);
    if (!$isYt && !$isVimeo) {
        errorResponse('URL non supportée (YouTube et Vimeo uniquement)', 400);
    }

    $ytdlp = '/opt/homebrew/bin/yt-dlp';
    if (!file_exists($ytdlp)) {
        $ytdlp = trim(shell_exec('which yt-dlp 2>/dev/null'));
    }
    if (!$ytdlp || !file_exists($ytdlp)) {
        http_response_code(503);
        echo json_encode(['error' => 'yt-dlp non disponible sur ce serveur', 'unavailable' => true]);
        exit;
    }

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    // Nom de fichier déterministe pour éviter les doublons
    $id       = $isYt ? $ytM[1] : $vmM[1];
    $prefix   = $isYt ? 'yt' : 'vimeo';
    $filename = $prefix . '_' . $id . '.mp4';
    $outPath  = UPLOAD_DIR . $filename;

    // Si déjà téléchargé, on renvoie directement
    if (file_exists($outPath) && filesize($outPath) > 10000) {
        jsonResponse(['url' => UPLOAD_URL . $filename, 'filename' => $filename, 'cached' => true]);
        return;
    }

    // Localiser ffmpeg (non présent dans le PATH de PHP sous Herd/Valet)
    $ffmpegBin = '';
    foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $p) {
        if (file_exists($p)) { $ffmpegBin = $p; break; }
    }
    $ffmpegArg = $ffmpegBin ? '--ffmpeg-location ' . escapeshellarg($ffmpegBin) : '';

    // Format : meilleure qualité ≤ 1080p en mp4
    $format  = 'bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/bestvideo[height<=1080]+bestaudio/best[ext=mp4]/best';
    $outTpl  = escapeshellarg(UPLOAD_DIR . $prefix . '_' . $id . '.%(ext)s');
    $urlEsc  = escapeshellarg($url);
    $cmd     = "$ytdlp -f " . escapeshellarg($format) . " --merge-output-format mp4 $ffmpegArg -o $outTpl $urlEsc 2>&1";

    exec($cmd, $lines, $code);

    if ($code !== 0 || !file_exists($outPath) || filesize($outPath) < 10000) {
        $log = implode("\n", array_slice($lines, -10));
        errorResponse('Téléchargement échoué : ' . $log, 500);
    }

    jsonResponse(['url' => UPLOAD_URL . $filename, 'filename' => $filename, 'cached' => false]);
}

/**
 * POST /api/media/merge-av
 * Fusionne une vidéo (webm) et une piste audio (mp3/m4a/wav…) → mp4 via ffmpeg
 */
function _eb80_orig_handleMergeAV(array $input) {
    set_time_limit(0);

    $videoFile = basename($input['video'] ?? '');
    $audioUrl  = $input['audio'] ?? '';
    $volume    = max(0, min(3, (float)($input['volume'] ?? 1.0)));
    $outname   = basename($input['outname'] ?? '');

    if (!$videoFile) errorResponse('Fichier vidéo manquant', 400);

    $videoPath = UPLOAD_DIR . $videoFile;
    if (!file_exists($videoPath)) errorResponse('Vidéo introuvable : ' . $videoFile, 404);

    // Audio : fichier local uniquement (dans uploads/)
    if (!$audioUrl || strpos($audioUrl, '/uploads/') === false) {
        errorResponse('Le fichier audio doit être dans la médiathèque', 400);
    }
    $audioFile = basename(parse_url($audioUrl, PHP_URL_PATH));
    $audioPath = UPLOAD_DIR . $audioFile;
    if (!file_exists($audioPath)) errorResponse('Audio introuvable : ' . $audioFile, 404);

    // Localiser ffmpeg
    $ffmpeg = '';
    foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $p) {
        if (file_exists($p)) { $ffmpeg = $p; break; }
    }
    if (!$ffmpeg) errorResponse('ffmpeg non disponible sur ce serveur', 500);

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    // Nom de fichier de sortie
    if (!$outname || !preg_match('/\.mp4$/i', $outname)) {
        $outname = preg_replace('/\.[^.]+$/', '', $videoFile) . '-son.mp4';
    }
    $outPath = UPLOAD_DIR . $outname;

    // Filtre volume (si différent de 1)
    $audioFilter = abs($volume - 1.0) > 0.01 ? '-filter:a ' . escapeshellarg("volume={$volume}") : '';

    $cmd = escapeshellarg($ffmpeg)
        . ' -y -i ' . escapeshellarg($videoPath)
        . ' -i '    . escapeshellarg($audioPath)
        . ' -c:v copy -c:a aac -b:a 192k'
        . ($audioFilter ? " $audioFilter" : '')
        . ' -shortest '
        . escapeshellarg($outPath)
        . ' 2>&1';

    exec($cmd, $lines, $code);

    if ($code !== 0 || !file_exists($outPath) || filesize($outPath) < 10000) {
        $log = implode("\n", array_slice($lines, -8));
        errorResponse('Fusion échouée : ' . $log, 500);
    }

    jsonResponse(['url' => UPLOAD_URL . $outname, 'filename' => $outname]);
}

/**
 * POST /api/media/extract-audio
 * Extrait la piste audio d'une vidéo → m4a via ffmpeg
 */
function _eb80_orig_handleExtractAudio(array $input) {
    set_time_limit(0);

    $videoFile = basename($input['video'] ?? '');
    if (!$videoFile) errorResponse('Fichier vidéo manquant', 400);

    $videoPath = UPLOAD_DIR . $videoFile;
    if (!file_exists($videoPath)) errorResponse('Vidéo introuvable : ' . $videoFile, 404);

    // Localiser ffmpeg
    $ffmpeg = '';
    foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $p) {
        if (file_exists($p)) { $ffmpeg = $p; break; }
    }
    if (!$ffmpeg) errorResponse('ffmpeg non disponible sur ce serveur', 500);

    // Nom de sortie : fourni par le client (éditable) ou même base que la vidéo + .m4a
    $outnameRaw = trim($input['outname'] ?? '');
    if ($outnameRaw) {
        $outnameRaw = preg_replace('/[^a-zA-Z0-9._\-]/', '-', basename($outnameRaw));
        $outFile = preg_match('/\.m4a$/i', $outnameRaw) ? $outnameRaw : $outnameRaw . '.m4a';
    } else {
        $outFile = preg_replace('/\.[^.]+$/', '', $videoFile) . '.m4a';
    }
    $outPath = UPLOAD_DIR . $outFile;

    // Déjà extrait ?
    if (file_exists($outPath) && filesize($outPath) > 1000) {
        jsonResponse(['url' => UPLOAD_URL . $outFile, 'filename' => $outFile, 'cached' => true]);
        return;
    }

    $cmd = escapeshellarg($ffmpeg)
        . ' -y -i ' . escapeshellarg($videoPath)
        . ' -vn -c:a aac -b:a 192k '
        . escapeshellarg($outPath)
        . ' 2>&1';

    exec($cmd, $lines, $code);

    if ($code !== 0 || !file_exists($outPath) || filesize($outPath) < 1000) {
        $log = implode("\n", array_slice($lines, -6));
        errorResponse('Extraction échouée : ' . $log, 500);
    }

    jsonResponse(['url' => UPLOAD_URL . $outFile, 'filename' => $outFile, 'cached' => false]);
}

/**
 * GET /api/media/local-file?file=yt_xxx.mp4
 * Sert un fichier depuis uploads/ avec CORS — permet au navigateur de récupérer
 * un fichier local pour l'uploader vers un autre serveur (fallback yt-dlp).
 */
function handleLocalFile() {
    $file = basename($_GET['file'] ?? '');
    if (!$file) { http_response_code(400); echo json_encode(['error' => 'Missing file']); exit; }
    $path = UPLOAD_DIR . $file;
    if (!file_exists($path)) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/**
 * POST /api/media/optimize-all
 * Génère les versions WebP pour toutes les images de uploads/ et met à jour
 * les settings DB pour chaque slot (img_hero, img_photo1, etc.)
 */
function handleOptimizeAll() {
    if (!function_exists('imagewebp')) {
        http_response_code(503);
        echo json_encode(['error' => 'GD WebP non disponible']);
        exit;
    }

    $db     = getDb();
    $pub    = '/uploads/';
    $done   = 0;
    $errors = [];

    // Charger les settings actuels pour mettre à jour les slots concernés
    $settings = [];
    $st = $db->query("SELECT key, value FROM settings WHERE key LIKE 'img_%'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $settings[$r['key']] = $r['value'];
    }

    // Slot keys à traiter (clés simples qui pointent vers /uploads/...)
    $slotKeys = array_filter(array_keys($settings), function($k) {
        return !str_ends_with($k, '_type')
            && !str_ends_with($k, '_webp')
            && !str_ends_with($k, '_video')
            && !str_ends_with($k, '_position')
            && !str_ends_with($k, '_playlist')
            && !str_ends_with($k, '_schedule')
            && !str_ends_with($k, '_logo')
            && !str_ends_with($k, '_programme')
            && !str_ends_with($k, '_delay');
    });

    $genWebp = function($srcImg, $srcW, $srcH, $maxW, $quality, $destPath) {
        $w   = min($srcW, $maxW);
        $h   = (int)round($srcH * $w / $srcW);
        $out = imagescale($srcImg, $w, $h, IMG_BICUBIC);
        if (!$out) return false;
        $ok  = imagewebp($out, $destPath, $quality);

        return $ok;
    };

    foreach ($slotKeys as $key) {
        $url = $settings[$key] ?? '';
        if (!$url || !str_starts_with($url, '/uploads/')) continue;

        $filename = basename($url);
        $srcPath  = UPLOAD_DIR . $filename;
        if (!file_exists($srcPath)) continue;

        $mime = mime_content_type($srcPath) ?: '';
        if (!str_starts_with($mime, 'image/')) continue;

        $base        = pathinfo($filename, PATHINFO_FILENAME);
        $mobileFile  = $base . '-768w.webp';
        $desktopFile = $base . '-1920w.webp';
        $mobilePath  = UPLOAD_DIR . $mobileFile;
        $desktopPath = UPLOAD_DIR . $desktopFile;

        // Générer seulement si manquant
        if (!file_exists($mobilePath) || !file_exists($desktopPath)) {
            $src = match($mime) {
                'image/jpeg' => @imagecreatefromjpeg($srcPath),
                'image/png'  => @imagecreatefrompng($srcPath),
                'image/webp' => @imagecreatefromwebp($srcPath),
                'image/gif'  => @imagecreatefromgif($srcPath),
                default      => null,
            };
            if (!$src) { $errors[] = $filename; continue; }
            $srcW = imagesx($src); $srcH = imagesy($src);
            if (!file_exists($mobilePath))  $genWebp($src, $srcW, $srcH, 768,  80, $mobilePath);
            if (!file_exists($desktopPath)) $genWebp($src, $srcW, $srcH, 1920, 85, $desktopPath);

        }

        // Mettre à jour la clé _webp dans les settings si elle n'existe pas encore
        $webpKey = $key . '_webp';
        if (empty($settings[$webpKey])) {
            $webpVal = json_encode(['m' => $pub . $mobileFile, 'd' => $pub . $desktopFile]);
            $upd = $db->prepare("INSERT INTO settings (key, value) VALUES (:k, :v)
                                 ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $upd->execute([':k' => $webpKey, ':v' => $webpVal]);
        }
        $done++;
    }

    echo json_encode(['done' => $done, 'errors' => $errors]);
    exit;
}

/**
 * POST /api/media/optimize
 * Génère des versions WebP optimisées (768w mobile, 1920w desktop) d'une image.
 * Idempotent : si les fichiers existent déjà, retourne simplement leurs URLs.
 */
function handleOptimizeImage(array $input) {
    $url = $input['url'] ?? '';
    if (!$url) { http_response_code(400); echo json_encode(['error' => 'url manquant']); exit; }

    $filename = basename(parse_url($url, PHP_URL_PATH));
    $srcPath  = UPLOAD_DIR . $filename;

    if (!file_exists($srcPath)) {
        http_response_code(404); echo json_encode(['error' => 'Fichier introuvable']); exit;
    }

    $mime = mime_content_type($srcPath) ?: '';
    if (!str_starts_with($mime, 'image/')) {
        echo json_encode(['skipped' => true, 'reason' => 'not_image']); exit;
    }

    if (!function_exists('imagewebp')) {
        http_response_code(503); echo json_encode(['error' => 'GD WebP non disponible']); exit;
    }

    $base        = pathinfo($filename, PATHINFO_FILENAME);
    $mobileFile  = $base . '-768w.webp';
    $desktopFile = $base . '-1920w.webp';
    $mobilePath  = UPLOAD_DIR . $mobileFile;
    $desktopPath = UPLOAD_DIR . $desktopFile;
    $pub         = '/uploads/';

    // Si les deux existent déjà, retourner directement
    if (file_exists($mobilePath) && file_exists($desktopPath)) {
        echo json_encode(['mobile' => $pub . $mobileFile, 'desktop' => $pub . $desktopFile, 'cached' => true]);
        exit;
    }

    // Charger l'image source
    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($srcPath),
        'image/png'  => @imagecreatefrompng($srcPath),
        'image/webp' => @imagecreatefromwebp($srcPath),
        'image/gif'  => @imagecreatefromgif($srcPath),
        default      => null,
    };
    if (!$src) { http_response_code(422); echo json_encode(['error' => 'Lecture image impossible']); exit; }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $genWebp = function($srcImg, $srcW, $srcH, $maxW, $quality, $destPath) {
        $w   = min($srcW, $maxW);
        $h   = (int)round($srcH * $w / $srcW);
        $out = imagescale($srcImg, $w, $h, IMG_BICUBIC);
        if (!$out) return false;
        $ok = imagewebp($out, $destPath, $quality);

        return $ok;
    };

    if (!file_exists($mobilePath))  $genWebp($src, $srcW, $srcH, 768,  80, $mobilePath);
    if (!file_exists($desktopPath)) $genWebp($src, $srcW, $srcH, 1920, 85, $desktopPath);



    echo json_encode(['mobile' => $pub . $mobileFile, 'desktop' => $pub . $desktopFile]);
    exit;
}

/**
 * Retourne un tableau associatif [basename => true] de tous les fichiers
 * /uploads/ référencés quelque part dans la base de données.
 */
function _collectReferencedFilenames(): array {
    $referenced = [];

    $addRef = function(string $raw) use (&$referenced) {
        if (!$raw) return;
        $raw = trim($raw);
        if (!str_contains($raw, 'uploads')) return;

        // JSON : décoder proprement (\/uploads\/ → /uploads/) puis parcourir toutes les valeurs
        if ($raw[0] === '{' || $raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                array_walk_recursive($decoded, function($val) use (&$referenced) {
                    if (is_string($val) && str_contains($val, '/uploads/')) {
                        $referenced[basename($val)] = true;
                    }
                });
                return;
            }
        }

        // Chemin direct, URL absolue ou JSON malformé : extraire tous les /uploads/ après déséchappement
        preg_match_all('~/uploads/([^"\'\\\\<>\\s]+)~', str_replace('\\/', '/', $raw), $m);
        foreach ($m[1] as $fn) $referenced[basename($fn)] = true;
    };

    $rows = dbFetchAll("SELECT poster, media_slots, rs_visuals, gallery, kit_files FROM events");
    foreach ($rows as $r) {
        $addRef($r['poster']      ?? '');
        $addRef($r['media_slots'] ?? '');
        $addRef($r['rs_visuals']  ?? '');
        $addRef($r['gallery']     ?? '');
        $addRef($r['kit_files']   ?? '');
    }

    $rows = dbFetchAll("SELECT media FROM content");
    foreach ($rows as $r) $addRef($r['media'] ?? '');

    $rows = dbFetchAll("SELECT value FROM settings WHERE value LIKE '%/uploads/%' OR value LIKE '%\\/uploads\\/%'");
    foreach ($rows as $r) $addRef($r['value'] ?? '');

    // media_states : filename du projet + tous les /uploads/ utilisés dans state_json
    // Attraper l'erreur si la colonne state_json n'existe pas encore sur ce serveur
    try {
        $rows = dbFetchAll("SELECT filename, state_json FROM media_states");
        foreach ($rows as $r) {
            $referenced[basename($r['filename'] ?? '')] = true;
            $addRef($r['state_json'] ?? '');
        }
    } catch (\Throwable $e) {
        // state_json absent : scanner juste les filenames
        $rows = dbFetchAll("SELECT filename FROM media_states");
        foreach ($rows as $r) $referenced[basename($r['filename'] ?? '')] = true;
    }

    return $referenced;
}

/**
 * Retourne les IDs des media_links YouTube/Vimeo référencés dans settings/events/content.
 */
function _collectUsedLinkIds(): array {
    $ytIds = [];
    $addYtIds = function(string $raw) use (&$ytIds) {
        if (!$raw || !str_contains($raw, 'youtu')) return;
        $clean = str_replace('\\/', '/', $raw);
        preg_match_all('~youtu(?:\.be/|be\.com/(?:watch\?v=|embed/|v/))([A-Za-z0-9_-]{11})~', $clean, $m);
        foreach ($m[1] as $id) $ytIds[$id] = true;
    };

    $rows = dbFetchAll("SELECT value FROM settings WHERE value LIKE '%youtu%'");
    foreach ($rows as $r) $addYtIds($r['value'] ?? '');

    $rows = dbFetchAll("SELECT media_slots, rs_visuals FROM events");
    foreach ($rows as $r) { $addYtIds($r['media_slots'] ?? ''); $addYtIds($r['rs_visuals'] ?? ''); }

    $rows = dbFetchAll("SELECT media FROM content WHERE media LIKE '%youtu%'");
    foreach ($rows as $r) $addYtIds($r['media'] ?? '');

    if (empty($ytIds)) return [];

    $usedIds = [];
    $links = dbFetchAll("SELECT id, url FROM media_links WHERE platform = 'youtube' OR platform = 'vimeo'");
    foreach ($links as $link) {
        preg_match('~youtu(?:\.be/|be\.com/(?:watch\?v=|embed/|v/))([A-Za-z0-9_-]{11})~', $link['url'], $m);
        if (isset($m[1]) && isset($ytIds[$m[1]])) $usedIds[] = (int)$link['id'];
    }
    return $usedIds;
}

/**
 * GET /api/media/used-files
 * Retourne la liste de tous les basenames de /uploads/ référencés en DB,
 * et les IDs des liens YouTube/Vimeo utilisés.
 */
function handleGetUsedFiles() {
    requireAuth(['admin']);
    $ref     = _collectReferencedFilenames();
    $linkIds = _collectUsedLinkIds();
    jsonResponse(['used' => array_keys($ref), 'used_link_ids' => $linkIds]);
}

/**
 * GET /api/media/orphan-crops
 * Retourne les fichiers crop dans uploads/ non référencés en DB.
 */
function handleGetOrphanCrops() {
    requireAuth(['admin']);
    $db = getDb();

    $cropFiles = [];
    if (is_dir(UPLOAD_DIR)) {
        foreach (new DirectoryIterator(UPLOAD_DIR) as $f) {
            if (!$f->isFile()) continue;
            $fn = $f->getFilename();
            if (strpos($fn, '-crop-') !== false || strpos($fn, 'crop-') === 0) {
                $cropFiles[$fn] = [
                    'filename' => $fn,
                    'url'      => UPLOAD_URL . $fn,
                    'size'     => $f->getSize(),
                    'date'     => date('Y-m-d H:i', $f->getMTime()),
                ];
            }
        }
    }

    if (empty($cropFiles)) {
        jsonResponse(['orphans' => [], 'total_size' => 0]);
        return;
    }

    $referenced = _collectReferencedFilenames();

    $orphans   = [];
    $totalSize = 0;
    foreach ($cropFiles as $fn => $info) {
        if (!isset($referenced[$fn])) {
            $orphans[]  = $info;
            $totalSize += $info['size'];
        }
    }

    usort($orphans, fn($a, $b) => strcmp($b['date'], $a['date']));
    jsonResponse(['orphans' => $orphans, 'total_size' => $totalSize]);
}

/**
 * DELETE /api/media/orphan-crops
 * Supprime une liste de fichiers crop (body JSON : {"filenames":["a.jpg","b.jpg"]})
 * ou tous les orphelins si filenames absent.
 */
function handleDeleteOrphanCrops(array $input) {
    requireAuth(['admin']);

    $toDelete = $input['filenames'] ?? null;

    if ($toDelete === null) {
        // Supprimer tous les orphelins : recalcul via handleGetOrphanCrops interne
        ob_start();
        handleGetOrphanCrops();
        $json = ob_get_clean();
        $data = json_decode($json, true);
        $toDelete = array_column($data['orphans'] ?? [], 'filename');
    }

    $deleted = [];
    $errors  = [];
    foreach ($toDelete as $fn) {
        $fn = basename($fn); // sécurité : pas de path traversal
        if (strpos($fn, '-crop-') === false && strpos($fn, 'crop-') !== 0) {
            $errors[] = $fn . ' (non-crop refusé)';
            continue;
        }
        $path = UPLOAD_DIR . $fn;
        if (file_exists($path)) {
            if (unlink($path)) $deleted[] = $fn;
            else $errors[] = $fn . ' (erreur suppression)';
        }
    }

    jsonResponse(['deleted' => $deleted, 'errors' => $errors]);
}

/**
 * POST /api/media/rename-batch
 * Renomme une liste de fichiers/projets en lot.
 * Body: { renames: [{old: 'filename', new: 'new_filename'}, ...] }
 */
function handleRenameBatch(array $input) {
    requireAuth(['admin']);
    $renames = $input['renames'] ?? [];
    if (!$renames) errorResponse('renames requis', 400);

    $db      = getDb();
    $results = [];

    foreach ($renames as $r) {
        $oldName = trim($r['old'] ?? '');
        $newName = trim($r['new'] ?? '');

        if (!$oldName || !$newName || $oldName === $newName) {
            $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => false, 'error' => 'Noms invalides'];
            continue;
        }

        // Sécurité : pas de path traversal
        $oldName = basename($oldName);
        $newName = basename($newName);

        try {
            // ── Projet virtuel (pas de fichier physique) ──────────────
            if (str_starts_with($oldName, '_project_')) {
                $db->prepare("UPDATE media_states SET filename = ?, updated_at = datetime('now') WHERE filename = ?")
                   ->execute([$newName, $oldName]);
                $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => true];
                continue;
            }

            // ── Fichier physique ──────────────────────────────────────
            $oldPath = UPLOAD_DIR . $oldName;
            $newPath = UPLOAD_DIR . $newName;

            if (!file_exists($oldPath)) {
                $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => false, 'error' => 'Fichier introuvable'];
                continue;
            }
            if (file_exists($newPath) && realpath($oldPath) !== realpath($newPath)) {
                $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => false, 'error' => 'Nom déjà utilisé'];
                continue;
            }
            if (!rename($oldPath, $newPath)) {
                $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => false, 'error' => 'Renommage impossible'];
                continue;
            }

            // Renommer le _web.jpg associé
            $oldBase = pathinfo($oldName, PATHINFO_FILENAME);
            $newBase = pathinfo($newName, PATHINFO_FILENAME);
            foreach ([['_web.jpg','_web.jpg'],['_768w.jpg','_768w.jpg'],['_1920w.jpg','_1920w.jpg']] as [$os,$ns]) {
                $ow = UPLOAD_DIR . $oldBase . $os;
                $nw = UPLOAD_DIR . $newBase . $ns;
                if (file_exists($ow)) rename($ow, $nw);
            }

            // Mettre à jour toutes les références URL en base
            $oldUrl = UPLOAD_URL . $oldName;
            $newUrl = UPLOAD_URL . $newName;
            foreach ([
                "UPDATE settings     SET value        = REPLACE(value,        ?, ?) WHERE value        LIKE ?",
                "UPDATE content      SET media        = REPLACE(media,        ?, ?) WHERE media        LIKE ?",
                "UPDATE content      SET body         = REPLACE(body,         ?, ?) WHERE body         LIKE ?",
                "UPDATE events       SET poster       = REPLACE(poster,       ?, ?) WHERE poster       LIKE ?",
                "UPDATE events       SET media_slots  = REPLACE(media_slots,  ?, ?) WHERE media_slots  LIKE ?",
                "UPDATE events       SET rs_visuals   = REPLACE(rs_visuals,   ?, ?) WHERE rs_visuals   LIKE ?",
                "UPDATE events       SET kit_files    = REPLACE(kit_files,    ?, ?) WHERE kit_files    LIKE ?",
                "UPDATE events       SET gallery      = REPLACE(gallery,      ?, ?) WHERE gallery      LIKE ?",
                "UPDATE media_states SET state_json   = REPLACE(state_json,   ?, ?) WHERE state_json   LIKE ?",
            ] as $sql) {
                $db->prepare($sql)->execute([$oldUrl, $newUrl, '%' . $oldName . '%']);
            }

            // Déplacer l'entrée media_states
            $db->prepare("UPDATE media_states SET filename = ?, updated_at = datetime('now') WHERE filename = ?")
               ->execute([$newName, $oldName]);

            $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => true];

        } catch (\Throwable $e) {
            $results[] = ['old' => $oldName, 'new' => $newName, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    $ok     = count(array_filter($results, fn($r) => $r['ok']));
    $failed = count($results) - $ok;
    jsonResponse(['results' => $results, 'ok' => $ok, 'failed' => $failed]);
}