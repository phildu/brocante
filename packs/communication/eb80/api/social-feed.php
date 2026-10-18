<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Feed social public (sans auth)
 * GET  /api/social-feed         → posts filtrés selon config admin (cachés 1h)
 * POST /api/social-feed/refresh → invalide le cache (auth admin)
 */

ob_start();
require_once __DIR__ . '/../compat.php';
ob_end_clean();
error_reporting(0);

$path   = getPath();
$method = getMethod();

if ($method === 'POST' && $path === '/social-feed/refresh') {
    requireAuth(['admin']);
    getDb()->prepare("DELETE FROM settings WHERE key='cache_social_feed'")->execute();
    jsonResponse(['ok' => true]);
}

header('Cache-Control: public, max-age=1800');

$raw  = _sfCached();
$data = _sfApplyConfig($raw);
jsonResponse($data);

// ── Appliquer la config admin ─────────────────────────────────────────────────

function _sfApplyConfig(array $raw): array {
    $db = getDb();

    $sc = $db->prepare("SELECT value FROM settings WHERE key='social_feed_config' LIMIT 1");
    $sc->execute();
    $configJson = $sc->fetchColumn();

    $sl = $db->prepare("SELECT value FROM settings WHERE key='social_feed_limit' LIMIT 1");
    $sl->execute();
    $limit = max(1, (int)($sl->fetchColumn() ?: 5));

    $allPosts = array_merge($raw['facebook'] ?? [], $raw['instagram'] ?? []);

    if ($configJson) {
        $config = json_decode($configJson, true) ?: [];
        $order  = array_column($config, null, 'id');

        // Appliquer ordre et visibilité
        $sorted = [];
        foreach ($config as $c) {
            if (($c['visible'] ?? true) === false) continue;
            $found = array_filter($allPosts, fn($p) => $p['id'] === $c['id']);
            if ($found) $sorted[] = array_values($found)[0];
        }
        // Ajouter les nouveaux posts non présents dans la config
        foreach ($allPosts as $p) {
            if (!isset($order[$p['id']])) $sorted[] = $p;
        }
        $posts = array_slice($sorted, 0, $limit);
    } else {
        usort($allPosts, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        $posts = array_slice($allPosts, 0, $limit);
    }

    return array_merge($raw, ['posts' => $posts]);
}

// ── Cache ─────────────────────────────────────────────────────────────────────

function _sfCached(): array {
    $db   = getDb();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key='cache_social_feed' LIMIT 1");
    $stmt->execute();
    $raw  = $stmt->fetchColumn();

    if ($raw) {
        $obj = json_decode($raw, true);
        if ($obj && isset($obj['cached_at']) && (time() - (int)$obj['cached_at']) < 3600) {
            return $obj;
        }
    }

    $fb  = _sfFetchFb($db);
    $ig  = _sfFetchIg($db);

    // Mélanger et trier par date desc, garder 5
    $mixed = array_merge($fb, $ig);
    usort($mixed, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    $mixed = array_slice($mixed, 0, 5);

    $data = ['posts' => $mixed, 'facebook' => $fb, 'instagram' => $ig, 'cached_at' => time()];

    $db->prepare("INSERT OR REPLACE INTO settings(key,value,label,section,type)
                  VALUES('cache_social_feed',?,'Cache feed social','cache','text')")
       ->execute([json_encode($data)]);

    return $data;
}

// ── Facebook ──────────────────────────────────────────────────────────────────

function _sfFetchFb($db): array {
    $token  = getSetting('rs_facebook_token');
    $pageId = getSetting('rs_facebook_pageid');
    if (empty($token) || empty($pageId)) return [];

    try {
        $pages     = _sfCurl("https://graph.facebook.com/v21.0/me/accounts?fields=id,access_token&access_token=" . urlencode($token));
        $pageToken = $token;
        foreach ($pages['data'] ?? [] as $p) {
            if ($p['id'] == $pageId) { $pageToken = $p['access_token'] ?? $token; break; }
        }

        $fields = 'id,message,full_picture,created_time,permalink_url';
        $resp   = _sfCurl("https://graph.facebook.com/v21.0/{$pageId}/posts?fields=" . urlencode($fields) . "&limit=4&access_token=" . urlencode($pageToken));

        $posts = [];
        foreach ($resp['data'] ?? [] as $p) {
            if (empty($p['message']) && empty($p['full_picture'])) continue;
            $posts[] = [
                'id'      => $p['id'],
                'text'    => $p['message'] ?? '',
                'image'   => $p['full_picture'] ?? '',
                'date'    => $p['created_time'] ?? '',
                'url'     => $p['permalink_url'] ?? "https://www.facebook.com/{$pageId}",
                'network' => 'facebook',
            ];
        }
        return $posts;
    } catch (\Throwable $_) {
        return [];
    }
}

// ── Instagram ─────────────────────────────────────────────────────────────────

function _sfFetchIg($db): array {
    $token = getSetting('rs_instagram_token');
    if (empty($token)) return [];

    try {
        $igId = _sfResolveIg($token, $db);
        if (empty($igId)) return [];

        $fields = 'id,caption,media_type,media_url,thumbnail_url,timestamp,permalink';
        $resp   = _sfCurl("https://graph.facebook.com/v21.0/{$igId}/media?fields=" . urlencode($fields) . "&limit=4&access_token=" . urlencode($token));

        $posts = [];
        foreach ($resp['data'] ?? [] as $m) {
            if (!in_array($m['media_type'] ?? '', ['IMAGE', 'CAROUSEL_ALBUM', 'VIDEO'])) continue;
            $img = $m['media_type'] === 'VIDEO'
                ? ($m['thumbnail_url'] ?? $m['media_url'] ?? '')
                : ($m['media_url'] ?? '');
            $posts[] = [
                'id'      => $m['id'],
                'text'    => $m['caption'] ?? '',
                'image'   => $img,
                'date'    => $m['timestamp'] ?? '',
                'url'     => $m['permalink'] ?? 'https://www.instagram.com/',
                'network' => 'instagram',
            ];
        }
        return $posts;
    } catch (\Throwable $_) {
        return [];
    }
}

function _sfResolveIg(string $token, $db): string {
    // 1. ID manuel
    $s = $db->prepare("SELECT value FROM settings WHERE key='rs_instagram_accountid' LIMIT 1");
    $s->execute();
    $v = trim($s->fetchColumn() ?: '');
    if (!empty($v) && is_numeric($v)) return $v;

    // 2. Cache auto
    $s2 = $db->prepare("SELECT value FROM settings WHERE key='rs_instagram_userid' LIMIT 1");
    $s2->execute();
    $v2 = trim($s2->fetchColumn() ?: '');
    if (!empty($v2) && is_numeric($v2)) return $v2;

    // 3. Via Page FB
    $s3 = $db->prepare("SELECT value FROM settings WHERE key='rs_facebook_pageid' LIMIT 1");
    $s3->execute();
    $fbPageId = trim($s3->fetchColumn() ?: '');
    if (!empty($fbPageId)) {
        try {
            $pd   = _sfCurl("https://graph.facebook.com/v21.0/{$fbPageId}?fields=instagram_business_account&access_token=" . urlencode($token));
            $igId = $pd['instagram_business_account']['id'] ?? '';
            if (!empty($igId) && is_numeric($igId)) {
                $db->prepare("INSERT OR REPLACE INTO settings(key,value,label,section,type) VALUES('rs_instagram_userid',?,'Instagram Business Account ID (auto)','instagram','text')")->execute([$igId]);
                return $igId;
            }
        } catch (\Throwable $_) {}
    }

    return '';
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

function _sfCurl(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'ElectroBoy80/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $json = json_decode($body ?: '{}', true);
    return is_array($json) ? $json : [];
}
