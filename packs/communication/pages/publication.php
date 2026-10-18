<?php
// Publication sur les réseaux sociaux : la page « publish » d'electroboy80 (composer, visuel de l'éditeur, messages par réseau,
// post / story / reel, programmation et récurrence, historique), portée telle quelle dans le cadre commun (lib/eb80-frame.php).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/eb80-frame.php';
com_eb80_frame($plugin, $pluginCsrf, $role, 'publish', 'Publication');
