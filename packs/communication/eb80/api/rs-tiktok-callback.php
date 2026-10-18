<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — TikTok OAuth Callback
 * GET /api/rs/tiktok-callback?code=...&state=...
 *
 * Échange le code d'autorisation contre un access_token + refresh_token
 * et les stocke dans la table settings.
 */

ob_start();
require_once __DIR__ . '/../compat.php';
ob_end_clean();
error_reporting(0);

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    showPage('error', 'TikTok a refusé l\'autorisation : ' . htmlspecialchars($error));
    exit;
}

if (empty($code)) {
    showPage('error', 'Code d\'autorisation manquant.');
    exit;
}

// Récupérer client_key et client_secret depuis la DB
$db = getDb();
$clientKey    = getSettingDirect($db, 'rs_tiktok_client_key');
$clientSecret = getSettingDirect($db, 'rs_tiktok_client_secret');

if (empty($clientKey) || empty($clientSecret)) {
    showPage('error', 'Client Key ou Client Secret non configuré dans les paramètres RS.');
    exit;
}

$redirectUri = appBaseUrl() . app_prefix(plugin_api_url('communication', 'eb80') . '&path=' . rawurlencode('/rs/tiktok-callback'));

// Échanger le code contre un token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://open.tiktokapis.com/v2/oauth/token/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_key'    => $clientKey,
        'client_secret' => $clientSecret,
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => $redirectUri,
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15,
]);
$body  = curl_exec($ch);
$errno = curl_errno($ch);

if ($errno || !$body) {
    showPage('error', 'Erreur réseau lors de l\'échange du token (errno ' . $errno . ').');
    exit;
}

$data = json_decode($body, true);

if (!empty($data['error'])) {
    showPage('error', 'Erreur TikTok : ' . ($data['error_description'] ?? $data['error']));
    exit;
}

$accessToken  = $data['access_token']  ?? '';
$refreshToken = $data['refresh_token'] ?? '';
$openId       = $data['open_id']       ?? '';

if (empty($accessToken) || empty($openId)) {
    showPage('error', 'Réponse TikTok invalide : ' . htmlspecialchars($body));
    exit;
}

// Sauvegarder dans la DB
foreach ([
    'rs_tiktok_access_token'  => $accessToken,
    'rs_tiktok_refresh_token' => $refreshToken,
    'rs_tiktok_open_id'       => $openId,
] as $key => $value) {
    $db->prepare("INSERT OR REPLACE INTO settings (key, value, label, section, type) VALUES (?, ?, ?, 'tiktok', 'text')")
       ->execute([$key, $value, $key]);
}

showPage('success', 'Compte TikTok connecté avec succès ! Open ID : ' . htmlspecialchars($openId));

// ── Helpers ───────────────────────────────────────────────────────────────────

function getSettingDirect(PDO $db, string $key): string {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
    $stmt->execute([$key]);
    return trim($stmt->fetch()['value'] ?? '');
}

function showPage(string $type, string $message): void {
    $color = $type === 'success' ? '#00ff88' : '#ff006e';
    $icon  = $type === 'success' ? '✓' : '✗';
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>
    <title>TikTok OAuth — Electroboy80</title>
    <style>body{background:#000;color:#fff;font-family:'Courier New',monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{text-align:center;padding:40px;border:2px solid {$color};max-width:500px;}
    .icon{font-size:48px;margin-bottom:16px;}
    .msg{color:{$color};font-size:16px;margin-bottom:20px;line-height:1.6;}
    .btn{background:{$color};color:#000;border:none;padding:10px 24px;font-family:inherit;font-size:14px;cursor:pointer;}
    </style></head><body>
    <div class='box'>
      <div class='icon'>{$icon}</div>
      <div class='msg'>{$message}</div>
      <button class='btn' onclick='window.close()'>Fermer et retourner à l'admin</button>
    </div>
    <script>setTimeout(()=>window.close(),3000);</script>
    </body></html>";
}
