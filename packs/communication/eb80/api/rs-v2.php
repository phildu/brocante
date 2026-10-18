<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
// ELECTROBOY 80 — RS handler v2 (jamais mis en cache OPcache)

ob_start();
require_once __DIR__ . '/../compat.php';
ob_end_clean();
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_exception_handler(function(\Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Exception PHP: ' . $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    exit;
});

// En mode RS_LIB_ONLY, ce fichier est inclus pour ses fonctions uniquement (rs-schedule.php)
if (!defined('RS_LIB_ONLY')) {
    $path   = getPath();
    $method = getMethod();
    $input  = getJsonInput();

    if ($path === '/rs/publish' && $method === 'POST') {
        handlePublish($input);
    } elseif ($path === '/rs/event' && $method === 'POST') {
        handleCreateEvent($input);
    } elseif ($path === '/rs/diagnose' && $method === 'GET') {
        handleDiagnose();
    } elseif ($path === '/rs/media/facebook' && $method === 'GET') {
        handleFbMedia();
    } elseif ($path === '/rs/media/facebook-photos' && $method === 'GET') {
        handleFbPhotos();
    } elseif ($path === '/rs/media/tiktok' && $method === 'GET') {
        handleTikTokMedia();
    } elseif ($path === '/rs/fb-refresh-token' && $method === 'POST') {
        handleFbRefreshToken();
    } elseif ($path === '/rs/ig-refresh-token' && $method === 'POST') {
        handleIgRefreshToken();
    } elseif ($path === '/rs/threads-refresh-token' && $method === 'POST') {
        handleThreadsRefreshToken();
    } elseif ($path === '/rs/generate-message' && $method === 'POST') {
        handleGenerateMessage($input);
    } elseif (preg_match('#^/rs/test/([a-z0-9_-]+)$#i', $path, $m) && $method === 'POST') {
        handleTestPlatform($m[1]);
    } else {
        errorResponse('Route non trouvée [rs-v2 path=' . $path . ' method=' . $method . ']', 404);
    }
}

// ── Token longue durée Instagram (60 jours) ───────────────────────────────────

function handleFbRefreshToken() {
    requireAuth(['admin']);
    $token     = getSetting('rs_facebook_token');
    $appId     = getSetting('rs_facebook_app_id');
    $appSecret = getSetting('rs_facebook_app_secret');
    if (empty($token))     errorResponse('Token Facebook non configuré', 412);
    if (empty($appId))     errorResponse('App ID non configuré', 412);
    if (empty($appSecret)) errorResponse('App Secret non configuré', 412);

    $url  = "https://graph.facebook.com/oauth/access_token?" . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $appSecret,
        'fb_exchange_token' => $token,
    ]);
    $data = apiCurl($url);
    if (isset($data['error'])) errorResponse($data['error']['message'] ?? 'Erreur échange token', 400);

    $newToken  = $data['access_token'] ?? '';
    if (empty($newToken)) errorResponse('access_token manquant dans la réponse', 500);
    $expiresAt = isset($data['expires_in']) ? date('d/m/Y', time() + (int)$data['expires_in']) : '?';

    getDb()->prepare("INSERT OR REPLACE INTO settings (key, value, label, section, type) VALUES ('rs_facebook_token', ?, 'Access Token Facebook', 'facebook', 'password')")
           ->execute([$newToken]);

    jsonResponse(['ok' => true, 'expires' => $expiresAt]);
}

function handleIgRefreshToken() {
    requireAuth(['admin']);

    $token     = getSetting('rs_instagram_token');
    $appId     = getSetting('rs_instagram_app_id');
    $appSecret = getSetting('rs_instagram_app_secret');

    if (empty($token))     { errorResponse('Token Instagram non configuré', 412); }
    if (empty($appId))     { errorResponse('App ID Instagram non configuré (rs_instagram_app_id)', 412); }
    if (empty($appSecret)) { errorResponse('App Secret Instagram non configuré (rs_instagram_app_secret)', 412); }

    $url = "https://graph.facebook.com/oauth/access_token?"
         . http_build_query([
             'grant_type'        => 'fb_exchange_token',
             'client_id'         => $appId,
             'client_secret'     => $appSecret,
             'fb_exchange_token' => $token,
           ]);

    $data = apiCurl($url);

    if (isset($data['error'])) {
        $msg = $data['error']['message'] ?? 'Erreur lors de l\'échange du token';
        errorResponse($msg, 400);
    }

    $newToken = $data['access_token'] ?? '';
    if (empty($newToken)) {
        errorResponse('Réponse invalide — access_token manquant', 500);
    }

    $expiresIn  = (int)($data['expires_in'] ?? 0);
    $expiresAt  = $expiresIn > 0 ? date('d/m/Y', time() + $expiresIn) : '?';

    $db = getDb();
    $db->prepare("INSERT OR REPLACE INTO settings (key, value, label, section, type) VALUES ('rs_instagram_token', ?, 'Access Token Instagram', 'instagram', 'password')")
       ->execute([$newToken]);

    jsonResponse(['ok' => true, 'expires' => $expiresAt, 'message' => 'Token longue durée sauvegardé — expire le ' . $expiresAt]);
}

// ── Token longue durée Threads (60 jours) ────────────────────────────────────
function handleThreadsRefreshToken() {
    requireAuth(['admin']);

    $token     = getSetting('rs_threads_token');
    $appId     = getSetting('rs_threads_app_id');
    $appSecret = getSetting('rs_threads_app_secret');

    if (empty($token))     errorResponse('Token Threads non configuré', 412);
    if (empty($appId))     errorResponse('App ID non configuré (rs_threads_app_id)', 412);
    if (empty($appSecret)) errorResponse('App Secret non configuré (rs_threads_app_secret)', 412);

    // Essai 1 : échange short-lived → long-lived via graph.threads.net
    $url = "https://graph.threads.net/access_token?" . http_build_query([
        'grant_type'    => 'th_exchange_token',
        'client_id'     => $appId,
        'client_secret' => $appSecret,
        'access_token'  => $token,
    ]);
    $data = apiCurl($url);

    // Si le token est déjà long-lived, utiliser refresh à la place
    if (isset($data['error'])) {
        $url2 = "https://graph.threads.net/refresh_access_token?" . http_build_query([
            'grant_type'   => 'th_refresh_token',
            'access_token' => $token,
        ]);
        $data = apiCurl($url2);
    }

    if (isset($data['error'])) {
        $msg = $data['error']['message'] ?? ($data['error_message'] ?? 'Erreur lors de l\'échange du token');
        errorResponse($msg, 400);
    }

    $newToken  = $data['access_token'] ?? '';
    if (empty($newToken)) errorResponse('Réponse invalide — access_token manquant', 500);

    $expiresIn = (int)($data['expires_in'] ?? 0);
    $expiresAt = $expiresIn > 0 ? date('d/m/Y', time() + $expiresIn) : '?';

    $db = getDb();
    $db->prepare("INSERT OR REPLACE INTO settings (key, value, label, section, type) VALUES ('rs_threads_token', ?, 'Access Token Threads', 'threads', 'password')")
       ->execute([$newToken]);

    jsonResponse(['ok' => true, 'expires' => $expiresAt, 'message' => 'Token Threads sauvegardé — expire le ' . $expiresAt]);
}

