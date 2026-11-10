<?php
// « API & Réseaux » (administrateurs) : les réglages d'electroboy80 concernant la publication, portés dans le cadre commun et
// réduits par eb80-after.js aux Réseaux sociaux (applications et jetons Facebook, Instagram, Threads, TikTok, diagnostic,
// renouvellement) et aux Clés IA (rédaction du message). Réglages rangés dans data/com/<boutique>.db.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/frame.php';
prs_eb80_frame($plugin, $pluginCsrf, $role, 'rs-settings', 'API & Réseaux');
