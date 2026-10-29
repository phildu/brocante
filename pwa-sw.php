<?php
// Service worker minimal de la boutique : rend la web app installable, sans rien mettre en cache
// (les pages, le panier et le paiement demandent toujours le réseau et une connexion à jour).
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* réseau direct */ });
