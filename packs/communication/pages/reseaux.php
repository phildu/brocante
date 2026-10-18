<?php
// Réglages des réseaux sociaux (administrateurs) : l'onglet « Réseaux sociaux » de la page « API & Réseaux » d'electroboy80
// (applications et jetons Facebook, Instagram, Threads, TikTok, diagnostic, renouvellement), porté dans le cadre commun.
// Les jetons sont rangés dans la base propre au portage de la boutique (data/com/<boutique>.db), jamais servie ni déployée.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/eb80-frame.php';
com_eb80_frame($plugin, $pluginCsrf, $role, 'rs-settings', 'Réseaux sociaux');
