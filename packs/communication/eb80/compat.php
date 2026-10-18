<?php
// Couche d'adaptation du portage d'electroboy80 (côté serveur). Les fichiers de eb80/api/ sont des copies d'electroboy80
// (tools/extract-eb80.php) ; ce fichier fixe leurs constantes pour la boutique courante, puis remplace les fonctions que
// l'extraction a renommées en _eb80_orig_… :
//  - connexion : celle de l'administration de la boutique (aucun jeton electroboy80), vérifiée par admin/plugin-api.php ;
//  - envoi de fichiers (direct, par morceaux, import par adresse) : type réel contrôlé (images, vidéos, sons), extension tirée
//    du type, adresses internes refusées pour l'import ;
//  - suggestion SEO : Gemini de la boutique, dans son quota d'IA ;
//  - outils vidéo qui exigent yt-dlp ou ffmpeg sur le serveur : indisponibles (comme electroboy80 sans ces outils) ;
//  - liste des médias : celle d'electroboy80 (dossier uploads/<boutique>/) plus les photos des fiches, en lecture seule.
// Isolation : chaque boutique a son dossier d'envoi (uploads/<boutique>/) et sa base d'electroboy80 (EB80_DB_PATH :
// data/com/<boutique>.db, jamais servie ni déployée), séparée de la base de la boutique : rien n'est partagé ni mélangé.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

define('UPLOAD_DIR', com_root() . '/uploads/' . tenant_slug() . '/');
define('UPLOAD_URL', '/uploads/' . tenant_slug() . '/');
define('EB80_DB_PATH', com_root() . '/data/com/' . tenant_slug() . '.db'); // jamais DB_PATH : c'est la base de la boutique
define('JWT_SECRET', bin2hex(random_bytes(16))); // les jetons d'electroboy80 ne servent pas ici : clé jetable
define('JWT_EXPIRES', 3600);
define('MAX_FILE_SIZE', COM_VIDEO_MAX_BYTES);
define('DB_DRIVER', 'sqlite');
const EB80_AUDIO_TYPES = ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg', 'audio/webm' => 'weba', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac'];
const EB80_AUDIO_MAX_BYTES = 50 * 1024 * 1024;
if (!is_dir(dirname(EB80_DB_PATH))) @mkdir(dirname(EB80_DB_PATH), 0700, true);
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);

require_once __DIR__ . '/api/config.php';

/** Réponse JSON interceptée (voir jsonResponse()) : permet de compléter la réponse d'un contrôleur d'electroboy80. */
final class Eb80Response extends Exception
{
    public function __construct(public mixed $data, public int $status) { parent::__construct('réponse'); }
}

function jsonResponse($data, $status = 200, int $cacheTtl = 0)
{
    if (!empty($GLOBALS['eb80_capture'])) throw new Eb80Response($data, (int) $status);
    _eb80_orig_jsonResponse($data, $status, $cacheTtl);
}

/** Exécute une fonction d'electroboy80 et renvoie sa réponse JSON au lieu de l'envoyer : [données, statut]. */
function eb80_capture(callable $fn): array
{
    $GLOBALS['eb80_capture'] = true;
    try {
        $fn();
    } catch (Eb80Response $r) {
        return [$r->data, $r->status];
    } finally {
        $GLOBALS['eb80_capture'] = false;
    }
    return [null, 200];
}

// ── Connexion : celle de l'administration de la boutique ────────────────────
/** L'utilisateur vu par electroboy80 : un administrateur (les droits fins sont vérifiés par la route eb80 du plugin). */
function eb80_user(): array
{
    $s = $GLOBALS['eb80_session'] ?? null;
    return ['id' => (int) ($s['id'] ?? 0), 'role' => 'admin', 'name' => ($s['name'] ?? '') !== '' ? $s['name'] : 'Compte principal', 'email' => '', 'permissions' => []];
}
function requireAuth($roles = [])
{
    if (empty($GLOBALS['eb80_session'])) errorResponse('Connexion requise', 401);
    return eb80_user();
}
function requirePerm(string $perm)
{
    return requireAuth();
}
function getCurrentUser()
{
    return empty($GLOBALS['eb80_session']) ? null : eb80_user();
}

