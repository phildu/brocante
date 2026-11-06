<?php
// Espace visiteur de la galerie (web app installable) : fidélité propre à la galerie, indépendante des boutiques. Voir includes/gallery-loyalty.php.
//   GET  ?g=<galerie>[&t=<jeton>][&s=<lieu.code>]  la page ; GET ?g=<galerie>&manifest=1  le manifeste d'installation
//   POST JSON {g, action: login | me | scan | redeem | forget, token…}  l'API de la page
require_once __DIR__ . '/../includes/apps.php';
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-loyalty.php';
require_once __DIR__ . '/../includes/gallery-locate.php';
require_once __DIR__ . '/../includes/oauth.php';

$in = $_POST;
$slug = strtolower((string) ($_GET['g'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $j = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($j) ? $j : $in;
    $slug = strtolower((string) ($in['g'] ?? $slug));
}
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$loyalty = $gallery && gl_open($gallery);
$live = $gallery && !empty($gallery['published']) && go_is_live($gallery);
$loc = ($live && $_SERVER['REQUEST_METHOD'] === 'GET') ? gallery_locate_data($gallery) : null;   // la localisation reste accessible même sans fidélité
$open = $loyalty || $loc !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $reply = static function (array $body, int $code = 200): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; };
    if (!empty($in['website'])) $reply(['ok' => true, 'message' => 'Un e-mail avec votre lien personnel vient de vous être envoyé.']);     // champ piège
    if (!$open) $reply(['error' => "L'espace visiteur n'est pas ouvert."], 404);
    $token = (string) ($in['token'] ?? '');
    switch ((string) ($in['action'] ?? '')) {
        case 'login':
            $err = gl_login($gallery, $in, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $reply($err ? ['error' => $err] : ['ok' => true, 'message' => 'Un e-mail avec votre lien personnel vient de vous être envoyé : ouvrez-le sur ce téléphone.'], $err ? 422 : 200);
        case 'me':
            $d = gl_read($slug);
            $email = gl_member_by_token($d, $token);
            $email === null ? $reply(['error' => 'Lien invalide ou expiré.'], 401) : $reply(['ok' => true, 'me' => gl_member_view($gallery, $d['members'][$email])]);
        case 'scan':
            [$me, $msg, $err] = gl_scan($gallery, $token, (string) ($in['scan'] ?? ''));
            $reply($err ? ['error' => $err] : ['ok' => true, 'message' => $msg, 'me' => $me], $err ? 422 : 200);
        case 'redeem':
            [$me, $msg, $err] = gl_redeem($gallery, $token, (int) ($in['tier'] ?? -1));
            $reply($err ? ['error' => $err] : ['ok' => true, 'message' => $msg, 'me' => $me], $err ? 422 : 200);
        case 'forget':
            $reply(['ok' => gl_forget($gallery, $token)]);
    }
    $reply(['error' => 'Action inconnue.'], 400);
}

if (isset($_GET['manifest'])) {                // manifeste : nom personnalisable (galerie → nom des applications) et icône de la galerie
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    if (!$gallery) {
        http_response_code(404);
        echo json_encode(['error' => 'Galerie introuvable.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = gallery_app_name($gallery, 'gallery_visitor');
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#2f6b4f';
    $icon = '/galerie/icon.php?g=' . rawurlencode($slug);
    echo json_encode([
        'id' => app_manifest_id('gallery_visitor', '', $slug),
        'name' => $name,
        'short_name' => app_short_name($name, 14),
        'description' => trim((string) ($gallery['tagline'] ?? '')) ?: 'Programme de fidélité et plan des lieux de la galerie.',
        'lang' => 'fr',
        'dir' => 'ltr',
        'start_url' => '/galerie/visiteur.php?g=' . rawurlencode($slug) . '&source=webapp',
        'scope' => '/galerie/',
        'display' => 'standalone',
        'display_override' => ['standalone', 'minimal-ui'],
        'orientation' => 'any',
        'categories' => ['shopping', 'lifestyle'],
        'prefer_related_applications' => false,
        'related_applications' => [],
        'background_color' => '#ffffff',
        'theme_color' => $accent,
        'icons' => [
            ['src' => $icon . '&s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon . '&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon . '&s=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => $icon . '&s=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('X-Robots-Tag: noindex');
header('Content-Type: text/html; charset=utf-8');
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
if (!$open) {
    http_response_code(404);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Espace visiteur</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 16px;color:#222}</style></head><body><h1>Espace visiteur</h1><p>Cet espace n\'est pas ouvert pour le moment.</p><p><a href="/galerie/' . $h($slug) . '/">Retour à la galerie</a></p></body></html>';
    exit;
}
$s = gl_settings($gallery);
$vocab = gt_of($gallery)['vocab'];
$flashErr = '';
if (isset($_COOKIE[session_name()])) {          // message laissé par la connexion sociale (oauth/finish.php) ; session ouverte seulement si le visiteur en a déjà une
    session_start();
    $flashErr = (string) ($_SESSION['gv_error'] ?? '');
    unset($_SESSION['gv_error']);
    session_write_close();
}
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#2f6b4f';
$ink = (0.299 * hexdec(substr($accent, 1, 2)) + 0.587 * hexdec(substr($accent, 3, 2)) + 0.114 * hexdec(substr($accent, 5, 2))) > 160 ? '#1f1f1f' : '#ffffff';
$visitorAppName = gallery_app_name($gallery, 'gallery_visitor');
$cfg = ['g' => $slug, 'api' => '/galerie/visiteur.php', 'key' => 'gl_t_' . $slug, 'program' => $s['program_name'], 'galleryUrl' => '/galerie/' . $slug . '/',
    'loyalty' => $loyalty, 'loc' => $loc, 'accent' => $accent, 'memberPlural' => (string) ($vocab['members'] ?? 'Commerces')];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= $h($accent) ?>">
<link rel="manifest" href="/galerie/visiteur.php?g=<?= $h($slug) ?>&manifest=1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= $h(app_short_name($visitorAppName, 14)) ?>">
<link rel="apple-touch-icon" href="/galerie/icon.php?g=<?= $h($slug) ?>&amp;s=180">
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-save.js') ?>" data-auto data-delay="2200" data-key="gl-visiteur-<?= $h($slug) ?>" data-name="<?= $h($visitorAppName) ?>" data-icon="/galerie/icon.php?g=<?= $h($slug) ?>" data-accent="<?= $h($accent) ?>" data-sw="/galerie/pwa-sw.php" data-scope="/galerie/"></script>
<title><?= $h(($loyalty ? $s['program_name'] . ' — ' : '') . $visitorAppName) ?></title>
<link rel="stylesheet" href="/assets/oauth.css">
<style>
  :root { --a: <?= $h($accent) ?>; --ai: <?= $h($ink) ?>; --line: #ddd; --soft: #666; }
  * { box-sizing: border-box; } body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #f6f4ee; }
  header { background: var(--a); color: var(--ai); padding: 22px 16px 18px; } header .in, main { max-width: 640px; margin: 0 auto; } header p { margin: 0; opacity: .85; font-size: .85rem; letter-spacing: .08em; text-transform: uppercase; } header h1 { margin: 4px 0 0; font-size: 1.6rem; }
  main { padding: 16px; display: grid; gap: 14px; } [hidden] { display: none !important; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px; display: grid; gap: 10px; } .card h2 { margin: 0; font-size: 1.05rem; }
  .soft { color: var(--soft); font-size: .88rem; margin: 0; } .pts { font-size: 3rem; font-weight: 700; line-height: 1; margin: 0; }
  .bar { height: 10px; background: #e8e5dc; border-radius: 99px; overflow: hidden; } .bar i { display: block; height: 100%; background: var(--a); }
  label { display: grid; gap: 3px; font-size: .88rem; } input[type=email], input[type=text], input[type=search] { font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; width: 100%; }
  button, .btn { font: inherit; font-weight: 600; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--a); background: #fff; color: var(--a); cursor: pointer; text-decoration: none; display: inline-block; }
  button.primary { background: var(--a); color: var(--ai); } button:disabled { opacity: .45; cursor: default; }
  .msg { min-height: 1.3em; margin: 0; font-weight: 600; } .err { color: #b42318; } .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
  ul.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } ul.list li { display: flex; gap: 10px; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; }
  .code { font: 700 1.2rem ui-monospace, monospace; letter-spacing: .1em; padding: 2px 8px; border: 2px dashed var(--a); border-radius: 6px; }
  .loc-list { display: grid; gap: 8px; }
  .loc-item { border: 1px solid #e6e1d6; border-radius: 10px; padding: 10px 12px; display: grid; gap: 4px; background: #fff; }
  .loc-item h3 { margin: 0; font-size: .98rem; }
  .loc-item .meta { font-size: .84rem; color: var(--soft); margin: 0; }
  .loc-acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
  .loc-acts a, .loc-acts button { font-size: .84rem; padding: 6px 10px; }
  .loc-chip { border-radius: 999px; padding: 6px 12px; font-size: .85rem; font-weight: 600; }
  .loc-chip[aria-pressed="true"] { background: var(--a); color: var(--ai); }
  #loc-map { height: 360px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #f6f3ec; }
  #loc-map .leaflet-container { font: inherit; }
</style>
</head>
<body>
<header><div class="in"><p>Espace visiteur</p><h1><?= $h($loyalty ? $s['program_name'] . ' · ' . $visitorAppName : $visitorAppName) ?></h1></div></header>
<main id="app" data-cfg="<?= $h(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>">
  <?php if ($loyalty && $s['intro'] !== ''): ?><p class="soft"><?= $h($s['intro']) ?></p><?php endif; ?>
  <p class="msg<?= $flashErr !== '' ? ' err' : '' ?>" id="msg" role="status"><?= $h($flashErr) ?></p>

  <section class="card" id="install" hidden>
    <h2>Installer l'appli sur mon téléphone</h2>
    <p class="soft" id="install-help"><?= $loyalty ? "Retrouvez vos points d'un seul geste, comme une application." : 'Retrouvez le plan et les lieux pratiques, comme une application.' ?></p>
    <p><button type="button" class="primary" id="install-btn">Installer l'appli</button></p>
  </section>

  <section class="card" id="login" hidden>
    <h2>Accéder à mon espace</h2>
    <p class="soft">Indiquez votre e-mail : nous vous envoyons un lien personnel, sans mot de passe.<?= $s['welcome'] > 0 ? ' ' . (int) $s['welcome'] . ' points offerts à l\'inscription.' : '' ?></p>
    <form id="login-form" style="display:grid;gap:10px;" autocomplete="on">
      <label>Mon e-mail<input type="email" name="email" required maxlength="160" autocomplete="email"></label>
      <label>Prénom (facultatif)<input type="text" name="name" maxlength="60" autocomplete="given-name"></label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
      <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="consent" value="1"> <span>J'accepte que <?= $h($gallery['name']) ?> enregistre mon e-mail pour ce programme de fidélité (effaçable à tout moment depuis mon espace).</span></label>
      <button class="primary" type="submit">Recevoir mon lien</button>
      <input type="hidden" name="g" value="<?= $h($slug) ?>">
      <?php if ($social = oauth_buttons_html('gvisitor', [], false)): ?>
        <div class="oauth-sep"><span>ou</span></div>
        <?= $social ?>
        <p class="oauth-note">Avec un réseau social, vous êtes connecté tout de suite, sans e-mail à ouvrir (la case ci-dessus doit être cochée).</p>
      <?php endif; ?>
    </form>
  </section>

  <div id="me" hidden style="display:grid;gap:14px;">
    <section class="card">
      <p class="soft" id="who"></p>
      <p class="pts"><span id="pts">0</span> <small style="font-size:1rem;font-weight:400;">points</small></p>
      <div class="bar" id="bar-wrap" hidden><i id="bar" style="width:0"></i></div>
      <p class="soft" id="next"></p>
    </section>
    <section class="card" id="tiers-card" hidden><h2>Mes récompenses</h2><ul class="list" id="tiers"></ul></section>
    <section class="card" id="rewards-card" hidden><h2>Récompenses obtenues</h2><p class="soft">Présentez le code à l'organisateur pour la recevoir.</p><ul class="list" id="rewards"></ul></section>
    <section class="card" id="stands-card" hidden>
      <h2>Gagner des points</h2>
      <p class="soft">Scannez avec l'appareil photo de votre téléphone le QR code affiché chez chaque lieu participant : <?= (int) $s['per_scan'] ?> points par lieu et par jour.</p>
      <ul class="list" id="stands"></ul>
    </section>
    <section class="card"><div class="row"><a class="btn" href="<?= $h($cfg['galleryUrl']) ?>">← Retour à la galerie</a><span><button type="button" id="logout">Me déconnecter</button> <button type="button" id="forget">Effacer mes données</button></span></div></section>
  </div>
  <?php if ($loc): ?>
  <section class="card" id="loc" aria-labelledby="h-loc">
    <h2 id="h-loc">Où trouver un stand ou une boutique</h2>
    <p class="soft">Cherchez un nom, un numéro d'emplacement ou une adresse. Sur place, « Me localiser » trie les lieux par distance et ouvre l'itinéraire.</p>
    <?php if ($evt = $loc['event']): ?>
      <p class="soft"><strong><?= $h($evt['title']) ?></strong><?= $evt['date'] !== '' ? ' · ' . $h($evt['date']) : '' ?><?= $evt['hours'] !== '' ? ' · ' . $h($evt['hours']) : '' ?><?= $evt['address'] !== '' ? '<br>' . $h($evt['address']) : '' ?></p>
    <?php endif; ?>
    <div class="row" style="align-items:stretch;justify-content:flex-start;">
      <label style="flex:1 1 230px;">Rechercher<input type="search" id="loc-q" placeholder="Nom, emplacement, rue…" autocomplete="off"></label>
      <button type="button" id="loc-geo" style="align-self:end;">Me localiser</button>
    </div>
    <div id="loc-filters" class="row" style="justify-content:flex-start;gap:6px;" hidden>
      <button type="button" class="loc-chip" data-k="all" aria-pressed="true">Tout</button>
      <button type="button" class="loc-chip" data-k="boutique" aria-pressed="false"><?= $h(ucfirst((string) ($vocab['members'] ?? 'Commerces'))) ?></button>
      <button type="button" class="loc-chip" data-k="emplacement" aria-pressed="false">Emplacements</button>
      <button type="button" class="loc-chip" data-k="evenement" aria-pressed="false">Événement</button>
    </div>
    <p class="soft" id="loc-status" role="status" aria-live="polite"></p>
    <div class="loc-list" id="loc-list"></div>
    <p class="soft" id="loc-none" hidden>Aucun lieu ne correspond à cette recherche.</p>
    <p><button type="button" id="loc-map-toggle">Afficher le plan</button></p>
    <div id="loc-mapbox" hidden>
      <div id="loc-map" role="application" aria-label="Plan des lieux"></div>
      <p class="soft" id="loc-map-msg" style="margin:6px 0 0;" role="status"></p>
    </div>
  </section>
  <?php endif; ?>
  <p class="soft" id="foot" hidden><a href="<?= $h($cfg['galleryUrl']) ?>">← Retour à la galerie</a></p>
</main>
<script>
(function () {
  var root = document.getElementById('app'), cfg = JSON.parse(root.dataset.cfg), $ = function (id) { return document.getElementById(id); }, token = '', me = null;
  function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k) || ''; if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} return ''; }
  function say(t, err) { var m = $('msg'); m.textContent = t || ''; m.className = 'msg' + (err ? ' err' : ''); }
  function el(t, text, cls) { var n = document.createElement(t); if (text != null) n.textContent = text; if (cls) n.className = cls; return n; }
  function api(body) { body.g = cfg.g; body.token = token; return fetch(cfg.api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json().then(function (j) { j.status = r.status; return j; }); }); }

  var q = new URLSearchParams(location.search), t = q.get('t'), sc = q.get('s');
  if (t && /^[a-f0-9]{32}$/.test(t)) ls(cfg.key, t);
  if (sc && /^[a-z0-9]{6}\.[a-f0-9]{16}$/.test(sc)) ls(cfg.key + '_s', sc);          // QR scanné : crédité dès la connexion
  if (t || sc) history.replaceState(null, '', location.pathname + '?g=' + encodeURIComponent(cfg.g));
  token = ls(cfg.key);

  function render() {
    $('login').hidden = !!me || !cfg.loyalty; $('me').hidden = !me; $('foot').hidden = !!me;
    if (!me) return;
    $('who').textContent = me.name ? 'Bonjour ' + me.name + ' · ' + me.email : me.email;
    $('pts').textContent = me.points;
    var nx = me.next; $('bar-wrap').hidden = !nx;
    if (nx) { $('bar').style.width = Math.min(100, Math.round(me.points / nx.pts * 100)) + '%'; $('next').textContent = 'Encore ' + (nx.pts - me.points) + ' points pour : ' + nx.label; }
    else $('next').textContent = me.tiers.length ? 'Vous avez atteint tous les paliers : échangez vos points ci-dessous.' : '';
    $('tiers-card').hidden = !me.tiers.length; var tl = $('tiers'); tl.textContent = '';
    me.tiers.forEach(function (tr, i) {
      var li = el('li'), b = el('button', 'Échanger', 'primary'); b.disabled = me.points < tr.pts;
      b.addEventListener('click', function () { if (confirm('Échanger ' + tr.pts + ' points contre « ' + tr.label + ' » ?')) api({ action: 'redeem', tier: i }).then(function (j) { j.error ? say(j.error, true) : (say(j.message), me = j.me, render()); }); });
      li.appendChild(el('span', tr.label + ' — ' + tr.pts + ' pts')); li.appendChild(b); tl.appendChild(li);
    });
    $('rewards-card').hidden = !me.rewards.length; var rl = $('rewards'); rl.textContent = '';
    me.rewards.forEach(function (r) { var li = el('li'); li.appendChild(el('span', r.label + (r.status === 'used' ? ' — remise' : ''))); li.appendChild(el('span', r.code, 'code')); if (r.status === 'used') li.style.opacity = '.5'; rl.appendChild(li); });
    $('stands-card').hidden = !me.stands.length; var sl = $('stands'); sl.textContent = '';
    me.stands.forEach(function (s) { var li = el('li'); li.appendChild(el('span', s.name)); li.appendChild(el('span', s.done ? '✓ scanné aujourd\'hui' : '', 'soft')); sl.appendChild(li); });
  }
  function boot() {
    if (!cfg.loyalty || !token) { render(); return; }
    api({ action: 'me' }).then(function (j) {
      if (j.error) { if (j.status === 401) { ls(cfg.key, null); token = ''; say('Ce lien n\'est plus valide : demandez-en un nouveau.', true); } else say(j.error, true); render(); return; }
      me = j.me; render();
      var pend = ls(cfg.key + '_s');
      if (pend) { ls(cfg.key + '_s', null); api({ action: 'scan', scan: pend }).then(function (r) { r.error ? say(r.error, true) : (say(r.message), me = r.me, render()); }); }
    }).catch(function () { say('Connexion impossible : réessayez.', true); });
  }
  $('login-form').addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.name === 'provider') return;          // bouton « Continuer avec… » : envoi normal vers oauth/start.php
    e.preventDefault(); var f = new FormData(e.target), b = { action: 'login', email: f.get('email'), name: f.get('name'), website: f.get('website'), consent: f.get('consent') ? 1 : 0 };
    say('Envoi…'); api(b).then(function (j) { j.error ? say(j.error, true) : say(j.message); }).catch(function () { say('Envoi impossible : réessayez.', true); });
  });
  $('logout').addEventListener('click', function () { ls(cfg.key, null); token = ''; me = null; say(''); render(); });
  $('forget').addEventListener('click', function () { if (confirm('Effacer définitivement mon e-mail, mes points et mes récompenses ?')) api({ action: 'forget' }).then(function () { ls(cfg.key, null); token = ''; me = null; say('Vos données sont effacées.'); render(); }); });

  // Localisation : liste des commerces et emplacements, recherche, distance depuis la position et plan chargé seulement à la demande.
  (function () {
    var loc = cfg.loc;
    if (!loc || !$('loc')) return;
    var list = $('loc-list'), none = $('loc-none'), status = $('loc-status'), q = $('loc-q'), filters = $('loc-filters');
    var mapBox = $('loc-mapbox'), mapEl = $('loc-map'), mapBtn = $('loc-map-toggle'), mapMsg = $('loc-map-msg');
    var filter = 'all', pos = null, posAcc = 0, map = null, mapPromise = null, meLayer = null, spotLayers = {}, ready = false;

    function norm(s) { s = (s == null ? '' : String(s)).toLowerCase(); return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s; }
    function distance(a, b) {
      var R = 6371000, rad = Math.PI / 180, dLat = (b[0] - a[0]) * rad, dLng = (b[1] - a[1]) * rad;
      var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a[0] * rad) * Math.cos(b[0] * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
      return 2 * R * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }
    function fmtDist(m) { return m < 1000 ? Math.max(10, Math.round(m / 10) * 10) + ' m' : (m / 1000).toFixed(m < 10000 ? 1 : 0).replace('.', ',') + ' km'; }
    function spotDist(s) { return pos && s.point ? distance(pos, s.point) : null; }
    function typeLabel(t) { return t === 'boutique' ? 'Boutique' : (t === 'emplacement' ? 'Emplacement' : 'Événement'); }
    function directions(s) {
      var dest = s.point ? s.point[0].toFixed(6) + ',' + s.point[1].toFixed(6) : s.address;
      return dest ? 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dest) : '';
    }

    function render() {
      var term = norm(q.value);
      var rows = loc.spots.filter(function (s) {
        if (filter !== 'all' && s.type !== filter) return false;
        return !term || norm([s.name, s.stand, s.zone, s.address, s.note].join(' ')).indexOf(term) >= 0;
      });
      rows.sort(function (a, b) {
        var da = spotDist(a), db = spotDist(b);
        if (da != null && db != null) return da - db;
        return da != null ? -1 : (db != null ? 1 : 0);
      });
      list.textContent = '';
      rows.forEach(function (s) {
        var card = el('article', null, 'loc-item'), h = el('h3', s.name); card.appendChild(h);
        var meta = [];
        if (!(s.type === 'emplacement' && s.stand)) meta.push(typeLabel(s.type));
        if (s.stand) meta.push('Emplacement ' + s.stand);
        if (s.zone) meta.push(s.zone);
        if (s.address) meta.push(s.address);
        if (s.note) meta.push(s.note);
        if (pos && s.point) meta.push(fmtDist(spotDist(s)));
        if (meta.length) card.appendChild(el('p', meta.join(' · '), 'meta'));
        var acts = el('div', null, 'loc-acts');
        if (s.point || s.address) {
          var b = el('button', 'Voir sur le plan'); b.type = 'button'; b.addEventListener('click', function () { showOnMap(s); }); acts.appendChild(b);
        }
        var d = directions(s);
        if (d) { var a = el('a', 'Itinéraire', 'btn'); a.href = d; a.target = '_blank'; a.rel = 'noopener'; acts.appendChild(a); }
        if (s.url) { var u = el('a', 'Sa boutique', 'btn'); u.href = s.url; u.target = '_blank'; u.rel = 'noopener'; acts.appendChild(u); }
        if (acts.children.length) card.appendChild(acts);
        list.appendChild(card);
      });
      none.hidden = rows.length > 0;
      status.textContent = rows.length ? rows.length + (rows.length > 1 ? ' lieux' : ' lieu') + (pos ? ' · triés du plus proche au plus loin.' : '.') : '';
      if (map && ready) {
        Object.keys(spotLayers).forEach(function (id) { map.removeLayer(spotLayers[id]); });
        spotLayers = {};
        rows.forEach(function (s) { if (s.point) addSpot(s); });
      }
    }

    var types = {};
    loc.spots.forEach(function (s) { types[s.type] = 1; });
    filters.hidden = Object.keys(types).length < 2;
    [].forEach.call(filters.querySelectorAll('.loc-chip'), function (b) {
      if (b.dataset.k !== 'all' && !types[b.dataset.k]) b.hidden = true;
      b.addEventListener('click', function () {
        filter = b.dataset.k;
        [].forEach.call(filters.querySelectorAll('.loc-chip'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        render();
      });
    });
    q.addEventListener('input', render);
    render();

    function loadLeaflet() {
      return new Promise(function (ok, ko) {
        if (window.L) return ok(window.L);
        var css = document.createElement('link'), js = document.createElement('script');
        css.rel = 'stylesheet'; css.href = '/assets/lib/leaflet/leaflet.min.css';
        js.src = '/assets/lib/leaflet/leaflet.min.js'; js.async = true;
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
      var title = it.role === 'slot' && it.num ? 'Emplacement ' + it.num : (it.role === 'zone' || it.role === 'territoire' || it.role === 'secteur' ? (it.label || it.ztype || 'Zone') : (it.shop || it.label || 'Repère'));
      h.textContent = title; p.appendChild(h);
      [it.zone, it.note].forEach(function (v) { if (v) { var n = document.createElement('div'); n.textContent = v; p.appendChild(n); } });
      return p;
    }
    function spotPopup(s) {
      var p = document.createElement('div'), h = document.createElement('strong'); h.textContent = s.name; p.appendChild(h);
      [s.stand ? 'Emplacement ' + s.stand : '', s.zone, s.address, s.note].forEach(function (v) { if (v) { var n = document.createElement('div'); n.textContent = v; p.appendChild(n); } });
      var d = directions(s);
      if (d) { var a = document.createElement('a'); a.href = d; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Itinéraire ↗'; a.style.display = 'block'; p.appendChild(a); }
      if (s.url) { var u = document.createElement('a'); u.href = s.url; u.target = '_blank'; u.rel = 'noopener'; u.textContent = 'Sa boutique ↗'; u.style.display = 'block'; p.appendChild(u); }
      return p;
    }
    function addSpot(s) {
      if (!map || !s.point) return null;
      var color = s.type === 'boutique' ? (cfg.accent || '#2f6b4f') : (s.type === 'emplacement' ? '#1f1f1f' : '#b5502e');
      var layer = L.circleMarker(s.point, { radius: 9, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1 });
      layer.bindPopup(function () { return spotPopup(s); });
      layer.addTo(map);
      spotLayers[s.id] = layer;
      return layer;
    }
    function focusSpot(s) {
      if (!map || !s.point) return;
      var layer = spotLayers[s.id] || addSpot(s);
      map.setView(s.point, Math.max(map.getZoom() || 17, 18));
      if (layer && layer.openPopup) layer.openPopup();
    }
    function showPosition(acc) {
      if (!map || !pos) return;
      if (meLayer) map.removeLayer(meLayer);
      var c = L.circle(pos, { radius: Math.max(15, acc || 30), color: '#1a73e8', weight: 1, fillColor: '#1a73e8', fillOpacity: .12 });
      var m = L.circleMarker(pos, { radius: 7, color: '#fff', weight: 2, fillColor: '#1a73e8', fillOpacity: 1 });
      meLayer = L.layerGroup([c, m]).addTo(map);
      map.setView(pos, Math.max(map.getZoom() || 17, 17));
    }
    function geocodeFirst() {
      var s = null;
      for (var i = 0; i < loc.spots.length; i++) if (loc.spots[i].address && !loc.spots[i].point) { s = loc.spots[i]; break; }
      if (!s) return;
      geocodeAddress(s.address).then(function (pt) {
        if (!pt) { mapMsg.textContent = 'Adresse introuvable : utilisez « Itinéraire ».'; return; }
        s.point = pt; addSpot(s); map.setView(pt, 17);
      });
    }
    function initMap() {
      if (map) return Promise.resolve(map);
      if (mapPromise) return mapPromise;
      mapMsg.textContent = 'Chargement du plan…';
      mapPromise = loadLeaflet().then(function (L) {
        map = L.map(mapEl, { scrollWheelZoom: false, maxZoom: 22 });
        if (loc.base === 'none') {
          mapEl.style.background = 'linear-gradient(rgba(0,0,0,.06) 1px,transparent 1px) 0 0/40px 40px, linear-gradient(90deg,rgba(0,0,0,.06) 1px,transparent 1px) 0 0/40px 40px, #f6f3ec';
        } else {
          var sat = loc.base === 'sat';
          var tl = L.tileLayer(loc.tiles[sat ? 'sat' : 'osm'], { maxZoom: 22, maxNativeZoom: 19, attribution: sat ? 'Imagerie © IGN' : 'Plan © IGN' });
          tl.on('tileerror', function () {
            if (!map.hasLayer(tl)) return;
            map.removeLayer(tl);
            tl = sat ? L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 22, maxNativeZoom: 19, attribution: 'Imagerie © Esri, Maxar' })
              : L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png', { subdomains: 'abcd', maxZoom: 22, maxNativeZoom: 20, attribution: '© OpenStreetMap, © CARTO' });
            tl.addTo(map);
          });
          tl.addTo(map);
        }
        if (loc.background) L.imageOverlay(loc.background.url, loc.background.bounds, { opacity: loc.background.opacity }).addTo(map);
        L.control.scale({ metric: true, imperial: false }).addTo(map);
        var bounds = [], backLayers = [];
        loc.items.forEach(function (it) {
          var layer;
          if (it.kind === 'marker') layer = L.circleMarker(it.pts[0], { radius: 7, color: '#fff', weight: 2, fillColor: it.color, fillOpacity: 1 });
          else if (it.kind === 'polygon') layer = L.polygon(it.pts, (it.role === 'zone' || it.role === 'territoire' || it.role === 'secteur')
            ? { color: it.color, weight: 2, dashArray: '8 6', fillColor: it.color, fillOpacity: .07 }
            : { color: it.color, weight: 2, fillColor: it.color, fillOpacity: .3 });
          else layer = L.polyline(it.pts, { color: it.color, weight: 3 });
          if ((it.role === 'zone' || it.role === 'territoire' || it.role === 'secteur') && it.label && layer.getBounds) {
            var bd = layer.getBounds();
            var sp = document.createElement('span');
            sp.textContent = it.ztype && it.label.toLowerCase().indexOf(it.ztype.toLowerCase()) !== 0 ? it.ztype + ' · ' + it.label : it.label;
            sp.style.cssText = 'display:inline-block;transform:translate(-50%,-100%);white-space:nowrap;font:700 11px/1.3 system-ui,sans-serif;color:#222;background:rgba(255,255,255,.88);border:1px solid rgba(0,0,0,.3);border-radius:4px;padding:2px 7px;pointer-events:none';
            L.marker([bd.getNorth(), bd.getCenter().lng], { icon: L.divIcon({ className: '', html: sp.outerHTML, iconSize: [0, 0] }), interactive: false, keyboard: false }).addTo(map);
          }
          if (it.role === 'slot') {
            layer.bindPopup(function () { return popup(it); });
            if (it.num) layer.bindTooltip('Emplacement ' + it.num + (it.zone ? ' · ' + it.zone : ''), { direction: 'center' });
          } else if (it.kind === 'marker' && (it.shop || it.label)) {
            layer.bindTooltip(it.shop || it.label, { direction: 'top' });
          }
          layer.addTo(map);
          if (it.role === 'zone' || it.role === 'territoire' || it.role === 'secteur') backLayers.push(layer);
          bounds.push(layer);
        });
        map.whenReady(function () { backLayers.forEach(function (l) { l.bringToBack(); }); });
        loc.spots.forEach(function (s) { if (s.point) { var l = addSpot(s); if (l) bounds.push(l); } });
        var group = L.featureGroup(bounds);
        if (group.getLayers().length) map.fitBounds(group.getBounds().pad(.2));
        else if (loc.background) map.fitBounds(loc.background.bounds);
        else if (loc.center) map.setView(loc.center, loc.zoom);
        else geocodeFirst();
        ready = true;
        mapMsg.textContent = '';
        if (pos) showPosition(posAcc);
        setTimeout(function () { map.invalidateSize(); }, 60);
        return map;
      }).catch(function (e) {
        mapPromise = null;
        mapMsg.textContent = 'Le plan est indisponible pour le moment.';
        throw e;
      });
      return mapPromise;
    }
    function showOnMap(s) {
      mapBox.hidden = false; mapBtn.textContent = 'Masquer le plan';
      initMap().then(function () {
        setTimeout(function () { if (map) map.invalidateSize(); }, 60);
        if (s.point) { setTimeout(function () { focusSpot(s); }, 100); return; }
        if (!s.address) return;
        mapMsg.textContent = 'Recherche de l\'adresse…';
        geocodeAddress(s.address).then(function (pt) {
          if (!pt) { mapMsg.textContent = 'Adresse introuvable sur le plan : utilisez « Itinéraire ».'; return; }
          s.point = pt; mapMsg.textContent = ''; render();
          setTimeout(function () { focusSpot(s); }, 60);
        });
      }).catch(function () {});
    }
    mapBtn.addEventListener('click', function () {
      if (!mapBox.hidden) { mapBox.hidden = true; mapBtn.textContent = 'Afficher le plan'; return; }
      mapBox.hidden = false; mapBtn.textContent = 'Masquer le plan';
      initMap().then(function () { setTimeout(function () { map.invalidateSize(); }, 60); }).catch(function () {});
    });
    $('loc-geo').addEventListener('click', function () {
      if (!navigator.geolocation) { status.textContent = 'Votre navigateur ne donne pas la position.'; return; }
      status.textContent = 'Recherche de votre position…';
      navigator.geolocation.getCurrentPosition(function (p) {
        pos = [p.coords.latitude, p.coords.longitude]; posAcc = p.coords.accuracy;
        render();
        if (map && ready) showPosition(posAcc);
      }, function (e) {
        status.textContent = e && e.code === 1 ? 'Position refusée : autorisez la localisation dans votre navigateur.' : 'Position indisponible : réessayez dehors ou près d\'une fenêtre.';
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    });
  })();

  // Installation sur l'écran d'accueil
  (function () {
    var box = $('install'), btn = $('install-btn'), help = $('install-help'), ev = null;
    if (window.PwaSave) {                                   // feuille commune : étapes adaptées au navigateur
      if (box && !window.PwaSave.isStandalone()) box.hidden = false;
      if (btn) btn.addEventListener('click', function () { window.PwaSave.open(); });
      return;
    }
    if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone) return;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent); box.hidden = false;
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); ev = e; });
    window.addEventListener('appinstalled', function () { box.hidden = true; });
    btn.addEventListener('click', function () {
      if (ev) { ev.prompt(); ev.userChoice.then(function () { ev = null; }); return; }
      help.textContent = ios ? 'Sur iPhone : touchez le bouton Partager de Safari, puis « Sur l\'écran d\'accueil ».' : 'Ouvrez le menu de votre navigateur (⋮), puis « Installer l\'application » ou « Ajouter à l\'écran d\'accueil ».'; help.style.fontWeight = '600';
    });
  })();
  boot();
})();
</script>
</body>
</html>
