/* Service worker du comptage : l'écran du compteur doit s'ouvrir même sans
 * réseau (champ, parking, 4G capricieuse). Les appuis, eux, sont mis en file
 * d'attente par comptage.js (localStorage) ; ce fichier ne s'occupe que de
 * l'application elle-même. Jamais d'API en cache. */
var CACHE = 'comptage-v2';
var PAGE = '/comptage/index.php';
var ASSETS = ['/assets/style.css', '/assets/comptage.css', '/assets/comptage.js', '/assets/comptage-vision.js', '/assets/logo-macaron.png'];

// Ne garde que la vraie page du compteur (marquée par l'en-tête X-Cp-App), pas
// la page de connexion ni une redirection.
function keepPage(cache, res) {
  if (res.ok && !res.redirected && res.headers.get('X-Cp-App')) return cache.put(PAGE, res.clone());
}

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) {
    // La page est mise en cache dès l'installation : au premier chargement, le
    // service worker ne contrôle pas encore la page, qui ne passerait donc
    // jamais par le cache de navigation ci-dessous.
    var page = fetch(PAGE, { credentials: 'same-origin' }).then(function (res) { return keepPage(c, res); }).catch(function () {});
    // addAll échouerait en bloc si un fichier manque : on tolère.
    return Promise.all([page].concat(ASSETS.map(function (u) { return c.add(u).catch(function () {}); })));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin || url.pathname.indexOf('/comptage/api.php') === 0) return;

  // Page du compteur : le réseau d'abord (toujours à jour), le cache en secours.
  if (req.mode === 'navigate' && url.pathname.indexOf('/comptage/') === 0) {
    e.respondWith(fetch(req).then(function (res) {
      var copy = res.clone();
      caches.open(CACHE).then(function (c) { keepPage(c, copy); });
      return res;
    }).catch(function () {
      return caches.match(PAGE).then(function (r) {
        return r || new Response('<meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;padding:24px"><h1>Hors ligne</h1><p>Ouvrez le compteur une première fois avec du réseau pour pouvoir l\'utiliser ensuite sans connexion.</p>', { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 });
      });
    }));
    return;
  }

  // Détecteur de personnes (une dizaine de Mo, immuable) : mis en cache à la première
  // utilisation du mode caméra, puis servi sans jamais être retéléchargé.
  if (url.pathname.indexOf('/assets/vendor/') === 0) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        return hit || fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; });
      });
    }));
    return;
  }

  // Fichiers statiques : le cache d'abord, rafraîchi en tâche de fond.
  if (/\.(css|js|png|json)$/.test(url.pathname)) {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