// ── Diagnostic Threads ────────────────────────────────────────────────────────
function diagnoseThreads(): array {
    $token  = getSetting('rs_threads_token');
    $userId = getSetting('rs_threads_userid');

    if (empty($token))  return ['ok' => false, 'error' => 'Token Threads non configuré', 'fix' => 'Renseigner Access Token dans RS Settings → Threads'];
    if (empty($userId)) return ['ok' => false, 'error' => 'Threads User ID non configuré', 'fix' => 'GET https://graph.threads.net/v1.0/me?fields=id&access_token={token}'];

    $me = apiCurl("https://graph.threads.net/v1.0/me?fields=id,username&access_token=" . urlencode($token));

    if (isset($me['error'])) {
        $msg = $me['error']['message'] ?? 'Erreur API Threads';
        $code = $me['error']['code'] ?? 0;
        if ($code == 190) return ['ok' => false, 'error' => 'Token expiré ou invalide', 'fix' => 'Utiliser le bouton ↻ pour renouveler le token'];
        return ['ok' => false, 'error' => $msg];
    }

    $username = $me['username'] ?? $me['id'] ?? '?';
    return ['ok' => true, 'message' => "Connecté · @{$username} (ID {$userId})"];
}

function _eb80_orig_apiCurl(string $url, array $opts = []): array {
    $ch = curl_init();
    $defaults = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ];
    curl_setopt_array($ch, $opts + $defaults);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($errno || !$body) {
        $msg = match($errno) {
            28 => 'Timeout — impossible de joindre l\'API depuis ce serveur (réseau local ?)',
            7  => 'Connexion refusée — serveur API inaccessible',
            6  => 'Impossible de résoudre l\'hôte DNS',
            35 => 'Erreur SSL/TLS',
            default => 'Erreur réseau cURL #'.$errno,
        };
        return ['error' => ['message' => $msg]];
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : ['error' => ['message' => 'Réponse non-JSON']];
}

function apiPost(string $url, array $data, string $token = ''): array {
    $body = json_encode($data);
    $hdrs = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $hdrs[] = 'Authorization: Bearer ' . $token;
    return apiCurl($url, [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $hdrs,
    ]);
}

function diagnosePlatform(string $platform): array {
    switch (strtolower($platform)) {
        case 'instagram': return diagnoseInstagram();
        case 'facebook':  return diagnoseFacebook();
        case 'tiktok':    return diagnoseTikTok();
        case 'threads':   return diagnoseThreads();
        default:          return ['ok' => false, 'error' => 'Plateforme non supportée : ' . $platform];
    }
}

function diagnoseInstagram(): array {
    $igToken = getSetting('rs_instagram_token');
    if (empty($igToken) || strlen($igToken) < 20) return ['ok' => false, 'error' => 'Token non configuré'];

    $quickOpts = [CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4];

    // 1 — Valider le token
    $check = apiCurl("https://graph.facebook.com/debug_token?input_token=" . urlencode($igToken) . "&access_token=" . urlencode($igToken), $quickOpts);
    if (isset($check['error'])) return ['ok' => false, 'error' => 'Token invalide : ' . ($check['error']['message'] ?? 'erreur Meta')];
    if (!($check['data']['is_valid'] ?? false)) return ['ok' => false, 'error' => 'Token expiré ou révoqué — régénérez-le dans Graph API Explorer'];

    $scopes     = array_column($check['data']['granular_scopes'] ?? [], 'scope');
    $canPublish = in_array('instagram_content_publish', $scopes);
    $hasBasic   = in_array('instagram_basic', $scopes);
    $expiresAt  = date('d/m/Y', $check['data']['expires_at'] ?? 0);

    // 2 — Résoudre l'IG Business Account ID
    $igUserId = resolveIgBusinessId($igToken);

    if (empty($igUserId)) {
        return ['ok' => false,
            'error'      => 'Instagram Business Account ID introuvable',
            'scopes'     => $scopes,
            'expires_at' => $expiresAt,
            'fix'        => 'Liez votre compte Instagram Business à votre Page Facebook dans Meta Business Suite, puis renseignez manuellement l\'IG User ID dans RS Settings → Instagram → Instagram User ID (fallback).',
        ];
    }

    // 3 — Vérifier que l'IG account est accessible
    $igCheck = apiCurl("https://graph.facebook.com/v21.0/{$igUserId}?fields=id,username,name,biography&access_token=" . urlencode($igToken), $quickOpts);
    if (isset($igCheck['error'])) {
        return ['ok' => false,
            'error'        => 'IG Business Account inaccessible : ' . ($igCheck['error']['message'] ?? 'erreur'),
            'ig_user_id'   => $igUserId,
            'fix'          => 'L\'IG User ID ' . $igUserId . ' est peut-être incorrect. Vérifiez-le dans RS Settings → Instagram → Instagram User ID (fallback).',
        ];
    }

    $msg = 'Token valide ✓';
    $msg .= ' — @' . ($igCheck['username'] ?? $igUserId);
    $msg .= ' (Instagram Business)';
    if (!$canPublish) $msg .= ' — ⚠ permission instagram_content_publish manquante';
    if (!$hasBasic)   $msg .= ' — ⚠ permission instagram_basic manquante';

    return [
        'ok'          => $canPublish,
        'can_publish' => $canPublish,
        'ig_user_id'  => $igUserId,
        'ig_username' => $igCheck['username'] ?? '',
        'scopes'      => $scopes,
        'expires_at'  => $expiresAt,
        'message'     => $msg,
        'fix'         => $canPublish ? null : 'Régénérez le token en cochant instagram_content_publish dans Graph API Explorer → Permissions',
    ];
}

