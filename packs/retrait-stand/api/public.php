<?php
// Routes publiques : /api/retrait/creer (POST), /api/retrait/retour (retour de Stripe), /api/retrait/billet/<jeton> (billet QR).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rt.php';

if (!addon_is_entitled(RT_MODULE)) plugin_json(['error' => "Le retrait sur stand n'est pas disponible pour le moment."], 503);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$page = static function (string $title, string $inner) use ($h): never {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:32px auto;padding:0 16px;color:#222;text-align:center}.qr{width:260px;height:260px;margin:16px auto}.code{font:700 2rem monospace;letter-spacing:.2em}.box{background:#f4f1ea;padding:12px;border-radius:6px;text-align:left;font-size:.9rem}</style></head><body>' . $inner . '</body></html>';
    exit;
};

if ($agentPath === '/retrait/creer' && $method === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : $_POST;
    if (!empty($in['website'])) plugin_json(['error' => 'Demande refusée.'], 422);   // champ piège
    [$url, $err] = rt_start((string) ($in['ref'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['email'] ?? ''), (string) ($in['phone'] ?? ''), !empty($in['accept']), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'url' => $url]);
}

if ($agentPath === '/retrait/retour' && $method === 'GET') {
    $token = (string) ($_GET['token'] ?? '');
    $st = db()->prepare('SELECT * FROM rt_pickups WHERE token = ?');
    $st->execute([preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '-']);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $ok = false;
    if ($r) {
        try { $ok = rt_confirm($r); } catch (Throwable $e) { error_log('Retrait retour : ' . $e->getMessage()); }
    }
    if ($ok) { header('Location: ' . rt_ticket_url($r), true, 303); exit; }
    $page('Réservation en attente', '<h1>Réservation en attente</h1><p>Nous n\'avons pas encore reçu la confirmation de votre carte. Si elle a bien été validée, votre billet vous sera envoyé par e-mail d\'ici quelques minutes.</p><p><a href="' . $h(shop_abs_url('/boutique.php')) . '">Retour à la boutique</a></p>');
}

if (preg_match('#^/retrait/billet/([a-f0-9]{32})$#', $agentPath, $m)) {
    $r = rt_find($m[1]);
    if (!$r || !in_array($r['status'], ['authorized', 'collecting', 'collected', 'released', 'expired'], true)) plugin_json(['error' => 'Billet introuvable.'], 404);
    $s = rt_settings();
    $valid = $r['status'] === 'authorized' && (int) $r['expires_at'] > time();
    $labels = ['collected' => 'Déjà retiré et payé.', 'released' => 'Billet annulé : aucune somme prélevée.', 'expired' => 'Billet expiré : aucune somme prélevée.', 'collecting' => 'Encaissement en cours…', 'authorized' => 'Billet périmé.'];
    $inner = '<h1>Billet de retrait</h1><p><strong>' . $h($r['product_name']) . '</strong><br>' . $h(rt_eur((int) $r['amount_cents'])) . '</p>';
    if ($valid) {
        $inner .= '<div class="qr" id="qr" data-url="' . $h(rt_ticket_url($r)) . '"></div><div class="code">' . $h($r['code']) . '</div><p>Valable jusqu\'au ' . $h(date('d/m/Y', (int) $r['expires_at'])) . '. Présentez ce billet sur le stand : la somme bloquée sur votre carte sera alors prélevée.</p>'
            . ($s['place'] !== '' ? '<p class="box">' . $h($s['place']) . '</p>' : '')
            . '<script src="' . $h(shop_abs_url('/assets/vendor/qrcode-generator.js')) . '"></script><script>var b=document.getElementById("qr"),q=qrcode(0,"M");q.addData(b.dataset.url);q.make();b.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});var s=b.querySelector("svg");s.style.width="100%";s.style.height="100%";</script>';
    } else {
        $inner .= '<p class="box">' . $h($labels[$r['status']] ?? 'Billet indisponible.') . '</p>';
    }
    $page('Billet de retrait', $inner);
}
plugin_json(['error' => 'Adresse inconnue.'], 404);
