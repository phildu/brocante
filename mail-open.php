<?php
// Pixel d'ouverture d'e-mail : /mail-open.php?t=<jeton> (voir includes/mail-tracking.php).
// Répond toujours par une image transparente de 1 × 1 pixel, même si le jeton
// est inconnu, pour ne rien révéler et ne jamais casser l'affichage du message.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/mail-tracking.php';

$token = (string) ($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $dbFile = tenant_path('db_file');
        if ($dbFile !== '' && is_file($dbFile)) {
            $pdo = new PDO('sqlite:' . $dbFile, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            mail_open_record_token($pdo, $token, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }
    } catch (Throwable $e) {
        // Jamais d'erreur visible dans le message : l'image reste transparente.
        error_log('Pixel d’ouverture e-mail : ' . $e->getMessage());
    }
}

$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
header('Content-Type: image/gif');
header('Content-Length: ' . strlen($gif));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $gif;
