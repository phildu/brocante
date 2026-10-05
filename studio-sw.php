<?php
// Service worker minimal du Studio : rend l'application installable, sans rien mettre en cache
// (les pages et les envois demandent toujours le réseau et une connexion à jour).
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* réseau direct */ });
