<?php
// Couche d'adaptation du portage d'electroboy80 (côté serveur). Les fichiers de eb80/api/ sont des copies d'electroboy80
// (tools/extract-eb80.php) ; ce fichier fixe leurs constantes pour la boutique courante, puis remplace les fonctions que
// l'extraction a renommées en _eb80_orig_… :
//  - connexion : celle de l'administration de la boutique (aucun jeton electroboy80), vérifiée par admin/plugin-api.php ;
//  - envoi de fichiers (direct, par morceaux, import par adresse) : type réel contrôlé (images, vidéos, sons), extension tirée
//    du type, adresses internes refusées pour l'import ;
//  - suggestion SEO, script vidéo et message de publication par l'IA : Gemini de la boutique, dans son quota d'IA ;
//  - réseaux sociaux : simulateur local possible (EB80_RS_SIMULATEUR), rôle « collaborateur » pour le community manager ;
//  - outils vidéo qui exigent yt-dlp ou ffmpeg sur le serveur : indisponibles (comme electroboy80 sans ces outils) ;
//  - liste des médias : celle d'electroboy80 (dossier uploads/<boutique>/) plus les photos des fiches, en lecture seule.
// Isolation : chaque boutique a son dossier d'envoi (uploads/<boutique>/) et sa base d'electroboy80 (EB80_DB_PATH :
// data/com/<boutique>.db, jamais servie ni déployée), séparée de la base de la boutique : rien n'est partagé ni mélangé.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

