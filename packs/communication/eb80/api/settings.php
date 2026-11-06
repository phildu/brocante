<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Settings API
 * GET  /api/settings
 * PATCH /api/settings
 */

require_once __DIR__ . '/../compat.php';

$path = getPath();
$method = getMethod();
$input = getJsonInput();

// Routes
if ($path === '/settings' && $method === 'GET') {
    handleGetSettings();
} elseif ($path === '/settings' && $method === 'PATCH') {
    handlePatchSettings($input);
} elseif ($path === '/settings/test-email' && $method === 'POST') {
    handleTestEmail($input);
} else {
    errorResponse('Route non trouvée', 404);
}

/**
 * GET /api/settings
 * Retourne tous les paramètres
 */
function handleGetSettings() {
    $db = getDb();
    
    // Get all settings
    $stmt = $db->query("SELECT `key`, value, label, section, type FROM settings ORDER BY section, `key`");
    $settings = $stmt->fetchAll();

    // Cette route est publique : un visiteur ne reçoit pas les secrets rangés dans
    // la table (clé Stripe, SMTP, jetons…) — voir reglages-publics.php.
    // L'administration et les collaborateurs habilités reçoivent tout, jeton à l'appui.
    require_once __DIR__ . '/../reglages-publics.php';
    $moi   = getCurrentUser();
    $perms = [];
    if ($moi) {
        $raw   = $moi['permissions'] ?? '[]';
        $perms = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    }
    $complet = $moi && (($moi['role'] ?? '') === 'admin'
                        || array_intersect(['editor', 'community'], $perms));
    if (!$complet) {
        $settings = array_values(array_filter($settings, fn($r) => eb80_reglage_est_public((string) $r['key'])));
    }
    
    // Get media links
    $mediaStmt = $db->query("SELECT * FROM media_links ORDER BY created_at DESC");
    $mediaLinks = $mediaStmt->fetchAll();
    
    // Get events
    $eventsStmt = $db->query("SELECT * FROM events WHERE status = 'upcoming' ORDER BY date DESC");
    $events = $eventsStmt->fetchAll();
    
    // Get content
    $contentStmt = $db->query("SELECT * FROM content WHERE status = 'published' ORDER BY published_at DESC LIMIT 20");
    $content = $contentStmt->fetchAll();
    
    // Format settings as key-value pairs
    $settingsMap = [];
    foreach ($settings as $setting) {
        $settingsMap[$setting['key']] = $setting['value'];
    }
    
    jsonResponse([
        'settings' => $settingsMap,
        'settingsList' => $settings,
        'mediaLinks' => $mediaLinks,
        'events' => $events,
        'content' => $content
    ]);
}

/**
 * PATCH /api/settings
 * Met à jour un ou plusieurs paramètres
 */