// ── Fichiers : type réel contrôlé ───────────────────────────────────────────
/** Type réel d'un fichier accepté dans la médiathèque : ['type' => photo|video|audio, 'ext', 'mime'] ou null. */
function eb80_detect(string $file): ?array
{
    $d = com_detect($file);
    if ($d) return ['type' => $d['type'], 'ext' => $d['ext'], 'mime' => $d['mime']];
    $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file) : '';
    return isset(EB80_AUDIO_TYPES[$mime]) ? ['type' => 'audio', 'ext' => EB80_AUDIO_TYPES[$mime], 'mime' => $mime] : null;
}
function eb80_max_bytes(string $type): int
{
    return match ($type) { 'photo' => COM_IMAGE_MAX_BYTES, 'audio' => EB80_AUDIO_MAX_BYTES, default => COM_VIDEO_MAX_BYTES };
}
/** Range un fichier contrôlé dans UPLOAD_DIR sous un nom tiré de $base, extension selon son type ; renvoie le nom ou une erreur. */
function eb80_store(string $src, string $base, bool $uploaded): array
{
    $d = eb80_detect($src);
    if (!$d) return ['error' => 'Format non pris en charge (images, vidéos MP4/MOV/WebM, sons MP3/M4A/WAV/OGG).'];
    if (filesize($src) > eb80_max_bytes($d['type'])) return ['error' => 'Fichier trop volumineux (' . round(eb80_max_bytes($d['type']) / 1048576) . ' Mo au plus).'];
    $name = $base . '.' . $d['ext'];
    if (file_exists(UPLOAD_DIR . $name)) $name = $base . '-' . substr(uniqid(), -4) . '.' . $d['ext'];
    $ok = $uploaded ? move_uploaded_file($src, UPLOAD_DIR . $name) : @rename($src, UPLOAD_DIR . $name);
    if (!$ok) return ['error' => 'Erreur déplacement fichier'];
    @chmod(UPLOAD_DIR . $name, 0644);
    return ['filename' => $name, 'detect' => $d];
}

function uploadFile($fieldName = 'file')
{
    if (!isset($_FILES[$fieldName])) return ['error' => 'Aucun fichier'];
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'Erreur upload: ' . $file['error']];
    $base = slugifyFilename(pathinfo((string) $file['name'], PATHINFO_FILENAME));
    $r = eb80_store($file['tmp_name'], $base, true);
    if (isset($r['error'])) return $r;
    $urlWeb = null;
    if ($r['detect']['type'] === 'photo' && function_exists('imagecreatefromjpeg')) {
        $webFile = generateWebImage(UPLOAD_DIR . $r['filename'], pathinfo($r['filename'], PATHINFO_FILENAME));
        if ($webFile) $urlWeb = UPLOAD_URL . basename($webFile);
    }
    return ['url' => UPLOAD_URL . $r['filename'], 'url_web' => $urlWeb, 'filename' => $r['filename'], 'name' => $file['name'],
        'size' => filesize(UPLOAD_DIR . $r['filename']), 'mimetype' => $r['detect']['mime']];
}

