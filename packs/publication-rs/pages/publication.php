<?php
// Publication sur les réseaux sociaux : la page « publish » d'electroboy80 (composer, visuel de la médiathèque ou envoyé,
// messages par réseau, post / story / reel, programmation et récurrence, historique), portée telle quelle dans le cadre commun
// (lib/frame.php). L'éditeur visuel et l'affiche restent dans le pack Communication : leurs onglets sont masqués par eb80-after.js.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/frame.php';
prs_eb80_frame($plugin, $pluginCsrf, $role, 'publish', 'Publication réseaux sociaux');
