<?php
// Web app des visiteurs (page /visiteur.php de la boutique) : fidélité, alertes chineur, réservations et billets, scan des QR codes.
// Seules les rubriques des modules actifs et sous licence sont proposées. Les données viennent de /api/visiteur/… avec le lien personnel
// envoyé par e-mail (jeton) ; aucun mot de passe, aucun cookie : le jeton est gardé dans le navigateur (stockage local) et peut être effacé.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';
if (!addon_is_entitled(EV_MODULE)) return;

$s = ev_settings();
$sec = ev_sections();
$loyalty = $s['enabled'] === '1';
$alertApi = '';
if ($sec['alertes']) {
    require_once dirname(__DIR__, 2) . '/alerte-chineur/lib/ac.php';
    $alertApi = ac_url('abonner');
}
$tabs = [];
if ($loyalty) $tabs['fid'] = $s['program_name'];
if ($sec['alertes']) $tabs['alertes'] = 'Mes alertes';
if ($sec['billets']) $tabs['billets'] = 'Réservations';
if ($sec['scan']) $tabs['scan'] = 'Scanner un QR';
if (!$tabs) $tabs['fid'] = 'Mon espace';
$cfg = ['api' => ev_url(''), 'alertApi' => $alertApi, 'storeKey' => 'ev_t_' . tenant_slug(), 'program' => $s['program_name'], 'loyalty' => $loyalty, 'sections' => $sec, 'cart' => '/cart.php'];
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
require_once dirname(__DIR__, 3) . '/includes/oauth.php';
$socialErr = (string) ($_SESSION['ev_oauth_error'] ?? '');       // message laissé par la connexion sociale (oauth/finish.php)
unset($_SESSION['ev_oauth_error']);
?>
<link rel="stylesheet" href="/assets/oauth.css">
<style>
  .ev { max-width: 760px; margin: 28px auto 56px; padding: 0 16px; display: grid; gap: 16px; }
  .ev h1 { margin: 0; font-size: 1.8rem; } .ev h2 { margin: 0 0 6px; font-size: 1.1rem; }
  .ev-card { border: 1px solid var(--line); border-radius: 12px; padding: 16px; display: grid; gap: 10px; background: var(--surface, transparent); }
  .ev-tabs { display: flex; gap: 8px; flex-wrap: wrap; } .ev-tabs button { font: inherit; padding: 8px 14px; border: 1px solid var(--line); border-radius: 999px; background: transparent; color: inherit; cursor: pointer; }
  .ev-tabs button[aria-selected="true"] { background: var(--accent); color: var(--accent-ink, #fff); border-color: var(--accent); }
  .ev-points { font-size: 2.6rem; font-weight: 700; line-height: 1; } .ev-soft { color: var(--ink-soft); font-size: .88rem; margin: 0; }
  .ev-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; } .ev label { display: grid; gap: 3px; font-size: .85rem; } .ev input[type=text], .ev input[type=email] { padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; font: inherit; min-width: 190px; }
  .ev table { width: 100%; border-collapse: collapse; font-size: .9rem; } .ev td, .ev th { padding: 6px 4px; border-bottom: 1px solid var(--line); text-align: left; }
  .ev-code { font: 600 1.05rem ui-monospace, monospace; letter-spacing: .06em; padding: 4px 8px; border: 1px dashed var(--line); border-radius: 6px; }
  .ev [hidden] { display: none !important; } .ev-msg { min-height: 1.2em; margin: 0; } .ev video { width: 100%; max-height: 60vh; border-radius: 10px; background: #000; }
</style>
<?php $evSite = trim((string) (get_content()['site_name'] ?? '')) ?: (string) tenant('name'); $evLogo = ltrim((string) logo_url('square'), '/'); ?>
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/../../../assets/pwa-save.js') ?>" data-auto data-delay="2200" data-key="ev-visiteur" data-name="<?= $h($evSite) ?>"<?php if ($evLogo !== ''): ?> data-icon="/<?= $h($evLogo) ?>"<?php endif; ?> data-manifest="/visiteur-manifest.php" data-sw="/pwa-sw.php" data-scope="<?= $h(tenant_base_path() . '/') ?>"></script>
<main class="ev" id="ev" data-cfg="<?= $h(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>" aria-labelledby="ev-title">
  <div><p class="eyebrow">Espace visiteur</p><h1 id="ev-title">Mon espace</h1></div>
  <section class="ev-card" id="ev-install" hidden>
    <h2>📲 Installer l'appli sur mon téléphone</h2>
    <p class="ev-soft" id="ev-install-help">Retrouvez votre espace d'un seul geste, comme une application.</p>
    <p><button type="button" class="btn btn-primary" id="ev-install-btn">Installer l'appli</button></p>
  </section>

  <section class="ev-card" id="ev-login" hidden>
    <h2>Accéder à mon espace</h2>
    <p class="ev-soft">Indiquez votre e-mail : nous vous envoyons un lien personnel, sans mot de passe.</p>
    <form id="ev-login-form" class="ev-row" autocomplete="on">
      <label>Mon e-mail<input type="email" name="email" required maxlength="160" autocomplete="email"></label>
      <label>Prénom (facultatif)<input type="text" name="name" maxlength="60" autocomplete="given-name"></label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
      <label style="grid-column:1/-1;flex-basis:100%;display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="consent" value="1"> <span>J'accepte que <?= $h(get_content()['site_name'] ?? tenant('name')) ?> enregistre mon adresse pour gérer mes points et m'envoyer mon lien personnel. Je peux effacer mon espace à tout moment.</span></label>
      <button class="btn btn-primary" type="submit">Recevoir mon lien</button>
      <?php if ($social = oauth_buttons_html('customer', ['next' => '/visiteur.php'], false)): ?>
        <div class="oauth-sep" style="flex-basis:100%;"><span>ou</span></div>
        <div style="flex-basis:100%;"><?= $social ?></div>
        <p class="oauth-note" style="flex-basis:100%;">Avec un réseau social, vous entrez tout de suite, sans e-mail à ouvrir (cochez d'abord la case d'accord ci-dessus).</p>
      <?php endif; ?>
    </form>
    <p class="ev-msg" id="ev-login-msg" role="<?= $socialErr !== '' ? 'alert' : 'status' ?>"<?= $socialErr !== '' ? ' style="color:#b3261e;"' : '' ?>><?= $h($socialErr) ?></p>
  </section>

  <div id="ev-app" hidden>
    <div class="ev-row" style="justify-content:space-between;align-items:center;margin-bottom:8px;">
      <p class="ev-soft" id="ev-who"></p>
      <span class="ev-row"><button type="button" class="btn" id="ev-logout">Me déconnecter</button></span>
    </div>
  </div>

  <nav class="ev-tabs" role="tablist" aria-label="Rubriques">
    <?php $first = true; foreach ($tabs as $id => $label): ?><button type="button" role="tab" data-tab="<?= $h($id) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= $h($label) ?></button><?php $first = false; endforeach; ?>
  </nav>

  <?php if (isset($tabs['fid'])): ?>
  <section class="ev-card" data-pane="fid" id="ev-fid">
    <div id="ev-fid-guest" class="ev-soft">Connectez-vous avec votre lien personnel pour voir vos points. <button type="button" class="btn" id="ev-fid-login">Recevoir mon lien</button></div>
    <div id="ev-fid-body" hidden>
      <p class="ev-soft"><?= $h($s['program_name']) ?> — vos points</p>
      <p class="ev-points" id="ev-points">0</p>
      <p class="ev-soft" id="ev-next"></p>
      <h2 style="margin-top:12px;">Récompenses</h2>
      <div id="ev-tiers" style="display:grid;gap:8px;"></div>
      <div id="ev-rewards" style="display:grid;gap:8px;margin-top:6px;"></div>
      <h2 style="margin-top:12px;">Historique</h2>
      <table><tbody id="ev-history"></tbody></table>
      <p class="ev-soft" style="margin-top:10px;">Vous gagnez <?= $h(rtrim(rtrim(number_format((float) $s['points_per_euro'], 1, ',', ''), '0'), ',')) ?> point par euro dépensé (hors livraison), crédités après paiement lorsque vous achetez avec l'adresse e-mail de votre espace.</p>
      <p><button type="button" class="btn" id="ev-erase">Effacer mon espace et mes points</button></p>
    </div>
    <p class="ev-msg" id="ev-fid-msg" role="status"></p>
  </section>
  <?php endif; ?>

  <?php if ($sec['alertes']): ?>
  <section class="ev-card" data-pane="alertes" hidden>
    <h2>Mes alertes chineur</h2>
    <p class="ev-soft">Dites-nous ce que vous cherchez : nous vous écrivons dès qu'une pièce correspondante arrive.</p>
    <form id="ev-alert-form" class="ev-row" autocomplete="off">
      <label>Je cherche<input type="text" name="keywords" required minlength="3" maxlength="120" placeholder="ex. céramique vintage"></label>
      <label>Prix max. (€)<input type="text" name="max_price" inputmode="decimal" style="min-width:90px;width:100px;" placeholder="facultatif"></label>
      <label>Mon e-mail<input type="email" name="email" required maxlength="160"></label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
      <button class="btn btn-primary" type="submit">Me prévenir</button>
    </form>
    <p class="ev-msg" id="ev-alert-msg" role="status"></p>
    <p class="ev-soft">Chaque e-mail contient un lien de désinscription.</p>
  </section>
  <?php endif; ?>

  <?php if ($sec['billets']): ?>
  <section class="ev-card" data-pane="billets" hidden>
    <h2>Mes réservations et billets</h2>
    <div id="ev-guest-billets" class="ev-soft">Connectez-vous avec votre lien personnel pour retrouver vos billets. <button type="button" class="btn" id="ev-billets-login">Recevoir mon lien</button></div>
    <div id="ev-billets" hidden>
      <div id="ev-pickups" style="display:grid;gap:8px;"></div>
      <div id="ev-reservations" style="display:grid;gap:8px;margin-top:8px;"></div>
      <p class="ev-soft" id="ev-billets-empty" hidden>Aucune réservation en cours pour cette adresse.</p>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($sec['scan']): ?>
  <section class="ev-card" data-pane="scan" hidden>
    <h2>Scanner un QR code</h2>
    <p class="ev-soft">Scannez le QR code collé sur un objet pour lire son histoire (époque, provenance, certificat).</p>
    <video id="ev-video" playsinline muted hidden></video>
    <p><button type="button" class="btn btn-primary" id="ev-scan-start">Ouvrir l'appareil photo</button> <button type="button" class="btn" id="ev-scan-stop" hidden>Arrêter</button></p>
    <p class="ev-msg" id="ev-scan-msg" role="status"></p>
  </section>
  <?php endif; ?>

  <p style="margin:0;"><a href="/boutique.php">← Retour à la boutique</a></p>
</main>
<script>
(function () {
  // Installation sur l'écran d'accueil : invite du navigateur quand elle existe, sinon la marche à suivre (iPhone, autres navigateurs)
  (function () {
    var box = document.getElementById('ev-install'), btn = document.getElementById('ev-install-btn'), help = document.getElementById('ev-install-help'), ev = null;
    if (!box) return;
    if (window.PwaSave) {                                   // feuille commune : étapes adaptées au navigateur
      if (!window.PwaSave.isStandalone()) box.hidden = false;
      btn.addEventListener('click', function () { window.PwaSave.open(); });
      return;
    }
    if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone) return;
    var l = document.createElement('link'); l.rel = 'manifest'; l.href = '/visiteur-manifest.php'; document.head.appendChild(l);
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    box.hidden = false;
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); ev = e; });
    window.addEventListener('appinstalled', function () { box.hidden = true; });
    btn.addEventListener('click', function () {
      if (ev) { ev.prompt(); ev.userChoice.then(function () { ev = null; }); return; }
      help.textContent = ios ? 'Sur iPhone : touchez le bouton Partager de Safari, puis « Sur l\'écran d\'accueil ».' : 'Ouvrez le menu de votre navigateur (⋮), puis « Installer l\'application » ou « Ajouter à l\'écran d\'accueil ».';
      help.style.fontWeight = '600';
    });
  })();
  var root = document.getElementById('ev'), cfg = JSON.parse(root.dataset.cfg), $ = function (id) { return document.getElementById(id); };
  var token = '', storeOk = true;
  try { var q = new URLSearchParams(location.search).get('t'); if (q && /^[a-f0-9]{32}$/.test(q)) { localStorage.setItem(cfg.storeKey, q); history.replaceState(null, '', location.pathname); } token = localStorage.getItem(cfg.storeKey) || ''; } catch (e) { storeOk = false; }
  function el(t, text, attrs) { var n = document.createElement(t); if (text != null) n.textContent = text; Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); return n; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
  function say(id, t, bad) { var m = $(id); if (m) { m.textContent = t || ''; m.style.color = bad ? '#b3261e' : ''; } }
  function api(path, opt) { return fetch(cfg.api + path, opt).then(function (r) { return r.json().catch(function () { return { error: 'Réponse illisible.' }; }); }); }
  function post(path, body) { return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) }); }

  // Rubriques
  var tabs = [].slice.call(document.querySelectorAll('.ev-tabs button')), panes = [].slice.call(document.querySelectorAll('[data-pane]'));
  function show(id) { tabs.forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === id ? 'true' : 'false'); }); panes.forEach(function (p) { p.hidden = p.dataset.pane !== id; }); if (id !== 'scan') stopScan(); }
  tabs.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
  show(tabs[0] ? tabs[0].dataset.tab : 'fid');

  // Connexion par lien envoyé par e-mail
  function openLogin() { $('ev-login').hidden = false; $('ev-login').scrollIntoView({ behavior: 'smooth', block: 'center' }); var i = document.querySelector('#ev-login-form [name=email]'); if (i) i.focus(); }
  ['ev-fid-login', 'ev-billets-login'].forEach(function (id) { if ($(id)) $(id).addEventListener('click', openLogin); });
  $('ev-login-form').addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.name === 'provider') return;        // bouton « Continuer avec… » : envoi normal vers oauth/start.php
    e.preventDefault(); var d = {}; [].forEach.call(e.target.elements, function (i) { if (i.name) d[i.name] = i.type === 'checkbox' ? i.checked : i.value; });
    say('ev-login-msg', 'Un instant…');
    post('lien', d).then(function (r) { say('ev-login-msg', r.error || r.message, !!r.error); if (!r.error) e.target.reset(); }).catch(function () { say('ev-login-msg', 'Envoi impossible, réessayez.', true); });
  });
  $('ev-logout').addEventListener('click', function () { try { localStorage.removeItem(cfg.storeKey); } catch (e) {} location.reload(); });

  // Espace du membre
  function render(d) {
    $('ev-login').hidden = true; $('ev-app').hidden = false; $('ev-who').textContent = 'Connecté : ' + d.email;
    var ap = document.querySelector('#ev-alert-form [name=email]'); if (ap && !ap.value) ap.value = d.email;
    if (cfg.loyalty && $('ev-fid-body')) {
      $('ev-fid-guest').hidden = true; $('ev-fid-body').hidden = false; $('ev-points').textContent = d.points;
      var next = d.tiers.filter(function (t) { return !t.reachable; })[0];
      $('ev-next').textContent = next ? 'Encore ' + next.missing + ' point' + (next.missing > 1 ? 's' : '') + ' pour ' + eur(next.cents) + ' de remise.' : (d.tiers.length ? 'Vous pouvez obtenir toutes les récompenses.' : '');
      var tw = $('ev-tiers'); tw.textContent = '';
      d.tiers.forEach(function (t) {
        var row = el('div', null, { 'class': 'ev-row', style: 'justify-content:space-between;align-items:center;border:1px solid var(--line);border-radius:8px;padding:8px 10px;' });
        row.appendChild(el('span', eur(t.cents) + ' de remise — ' + t.points + ' points'));
        var b = el('button', t.reachable ? 'Obtenir' : 'Il manque ' + t.missing, { type: 'button', 'class': t.reachable ? 'btn btn-primary' : 'btn' }); b.disabled = !t.reachable;
        b.addEventListener('click', function () { b.disabled = true; post('recompense', { t: token, tier: t.index }).then(function (r) { say('ev-fid-msg', r.error || 'Récompense créée : ' + r.reward.code + '.', !!r.error); load(); }); });
        row.appendChild(b); tw.appendChild(row);
      });
      var rw = $('ev-rewards'); rw.textContent = '';
      d.rewards.forEach(function (r) {
        var row = el('div', null, { 'class': 'ev-row', style: 'justify-content:space-between;align-items:center;' });
        var left = el('span'); left.appendChild(el('span', r.code, { 'class': 'ev-code' })); left.appendChild(document.createTextNode(' ' + eur(r.cents) + (r.status === 'used' ? ' — utilisée' : '')));
        row.appendChild(left);
        if (r.status === 'active') row.appendChild(el('a', 'Utiliser sur mon panier', { 'class': 'btn', href: cfg.cart + '?recompense=' + encodeURIComponent(r.code) }));
        rw.appendChild(row);
      });
      var hb = $('ev-history'); hb.textContent = '';
      if (!d.history.length) { var tr0 = el('tr'); tr0.appendChild(el('td', 'Aucun mouvement pour le moment.')); hb.appendChild(tr0); }
      d.history.forEach(function (h) { var tr = el('tr'); tr.appendChild(el('td', (h.created_at || '').slice(0, 10))); tr.appendChild(el('td', h.note || h.kind)); tr.appendChild(el('td', (h.points > 0 ? '+' : '') + h.points, { style: 'text-align:right;font-weight:600;' })); hb.appendChild(tr); });
    }
    if ($('ev-billets')) {
      $('ev-guest-billets').hidden = true; $('ev-billets').hidden = false;
      var pk = $('ev-pickups'), rs = $('ev-reservations'); pk.textContent = ''; rs.textContent = '';
      d.pickups.forEach(function (p) { var row = el('div', null, { 'class': 'ev-row', style: 'justify-content:space-between;align-items:center;' }); row.appendChild(el('span', 'Retrait sur stand — ' + p.product + (p.expires_at ? ' (jusqu\'au ' + new Date(p.expires_at * 1000).toLocaleDateString('fr-FR') + ')' : ''))); row.appendChild(el('a', 'Ouvrir le billet', { 'class': 'btn btn-primary', href: p.ticket })); pk.appendChild(row); });
      d.reservations.forEach(function (r) { rs.appendChild(el('div', 'Réservation — ' + r.product + ' : versement ' + eur(r.deposit) + ' sur ' + eur(r.price) + ', jusqu\'au ' + new Date(r.expires_at * 1000).toLocaleDateString('fr-FR') + '. Solde à régler en boutique.')); });
      $('ev-billets-empty').hidden = !!(d.pickups.length || d.reservations.length);
    }
  }
  function load() {
    if (!token) { $('ev-login').hidden = false; return; }
    api('moi?t=' + token).then(function (d) {
      if (d.error) { try { localStorage.removeItem(cfg.storeKey); } catch (e) {} token = ''; $('ev-login').hidden = false; say('ev-login-msg', d.error, true); return; }
      render(d);
    });
  }
  if ($('ev-erase')) $('ev-erase').addEventListener('click', function () {
    if (!confirm('Effacer votre espace, vos points et vos récompenses non utilisées ? Cette action est définitive.')) return;
    post('supprimer', { t: token }).then(function (r) { try { localStorage.removeItem(cfg.storeKey); } catch (e) {} alert(r.error || r.message); location.reload(); });
  });
  load();
  if (!token) $('ev-login').hidden = false;

  // Alertes chineur (module Alerte chineur)
  var af = $('ev-alert-form');
  if (af) af.addEventListener('submit', function (e) {
    e.preventDefault(); var d = {}; [].forEach.call(af.elements, function (i) { if (i.name) d[i.name] = i.value; });
    say('ev-alert-msg', 'Un instant…');
    fetch(cfg.alertApi, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(d) }).then(function (r) { return r.json(); })
      .then(function (r) { say('ev-alert-msg', r.error || r.message, !!r.error); if (!r.error) { af.keywords.value = ''; af.max_price.value = ''; } }).catch(function () { say('ev-alert-msg', 'Envoi impossible, réessayez.', true); });
  });

  // Scan des QR codes (module QR Code Story) : lecture par l'appareil photo, uniquement vers une page de cette boutique
  var stream = null, scanning = false;
  function stopScan() { scanning = false; if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } var v = $('ev-video'); if (v) { v.hidden = true; v.srcObject = null; } if ($('ev-scan-stop')) { $('ev-scan-stop').hidden = true; $('ev-scan-start').hidden = false; } }
  if ($('ev-scan-start')) {
    $('ev-scan-stop').addEventListener('click', stopScan);
    $('ev-scan-start').addEventListener('click', function () {
      if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { say('ev-scan-msg', "Ce navigateur ne lit pas les QR codes ici : ouvrez l'appareil photo de votre téléphone, visez le QR code puis touchez le lien.", true); return; }
      var det = new BarcodeDetector({ formats: ['qr_code'] }), v = $('ev-video');
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
        stream = s; v.srcObject = s; v.hidden = false; v.play(); scanning = true; $('ev-scan-stop').hidden = false; $('ev-scan-start').hidden = true; say('ev-scan-msg', 'Visez le QR code…');
        (function tick() {
          if (!scanning) return;
          det.detect(v).then(function (codes) {
            var c = codes[0] && codes[0].rawValue;
            if (c) {
              try { var u = new URL(c, location.href); if (u.origin === location.origin) { stopScan(); location.href = u.href; return; } say('ev-scan-msg', "Ce QR code ne mène pas à cette boutique : il n'a pas été ouvert.", true); } catch (e) { say('ev-scan-msg', "QR code non reconnu.", true); }
            }
            setTimeout(tick, 350);
          }).catch(function () { setTimeout(tick, 500); });
        })();
      }).catch(function () { say('ev-scan-msg', "Accès à l'appareil photo refusé ou impossible.", true); });
    });
  }
})();
</script>