/** POST /media/upload-chunk : même contrat qu'electroboy80, morceaux rangés dans data/com-tmp/ et fichier final contrôlé. */
function handleUploadChunk()
{
    requireAuth(['admin']);
    $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['upload_id'] ?? ''));
    $chunkIndex = (int) ($_POST['chunk_index'] ?? 0);
    $totalChunks = (int) ($_POST['total_chunks'] ?? 1);
    if ($uploadId === '' || strlen($uploadId) > 64) errorResponse('upload_id requis', 400);
    if ($totalChunks < 1 || $totalChunks > 2000 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) errorResponse('total_chunks invalide', 400);
    if (!isset($_FILES['chunk'])) errorResponse('chunk manquant', 400);
    if ($_FILES['chunk']['error'] !== UPLOAD_ERR_OK) errorResponse('Erreur upload chunk: ' . $_FILES['chunk']['error'], 400);
    com_tmp_cleanup();
    $tmpDir = com_tmp_dir() . '/eb80-' . tenant_slug() . '-' . $uploadId . '/';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0700, true)) errorResponse('Impossible de créer le répertoire temporaire', 500);
    $chunkFile = $tmpDir . 'chunk_' . str_pad((string) $chunkIndex, 8, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) errorResponse('Erreur déplacement du chunk', 500);
    $parts = glob($tmpDir . 'chunk_*') ?: [];
    if (array_sum(array_map('filesize', $parts)) > MAX_FILE_SIZE) {
        array_map('unlink', $parts);
        @rmdir($tmpDir);
        errorResponse('Fichier trop volumineux', 400);
    }
    if (count($parts) < $totalChunks) jsonResponse(['done' => false, 'received' => count($parts), 'total' => $totalChunks]);
    $assembled = $tmpDir . 'assemble';
    $out = fopen($assembled, 'wb');
    for ($i = 0; $i < $totalChunks; $i++) {
        $part = $tmpDir . 'chunk_' . str_pad((string) $i, 8, '0', STR_PAD_LEFT);
        if (!is_file($part)) { fclose($out); errorResponse("Chunk $i manquant lors du réassemblage", 500); }
        $in = fopen($part, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);
    $r = eb80_store($assembled, time() . '-' . random_int(100000, 999999), false);
    foreach (glob($tmpDir . '*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir);
    if (isset($r['error'])) errorResponse($r['error'], 415);
    jsonResponse(['done' => true, 'filename' => $r['filename'], 'url' => UPLOAD_URL . $r['filename'], 'size' => filesize(UPLOAD_DIR . $r['filename'])]);
}

/** POST /media/import : téléchargement d'une adresse web, sans jamais joindre le réseau interne (com_fetch_url()). */
function handleImportUrl($input)
{
    requireAuth(['admin']);
    $url = trim((string) ($input['url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) errorResponse('URL invalide', 400);
    try {
        [$tmp, $name] = com_fetch_url($url, MAX_FILE_SIZE);
    } catch (Throwable $e) {
        errorResponse($e->getMessage(), 400);
    }
    if (filesize($tmp) < 100) { @unlink($tmp); errorResponse('Fichier trop petit ou vide', 400); }
    $r = eb80_store($tmp, slugifyFilename(pathinfo($name, PATHINFO_FILENAME) ?: 'import'), false);
    if (isset($r['error'])) { @unlink($tmp); errorResponse($r['error'], 400); }
    if ($r['detect']['type'] === 'photo') generateWebImage(UPLOAD_DIR . $r['filename'], pathinfo($r['filename'], PATHINFO_FILENAME));
    jsonResponse(['filename' => $r['filename'], 'url' => UPLOAD_URL . $r['filename'], 'size' => filesize(UPLOAD_DIR . $r['filename'])]);
}

/** POST /media/seo-suggest : texte alternatif, titre et nom de fichier proposés par Gemini (clé et quota de la boutique). */
function handleSeoSuggest($input)
{
    requireAuth(['admin']);
    $filename = basename(trim((string) ($input['filename'] ?? '')));
    if ($filename === '') errorResponse('filename requis', 400);
    $filepath = UPLOAD_DIR . $filename;
    if (!is_file($filepath)) errorResponse('Fichier introuvable', 404);
    if (!com_ai_ready()) errorResponse('Clé Gemini absente : suggestions de l\'IA indisponibles.', 400);
    if (($gate = ai_gate()) !== null) errorResponse($gate, 402);
    $shop = (string) get_content()['site_name'];
    $prompt = 'Tu es un expert SEO pour « ' . $shop . ' », ' . tenant('ai.shop') . '. Analyse cette image et réponds en JSON avec exactement ces 3 champs : '
        . '"alt" (texte alternatif SEO en français, 120 caractères au plus, décrivant ce que montre l\'image), '
        . '"title" (attribut title court en français, 60 caractères au plus), '
        . '"slug" (nom de fichier SEO : minuscules, tirets, sans accents). Réponds uniquement avec le JSON.';
    $small = downscale_for_ai($filepath) ?? $filepath;
    $text = gemini_describe_image($small, $prompt);
    if ($small !== $filepath) @unlink($small);
    $data = $text ? json_decode((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true) : null;
    if (!is_array($data) || empty($data['alt'])) errorResponse('Réponse non reconnue, réessayez.', 502);
    jsonResponse(['alt' => trim((string) $data['alt']), 'title' => trim((string) ($data['title'] ?? '')), 'slug' => trim((string) ($data['slug'] ?? ''))]);
}

/** Outils vidéo d'electroboy80 qui exigent yt-dlp ou ffmpeg sur le serveur : indisponibles sur l'hébergement de la plateforme. */
function eb80_unavailable(string $what): never
{
    jsonResponse(['error' => $what . ' indisponible sur ce serveur (outil vidéo absent).', 'unavailable' => true], 501);
}
function handleDownloadVideo(array $input) { requireAuth(['admin']); eb80_unavailable('Téléchargement de vidéo'); }
function handleMergeAV(array $input) { requireAuth(['admin']); eb80_unavailable('Fusion audio et vidéo'); }
function handleExtractAudio(array $input) { requireAuth(['admin']); eb80_unavailable('Extraction de la piste audio'); }

/** GET /media : la liste d'electroboy80 (uploads/<boutique>/) suivie des photos des fiches produit, en lecture seule. */
function handleGetMedia()
{
    [$files] = eb80_capture('_eb80_orig_handleGetMedia');
    $files = is_array($files) ? $files : [];
    $seen = array_flip(array_column($files, 'filename'));
    foreach (db()->query('SELECT p.path, p.label, p.created_at, p.type, pr.ref, pr.name FROM product_photos p JOIN products pr ON pr.ref = p.product_ref
            WHERE p.is_hidden = 0 ORDER BY p.id DESC')->fetchAll(PDO::FETCH_ASSOC) as $ph) {
        $abs = com_root() . '/' . $ph['path'];
        $fn = basename((string) $ph['path']);
        if (isset($seen[$fn]) || !is_file($abs) || str_contains((string) $ph['path'], '..')) continue;
        $seen[$fn] = true;
        $files[] = ['url' => '/' . $ph['path'], 'url_web' => null, 'filename' => $fn, 'name' => $fn, 'size' => filesize($abs),
            'mimetype' => mime_content_type($abs) ?: 'image/jpeg', 'date' => date('Y-m-d H:i:s', filemtime($abs)),
            'readonly' => true, 'fiche' => ['ref' => $ph['ref'], 'name' => $ph['name'], 'label' => $ph['label']]];
    }
    usort($files, static fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
    jsonResponse($files);
}