function diagnoseFacebook(): array {
    $fbToken  = getSetting('rs_facebook_token');
    $fbPageId = getSetting('rs_facebook_pageid');
    if (empty($fbToken)) return ['ok' => false, 'error' => 'Token non configuré'];
    if (str_contains($fbToken, '|') && preg_match('/^\d+\|/', $fbToken)) {
        return ['ok' => false, 'error' => "App Token détecté — invalide pour poster.", 'fix' => "developers.facebook.com/tools/explorer → permissions pages_show_list + pages_manage_posts → token EAA..."];
    }
    $quickOpts = [CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3];
    $check = apiCurl("https://graph.facebook.com/v21.0/me?access_token=".urlencode($fbToken), $quickOpts);
    if (isset($check['error'])) return ['ok' => false, 'error' => $check['error']['message'] ?? 'Token invalide'];
    $pages    = apiCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token&access_token=".urlencode($fbToken), $quickOpts);
    $pageList = $pages['data'] ?? [];
    if (isset($pages['error'])) return ['ok' => false, 'error' => 'Erreur pages: ' . ($pages['error']['message'] ?? 'inconnu')];
    if (empty($pageList)) {
        if (!empty($fbPageId)) {
            $directPage = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}?fields=id,name,access_token&access_token=".urlencode($fbToken), $quickOpts);
            if (!isset($directPage['error']) && !empty($directPage['id'])) return ['ok' => true, 'can_publish' => true, 'page_name' => $directPage['name'] ?? $fbPageId, 'page_id' => $directPage['id'], 'message' => "Connecté à la page \"{$directPage['name']}\" ✓ (Business Manager)"];
            return ['ok' => true, 'can_publish' => true, 'page_id' => $fbPageId, 'page_name' => 'Page '.$fbPageId, 'message' => "Token valide ✓ — page {$fbPageId}"];
        }
        return ['ok' => false, 'error' => 'Aucune Page Facebook accessible. Configurez le Page ID.'];
    }
    $targetPage = null;
    foreach ($pageList as $p) { if (empty($fbPageId) || $p['id'] == $fbPageId) { $targetPage = $p; break; } }
    if (!$targetPage) return ['ok' => false, 'error' => "Page ID {$fbPageId} non trouvée.", 'pages' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $pageList)];
    return ['ok' => true, 'can_publish' => true, 'page_name' => $targetPage['name'], 'page_id' => $targetPage['id'], 'message' => "Connecté à la page \"{$targetPage['name']}\" ✓"];
}

/**
 * Résout le vrai Instagram Business/Creator Account ID.
 *
 * Ordre de priorité :
 * 1. rs_instagram_accountid   — saisi manuellement dans RS Settings (le plus fiable)
 * 2. rs_instagram_userid      — mis en cache automatiquement lors d'une résolution précédente
 * 3. Page Facebook → champ instagram_business_account (méthode officielle Meta)
 * 4. debug_token → granular_scopes → instagram_basic → target_ids (fallback)
 */
function resolveIgBusinessId(string $igToken): string {
    $db = getDb();

    // 1 — ID saisi manuellement
    $s1 = $db->prepare("SELECT value FROM settings WHERE key='rs_instagram_accountid' LIMIT 1");
    $s1->execute();
    $manual = trim($s1->fetchColumn() ?: '');
    if (!empty($manual) && is_numeric($manual)) return $manual;

    // 2 — Cache résolution précédente
    $s2 = $db->prepare("SELECT value FROM settings WHERE key='rs_instagram_userid' LIMIT 1");
    $s2->execute();
    $cached = trim($s2->fetchColumn() ?: '');
    if (!empty($cached) && is_numeric($cached)) return $cached;

    // 3 — Via la Page Facebook (méthode officielle : /{page-id}?fields=instagram_business_account)
    $fbPageId = getSetting('rs_facebook_pageid');
    if (!empty($fbPageId)) {
        // Récupérer le page access token
        $pageToken = $igToken;
        $pages = apiCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,access_token&access_token=" . urlencode($igToken));
        foreach ($pages['data'] ?? [] as $p) {
            if ($p['id'] == $fbPageId) { $pageToken = $p['access_token'] ?? $igToken; break; }
        }
        $pageData = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}?fields=instagram_business_account&access_token=" . urlencode($pageToken));
        $igId = $pageData['instagram_business_account']['id'] ?? '';
        if (!empty($igId) && is_numeric($igId)) {
            $db->prepare("INSERT OR REPLACE INTO settings(key,value,label,section,type) VALUES('rs_instagram_userid',?,'Instagram Business Account ID (auto)','instagram','text')")->execute([$igId]);
            return $igId;
        }
    }

    // 4 — debug_token → granular_scopes → instagram_basic → target_ids
    try {
        $debug = apiCurl("https://graph.facebook.com/debug_token?input_token=" . urlencode($igToken) . "&access_token=" . urlencode($igToken));
        foreach ($debug['data']['granular_scopes'] ?? [] as $s) {
            if ($s['scope'] === 'instagram_basic' && !empty($s['target_ids'][0])) {
                $igId = (string)$s['target_ids'][0];
                $db->prepare("INSERT OR REPLACE INTO settings(key,value,label,section,type) VALUES('rs_instagram_userid',?,'Instagram Business Account ID (auto)','instagram','text')")->execute([$igId]);
                return $igId;
            }
        }
    } catch (\Throwable $_) {}

    return '';
}

function _igPublishMedia(array $payload): array {
    $igToken  = getSetting('rs_instagram_token');
    if (empty($igToken) || strlen($igToken) < 20) return ['ok' => false, 'error' => 'Token Instagram non configuré.'];

    $igUserId = resolveIgBusinessId($igToken);
    if (empty($igUserId)) {
        return ['ok' => false,
            'error' => 'Instagram Business Account ID introuvable.',
            'fix'   => '1) Entrez l\'IG User ID manuellement dans RS Settings → Instagram → Instagram User ID (fallback). ' .
                       '2) Ou liez votre compte Instagram Business à votre Page Facebook dans Meta Business Suite.'];
    }

    $payload['access_token'] = $igToken;
    $container = apiPost("https://graph.facebook.com/v21.0/{$igUserId}/media", $payload);
    if (isset($container['error'])) {
        $msg  = $container['error']['error_user_msg'] ?? $container['error']['message'] ?? 'Erreur';
        $code = $container['error']['code'] ?? 0;
        $fix  = '';
        if ($code === 10 || $code === 200)  $fix = 'Permission manquante : ajoutez instagram_content_publish à votre token.';
        if ($code === 24)                   $fix = 'Compte Instagram non connecté à cette Page Facebook.';
        if ($code === 9007)                 $fix = 'Compte Instagram non professionnel — passez en compte Business ou Créateur.';
        return ['ok' => false, 'error' => "Instagram container (#{$code}): {$msg}", 'fix' => $fix, 'ig_user_id' => $igUserId];
    }
    $creationId = $container['id'] ?? null;
    if (!$creationId) return ['ok' => false, 'error' => 'Instagram: container créé sans ID', 'ig_user_id' => $igUserId];

    // Attendre que le media soit prêt (max 30 secondes)
    $ready = false; $lastStatus = 'PENDING';
    for ($i = 0; $i < 15; $i++) {
        sleep(2);
        $poll = apiCurl("https://graph.facebook.com/v21.0/{$creationId}?fields=status_code&access_token=" . urlencode($igToken));
        $lastStatus = $poll['status_code'] ?? 'PENDING';
        if ($lastStatus === 'FINISHED') { $ready = true; break; }
        if ($lastStatus === 'ERROR')    { break; }
    }
    if (!$ready) return ['ok' => false, 'error' => "Instagram: traitement échoué (status: {$lastStatus})", 'ig_user_id' => $igUserId];

    $publish = apiPost("https://graph.facebook.com/v21.0/{$igUserId}/media_publish", ['creation_id' => $creationId, 'access_token' => $igToken]);
    if (isset($publish['error'])) return ['ok' => false, 'error' => 'Instagram publish: ' . ($publish['error']['message'] ?? 'Erreur'), 'ig_user_id' => $igUserId];
    return ['ok' => true, 'post_id' => $publish['id'] ?? null];
}

