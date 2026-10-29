<?php
// Routes publiques : /api/reserver/creer (POST) et /api/reserver/retour (retour de Stripe).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ra.php';

if (!addon_is_entitled(RA_MODULE)) plugin_json(['error' => "Les réservations ne sont pas disponibles pour le moment."], 503);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($agentPath === '/reserver/creer' && $method === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : $_POST;
    if (!empty($in['website'])) plugin_json(['error' => 'Demande refusée.'], 422);   // champ piège
    [$url, $err] = ra_start((string) ($in['ref'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['email'] ?? ''), (string) ($in['phone'] ?? ''), !empty($in['accept']), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'url' => $url]);
}
if ($agentPath === '/reserver/retour' && $method === 'GET') {
    $token = (string) ($_GET['token'] ?? '');
    $st = db()->prepare('SELECT r.*, p.name AS pname FROM ra_reservations r LEFT JOIN products p ON p.ref = r.product_ref WHERE r.token = ?');
    $st->execute([preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '-']);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $ok = false;
    if ($r) {
        try { $ok = ra_confirm($r); } catch (Throwable $e) { error_log('Réservations retour : ' . $e->getMessage()); }
        if ($ok) { $st->execute([$token]); $r = $st->fetch(PDO::FETCH_ASSOC); }
    }
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $title = $ok ? 'Réservation confirmée' : 'Réservation en attente';
    $text = $ok ? '« ' . $r['pname'] . ' » vous est réservée jusqu\'au ' . date('d/m/Y', (int) $r['expires_at']) . '. Un e-mail de confirmation vous a été envoyé.'
        : "Nous n'avons pas encore reçu la confirmation du paiement. S'il a bien été débité, la réservation s'activera d'ici quelques minutes et vous recevrez un e-mail.";
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:48px auto;padding:0 16px;color:#222}</style></head><body><h1>' . $h($title) . '</h1><p>' . $h($text) . '</p><p><a href="' . $h(shop_abs_url('/boutique.php')) . '">Retour à la boutique</a></p></body></html>';
    exit;
}
plugin_json(['error' => 'Adresse inconnue.'], 404);