// Heures d'electroboy80 (publications programmées, dates des projets) : heure locale de la boutique, comme son interface les saisit
// (fuseau « timezone » du tenant.php, Paris par défaut ; PHP en ligne de commande compterait sinon en temps universel).
date_default_timezone_set((string) (tenant('timezone') ?: 'Europe/Paris'));

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
// Vidéo IA : fichier MP4 renvoyé par l'agent de rendu (500 Mo au plus) ; ses morceaux attendent hors de uploads/ (jamais servis).
const EB80_VIDEO_MAX_OCTETS = 500 * 1024 * 1024;
define('EB80_TMP_DIR', com_root() . '/data/com/tmp/' . tenant_slug() . '/');
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
    // Administrateur de la boutique → « admin » d'electroboy80 ; community manager → « collaborateur » (droits editor et community :
    // il publie et programme, mais les réglages d'electroboy80 ne lui renvoient pas les jetons ni les clés).
    $admin = ($s['role'] ?? '') === 'admin';
    return ['id' => (int) ($s['id'] ?? 0), 'role' => $admin ? 'admin' : 'collaborator', 'name' => ($s['name'] ?? '') !== '' ? $s['name'] : 'Compte principal',
        'email' => '', 'permissions' => $admin ? [] : ['editor', 'community']];
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
/** Corps JSON de l'appel ; la route peut le remplacer (réglages filtrés, voir api/eb80.php) par $GLOBALS['eb80_json_input']. */
function getJsonInput()
{
    return isset($GLOBALS['eb80_json_input']) && is_array($GLOBALS['eb80_json_input']) ? $GLOBALS['eb80_json_input'] : _eb80_orig_getJsonInput();
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

/** Clés d'IA lues par ai-generate.php : la clé Gemini de la boutique ; aucune autre (la boutique n'utilise pas Claude). */
function getAiKey(string $keyName): string
{
    return $keyName === 'gemini_api_key' && com_ai_ready() ? GEMINI_API_KEY : '';
}

// ── Réseaux sociaux ─────────────────────────────────────────────────────────
/**
 * Simulateur local des réseaux (constante EB80_RS_SIMULATEUR, à mettre dans config.local.php d'un poste de test, jamais en ligne) :
 * aucun appel ne part, chaque appel est noté dans data/com/<boutique>-simulateur.log et reçoit une réponse plausible (identifiant,
 * page, compte Instagram, statut « FINISHED »…). Sans la constante, les vrais appels d'electroboy80.
 */
function eb80_rs_simulated(): bool
{
    return defined('EB80_RS_SIMULATEUR') && EB80_RS_SIMULATEUR;
}
function apiCurl(string $url, array $opts = []): array
{
    if (!eb80_rs_simulated()) return _eb80_orig_apiCurl($url, $opts);
    $post = $opts[CURLOPT_POSTFIELDS] ?? null;
    $line = ['at' => date('c'), 'method' => !empty($opts[CURLOPT_POST]) || $post !== null ? 'POST' : 'GET',
        'url' => preg_replace('/(access_token|client_secret|fb_exchange_token)=[^&]+/', '$1=***', $url),
        'body' => is_string($post) ? mb_substr($post, 0, 500) : (is_array($post) ? array_keys($post) : null)];
    @file_put_contents(dirname(EB80_DB_PATH) . '/' . tenant_slug() . '-simulateur.log', json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    $id = 'sim_' . bin2hex(random_bytes(4));
    $reply = [
        'id' => $id, 'post_id' => $id, 'success' => true, 'status_code' => 'FINISHED', 'status' => 'FINISHED', 'simulateur' => true,
        'name' => 'Page simulée', 'username' => 'boutique_simulee', 'access_token' => 'sim_token', 'expires_in' => 5184000,
        'instagram_business_account' => ['id' => 'sim_ig'], 'permalink_url' => 'https://example.test/' . $id,
    ];
    // TikTok répond { data: { publish_id } } ; Meta, pour les listes (pages…), { data: [ … ] }.
    $reply['data'] = str_contains($url, 'tiktok')
        ? ['publish_id' => $id, 'status' => 'PUBLISH_COMPLETE']
        : [['id' => 'sim_page', 'name' => 'Page simulée', 'access_token' => 'sim_token', 'instagram_business_account' => ['id' => 'sim_ig']]];
    return $reply;
}
/** Table des publications programmées : config.php d'electroboy80 la crée avec un ancien schéma (sans next_run) que rs-schedule.php
 *  ne corrige pas ; vide, on la retire pour qu'il crée la bonne. Appelée avant toute route /rs/schedule et par la tâche planifiée. */
function eb80_fix_schedule_table(): void
{
    $db = getDb();
    $cols = array_column($db->query('PRAGMA table_info(scheduled_posts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if ($cols && !in_array('next_run', $cols, true) && (int) $db->query('SELECT COUNT(*) FROM scheduled_posts')->fetchColumn() === 0) {
        $db->exec('DROP TABLE scheduled_posts');
    }
}
function refreshTikTokToken(): bool
{
    return eb80_rs_simulated() ? true : _eb80_orig_refreshTikTokToken();
}
/** POST /rs/generate-message : texte de publication rédigé par Gemini (clé et quota de la boutique), même réponse qu'electroboy80. */
function handleGenerateMessage(array $input): void
{
    requireAuth(['admin']);
    if (!com_ai_ready()) errorResponse('Clé Gemini absente : rédaction par l\'IA indisponible.', 400);
    if (($gate = ai_gate()) !== null) errorResponse($gate, 402);
    $networks = array_map('strval', (array) ($input['networks'] ?? []));
    $type = match (trim((string) ($input['content_type'] ?? 'post'))) { 'story' => 'Story', 'reel' => 'Reel / vidéo courte', default => 'Post' };
    $ctx = [];
    foreach (['event_name' => 'Sujet', 'event_date' => 'Date', 'event_venue' => 'Lieu', 'hint' => 'Indications'] as $k => $label) {
        $v = trim((string) ($input[$k] ?? ''));
        if ($v !== '') $ctx[] = "$label : " . mb_substr($v, 0, 300);
    }
    $prompt = 'Tu es le community manager de « ' . get_content()['site_name'] . ' », ' . tenant('ai.shop') . '. Rédige un ' . $type . ' pour '
        . ($networks ? implode(', ', array_map('ucfirst', $networks)) : 'les réseaux sociaux') . ' en français.'
        . ($ctx ? "\nContexte :\n" . implode("\n", $ctx) : '')
        . "\n\nTon chaleureux et concret, sans formules banales. Pour Instagram et TikTok : 150 caractères au plus et 3 à 5 mots-dièse."
        . ' Pour Facebook : 280 caractères au plus, sans mots-dièse. Pour Threads : 200 caractères au plus. Si plusieurs réseaux, adapte'
        . " pour le premier. N'invente ni prix, ni date, ni caractéristique. Réponds uniquement avec le texte, sans guillemets.";
    $text = gemini_describe_image('', $prompt);
    if (!$text) errorResponse('Rédaction par l\'IA indisponible pour le moment, réessayez.', 502);
    jsonResponse(['ok' => true, 'message' => trim($text)]);
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

// ── Vidéo IA : scénario écrit par l'IA (eb80/api/video-jobs-ai.php) ──────────────────────────────────────────────────────────────
// IA choisie dans API & Réseaux → IA (réglage video_ai_provider de la base d'electroboy80) : Gemini par défaut (clé de la boutique ou
// partagée, quota de la boutique), Claude si une clé Claude existe (celle de la boutique, .secrets/<boutique>/claude.key, ou la clé
// partagée du portail). Le coût est compté dans le quota de la boutique dans les deux cas (barème du portail → IA).

function eb80_via_fournisseur(): string
{
    return getSetting('video_ai_provider') === 'claude' ? 'claude' : 'gemini';
}

/** Clé Claude de la boutique, sinon clé partagée de la plateforme, sinon chaîne vide. */
function eb80_claude_key(): string
{
    $file = secret_path('claude.key');
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

/** Clé de l'appel (« gemini » ou la clé Claude), après le contrôle du quota d'IA ; arrête l'appel avec un message clair sinon. */
function eb80_via_cle(): string
{
    if (($gate = ai_gate()) !== null) errorResponse($gate, 402);
    if (defined('EB80_IA_SIMULATEUR') && EB80_IA_SIMULATEUR) return 'simulateur';
    if (eb80_via_fournisseur() === 'claude') {
        $key = eb80_claude_key();
        if ($key === '') errorResponse('Clé Claude absente : API & Réseaux → IA (ou choisir Gemini pour le scénario vidéo).', 400);
        return $key;
    }
    if (!com_ai_ready()) errorResponse('Clé Gemini absente : scénario par l\'IA indisponible.', 400);
    return 'gemini';
}

/** « La Brocante du Petit Chalet, brocante et dépôt-vente de… » (nom et description de la boutique). */
function eb80_via_identite_courte(): string
{
    return '« ' . get_content()['site_name'] . ' », ' . tenant('ai.shop');
}

/** Présentation de la boutique en tête du prompt du scénario (à la place de celle d'Electroboy 80). */
function eb80_via_identite(): string
{
    return 'Tu es réalisateur de clips promo pour ' . eb80_via_identite_courte() . ". Ton ton est chaleureux et vivant ;\n"
        . "tu mets en valeur des pièces réelles de la boutique, sans rien inventer (ni prix, ni date, ni caractéristique).";
}

/** Sujet par défaut (aucune pièce choisie) : la boutique elle-même, et non un concert. */
function eb80_via_sujet_boutique(): array
{
    return ['profil' => 'generique', 'photos' => [],
        'contexte' => 'La boutique « ' . get_content()['site_name'] . ' » (' . tenant('ai.shop') . ') : vidéo de promotion de la boutique et de ses nouveautés.'];
}

/** Schéma JSON → schéma de réponse de Gemini (types en capitales, sans additionalProperties ni valeur vide dans les listes). */
function eb80_gemini_schema(array $schema): array
{
    $out = [];
    foreach ($schema as $k => $v) {
        if ($k === 'additionalProperties') continue;
        if ($k === 'type' && is_string($v)) { $out[$k] = strtoupper($v); continue; }
        if ($k === 'enum' && is_array($v)) { if (in_array('', $v, true)) continue; $out[$k] = array_values($v); continue; }
        if ($k === 'properties' && is_array($v)) { $out[$k] = array_map('eb80_gemini_schema', $v); continue; }
        $out[$k] = is_array($v) && $k === 'items' ? eb80_gemini_schema($v) : $v;
    }
    return $out;
}

/** Réponse factice (EB80_IA_SIMULATEUR, tests locaux) : aucun appel à une IA. */
function eb80_via_simulation(array $schema): array
{
    $required = (array) ($schema['required'] ?? []);
    if (in_array('palettes', $required, true)) { // palettes des visuels : 10 palettes factices, teintes réparties
        $hsl = static function (float $h, float $s, float $l): string {
            $f = static function (float $n) use ($h, $s, $l): int {
                $k = fmod($n + $h / 30, 12);
                return (int) round(255 * ($l - $s * min($l, 1 - $l) * max(-1, min($k - 3, 9 - $k, 1))));
            };
            return sprintf('#%02X%02X%02X', $f(0), $f(8), $f(4));
        };
        $jeu = static fn (float $h, float $l) => ['fond' => $hsl($h, .35, $l), 'titre' => $hsl($h, .2, $l > .5 ? .12 : .95), 'texte' => $hsl($h, .15, $l > .5 ? .2 : .85),
            'accent' => $hsl(fmod($h + 30, 360), .7, .5), 'accent2' => $hsl(fmod($h + 180, 360), .65, .55), 'discret' => $hsl($h, .1, $l > .5 ? .45 : .6)];
        $palettes = [];
        for ($i = 0; $i < 10; $i++) {
            $h = $i * 36.0;
            $l = $i % 2 ? .9 : .12;
            $nuances = [];
            foreach (['Plus claire' => .15, 'Plus sombre' => -.1, 'Douce' => .05, 'Saturée' => 0, 'Inversée' => null] as $nom => $d) {
                $nuances[] = ['nom' => $nom, 'couleurs' => $jeu($h, $d === null ? 1 - $l : max(.05, min(.95, $l + $d)))];
            }
            $palettes[] = ['nom' => 'Palette simulée ' . ($i + 1), 'ambiance' => 'Palette factice (aucun appel à l\'IA).', 'couleurs' => $jeu($h, $l), 'nuances' => $nuances];
        }
        return [['palettes' => $palettes], ['model' => 'simulateur']];
    }
    if (in_array('accent', $required, true)) {
        return [['accent' => '#B5502E', 'label' => 'À DÉCOUVRIR', 'palette' => 'chaud', 'explication' => 'Charte simulée (aucun appel à l\'IA).'], ['model' => 'simulateur']];
    }
    $scene = static fn (string $type, float $duree, string $texte = '', string $texte2 = '', string $photo = '', array $mots = []) => [
        'type' => $type, 'duree' => $duree, 'texte' => $texte, 'texte2' => $texte2, 'photo' => $photo, 'mots' => $mots, 'bandeau' => '',
        'anim' => '', 'transition' => 'coupe', 'cadrage' => ['x' => 50, 'y' => 50, 'zoom' => 1]];
    $variante = ['nom' => 'Simulée', 'scenes' => [
        $scene('titre', 2.12, mb_strtoupper((string) get_content()['site_name']), 'EN BOUTIQUE'),
        $scene('photo', 2.12, 'PIÈCE UNIQUE', 'À SAISIR', 'IMG-0'), $scene('photo', 2.12, 'CHINÉ', 'POUR VOUS', 'IMG-1'),
        $scene('mots', 2.82, '', '', '', ['CHINÉ.', 'UNIQUE.']), $scene('concert', 3.53, '', 'À DÉCOUVRIR'), $scene('fin', 2.29, 'À BIENTÔT')],
        'mentions' => ['VINTAGE', 'PIÈCE UNIQUE'], 'texteInfos' => 'À découvrir', 'palette' => 'chaud', 'badge' => '', 'musique' => '',
        'legende' => 'Vidéo simulée.', 'hashtags' => ['brocante', 'vintage'], 'explication' => 'Variante simulée (aucun appel à l\'IA).', 'accentLieu' => ''];
    return [['variantes' => [$variante]], ['model' => 'simulateur']];
}

/**
 * Remplace viaClaude() d'electroboy80 : même contrat (système, contenu en blocs texte / image, schéma JSON) et même retour
 * [réponse JSON, réponse brute], avec Gemini ou Claude selon la clé reçue (eb80_via_cle()) ; consommation comptée.
 */
function viaClaude(string $cle, string $systeme, array $contenu, array $schema, int $maxTokens, int $delai): array
{
    if ($cle === 'simulateur') return eb80_via_simulation($schema);
    if ($cle !== 'gemini') {
        // Claude ; s'il échoue (clé invalide, quota, réseau…) et qu'une clé Gemini existe : repli sur Gemini
        $erreurClaude = null;
        $GLOBALS['eb80_capture'] = com_ai_ready();
        try {
            [$json, $rep] = _eb80_orig_viaClaude($cle, $systeme, $contenu, $schema, $maxTokens, $delai);
            ai_usage_log_claude((array) ($rep['usage'] ?? []), (string) ($rep['model'] ?? VIA_MODELE));
            return [$json, $rep];
        } catch (Eb80Response $r) {
            $erreurClaude = (string) (($r->data['error'] ?? '') ?: 'erreur');
        } finally {
            $GLOBALS['eb80_capture'] = false;
        }
        error_log('Pack Communication : Claude indisponible (' . $erreurClaude . '), repli sur Gemini');
    }
    $parts = [];
    foreach ($contenu as $bloc) {
        if (($bloc['type'] ?? '') === 'text') $parts[] = ['text' => (string) $bloc['text']];
        elseif (($bloc['type'] ?? '') === 'image') $parts[] = ['inlineData' => ['mimeType' => (string) ($bloc['source']['media_type'] ?? 'image/jpeg'), 'data' => (string) ($bloc['source']['data'] ?? '')]];
    }
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $systeme]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => eb80_gemini_schema($schema), 'maxOutputTokens' => max(8192, $maxTokens * 2)],
    ];
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => $delai]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($err) errorResponse('Erreur réseau Gemini : ' . $err, 502);
    $rep = json_decode((string) $body, true) ?: [];
    if ($code >= 400 || isset($rep['error'])) errorResponse('Gemini : ' . ($rep['error']['message'] ?? "HTTP $code"), 502);
    ai_usage_log_text((array) ($rep['usageMetadata'] ?? []));
    $text = '';
    foreach ((array) ($rep['candidates'][0]['content']['parts'] ?? []) as $part) $text .= (string) ($part['text'] ?? '');
    $json = json_decode($text, true);
    if (!is_array($json)) errorResponse(($rep['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS' ? 'Réponse de Gemini tronquée, réessayez.' : 'Réponse de Gemini illisible.', 502);
    return [$json, ['model' => 'gemini-2.5-flash', 'usage' => $rep['usageMetadata'] ?? []]];
}

// ── Palettes des visuels (eb80/api/palettes.php) : la boutique et son IA ─────────────────────────────────────────────────
function palettesIdentite(): string
{
    return eb80_via_identite_courte();
}

/** Même appel que le scénario de la Vidéo IA (eb80_via_cle, viaClaude) : Gemini ou Claude, quota de la boutique, simulateur. */
function palettesAppelIA(string $systeme, string $texte, array $schema): array
{
    [$json, $rep] = viaClaude(eb80_via_cle(), $systeme, [['type' => 'text', 'text' => $texte]], $schema, 4000, 100);
    return [$json, (string) ($rep['model'] ?? '')];
}

