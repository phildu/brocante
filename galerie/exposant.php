<?php
// Espace exposant (web app installable) : état de la demande d'emplacement, pass de confirmation avec QR code, plan et itinéraire.
//   GET  ?g=<galerie>&t=<jeton>          l'espace personnel (lien reçu par e-mail)
//   GET  ?g=<galerie>&t=<jeton>&annuler=1 la confirmation d'annulation
//   GET  ?g=<galerie>&p=<code>           le contrôle d'un pass par un organisateur ou la sécurité
//   GET  ?g=<galerie>&t=<jeton>&manifest=1 le manifeste d'installation
//   POST JSON {g, slot, …}               une demande d'emplacement depuis le plan public (voir includes/gallery-booking.php)
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-booking.php';
require_once __DIR__ . '/../includes/gallery-waitlist.php';
require_once __DIR__ . '/../includes/gallery-locate.php';
require_once __DIR__ . '/../includes/gallery-cancellation.php';
require_once __DIR__ . '/../includes/gallery-messages.php';

function gx_page(string $title, string $html, string $accent = '#2f6b4f'): never
{
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#2f6b4f';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('Referrer-Policy: no-referrer');
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $h($title) . '</title><style>'
        . ':root{--a:' . $h($accent) . '}*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f1f1f;background:#f6f4ee}'
        . 'main{max-width:540px;margin:0 auto;padding:24px 16px 48px}h1{font-size:1.5rem;margin:0 0 16px}'
        . '.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:16px;display:grid;gap:10px;margin:0 0 14px}'
        . '.card h2{margin:0;font-size:1.1rem}.soft{color:#666;font-size:.88rem;margin:0}'
        . '.verdict{border-radius:12px;padding:14px 16px;font-weight:700;margin:0 0 14px}'
        . '.verdict.ok{background:#e6f4ea;color:#1c6b3c;border:1px solid #b7dcc2}.verdict.bad{background:#fde8e8;color:#a51b1b;border:1px solid #f3c0c0}'
        . '.verdict.warn{background:#fff4d6;color:#7a4b00;border:1px solid #efd79a}'
        . 'dl{margin:0;display:grid;gap:8px}dl div{display:flex;gap:12px;justify-content:space-between}dt{color:#666;font-size:.88rem}dd{margin:0;text-align:right;font-weight:600}'
        . 'button,.btn{font:inherit;font-weight:600;padding:10px 16px;border-radius:10px;border:1px solid var(--a);background:#fff;color:var(--a);cursor:pointer;text-decoration:none;display:inline-block}'
        . 'button.primary{background:var(--a);color:#fff}.danger{border-color:#b42318;color:#b42318}'
        . '</style></head><body><main><h1>' . $h($title) . '</h1>' . $html . '</main></body></html>';
    exit;
}

$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$in = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $j = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($j)) { $in = $j; $slug = strtolower((string) ($j['g'] ?? $slug)); }
}
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$accent = $gallery && preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#2f6b4f';
$ink = (0.299 * hexdec(substr($accent, 1, 2)) + 0.587 * hexdec(substr($accent, 3, 2)) + 0.114 * hexdec(substr($accent, 5, 2))) > 160 ? '#1f1f1f' : '#ffffff';
$galleryUrl = '/galerie/' . rawurlencode($slug) . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($in['action'] ?? '') === 'cancel') {          // confirmation de l'annulation
    $ct = (string) ($in['t'] ?? '');
    $row = $gallery ? gb_by_token($slug, $ct) : null;
    if (!$gallery || !$row) {
        gx_page('Lien invalide', "<p>Ce lien n'est plus valide : la demande a déjà été annulée ou traitée.</p><p><a class=\"btn\" href=\"/\">Retour à l'accueil</a></p>", $accent);
    }
    if (($row['status'] ?? '') === 'taken') {
        gx_page('Demande confirmée', '<p>Votre emplacement est confirmé : il ne peut plus être annulé depuis cette page. Contactez l\'organisateur de la galerie pour toute modification.</p>'
            . '<p><a class="btn" href="/galerie/exposant.php?g=' . rawurlencode($slug) . '&amp;t=' . rawurlencode($ct) . '">Ouvrir mon espace exposant</a></p>', $accent);
    }
    $cancelSlot = '';
    $ok = gb_cancel($slug, $ct, $cancelSlot);
    if ($ok && $cancelSlot !== '') gwl_on_release($gallery, $cancelSlot, 'exposant');
    gx_page($ok ? 'Demande annulée' : 'Lien invalide',
        '<p>' . ($ok ? "Votre demande est annulée : l'emplacement est de nouveau libre." : "Ce lien n'est plus valide (demande déjà traitée ou annulée).") . '</p>'
        . ($gallery ? '<p><a class="btn" href="' . htmlspecialchars($galleryUrl, ENT_QUOTES, 'UTF-8') . '">Voir les emplacements libres</a></p>' : ''), $accent);
}

