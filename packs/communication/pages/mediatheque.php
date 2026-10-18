<?php
// Médiathèque du pack Communication : celle d'electroboy80, portée telle quelle (assets/eb80/admin.html, recopiée par
// tools/extract-eb80.php), affichée dans un cadre sous le menu de la boutique (lib/eb80-frame.php).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/eb80-frame.php';
com_eb80_frame($plugin, $pluginCsrf, $role, 'media', 'Médiathèque');
