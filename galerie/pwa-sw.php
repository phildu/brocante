<?php
// Service worker minimal des espaces galerie (visiteur et exposant) : rend l'application
// installable sur Android/Chrome, sans rien mettre en cache (les pages demandent le réseau).
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* réseau direct */ });
