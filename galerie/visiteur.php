<?php
// Espace visiteur de la galerie (web app installable) : fidélité propre à la galerie, indépendante des boutiques. Voir includes/gallery-loyalty.php.
//   GET  ?g=<galerie>[&t=<jeton>][&s=<lieu.code>]  la page ; GET ?g=<galerie>&manifest=1  le manifeste d'installation
//   POST JSON {g, action: login | me | scan | redeem | forget, token…}  l'API de la page
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-loyalty.php';
require_once __DIR__ . '/../includes/oauth.php';

$in = $_POST;
$slug = strtolower((string) ($_GET['g'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $j = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($j) ? $j : $in;
    $slug = strtolower((string) ($in['g'] ?? $slug));
}
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$open = $gallery && gl_open($gallery);

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

if (isset($_GET['manifest'])) {                // manifeste : nom et icône de la galerie, ouverture sur l'espace visiteur
    header('Content-Type: application/manifest+json; charset=utf-8');
    $name = $gallery ? $gallery['name'] : 'Galerie';
    $logo = $gallery ? (string) ($gallery['style']['logo'] ?? '') : '';
    echo json_encode(['name' => $name . ' — espace visiteur', 'short_name' => mb_substr($name, 0, 12), 'start_url' => '/galerie/visiteur.php?g=' . $slug, 'scope' => '/galerie/', 'display' => 'standalone',
        'background_color' => '#ffffff', 'theme_color' => $gallery['accent'] ?? '#2f6b4f',
        'icons' => $logo !== '' ? [['src' => '/' . ltrim($logo, '/'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']] : []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
$flashErr = '';
if (isset($_COOKIE[session_name()])) {          // message laissé par la connexion sociale (oauth/finish.php) ; session ouverte seulement si le visiteur en a déjà une
    session_start();
    $flashErr = (string) ($_SESSION['gv_error'] ?? '');
    unset($_SESSION['gv_error']);
    session_write_close();
}
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#2f6b4f';
$ink = (0.299 * hexdec(substr($accent, 1, 2)) + 0.587 * hexdec(substr($accent, 3, 2)) + 0.114 * hexdec(substr($accent, 5, 2))) > 160 ? '#1f1f1f' : '#ffffff';
$cfg = ['g' => $slug, 'api' => '/galerie/visiteur.php', 'key' => 'gl_t_' . $slug, 'program' => $s['program_name'], 'galleryUrl' => '/galerie/' . $slug . '/'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= $h($accent) ?>">
<link rel="manifest" href="/galerie/visiteur.php?g=<?= $h($slug) ?>&manifest=1">
<?php $pwaLogo = (string) ($gallery['style']['logo'] ?? ''); ?>
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-save.js') ?>" data-auto data-delay="2200" data-key="gl-visiteur-<?= $h($slug) ?>" data-name="<?= $h($s['program_name'] . ' — ' . $gallery['name']) ?>"<?php if ($pwaLogo !== ''): ?> data-icon="<?= $h('/' . ltrim($pwaLogo, '/')) ?>"<?php endif; ?> data-accent="<?= $h($accent) ?>" data-sw="/galerie/pwa-sw.php" data-scope="/galerie/"></script>
<title><?= $h($s['program_name']) ?> — <?= $h($gallery['name']) ?></title>
<link rel="stylesheet" href="/assets/oauth.css">
<style>
  :root { --a: <?= $h($accent) ?>; --ai: <?= $h($ink) ?>; --line: #ddd; --soft: #666; }
  * { box-sizing: border-box; } body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #f6f4ee; }
  header { background: var(--a); color: var(--ai); padding: 22px 16px 18px; } header .in, main { max-width: 640px; margin: 0 auto; } header p { margin: 0; opacity: .85; font-size: .85rem; letter-spacing: .08em; text-transform: uppercase; } header h1 { margin: 4px 0 0; font-size: 1.6rem; }
  main { padding: 16px; display: grid; gap: 14px; } [hidden] { display: none !important; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px; display: grid; gap: 10px; } .card h2 { margin: 0; font-size: 1.05rem; }
  .soft { color: var(--soft); font-size: .88rem; margin: 0; } .pts { font-size: 3rem; font-weight: 700; line-height: 1; margin: 0; }
  .bar { height: 10px; background: #e8e5dc; border-radius: 99px; overflow: hidden; } .bar i { display: block; height: 100%; background: var(--a); }
  label { display: grid; gap: 3px; font-size: .88rem; } input[type=email], input[type=text] { font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; width: 100%; }
  button, .btn { font: inherit; font-weight: 600; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--a); background: #fff; color: var(--a); cursor: pointer; text-decoration: none; display: inline-block; }
  button.primary { background: var(--a); color: var(--ai); } button:disabled { opacity: .45; cursor: default; }
  .msg { min-height: 1.3em; margin: 0; font-weight: 600; } .err { color: #b42318; } .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
  ul.list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } ul.list li { display: flex; gap: 10px; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; }
  .code { font: 700 1.2rem ui-monospace, monospace; letter-spacing: .1em; padding: 2px 8px; border: 2px dashed var(--a); border-radius: 6px; }
</style>
</head>
<body>
<header><div class="in"><p>Espace visiteur</p><h1><?= $h($s['program_name']) ?> · <?= $h($gallery['name']) ?></h1></div></header>
<main id="app" data-cfg="<?= $h(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>">
  <?php if ($s['intro'] !== ''): ?><p class="soft"><?= $h($s['intro']) ?></p><?php endif; ?>
  <p class="msg<?= $flashErr !== '' ? ' err' : '' ?>" id="msg" role="status"><?= $h($flashErr) ?></p>

  <section class="card" id="install" hidden>
    <h2>📲 Installer l'appli sur mon téléphone</h2>
    <p class="soft" id="install-help">Retrouvez vos points d'un seul geste, comme une application.</p>
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
    $('login').hidden = !!me; $('me').hidden = !me; $('foot').hidden = !!me;
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
    if (!token) { render(); return; }
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

  // Installation sur l'écran d'accueil
  (function () {
    var box = $('install'), btn = $('install-btn'), help = $('install-help'), ev = null;
    if (window.PwaSave) {                                   // feuille commune : étapes adaptées au navigateur
      if (box && !window.PwaSave.isStandalone()) box.hidden = false;
      if (btn) btn.addEventListener('click', function () { window.PwaSave.open(); });
      return;
    }
    if ((window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone) return;
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