function handlePatchSettings($input) {
    $user = requireAuth(['admin']);
    
    $db = getDb();
    $updated = [];
    
    foreach ($input as $key => $value) {
        // Skip non-setting keys
        if (in_array($key, ['mediaLinks', 'events', 'content'])) continue;
        
        $stmt = $db->prepare("UPDATE settings SET value = ? WHERE `key` = ?");
        $stmt->execute([$value, $key]);
        
        if ($stmt->rowCount() > 0) {
            $updated[] = $key;
        } else {
            // Insert if doesn't exist — cross-compatible SQLite / MySQL
            try {
                $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                $insSql = ($drv === 'sqlite')
                    ? "INSERT OR IGNORE INTO settings (key, value, label, section, type) VALUES (?, ?, ?, 'general', 'text')"
                    : "INSERT IGNORE INTO settings (`key`, value, label, section, type) VALUES (?, ?, ?, 'general', 'text')";
                $ins = $db->prepare($insSql);
                $ins->execute([$key, $value, $key]);
                if ($ins->rowCount() > 0) {
                    $updated[] = $key;
                }
            } catch (\Throwable $e) {
                // Key already exists with same value — safe to ignore
            }
        }
    }
    
    // Handle media links
    if (isset($input['mediaLinks']) && is_array($input['mediaLinks'])) {
        $db->exec("DELETE FROM media_links");
        foreach ($input['mediaLinks'] as $link) {
            $stmt = $db->prepare("INSERT INTO media_links (url, title, platform, thumb) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $link['url'] ?? '',
                $link['title'] ?? '',
                $link['platform'] ?? 'direct',
                $link['thumb'] ?? ''
            ]);
        }
    }
    
    // Handle events
    if (isset($input['events']) && is_array($input['events'])) {
        foreach ($input['events'] as $event) {
            if (isset($event['id']) && $event['id']) {
                // Update existing
                $stmt = $db->prepare("UPDATE events SET name = ?, date = ?, time = ?, venue = ?, city = ?, country = ?, ticket_url = ?, poster = ?, description = ?, networks = ?, status = ? WHERE id = ?");
                $stmt->execute([
                    $event['name'],
                    $event['date'],
                    $event['time'] ?? '21:00',
                    $event['venue'] ?? '',
                    $event['city'] ?? '',
                    $event['country'] ?? 'FR',
                    $event['ticket_url'] ?? '',
                    $event['poster'] ?? '',
                    $event['description'] ?? '',
                    json_encode($event['networks'] ?? []),
                    $event['status'] ?? 'upcoming',
                    $event['id']
                ]);
            } else {
                // Insert new
                $stmt = $db->prepare("INSERT INTO events (name, date, time, venue, city, country, ticket_url, poster, description, networks, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $event['name'],
                    $event['date'],
                    $event['time'] ?? '21:00',
                    $event['venue'] ?? '',
                    $event['city'] ?? '',
                    $event['country'] ?? 'FR',
                    $event['ticket_url'] ?? '',
                    $event['poster'] ?? '',
                    $event['description'] ?? '',
                    json_encode($event['networks'] ?? []),
                    $event['status'] ?? 'upcoming'
                ]);
            }
        }
    }
    
    // Handle content
    if (isset($input['content']) && is_array($input['content'])) {
        foreach ($input['content'] as $post) {
            if (isset($post['id']) && $post['id']) {
                // Update existing
                $stmt = $db->prepare("UPDATE content SET title = ?, body = ?, audience = ?, networks = ?, media = ?, status = ?, scheduled_at = ? WHERE id = ?");
                $stmt->execute([
                    $post['title'],
                    $post['body'] ?? '',
                    $post['audience'] ?? 'all',
                    json_encode($post['networks'] ?? []),
                    json_encode($post['media'] ?? []),
                    $post['status'] ?? 'draft',
                    $post['scheduled_at'] ?? null,
                    $post['id']
                ]);
            } else {
                // Insert new
                $stmt = $db->prepare("INSERT INTO content (title, type, body, audience, networks, media, status, scheduled_at, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $post['title'],
                    $post['type'] ?? 'post',
                    $post['body'] ?? '',
                    $post['audience'] ?? 'all',
                    json_encode($post['networks'] ?? []),
                    json_encode($post['media'] ?? []),
                    $post['status'] ?? 'draft',
                    $post['scheduled_at'] ?? null,
                    $user['id']
                ]);
            }
        }
    }
    
    jsonResponse([
        'message' => 'Paramètres mis à jour',
        'updated' => $updated
    ]);
}
function handleTestEmail($input) {
    $user = requireAuth(['admin']);
    $to = trim($input['to'] ?? $user['email'] ?? '');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) errorResponse('Adresse email invalide', 400);

    $html = "
<div style='background:#000;color:#f0f0f0;font-family:Courier New,monospace;max-width:460px;margin:0 auto;border:1px solid #222;'>
  <div style='background:#ffe600;padding:10px 18px;'>
    <span style='font-size:20px;font-weight:bold;letter-spacing:3px;color:#000'>ELECTROBOY80</span>
  </div>
  <div style='padding:22px 18px;'>
    <p style='color:#ffe600;font-size:13px;letter-spacing:2px;text-transform:uppercase;margin:0 0 12px'>Test SMTP</p>
    <p style='margin:0 0 10px;font-size:13px;'>Si tu reçois cet email, la configuration SMTP est correcte ✓</p>
    <p style='color:#555;font-size:11px;margin:0;'>Envoyé depuis : " . htmlspecialchars(getSetting('smtp_from') ?: getSetting('smtp_user') ?: '?') . "</p>
  </div>
  <div style='background:#080808;border-top:1px solid #1a1a1a;padding:8px 18px;font-size:10px;color:#444;letter-spacing:1px;'>
    ELECTROBOY80 · Test automatique
  </div>
</div>";

    $result = sendMail($to, '🔧 Test SMTP — ELECTROBOY80', $html);
    if ($result === true) {
        jsonResponse(['ok' => true, 'message' => "Email envoyé à $to"]);
    } else {
        errorResponse('Échec envoi : ' . (is_string($result) ? $result : 'erreur inconnue'), 500);
    }
}
