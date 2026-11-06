<?php
// Service worker minimal de la boutique : rend la web app installable, sans rien mettre en cache
// (les pages, le panier et le paiement demandent toujours le réseau et une connexion à jour).
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* réseau direct */ });

// Notifications du navigateur (Web Push) : affichage d'un message reçu, puis ouverture du site au clic.
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || 'Notification', {
    body: data.body || '',
    icon: '/pwa-icon.php?s=192',
    data: { url: data.url || '/' }
  }));
});
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = new URL('/', self.location.origin).href;
  try {
    var u = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
    if (u.origin === self.location.origin) target = u.href; // jamais de redirection vers un autre site
  } catch (e) {}
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if (list[i].url === target && 'focus' in list[i]) return list[i].focus(); }
    return self.clients.openWindow(target);
  }));
});
