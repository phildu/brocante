<?php
// Caisse de la buvette (application installable, plein écran sur téléphone) : on touche les articles de la carte, la caisse calcule
// le total et le rendu de monnaie, et note la vente (espèces ou carte) dans l'événement actif. Accès : code bénévole.
// Les ventes alimentent « quantités vendues » du bilan. Appels : buvette/ventes.php. Manifeste : ?manifest=1.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/buvette-caisse.php';
require_once __DIR__ . '/../includes/apps.php';

bv_public_enter();   // galerie d'après l'adresse, extension active (sinon page introuvable)
bv_boot();

$ctx = bv_gallery_context();
$slug = (string) $ctx['slug'];
$gallery = gallery_load($slug) ?: [];
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#b5502e';
$appName = gallery_app_name($gallery, 'gallery_caisse');
$root = '/galerie/' . $slug . '/buvette/';

if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode([
        'id' => $root . 'caisse.php?app=caisse',
        'name' => $appName,
        'short_name' => app_short_name($appName, 12),
        'description' => 'Encaisser les ventes de la buvette.',
        'lang' => 'fr', 'dir' => 'ltr',
        'start_url' => $root . 'caisse.php?source=webapp',
        'scope' => $root,
        'display' => 'standalone', 'orientation' => 'portrait',
        'background_color' => '#ffffff', 'theme_color' => $accent,
        'icons' => [
            ['src' => gallery_app_icon_url($gallery, 'gallery_caisse', 192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => gallery_app_icon_url($gallery, 'gallery_caisse', 512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

bv_require_volunteer();
header('Cache-Control: no-store');
header('X-Bv-Caisse: 1');   // repère la vraie page pour le service worker (hors ligne)

$event = bv_active_event();
$cats = bv_item_categories();
$items = [];
foreach (bv_items_list(true) as $it) {
    $items[] = ['id' => (int) $it['id'], 'name' => (string) $it['name'], 'cents' => (int) $it['price_cents'], 'cat' => isset($cats[$it['category']]) ? $it['category'] : 'autre'];
}
$cfg = [
    'api' => $root . 'ventes.php', 'csrf' => shop_csrf_token(), 'items' => $items, 'cats' => $cats,
    'hasEvent' => $event !== null, 'staff' => bv_is_staff(), 'cardQr' => bv_card_account() !== null,
    'slug' => $slug, 'root' => $root, 'sw' => $root . 'caissesw.php', 'generated' => time(),
    'roster' => $event ? bv_roster((int) $event['id']) : [],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= h($accent) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(app_short_name($appName, 14)) ?>">
<link rel="stylesheet" href="/assets/barcode-scan.css">
<link rel="manifest" href="<?= h($root) ?>caisse.php?manifest=1">
<link rel="apple-touch-icon" href="<?= h(gallery_app_icon_url($gallery, 'gallery_caisse', 180)) ?>">
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/pwa-save.js') ?>" data-auto data-delay="2500" data-key="buvette-caisse-<?= h($slug) ?>" data-name="<?= h($appName) ?>" data-icon="<?= h(gallery_app_icon_url($gallery, 'gallery_caisse', 192)) ?>" data-manifest="<?= h($root) ?>caisse.php?manifest=1" data-sw="<?= h($root) ?>caissesw.php" data-scope="<?= h($root) ?>" data-accent="<?= h($accent) ?>"></script>
<title><?= h($appName) ?></title>
<style>
:root { --ac: <?= h($accent) ?>; --bg: #f7f4ee; --card: #fff; --ink: #1d1b17; --soft: #6b665c; --line: #e2ddd1; --ok: #1e6b3a; --bad: #a4361b; }
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; }
body { font: 16px/1.35 system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); background: var(--bg); -webkit-tap-highlight-color: transparent; }
button { font: inherit; color: inherit; cursor: pointer; }
.app { display: flex; flex-direction: column; height: 100%; max-width: 640px; margin: 0 auto; }
.top { display: flex; align-items: center; gap: 8px; padding: calc(8px + env(safe-area-inset-top, 0px)) 12px 8px; background: var(--ac); color: #fff; }
.top strong { flex: 1; font-size: 1.05rem; }
.top button, .top a { background: rgba(255,255,255,.18); border: 0; color: #fff; border-radius: 10px; padding: 8px 12px; text-decoration: none; font-size: .9rem; }
.day { background: var(--card); border-bottom: 1px solid var(--line); padding: 6px 12px; font-size: .85rem; color: var(--soft); display: flex; justify-content: space-between; }
.grid-wrap { flex: 1; overflow-y: auto; padding: 8px 10px 12px; -webkit-overflow-scrolling: touch; }
.cat { margin: 10px 2px 6px; font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--soft); }
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.art { position: relative; min-height: 78px; padding: 10px; text-align: left; background: var(--card); border: 1px solid var(--line); border-radius: 12px; display: flex; flex-direction: column; justify-content: space-between; user-select: none; touch-action: manipulation; }
.art:active { transform: scale(.97); }
.art .n { font-weight: 600; }
.art .p { color: var(--soft); font-size: .9rem; }
.art .q { position: absolute; top: 6px; right: 6px; min-width: 26px; height: 26px; border-radius: 13px; background: var(--ac); color: #fff; font-weight: 700; font-size: .85rem; display: none; align-items: center; justify-content: center; padding: 0 6px; }
.art.has { border-color: var(--ac); box-shadow: 0 0 0 1px var(--ac) inset; }
.art.has .q { display: flex; }
.bar { background: var(--card); border-top: 1px solid var(--line); padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px)); display: flex; gap: 8px; align-items: center; }
.bar .tot { flex: 1; font-size: 1.5rem; font-weight: 800; }
.bar .tot small { display: block; font-size: .75rem; font-weight: 400; color: var(--soft); }
.btn { border: 0; border-radius: 12px; padding: 14px 18px; font-weight: 700; background: var(--ac); color: #fff; }
.btn.ghost { background: #ece7da; color: var(--ink); }
.btn:disabled { opacity: .4; }
.sheet { position: fixed; inset: 0; z-index: 20; background: rgba(0,0,0,.45); display: none; align-items: flex-end; justify-content: center; }
.sheet.on { display: flex; }
.panel { width: 100%; max-width: 640px; max-height: 92%; overflow-y: auto; background: var(--bg); border-radius: 18px 18px 0 0; padding: 16px 14px calc(16px + env(safe-area-inset-bottom, 0px)); display: grid; gap: 12px; }
.panel h2 { margin: 0; font-size: 1.1rem; }
.lines { margin: 0; padding: 0; list-style: none; display: grid; gap: 4px; font-size: .95rem; }
.lines li { display: flex; justify-content: space-between; gap: 8px; }
.pay { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pay .btn { padding: 18px 10px; font-size: 1.05rem; }
.quick { display: flex; flex-wrap: wrap; gap: 8px; }
.quick .btn { flex: 1 1 70px; padding: 14px 8px; }
.cashin { width: 100%; padding: 14px; font-size: 1.2rem; border: 1px solid var(--line); border-radius: 12px; background: var(--card); }
.change { font-size: 2.4rem; font-weight: 800; text-align: center; color: var(--ok); }
.change.low { color: var(--bad); }
.msg { margin: 0; min-height: 1.2em; font-size: .9rem; color: var(--bad); }
.done { text-align: center; padding: 10px 0; }
.done .big { font-size: 2.6rem; font-weight: 800; color: var(--ok); }
.hist { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
.hist li { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px; display: grid; grid-template-columns: 1fr auto; gap: 2px 8px; font-size: .9rem; }
.hist li.v { opacity: .5; text-decoration: line-through; }
.hist .l { grid-column: 1 / 2; color: var(--soft); font-size: .82rem; }
.hist button { grid-row: 1 / 3; grid-column: 2; align-self: center; background: #f1d9d2; border: 0; border-radius: 8px; padding: 8px 10px; color: var(--bad); font-weight: 600; }
.qrbox { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 12px; width: min(78vw, 320px); margin: 0 auto; line-height: 0; }
.qrbox svg { width: 100%; height: auto; display: block; }
.wait { text-align: center; color: var(--soft); margin: 0; }
.pay.three { grid-template-columns: 1fr 1fr; }
.pay.three .full { grid-column: 1 / -1; }
.net { padding: 8px 12px; font-size: .9rem; text-align: center; background: #fff4e0; color: #8a4b00; border-bottom: 1px solid var(--line); cursor: pointer; }
.net.sync { background: #e6f0ff; color: #1b4a8a; }
.net.bad { background: #f9e3de; color: var(--bad); }
.net[hidden] { display: none; }
.empty { padding: 40px 20px; text-align: center; color: var(--soft); }
</style>
</head>
<body>
<div class="app">
  <div class="top">
    <strong><?= h($ctx['name']) ?> · Caisse</strong>
    <button type="button" id="scan-open" aria-label="Scanner le QR d'un article">📷 Scanner</button>
    <button type="button" id="hist-open">Ventes</button>
    <a href="<?= h($root) ?>index.php">Buvette</a>
  </div>
  <div class="net" id="net" role="status" hidden></div>
<?php if (!$event): ?>
  <p class="empty">Aucun événement actif.<br>Le responsable doit en activer un dans la buvette (Tableau de bord) pour ouvrir la caisse.</p>
<?php elseif (!$items): ?>
  <p class="empty">La carte est vide.<br>Le responsable doit ajouter des articles (Carte &amp; prix).</p>
<?php else: ?>
  <div class="day" id="day"><span><?= h($event['name']) ?></span><span id="day-total">…</span></div>
  <div class="grid-wrap" id="grid"></div>
  <div class="bar">
    <div class="tot"><small id="count">Panier vide</small><span id="total">0,00 €</span></div>
    <button type="button" class="btn ghost" id="clear" disabled>Vider</button>
    <button type="button" class="btn" id="pay" disabled>Encaisser</button>
  </div>
<?php endif; ?>
</div>

<div class="sheet" id="pay-sheet"><div class="panel" id="pay-panel"></div></div>
<div class="sheet" id="hist-sheet"><div class="panel">
  <h2>Ventes de l'événement</h2>
  <p id="hist-sum" class="msg" style="color:var(--soft)"></p>
  <ul class="hist" id="hist"></ul>
  <p class="msg" id="hist-msg"></p>
  <button type="button" class="btn ghost" id="hist-close">Fermer</button>
</div></div>

<script src="/assets/vendor/qrcode-generator.js"></script>
<script src="/assets/barcode-scan.js"></script>
<script>
(function () {
  var CFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }

  // ── Mode dégradé : l'application s'ouvre sans réseau (service worker), les ventes et les gratuités saisies sans réseau sont mises
  //    en file d'attente sur le téléphone et envoyées au retour du réseau (identifiant uid : jamais de doublon). ──
  if ('serviceWorker' in navigator) navigator.serviceWorker.register(CFG.sw, { scope: CFG.root }).catch(function () {});
  var KEY = 'bvc:' + CFG.slug;
  function lsGet(k, d) { try { var v = localStorage.getItem(KEY + ':' + k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem(KEY + ':' + k, JSON.stringify(v)); } catch (e) {} }
  // La carte et les badges sont recopiés sur le téléphone : si la page vient du cache (hors ligne), on garde la copie la plus récente.
  var cache = lsGet('cache', null);
  if (cache && cache.items && cache.items.length && (cache.generated || 0) > (CFG.generated || 0)) { CFG.items = cache.items; CFG.roster = cache.roster || CFG.roster; CFG.generated = cache.generated; }
  else if (CFG.items.length) lsSet('cache', { items: CFG.items, roster: CFG.roster, generated: CFG.generated });
  var queue = lsGet('queue', []);          // opérations pas encore acquittées par le serveur
  function persist() { lsSet('queue', queue); }
  function uid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, '') : Date.now().toString(36) + Math.random().toString(36).slice(2, 12); }
  var netDown = !navigator.onLine, authLost = false, syncError = '', syncing = false, lastState = null;
  function isOffline() { return netDown || !navigator.onLine; }

  if (!CFG.hasEvent || !CFG.items.length) return;

  var basket = {};                       // id -> quantité
  var byId = {}; CFG.items.forEach(function (i) { byId[i.id] = i; });
  function total() { var t = 0; for (var id in basket) t += basket[id] * byId[id].cents; return t; }
  function count() { var n = 0; for (var id in basket) n += basket[id]; return n; }

  function call(data) {
    data.csrf_token = CFG.csrf;
    return fetch(CFG.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) {
        netDown = false;
        return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue (' + r.status + ').' }; }).then(function (j) { if (r.status === 401 || r.status === 403) j.auth = true; return j; });
      })
      .catch(function () { netDown = true; banner(); return { ok: false, network: true, error: 'Pas de réseau.' }; });
  }

  // ── Bandeau d'état et file d'attente ──
  function opLabel(op) {
    if (op.type === 'gift') return '🎁 ' + (op.vname || 'Bénévole') + ' · ' + op.name + ' offert';
    return op.items.map(function (i) { return i.qty + ' × ' + (byId[i.id] ? byId[i.id].name : 'article'); }).join(', ') + ' · ' + eur(op.total_cents) + (op.method === 'card' ? ' · carte (terminal)' : ' · espèces');
  }
  function queuedSalesCents() { return queue.reduce(function (s, o) { return s + (o.type === 'sale' && !o.error ? o.total_cents : 0); }, 0); }
  function banner() {
    var n = $('net'); if (!n) return;
    var q = queue.length, bad = queue.filter(function (o) { return o.error; }).length, text = '', cls = '';
    if (isOffline()) { text = '📴 Hors ligne : la caisse continue' + (q ? ' · ' + q + ' en attente d’envoi' : ''); cls = 'off'; }
    else if (authLost) { text = '🔒 Session expirée : rechargez la page (code bénévole). ' + q + ' opération(s) conservée(s).'; cls = 'bad'; }
    else if (syncError && q) { text = '⚠ ' + syncError + ' · ' + q + ' en attente'; cls = 'bad'; }
    else if (bad) { text = '⚠ ' + bad + ' opération(s) à vérifier (touchez ici)'; cls = 'bad'; }
    else if (q) { text = '↻ Envoi de ' + q + ' opération(s)…'; cls = 'sync'; }
    n.hidden = !text; n.textContent = text; n.className = 'net ' + cls;
    dayLine();
  }
  function dayLine() {
    var d = $('day-total'); if (!d || !lastState) return;
    var s = lastState.summary, extra = queue.filter(function (o) { return o.type === 'sale' && !o.error; }).length;
    d.textContent = s.count + ' vente' + (s.count > 1 ? 's' : '') + ' · ' + eur(s.total_cents) + (extra ? ' (+ ' + extra + ' hors ligne : ' + eur(queuedSalesCents()) + ')' : '');
  }
  function flush() {
    if (syncing || isOffline()) return;
    var ops = queue.filter(function (o) { return !o.error; });
    if (!ops.length) return;
    syncing = true; banner();
    call({ action: 'sync', ops: ops }).then(function (r) {
      syncing = false;
      if (r.network) { banner(); return; }
      if (r.auth) { authLost = true; banner(); return; }
      if (!r.results) { syncError = r.error || 'Envoi impossible'; banner(); return; }
      authLost = false; syncError = '';
      var done = {};
      r.results.forEach(function (x) {
        if (x.ok) done[x.uid] = true;
        else queue.forEach(function (o) { if (o.uid === x.uid) o.error = x.error || 'Refusée'; });
      });
      queue = queue.filter(function (o) { return !done[o.uid]; });
      persist(); applyState(r); banner();
    });
  }
  window.addEventListener('online', function () { netDown = false; banner(); flush(); });
  window.addEventListener('offline', function () { netDown = true; banner(); });
  setInterval(flush, 15000);
  $('net').addEventListener('click', function () { openHist(); });

  // ── Carte ──
  var tiles = {};
  var grid = $('grid');
  Object.keys(CFG.cats).forEach(function (key) {
    var list = CFG.items.filter(function (i) { return i.cat === key; });
    if (!list.length) return;
    grid.appendChild(el('div', 'cat', CFG.cats[key]));
    var g = el('div', 'grid'); grid.appendChild(g);
    list.forEach(function (i) {
      var b = el('button', 'art'); b.type = 'button';
      b.appendChild(el('span', 'n', i.name)); b.appendChild(el('span', 'p', eur(i.cents)));
      var q = el('span', 'q'); b.appendChild(q);
      b.addEventListener('click', function () { basket[i.id] = (basket[i.id] || 0) + 1; refresh(); if (navigator.vibrate) navigator.vibrate(8); });
      // Appui long : retirer une portion.
      var t = null;
      b.addEventListener('touchstart', function () { t = setTimeout(function () { t = 'long'; if (basket[i.id]) { basket[i.id]--; if (!basket[i.id]) delete basket[i.id]; refresh(); } }, 550); }, { passive: true });
      b.addEventListener('touchend', function (e) { if (t === 'long') { e.preventDefault(); } clearTimeout(t === 'long' ? 0 : t); t = null; });
      b.addEventListener('contextmenu', function (e) { e.preventDefault(); });
      g.appendChild(b); tiles[i.id] = { b: b, q: q };
    });
  });

  function refresh() {
    CFG.items.forEach(function (i) {
      var n = basket[i.id] || 0, t = tiles[i.id];
      if (!t) return;
      t.b.classList.toggle('has', n > 0); t.q.textContent = n;
    });
    var n = count();
    $('total').textContent = eur(total());
    $('count').textContent = n ? n + (n > 1 ? ' articles' : ' article') + ' · appui long = en retirer un' : 'Panier vide';
    $('pay').disabled = $('clear').disabled = !n;
  }
  $('clear').addEventListener('click', function () { basket = {}; refresh(); });

  // ── Scan du QR d'un article (carte imprimée) : l'article s'ajoute au panier ──
  function toast(text) {
    var t = el('div', null, text); t.setAttribute('role', 'status');
    t.style.cssText = 'position:fixed;left:50%;top:70px;transform:translateX(-50%);z-index:50;background:#1d1b17;color:#fff;padding:10px 16px;border-radius:12px;max-width:90%;text-align:center';
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 1800);
  }
  function addScanned(raw) {
    if (/[?&]v=\d+\.[a-f0-9]{16}/.test(String(raw || ''))) { openGift(String(raw)); return true; }    // badge d'un bénévole
    var m = /[?&]a=(\d+)/.exec(String(raw || ''));
    var item = m ? byId[+m[1]] : null;
    if (!item) { toast('QR inconnu : ce n’est pas un article de la carte.'); return false; }
    basket[item.id] = (basket[item.id] || 0) + 1; refresh(); toast('+ ' + item.name);
    return true;
  }
  $('scan-open').addEventListener('click', function () {
    if (!window.barcodeScanCamera) { toast('Le lecteur de codes n’est pas chargé.'); return; }
    window.barcodeScanCamera(function (code) { addScanned(code); }, function () { toast('Caméra inaccessible : touchez les articles.'); });
  });

  // ── Encaissement ──
  var sheet = $('pay-sheet'), panel = $('pay-panel');
  function close() { stopPoll(); sheet.classList.remove('on'); }
  sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });
  function recap(parent) {
    var ul = el('ul', 'lines');
    Object.keys(basket).forEach(function (id) {
      var li = el('li'); li.appendChild(el('span', null, basket[id] + ' × ' + byId[id].name)); li.appendChild(el('span', null, eur(basket[id] * byId[id].cents))); ul.appendChild(li);
    });
    parent.appendChild(ul);
  }
  function items() { return Object.keys(basket).map(function (id) { return { id: +id, qty: basket[id] }; }); }

  function openPay() {
    panel.textContent = '';
    panel.appendChild(el('h2', null, 'À encaisser : ' + eur(total())));
    recap(panel);
    var pay = el('div', 'pay');
    var cash = el('button', 'btn', '💶 Espèces'); cash.type = 'button';
    var card = el('button', 'btn', CFG.cardQr ? '💳 Terminal carte' : '💳 Carte'); card.type = 'button';
    pay.appendChild(cash); pay.appendChild(card);
    var msg = el('p', 'msg');
    if (CFG.cardQr && !isOffline()) {
      pay.className = 'pay three';
      var qr = el('button', 'btn full', '📱 Carte : QR à scanner'); qr.type = 'button';
      qr.addEventListener('click', function () { startQr(msg); });
      pay.insertBefore(qr, pay.firstChild);
    }
    panel.appendChild(pay);
    if (CFG.cardQr && isOffline()) { msg.style.color = 'var(--soft)'; msg.textContent = 'Hors ligne : le QR de paiement demande du réseau. Espèces ou terminal carte restent possibles.'; }
    panel.appendChild(msg);
    var back = el('button', 'btn ghost', 'Retour'); back.type = 'button'; back.addEventListener('click', close); panel.appendChild(back);
    cash.addEventListener('click', openCash);
    card.addEventListener('click', function () { send('card', null, msg); });
    sheet.classList.add('on');
  }
  function openCash() {
    var tot = total();
    panel.textContent = '';
    panel.appendChild(el('h2', null, 'Espèces : ' + eur(tot)));
    var quick = el('div', 'quick'), given = tot;
    var inp = el('input', 'cashin'); inp.type = 'number'; inp.inputMode = 'decimal'; inp.step = '0.01'; inp.min = '0'; inp.placeholder = 'Somme remise (€)'; inp.value = (tot / 100).toFixed(2);
    var change = el('div', 'change'); var msg = el('p', 'msg');
    var ok = el('button', 'btn', 'Valider la vente'); ok.type = 'button';
    function upd() {
      var v = Math.round(parseFloat(String(inp.value).replace(',', '.')) * 100);
      given = isNaN(v) ? 0 : v;
      var d = given - tot;
      change.textContent = d >= 0 ? 'Rendre ' + eur(d) : 'Il manque ' + eur(-d);
      change.classList.toggle('low', d < 0); ok.disabled = d < 0;
    }
    var amounts = [tot]; [500, 1000, 2000, 5000].forEach(function (a) { if (a >= tot && amounts.indexOf(a) < 0) amounts.push(a); });
    amounts.slice(0, 5).forEach(function (a) {
      var b = el('button', 'btn ghost', a === tot ? 'Compte juste' : eur(a).replace(',00', '')); b.type = 'button';
      b.addEventListener('click', function () { inp.value = (a / 100).toFixed(2); upd(); }); quick.appendChild(b);
    });
    inp.addEventListener('input', upd);
    ok.addEventListener('click', function () { send('cash', given, msg); });
    var back = el('button', 'btn ghost', 'Retour'); back.type = 'button'; back.addEventListener('click', openPay);
    [quick, inp, change, msg, ok, back].forEach(function (n) { panel.appendChild(n); });
    upd();
  }
  // ── Paiement par QR : le client scanne, paie sur Stripe ; la caisse suit le paiement (réseau requis) ──
  var poll = null;
  function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }
  function startQr(msg) {
    msg.textContent = 'Création du QR…';
    call({ action: 'sale', items: items(), method: 'stripe' }).then(function (r) {
      if (r.network) { msg.textContent = 'Pas de réseau : encaissez en espèces ou au terminal carte.'; return; }
      if (!r.ok) { msg.textContent = r.error || 'Échec.'; return; }
      var sale = r.sale, tot = sale.total_cents;
      applyState(r);
      panel.textContent = '';
      panel.appendChild(el('h2', null, 'Le client scanne et paie : ' + eur(tot)));
      var box = el('div', 'qrbox');
      try { var q = qrcode(0, 'M'); q.addData(sale.url); q.make(); box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 0, scalable: true }); } catch (e) { box.textContent = 'QR indisponible.'; }
      panel.appendChild(box);
      var wait = el('p', 'wait', 'En attente du paiement…'); panel.appendChild(wait);
      var m2 = el('p', 'msg'); panel.appendChild(m2);
      var cancel = el('button', 'btn ghost', 'Annuler ce paiement'); cancel.type = 'button'; panel.appendChild(cancel);
      function finish(st) {
        stopPoll();
        if (st === 'paid') {
          basket = {}; refresh(); panel.textContent = '';
          var d = el('div', 'done'); d.appendChild(el('p', null, 'Paiement reçu : ' + eur(tot))); d.appendChild(el('div', 'big', 'Payé ✓')); panel.appendChild(d);
          var nb = el('button', 'btn', 'Nouvelle vente'); nb.type = 'button'; nb.addEventListener('click', close); panel.appendChild(nb);
        } else {
          wait.textContent = 'Paiement annulé ou expiré : rien n’a été débité.';
          cancel.textContent = 'Retour'; cancel.onclick = openPay;
        }
      }
      function check(doCancel) {
        call({ action: doCancel ? 'card_cancel' : 'card_status', id: sale.id }).then(function (x) {
          if (x.network) { m2.textContent = 'Réseau coupé : le paiement sera vérifié dès son retour.'; return; }
          if (x.summary) applyState(x);
          if (!x.ok) { m2.textContent = x.error || ''; return; }
          m2.textContent = '';
          if (x.status === 'paid' || x.status === 'canceled') finish(x.status);
        });
      }
      poll = setInterval(function () { check(false); }, 2500);
      cancel.onclick = function () { check(true); };
    });
  }
  function doneScreen(lines, offline) {
    basket = {}; refresh();
    panel.textContent = '';
    var d = el('div', 'done');
    lines.forEach(function (l) { d.appendChild(el(l.big ? 'div' : 'p', l.big ? 'big' : null, l.text)); });
    if (offline) d.appendChild(el('p', 'wait', '📴 Enregistrée sur ce téléphone : elle sera envoyée dès que le réseau revient.'));
    panel.appendChild(d);
    var nb = el('button', 'btn', 'Nouvelle vente'); nb.type = 'button'; nb.addEventListener('click', close); panel.appendChild(nb);
  }
  function queueSale(op) {
    queue.push(op); persist(); banner(); renderHist();
    var chg = op.given_cents != null ? op.given_cents - op.total_cents : 0;
    var lines = [{ text: 'Vente enregistrée : ' + eur(op.total_cents) + (op.method === 'card' ? ' (carte)' : ' (espèces)') }];
    if (chg > 0) { lines.push({ text: 'À rendre' }); lines.push({ big: true, text: eur(chg) }); }
    doneScreen(lines, true);
  }
  function send(method, given, msg) {
    var op = { uid: uid(), type: 'sale', at: Math.floor(Date.now() / 1000), items: items(), method: method, given_cents: given, total_cents: total() };
    if (isOffline()) { queueSale(op); return; }
    msg.textContent = 'Enregistrement…';
    call({ action: 'sale', uid: op.uid, items: op.items, method: method, given_cents: given }).then(function (r) {
      if (r.network) { queueSale(op); return; }                  // réseau perdu en route : même identifiant, aucun doublon à l'envoi
      if (!r.ok) { msg.textContent = r.error || 'Échec.'; return; }
      applyState(r);
      var lines = [{ text: 'Vente enregistrée : ' + eur(r.sale.total_cents) + (method === 'card' ? ' (carte)' : ' (espèces)') }];
      if (r.sale.change_cents != null && r.sale.change_cents > 0) { lines.push({ text: 'À rendre' }); lines.push({ big: true, text: eur(r.sale.change_cents) }); }
      doneScreen(lines, false);
    });
  }
  $('pay').addEventListener('click', openPay);

  // ── Gratuités des bénévoles : le serveur scanne le badge, puis touche l'article offert (possible sans réseau) ──
  function isBar(i) { return String(i.cat).indexOf('boissons') === 0; }
  function giftLine(label, st) { return label + ' : ' + st.used + ' sur ' + st.quota + ' pris' + (st.left ? ' · reste ' + st.left : ' · plus rien'); }
  function rosterFind(code) { var r = (CFG.roster || []).filter(function (v) { return v.code === code; }); return r.length ? r[0] : null; }
  // Statut du bénévole + gratuités encore en file d'attente (pas encore comptées par le serveur).
  function adjust(status, code) {
    var c = { bar: 0, food: 0 }, out = {};
    queue.forEach(function (o) { if (o.type === 'gift' && o.code === code) c[o.kind]++; });
    ['bar', 'food'].forEach(function (k) { var s = status[k]; out[k] = { used: s.used + c[k], quota: s.quota, left: Math.max(0, s.quota - s.used - c[k]) }; });
    return out;
  }
  function openGift(raw) {
    var m = /v=(\d+\.[a-f0-9]{16})/.exec(raw), code = m ? m[1] : raw;
    panel.textContent = ''; sheet.classList.add('on');
    panel.appendChild(el('h2', null, '🎁 Gratuité bénévole'));
    var info = el('p', 'wait', 'Lecture du badge…'); panel.appendChild(info);
    var body = el('div'); body.style.cssText = 'display:grid;gap:10px'; panel.appendChild(body);
    var msg = el('p', 'msg'); panel.appendChild(msg);
    var end = el('button', 'btn ghost', 'Terminer'); end.type = 'button'; end.addEventListener('click', close); panel.appendChild(end);
    var lastGift = null, vol = null, base = null;
    function draw() {
      var st = adjust(base, code);
      info.textContent = ''; info.appendChild(el('strong', null, vol.name));
      if (isOffline()) info.appendChild(el('span', null, ' · 📴 hors ligne'));
      body.textContent = '';
      var lines = el('ul', 'lines');
      [['bar', 'Boissons'], ['food', 'Repas']].forEach(function (k) { var li = el('li'); li.appendChild(el('span', null, giftLine(k[1], st[k[0]]))); lines.appendChild(li); });
      body.appendChild(lines);
      var g = el('div', 'grid');
      CFG.items.forEach(function (i) {
        var kind = isBar(i) ? 'bar' : 'food';
        if (!st[kind].left) return;
        var b = el('button', 'art'); b.type = 'button';
        b.appendChild(el('span', 'n', i.name)); b.appendChild(el('span', 'p', kind === 'bar' ? 'boisson offerte' : 'repas offert'));
        b.addEventListener('click', function () { give(i); });
        g.appendChild(b);
      });
      if (g.children.length) body.appendChild(g); else body.appendChild(el('p', 'wait', 'Plus aucune gratuité disponible pour ce bénévole.'));
      if (lastGift) {
        var u = el('button', 'btn ghost', 'Annuler « ' + lastGift.name + ' »'); u.type = 'button';
        u.addEventListener('click', undo);
        body.appendChild(u);
      }
    }
    function local() {                                           // sans réseau : badges et quotas copiés sur le téléphone
      var v = rosterFind(code);
      if (!v) { info.textContent = ''; msg.textContent = 'Badge inconnu sur ce téléphone : il faut du réseau pour ce bénévole.'; return; }
      vol = { id: v.id, name: v.name }; base = v.status; draw();
    }
    function refreshGift() {
      if (isOffline()) { local(); return; }
      call({ action: 'gift_lookup', code: code }).then(function (r) {
        if (r.network) { local(); return; }
        if (!r.ok) { info.textContent = ''; msg.textContent = r.error || 'Badge illisible.'; return; }
        vol = r.volunteer; base = r.status; draw();
      });
    }
    function queueGift(item) {
      var kind = isBar(item) ? 'bar' : 'food';
      if (!adjust(base, code)[kind].left) { msg.textContent = 'Quota atteint pour ce bénévole.'; return; }
      var op = { uid: uid(), type: 'gift', at: Math.floor(Date.now() / 1000), code: code, item_id: item.id, name: item.name, kind: kind, vname: vol.name };
      queue.push(op); persist(); banner(); renderHist();
      lastGift = { name: item.name, uid: op.uid, queued: true };
      draw(); msg.style.color = 'var(--ok)'; msg.textContent = 'Offert : ' + item.name + ' ✓ (hors ligne, sera envoyé au retour du réseau)';
    }
    function give(item) {
      msg.textContent = ''; msg.style.color = '';
      if (isOffline()) { queueGift(item); return; }
      call({ action: 'gift_give', uid: uid(), code: code, item_id: item.id }).then(function (r) {
        if (r.network) { queueGift(item); return; }
        if (!r.ok) { msg.textContent = r.error || 'Échec.'; if (r.status) { base = r.status; draw(); } return; }
        lastGift = r.gift; base = r.status; if (navigator.vibrate) navigator.vibrate(30);
        draw(); msg.style.color = 'var(--ok)'; msg.textContent = 'Offert : ' + r.gift.name + ' ✓';
      });
    }
    function undo() {
      if (lastGift.queued) {                                      // jamais parti : on l'efface de la file
        var before = queue.length;
        queue = queue.filter(function (o) { return o.uid !== lastGift.uid; });
        if (queue.length === before) { msg.textContent = 'Déjà envoyée : annulez-la depuis « Ventes » quand le réseau est là.'; return; }
        persist(); banner(); renderHist(); lastGift = null; msg.textContent = ''; draw(); return;
      }
      call({ action: 'gift_void', id: lastGift.id }).then(function (x) {
        if (x.network) { msg.textContent = 'Pas de réseau : l’annulation demande du réseau.'; return; }
        if (!x.ok) { msg.textContent = x.error || 'Échec.'; return; }
        msg.textContent = ''; lastGift = null; refreshGift();
      });
    }
    refreshGift();
  }

  // ── Ventes du jour (et opérations en attente d'envoi) ──
  var hs = $('hist-sheet');
  function applyState(r) {
    if (!r.summary) return;
    lastState = r;
    CFG.cardQr = r.card_qr !== undefined ? r.card_qr : CFG.cardQr;
    if (r.items && r.items.length) { if (r.roster) CFG.roster = r.roster; lsSet('cache', { items: r.items, roster: r.roster || CFG.roster, generated: r.generated || 0 }); }
    renderHist(); dayLine();
  }
  function renderHist() {
    var r = lastState, ul = $('hist'); if (!ul) return;
    $('hist-sum').textContent = r ? 'Espèces ' + eur(r.summary.cash_cents) + ' · Carte ' + eur(r.summary.card_cents) + (r.summary.voided ? ' · ' + r.summary.voided + ' annulée(s)' : '') : '';
    ul.textContent = '';
    queue.slice().reverse().forEach(function (op) {                // opérations pas encore envoyées
      var li = el('li', op.error ? '' : '');
      li.appendChild(el('span', null, (op.error ? '⚠ ' : '📴 ') + opLabel(op)));
      li.appendChild(el('span', 'l', op.error ? 'Refusée par le serveur : ' + op.error : 'En attente d’envoi'));
      var b = el('button', null, op.error ? 'Retirer' : 'Retirer'); b.type = 'button';
      b.addEventListener('click', function () {
        if (!confirm('Retirer cette opération ? Elle ne sera jamais enregistrée.')) return;
        queue = queue.filter(function (o) { return o.uid !== op.uid; }); persist(); banner(); renderHist();
      });
      li.appendChild(b);
      if (op.error) {
        var again = el('button', null, 'Réessayer'); again.type = 'button'; again.style.marginLeft = '6px';
        again.addEventListener('click', function () { delete op.error; persist(); banner(); renderHist(); flush(); });
        li.appendChild(again);
      }
      ul.appendChild(li);
    });
    if (r) (r.recent || []).forEach(function (s) {
      var li = el('li', s.voided ? 'v' : '');
      li.appendChild(el('span', null, s.time + ' · ' + eur(s.total_cents) + ' · ' + s.method_label + (s.pending ? ' · en attente' : '')));
      li.appendChild(el('span', 'l', s.label));
      if (s.can_void) {
        var b = el('button', null, 'Annuler'); b.type = 'button';
        b.addEventListener('click', function () {
          if (!confirm('Annuler cette vente de ' + eur(s.total_cents) + ' ?')) return;
          call({ action: 'void', id: s.id }).then(function (x) { if (x.network) { $('hist-msg').textContent = 'Pas de réseau : l’annulation demande du réseau.'; return; } if (!x.ok) { $('hist-msg').textContent = x.error || 'Échec.'; return; } $('hist-msg').textContent = ''; applyState(x); });
        });
        li.appendChild(b);
      }
      ul.appendChild(li);
    });
    if (!ul.children.length) ul.appendChild(el('li', null, 'Aucune vente pour le moment.'));
  }
  function openHist() {
    hs.classList.add('on'); renderHist();
    call({ action: 'state' }).then(function (r) {
      if (r.network) { $('hist-msg').textContent = 'Hors ligne : seules les opérations en attente sont affichées.'; return; }
      if (r.ok) { $('hist-msg').textContent = ''; applyState(r); } else $('hist-msg').textContent = r.error || '';
    });
  }
  $('hist-open').addEventListener('click', openHist);
  $('hist-close').addEventListener('click', function () { hs.classList.remove('on'); });
  hs.addEventListener('click', function (e) { if (e.target === hs) hs.classList.remove('on'); });

  refresh(); banner();
  // Ouverture depuis l'appareil photo du téléphone : caisse.php?a=<article> ajoute l'article, caisse.php?v=<badge> charge le bénévole.
  var qs = new URLSearchParams(location.search);
  if (qs.get('a') || qs.get('v')) {
    var which = qs.get('v') ? '?v=' + qs.get('v') : '?a=' + qs.get('a');
    history.replaceState(null, '', location.pathname);
    addScanned(which);
  }
  call({ action: 'state' }).then(function (r) { if (r.ok) applyState(r); flush(); });
})();
</script>
</body>
</html>