$msgError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($in['action'] ?? '') === 'message' && $gallery && gt_feature($gallery, 'messages')) { // message de l'exposant à l'organisateur
    $ct = (string) ($in['t'] ?? '');
    $msgRow = $gallery ? gb_by_token($slug, $ct) : null;
    if (!$gallery || !$msgRow) {
        gx_page('Lien invalide', "<p>Ce lien n'est plus valide : ouvrez celui reçu par e-mail après votre demande d'emplacement.</p>", $accent);
    }
    if (!gmsg_guest_quota_ok($slug)) {
        $msgError = 'Trop de messages envoyés : réessayez dans une heure.';
    } else {
        $threadId = gmsg_open_exposant($gallery, $msgRow);
        $msgError = $threadId === null ? "La conversation n'a pas pu être ouverte."
            : (string) (gmsg_post($slug, $threadId, 'guest', (string) ($in['body'] ?? ''), ['guest_quota' => true, 'by' => (string) ($msgRow['name'] ?? '')]) ?? '');
        if ($msgError === '' && ($thread = gmsg_thread_for_booking($slug, $ct))) gmsg_notify_admins($gallery, $thread, (string) ($in['body'] ?? ''));
    }
    if ($msgError === '') {
        header('Location: /galerie/exposant.php?' . http_build_query(['g' => $slug, 't' => $ct, 'msg' => 1]) . '#messages');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($in['action'] ?? '') !== 'message') {          // nouvelle demande depuis le plan public
    header('Content-Type: application/json; charset=utf-8');
    if (!empty($in['website'])) { echo json_encode(['ok' => true, 'message' => 'Votre demande est enregistrée.']); exit; }   // champ piège
    if (!$gallery) { http_response_code(404); echo json_encode(['error' => 'Galerie introuvable.']); exit; }
    [$e, $err] = gb_request($gallery, (string) ($in['slot'] ?? ''), $in, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $_FILES);
    http_response_code($err ? 422 : 200);
    $message = 'Votre demande est enregistrée : un e-mail de confirmation vient de vous être envoyé. L\'organisateur vous répond ensuite.';
    if (!$err && is_array($e) && !empty($e['cni']['uploaded'])) $message .= ' Votre pièce d\'identité a été reçue pour vérification ; sa copie sera supprimée automatiquement le ' . date('d/m/Y', (int) $e['cni']['purge_after']) . '.';
    echo json_encode($err ? ['error' => $err] : ['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) ($_GET['t'] ?? '');
$pass = (string) ($_GET['p'] ?? '');

if (isset($_GET['manifest'])) {                                                              // manifeste : nom et icône de la galerie, ouverture sur cet espace
    $row = ($gallery && preg_match('/^[a-f0-9]{32}$/', $token)) ? gb_by_token($slug, $token) : null;
    if (!$row) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo '{}'; exit; }
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $name = (string) $gallery['name'];
    $logo = (string) ($gallery['style']['logo'] ?? '');
    echo json_encode(['name' => $name . ' — espace exposant', 'short_name' => 'Exposant',
        'start_url' => '/galerie/exposant.php?g=' . rawurlencode($slug) . '&t=' . $token, 'scope' => '/galerie/', 'display' => 'standalone',
        'background_color' => '#ffffff', 'theme_color' => $accent,
        'icons' => $logo !== '' ? [['src' => '/' . ltrim($logo, '/'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']] : []],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Contrôle d'un pass par un organisateur ou la sécurité : jamais d'e-mail ni de téléphone, seulement l'identité et l'emplacement.
if ($pass !== '' || isset($_GET['p'])) {
    $row = $gallery ? gb_by_pass($slug, $pass) : null;
    $galleryEventForPass = $gallery ? gallery_event($gallery) : null;
    $passCancelled = ge_cancelled($galleryEventForPass);
    $valid = $row && ($row['status'] ?? '') === 'taken' && !$passCancelled;
    $slot = $valid ? gb_slot($gallery, (string) $row['slot_id']) : null;
    $loc = $slot ? gallery_locate_slot($gallery, $slot) : null;
    $event = $loc['event'] ?? ($gallery ? gallery_locate_event($gallery) : null);
    $stand = $slot ? gb_slot_name($gallery, $slot) : 'Emplacement attribué';
    $zone = (string) ($loc['zone'] ?? '');
    $html = '<div class="verdict ' . ($valid ? 'ok' : 'bad') . '">' . ($valid ? 'Pass valide' : 'Pass non valide') . '</div>';
    if ($valid) {
        $endRaw = (string) ($event['end_raw'] ?? '') ?: (string) ($event['date_raw'] ?? '');
        $html .= '<div class="card"><p class="soft">Galerie</p><h2>' . htmlspecialchars((string) $gallery['name'], ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<dl><div><dt>Exposant</dt><dd>' . htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</dd></div>'
            . '<div><dt>Emplacement</dt><dd>' . htmlspecialchars($stand, ENT_QUOTES, 'UTF-8') . '</dd></div>'
            . '<div><dt>Statut</dt><dd>Confirmé</dd></div></dl>';
        if ($event) {
            $html .= '<p class="soft">' . htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8')
                . ($event['date'] !== '' ? ' · ' . htmlspecialchars($event['date'], ENT_QUOTES, 'UTF-8') : '')
                . ($event['hours'] !== '' ? ' · ' . htmlspecialchars($event['hours'], ENT_QUOTES, 'UTF-8') : '')
                . ($event['address'] !== '' ? '<br>' . htmlspecialchars($event['address'], ENT_QUOTES, 'UTF-8') : '') . '</p>';
        }
        if ($endRaw !== '' && $endRaw < date('Y-m-d')) $html .= '<div class="verdict warn">L\'événement indiqué est terminé : vérifiez auprès de l\'organisateur.</div>';
        $html .= '<p class="soft">Pass nominatif : ne pas transférer. Ce contrôle n\'affiche aucune coordonnée personnelle.</p></div>';
    } else {
        if ($passCancelled) $html .= '<div class="verdict warn">L\'événement a été annulé : ce pass n\'est plus valable.</div>';
        $html .= '<div class="card"><p>Ce code ne correspond à aucune confirmation d\'emplacement en cours.</p>'
            . '<p class="soft">Demandez à l\'exposant d\'ouvrir son espace exposant et de présenter le QR code de confirmation. Une demande encore en attente n\'a pas de pass.</p></div>';
    }
    $html .= '<p><button type="button" onclick="location.reload()">Actualiser le contrôle</button></p>'
        . '<p class="soft">Contrôle effectué le ' . date('d/m/Y \à H:i') . '.</p>';
    gx_page('Contrôle du pass', $html, $accent);
}

$row = ($gallery && preg_match('/^[a-f0-9]{32}$/', $token)) ? gb_by_token($slug, $token) : null;
if (!$gallery || !$row) {
    gx_page('Espace exposant', "<p>Ce lien n'est plus valide : ouvrez le lien reçu par e-mail après votre demande d'emplacement, ou refaites une demande depuis le plan de la galerie.</p>"
        . ($gallery ? '<p><a class="btn" href="' . htmlspecialchars($galleryUrl, ENT_QUOTES, 'UTF-8') . '">Voir les emplacements libres</a></p>' : '<p><a class="btn" href="/">Retour à l\'accueil</a></p>'), $accent);
}

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$hold = gb_hold_seconds($gallery);
$taken = ($row['status'] ?? '') === 'taken';
$expired = ($row['status'] ?? '') === 'pending' && !gb_active($row, $hold);
$requested = (int) ($row['requested'] ?? 0);
$decided = (int) ($row['decided'] ?? 0);
$holdUntil = $requested > 0 ? $requested + $hold : 0;
$price = isset($row['price_cents']) && $row['price_cents'] !== null ? (int) $row['price_cents'] : null;
$slot = gb_slot($gallery, (string) $row['slot_id']);
$stand = $slot ? gb_slot_name($gallery, $slot) : ($taken ? 'Emplacement attribué' : 'Emplacement retiré du plan');
$slotLoc = $slot ? gallery_locate_slot($gallery, $slot) : null;
$event = $slotLoc['event'] ?? gallery_locate_event($gallery);
$galleryEvent = gallery_event($gallery);
$eventCancelled = ge_cancelled($galleryEvent);
$cancelInfo = $eventCancelled ? (array) $galleryEvent['cancelled'] : [];
$refund = gcn_row_refund($row);
$plan = gallery_locate_data($gallery, [], gallery_locate_origin());
$zone = (string) ($slotLoc['zone'] ?? '');
$address = (string) ($slotLoc['address'] ?? ($event['address'] ?? ''));
$point = $slotLoc['point'] ?? null;
if (!$point && $slot && ($slot['kind'] ?? '') === 'event' && !empty($plan['center'])) $point = $plan['center'];
$directions = (string) ($slotLoc['directions'] ?? gallery_locate_directions($point, $address));
$canMap = (bool) ($point || $address !== '' || $plan);
$passUrl = $taken ? gb_pass_url($gallery, $row) : '';
$passCode = $taken ? gb_pass_code($slug, (string) $row['token']) : '';
$passOn = $taken && $passUrl !== '' && $passCode !== '';
$statusLabel = $eventCancelled ? 'Événement annulé' : ($taken ? 'Confirmé' : ($expired ? 'Délai dépassé' : 'En attente'));
$statusClass = $eventCancelled ? 'bad' : ($taken ? 'ok' : ($expired ? 'bad' : 'wait'));
$canCancel = !$taken && !$eventCancelled;
$msgThread = gt_feature($gallery, 'messages') ? gmsg_thread_for_booking($slug, $token) : null;
if ($msgThread) {
    gmsg_mark_read($slug, $msgThread['id'], 'guest');
    $msgThread = gmsg_thread($slug, $msgThread['id']) ?? $msgThread;
}
$msgDraft = $msgError !== '' ? (string) ($in['body'] ?? '') : '';

if (isset($_GET['annuler'])) {                                                              // confirmation d'annulation (un lien ouvert par un antivirus n'annule rien)
    if ($eventCancelled) {
        gx_page('Événement annulé', '<p>Cet événement a été annulé par l’organisateur : votre demande est gelée et aucun remboursement n’est à demander ici.</p>'
            . '<p><a class="btn" href="/galerie/exposant.php?g=' . rawurlencode($slug) . '&amp;t=' . rawurlencode($token) . '">Ouvrir mon espace exposant</a></p>', $accent);
    }
    if ($taken) {
        gx_page('Demande confirmée', '<p>Votre emplacement est confirmé : il ne peut plus être annulé depuis cette page. Contactez l\'organisateur de la galerie pour toute modification.</p>'
            . '<p><a class="btn" href="/galerie/exposant.php?g=' . rawurlencode($slug) . '&amp;t=' . rawurlencode($token) . '">Ouvrir mon espace exposant</a></p>', $accent);
    }
    gx_page('Annuler ma demande', '<p>Voulez-vous annuler votre demande d\'emplacement pour « ' . $h($gallery['name']) . ' » ?</p>'
        . '<p class="soft">' . $h($stand) . ($zone !== '' ? ' · ' . $h($zone) : '') . '</p>'
        . '<form method="post"><input type="hidden" name="g" value="' . $h($slug) . '"><input type="hidden" name="t" value="' . $h($token) . '"><input type="hidden" name="action" value="cancel">'
        . '<button type="submit" class="danger">Oui, annuler ma demande</button></form>'
        . '<p><a class="btn" href="/galerie/exposant.php?g=' . rawurlencode($slug) . '&amp;t=' . rawurlencode($token) . '">Non, revenir à mon espace</a></p>', $accent);
}

$cfg = [
    'slug' => $slug, 'slotId' => (string) ($row['slot_id'] ?? ''), 'stand' => $stand, 'zone' => $zone,
    'point' => $point, 'address' => $address, 'directions' => $directions, 'accent' => $accent,
    'plan' => $plan ? ['center' => $plan['center'], 'zoom' => $plan['zoom'], 'base' => $plan['base'],
        'tiles' => $plan['tiles'], 'background' => $plan['background'], 'items' => $plan['items']] : null,
    'pass' => $eventCancelled ? '' : $passUrl, 'code' => $eventCancelled ? '' : $passCode,
];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= $h($accent) ?>">
<link rel="manifest" href="/galerie/exposant.php?g=<?= $h($slug) ?>&amp;t=<?= $h($token) ?>&amp;manifest=1">
<?php $pwaLogo = (string) ($gallery['style']['logo'] ?? ''); ?>
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-save.js') ?>" data-auto data-delay="2200" data-key="gx-exposant-<?= $h($slug) ?>" data-name="<?= $h('Espace exposant — ' . $gallery['name']) ?>"<?php if ($pwaLogo !== ''): ?> data-icon="<?= $h('/' . ltrim($pwaLogo, '/')) ?>"<?php endif; ?> data-accent="<?= $h($accent) ?>" data-sw="/galerie/pwa-sw.php" data-scope="/galerie/"></script>
<title>Espace exposant — <?= $h($gallery['name']) ?></title>
<style>
  :root { --a: <?= $h($accent) ?>; --ai: <?= $h($ink) ?>; --line: #ddd; --soft: #666; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #f6f4ee; }
  header { background: var(--a); color: var(--ai); padding: 22px 16px 18px; }
  header .in, main { max-width: 640px; margin: 0 auto; }
  header p { margin: 0; opacity: .85; font-size: .85rem; letter-spacing: .08em; text-transform: uppercase; }
  header h1 { margin: 4px 0 0; font-size: 1.6rem; }
  main { padding: 16px; display: grid; gap: 14px; }
  [hidden] { display: none !important; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px; display: grid; gap: 10px; }
  .card h2 { margin: 0; font-size: 1.05rem; }
  .soft { color: var(--soft); font-size: .88rem; margin: 0; }
  .pill { justify-self: start; display: inline-block; font-size: .76rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; }
  .pill.ok { background: #e6f4ea; color: #1c6b3c; }
  .pill.wait { background: #fff4d6; color: #7a4b00; }
  .pill.bad { background: #fde8e8; color: #a51b1b; }
  dl { margin: 0; display: grid; gap: 8px; }
  dl div { display: flex; gap: 12px; justify-content: space-between; align-items: baseline; }
  dt { color: var(--soft); font-size: .88rem; }
  dd { margin: 0; text-align: right; font-weight: 600; }
  .evt { border-left: 3px solid var(--a); padding-left: 10px; display: grid; gap: 2px; }
  .evt strong { font-size: .98rem; }
  .btn, button { font: inherit; font-weight: 600; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--a); background: #fff; color: var(--a); cursor: pointer; text-decoration: none; display: inline-block; }
  button.primary { background: var(--a); color: var(--ai); }
  button.danger { border-color: #b42318; color: #b42318; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .qr { width: 100%; max-width: 280px; margin: 0 auto; background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px; }
  .qr svg { width: 100%; height: auto; display: block; }
  .code { font: 700 .9rem ui-monospace, monospace; letter-spacing: .01em; padding: 2px 8px; border: 2px dashed var(--a); border-radius: 6px; word-break: break-all; }
  .code-line { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 0; }
  .code-line button { padding: 6px 10px; font-size: .84rem; }
  #gx-map { height: 360px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #f6f3ec; }
  #gx-map .leaflet-container { font: inherit; }
  .fullpass { position: fixed; inset: 0; z-index: 9999; background: #fff; display: grid; overflow: auto; padding: 18px; }
  .fullpass-in { max-width: 520px; width: 100%; margin: auto; display: grid; gap: 12px; justify-items: center; text-align: center; }
  .fullpass .close { justify-self: end; }
  .fullpass h2 { margin: 0; font-size: 1.2rem; }
  .fullpass .qr { max-width: 420px; }
  .msgs { display: grid; gap: 10px; }
  .msg { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; max-width: 92%; }
  .msg.admin { margin-right: auto; background: #f4f7f4; }
  .msg.guest { margin-left: auto; background: #eef3f8; }
  .msg-head { display: flex; justify-content: space-between; gap: 8px; font-size: .78rem; color: var(--soft); }
  .msg-head strong { color: #1f1f1f; }
  .msg p { margin: 6px 0 0; white-space: pre-line; overflow-wrap: anywhere; }
  .msg-form { display: grid; gap: 10px; }
  .msg-form label { display: grid; gap: 6px; font-weight: 600; font-size: .9rem; }
  .msg-form textarea { font: inherit; padding: 10px; border: 1px solid var(--line); border-radius: 10px; min-height: 110px; width: 100%; }
  .notice { background: #e6f4ea; color: #1c6b3c; border: 1px solid #b7dcc2; border-radius: 10px; padding: 10px 12px; margin: 0; }
  @media print {
    .noprint { display: none !important; }
    body { background: #fff; }
    header { color: #000; background: none; border-bottom: 2px solid #000; }
    .card { break-inside: avoid; border-color: #999; }
    .qr { max-width: 300px; }
  }
</style>
</head>
<body>
<header><div class="in"><p>Espace exposant</p><h1><?= $h($gallery['name']) ?></h1></div></header>
<main id="app" data-cfg="<?= $h(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>">

  <section class="card" id="status">
    <span class="pill <?= $h($statusClass) ?>"><?= $h($statusLabel) ?></span>
    <h2><?= $h($stand) ?></h2>
    <?php if ($eventCancelled): ?>
      <p class="soft"><strong>L’organisateur a annulé cet événement.</strong> <?= $h(ge_cancel_reason($galleryEvent)) ?>.</p>
      <p class="soft">Votre emplacement n’est plus à tenir<?= $taken ? ' ; le remboursement est détaillé ci-dessous' : ' : votre demande était en attente, aucun paiement n’a été enregistré' ?>.</p>
    <?php elseif ($taken): ?>
      <p class="soft">Votre emplacement est attribué. Le pass ci-dessous confirme la réservation aux organisateurs et à la sécurité du site.</p>
    <?php elseif ($expired): ?>
      <p class="soft">Le délai de réservation est dépassé : l'emplacement a été remis à disposition. Vous pouvez annuler cette demande ou en choisir un autre sur le plan.</p>
    <?php else: ?>
      <p class="soft">Votre demande est enregistrée. L'organisateur vous répond par e-mail ; l'emplacement reste retenu jusqu'au <?= $h($holdUntil ? date('d/m/Y', $holdUntil) : '—') ?>.</p>
    <?php endif; ?>
    <dl>
      <div><dt>Exposant</dt><dd><?= $h($row['name'] ?? '') ?></dd></div>
      <div><dt>Type</dt><dd><?= $h(ct_label((string) $row['kind'])) ?></dd></div>
      <?php if ($requested): ?><div><dt>Demande</dt><dd><?= $h(date('d/m/Y à H:i', $requested)) ?></dd></div><?php endif; ?>
      <div><dt>Tarif</dt><dd><?= $h(gb_eur($price)) ?></dd></div>
      <?php if ($taken && $decided): ?><div><dt>Attribué le</dt><dd><?= $h(date('d/m/Y', $decided)) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($event): ?>
      <div class="evt">
        <strong><?= $h($event['title']) ?></strong>
        <?php if ($event['date'] !== '' || $event['hours'] !== ''): ?><span class="soft"><?= $h(trim($event['date'] . ($event['hours'] !== '' ? ' · ' . $event['hours'] : ''))) ?></span><?php endif; ?>
        <?php if ($event['address'] !== ''): ?><span class="soft"><?= $h($event['address']) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($passOn && !$eventCancelled): ?>
  <section class="card" id="pass-card">
    <h2>Pass de confirmation</h2>
    <p class="soft">Présentez ce QR code aux organisateurs ou à la sécurité du site, sur téléphone ou imprimé.</p>
    <div id="qr" class="qr" role="img" aria-label="QR code de confirmation de l'emplacement"></div>
    <p class="code-line">Code de secours : <span class="code" id="pass-code"><?= $h($passCode) ?></span> <button type="button" id="copy-code">Copier</button></p>
    <div class="row noprint">
      <button type="button" class="primary" id="fullpass-btn">Afficher en grand</button>
      <button type="button" onclick="window.print()">Imprimer</button>
    </div>
    <p class="soft">Pass nominatif : <?= $h($row['name'] ?? '') ?> · <?= $h($stand) ?>. Ne le transférez pas ; un code différent est nécessaire pour annuler la demande.</p>
  </section>
  <?php elseif (!$taken && !$eventCancelled): ?>
  <section class="card">
    <h2>Pass de confirmation</h2>
    <p class="soft">Le QR code de confirmation apparaîtra ici dès que l'organisateur aura accepté votre demande.</p>
  </section>
  <?php endif; ?>

  <?php if (gt_feature($gallery, 'messages')): ?>
  <section class="card" id="messages">
    <h2>Messages avec l'organisateur</h2>
    <?php if (isset($_GET['msg'])): ?><p class="notice">Votre message a bien été envoyé.</p><?php endif; ?>
    <?php if ($msgError !== ''): ?><p class="verdict bad"><?= $h($msgError) ?></p><?php endif; ?>
    <?php if ($msgThread && $msgThread['messages']): ?>
      <div class="msgs">
        <?php foreach ($msgThread['messages'] as $m): ?>
          <div class="msg <?= $m['from'] === 'admin' ? 'admin' : 'guest' ?>">
            <div class="msg-head"><strong><?= $h($m['from'] === 'admin' ? ($m['by'] !== '' ? $m['by'] : 'Organisateur') : ($row['name'] ?? 'Vous')) ?></strong><span><?= $h(date('d/m/Y H:i', $m['at'])) ?></span></div>
            <p><?= nl2br($h($m['body'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="soft">Une question sur votre emplacement, votre paiement ou l'installation ? Écrivez à l'organisateur : il reçoit un e-mail et vous répond ici.</p>
    <?php endif; ?>
    <form method="post" class="msg-form">
      <input type="hidden" name="g" value="<?= $h($slug) ?>">
      <input type="hidden" name="t" value="<?= $h($token) ?>">
      <input type="hidden" name="action" value="message">
      <label>Votre message<textarea name="body" maxlength="<?= GMSG_MAX_BODY ?>" rows="4" required placeholder="Bonjour, …"><?= $h($msgDraft) ?></textarea></label>
      <div><button type="submit" class="primary">Envoyer le message</button></div>
    </form>
    <p class="soft">La conversation est privée : elle n'est visible que par vous et l'organisateur.</p>
  </section>
  <?php endif; ?>

  <?php if ($eventCancelled): ?>
  <section class="card" id="refund">
    <h2>Remboursement</h2>
    <?php if (!$taken): ?>
      <p class="soft">Votre demande était encore en attente : aucun paiement n’a été enregistré. Vous n’avez rien à payer et aucun remboursement n’est nécessaire.</p>
    <?php elseif ($refund['status'] === 'paid'): ?>
      <p class="soft">Un remboursement de <strong><?= $h(gb_eur($refund['amount_cents'])) ?></strong> a été effectué<?= $refund['paid_at'] > 0 ? ' le ' . $h(date('d/m/Y', $refund['paid_at'])) : '' ?>.</p>
    <?php elseif ($refund['status'] === 'due'): ?>
      <p class="soft">Remboursement prévu : <strong><?= $h(gb_eur($refund['amount_cents'])) ?></strong><?php if ($refund['price_cents'] > 0): ?> (<?= (int) $refund['pct'] ?> % du tarif de <?= $h(gb_eur($refund['price_cents'])) ?>)<?php endif; ?>, sous <?= (int) ($cancelInfo['refund_delay_days'] ?? 0) ?> jours par <?= $h((string) ($cancelInfo['refund_method'] ?? '')) ?>.</p>
    <?php else: ?>
      <p class="soft">Aucun remboursement n’est prévu à ce titre selon les conditions de vente de l’événement.</p>
    <?php endif; ?>
    <?php if ($refund['note'] !== ''): ?><p class="soft"><?= $h($refund['note']) ?></p><?php endif; ?>
    <p class="soft">Si vous avez réglé sur place et que ce montant ne correspond pas, contactez l’organisateur.</p>
  </section>
  <?php endif; ?>

  <?php if ($canMap): ?>
  <section class="card" id="loc">
    <h2>Trouver mon emplacement</h2>
    <p class="soft"><?= $zone !== '' ? $h($zone) . ' · ' : '' ?><?= $address !== '' ? $h($address) : 'Le plan indique la position de votre emplacement.' ?></p>
    <div class="row noprint">
      <button type="button" id="gx-map-toggle">Afficher le plan</button>
      <?php if ($directions !== ''): ?><a class="btn" href="<?= $h($directions) ?>" target="_blank" rel="noopener noreferrer">Itinéraire</a><?php endif; ?>
      <button type="button" id="gx-geo">Me localiser</button>
    </div>
    <p class="soft" id="gx-dist" role="status" aria-live="polite"></p>
    <div id="gx-mapbox" hidden class="noprint">
      <div id="gx-map" role="application" aria-label="Plan de la galerie avec votre emplacement"></div>
      <p class="soft" id="gx-map-msg" style="margin:6px 0 0;" role="status"></p>
    </div>
  </section>
  <?php else: ?>
  <section class="card"><h2>Trouver mon emplacement</h2>
    <p class="soft">Aucun plan n'est disponible pour cette galerie. L'organisateur vous précisera l'emplacement et les modalités d'installation par e-mail.</p>
  </section>
  <?php endif; ?>

  <section class="card" id="install" hidden>
    <h2>Installer l'appli sur mon téléphone</h2>
    <p class="soft" id="install-help">Retrouvez votre emplacement et votre pass d'un seul geste, comme une application.</p>
    <p class="noprint"><button type="button" class="primary" id="install-btn">Installer l'appli</button></p>
  </section>

  <?php if ($canCancel): ?>
  <section class="card noprint">
    <h2>Annuler ma demande</h2>
    <p class="soft">L'emplacement redeviendra libre pour un autre exposant.</p>
    <form method="post" id="cancel-form">
      <input type="hidden" name="g" value="<?= $h($slug) ?>">
      <input type="hidden" name="t" value="<?= $h($token) ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="danger">Annuler ma demande</button>
    </form>
  </section>
  <?php endif; ?>

  <p class="soft noprint"><a href="<?= $h($galleryUrl) ?>">← Retour à la galerie</a></p>
</main>

<?php if ($passOn && !$eventCancelled): ?>
<div class="fullpass" id="fullpass" hidden role="dialog" aria-modal="true" aria-labelledby="fullpass-title">
  <div class="fullpass-in">
    <button type="button" class="close noprint" id="fullpass-close">Fermer</button>
    <h2 id="fullpass-title"><?= $h($gallery['name']) ?></h2>
    <p class="soft">Pass de confirmation</p>
    <p><strong><?= $h($row['name'] ?? '') ?></strong><br><?= $h($stand) ?><?php if ($event && ($event['date'] !== '' || $event['hours'] !== '')): ?><br><span class="soft"><?= $h(trim($event['date'] . ($event['hours'] !== '' ? ' · ' . $event['hours'] : ''))) ?></span><?php endif; ?></p>
    <div id="qr-full" class="qr" role="img" aria-label="QR code de confirmation agrandi"></div>
    <p class="code"><?= $h($passCode) ?></p>
    <p class="soft">À présenter aux organisateurs ou à la sécurité du site.</p>
  </div>
</div>
<?php endif; ?>

<script src="/assets/vendor/qrcode-generator.js"></script>
<script>
(function () {
  var root = document.getElementById('app'), cfg = JSON.parse(root.dataset.cfg), $ = function (id) { return document.getElementById(id); };

  // Pass : QR fabriqué dans le navigateur ; le code de secours reste lisible si la bibliothèque ne charge pas.
  (function () {
    if (!cfg.pass) return;
    function draw(target, cell) {
      if (!target || typeof qrcode !== 'function') return false;
      try {
        var q = qrcode(0, 'M'); q.addData(cfg.pass); q.make();
        target.innerHTML = q.createSvgTag({ cellSize: cell, margin: 0, scalable: true, alt: 'QR code de confirmation' });
        return true;
      } catch (e) { return false; }
    }
    var qr = $('qr'), full = $('qr-full');
    if (!draw(qr, 5)) qr.textContent = 'QR indisponible : présentez le code de secours ci-dessous.';
    draw(full, 9);
    var code = $('pass-code'), copy = $('copy-code');
    copy.addEventListener('click', function () {
      function done() { copy.textContent = 'Copié'; setTimeout(function () { copy.textContent = 'Copier'; }, 1500); }
      function fallback() {
        var r = document.createRange(); r.selectNodeContents(code);
        var s = getSelection(); s.removeAllRanges(); s.addRange(r);
        try { document.execCommand('copy'); done(); } catch (e) {}
      }
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(cfg.code).then(done, fallback);
      else fallback();
    });
    var box = $('fullpass'), open = $('fullpass-btn'), close = $('fullpass-close');
    function show(on) {
      box.hidden = !on;
      document.body.style.overflow = on ? 'hidden' : '';
      if (on) close.focus();
    }
    open.addEventListener('click', function () { show(true); });
    close.addEventListener('click', function () { show(false); open.focus(); });
    box.addEventListener('click', function (e) { if (e.target === box) show(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) show(false); });
  })();

  // Localisation : plan chargé à la demande, emplacement mis en évidence, position et distance à vol d'oiseau.
  (function () {
    var plan = cfg.plan, mapBox = $('gx-mapbox'), mapEl = $('gx-map'), mapBtn = $('gx-map-toggle');
    if (!mapEl || !mapBtn) return;
    var mapMsg = $('gx-map-msg'), distEl = $('gx-dist'), map = null, mapPromise = null, meLayer = null;
    var standPoint = cfg.point || null, ready = false, lastPos = null, lastAcc = 0;
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function distance(a, b) {
      var R = 6371000, rad = Math.PI / 180, dLat = (b[0] - a[0]) * rad, dLng = (b[1] - a[1]) * rad;
      var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a[0] * rad) * Math.cos(b[0] * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
      return 2 * R * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }
    function fmtDist(m) { return m < 1000 ? Math.max(10, Math.round(m / 10) * 10) + ' m' : (m / 1000).toFixed(m < 10000 ? 1 : 0).replace('.', ',') + ' km'; }
    function showDistance(pos) {
      if (!pos || !standPoint) return;
      distEl.textContent = 'Vous êtes à environ ' + fmtDist(distance(pos, standPoint)) + ' de votre emplacement, à vol d\'oiseau.';
    }

    function loadLeaflet() {
      return new Promise(function (ok, ko) {
        if (window.L) return ok(window.L);
        var css = document.createElement('link'), js = document.createElement('script');
        css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
        js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.async = true;
        css.onerror = ko; js.onerror = ko; js.onload = function () { ok(window.L); };
        document.head.appendChild(css); document.head.appendChild(js);
      });
    }
    function geocodeAddress(address) {
      if (!address) return Promise.resolve(null);
      return fetch('https://data.geopf.fr/geocodage/search?index=address&limit=1&q=' + encodeURIComponent(address), { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (j) {
          var c = j && j.features && j.features[0] && j.features[0].geometry && j.features[0].geometry.coordinates;
          return c && c.length >= 2 ? [c[1], c[0]] : null;
        }).catch(function () { return null; });
    }
    function popup(it) {
      var p = document.createElement('div'), h = document.createElement('strong');
      var title = it.role === 'slot' && it.num ? 'Emplacement ' + it.num : (it.role === 'zone' || it.role === 'territoire' ? (it.label || it.ztype || 'Zone') : (it.label || 'Repère'));
      h.textContent = title; p.appendChild(h);
      [it.zone, it.note].forEach(function (v) { if (v) { var n = document.createElement('div'); n.textContent = v; p.appendChild(n); } });
      return p;
    }
    function meLocation(pos, acc) {
      if (!map || !pos) return;
      if (meLayer) map.removeLayer(meLayer);
      var c = L.circle(pos, { radius: Math.max(15, acc || 30), color: '#1a73e8', weight: 1, fillColor: '#1a73e8', fillOpacity: .12 });
      var m = L.circleMarker(pos, { radius: 7, color: '#fff', weight: 2, fillColor: '#1a73e8', fillOpacity: 1 });
      meLayer = L.layerGroup([c, m]).addTo(map);
    }
    function initMap() {
      if (map) return Promise.resolve(map);
      if (mapPromise) return mapPromise;
      mapMsg.textContent = 'Chargement du plan…';
      mapPromise = loadLeaflet().then(function (L) {
        map = L.map(mapEl, { scrollWheelZoom: false, maxZoom: 22 });
        if (!plan || plan.base === 'none') {
          mapEl.style.background = 'linear-gradient(rgba(0,0,0,.06) 1px,transparent 1px) 0 0/40px 40px, linear-gradient(90deg,rgba(0,0,0,.06) 1px,transparent 1px) 0 0/40px 40px, #f6f3ec';
        } else {
          var sat = plan.base === 'sat';
          var tl = L.tileLayer(plan.tiles[sat ? 'sat' : 'osm'], { maxZoom: 22, maxNativeZoom: 19, attribution: sat ? 'Imagerie © IGN' : 'Plan © IGN' });
          tl.on('tileerror', function () {
            if (!map.hasLayer(tl)) return;
            map.removeLayer(tl);
            tl = sat ? L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 22, maxNativeZoom: 19, attribution: 'Imagerie © Esri, Maxar' })
              : L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png', { subdomains: 'abcd', maxZoom: 22, maxNativeZoom: 20, attribution: '© OpenStreetMap, © CARTO' });
            tl.addTo(map);
          });
          tl.addTo(map);
        }
        if (plan && plan.background) L.imageOverlay(plan.background.url, plan.background.bounds, { opacity: plan.background.opacity }).addTo(map);
        L.control.scale({ metric: true, imperial: false }).addTo(map);

        var slotLayer = null, backLayers = [];
        (plan && plan.items ? plan.items : []).forEach(function (it) {
          var layer;
          if (it.kind === 'marker') layer = L.circleMarker(it.pts[0], { radius: 7, color: '#fff', weight: 2, fillColor: it.color, fillOpacity: 1 });
          else if (it.kind === 'polygon') layer = L.polygon(it.pts, (it.role === 'zone' || it.role === 'territoire')
            ? { color: it.color, weight: 2, dashArray: '8 6', fillColor: it.color, fillOpacity: .07 }
            : { color: it.color, weight: 2, fillColor: it.color, fillOpacity: .3 });
          else layer = L.polyline(it.pts, { color: it.color, weight: 3 });

          var mine = it.id === cfg.slotId;
          if (mine) {
            layer.setStyle({ color: cfg.accent, fillColor: cfg.accent, weight: 4, fillOpacity: .55, opacity: 1 });
            slotLayer = layer;
          }
          if ((it.role === 'zone' || it.role === 'territoire') && it.label && layer.getBounds && !mine) {
            var bd = layer.getBounds();
            var sp = document.createElement('span');
            sp.textContent = it.ztype && it.label.toLowerCase().indexOf(it.ztype.toLowerCase()) !== 0 ? it.ztype + ' · ' + it.label : it.label;
            sp.style.cssText = 'display:inline-block;transform:translate(-50%,-100%);white-space:nowrap;font:700 11px/1.3 system-ui,sans-serif;color:#222;background:rgba(255,255,255,.88);border:1px solid rgba(0,0,0,.3);border-radius:4px;padding:2px 7px;pointer-events:none';
            L.marker([bd.getNorth(), bd.getCenter().lng], { icon: L.divIcon({ className: '', html: sp.outerHTML, iconSize: [0, 0] }), interactive: false, keyboard: false }).addTo(map);
          }
          layer.bindPopup(function () { return popup(it); });
          if (it.role === 'slot' && it.num) layer.bindTooltip('Emplacement ' + it.num + (it.zone ? ' · ' + it.zone : ''), { direction: 'center' });
          else if (it.kind === 'marker' && it.label) layer.bindTooltip(it.label, { direction: 'top' });
          layer.addTo(map);
          if (it.role === 'zone' || it.role === 'territoire') backLayers.push(layer);
        });

        if (!slotLayer && standPoint) {
          slotLayer = L.circleMarker(standPoint, { radius: 11, color: '#fff', weight: 3, fillColor: cfg.accent, fillOpacity: 1 }).addTo(map);
          slotLayer.bindPopup('<strong>' + esc(cfg.stand) + '</strong>');
        }
        map.whenReady(function () {
          backLayers.forEach(function (l) { l.bringToBack(); });
          if (slotLayer && slotLayer.openPopup) slotLayer.openPopup();
        });

        if (slotLayer && slotLayer.getBounds) map.fitBounds(slotLayer.getBounds().pad(.6));
        else if (slotLayer && slotLayer.getLatLng) map.setView(slotLayer.getLatLng(), 18);
        else if (standPoint) map.setView(standPoint, 18);
        else if (plan && plan.background) map.fitBounds(plan.background.bounds);
        else if (plan && plan.center) map.setView(plan.center, plan.zoom || 17);
        else if (cfg.address) geocodeAddress(cfg.address).then(function (pt) {
          if (!pt) { mapMsg.textContent = 'Adresse introuvable sur le plan : utilisez « Itinéraire ».'; return; }
          standPoint = pt;
          L.circleMarker(pt, { radius: 11, color: '#fff', weight: 3, fillColor: cfg.accent, fillOpacity: 1 }).addTo(map).bindPopup('<strong>' + esc(cfg.stand) + '</strong>').openPopup();
          map.setView(pt, 17);
          if (lastPos) showDistance(lastPos);
        });

        ready = true;
        mapMsg.textContent = '';
        if (lastPos) { meLocation(lastPos, lastAcc); showDistance(lastPos); }
        setTimeout(function () { map.invalidateSize(); }, 60);
        return map;
      }).catch(function (e) {
        mapPromise = null;
        console.error('Plan indisponible', e);
        mapMsg.textContent = 'Le plan est indisponible pour le moment : utilisez « Itinéraire ».';
        throw e;
      });
      return mapPromise;
    }
    mapBtn.addEventListener('click', function () {
      if (!mapBox.hidden) { mapBox.hidden = true; mapBtn.textContent = 'Afficher le plan'; return; }
      mapBox.hidden = false; mapBtn.textContent = 'Masquer le plan';
      initMap().then(function () { setTimeout(function () { if (map) map.invalidateSize(); }, 60); }).catch(function () {});
    });
    $('gx-geo').addEventListener('click', function () {
      if (!navigator.geolocation) { distEl.textContent = 'Votre navigateur ne donne pas la position.'; return; }
      distEl.textContent = 'Recherche de votre position…';
      navigator.geolocation.getCurrentPosition(function (p) {
        var pos = [p.coords.latitude, p.coords.longitude];
        lastPos = pos; lastAcc = p.coords.accuracy;
        if (map && ready) meLocation(pos, lastAcc);
        if (standPoint) { showDistance(pos); return; }
        if (!cfg.address) { distEl.textContent = 'Position enregistrée, mais votre emplacement n\'est pas encore situé sur le plan.'; return; }
        geocodeAddress(cfg.address).then(function (pt) {
          if (!pt) { distEl.textContent = 'Position enregistrée, mais l\'adresse du lieu n\'a pas pu être située.'; return; }
          standPoint = pt; showDistance(pos);
          if (map && ready) { L.circleMarker(pt, { radius: 11, color: '#fff', weight: 3, fillColor: cfg.accent, fillOpacity: 1 }).addTo(map); map.setView(pt, 17); }
        });
      }, function (e) {
        distEl.textContent = e && e.code === 1 ? 'Position refusée : autorisez la localisation dans votre navigateur.' : 'Position indisponible : réessayez dehors ou près d\'une fenêtre.';
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    });
  })();

  // Installation sur l'écran d'accueil
  (function () {
    var box = $('install'), btn = $('install-btn'), help = $('install-help'), ev = null;
    if (!box) return;
    if (window.PwaSave) {                                   // feuille commune : étapes adaptées au navigateur
      if (!window.PwaSave.isStandalone()) box.hidden = false;
      btn.addEventListener('click', function () { window.PwaSave.open(); });
      return;
    }
    if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone) return;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent); box.hidden = false;
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); ev = e; });
    window.addEventListener('appinstalled', function () { box.hidden = true; });
    btn.addEventListener('click', function () {
      if (ev) { ev.prompt(); ev.userChoice.then(function () { ev = null; }); return; }
      help.textContent = ios ? 'Sur iPhone : touchez le bouton Partager de Safari, puis « Sur l\'écran d\'accueil ».' : 'Ouvrez le menu de votre navigateur (⋮), puis « Installer l\'application » ou « Ajouter à l\'écran d\'accueil ».';
      help.style.fontWeight = '600';
    });
  })();

  var cancel = $('cancel-form');
  if (cancel) cancel.addEventListener('submit', function (e) {
    if (!confirm('Annuler définitivement votre demande d\'emplacement ?')) e.preventDefault();
  });
})();
</script>
</body>
</html>
