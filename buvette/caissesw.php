<?php
// Service worker de la caisse de la buvette : l'écran de la caisse doit s'ouvrir même sans réseau (champ, parking, 4G capricieuse).
// Il ne garde que l'application elle-même (page de la caisse et fichiers statiques) ; les ventes et les gratuités, elles, sont mises en
// file d'attente par la page (localStorage) et rejouées au retour du réseau. Jamais d'API en cache (ventes.php).
// Servi à /galerie/<galerie>/buvette/caissesw.php : sa portée (scope) est l'espace buvette de la galerie, chaque galerie a son cache.
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
var BASE = new URL(self.registration.scope).pathname;
var CACHE = 'buvette-caisse-v1|' + BASE;
var PAGE = BASE + 'caisse.php';
var ASSETS = ['/assets/vendor/qrcode-generator.js', '/assets/barcode-scan.js', '/assets/barcode-scan.css', '/assets/lib/html5-qrcode/html5-qrcode.min.js', '/assets/pwa-save.js'];

// Ne garde que la vraie page de la caisse (marquée par l'en-tête X-Bv-Caisse), pas la page de connexion ni une redirection.
function keepPage(cache, res) {
  if (res.ok && !res.redirected && res.headers.get('X-Bv-Caisse')) return cache.put(PAGE, res.clone());
}

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) {
    var page = fetch(PAGE, { credentials: 'same-origin' }).then(function (res) { return keepPage(c, res); }).catch(function () {});
    return Promise.all([page].concat(ASSETS.map(function (u) { return c.add(u).catch(function () {}); })));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.split('|')[1] === BASE && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin || url.pathname.indexOf(BASE + 'ventes.php') === 0) return;

  // La page de la caisse : réseau d'abord (carte et session à jour), la copie du téléphone sans réseau. Les paramètres ?a= / ?v= (QR) sont ignorés.
  if (url.pathname === PAGE && (req.mode === 'navigate' || req.destination === 'document')) {
    e.respondWith(fetch(req).then(function (res) {
      var copy = res.clone();
      caches.open(CACHE).then(function (c) { return keepPage(c, copy); });
      return res;
    }).catch(function () {
      return caches.open(CACHE).then(function (c) { return c.match(PAGE); }).then(function (hit) {
        return hit || new Response('<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;padding:32px;text-align:center"><h1>Hors ligne</h1><p>La caisse n\'a pas encore été ouverte avec du réseau sur ce téléphone : ouvrez-la une fois en ligne pour pouvoir l\'utiliser sans réseau.</p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      });
    }));
    return;
  }

  // Fichiers statiques (scripts, styles, icône de la galerie) : cache d'abord, mis à jour en arrière-plan.
  if (url.pathname.indexOf('/assets/') === 0 || url.pathname === '/galerie/icon.php' || url.pathname === BASE + 'caisse.php') {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