function publishInstagramStory(string $mediaUrl): array {
    if (empty($mediaUrl)) return ['ok' => false, 'error' => 'Story Instagram : URL requise.'];
    $host = strtolower(parse_url($mediaUrl, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost','127.0.0.1']) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) return ['ok' => false, 'error' => 'Story Instagram : URL locale non accessible.'];
    $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
    $payload = in_array($ext, ['mp4','mov','webm']) ? ['media_type' => 'STORIES', 'video_url' => $mediaUrl] : ['media_type' => 'STORIES', 'image_url' => $mediaUrl];
    $res = _igPublishMedia($payload);
    return $res['ok'] ? ['ok' => true, 'message' => 'Story Instagram publiée ✓', 'post_id' => $res['post_id']] : $res;
}

function publishInstagramReel(string $caption, string $videoUrl): array {
    if (empty($videoUrl)) return ['ok' => false, 'error' => 'Reel Instagram : URL vidéo requise.'];
    $host = strtolower(parse_url($videoUrl, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost','127.0.0.1']) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) return ['ok' => false, 'error' => 'Reel Instagram : URL locale non accessible.'];
    $res = _igPublishMedia(['media_type' => 'REELS', 'video_url' => $videoUrl, 'caption' => $caption, 'share_to_feed' => true]);
    return $res['ok'] ? ['ok' => true, 'message' => 'Reel Instagram publié ✓', 'post_id' => $res['post_id'], 'url' => $res['post_id'] ? "https://www.instagram.com/p/{$res['post_id']}/" : null] : $res;
}

function _fbGetPageToken(): array {
    $fbToken  = getSetting('rs_facebook_token');
    $fbPageId = getSetting('rs_facebook_pageid');
    if (empty($fbToken) || empty($fbPageId)) return ['ok' => false, 'error' => 'Token ou Page ID Facebook non configuré.'];
    $pageToken = $fbToken;
    $pages = apiCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token&access_token=".urlencode($fbToken));
    foreach ($pages['data'] ?? [] as $p) { if ($p['id'] == $fbPageId) { $pageToken = $p['access_token'] ?? $fbToken; break; } }
    if ($pageToken === $fbToken) {
        $direct = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}?fields=access_token&access_token=".urlencode($fbToken));
        if (!empty($direct['access_token'])) $pageToken = $direct['access_token'];
    }
    return ['ok' => true, 'token' => $pageToken, 'page_id' => $fbPageId];
}

function publishFacebookStory(string $mediaUrl): array {
    if (empty($mediaUrl)) return ['ok' => false, 'error' => 'Story Facebook : URL requise.'];
    $host = strtolower(parse_url($mediaUrl, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost','127.0.0.1']) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) return ['ok' => false, 'error' => 'Story Facebook : URL locale non accessible.'];
    $fb = _fbGetPageToken();
    if (!$fb['ok']) return $fb;
    $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
    $isVideo = in_array($ext, ['mp4','mov','webm']);
    $res = $isVideo
        ? apiPost("https://graph.facebook.com/v21.0/{$fb['page_id']}/video_stories", ['video_state' => 'PUBLISHED', 'video_url' => $mediaUrl, 'access_token' => $fb['token']])
        : apiPost("https://graph.facebook.com/v21.0/{$fb['page_id']}/photo_stories", ['url' => $mediaUrl, 'access_token' => $fb['token']]);
    if (isset($res['error'])) return ['ok' => false, 'error' => 'Facebook Story: ' . ($res['error']['message'] ?? 'Erreur')];
    return ['ok' => true, 'message' => 'Story Facebook publiée ✓', 'post_id' => $res['post_id'] ?? $res['id'] ?? null];
}

function publishFacebookReel(string $caption, string $videoUrl): array {
    if (empty($videoUrl)) return ['ok' => false, 'error' => 'Reel Facebook : URL vidéo requise.'];
    $host = strtolower(parse_url($videoUrl, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost','127.0.0.1']) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) return ['ok' => false, 'error' => 'Reel Facebook : URL locale non accessible.'];
    $fb = _fbGetPageToken();
    if (!$fb['ok']) return $fb;
    $res = apiPost("https://graph.facebook.com/v21.0/{$fb['page_id']}/video_reels", ['video_url' => $videoUrl, 'description' => $caption, 'video_state' => 'PUBLISHED', 'access_token' => $fb['token']]);
    if (isset($res['error'])) return ['ok' => false, 'error' => 'Facebook Reel: ' . ($res['error']['message'] ?? 'Erreur')];
    return ['ok' => true, 'message' => 'Reel Facebook publié ✓', 'post_id' => $res['post_id'] ?? $res['id'] ?? null];
}

function diagnoseTikTok(): array {
    $token = getSetting('rs_tiktok_access_token');
    if (empty($token)) return ['ok' => false, 'error' => 'Non connecté. Cliquez "Connecter TikTok" dans les paramètres RS.'];
    $res = apiCurl('https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,avatar_url', [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    if (!empty($res['error']['code']) && $res['error']['code'] !== 'ok') return ['ok' => false, 'error' => 'TikTok: ' . ($res['error']['message'] ?? 'Token invalide')];
    $user = $res['data']['user'] ?? [];
    return ['ok' => true, 'message' => "Connecté à TikTok en tant que \"{$user['display_name']}\" ✓", 'user' => $user];
}

function _eb80_orig_refreshTikTokToken(): bool {
    $refreshToken = getSetting('rs_tiktok_refresh_token');
    $clientKey    = getSetting('rs_tiktok_client_key');
    $clientSecret = getSetting('rs_tiktok_client_secret');
    if (empty($refreshToken) || empty($clientKey) || empty($clientSecret)) return false;
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => 'https://open.tiktokapis.com/v2/oauth/token/', CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['client_key' => $clientKey, 'client_secret' => $clientSecret, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]), CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);    $data = json_decode($body, true);
    if (empty($data['access_token'])) return false;
    $db = getDb();
    foreach (['rs_tiktok_access_token' => $data['access_token'], 'rs_tiktok_refresh_token' => $data['refresh_token'] ?? $refreshToken] as $key => $val) {
        $db->prepare("INSERT OR REPLACE INTO settings (key,value,label,section,type) VALUES (?,?,?,?,'text')")->execute([$key, $val, $key, 'tiktok']);
    }
    return true;
}

function publishTikTok(string $message, string $imageUrl): array {
    $token = getSetting('rs_tiktok_access_token');
    if (empty($token)) return ['ok' => false, 'error' => 'TikTok non connecté.'];
    $useImage = false;
    if ($imageUrl) {
        $host = strtolower(parse_url($imageUrl, PHP_URL_HOST) ?? '');
        $useImage = !in_array($host, ['localhost','127.0.0.1']) && !str_ends_with($host, '.test') && !str_ends_with($host, '.local');
    }
    if (!$useImage) return ['ok' => false, 'error' => 'TikTok requiert une URL publique (https://).'];
    $ext = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
    $isVideo = in_array($ext, ['mp4','mov','webm','avi']);
    $hdrs = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    if ($isVideo) {
        $payload = ['post_info' => ['title' => mb_substr($message, 0, 150), 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => false, 'disable_comment' => false, 'disable_stitch' => false], 'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $imageUrl]];
        $res = apiPost('https://open.tiktokapis.com/v2/post/publish/video/init/', $payload, $token);
    } else {
        $payload = ['media_type' => 'PHOTO', 'post_info' => ['title' => mb_substr($message, 0, 150), 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_comment' => false], 'source_info' => ['source' => 'PULL_FROM_URL', 'photo_images' => [$imageUrl], 'photo_cover_index' => 0]];
        $res = apiPost('https://open.tiktokapis.com/v2/post/publish/content/init/', $payload, $token);
    }
    if (!empty($res['error']['code']) && str_contains($res['error']['message'] ?? '', 'access_token')) {
        if (refreshTikTokToken()) { $token = getSetting('rs_tiktok_access_token'); $res = $isVideo ? apiPost('https://open.tiktokapis.com/v2/post/publish/video/init/', $payload, $token) : apiPost('https://open.tiktokapis.com/v2/post/publish/content/init/', $payload, $token); }
    }
    if (!empty($res['error']['code']) && $res['error']['code'] !== 'ok') return ['ok' => false, 'error' => 'TikTok: ' . ($res['error']['message'] ?? 'Erreur')];
    return ['ok' => true, 'message' => 'Publié sur TikTok ✓' . ($isVideo ? ' (vidéo)' : ' (photo)'), 'publish_id' => $res['data']['publish_id'] ?? null];
}

function handleDiagnose() {
    requireAuth(['admin']);
    jsonResponse(['platforms' => ['instagram' => diagnoseInstagram(), 'facebook' => diagnoseFacebook(), 'tiktok' => diagnoseTikTok()]]);
}

function handlePublish(array $input) {
    requireAuth(['admin']);
    $message     = trim($input['message'] ?? '');
    $messages    = $input['messages'] ?? [];   // textes par réseau {"facebook":"…","instagram":"…"}
    $imageUrl    = trim($input['image_url'] ?? '');
    $videoUrl    = trim($input['video_url'] ?? '');
    $networks    = $input['networks'] ?? [];
    $contentType = strtolower(trim($input['content_type'] ?? 'post'));
    if (empty($networks)) errorResponse('Au moins un réseau requis', 400);
    $mediaUrl = $videoUrl ?: $imageUrl;
    $results = [];
    foreach ($networks as $network) {
        $net = strtolower($network);
        $msg = trim($messages[$net] ?? $messages[$network] ?? $message);
        if (empty($msg) && $contentType === 'post') { $results[$net] = ['ok' => false, 'error' => 'Message vide pour ' . $network]; continue; }
        switch ($net) {
            case 'facebook':  $results['facebook']  = match($contentType) { 'story' => publishFacebookStory($mediaUrl), 'reel' => publishFacebookReel($msg, $mediaUrl), default => publishFacebook($msg, $imageUrl) }; break;
            case 'instagram': $results['instagram'] = match($contentType) { 'story' => publishInstagramStory($mediaUrl), 'reel' => publishInstagramReel($msg, $mediaUrl), default => publishInstagram($msg, $imageUrl) }; break;
            case 'tiktok':    $results['tiktok']    = publishTikTok($msg, $mediaUrl); break;
            case 'threads':   $results['threads']   = publishThreads($msg, $imageUrl, $videoUrl, $contentType); break;
            default:          $results[$net]        = ['ok' => false, 'error' => 'Publication non supportée pour ' . $network];
        }
    }
    jsonResponse(['results' => $results]);
}

function publishFacebook(string $message, string $imageUrl): array {
    $fbToken  = getSetting('rs_facebook_token');
    $fbPageId = getSetting('rs_facebook_pageid');
    if (empty($fbToken)) return ['ok' => false, 'error' => 'Token Facebook non configuré.'];
    if (str_contains($fbToken, '|') && preg_match('/^\d+\|/', $fbToken)) return ['ok' => false, 'error' => "Token invalide (App Token). Utilisez un User Token avec pages_manage_posts.", 'fix' => "developers.facebook.com/tools/explorer → permissions pages_show_list, pages_manage_posts → token EAA..."];
    $pages = apiCurl("https://graph.facebook.com/v21.0/me/accounts?access_token=".urlencode($fbToken));
    $pageList = $pages['data'] ?? [];
    if (isset($pages['error'])) return ['ok' => false, 'error' => 'Erreur Facebook: ' . ($pages['error']['message'] ?? 'Token invalide')];
    $targetPage = null;
    foreach ($pageList as $p) { if (empty($fbPageId) || $p['id'] == $fbPageId) { $targetPage = $p; break; } }
    if (!$targetPage) {
        if (!empty($fbPageId)) {
            $directPage = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}?fields=id,name,access_token&access_token=".urlencode($fbToken));
            if (!isset($directPage['error']) && !empty($directPage['id'])) $targetPage = $directPage;
        }
        if (!$targetPage) { $targetPage = !empty($fbPageId) ? ['id' => $fbPageId, 'name' => 'Page '.$fbPageId, 'access_token' => $fbToken] : null; }
        if (!$targetPage) return ['ok' => false, 'error' => 'Aucune page Facebook accessible.'];
    }
    $pageToken = $targetPage['access_token'] ?? $fbToken;
    $pageId    = $targetPage['id'];
    $useImage  = false;
    if ($imageUrl) { $host = strtolower(parse_url($imageUrl, PHP_URL_HOST) ?? ''); $useImage = !in_array($host, ['localhost','127.0.0.1']) && !str_ends_with($host, '.test') && !str_ends_with($host, '.local'); }
    $data = $useImage
        ? apiPost("https://graph.facebook.com/v21.0/{$pageId}/photos", ['url' => $imageUrl, 'caption' => $message, 'access_token' => $pageToken])
        : apiPost("https://graph.facebook.com/v21.0/{$pageId}/feed",   ['message' => $message, 'access_token' => $pageToken]);
    if (isset($data['error'])) return ['ok' => false, 'error' => 'Facebook: ' . ($data['error']['message'] ?? 'Erreur'), 'code' => $data['error']['code'] ?? 0];
    return ['ok' => true, 'message' => 'Publié sur Facebook ✓' . (!$useImage && $imageUrl ? ' (image ignorée — serveur local)' : ''), 'post_id' => $data['post_id'] ?? $data['id'] ?? null, 'page' => $targetPage['name'] ?? $pageId];
}

function publishInstagram(string $message, string $imageUrl): array {
    if (empty($imageUrl)) return ['ok' => false, 'error' => 'Instagram requiert un visuel pour publier.'];
    $host = strtolower(parse_url($imageUrl, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost','127.0.0.1']) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
        return ['ok' => false, 'error' => "Image hébergée localement ({$host}) — Instagram ne peut pas y accéder.", 'fix' => 'Sauvegardez l\'image en médiathèque (💾) puis publiez depuis l\'onglet Média.'];
    }
    $res = _igPublishMedia(['image_url' => $imageUrl, 'caption' => $message]);
    return $res['ok']
        ? ['ok' => true, 'message' => 'Publié sur Instagram ✓', 'post_id' => $res['post_id'], 'url' => $res['post_id'] ? "https://www.instagram.com/p/{$res['post_id']}/" : null]
        : $res;
}

function publishThreads(string $message, string $imageUrl = '', string $videoUrl = '', string $contentType = 'post'): array {
    $token  = getSetting('rs_threads_token');
    $userId = getSetting('rs_threads_userid');
    if (empty($token))  return ['ok' => false, 'error' => 'Token Threads non configuré.', 'fix' => 'Renseigner l\'Access Token dans API & Réseaux → Réseaux sociaux → Threads'];
    if (empty($userId)) return ['ok' => false, 'error' => 'Threads User ID non configuré.', 'fix' => 'GET https://graph.threads.net/v1.0/me?fields=id&access_token={token}'];
    if ($contentType === 'story') return ['ok' => false, 'error' => 'Threads ne gère pas les Stories.'];

    $isLocal = function (string $url): bool {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        return in_array($host, ['localhost', '127.0.0.1']) || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    };
    $text = mb_substr($message, 0, 500);
    $payload = ['media_type' => 'TEXT', 'text' => $text, 'access_token' => $token];
    $note = '';
    if ($videoUrl && !$isLocal($videoUrl)) {
        $payload['media_type'] = 'VIDEO'; $payload['video_url'] = $videoUrl;
    } elseif ($imageUrl && !$isLocal($imageUrl)) {
        $payload['media_type'] = 'IMAGE'; $payload['image_url'] = $imageUrl;
    } elseif ($imageUrl || $videoUrl) {
        $note = ' (visuel ignoré — serveur local)';
    }
    if ($payload['media_type'] === 'TEXT' && $text === '') return ['ok' => false, 'error' => 'Threads requiert un texte ou un visuel.'];

    $container = apiPost("https://graph.threads.net/v1.0/{$userId}/threads", $payload);
    if (isset($container['error'])) {
        $code = $container['error']['code'] ?? 0;
        $fix  = $code == 190 ? 'Token expiré : utiliser le bouton ↻ Convertir en token 60 jours.' : '';
        return ['ok' => false, 'error' => 'Threads: ' . ($container['error']['error_user_msg'] ?? $container['error']['message'] ?? 'Erreur'), 'fix' => $fix];
    }
    $creationId = $container['id'] ?? null;
    if (!$creationId) return ['ok' => false, 'error' => 'Threads: conteneur créé sans ID'];

    $ready = false;
    $tries = $payload['media_type'] === 'VIDEO' ? 20 : 5;
    for ($i = 0; $i < $tries; $i++) {
        sleep($payload['media_type'] === 'TEXT' ? 1 : 3);
        $poll = apiCurl("https://graph.threads.net/v1.0/{$creationId}?fields=status,error_message&access_token=" . urlencode($token));
        $st = $poll['status'] ?? '';
        if ($st === 'FINISHED') { $ready = true; break; }
        if ($st === 'ERROR' || $st === 'EXPIRED') return ['ok' => false, 'error' => 'Threads: ' . ($poll['error_message'] ?? 'traitement du média en échec')];
    }
    if (!$ready) return ['ok' => false, 'error' => 'Threads: média encore en traitement, réessayez dans un instant.'];

    $pub = apiPost("https://graph.threads.net/v1.0/{$userId}/threads_publish", ['creation_id' => $creationId, 'access_token' => $token]);
    if (isset($pub['error'])) return ['ok' => false, 'error' => 'Threads: ' . ($pub['error']['error_user_msg'] ?? $pub['error']['message'] ?? 'Erreur de publication')];
    $postId = $pub['id'] ?? null;
    $permalink = null;
    if ($postId) {
        $info = apiCurl("https://graph.threads.net/v1.0/{$postId}?fields=permalink&access_token=" . urlencode($token));
        $permalink = $info['permalink'] ?? null;
    }
    return ['ok' => true, 'message' => 'Publié sur Threads ✓' . $note, 'post_id' => $postId, 'url' => $permalink];
}

function handleCreateEvent(array $input) {
    requireAuth(['admin']);
    $name = trim($input['name'] ?? ''); $description = trim($input['description'] ?? '');
    $startDate = trim($input['start_date'] ?? ''); $startTime = trim($input['start_time'] ?? '21:00');
    $endDate = trim($input['end_date'] ?? ''); $endTime = trim($input['end_time'] ?? '');
    $location = trim($input['location'] ?? ''); $imageUrl = trim($input['image_url'] ?? '');
    if (empty($name)) errorResponse('Nom requis', 400);
    if (empty($startDate)) errorResponse('Date de début requise', 400);
    jsonResponse(publishFacebookEvent($name, $description, $startDate, $startTime, $endDate, $endTime, $location, $imageUrl));
}

function publishFacebookEvent(string $name, string $description, string $startDate, string $startTime, string $endDate, string $endTime, string $location, string $imageUrl): array {
    $fbToken = getSetting('rs_facebook_token'); $fbPageId = getSetting('rs_facebook_pageid');
    if (empty($fbToken)) return ['ok' => false, 'error' => 'Token Facebook non configuré.'];
    if (empty($fbPageId)) return ['ok' => false, 'error' => 'Page ID Facebook non configuré.'];
    $startDt = $startDate . 'T' . ($startTime ?: '20:00') . ':00';
    $endDt   = $endDate ? $endDate . 'T' . ($endTime ?: '23:00') . ':00' : '';
    $pageToken = $fbToken;
    $pages = apiCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,name,access_token&access_token=".urlencode($fbToken));
    foreach ($pages['data'] ?? [] as $p) { if ($p['id'] == $fbPageId) { $pageToken = $p['access_token'] ?? $fbToken; break; } }
    if ($pageToken === $fbToken) { $d = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}?fields=access_token&access_token=".urlencode($fbToken)); if (!empty($d['access_token'])) $pageToken = $d['access_token']; }
    $payload = ['name' => $name, 'start_time' => $startDt, 'access_token' => $pageToken];
    if ($description) $payload['description'] = $description;
    if ($endDt)       $payload['end_time']     = $endDt;
    if ($location)    $payload['place']         = json_encode(['name' => $location]);
    if ($imageUrl) {
        $host = strtolower(parse_url($imageUrl, PHP_URL_HOST) ?? '');
        if (!in_array($host, ['localhost','127.0.0.1']) && !str_ends_with($host, '.test') && !str_ends_with($host, '.local')) {
            $photoRes = apiPost("https://graph.facebook.com/v21.0/{$fbPageId}/photos", ['url' => $imageUrl, 'published' => false, 'access_token' => $pageToken]);
            if (!empty($photoRes['id'])) $payload['cover'] = json_encode(['offset_x' => 0, 'offset_y' => 0, 'media_fbid' => $photoRes['id']]);
        }
    }
    $result = apiPost("https://graph.facebook.com/v21.0/{$fbPageId}/events", $payload);
    if (isset($result['error'])) { $msg = $result['error']['message'] ?? 'Erreur'; $code = $result['error']['code'] ?? 0; if ($code == 200 || $code == 10) $msg .= ' → Ajoutez pages_manage_events dans votre token.'; return ['ok' => false, 'error' => $msg, 'code' => $code]; }
    return ['ok' => true, 'message' => 'Événement créé sur Facebook ✓', 'event_id' => $result['id'] ?? null, 'url' => isset($result['id']) ? "https://www.facebook.com/events/{$result['id']}/" : null];
}

function handleFbMedia() {
    requireAuth(['admin']);
    $fbToken = getSetting('rs_facebook_token'); $fbPageId = getSetting('rs_facebook_pageid');
    if (empty($fbToken)) errorResponse('Token Facebook non configuré', 412);
    if (empty($fbPageId)) errorResponse('Page ID Facebook non configuré', 412);
    $limit = min((int)($_GET['limit'] ?? 30), 100);
    $pageToken = $fbToken;
    $pages = apiCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,access_token&access_token=".urlencode($fbToken));
    foreach ($pages['data'] ?? [] as $p) { if ($p['id'] == $fbPageId) { $pageToken = $p['access_token'] ?? $fbToken; break; } }
    $fields = 'id,title,description,source,picture,thumbnails,length,created_time';
    $data = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}/videos?fields={$fields}&limit={$limit}&access_token=".urlencode($pageToken));
    if (isset($data['error'])) errorResponse('Facebook: ' . ($data['error']['message'] ?? 'Erreur'), 400);
    $items = array_map(fn($v) => ['id' => $v['id'], 'title' => $v['title'] ?? '', 'description' => $v['description'] ?? '', 'media_url' => $v['source'] ?? '', 'thumbnail_url' => $v['picture'] ?? ($v['thumbnails']['data'][0]['uri'] ?? ''), 'duration' => $v['length'] ?? 0, 'created_time' => $v['created_time'] ?? '', 'media_type' => 'VIDEO', 'platform' => 'facebook'], $data['data'] ?? []);
    jsonResponse(['items' => $items, 'total' => count($items), 'has_next' => isset($data['paging']['next'])]);
}

/**
 * GET /api/rs/media/facebook-photos
 * Liste les photos uploadées sur la Page Facebook (/{page-id}/photos?type=uploaded)
 */
function handleFbPhotos() {
    requireAuth(['admin']);
    $fbToken  = getSetting('rs_facebook_token');
    $fbPageId = getSetting('rs_facebook_pageid');
    if (empty($fbToken))  errorResponse('Token Facebook non configuré. Allez dans RS Settings → Facebook.', 412);
    if (empty($fbPageId)) errorResponse('Page ID Facebook non configuré. Allez dans RS Settings → Facebook.', 412);

    $limit  = min((int)($_GET['limit'] ?? 50), 100);
    $cursor = $_GET['cursor'] ?? '';

    // Résoudre le page access token depuis le user token
    $pageToken = $fbToken;
    $pages = apiCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,access_token&access_token=" . urlencode($fbToken));
    foreach ($pages['data'] ?? [] as $p) {
        if ($p['id'] == $fbPageId) { $pageToken = $p['access_token'] ?? $fbToken; break; }
    }

    $params = http_build_query([
        'type'         => 'uploaded',
        'fields'       => 'id,name,created_time,images',
        'limit'        => $limit,
        'access_token' => $pageToken,
    ]);
    if ($cursor) $params .= '&after=' . urlencode($cursor);

    $data = apiCurl("https://graph.facebook.com/v21.0/{$fbPageId}/photos?{$params}");
    if (isset($data['error'])) errorResponse('Facebook: ' . ($data['error']['message'] ?? 'Erreur'), 400);

    $items = [];
    foreach ($data['data'] ?? [] as $p) {
        $images = $p['images'] ?? [];
        if (empty($images)) continue;
        // Facebook retourne les tailles triées du plus grand au plus petit
        $largeUrl = $images[0]['source'] ?? '';
        $thumbUrl = count($images) > 2 ? $images[count($images) - 2]['source'] : ($images[count($images) - 1]['source'] ?? $largeUrl);
        if (empty($largeUrl)) continue;
        $items[] = [
            'id'           => $p['id'],
            'title'        => $p['name'] ?? '',
            'media_url'    => $largeUrl,
            'display_url'  => $largeUrl,
            'thumbnail_url' => $thumbUrl,
            'created_time' => $p['created_time'] ?? '',
            'media_type'   => 'IMAGE',
            'platform'     => 'facebook',
            'downloadable' => true,
        ];
    }

    $afterCursor = $data['paging']['cursors']['after'] ?? null;
    $hasNext     = isset($data['paging']['next']) || (count($items) >= $limit && $afterCursor);
    jsonResponse([
        'items'    => $items,
        'total'    => count($items),
        'cursor'   => $afterCursor,
        'has_next' => $hasNext,
    ]);
}

function handleTikTokMedia() {
    requireAuth(['admin']);
    $token = getSetting('rs_tiktok_access_token');
    if (empty($token)) errorResponse('TikTok non connecté.', 412);
    $limit = min((int)($_GET['limit'] ?? 20), 20);
    $fields = 'id,title,video_description,cover_image_url,embed_link,share_url,duration,create_time,height,width';
    $res = apiPost('https://open.tiktokapis.com/v2/video/list/?fields=' . urlencode($fields), ['max_count' => $limit], $token);
    if (!empty($res['error']['code']) && str_contains($res['error']['message'] ?? '', 'access_token')) { if (refreshTikTokToken()) { $token = getSetting('rs_tiktok_access_token'); $res = apiPost('https://open.tiktokapis.com/v2/video/list/?fields=' . urlencode($fields), ['max_count' => $limit], $token); } }
    if (!empty($res['error']['code']) && $res['error']['code'] !== 'ok') errorResponse('TikTok: ' . ($res['error']['message'] ?? 'Erreur'), 400);
    $items = array_map(fn($v) => ['id' => $v['id'], 'title' => $v['title'] ?? $v['video_description'] ?? '', 'description' => $v['video_description'] ?? '', 'thumbnail_url' => $v['cover_image_url'] ?? '', 'share_url' => $v['share_url'] ?? $v['embed_link'] ?? '', 'duration' => $v['duration'] ?? 0, 'created_time' => $v['create_time'] ?? '', 'width' => $v['width'] ?? 0, 'height' => $v['height'] ?? 0, 'media_type' => 'VIDEO', 'platform' => 'tiktok', 'downloadable' => false], $res['data']['videos'] ?? []);
    jsonResponse(['items' => $items, 'total' => count($items), 'has_next' => !empty($res['data']['cursor']), 'note' => 'Les vidéos TikTok ne peuvent pas être téléchargées directement via l\'API.']);
}

function handleTestPlatform(string $platform) {
    requireAuth(['admin']);
    try {
        $result = diagnosePlatform($platform);
        if ($result['ok']) { jsonResponse(['ok' => true, 'message' => $result['message'] ?? 'OK']); }
        else { $msg = $result['error'] ?? 'Erreur'; if (!empty($result['fix'])) $msg .= ' → ' . $result['fix']; jsonResponse(['ok' => false, 'message' => $msg]); }
    } catch (\Throwable $e) { jsonResponse(['ok' => false, 'message' => 'Erreur interne: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']); }
}

// ── Génération IA du message RS ───────────────────────────────────────────────
function _eb80_orig_handleGenerateMessage(array $input): void {
    requireAuth(['admin']);

    $networks    = $input['networks']    ?? [];
    $eventName   = trim($input['event_name']  ?? '');
    $eventDate   = trim($input['event_date']  ?? '');
    $eventVenue  = trim($input['event_venue'] ?? '');
    $contentType = trim($input['content_type'] ?? 'post');
    $hint        = trim($input['hint']         ?? '');   // suggestion libre de l'admin

    // Clé API : body > DB (on mémorise le nom de la clé pour connaître le provider)
    $apiKey    = trim($input['api_key'] ?? '');
    $dbKeyName = '';
    if (!$apiKey) {
        $db = getDb();
        foreach (['gemini_api_key', 'anthropic_api_key'] as $k) {
            $sv = $db->prepare("SELECT value FROM settings WHERE key=?");
            $sv->execute([$k]);
            $v = $sv->fetchColumn();
            if ($v) { $apiKey = trim($v); $dbKeyName = $k; break; }
        }
    }
    if (!$apiKey) errorResponse('Clé API non configurée (AIza… pour Gemini ou sk-ant-… pour Claude)', 400);

    // Construire le contexte
    $netsStr  = implode(', ', array_map('ucfirst', $networks)) ?: 'réseaux sociaux';
    $typeStr  = match($contentType) { 'story' => 'Story', 'reel' => 'Reel/vidéo courte', default => 'Post' };
    $ctx      = [];
    if ($eventName)  $ctx[] = "Concert / événement : {$eventName}";
    if ($eventDate)  $ctx[] = "Date : {$eventDate}";
    if ($eventVenue) $ctx[] = "Lieu : {$eventVenue}";
    if ($hint)       $ctx[] = "Indications supplémentaires : {$hint}";
    $ctxBlock = $ctx ? "\nContexte :\n" . implode("\n", $ctx) : '';

    $prompt = "Tu es le community manager d'ELECTROBOY 80, artiste solo de rock new wave synthétique des années 2080."
        . " Rédige un {$typeStr} pour {$netsStr} en français."
        . $ctxBlock
        . "\n\nTon style : percutant, énergie punk-synthwave, sans formules banales."
        . " Pour Instagram/TikTok : max 150 caractères + 3-5 hashtags pertinents."
        . " Pour Facebook : max 280 caractères, plus de contexte, sans hashtags."
        . " Pour Threads : max 200 caractères."
        . " Si plusieurs réseaux, adapte pour le premier et donne une version compacte."
        . " Réponds UNIQUEMENT avec le texte du post, sans guillemets, sans explication.";

    // Provider : nom de la clé DB > préfixe de la clé
    $isGemini = ($dbKeyName === 'gemini_api_key') || str_starts_with($apiKey, 'AIza');

    if ($isGemini) {
        $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($apiKey);
        $payload = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.8, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['content-type: application/json'], CURLOPT_TIMEOUT => 20]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200) {
            $err = json_decode($resp, true);
            errorResponse('Erreur Gemini : ' . ($err['error']['message'] ?? $resp), 502);
        }
        $body = json_decode($resp, true);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) errorResponse('Gemini : réponse vide', 502);
    } else {
        // Claude
        $payload = json_encode(['model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 400,
            'messages' => [['role' => 'user', 'content' => $prompt]]]);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01']]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200) {
            $err = json_decode($resp, true);
            errorResponse('Erreur Claude : ' . ($err['error']['message'] ?? $resp), 502);
        }
        $body = json_decode($resp, true);
        $text = $body['content'][0]['text'] ?? null;
        if (!$text) errorResponse('Claude : réponse vide', 502);
    }

    jsonResponse(['ok' => true, 'message' => trim($text)]);
}
