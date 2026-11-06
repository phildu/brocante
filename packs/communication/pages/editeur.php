<?php
// Éditeur visuel du pack Communication : celui d'electroboy80 (affiche, carrousel, vidéo et reel, couches libres, effets),
// porté tel quel dans le même cadre que la médiathèque (lib/eb80-frame.php), ouvert sur sa page « editor ».
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/eb80-frame.php';
com_eb80_frame($plugin, $pluginCsrf, $role, 'editor', 'Éditeur visuel');
